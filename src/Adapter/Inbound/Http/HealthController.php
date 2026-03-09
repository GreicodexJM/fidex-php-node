<?php

declare(strict_types=1);

namespace FideX\Adapter\Inbound\Http;

use FideX\Port\Outbound\QueuePort;

/**
 * GET /health — Liveness and readiness probe
 *
 * Returns node health status including:
 * - Database connectivity
 * - Key file availability
 * - Queue depth
 */
final class HealthController
{
    public function __construct(
        private readonly \PDO    $pdo,
        private readonly QueuePort $queue,
        private readonly string  $signPrivateKeyPath,
        private readonly string  $encPrivateKeyPath,
        private readonly string  $nodeId,
    ) {
    }

    /** @param array<string, string> $params */
    public function __invoke(array $params): void
    {
        $checks = [];
        $healthy = true;

        // Database check
        try {
            $this->pdo->query('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'error: ' . $e->getMessage();
            $healthy = false;
        }

        // Keys check
        $checks['sign_key'] = file_exists($this->signPrivateKeyPath) ? 'ok' : 'missing';
        $checks['enc_key']  = file_exists($this->encPrivateKeyPath)  ? 'ok' : 'missing';

        if ($checks['sign_key'] !== 'ok' || $checks['enc_key'] !== 'ok') {
            $healthy = false;
        }

        // Queue depth
        try {
            $checks['queue_pending'] = $this->queue->countPending();
        } catch (\Throwable) {
            $checks['queue_pending'] = 'unknown';
        }

        $status = $healthy ? 'healthy' : 'degraded';
        $code   = $healthy ? 200 : 503;

        Router::json([
            'status'         => $status,
            'node_id'        => $this->nodeId,
            'implementation' => 'fidex-php/1.0',
            'checks'         => $checks,
            'timestamp'      => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ], $code);
    }
}
