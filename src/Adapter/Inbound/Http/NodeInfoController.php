<?php

declare(strict_types=1);

namespace FideX\Adapter\Inbound\Http;

use PDO;

/**
 * GET /api/v1/node-info — Public node identity endpoint
 *
 * Used by the QR onboarding webapp to discover this node's
 * identity and AS5 config URL without any authentication.
 *
 * Response is CORS-enabled so the browser app can call it.
 */
final class NodeInfoController
{
    public function __construct(
        private readonly string  $nodeId,
        private readonly string  $nodeName,
        private readonly string  $nodeBaseUrl,
        private readonly bool    $keysConfigured,
        private readonly PDO     $pdo,
    ) {
    }

    /** @param array<string, string> $params */
    public function __invoke(array $params): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $as5ConfigUrl = $this->nodeBaseUrl . '/as5/config';

        // Count registered partners
        $partnerCount = 0;
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM fidex_partners WHERE active = 1');
            $partnerCount = (int) ($stmt?->fetchColumn() ?? 0);
        } catch (\Throwable) {
            // DB not yet migrated — ignore
        }

        // Count queued jobs
        $queuedJobs = 0;
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM fidex_queue WHERE status = 'pending'");
            $queuedJobs = (int) ($stmt?->fetchColumn() ?? 0);
        } catch (\Throwable) {
            // DB not yet migrated — ignore
        }

        Router::json([
            'fidex_version'    => '1.0',
            'node_id'          => $this->nodeId,
            'node_name'        => $this->nodeName,
            'node_base_url'    => $this->nodeBaseUrl,
            'as5_config_url'   => $as5ConfigUrl,
            'qr_data'          => $as5ConfigUrl,
            'keys_configured'  => $this->keysConfigured,
            'partner_count'    => $partnerCount,
            'queued_jobs'      => $queuedJobs,
            'implementation'   => 'fidex-php/1.0',
        ]);
    }
}
