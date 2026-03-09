<?php

declare(strict_types=1);

namespace FideX\Adapter\Inbound\Http;

use FideX\Core\UseCase\ReceiveMessage;

/**
 * POST /api/v1/receive — Public B2B endpoint
 *
 * Receives encrypted FideX envelopes from trading partners.
 * Returns HTTP 202 immediately; processing is async.
 */
final class ReceiveController
{
    public function __construct(private readonly ReceiveMessage $useCase) {}

    /** @param array<string, string> $params */
    public function __invoke(array $params): void
    {
        $body = Router::parseJsonBody();

        if (empty($body)) {
            Router::error('VALIDATION_ERROR', 'Request body must be a valid JSON FideX envelope.', 400);
            return;
        }

        $result = $this->useCase->execute($body);

        if ($result->isSuccess()) {
            Router::json($result->getData(), 202);
        } else {
            Router::error($result->getError(), $result->getMessage(), $result->toHttpStatusCode());
        }
    }
}
