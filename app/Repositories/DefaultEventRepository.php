<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\EventAdapter;
use App\Contracts\EventRepository;
use DateTimeImmutable;

final class DefaultEventRepository implements EventRepository
{
    public function __construct(private readonly EventAdapter $adapter) {}

    public function listUpcoming(int $limit, ?int $campusId = null, string $range = 'upcoming', ?string $anchor = null, array $audiences = []): array
    {
        return $this->adapter->listUpcoming($limit, $campusId, $range, $anchor, $audiences);
    }

    public function listOccurrences(string $from, string $to, ?int $campusId, int $limit, bool $descending = false, array $audiences = []): array
    {
        return $this->adapter->listOccurrences($from, $to, $campusId, $limit, $descending, $audiences);
    }

    public function findEvent(int $eventId, DateTimeImmutable $start, DateTimeImmutable $end, array $audiences = []): ?array
    {
        return $this->adapter->findEvent($eventId, $start, $end, $audiences);
    }

    public function createEvent(array $cmd, DateTimeImmutable $now): int
    {
        return $this->adapter->createEvent($cmd, $now);
    }

    public function updateEvent(int $eventId, array $cmd, DateTimeImmutable $now): bool
    {
        return $this->adapter->updateEvent($eventId, $cmd, $now);
    }

    public function insertOccurrences(int $eventId, array $rows): array
    {
        return $this->adapter->insertOccurrences($eventId, $rows);
    }

    public function findOccurrenceAudience(int $occurrenceId): ?array
    {
        return $this->adapter->findOccurrenceAudience($occurrenceId);
    }

    public function listTags(): array
    {
        return $this->adapter->listTags();
    }

    public function tagsForEvent(int $eventId): array
    {
        return $this->adapter->tagsForEvent($eventId);
    }

    public function setEventTags(int $eventId, array $tags): array
    {
        return $this->adapter->setEventTags($eventId, $tags);
    }

    public function eventIdsWithTag(string $slug): array
    {
        return $this->adapter->eventIdsWithTag($slug);
    }

    public function setOccurrenceOverrides(int $occurrenceId, ?string $title, ?string $desc): bool
    {
        return $this->adapter->setOccurrenceOverrides($occurrenceId, $title, $desc);
    }

    public function findOccurrence(int $occurrenceId): ?array
    {
        return $this->adapter->findOccurrence($occurrenceId);
    }

    public function setOccurrenceCancelled(int $occurrenceId, bool $cancelled): bool
    {
        return $this->adapter->setOccurrenceCancelled($occurrenceId, $cancelled);
    }

    public function deleteOccurrence(int $occurrenceId): bool
    {
        return $this->adapter->deleteOccurrence($occurrenceId);
    }

    public function countAssignmentsForOccurrence(int $occurrenceId): int
    {
        return $this->adapter->countAssignmentsForOccurrence($occurrenceId);
    }

    public function listEventOccurrences(int $eventId): array
    {
        return $this->adapter->listEventOccurrences($eventId);
    }

    public function updateOccurrenceTimes(int $occurrenceId, string $start, string $end): bool
    {
        return $this->adapter->updateOccurrenceTimes($occurrenceId, $start, $end);
    }

    public function deleteOccurrencesForEvent(int $eventId, array $occurrenceIds): int
    {
        return $this->adapter->deleteOccurrencesForEvent($eventId, $occurrenceIds);
    }

    public function countAssignmentsForOccurrences(array $occurrenceIds): int
    {
        return $this->adapter->countAssignmentsForOccurrences($occurrenceIds);
    }

    public function updateOccurrenceTimesBatch(int $eventId, array $rows): int
    {
        return $this->adapter->updateOccurrenceTimesBatch($eventId, $rows);
    }

    public function deleteEvent(int $eventId): bool
    {
        return $this->adapter->deleteEvent($eventId);
    }

    public function saveRecurrence(int $eventId, ?array $rule): void
    {
        $this->adapter->saveRecurrence($eventId, $rule);
    }

    public function findRecurrence(int $eventId): ?array
    {
        return $this->adapter->findRecurrence($eventId);
    }
}
