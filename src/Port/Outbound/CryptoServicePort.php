<?php

declare(strict_types=1);

namespace FideX\Port\Outbound;

use FideX\Core\Domain\KeyPair;

/**
 * Outbound Port: Cryptographic Operations
 *
 * Defines the sign-then-encrypt (JWE(JWS(payload))) operations required by FideX AS5.
 *
 * Required algorithms per spec:
 *   - Signing:            RS256 (RFC 7518)
 *   - Key Encryption:     RSA-OAEP (RFC 7518)
 *   - Content Encryption: A256GCM (RFC 7518)
 *
 * Implementation: JoseCryptoService (web-token/jwt-framework)
 */
interface CryptoServicePort
{
    /**
     * Sign a payload using RS256 with the node's signing private key.
     *
     * Returns a JWS compact serialization string.
     *
     * @param array<string, mixed> $payload The business document to sign
     * @param KeyPair $signingKeyPair The sender's signing key pair
     * @return string JWS compact serialization
     */
    public function sign(array $payload, KeyPair $signingKeyPair): string;

    /**
     * Encrypt a JWS token using RSA-OAEP + A256GCM with the receiver's public key.
     *
     * Returns a JWE compact serialization string.
     * Per spec: JWE MUST include "cty":"JWT" header.
     *
     * @param string $jwsToken The signed payload to encrypt
     * @param string $receiverPublicKeyPem The receiver's public key (PEM format)
     * @param string $receiverKeyId The receiver's key ID (kid)
     * @return string JWE compact serialization
     */
    public function encrypt(string $jwsToken, string $receiverPublicKeyPem, string $receiverKeyId): string;

    /**
     * Decrypt a JWE token using the node's encryption private key.
     *
     * Returns the inner JWS compact serialization.
     *
     * @param string $jweToken The encrypted envelope to decrypt
     * @param KeyPair $encryptionKeyPair The receiver's encryption key pair
     * @return string Inner JWS compact serialization
     * @throws \RuntimeException on decryption failure
     */
    public function decrypt(string $jweToken, KeyPair $encryptionKeyPair): string;

    /**
     * Verify a JWS signature and extract the payload.
     *
     * @param string $jwsToken The signed token to verify
     * @param string $senderPublicKeyPem The sender's public key (PEM format)
     * @return array<string, mixed> The verified payload as an associative array
     * @throws \RuntimeException on signature verification failure
     */
    public function verify(string $jwsToken, string $senderPublicKeyPem): array;

    /**
     * Sign a J-MDN receipt payload using RS256.
     *
     * @param array<string, mixed> $jmdnPayload The receipt data to sign
     * @param KeyPair $signingKeyPair This node's signing key pair
     * @return string JWS compact serialization
     */
    public function signReceipt(array $jmdnPayload, KeyPair $signingKeyPair): string;

    /**
     * Verify a J-MDN receipt signature.
     *
     * @param string $jwsSignature The JWS signature from the J-MDN
     * @param string $senderPublicKeyPem The sender's public key (PEM format)
     * @return bool True if valid
     */
    public function verifyReceipt(string $jwsSignature, string $senderPublicKeyPem): bool;

    /**
     * Convert a PEM public key to JWK array representation.
     *
     * @return array<string, mixed>
     */
    public function pemToJwk(string $publicKeyPem, string $keyId, string $use, string $algorithm): array;
}
