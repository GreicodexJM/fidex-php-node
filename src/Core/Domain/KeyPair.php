<?php

declare(strict_types=1);

namespace FideX\Core\Domain;

/**
 * Key Pair Value Object
 *
 * Holds an RSA key pair (private + public) used for signing or encryption.
 * Immutable. Created once at bootstrap from PEM files.
 */
final class KeyPair
{
    public const USE_SIGN = 'sig';
    public const USE_ENC  = 'enc';

    public function __construct(
        private readonly string $keyId,
        private readonly string $use,
        private readonly string $privateKeyPem,
        private readonly string $publicKeyPem,
        private readonly string $algorithm,
    ) {
    }

    public static function fromPemFiles(
        string $keyId,
        string $use,
        string $privateKeyPath,
        string $publicKeyPath,
        string $algorithm = '',
    ): self {
        $privateKeyPem = file_get_contents($privateKeyPath);
        $publicKeyPem  = file_get_contents($publicKeyPath);

        if ($privateKeyPem === false || $publicKeyPem === false) {
            throw new \RuntimeException("Could not read key files: {$privateKeyPath} / {$publicKeyPath}");
        }

        $defaultAlg = $use === self::USE_SIGN ? 'RS256' : 'RSA-OAEP';
        return new self($keyId, $use, $privateKeyPem, $publicKeyPem, $algorithm ?: $defaultAlg);
    }

    public function getKeyId(): string       { return $this->keyId; }
    public function getUse(): string         { return $this->use; }
    public function getPrivateKeyPem(): string { return $this->privateKeyPem; }
    public function getPublicKeyPem(): string  { return $this->publicKeyPem; }
    public function getAlgorithm(): string   { return $this->algorithm; }
}
