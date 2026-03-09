<?php

declare(strict_types=1);

namespace FideX\Port\Outbound;

/**
 * Queue Job Value Object
 *
 * Represents a single pending job fetched from the queue.
 * Returned by QueuePort::fetchPending().
 */
final class QueueJob
{
    public function __construct(
        private readonly int    $id,
        private readonly string $jobType,
        private readonly array  $payload,
        private readonly int    $retryCount,
        private readonly \DateTimeImmutable $availableAt,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getJobType(): string
    {
        return $this->jobType;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getAvailableAt(): \DateTimeImmutable
    {
        return $this->availableAt;
    }
}
