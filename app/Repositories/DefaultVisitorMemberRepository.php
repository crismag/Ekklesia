<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\VisitorMemberAdapter;
use App\Contracts\VisitorMemberRepository;

final class DefaultVisitorMemberRepository implements VisitorMemberRepository
{
    public function __construct(private readonly VisitorMemberAdapter $adapter) {}

    public function personCandidates(string $lastName, string $email): array
    {
        return $this->adapter->personCandidates($lastName, $email);
    }

    public function peopleByIds(array $personIds): array
    {
        return $this->adapter->peopleByIds($personIds);
    }

    public function membershipStatuses(): array
    {
        return $this->adapter->membershipStatuses();
    }

    public function campuses(): array
    {
        return $this->adapter->campuses();
    }

    public function memberTypeIdByName(string $name): ?int
    {
        return $this->adapter->memberTypeIdByName($name);
    }

    public function eventsByIds(array $eventIds): array
    {
        return $this->adapter->eventsByIds($eventIds);
    }

    public function occurrencesByIds(array $occurrenceIds): array
    {
        return $this->adapter->occurrencesByIds($occurrenceIds);
    }

    public function upcomingEvents(string $fromDate, string $toDate): array
    {
        return $this->adapter->upcomingEvents($fromDate, $toDate);
    }

    public function transaction(callable $work): mixed
    {
        return $this->adapter->transaction($work);
    }

    public function completePromotedPerson(int $personId, ?int $birthMonth, ?int $birthDay, ?float $latitude, ?float $longitude): void
    {
        $this->adapter->completePromotedPerson($personId, $birthMonth, $birthDay, $latitude, $longitude);
    }
}
