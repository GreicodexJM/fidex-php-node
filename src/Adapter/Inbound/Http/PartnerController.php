<?php

declare(strict_types=1);

namespace FideX\Adapter\Inbound\Http;

use FideX\Core\UseCase\RegisterPartner;

/**
 * POST /api/v1/partners/register — Partner Registration
 *
 * Registers a new trading partner from their AS5 config URL.
 * Supports both internal (API-key protected) and public registration.
 *
 * Request:
 * { "as5_config_url": "https://partner.example.com/as5/config?token=xyz" }
 */
final class PartnerController
{
    public function __construct(
        private readonly RegisterPartner $useCase,
        private readonly string          $apiKey,
    ) {
    }

    /** @param array<string, string> $params */
    public function __invoke(array $params): void
    {
        // Auth: internal registration requires API key
        $token = Router::getBearerToken();
        if ($token !== $this->apiKey) {
            Router::error('UNAUTHORIZED', 'Invalid or missing API key.', 401);
            return;
        }

        $body         = Router::parseJsonBody();
        $as5ConfigUrl = (string) ($body['as5_config_url'] ?? '');
        $overwrite    = (bool) ($body['overwrite'] ?? false);

        $result = $this->useCase->execute($as5ConfigUrl, $overwrite);

        if ($result->isSuccess()) {
            Router::json($result->getData(), 201);
        } else {
            Router::error($result->getError(), $result->getMessage(), $result->toHttpStatusCode());
        }
    }
}
