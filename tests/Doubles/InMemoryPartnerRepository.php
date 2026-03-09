<?php

declare(strict_types=1);

namespace FideX\Tests\Doubles;

use FideX\Core\Domain\Partner;
use FideX\Port\Outbound\PartnerRepositoryPort;

/**
 * In-Memory Partner Repository (Test Double)
 *
 * Used in unit tests to avoid database dependencies.
 */
final class InMemoryPartnerRepository implements PartnerRepositoryPort
{
    /** @var array<string, Partner> */
    private array $store = [];

    public function save(Partner $partner): void
    {
        $this->store[$partner->getPartnerId()] = $partner;
    }

    public function findById(string $partnerId): ?Partner
    {
        return $this->store[$partnerId] ?? null;
    }

    public function findAllActive(): array
    {
        return array_values(
            array_filter(
                $this->store,
                fn (Partner $p) => $p->isActive()
            )
        );
    }

    public function exists(string $partnerId): bool
    {
        return isset($this->store[$partnerId]);
    }

    public function clear(): void
    {
        $this->store = [];
    }
}
