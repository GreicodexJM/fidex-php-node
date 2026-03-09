<?php

declare(strict_types=1);

namespace FideX\Tests\Doubles;

use FideX\Core\Domain\Message;
use FideX\Core\Domain\MessageStatus;
use FideX\Port\Outbound\MessageRepositoryPort;

/**
 * In-Memory Message Repository (Test Double)
 *
 * Used in unit tests to avoid database dependencies.
 * Implements the full MessageRepositoryPort contract.
 */
final class InMemoryMessageRepository implements MessageRepositoryPort
{
    /** @var array<string, Message> */
    private array $store = [];

    public function save(Message $message): void
    {
        $this->store[$message->getMessageId()] = $message;
    }

    public function findById(string $messageId): ?Message
    {
        return $this->store[$messageId] ?? null;
    }

    public function findByStatus(MessageStatus $status, int $limit = 50): array
    {
        return array_slice(
            array_values(
                array_filter(
                    $this->store,
                    fn (Message $m) => $m->getStatus() === $status
                )
            ),
            0,
            $limit
        );
    }

    public function findPendingRetries(int $maxRetries, int $limit = 20): array
    {
        return array_slice(
            array_values(
                array_filter(
                    $this->store,
                    fn (Message $m) => $m->getStatus() === MessageStatus::RETRY
                                       && $m->getRetryCount() < $maxRetries
                )
            ),
            0,
            $limit
        );
    }

    public function exists(string $messageId): bool
    {
        return isset($this->store[$messageId]);
    }

    /**
     * Helper for tests: get all stored messages.
     *
     * @return Message[]
     */
    public function all(): array
    {
        return array_values($this->store);
    }

    public function clear(): void
    {
        $this->store = [];
    }
}
