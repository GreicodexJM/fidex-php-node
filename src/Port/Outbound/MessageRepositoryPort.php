<?php

declare(strict_types=1);

namespace FideX\Port\Outbound;

use FideX\Core\Domain\Message;
use FideX\Core\Domain\MessageStatus;

/**
 * Outbound Port: Message Persistence
 *
 * Defines how the domain core interacts with message storage.
 * Implementations: SqliteMessageRepository, InMemoryMessageRepository (tests)
 */
interface MessageRepositoryPort
{
    /**
     * Persist a new message or update an existing one.
     */
    public function save(Message $message): void;

    /**
     * Find a message by its unique ID.
     */
    public function findById(string $messageId): ?Message;

    /**
     * Find all messages with a given status.
     *
     * @return Message[]
     */
    public function findByStatus(MessageStatus $status, int $limit = 50): array;

    /**
     * Find messages ready for retry (status=RETRY, retry_count < maxRetries).
     *
     * @return Message[]
     */
    public function findPendingRetries(int $maxRetries, int $limit = 20): array;

    /**
     * Check if a message ID already exists (idempotency check).
     */
    public function exists(string $messageId): bool;
}
