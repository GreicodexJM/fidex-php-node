<?php

declare(strict_types=1);

namespace FideX\Tests\Doubles;

use FideX\Port\Outbound\QueueJob;
use FideX\Port\Outbound\QueuePort;

/**
 * In-Memory Queue (Test Double)
 *
 * Used in unit tests to capture enqueued jobs without a database.
 */
final class InMemoryQueue implements QueuePort
{
    private int $idCounter = 1;

    /** @var array<int, array{id: int, job_type: string, payload: array, retry_count: int, status: string}> */
    private array $jobs = [];

    public function enqueue(string $jobType, array $payload, int $delaySeconds = 0): void
    {
        $id = $this->idCounter++;
        $this->jobs[$id] = [
            'id'          => $id,
            'job_type'    => $jobType,
            'payload'     => $payload,
            'retry_count' => 0,
            'status'      => 'pending',
        ];
    }

    public function fetchPending(array $jobTypes = [], int $limit = 20): array
    {
        $results = [];
        foreach ($this->jobs as $job) {
            if ($job['status'] !== 'pending') {
                continue;
            }
            if (!empty($jobTypes) && !in_array($job['job_type'], $jobTypes, true)) {
                continue;
            }
            $results[] = new QueueJob(
                id: $job['id'],
                jobType: $job['job_type'],
                payload: $job['payload'],
                retryCount: $job['retry_count'],
                availableAt: new \DateTimeImmutable(),
            );
            if (count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }

    public function markDone(int $jobId): void
    {
        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId]['status'] = 'done';
        }
    }

    public function markFailed(int $jobId, string $errorMessage): void
    {
        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId]['status'] = 'failed';
            $this->jobs[$jobId]['retry_count']++;
        }
    }

    public function countPending(): int
    {
        return count(array_filter($this->jobs, fn ($j) => $j['status'] === 'pending'));
    }

    /**
     * Test helper: get all enqueued jobs (any status).
     *
     * @return array<int, array{id: int, job_type: string, payload: array, retry_count: int, status: string}>
     */
    public function allJobs(): array
    {
        return array_values($this->jobs);
    }

    public function clear(): void
    {
        $this->jobs = [];
        $this->idCounter = 1;
    }
}
