<?php

declare(strict_types=1);

namespace FideX\Tests\Unit\Core\UseCase;

use FideX\Core\Domain\Message;
use FideX\Core\Domain\Partner;
use FideX\Core\Domain\Receipt;
use FideX\Core\Domain\MessageStatus;
use FideX\Core\UseCase\ProcessReceipt;
use FideX\Core\UseCase\Result;
use FideX\Port\Outbound\CryptoServicePort;
use FideX\Port\Outbound\LoggerPort;
use FideX\Tests\Doubles\InMemoryMessageRepository;
use FideX\Tests\Doubles\InMemoryPartnerRepository;
use PHPUnit\Framework\TestCase;

/**
 * ProcessReceiptTest
 *
 * Tests the ProcessReceipt use case in full isolation using in-memory
 * repositories and PHPUnit mocks for CryptoServicePort and LoggerPort.
 *
 * @covers \FideX\Core\UseCase\ProcessReceipt
 */
final class ProcessReceiptTest extends TestCase
{
    private InMemoryMessageRepository $messageRepo;
    private InMemoryPartnerRepository $partnerRepo;
    private CryptoServicePort         $crypto;
    private LoggerPort                $logger;
    private ProcessReceipt            $useCase;

    protected function setUp(): void
    {
        $this->messageRepo = new InMemoryMessageRepository();
        $this->partnerRepo = new InMemoryPartnerRepository();
        $this->crypto      = $this->createMock(CryptoServicePort::class);
        $this->logger      = $this->createMock(LoggerPort::class);

        $this->useCase = new ProcessReceipt(
            $this->messageRepo,
            $this->partnerRepo,
            $this->crypto,
            $this->logger,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeMessage(
        string $id = 'msg-001',
        string $encryptedPayload = 'jwe-token-placeholder',
    ): Message {
        $message = Message::createOutbound(
            messageId: $id,
            senderId: 'urn:custom:my-node',
            receiverId: 'urn:custom:partner-a',
            documentType: 'GS1_ORDER_JSON',
            rawPayload: ['order_id' => 'PO-001'],
        );
        $message->setEncryptedPayload($encryptedPayload);
        return $message;
    }

    private function makePartner(string $id = 'urn:custom:partner-a'): Partner
    {
        return Partner::register(
            partnerId: $id,
            name: 'Test Partner',
            receiveEndpoint: 'https://partner.example.com/receive',
            receiptEndpoint: 'https://partner.example.com/receipt',
            jwksUrl: 'https://partner.example.com/.well-known/jwks.json',
            as5ConfigUrl: 'https://partner.example.com/as5/config',
        );
    }

    /** Build a valid J-MDN payload for the given message and hash. */
    private function makeJmdn(
        string $messageId,
        string $encryptedPayload,
        string $status = Receipt::STATUS_DELIVERED,
        string $receiverId = 'urn:custom:partner-a',
        ?string $hashOverride = null,
    ): array {
        return [
            'original_message_id' => $messageId,
            'status'              => $status,
            'receiver_id'         => $receiverId,
            'hash_verification'   => $hashOverride ?? ('sha256:' . hash('sha256', $encryptedPayload)),
            'timestamp'           => '2026-03-09T17:00:00Z',
            'signature'           => 'sig-placeholder',
        ];
    }

    // ── Tests: Input validation ────────────────────────────────────────────

    public function test_returns_failure_when_required_field_is_missing(): void
    {
        $jmdn = $this->makeJmdn('msg-001', 'payload');
        unset($jmdn['status']); // remove required field

        $result = $this->useCase->execute($jmdn);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
        $this->assertStringContainsString("'status'", $result->getMessage());
    }

    // ── Tests: Repository lookups ──────────────────────────────────────────

    public function test_returns_failure_when_original_message_not_found(): void
    {
        // Message not in repository — partner registered
        $this->partnerRepo->save($this->makePartner());
        $jmdn = $this->makeJmdn('non-existent-msg', 'payload');

        $result = $this->useCase->execute($jmdn);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(Result::ERR_MESSAGE_NOT_FOUND, $result->getError());
    }

    public function test_returns_failure_when_receipt_signer_is_not_a_registered_partner(): void
    {
        $message = $this->makeMessage();
        $this->messageRepo->save($message);
        // Partner NOT added to repo
        $jmdn = $this->makeJmdn('msg-001', 'jwe-token-placeholder');

        $result = $this->useCase->execute($jmdn);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(Result::ERR_UNKNOWN_PARTNER, $result->getError());
    }

    // ── Tests: Hash verification ───────────────────────────────────────────

    public function test_returns_failure_when_hash_verification_does_not_match(): void
    {
        $encryptedPayload = 'actual-jwe-token';
        $message          = $this->makeMessage('msg-001', $encryptedPayload);
        $partner          = $this->makePartner(); // no cached JWKS → skip sig check

        $this->messageRepo->save($message);
        $this->partnerRepo->save($partner);

        $jmdn = $this->makeJmdn(
            messageId: 'msg-001',
            encryptedPayload: $encryptedPayload,
            hashOverride: 'sha256:wrong-hash-value',
        );

        $result = $this->useCase->execute($jmdn);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
        $this->assertStringContainsString('hash_verification', $result->getMessage());
    }

    // ── Tests: Happy paths ────────────────────────────────────────────────

    public function test_marks_message_as_acknowledged_when_jmdn_reports_delivered(): void
    {
        $encryptedPayload = 'valid-jwe-token';
        $message          = $this->makeMessage('msg-001', $encryptedPayload);
        $partner          = $this->makePartner(); // no cached JWKS → skip sig check

        $this->messageRepo->save($message);
        $this->partnerRepo->save($partner);

        $jmdn   = $this->makeJmdn('msg-001', $encryptedPayload, Receipt::STATUS_DELIVERED);
        $result = $this->useCase->execute($jmdn);

        $this->assertTrue($result->isSuccess());
        $saved = $this->messageRepo->findById('msg-001');
        $this->assertSame(MessageStatus::ACKNOWLEDGED, $saved->getStatus());
    }

    public function test_marks_message_as_failed_when_jmdn_reports_failed_delivery(): void
    {
        $encryptedPayload = 'valid-jwe-token';
        $message          = $this->makeMessage('msg-001', $encryptedPayload);
        $partner          = $this->makePartner();

        $this->messageRepo->save($message);
        $this->partnerRepo->save($partner);

        $jmdn   = $this->makeJmdn('msg-001', $encryptedPayload, Receipt::STATUS_FAILED);
        $result = $this->useCase->execute($jmdn);

        $this->assertTrue($result->isSuccess()); // processing succeeded; delivery failed
        $saved = $this->messageRepo->findById('msg-001');
        $this->assertSame(MessageStatus::FAILED, $saved->getStatus());
    }

    public function test_returns_success_data_with_original_message_id_and_status(): void
    {
        $encryptedPayload = 'valid-jwe-token';
        $message          = $this->makeMessage('msg-42', $encryptedPayload);
        $partner          = $this->makePartner();

        $this->messageRepo->save($message);
        $this->partnerRepo->save($partner);

        $jmdn   = $this->makeJmdn('msg-42', $encryptedPayload, Receipt::STATUS_DELIVERED);
        $result = $this->useCase->execute($jmdn);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('msg-42', $result->getData()['original_message_id']);
        $this->assertSame(Receipt::STATUS_DELIVERED, $result->getData()['status']);
    }
}
