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
     * Schema matches fidex-protocol-specification.md §6.2 exactly:
     * nested `endpoints` and `security` documents, canonical field names.
     * Trading partners use this URL to register with your node.
     *
     * @param array<string, string> $params
     */
    public function as5Config(array $params): void
    {
        // Strip query string from base URL when deriving public_domain.
        $publicDomain = (string) parse_url($this->nodeBaseUrl, PHP_URL_HOST);

        $config = [
            'fidex_version'             => '1.0',
            'supported_versions'        => ['1.0'],
            'conformance_profile'       => 'core',
            'node_id'                   => $this->nodeId,
            'organization_name'         => $this->nodeName,
            'public_domain'             => $publicDomain,
            'supported_document_types'  => [],
            'endpoints' => [
                'receive_message' => $this->nodeBaseUrl . '/api/v1/receive',
                'receive_receipt' => $this->nodeBaseUrl . '/api/v1/receipt',
                'register'        => $this->nodeBaseUrl . '/api/v1/register',
                'jwks'            => $this->nodeBaseUrl . '/.well-known/jwks.json',
            ],
            'security' => [
                'signature_algorithm'  => 'RS256',
                'encryption_algorithm' => 'RSA-OAEP',
                'content_encryption'   => 'A256GCM',
                'minimum_key_size'     => 2048,
            ],
        ];

        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');
        header('X-FideX-Node: fidex-php/1.0');

        echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
