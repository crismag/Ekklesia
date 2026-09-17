<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Availability\AvailabilityCommand;
use DateTimeImmutable;

interface AvailabilityRepository
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

    public function create(AvailabilityCommand $command, int $createdByAccountId, DateTimeImmutable $now): int;

    public function delete(int $unavailabilityId): bool;
}
