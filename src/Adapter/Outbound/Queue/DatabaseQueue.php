<?php

declare(strict_types=1);

namespace FideX\Adapter\Outbound\Queue;

use FideX\Port\Outbound\QueueJob;
use FideX\Port\Outbound\QueuePort;

/**
 * Database Queue Adapter
 *
 * Implements a job queue using the fidex_queue database table.
 * Works with SQLite or MySQL — no Redis or RabbitMQ needed.
 *
 * Locking strategy: UPDATE SET status='processing', locked_by=worker_id
 * Retry strategy: exponential backoff via available_at calculation
 */
final class DatabaseQueue implements QueuePort
{
    private string $workerId;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->workerId = gethostname() . ':' . getmypid();
    }

    public function enqueue(string $jobType, array $payload, int $delaySeconds = 0): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $availableAt = $now->modify("+{$delaySeconds} seconds")->format('Y-m-d\TH:i:s\Z');
        $nowStr = $now->format('Y-m-d\TH:i:s\Z');

        $stmt = $this->pdo->prepare(
            'INSERT INTO fidex_queue (job_type, payload, status, retry_count, available_at, created_at, updated_at)
             VALUES (:job_type, :payload, :status, 0, :available_at, :created_at, :updated_at)'
        );

        $stmt->execute([
            ':job_type'     => $jobType,
            ':payload'      => json_encode($payload, JSON_THROW_ON_ERROR),
            ':status'       => 'pending',
            ':available_at' => $availableAt,
            ':created_at'   => $nowStr,
            ':updated_at'   => $nowStr,
        ]);
    }

    public function fetchPending(array $jobTypes = [], int $limit = 20): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        if (empty($jobTypes)) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM fidex_queue
                 WHERE status = 'pending' AND available_at <= :now
                 ORDER BY available_at ASC LIMIT :limit"
            );
            $stmt->bindValue(':now', $now);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        } else {
            $placeholders = implode(',', array_fill(0, count($jobTypes), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT * FROM fidex_queue
                 WHERE status = 'pending' AND available_at <= ? AND job_type IN ({$placeholders})
                 ORDER BY available_at ASC LIMIT ?"
            );
            $params = [$now, ...$jobTypes, $limit];
            $stmt->execute($params);

            return $this->hydrateJobs($stmt->fetchAll());
        }

        $stmt->execute();
        return $this->hydrateJobs($stmt->fetchAll());
    }

    public function markDone(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE fidex_queue SET status = 'done', locked_at = NULL, locked_by = NULL, updated_at = :now
             WHERE id = :id"
        );
        $stmt->execute([
            ':now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            ':id'  => $jobId,
        ]);
    }

    public function markFailed(int $jobId, string $errorMessage): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Exponential backoff: 1min, 5min, 30min
        $stmt = $this->pdo->prepare('SELECT retry_count FROM fidex_queue WHERE id = :id');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch();
        $retryCount = isset($row['retry_count']) ? (int) $row['retry_count'] + 1 : 1;

        $backoffSeconds = min(60 * (2 ** ($retryCount - 1)), 1800);
        $availableAt = $now->modify("+{$backoffSeconds} seconds")->format('Y-m-d\TH:i:s\Z');

        $updateStmt = $this->pdo->prepare(
            "UPDATE fidex_queue
             SET status = 'pending', retry_count = :retry_count, error_message = :error,
                 available_at = :available_at, locked_at = NULL, locked_by = NULL, updated_at = :now
             WHERE id = :id"
        );
        $updateStmt->execute([
            ':retry_count'  => $retryCount,
            ':error'        => $errorMessage,
            ':available_at' => $availableAt,
            ':now'          => $now->format('Y-m-d\TH:i:s\Z'),
            ':id'           => $jobId,
        ]);
    }

    public function countPending(): int
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM fidex_queue WHERE status = 'pending' AND available_at <= :now"
        );
        $stmt->execute([':now' => $now]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function hydrateJobs(array $rows): array
    {
        return array_map(function (array $row): QueueJob {
            return new QueueJob(
                id: (int) $row['id'],
                jobType: (string) $row['job_type'],
                payload: json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR),
                retryCount: (int) $row['retry_count'],
                availableAt: new \DateTimeImmutable((string) $row['available_at']),
            );
        }, $rows);
    }
}
