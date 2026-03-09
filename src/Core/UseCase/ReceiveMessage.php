<?php

declare(strict_types=1);

namespace FideX\Core\UseCase;

use FideX\Core\Domain\Message;
use FideX\Port\Outbound\LoggerPort;
use FideX\Port\Outbound\MessageRepositoryPort;
use FideX\Port\Outbound\PartnerRepositoryPort;
use FideX\Port\Outbound\QueuePort;

/**
 * ReceiveMessage Use Case
 *
 * Handles inbound FideX envelopes received at POST /api/v1/receive.
 *
 * Implements fast-return semantics:
 * 1. Validate routing header (required fields, correct receiver, known sender)
 * 2. Check idempotency (duplicate message_id rejection)
 * 3. Verify payload_digest if present
 * 4. Persist message with RECEIVED status
 * 5. Enqueue JOB_PROCESS_INBOUND for async JWE decryption
 * 6. Return immediately (caller sends HTTP 202)
 *
 * The actual decryption + J-MDN dispatch runs asynchronously in the queue worker.
 */
final class ReceiveMessage
{
    /** Required fields in the routing header per FideX spec */
    private const REQUIRED_HEADER_FIELDS = [
        'fidex_version',
        'message_id',
        'sender_id',
        'receiver_id',
        'document_type',
        'timestamp',
    ];

    public function __construct(
        private readonly MessageRepositoryPort $messageRepository,
        private readonly PartnerRepositoryPort $partnerRepository,
        private readonly QueuePort             $queue,
        private readonly LoggerPort            $logger,
        private readonly string                $nodeId,
    ) {
    }

    /**
     * Execute the receive use case.
     *
     * @param array<string, mixed> $envelope { routing_header: {...}, encrypted_payload: "..." }
     */
    public function execute(array $envelope): Result
    {
        // ── Step 1: Validate envelope structure ────────────────────────────

        $routingHeader    = $envelope['routing_header'] ?? null;
        $encryptedPayload = $envelope['encrypted_payload'] ?? '';

        if (!is_array($routingHeader)) {
            return Result::failure(Result::ERR_VALIDATION_ERROR, 'routing_header is missing or invalid.');
        }

        // Validate all required header fields are present and non-empty
        foreach (self::REQUIRED_HEADER_FIELDS as $field) {
            if (empty($routingHeader[$field])) {
                return Result::failure(
                    Result::ERR_VALIDATION_ERROR,
                    "routing_header.{$field} is required."
                );
            }
        }

        if (empty($encryptedPayload)) {
            return Result::failure(Result::ERR_VALIDATION_ERROR, 'encrypted_payload is required.');
        }

        // ── Step 2: Validate receiver_id matches this node ─────────────────

        if ($routingHeader['receiver_id'] !== $this->nodeId) {
            return Result::failure(
                Result::ERR_VALIDATION_ERROR,
                "Message is addressed to '{$routingHeader['receiver_id']}', but this node is '{$this->nodeId}'."
            );
        }

        // ── Step 3: Verify sender is a known, registered partner ───────────

        $senderId = (string) $routingHeader['sender_id'];
        $partner  = $this->partnerRepository->findById($senderId);

        if ($partner === null) {
            $this->logger->warning('ReceiveMessage: unknown sender', ['sender_id' => $senderId]);
            return Result::failure(
                Result::ERR_UNKNOWN_PARTNER,
                "Sender '{$senderId}' is not a registered partner."
            );
        }

        // ── Step 4: Idempotency check (duplicate message_id) ───────────────

        $messageId = (string) $routingHeader['message_id'];

        if ($this->messageRepository->exists($messageId)) {
            $this->logger->info('ReceiveMessage: duplicate message rejected', ['message_id' => $messageId]);
            return Result::failure(
                Result::ERR_DUPLICATE_MESSAGE,
                "Message ID '{$messageId}' has already been received."
            );
        }

        // ── Step 5: Verify payload_digest if present ───────────────────────

        if (isset($routingHeader['payload_digest']) && $routingHeader['payload_digest'] !== '') {
            $expectedDigest = 'sha256:' . hash('sha256', $encryptedPayload);
            if ($routingHeader['payload_digest'] !== $expectedDigest) {
                $this->logger->warning('ReceiveMessage: payload digest mismatch', [
                    'message_id' => $messageId,
                    'expected'   => $expectedDigest,
                    'received'   => $routingHeader['payload_digest'],
                ]);
                return Result::failure(
                    Result::ERR_VALIDATION_ERROR,
                    'payload_digest verification failed — envelope may have been tampered with.'
                );
            }
        }

        // ── Step 6: Persist message as RECEIVED ───────────────────────────

        $receiptWebhook = isset($routingHeader['receipt_webhook'])
            ? (string) $routingHeader['receipt_webhook']
            : null;

        $message = Message::createInbound(
            messageId: $messageId,
            senderId: $senderId,
            receiverId: $this->nodeId,
            documentType: (string) $routingHeader['document_type'],
            timestamp: (string) $routingHeader['timestamp'],
            encryptedPayload: (string) $encryptedPayload,
            receiptWebhook: $receiptWebhook,
        );

        $this->messageRepository->save($message);

        // ── Step 7: Enqueue async processing job ──────────────────────────

        $this->queue->enqueue(QueuePort::JOB_PROCESS_INBOUND, [
            'message_id' => $messageId,
        ]);

        $this->logger->info('ReceiveMessage: accepted', [
            'message_id' => $messageId,
            'sender_id'  => $senderId,
        ]);

        // ── Step 8: Return fast (HTTP 202 semantics) ──────────────────────

        return Result::success([
            'message_id' => $messageId,
            'status'     => 'RECEIVED',
        ]);
    }
}
