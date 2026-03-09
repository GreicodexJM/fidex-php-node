<?php

declare(strict_types=1);

namespace FideX\Tests\Unit\Core\UseCase;

use FideX\Core\Domain\MessageStatus;
use FideX\Core\Domain\Partner;
use FideX\Core\UseCase\ReceiveMessage;
use FideX\Core\UseCase\Result;
use FideX\Port\Outbound\QueuePort;
use FideX\Tests\Doubles\InMemoryMessageRepository;
use FideX\Tests\Doubles\InMemoryPartnerRepository;
use FideX\Tests\Doubles\InMemoryQueue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ReceiveMessage Use Case Tests
 *
 * TDD: Written BEFORE implementation.
 *
 * Validates:
 * - Happy path: envelope accepted, message stored as RECEIVED, job queued
 * - Duplicate message ID rejected (idempotency)
 * - Unknown partner rejected
 * - Malformed routing header rejected
 * - Payload digest mismatch rejected
 */
class ReceiveMessageTest extends TestCase
{
    private InMemoryMessageRepository $messages;
    private InMemoryPartnerRepository $partners;
    private InMemoryQueue $queue;
    private MockObject $logger;
    private ReceiveMessage $useCase;

    protected function setUp(): void
    {
        $this->messages = new InMemoryMessageRepository();
        $this->partners = new InMemoryPartnerRepository();
        $this->queue    = new InMemoryQueue();
        $this->logger   = $this->createMock(\FideX\Port\Outbound\LoggerPort::class);

        $this->useCase = new ReceiveMessage(
            messageRepository: $this->messages,
            partnerRepository: $this->partners,
            queue: $this->queue,
            logger: $this->logger,
            nodeId: 'urn:custom:receiver-node',
        );

        // Pre-register sender as a known partner
        $sender = Partner::register(
            partnerId: 'urn:gln:0614141000005',
            name: 'Trusted Sender',
            receiveEndpoint: 'https://sender.example.com/api/v1/receive',
            receiptEndpoint: 'https://sender.example.com/api/v1/receipt',
            jwksUrl: 'https://sender.example.com/.well-known/jwks.json',
            as5ConfigUrl: 'https://sender.example.com/as5/config',
        );
        $this->partners->save($sender);
    }

    private function validEnvelope(): array
    {
        $encryptedPayload = 'eyJhbGciOiJSU0EtT0FFUCIsImVuYyI6IkEyNTZHQ00iLCJjdHkiOiJKV1QifQ.mock-encrypted';
        return [
            'routing_header' => [
                'fidex_version' => '1.0',
                'message_id'    => 'fdx-' . uniqid('', true),
                'sender_id'     => 'urn:gln:0614141000005',
                'receiver_id'   => 'urn:custom:receiver-node',
                'document_type' => 'GS1_ORDER_JSON',
                'timestamp'     => '2026-03-09T10:00:00.000Z',
            ],
            'encrypted_payload' => $encryptedPayload,
        ];
    }

    // ─── Happy Path ────────────────────────────────────────────────────────

    public function test_receive_accepts_valid_envelope_and_returns_accepted(): void
    {
        $envelope = $this->validEnvelope();

        $result = $this->useCase->execute($envelope);

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertSame($envelope['routing_header']['message_id'], $data['message_id']);
        $this->assertSame('RECEIVED', $data['status']);
    }

    public function test_receive_stores_message_with_received_status(): void
    {
        $envelope = $this->validEnvelope();
        $messageId = $envelope['routing_header']['message_id'];

        $this->useCase->execute($envelope);

        $message = $this->messages->findById($messageId);
        $this->assertNotNull($message);
        $this->assertSame(MessageStatus::RECEIVED, $message->getStatus());
        $this->assertSame('inbound', $message->getDirection());
        $this->assertSame('urn:gln:0614141000005', $message->getSenderId());
    }

    public function test_receive_enqueues_process_inbound_job(): void
    {
        $envelope = $this->validEnvelope();

        $this->useCase->execute($envelope);

        $jobs = $this->queue->allJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame(QueuePort::JOB_PROCESS_INBOUND, $jobs[0]['job_type']);
        $this->assertSame($envelope['routing_header']['message_id'], $jobs[0]['payload']['message_id']);
    }

    public function test_receive_stores_receipt_webhook_if_provided(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['routing_header']['receipt_webhook'] = 'https://sender.example.com/receipt';
        $messageId = $envelope['routing_header']['message_id'];

        $this->useCase->execute($envelope);

        $message = $this->messages->findById($messageId);
        $this->assertSame('https://sender.example.com/receipt', $message->getReceiptWebhook());
    }

    // ─── Idempotency ───────────────────────────────────────────────────────

    public function test_receive_rejects_duplicate_message_id(): void
    {
        $envelope = $this->validEnvelope();

        $this->useCase->execute($envelope);
        $result = $this->useCase->execute($envelope);  // same ID, second time

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_DUPLICATE_MESSAGE, $result->getError());
    }

    // ─── Failure Cases ─────────────────────────────────────────────────────

    public function test_receive_rejects_unknown_sender(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['routing_header']['sender_id'] = 'urn:gln:UNKNOWN_SENDER';

        $result = $this->useCase->execute($envelope);

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_UNKNOWN_PARTNER, $result->getError());
    }

    public function test_receive_rejects_missing_message_id(): void
    {
        $envelope = $this->validEnvelope();
        unset($envelope['routing_header']['message_id']);

        $result = $this->useCase->execute($envelope);

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
    }

    public function test_receive_rejects_missing_encrypted_payload(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['encrypted_payload'] = '';

        $result = $this->useCase->execute($envelope);

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
    }

    public function test_receive_rejects_wrong_receiver_id(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['routing_header']['receiver_id'] = 'urn:custom:some-other-node';

        $result = $this->useCase->execute($envelope);

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
    }

    public function test_receive_validates_payload_digest_when_present(): void
    {
        $envelope = $this->validEnvelope();
        // Set a wrong digest
        $envelope['routing_header']['payload_digest'] = 'sha256:wrong_hash_value';

        $result = $this->useCase->execute($envelope);

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
    }

    public function test_receive_accepts_correct_payload_digest(): void
    {
        $envelope = $this->validEnvelope();
        $correctDigest = 'sha256:' . hash('sha256', $envelope['encrypted_payload']);
        $envelope['routing_header']['payload_digest'] = $correctDigest;

        $result = $this->useCase->execute($envelope);

        $this->assertTrue($result->isSuccess());
    }
}
