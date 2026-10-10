<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Schedules\AssignmentBatchCommand;
use DateTimeImmutable;

/**
 * ScheduleAdapter is the source-specific data-access boundary used by
 * repositories. Each concrete adapter targets one backend (e.g. SQL,
 * a future replacement, or an in-memory test double) and is the only place
 * SQL or storage-specific code may live.
 *
 * Repositories depend on this contract so that swapping the source does not
 * require changes to services, controllers, or UI. When the store is replaced,
 * only the adapter implementation changes; everything above it stays untouched.
 *
 * Returned arrays are intentionally raw (associative arrays of scalars).
 * Repositories are responsible for shaping these into DTOs.
 */
interface ScheduleAdapter
{
    /**
     * @return list<array{id:int,name:string}>
     */
    public function listRolesForMinistry(int $ministryId): array;

    /**
     * @return list<array{id:int,display_name:string}>
     */
    public function listMinistryMembers(int $ministryId, array $campusIds = []): array;

    /**
     * @return list<array{id:int,display_name:string}>
     */
    public function listSpecialCandidates(int $ministryId, array $campusIds = []): array;

    /**
     * @param list<int> $campusIds
     * @param list<int>|null $eventIds null = no event-id restriction; [] = match nothing
     * @return list<array{
     *   id:int,
     *   occurrence_id:int,
     *   person_id:int,
     *   serving_role_id:int,
     *   starts_on:DateTimeImmutable,
     *   label:string
     * }>
     */
    public function listAssignmentsInRange(int $ministryId, DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], ?array $eventIds = null): array;

    /**
     * Event occurrences whose start falls in [start, end).
     *
     * Campus IDs are a hard authorization boundary. $eventIds further restrict
     * the result to assignment-scheduling targets. An empty list matches
     * nothing; null means "do not apply an event-id filter" and is reserved
     * for callers that are not the assignment editor (posted boards).
     *
     * @param list<int> $campusIds
     * @param list<int>|null $eventIds
     * @return list<array{
     *   id:int,
     *   event_id:int,
     *   event_title:string,
     *   starts_on:DateTimeImmutable,
     *   ends_on:DateTimeImmutable
     * }>
     */
    public function listOccurrencesInRange(DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], ?array $eventIds = null): array;

    /**
     * Events that may appear in the assignment scheduler for these campuses.
     *
     * @param list<int> $campusIds
     * @return list<array{id:int,title:string,is_default:bool}>
     */
    public function listEligibleSchedulingEvents(array $campusIds = []): array;

    /**
     * Configured default assignment-event IDs for these campuses.
     *
     * @param list<int> $campusIds
     * @return list<int>
     */
    public function listDefaultSchedulingEventIds(array $campusIds = []): array;

    /**
     * One person's assignments across all ministries within [start, end).
     *
     * @return list<array{
     *   id:int,
     *   person_id:int,
     *   serving_role_id:int,
     *   role_name:string,
     *   ministry_id:int,
     *   ministry_name:string,
     *   event_id:int,
     *   event_title:string,
     *   starts_on:DateTimeImmutable,
     *   ends_on:DateTimeImmutable,
     *   status:string
     * }>
     */
    public function listAssignmentsForPerson(int $personId, DateTimeImmutable $start, DateTimeImmutable $end): array;

    /**
     * @param list<int> $campusIds
     * @param list<int> $ministryIds
     * @return list<array<string,mixed>>
     */
    public function listScheduleBoard(DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], array $ministryIds = []): array;

    /**
     * @return array{saved:bool, assignment_count:int}
     */
    public function persistAssignmentBatch(AssignmentBatchCommand $command): array;

    /**
     * For each occurrence in $occurrenceIds, find persons who are either:
     *   - assigned to a different occurrence whose time window overlaps, or
     *   - already assigned in the same occurrence through a different ministry.
     *
     * Used by the schedule editor to flag conflict-prone members in the Add
     * picker, including special assignees serving in another ministry.
     *
     * @param list<int> $occurrenceIds
     * @return list<array{person_id:int, grid_occurrence_id:int, conflict_label:string}>
     */
    public function listConflictsForOccurrences(int $ministryId, array $occurrenceIds): array;
}
