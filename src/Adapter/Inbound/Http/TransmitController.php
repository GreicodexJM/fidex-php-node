<?php

declare(strict_types=1);

namespace FideX\Adapter\Inbound\Http;

use FideX\Core\UseCase\TransmitMessage;

/**
 * POST /api/v1/transmit — Internal ERP endpoint
 *
 * Accepts a raw payload from the local ERP, signs, encrypts, and queues
 * it for transmission to the destination partner.
 *
 * Requires: Bearer token or X-API-Key header matching FIDEX_API_KEY.
 *
 * Request:
 * {
 *   "destination_partner_id": "urn:gln:...",
 *   "document_type": "GS1_ORDER_JSON",
 *   "receipt_webhook": "https://...",  // optional
 *   "payload": { ... }
 * }
 *
 * Response 202:
 * {
 *   "message_id": "fdx-...",
 *   "status": "QUEUED",
 *   "timestamp": "2026-..."
 * }
 */
final class TransmitController
{
    public function __construct(
        private readonly TransmitMessage $useCase,
        private readonly string          $apiKey,
    ) {
    }

    /** @param array<string, string> $params */
    public function __invoke(array $params): void
    {
        // ── Auth check ─────────────────────────────────────────────────────
        $token = Router::getBearerToken();
        if ($token !== $this->apiKey) {
            Router::error('UNAUTHORIZED', 'Invalid or missing API key.', 401);
            return;
        }

        // ── Parse body ─────────────────────────────────────────────────────
        $body = Router::parseJsonBody();

        $destinationPartnerId = (string) ($body['destination_partner_id'] ?? '');
        $documentType         = (string) ($body['document_type'] ?? '');
        $payload              = $body['payload'] ?? [];
        $receiptWebhook       = isset($body['receipt_webhook']) ? (string) $body['receipt_webhook'] : null;

        if (!is_array($payload)) {
            Router::error('VALIDATION_ERROR', 'payload must be a JSON object.', 400);
            return;
        }

        // ── Execute use case ───────────────────────────────────────────────
        $result = $this->useCase->execute(
            destinationPartnerId: $destinationPartnerId,
            documentType: $documentType,
            payload: $payload,
            receiptWebhook: $receiptWebhook,
        );

        if ($result->isSuccess()) {
            Router::json($result->getData(), 202);
        } else {
            Router::error($result->getError(), $result->getMessage(), $result->toHttpStatusCode());
        }
    }
}
