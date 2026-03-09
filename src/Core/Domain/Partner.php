<?php

declare(strict_types=1);

namespace FideX\Core\Domain;

/**
 * Partner Entity
 *
 * Represents a registered trading partner.
 * Stores discovery endpoints and cached JWKS.
 */
final class Partner
{
    private function __construct(
        private readonly string $partnerId,
        private string          $name,
        private string          $receiveEndpoint,
        private string          $receiptEndpoint,
        private string          $jwksUrl,
        private string          $as5ConfigUrl,
        private ?string         $cachedJwks,
        private ?\DateTimeImmutable $jwksCachedAt,
        private bool            $active,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable          $updatedAt,
    ) {
    }

    public static function register(
        string $partnerId,
        string $name,
        string $receiveEndpoint,
        string $receiptEndpoint,
        string $jwksUrl,
        string $as5ConfigUrl,
    ): self {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return new self(
            partnerId: $partnerId,
            name: $name,
            receiveEndpoint: $receiveEndpoint,
            receiptEndpoint: $receiptEndpoint,
            jwksUrl: $jwksUrl,
            as5ConfigUrl: $as5ConfigUrl,
            cachedJwks: null,
            jwksCachedAt: null,
            active: true,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        string $partnerId, string $name, string $receiveEndpoint, string $receiptEndpoint,
        string $jwksUrl, string $as5ConfigUrl, ?string $cachedJwks,
        ?\DateTimeImmutable $jwksCachedAt, bool $active,
        \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            partnerId: $partnerId, name: $name, receiveEndpoint: $receiveEndpoint,
            receiptEndpoint: $receiptEndpoint, jwksUrl: $jwksUrl, as5ConfigUrl: $as5ConfigUrl,
            cachedJwks: $cachedJwks, jwksCachedAt: $jwksCachedAt, active: $active,
            createdAt: $createdAt, updatedAt: $updatedAt,
        );
    }

    public function updateJwksCache(string $jwksJson): void
    {
        $this->cachedJwks   = $jwksJson;
        $this->jwksCachedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt    = $this->jwksCachedAt;
    }

    public function deactivate(): void
    {
        $this->active    = false;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function activate(): void
    {
        $this->active    = true;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getPartnerId(): string          { return $this->partnerId; }
    public function getName(): string               { return $this->name; }
    public function getReceiveEndpoint(): string    { return $this->receiveEndpoint; }
    public function getReceiptEndpoint(): string    { return $this->receiptEndpoint; }
    public function getJwksUrl(): string            { return $this->jwksUrl; }
    public function getAs5ConfigUrl(): string       { return $this->as5ConfigUrl; }
    public function getCachedJwks(): ?string        { return $this->cachedJwks; }
    public function getJwksCachedAt(): ?\DateTimeImmutable { return $this->jwksCachedAt; }
    public function isActive(): bool                { return $this->active; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
