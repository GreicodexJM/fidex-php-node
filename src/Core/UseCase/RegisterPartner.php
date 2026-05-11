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
        /**
         * Development/testing escape hatch: when true, accepts plain http://
         * as5_config_url values. Always false in production.
         * Toggled via the FIDEX_ALLOW_HTTP_REGISTRATION env var (default false).
         */
        private readonly bool                  $allowHttp = false,
    ) {
    }

    public function execute(string $as5ConfigUrl, bool $overwrite = false): Result
    {
        // ── Step 1: Validate URL ────────────────────────────────────────────

        if (empty($as5ConfigUrl)) {
            return Result::failure(Result::ERR_VALIDATION_ERROR, 'as5_config_url is required.');
        }

        $isHttps = str_starts_with($as5ConfigUrl, 'https://');
        $isHttp  = str_starts_with($as5ConfigUrl, 'http://');
        if (!$isHttps && !($this->allowHttp && $isHttp)) {
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

        // ── Step 3: Extract required fields (spec §6.2 shape) ──────────────

        // The canonical AS5 config document carries the per-operation URLs in
        // a nested `endpoints` object. Field names are node_id / organization_name.
        if (empty($config['node_id'])) {
            return Result::failure(
                Result::ERR_VALIDATION_ERROR,
                'AS5 config is missing required field: node_id'
            );
        }
        if (empty($config['organization_name'])) {
            return Result::failure(
                Result::ERR_VALIDATION_ERROR,
                'AS5 config is missing required field: organization_name'
            );
        }
        $endpoints = $config['endpoints'] ?? null;
        if (!is_array($endpoints)) {
            return Result::failure(
                Result::ERR_VALIDATION_ERROR,
                'AS5 config is missing required object: endpoints'
            );
        }
        foreach (['receive_message', 'receive_receipt', 'jwks'] as $epField) {
            if (empty($endpoints[$epField])) {
                return Result::failure(
                    Result::ERR_VALIDATION_ERROR,
                    "AS5 config is missing required endpoint: endpoints.{$epField}"
                );
            }
        }

        $partnerId       = (string) $config['node_id'];
        $name            = (string) $config['organization_name'];
        $receiveEndpoint = (string) $endpoints['receive_message'];
        $receiptEndpoint = (string) $endpoints['receive_receipt'];
        $jwksUrl         = (string) $endpoints['jwks'];

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
