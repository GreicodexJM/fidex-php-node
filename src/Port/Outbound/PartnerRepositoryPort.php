<?php

declare(strict_types=1);

namespace FideX\Port\Outbound;

use FideX\Core\Domain\Partner;

/**
 * Outbound Port: Partner Persistence
 *
 * Defines how the domain core stores and retrieves trading partner data.
 * Implementations: SqlitePartnerRepository, InMemoryPartnerRepository (tests)
 */
interface PartnerRepositoryPort
{
    /**
     * Persist a new partner or update an existing one.
     */
    public function save(Partner $partner): void;

    /**
     * Find a partner by their URN identifier.
     */
    public function findById(string $partnerId): ?Partner;

    /**
     * Return all active partners.
     *
     * @return Partner[]
     */
    public function findAllActive(): array;

    /**
     * Check if a partner exists by URN.
     */
    public function exists(string $partnerId): bool;
}
