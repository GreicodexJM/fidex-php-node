<?php

declare(strict_types=1);

namespace FideX\Core\UseCase;

use FideX\Core\Domain\Partner;
use FideX\Port\Outbound\HttpClientPort;
use FideX\Port\Outbound\LoggerPort;
use FideX\Port\Outbound\PartnerRepositoryPort;

/**
 * RegisterPartner Use Case
 *
 * Registers a new trading partner using their AS5 discovery config URL.
 *
 * 1. Validate the AS5 config URL (must be HTTPS)
 * 2. Fetch the AS5 config from the URL
 * 3. Extract partner endpoints (receive, receipt, JWKS, partner_id)
 * 4. Fetch the partner's JWKS and cache it
 * 5. Persist the partner
 *
 * Supports both QR-code scan and manual URL registration flows.
 */
final class RegisterPartner
{
    public function __construct(
        private readonly PartnerRepositoryPort $partnerRepository,
        private readonly HttpClientPort        $httpClient,
        private readonly LoggerPort            $logger,
    ) {
    }

    public function execute(string $as5ConfigUrl, bool $overwrite = false): Result
    {
        // ── Step 1: Validate URL ────────────────────────────────────────────

        if (empty($as5ConfigUrl)) {
            return Result::failure(Result::ERR_VALIDATION_ERROR, 'as5_config_url is required.');
        }

        if (!str_starts_with($as5ConfigUrl, 'https://')) {
            return Result::failure(
                Result::ERR_VALIDATION_ERROR,
                'as5_config_url must use HTTPS.'
            );
        }

        // ── Step 2: Fetch AS5 config ────────────────────────────────────────

        $response = $this->httpClient->get($as5ConfigUrl);

        if (!$response->isSuccess()) {
            return Result::failure(
                Result::ERR_PROCESSING_ERROR,
                "Failed to fetch AS5 config from {$as5ConfigUrl}: HTTP {$response->getStatusCode()}"
            );
        }

        $config = $response->json();

        if ($config === null) {
            return Result::failure(
                Result::ERR_PROCESSING_ERROR,
                'AS5 config response is not valid JSON.'
            );
        }

        // ── Step 3: Extract required fields ────────────────────────────────

        $required = ['partner_id', 'name', 'receive_endpoint', 'receipt_endpoint', 'jwks_url'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                return Result::failure(
                    Result::ERR_VALIDATION_ERROR,
                    "AS5 config is missing required field: {$field}"
                );
            }
        }

        $partnerId       = (string) $config['partner_id'];
        $name            = (string) $config['name'];
        $receiveEndpoint = (string) $config['receive_endpoint'];
        $receiptEndpoint = (string) $config['receipt_endpoint'];
        $jwksUrl         = (string) $config['jwks_url'];

        // ── Step 4: Check for existing partner ─────────────────────────────

        if ($this->partnerRepository->exists($partnerId) && !$overwrite) {
            return Result::failure(
                Result::ERR_DUPLICATE_MESSAGE,
                "Partner '{$partnerId}' is already registered. Use overwrite=true to update."
            );
        }

        // ── Step 5: Fetch and cache JWKS ───────────────────────────────────

        $jwksResponse = $this->httpClient->get($jwksUrl);
        $cachedJwks   = null;

        if ($jwksResponse->isSuccess()) {
            $jwks = $jwksResponse->json();
            if ($jwks !== null && isset($jwks['keys'])) {
                $cachedJwks = $jwksResponse->getBody();
            }
        } else {
            $this->logger->warning('RegisterPartner: could not fetch JWKS', [
                'partner_id' => $partnerId,
                'jwks_url'   => $jwksUrl,
                'status'     => $jwksResponse->getStatusCode(),
            ]);
        }

        // ── Step 6: Create and save partner ────────────────────────────────

        $partner = Partner::register(
            partnerId: $partnerId,
            name: $name,
            receiveEndpoint: $receiveEndpoint,
            receiptEndpoint: $receiptEndpoint,
            jwksUrl: $jwksUrl,
            as5ConfigUrl: $as5ConfigUrl,
        );

        if ($cachedJwks !== null) {
            $partner->updateJwksCache($cachedJwks);
        }

        $this->partnerRepository->save($partner);

        $this->logger->info('RegisterPartner: registered', [
            'partner_id' => $partnerId,
            'name'       => $name,
        ]);

        return Result::success([
            'partner_id'  => $partnerId,
            'name'        => $name,
            'jwks_cached' => $cachedJwks !== null,
        ]);
    }
}
