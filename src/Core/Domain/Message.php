<?php

declare(strict_types=1);

namespace FideX\Core\Domain;

/**
 * Message Entity
 *
 * Represents a FideX message envelope (inbound or outbound).
 * Rich domain model with state transition methods.
 */
final class Message
{
    private function __construct(
        private readonly string  $messageId,
        private readonly string  $senderId,
        private readonly string  $receiverId,
        private readonly string  $documentType,
        private readonly string  $timestamp,
        private MessageStatus    $status,
        private readonly string  $direction,
        private readonly array   $rawPayload,
        private string           $encryptedPayload,
        private readonly ?string $receiptWebhook,
        private int              $retryCount,
        private ?string          $errorMessage,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable          $updatedAt,
    ) {
    }

    public static function createOutbound(
        string  $messageId,
        string  $senderId,
        string  $receiverId,
        string  $documentType,
        array   $rawPayload,
        ?string $receiptWebhook = null,
    ): self {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return new self(
            messageId: $messageId,
            senderId: $senderId,
            receiverId: $receiverId,
            documentType: $documentType,
            timestamp: $now->format('Y-m-d\TH:i:s.v\Z'),
            status: MessageStatus::PENDING,
            direction: 'outbound',
            rawPayload: $rawPayload,
            encryptedPayload: '',
            receiptWebhook: $receiptWebhook,
            retryCount: 0,
            errorMessage: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function createInbound(
        string  $messageId,
        string  $senderId,
        string  $receiverId,
        string  $documentType,
        string  $timestamp,
        string  $encryptedPayload,
        ?string $receiptWebhook = null,
    ): self {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return new self(
            messageId: $messageId,
            senderId: $senderId,
            receiverId: $receiverId,
            documentType: $documentType,
            timestamp: $timestamp,
            status: MessageStatus::RECEIVED,
            direction: 'inbound',
            rawPayload: [],
            encryptedPayload: $encryptedPayload,
            receiptWebhook: $receiptWebhook,
            retryCount: 0,
            errorMessage: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /** Reconstitute from persistence (no invariant checks needed here) */
    public static function reconstitute(
        string  $messageId, string $senderId, string $receiverId, string $documentType,
        string  $timestamp, MessageStatus $status, string $direction, array $rawPayload,
        string  $encryptedPayload, ?string $receiptWebhook, int $retryCount,
        ?string $errorMessage, \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            messageId: $messageId, senderId: $senderId, receiverId: $receiverId,
            documentType: $documentType, timestamp: $timestamp, status: $status,
            direction: $direction, rawPayload: $rawPayload, encryptedPayload: $encryptedPayload,
            receiptWebhook: $receiptWebhook, retryCount: $retryCount, errorMessage: $errorMessage,
            createdAt: $createdAt, updatedAt: $updatedAt,
        );
    }

    // ── State transitions ───────────────────────────────────────────────────

    public function markQueued(): void
    {
        $this->status = MessageStatus::QUEUED;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markSent(): void
    {
        $this->status = MessageStatus::SENT;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markAcknowledged(): void
    {
        $this->status = MessageStatus::ACKNOWLEDGED;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markDecrypted(): void
    {
        $this->status = MessageStatus::DECRYPTED;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markDelivered(): void
    {
        $this->status = MessageStatus::DELIVERED;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markFailed(string $reason): void
    {
        $this->status = MessageStatus::FAILED;
        $this->errorMessage = $reason;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function markRetry(): void
    {
        $this->status = MessageStatus::RETRY;
        $this->retryCount++;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function setEncryptedPayload(string $jwe): void
    {
        $this->encryptedPayload = $jwe;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    // ── Getters ─────────────────────────────────────────────────────────────

    public function getMessageId(): string       { return $this->messageId; }
    public function getSenderId(): string        { return $this->senderId; }
    public function getReceiverId(): string      { return $this->receiverId; }
    public function getDocumentType(): string    { return $this->documentType; }
    public function getTimestamp(): string       { return $this->timestamp; }
    public function getStatus(): MessageStatus   { return $this->status; }
    public function getDirection(): string       { return $this->direction; }
    public function getRawPayload(): array       { return $this->rawPayload; }
    public function getEncryptedPayload(): string { return $this->encryptedPayload; }
    public function getReceiptWebhook(): ?string  { return $this->receiptWebhook; }
    public function getRetryCount(): int         { return $this->retryCount; }
    public function getErrorMessage(): ?string    { return $this->errorMessage; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
