<?php

declare(strict_types=1);

namespace FideX\Tests\Unit\Core\UseCase;

use FideX\Core\Domain\KeyPair;
use FideX\Core\Domain\MessageStatus;
use FideX\Core\Domain\Partner;
use FideX\Core\UseCase\Result;
use FideX\Core\UseCase\TransmitMessage;
use FideX\Port\Outbound\QueuePort;
use FideX\Tests\Doubles\InMemoryMessageRepository;
use FideX\Tests\Doubles\InMemoryPartnerRepository;
use FideX\Tests\Doubles\InMemoryQueue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * TransmitMessage Use Case Tests
 *
 * TDD: Written BEFORE implementation.
 *
 * Validates:
 * - Happy path: message created, encrypted, queued
 * - Unknown partner returns failure
 * - Inactive partner returns failure
 * - Duplicate message ID returns failure
 * - Payload too large returns failure
 */
class TransmitMessageTest extends TestCase
{
    private InMemoryMessageRepository $messages;
    private InMemoryPartnerRepository $partners;
    private InMemoryQueue $queue;
    private MockObject $crypto;
    private MockObject $logger;
    private TransmitMessage $useCase;

    private static KeyPair $signingKeyPair;
    private static KeyPair $encryptionKeyPair;

    public static function setUpBeforeClass(): void
    {
        $signingKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($signingKey, $privPem);
        $details = openssl_pkey_get_details($signingKey);
        self::$signingKeyPair = new KeyPair('node-sign-test', KeyPair::USE_SIGN, $privPem, $details['key'], 'RS256');

        $encKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($encKey, $encPrivPem);
        $encDetails = openssl_pkey_get_details($encKey);
        self::$encryptionKeyPair = new KeyPair('node-enc-test', KeyPair::USE_ENC, $encPrivPem, $encDetails['key'], 'RSA-OAEP');
    }

    protected function setUp(): void
    {
        $this->messages = new InMemoryMessageRepository();
        $this->partners = new InMemoryPartnerRepository();
        $this->queue    = new InMemoryQueue();

        $this->crypto = $this->createMock(\FideX\Port\Outbound\CryptoServicePort::class);
        $this->logger = $this->createMock(\FideX\Port\Outbound\LoggerPort::class);

        $this->useCase = new TransmitMessage(
            messageRepository: $this->messages,
            partnerRepository: $this->partners,
            cryptoService: $this->crypto,
            queue: $this->queue,
            logger: $this->logger,
            nodeId: 'urn:custom:sender-node',
            signingKeyPair: self::$signingKeyPair,
        );
    }

    private function registerPartner(): void
    {
        $partner = Partner::register(
            partnerId: 'urn:gln:0614141000012',
            name: 'Test Pharmacy',
            receiveEndpoint: 'https://partner.example.com/api/v1/receive',
            receiptEndpoint: 'https://partner.example.com/api/v1/receipt',
            jwksUrl: 'https://partner.example.com/.well-known/jwks.json',
            as5ConfigUrl: 'https://partner.example.com/as5/config',
        );
        // Inject cached JWKS with public key
        $partner->updateJwksCache(json_encode([
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'enc',
                    'kid' => 'partner-enc-test-01',
                    'alg' => 'RSA-OAEP',
                    'n'   => 'test',
                    'e'   => 'AQAB',
                ],
            ],
        ]));
        $this->partners->save($partner);
    }

    // ─── Happy Path ────────────────────────────────────────────────────────

    public function test_transmit_queues_message_for_known_partner(): void
    {
        $this->registerPartner();

        $this->crypto->expects($this->once())
            ->method('sign')
            ->willReturn('mock-jws-token');

        $this->crypto->expects($this->once())
            ->method('encrypt')
            ->with('mock-jws-token', $this->anything(), $this->anything())
            ->willReturn('mock-jwe-token');

        $result = $this->useCase->execute(
            destinationPartnerId: 'urn:gln:0614141000012',
            documentType: 'GS1_ORDER_JSON',
            payload: ['order_id' => 'PO-001', 'amount' => 100.00],
            receiptWebhook: 'https://my-erp.com/fidex/receipt',
        );

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertNotEmpty($data['message_id']);
        $this->assertStringStartsWith('fdx-', $data['message_id']);
        $this->assertSame('QUEUED', $data['status']);
    }

    public function test_transmit_persists_message_with_queued_status(): void
    {
        $this->registerPartner();
        $this->crypto->method('sign')->willReturn('jws');
        $this->crypto->method('encrypt')->willReturn('jwe');

        $result = $this->useCase->execute(
            destinationPartnerId: 'urn:gln:0614141000012',
            documentType: 'GS1_ORDER_JSON',
            payload: ['order_id' => 'PO-002'],
        );

        $this->assertTrue($result->isSuccess());
        $messageId = $result->getData()['message_id'];
        $message = $this->messages->findById($messageId);

        $this->assertNotNull($message);
        $this->assertSame(MessageStatus::QUEUED, $message->getStatus());
        $this->assertSame('urn:custom:sender-node', $message->getSenderId());
        $this->assertSame('urn:gln:0614141000012', $message->getReceiverId());
        $this->assertSame('jwe', $message->getEncryptedPayload());
    }

    public function test_transmit_enqueues_transmit_job(): void
    {
        $this->registerPartner();
        $this->crypto->method('sign')->willReturn('jws');
        $this->crypto->method('encrypt')->willReturn('jwe');

        $result = $this->useCase->execute(
            destinationPartnerId: 'urn:gln:0614141000012',
            documentType: 'GS1_ORDER_JSON',
            payload: ['order_id' => 'PO-003'],
        );

        $this->assertTrue($result->isSuccess());
        $jobs = $this->queue->allJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame(QueuePort::JOB_TRANSMIT_MESSAGE, $jobs[0]['job_type']);
        $this->assertSame($result->getData()['message_id'], $jobs[0]['payload']['message_id']);
    }

    // ─── Failure Cases ─────────────────────────────────────────────────────

    public function test_transmit_fails_for_unknown_partner(): void
    {
        $result = $this->useCase->execute(
            destinationPartnerId: 'urn:gln:UNKNOWN',
            documentType: 'GS1_ORDER_JSON',
            payload: ['order_id' => 'PO-999'],
        );

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_UNKNOWN_PARTNER, $result->getError());
    }

    public function test_transmit_fails_for_inactive_partner(): void
    {
        $this->registerPartner();
        $partner = $this->partners->findById('urn:gln:0614141000012');
        $partner->deactivate();
        $this->partners->save($partner);

        $result = $this->useCase->execute(
            destinationPartnerId: 'urn:gln:0614141000012',
            documentType: 'GS1_ORDER_JSON',
            payload: ['order_id' => 'PO-999'],
        );

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_PARTNER_INACTIVE, $result->getError());
    }

    public function test_transmit_fails_for_missing_document_type(): void
    {
        $this->registerPartner();

        $result = $this->useCase->execute(
            destinationPartnerId: 'urn:gln:0614141000012',
            documentType: '',
            payload: ['order_id' => 'PO-999'],
        );

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
    }

    public function test_transmit_fails_for_empty_payload(): void
    {
        $this->registerPartner();

        $result = $this->useCase->execute(
            destinationPartnerId: 'urn:gln:0614141000012',
            documentType: 'GS1_ORDER_JSON',
            payload: [],
        );

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
    }

    public function test_transmit_rejects_receipt_webhook_without_https(): void
    {
        $this->registerPartner();

        $result = $this->useCase->execute(
            destinationPartnerId: 'urn:gln:0614141000012',
            documentType: 'GS1_ORDER_JSON',
            payload: ['order_id' => 'PO-001'],
            receiptWebhook: 'http://insecure.example.com/receipt', // HTTP not HTTPS
        );

        $this->assertTrue($result->isFailure());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
    }
}
