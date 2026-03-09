<?php

declare(strict_types=1);

namespace FideX\Adapter\Outbound\Crypto;

use FideX\Core\Domain\KeyPair;
use FideX\Port\Outbound\CryptoServicePort;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP;
use Jose\Component\Encryption\Compression\CompressionMethodManager;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer as JWECompactSerializer;
use Jose\Component\Encryption\Serializer\JWESerializerManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer as JWSCompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;

/**
 * JOSE Crypto Service
 *
 * Implements the FideX AS5 cryptographic operations using the
 * web-token/jwt-framework library.
 *
 * Protocol:
 *   - Sign-then-Encrypt: JWE(JWS(payload))
 *   - Signing:            RS256 (RFC 7518)
 *   - Key Encryption:     RSA-OAEP (RFC 7518)
 *   - Content Encryption: A256GCM (RFC 7518)
 *   - Min Key Size:       2048-bit RSA (4096 recommended)
 */
final class JoseCryptoService implements CryptoServicePort
{
    // ─── sign() ────────────────────────────────────────────────────────────

    /**
     * Sign a payload using RS256 with the sender's private key.
     *
     * {@inheritdoc}
     */
    public function sign(array $payload, KeyPair $signingKeyPair): string
    {
        $algorithmManager = new AlgorithmManager([new RS256()]);
        $jwsBuilder = new JWSBuilder($algorithmManager);
        $serializer = new JWSCompactSerializer();

        $jwk = $this->privateKeyPemToJwk($signingKeyPair->getPrivateKeyPem());

        $jws = $jwsBuilder
            ->create()
            ->withPayload(json_encode($payload, JSON_THROW_ON_ERROR))
            ->addSignature($jwk, [
                'alg' => 'RS256',
                'kid' => $signingKeyPair->getKeyId(),
            ])
            ->build();

        return $serializer->serialize($jws, 0);
    }

    // ─── encrypt() ─────────────────────────────────────────────────────────

    /**
     * Encrypt a JWS token with RSA-OAEP + A256GCM using the receiver's public key.
     * JWE header MUST include cty:JWT per FideX spec.
     *
     * {@inheritdoc}
     */
    public function encrypt(string $jwsToken, string $receiverPublicKeyPem, string $receiverKeyId): string
    {
        $keyEncAlgorithmManager     = new AlgorithmManager([new RSAOAEP()]);
        $contentEncAlgorithmManager = new AlgorithmManager([new A256GCM()]);
        $compressionMethodManager   = new CompressionMethodManager([]);

        $jweBuilder = new JWEBuilder(
            $keyEncAlgorithmManager,
            $contentEncAlgorithmManager,
            $compressionMethodManager,
        );

        $receiverJwk = $this->publicKeyPemToJwk($receiverPublicKeyPem);

        $jwe = $jweBuilder
            ->create()
            ->withPayload($jwsToken)
            ->withSharedProtectedHeader([
                'alg' => 'RSA-OAEP',
                'enc' => 'A256GCM',
                'cty' => 'JWT',   // REQUIRED by FideX spec
                'kid' => $receiverKeyId,
            ])
            ->addRecipient($receiverJwk)
            ->build();

        $serializer = new JWECompactSerializer();
        return $serializer->serialize($jwe, 0);
    }

    // ─── decrypt() ─────────────────────────────────────────────────────────

    /**
     * Decrypt a JWE token using this node's encryption private key.
     *
     * {@inheritdoc}
     */
    public function decrypt(string $jweToken, KeyPair $encryptionKeyPair): string
    {
        $keyEncAlgorithmManager     = new AlgorithmManager([new RSAOAEP()]);
        $contentEncAlgorithmManager = new AlgorithmManager([new A256GCM()]);

        $jweDecrypter = new JWEDecrypter(
            $keyEncAlgorithmManager,
            $contentEncAlgorithmManager,
            new CompressionMethodManager([]),
        );

        $serializerManager = new JWESerializerManager([new JWECompactSerializer()]);

        try {
            $jwe = $serializerManager->unserialize($jweToken);
        } catch (\Throwable $e) {
            throw new \RuntimeException('JWE deserialization failed: ' . $e->getMessage(), 0, $e);
        }

        $privateJwk = $this->privateKeyPemToJwk($encryptionKeyPair->getPrivateKeyPem());

        $success = $jweDecrypter->decryptUsingKey($jwe, $privateJwk, 0);

        if (!$success) {
            throw new \RuntimeException('JWE decryption failed: unable to decrypt with provided key.');
        }

        $payload = $jwe->getPayload();

        if ($payload === null || $payload === '') {
            throw new \RuntimeException('JWE decryption produced empty payload.');
        }

        return $payload;
    }

    // ─── verify() ──────────────────────────────────────────────────────────

    /**
     * Verify a JWS and extract its payload.
     *
     * {@inheritdoc}
     */
    public function verify(string $jwsToken, string $senderPublicKeyPem): array
    {
        $algorithmManager = new AlgorithmManager([new RS256()]);
        $jwsVerifier      = new JWSVerifier($algorithmManager);

        $serializerManager = new JWSSerializerManager([new JWSCompactSerializer()]);

        try {
            $jws = $serializerManager->unserialize($jwsToken);
        } catch (\Throwable $e) {
            throw new \RuntimeException('JWS deserialization failed: ' . $e->getMessage(), 0, $e);
        }

        $publicJwk = $this->publicKeyPemToJwk($senderPublicKeyPem);

        $isValid = $jwsVerifier->verifyWithKey($jws, $publicJwk, 0);

        if (!$isValid) {
            throw new \RuntimeException('JWS signature verification failed: invalid signature.');
        }

        $payloadJson = $jws->getPayload();

        if ($payloadJson === null || $payloadJson === '') {
            throw new \RuntimeException('JWS payload is empty after verification.');
        }

        $decoded = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('JWS payload is not a JSON object.');
        }

        return $decoded;
    }

    // ─── signReceipt() ─────────────────────────────────────────────────────

    /**
     * Sign a J-MDN receipt payload.
     *
     * {@inheritdoc}
     */
    public function signReceipt(array $jmdnPayload, KeyPair $signingKeyPair): string
    {
        return $this->sign($jmdnPayload, $signingKeyPair);
    }

    // ─── verifyReceipt() ───────────────────────────────────────────────────

    /**
     * Verify a J-MDN receipt signature.
     *
     * {@inheritdoc}
     */
    public function verifyReceipt(string $jwsSignature, string $senderPublicKeyPem): bool
    {
        try {
            $this->verify($jwsSignature, $senderPublicKeyPem);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    // ─── pemToJwk() ────────────────────────────────────────────────────────

    /**
     * Convert a PEM public key to JWK array (for JWKS endpoint).
     *
     * {@inheritdoc}
     */
    public function pemToJwk(string $publicKeyPem, string $keyId, string $use, string $algorithm): array
    {
        $jwk = JWKFactory::createFromKey(
            $publicKeyPem,
            null,
            [
                'kid' => $keyId,
                'use' => $use,
                'alg' => $algorithm,
            ],
        );

        return $jwk->all();
    }

    // ─── Private Helpers ───────────────────────────────────────────────────

    /**
     * Convert a PEM private key string to a JWK object.
     * web-token/jwt-framework >= 3.x expects a PEM string directly.
     */
    private function privateKeyPemToJwk(string $privateKeyPem): JWK
    {
        try {
            return JWKFactory::createFromKey($privateKeyPem, null, []);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to load private key: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Convert a PEM public key string to a JWK object.
     * web-token/jwt-framework >= 3.x expects a PEM string directly.
     */
    private function publicKeyPemToJwk(string $publicKeyPem): JWK
    {
        try {
            return JWKFactory::createFromKey($publicKeyPem, null, []);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to load public key: ' . $e->getMessage(), 0, $e);
        }
    }
}
