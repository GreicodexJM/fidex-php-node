<?php

declare(strict_types=1);

namespace FideX\Adapter\Inbound\Http;

use FideX\Core\UseCase\ProcessReceipt;

/**
 * POST /api/v1/receipt — Public B2B endpoint
 *
 * Receives J-MDN receipts from trading partners.
 * Verifies signature and updates the original message status.
 */
final class ReceiptController
{
    public function __construct(private readonly ProcessReceipt $useCase) {}

    /** @param array<string, string> $params */
    public function __invoke(array $params): void
    {
        $body = Router::parseJsonBody();

        if (empty($body)) {
            Router::error('VALIDATION_ERROR', 'Request body must be a valid JSON J-MDN.', 400);
            return;
        }

        $result = $this->useCase->execute($body);

        if ($result->isSuccess()) {
            Router::json(['acknowledged' => true, ...$result->getData()], 200);
        } else {
            Router::error($result->getError(), $result->getMessage(), $result->toHttpStatusCode());
        }
    }
}
