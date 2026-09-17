<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AvailabilityAdapter;
use App\Contracts\AvailabilityRepository;
use App\DTO\Availability\AvailabilityCommand;
use DateTimeImmutable;

final class DefaultAvailabilityRepository implements AvailabilityRepository
{
    public function __construct(
        private readonly AvailabilityAdapter $adapter,
    ) {
    }

    public function listForPerson(int $personId, ?DateTimeImmutable $activeFrom = null): array
    {
        return $this->adapter->listForPerson($personId, $activeFrom);
    }

    public function findById(int $unavailabilityId): ?array
    {
        return $this->adapter->findById($unavailabilityId);
    }

    public function create(AvailabilityCommand $command, int $createdByPortalUserId, DateTimeImmutable $now): int
    {
        return $this->adapter->create($command, $createdByPortalUserId, $now);
    }

    public function delete(int $unavailabilityId): bool
    {
        return $this->adapter->delete($unavailabilityId);
    }
}
