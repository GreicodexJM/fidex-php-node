<?php

declare(strict_types=1);

namespace FideX\Adapter\Inbound\Http;

use FideX\Core\Domain\KeyPair;
use FideX\Port\Outbound\CryptoServicePort;

/**
 * Discovery Endpoints
 *
 * GET /.well-known/jwks.json  — Public key set (REQUIRED by FideX spec)
 * GET /as5/config             — Node discovery configuration
 *
 * These endpoints are public and require no authentication.
 */
final class DiscoveryController
{
    public function __construct(
        private readonly CryptoServicePort $cryptoService,
        private readonly KeyPair           $signingKeyPair,
        private readonly KeyPair           $encryptionKeyPair,
        private readonly string            $nodeId,
        private readonly string            $nodeName,
        private readonly string            $nodeBaseUrl,
    ) {
    }

    /**
     * GET /.well-known/jwks.json
     *
     * Returns this node's public keys in JWKS format.
     * Required by FideX spec for automated partner key discovery.
     *
     * @param array<string, string> $params
     */
    public function jwks(array $params): void
    {
        $sigJwk = $this->cryptoService->pemToJwk(
            $this->signingKeyPair->getPublicKeyPem(),
            $this->signingKeyPair->getKeyId(),
            'sig',
            'RS256'
        );

        $encJwk = $this->cryptoService->pemToJwk(
            $this->encryptionKeyPair->getPublicKeyPem(),
            $this->encryptionKeyPair->getKeyId(),
            'enc',
            'RSA-OAEP'
        );

        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        header('X-FideX-Node: fidex-php/1.0');

        echo json_encode(['keys' => [$sigJwk, $encJwk]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /as5/config
     *
     * Returns this node's AS5 discovery configuration.
     * Trading partners use this URL to register with your node.
     *
     * @param array<string, string> $params
     */
    public function as5Config(array $params): void
    {
        $config = [
            'fidex_version'    => '1.0',
            'partner_id'       => $this->nodeId,
            'name'             => $this->nodeName,
            'receive_endpoint' => $this->nodeBaseUrl . '/api/v1/receive',
            'receipt_endpoint' => $this->nodeBaseUrl . '/api/v1/receipt',
            'jwks_url'         => $this->nodeBaseUrl . '/.well-known/jwks.json',
            'as5_config_url'   => $this->nodeBaseUrl . '/as5/config',
            'capabilities'     => ['sign', 'encrypt', 'receive', 'receipt'],
            'implementation'   => 'fidex-php/1.0 (https://github.com/GreicodexJM/fidex-protocol)',
        ];

        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');
        header('X-FideX-Node: fidex-php/1.0');

        echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
