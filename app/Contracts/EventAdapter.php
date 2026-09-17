<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

interface EventAdapter
{
    /** @return array<int, array{event_id:int,event_title:string,event_desc:?string,occurrence_count:int,next_occurrence_at:?string}> */
    /**
     * @param list<string> $audiences audiences the actor may read; see
     *                                App\Core\EventAudience::allowedFor().
     *                                An empty list is treated as 'members'
     *                                only — this fails closed, never open.
     */
    public function listUpcoming(int $limit, ?int $campusId = null, string $range = 'upcoming', ?string $anchor = null, array $audiences = []): array;

    /** @return list<array<string,mixed>> */
    public function listOccurrences(string $from, string $to, ?int $campusId, int $limit, bool $descending = false, array $audiences = []): array;

    /**
     * Null for an event the actor may not read, identically to one that does
     * not exist — a different response would be an enumeration oracle.
     *
     * @return array{event_id:int,event_title:string,event_desc:?string,occurrences:array}|null
     */
    public function findEvent(int $eventId, DateTimeImmutable $start, DateTimeImmutable $end, array $audiences = []): ?array;

    public function createEvent(array $cmd, DateTimeImmutable $now): int;
    public function updateEvent(int $eventId, array $cmd, DateTimeImmutable $now): bool;

    /** @param array<int,array{event_id:int,occurrence_start:string,occurrence_end:string}> $rows */
    public function insertOccurrences(int $eventId, array $rows): array;

    /**
     * The parent event's id and audience, so a write can be gated the same way
     * a read is. Null when the occurrence does not exist.
     *
     * @return array{event_id:int,audience:string}|null
     */
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
     * read override_title; nothing could ever set it, so the only way to say it
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
     * @return list<array{tag_id:int,slug:string,label:string,usage_count:int}>
     */
    public function listTags(): array;

    /** @return list<array{tag_id:int,slug:string,label:string}> */
    public function tagsForEvent(int $eventId): array;

    /**
     * Replace an event's tags with exactly this list, creating any that are new
     * and dropping any tag left carried by nothing.
     *
     * @param list<array{slug:string,label:string}> $tags
     * @return list<array{tag_id:int,slug:string,label:string}>
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

    /**
     * Every occurrence of one event, in date order.
     *
     * Correcting a mis-entered recurring event needs the whole series, not the
     * window findEvent() happens to have been asked for: a service entered at
     * the wrong time is wrong in every week it was generated into.
     *
     * @return list<array{occurrence_id:int,occurrence_start:string,occurrence_end:string}>
     */
    public function listEventOccurrences(int $eventId): array;

    /** Move one occurrence. Start and end are 'Y-m-d H:i:s'. */
    public function updateOccurrenceTimes(int $occurrenceId, string $start, string $end): bool;

    /**
     * Delete occurrences, scoped to their parent event.
     *
     * The event id is part of the query rather than merely checked beforehand,
     * so an id belonging to another event cannot be deleted through this call
     * however it reached the request.
     *
     * @param list<int> $occurrenceIds
     * @return int rows actually deleted
     */
    public function deleteOccurrencesForEvent(int $eventId, array $occurrenceIds): int;

    /** @param list<int> $occurrenceIds */
    public function countAssignmentsForOccurrences(array $occurrenceIds): int;

    /**
     * Move many occurrences in one transaction.
     *
     * Retiming a series is all-or-nothing on purpose: a run that stops halfway
     * leaves some occurrences on the corrected time and some on the wrong one,
     * which is harder to notice than a series that is uniformly wrong.
     *
     * @param list<array{occurrence_id:int,occurrence_start:string,occurrence_end:string}> $rows
     * @return int rows updated
     */
    public function updateOccurrenceTimesBatch(int $eventId, array $rows): int;

    /** Remove an event with its occurrences and campus links. */
    public function deleteEvent(int $eventId): bool;

    /**
     * Store (or clear) the rule an event repeats on.
     *
     * Passing null removes any rule: a schedule changed to a list of chosen
     * dates is no longer a repetition, and a stale rule left behind would
     * describe the event wrongly for every later reader.
     *
     * @param array<string,mixed>|null $rule
     */
    public function saveRecurrence(int $eventId, ?array $rule): void;

    /** @return array<string,mixed>|null */
    public function findRecurrence(int $eventId): ?array;
}
