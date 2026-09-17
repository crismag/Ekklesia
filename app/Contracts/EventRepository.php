<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

interface EventRepository
{
    public function listUpcoming(int $limit, ?int $campusId = null, string $range = 'upcoming', ?string $anchor = null, array $audiences = []): array;

    /** @return list<array<string,mixed>> */
    public function listOccurrences(string $from, string $to, ?int $campusId, int $limit, bool $descending = false, array $audiences = []): array;

    public function findEvent(int $eventId, DateTimeImmutable $start, DateTimeImmutable $end, array $audiences = []): ?array;

    public function createEvent(array $cmd, DateTimeImmutable $now): int;

    public function updateEvent(int $eventId, array $cmd, DateTimeImmutable $now): bool;

    public function insertOccurrences(int $eventId, array $rows): array;

    /** @return array{event_id:int,audience:string}|null */
    public function findOccurrenceAudience(int $occurrenceId): ?array;

    /**
     * Mark an occurrence cancelled, or put it back.
     *
     * Cancelling and deleting are different acts. "No service this Sunday" is
     * something the congregation needs to see; a date entered by mistake is
     * something to erase. The column has existed since the schema was created
     * and the application only ever deleted, so the difference could not be
     * expressed.
     */
    /**
     * Give one date of a series its own title and description.
     *
     * "This Sunday we meet at the park" is a normal thing for a church to say
     * about one week of a service that otherwise runs unchanged. Both columns
     * have existed since the schema was created and the calendar has always
     * read title_override; nothing could ever set it, so the only way to say it
     * was to break the date out of its series.
     *
     * Null on either clears it, and the occurrence inherits from its event
     * again. is_modified is derived here rather than passed in.
     */
    /**
     * Tags on events: a normalised many-to-many, not a comma-separated column.
     *
     * Event types answer "what kind of thing is this, and who may see it" — one
     * per event, and it decides audience. Tags answer a different question and
     * answer it many times over: a service can be Christmas and family and
     * music at once.
     *
     * @return list<array{slug:string,label:string,usage_count:int}>
     */
    public function listTags(): array;

    /** @return list<array{slug:string,label:string}> */
    public function tagsForEvent(int $eventId): array;

    /**
     * Replace an event's tags with exactly this list, creating any that are new
     * and dropping any tag left carried by nothing.
     *
     * @param list<array{slug:string,label:string}> $tags
     * @return list<array{slug:string,label:string}>
     */
    public function setEventTags(int $eventId, array $tags): array;

    /** @return list<int> */
    public function eventIdsWithTag(string $slug): array;

    public function setOccurrenceOverrides(int $occurrenceId, ?string $title, ?string $desc): bool;

    /** One occurrence with its overrides, or null. */
    public function findOccurrence(int $occurrenceId): ?array;

    public function setOccurrenceCancelled(int $occurrenceId, bool $cancelled): bool;

    public function deleteOccurrence(int $occurrenceId): bool;

    public function countAssignmentsForOccurrence(int $occurrenceId): int;

    /** @return list<array{occurrence_id:int,starts_at:string,ends_at:string}> */
    public function listEventOccurrences(int $eventId): array;

    public function updateOccurrenceTimes(int $occurrenceId, string $start, string $end): bool;

    /** @param list<int> $occurrenceIds */
    public function deleteOccurrencesForEvent(int $eventId, array $occurrenceIds): int;

    /** @param list<int> $occurrenceIds */
    public function countAssignmentsForOccurrences(array $occurrenceIds): int;

    /** @param list<array{occurrence_id:int,starts_at:string,ends_at:string}> $rows */
    public function updateOccurrenceTimesBatch(int $eventId, array $rows): int;

    public function deleteEvent(int $eventId): bool;

    /** @param array<string,mixed>|null $rule */
    public function saveRecurrence(int $eventId, ?array $rule): void;

    /** @return array<string,mixed>|null */
    public function findRecurrence(int $eventId): ?array;
}
