<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Schedules\AssignmentBatchCommand;
use DateTimeImmutable;

interface ScheduleRepository
{
    /**
     * @param list<int> $campusIds
     * @param list<int>|null $eventIds null = no event-id restriction; [] = no occurrences
     * @return array<string, mixed>
     */
    public function fetchScheduleGrid(int $ministryId, DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], ?array $eventIds = null): array;

    /**
     * @param list<int> $campusIds
     * @return list<array{id:int,title:string,is_default:bool}>
     */
    public function listEligibleSchedulingEvents(array $campusIds = []): array;

    /**
     * Events a scheduler may pick for a window: anything with an occurrence in
     * range, on this campus.
     *
     * Deliberately not filtered by uses_serving_schedule. That flag
     * chooses which event a campus *opens* with; it was never meant to decide
     * what a scheduler is allowed to look for. Filtering the picker by it meant
     * the only thing offered was the thing already selected.
     *
     * Only rows in events reach this. Birthdays and holidays are not
     * events — they arrive on the calendar from person records and the holiday
     * cache — so they cannot appear here.
     *
     * @param list<int> $campusIds
     * @return list<array{id:int,title:string,is_default:bool}>
     */
    public function listSchedulableEventsInRange(
        array $campusIds,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array;

    /**
     * @param list<int> $campusIds
     * @return list<int>
     */
    public function listDefaultSchedulingEventIds(array $campusIds = []): array;

    public function fetchScheduleBoard(DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], array $ministryIds = []): array;

    /**
     * Raw row shape for one person's assignments across all ministries.
     *
     * @return array{
     *   person_id:int,
     *   start:DateTimeImmutable,
     *   end:DateTimeImmutable,
     *   assignments:list<array<string, mixed>>
     * }
     */
    public function fetchMySchedule(int $personId, DateTimeImmutable $start, DateTimeImmutable $end): array;

    /**
     * @return array{saved: bool, assignment_count: int}
     */
    public function saveAssignments(AssignmentBatchCommand $command): array;
}

