<?php

declare(strict_types=1);

namespace FideX\Tests\Integration;

use FideX\Adapter\Outbound\Persistence\DatabaseConnection;
use FideX\Adapter\Outbound\Persistence\SqliteMessageRepository;
use FideX\Adapter\Outbound\Persistence\SqlitePartnerRepository;
use FideX\Adapter\Outbound\Queue\DatabaseQueue;
use FideX\Core\Domain\Message;
use FideX\Core\Domain\MessageStatus;
use FideX\Core\Domain\Partner;
use PHPUnit\Framework\TestCase;

/**
 * MessageFlowTest — Integration
 *
 * Exercises the SQLite adapters against a real in-memory database.
 * No network calls, no filesystem side effects.
 *
 * Covers:
 *  - SqliteMessageRepository: save, findById, idempotency
 *  - SqlitePartnerRepository: save, findById, exists, findAll
 *  - DatabaseQueue: push, pop, complete, fail
 */
final class MessageFlowTest extends TestCase
{
    private \PDO                    $pdo;
    private SqliteMessageRepository $messageRepo;
    private SqlitePartnerRepository $partnerRepo;
    private DatabaseQueue           $queue;

    protected function setUp(): void
    {
        // Fresh :memory: SQLite for every test — perfect isolation
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        // Run schema migrations inline
        $schema = file_get_contents(dirname(__DIR__, 2) . '/src/Adapter/Outbound/Persistence/schema.sql');
        $sql    = preg_replace('/--[^\n]*/', '', $schema) ?? $schema;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $this->pdo->exec($stmt . ';');
        }

        // All adapters take \PDO directly in their constructors
        $this->messageRepo = new SqliteMessageRepository($this->pdo);
        $this->partnerRepo = new SqlitePartnerRepository($this->pdo);
        $this->queue       = new DatabaseQueue($this->pdo);

        // Also inject singleton so any code using DatabaseConnection::get() gets the same connection
        DatabaseConnection::inject($this->pdo);
    }

    protected function tearDown(): void
    {
        DatabaseConnection::reset();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeOutboundMessage(string $id = 'msg-int-001'): Message
    {
        return Message::createOutbound(
            messageId: $id,
            senderId: 'urn:custom:my-node',
            receiverId: 'urn:custom:partner-x',
            documentType: 'GS1_ORDER_JSON',
            rawPayload: ['order_id' => 'INT-001'],
        );
    }

    private function makePartner(string $id = 'urn:custom:partner-x'): Partner
    {
        return Partner::register(
            partnerId: $id,
            name: 'Integration Test Partner',
            receiveEndpoint: 'https://partner-x.example.com/receive',
            receiptEndpoint: 'https://partner-x.example.com/receipt',
            jwksUrl: 'https://partner-x.example.com/.well-known/jwks.json',
            as5ConfigUrl: 'https://partner-x.example.com/as5/config',
        );
    }

    // ── Message Repository Tests ───────────────────────────────────────────

    public function test_message_can_be_saved_and_retrieved_by_id(): void
    {
        $message = $this->makeOutboundMessage('msg-persist-01');
        $this->messageRepo->save($message);

        $found = $this->messageRepo->findById('msg-persist-01');

        $this->assertNotNull($found);
        $this->assertSame('msg-persist-01', $found->getMessageId());
        $this->assertSame(MessageStatus::PENDING, $found->getStatus());
    }

    public function test_message_status_update_is_persisted(): void
    {
        $message = $this->makeOutboundMessage('msg-status-01');
        $this->messageRepo->save($message);

        $message->markAcknowledged();
        $this->messageRepo->save($message);

        $found = $this->messageRepo->findById('msg-status-01');
        $this->assertSame(MessageStatus::ACKNOWLEDGED, $found->getStatus());
    }

    public function test_find_by_id_returns_null_for_unknown_message(): void
    {
        $this->assertNull($this->messageRepo->findById('does-not-exist'));
    }

    public function test_saving_message_with_same_id_updates_rather_than_duplicates(): void
    {
        $message = $this->makeOutboundMessage('msg-upsert-01');
        $this->messageRepo->save($message);
        $message->markAcknowledged();
        $this->messageRepo->save($message); // second save = UPDATE

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM fidex_messages WHERE message_id = 'msg-upsert-01'"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    // ── Partner Repository Tests ───────────────────────────────────────────

    public function test_partner_can_be_saved_and_retrieved(): void
    {
        $partner = $this->makePartner('urn:custom:partner-save-test');
        $this->partnerRepo->save($partner);

        $found = $this->partnerRepo->findById('urn:custom:partner-save-test');
        $this->assertNotNull($found);
        $this->assertSame('Integration Test Partner', $found->getName());
    }

    public function test_partner_exists_returns_true_after_save(): void
    {
        $this->partnerRepo->save($this->makePartner('urn:custom:exists-test'));

        $this->assertTrue($this->partnerRepo->exists('urn:custom:exists-test'));
        $this->assertFalse($this->partnerRepo->exists('urn:custom:does-not-exist'));
    }

    public function test_find_all_active_returns_all_active_partners(): void
    {
        $this->partnerRepo->save($this->makePartner('urn:custom:p1'));
        $this->partnerRepo->save($this->makePartner('urn:custom:p2'));

        $all = $this->partnerRepo->findAllActive();
        $this->assertCount(2, $all);
    }

    // ── Queue Tests ────────────────────────────────────────────────────────

    public function test_job_can_be_enqueued_and_fetched(): void
    {
        $this->queue->enqueue('transmit_message', ['message_id' => 'msg-queue-01']);

        $jobs = $this->queue->fetchPending(['transmit_message'], 1);
        $this->assertCount(1, $jobs);
        $this->assertSame('transmit_message', $jobs[0]->getJobType());
        $this->assertSame('msg-queue-01', $jobs[0]->getPayload()['message_id']);
    }

    public function test_fetch_pending_returns_empty_when_queue_is_empty(): void
    {
        $this->assertSame([], $this->queue->fetchPending());
    }

    public function test_completed_job_is_not_returned_by_fetch_pending(): void
    {
        $this->queue->enqueue('transmit_message', ['message_id' => 'msg-complete-01']);
        $jobs = $this->queue->fetchPending(['transmit_message'], 1);
        $this->assertCount(1, $jobs);
        $this->queue->markDone($jobs[0]->getId());

        $remaining = $this->queue->fetchPending(['transmit_message'], 10);
        $this->assertSame([], $remaining);
    }

    public function test_count_pending_reflects_queue_depth(): void
    {
        $this->assertSame(0, $this->queue->countPending());

        $this->queue->enqueue('job_a', []);
        $this->queue->enqueue('job_b', []);
        $this->assertSame(2, $this->queue->countPending());

        $jobs = $this->queue->fetchPending([], 1);
        $this->queue->markDone($jobs[0]->getId());
        $this->assertSame(1, $this->queue->countPending());
    }
}
