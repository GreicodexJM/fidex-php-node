<?php

declare(strict_types=1);

namespace FideX\Port\Outbound;

/**
 * Outbound Port: Message Queue
 *
 * Defines how the domain core enqueues and dequeues work items.
 * Implementation: DatabaseQueue (SQLite/MySQL table-based)
 *
 * The queue is used to:
 *   - Defer outbound HTTP transmission after initial ERP request
 *   - Defer inbound JWE decryption after receiving envelope (return 202 fast)
 *   - Defer J-MDN dispatch after decryption
 */
interface QueuePort
{
    public const JOB_TRANSMIT_MESSAGE = 'transmit_message';
    public const JOB_PROCESS_INBOUND  = 'process_inbound';
    public const JOB_SEND_JMDN        = 'send_jmdn';

    /**
     * Enqueue a job for async processing.
     *
     * @param string $jobType One of the JOB_* constants
     * @param array<string, mixed> $payload Job-specific data
     * @param int $delaySeconds Seconds to wait before processing (0 = immediate)
     */
    public function enqueue(string $jobType, array $payload, int $delaySeconds = 0): void;

    /**
     * Fetch the next N pending jobs of given type(s).
     *
     * @param string[] $jobTypes Job types to fetch (empty = all types)
     * @param int $limit
     * @return QueueJob[]
     */
    public function fetchPending(array $jobTypes = [], int $limit = 20): array;

    /**
     * Mark a job as completed and remove from queue.
     */
    public function markDone(int $jobId): void;

    /**
     * Mark a job as failed (increment retry count).
     */
    public function markFailed(int $jobId, string $errorMessage): void;

    /**
     * Return the number of pending jobs.
     */
    public function countPending(): int;
}
