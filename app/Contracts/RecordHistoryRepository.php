<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * The history of people and household records, as the service reads it. Same
 * shapes as RecordHistoryAdapter; this is the source-agnostic side of that
 * boundary.
 */
interface RecordHistoryRepository
{
    /** @param array<string,mixed> $criteria */
    public function count(array $criteria): int;

    /**
     * @param array<string,mixed> $criteria
     * @return list<array<string,mixed>>
     */
    public function find(array $criteria, int $limit, int $offset): array;

    /** @return list<string> */
    public function actions(): array;

    /** @return list<array{id:int,first_name:string,last_name:string}> */
    public function peopleWithHistory(): array;

    public function personName(int $personId): ?string;

    public function householdName(int $householdId): ?string;
}
