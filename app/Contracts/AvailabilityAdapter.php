<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Availability\AvailabilityCommand;
use DateTimeImmutable;

/**
 * Source-specific data adapter for the portal-owned availability table.
 *
 * Lives in the portal DB (additive, never mirrors a ChurchCRM table).
 * Repositories depend on this contract; SQL is permitted only here.
 */
interface AvailabilityAdapter
{
    /**
     * @return list<array{
     *   id:int,
     *   person_id:int,
     *   starts_on:DateTimeImmutable,
     *   ends_on:DateTimeImmutable,
     *   reason:?string,
     *   created_by_account_id:int,
     *   created_at:DateTimeImmutable,
     *   updated_at:DateTimeImmutable
     * }>
     */
    public function listForPerson(int $personId, ?DateTimeImmutable $activeFrom = null): array;

    /**
     * @return array{
     *   id:int,
     *   person_id:int,
     *   starts_on:DateTimeImmutable,
     *   ends_on:DateTimeImmutable,
     *   reason:?string,
     *   created_by_account_id:int,
     *   created_at:DateTimeImmutable,
     *   updated_at:DateTimeImmutable
     * }|null
     */
    public function findById(int $unavailabilityId): ?array;

    /**
     * Persist a new entry. Returns the new id.
     */
    public function create(AvailabilityCommand $command, int $createdByAccountId, DateTimeImmutable $now): int;

    /**
     * Remove one entry. Returns true when a row was removed.
     */
    public function delete(int $unavailabilityId): bool;
}
