<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\RecordHistoryAdapter;
use App\Contracts\RecordHistoryRepository;

final class DefaultRecordHistoryRepository implements RecordHistoryRepository
{
    public function __construct(private readonly RecordHistoryAdapter $adapter) {}

    public function count(array $criteria): int
    {
        return $this->adapter->count($criteria);
    }

    public function find(array $criteria, int $limit, int $offset): array
    {
        return $this->adapter->find($criteria, $limit, $offset);
    }

    public function actions(): array
    {
        return $this->adapter->actions();
    }

    public function peopleWithHistory(): array
    {
        return $this->adapter->peopleWithHistory();
    }

    public function personName(int $personId): ?string
    {
        return $this->adapter->personName($personId);
    }

    public function householdName(int $householdId): ?string
    {
        return $this->adapter->householdName($householdId);
    }
}
