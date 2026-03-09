<?php

declare(strict_types=1);

namespace FideX\Adapter\Outbound\Persistence;

use FideX\Core\Domain\Partner;
use FideX\Port\Outbound\PartnerRepositoryPort;

/**
 * SQLite Partner Repository
 *
 * Implements PartnerRepositoryPort using PDO + SQLite (or MySQL).
 * Maps between the Partner entity and the fidex_partners table.
 */
final class SqlitePartnerRepository implements PartnerRepositoryPort
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    public function save(Partner $partner): void
    {
        $sql = <<<SQL
            INSERT INTO fidex_partners (
                partner_id, name, receive_endpoint, receipt_endpoint,
                jwks_url, as5_config_url, cached_jwks, jwks_cached_at,
                active, created_at, updated_at
            ) VALUES (
                :partner_id, :name, :receive_endpoint, :receipt_endpoint,
                :jwks_url, :as5_config_url, :cached_jwks, :jwks_cached_at,
                :active, :created_at, :updated_at
            )
            ON CONFLICT(partner_id) DO UPDATE SET
                name             = excluded.name,
                receive_endpoint = excluded.receive_endpoint,
                receipt_endpoint = excluded.receipt_endpoint,
                jwks_url         = excluded.jwks_url,
                as5_config_url   = excluded.as5_config_url,
                cached_jwks      = excluded.cached_jwks,
                jwks_cached_at   = excluded.jwks_cached_at,
                active           = excluded.active,
                updated_at       = excluded.updated_at
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':partner_id'       => $partner->getPartnerId(),
            ':name'             => $partner->getName(),
            ':receive_endpoint' => $partner->getReceiveEndpoint(),
            ':receipt_endpoint' => $partner->getReceiptEndpoint(),
            ':jwks_url'         => $partner->getJwksUrl(),
            ':as5_config_url'   => $partner->getAs5ConfigUrl(),
            ':cached_jwks'      => $partner->getCachedJwks(),
            ':jwks_cached_at'   => $partner->getJwksCachedAt()?->format('Y-m-d\TH:i:s.v\Z'),
            ':active'           => $partner->isActive() ? 1 : 0,
            ':created_at'       => $partner->getCreatedAt()->format('Y-m-d\TH:i:s.v\Z'),
            ':updated_at'       => $partner->getUpdatedAt()->format('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    public function findById(string $partnerId): ?Partner
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fidex_partners WHERE partner_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $partnerId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findAllActive(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fidex_partners WHERE active = 1 ORDER BY name ASC'
        );
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    public function exists(string $partnerId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM fidex_partners WHERE partner_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $partnerId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Partner
    {
        return Partner::reconstitute(
            partnerId: (string) $row['partner_id'],
            name: (string) $row['name'],
            receiveEndpoint: (string) $row['receive_endpoint'],
            receiptEndpoint: (string) $row['receipt_endpoint'],
            jwksUrl: (string) $row['jwks_url'],
            as5ConfigUrl: (string) $row['as5_config_url'],
            cachedJwks: isset($row['cached_jwks']) ? (string) $row['cached_jwks'] : null,
            jwksCachedAt: isset($row['jwks_cached_at']) && $row['jwks_cached_at'] !== null
                ? new \DateTimeImmutable((string) $row['jwks_cached_at'])
                : null,
            active: (bool) $row['active'],
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
