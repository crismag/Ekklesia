<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ScheduleAdapter;
use App\Contracts\ScheduleRepository;
use App\DTO\Schedules\AssignmentBatchCommand;
use DateTimeImmutable;

/**
 * Source-agnostic schedule repository.
 *
 * Composes a ScheduleAdapter (the source-specific data layer) into the shape
 * the service contract expects. This class contains zero SQL; all storage
 * concerns live in the adapter. When the underlying source changes (e.g.
 * the store is replaced), the adapter is swapped via the service container —
 * this repository is unchanged.
 */
final class DefaultScheduleRepository implements ScheduleRepository
{
    public function __construct(
        private readonly ScheduleAdapter $adapter,
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchScheduleBoard(DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], array $ministryIds = []): array
    {
        return $this->adapter->listScheduleBoard($start, $end, $campusIds, $ministryIds);
    }

    /**
     * @param list<int> $campusIds
     * @return list<array{id:int,title:string,is_default:bool}>
     */
    public function listEligibleSchedulingEvents(array $campusIds = []): array
    {
        return $this->adapter->listEligibleSchedulingEvents($campusIds);
    }

    public function listSchedulableEventsInRange(
        array $campusIds,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        return $this->adapter->listSchedulableEventsInRange($campusIds, $start, $end);
    }

    /**
     * @param list<int> $campusIds
     * @return list<int>
     */
    public function listDefaultSchedulingEventIds(array $campusIds = []): array
    {
        return $this->adapter->listDefaultSchedulingEventIds($campusIds);
    }

    /**
     * @param list<int>|null $eventIds
     * @return array<string, mixed>
     */
    public function fetchScheduleGrid(int $ministryId, DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], ?array $eventIds = null): array
    {
        $roles       = $this->adapter->listRolesForMinistry($ministryId);
        $people      = $this->adapter->listMinistryMembers($ministryId, $campusIds);
        $specialCandidates = $this->adapter->listSpecialCandidates($ministryId, $campusIds);
        $occurrences = $this->adapter->listOccurrencesInRange($start, $end, $campusIds, $eventIds);
        $assignments = $this->adapter->listAssignmentsInRange($ministryId, $start, $end, $campusIds, $eventIds);

        $occurrenceIds = array_map(fn (array $o): int => (int) $o['id'], $occurrences);
        $conflicts     = $this->adapter->listConflictsForOccurrences($ministryId, $occurrenceIds);

        return [
            'ministry_id' => $ministryId,
            'start'       => $start,
            'end'         => $end,
            'roles'       => $roles,
            'people'      => $people,
            'special_candidates' => $specialCandidates,
            'occurrences' => $occurrences,
            'assignments' => $assignments,
            'conflicts'   => $conflicts,
            'warnings'    => [],
        ];
    }

    /**
     * @return array{
     *   person_id:int,
     *   start:DateTimeImmutable,
     *   end:DateTimeImmutable,
     *   assignments:list<array<string, mixed>>
     * }
     */
    public function fetchMySchedule(int $personId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [
            'person_id' => $personId,
            'start' => $start,
            'end' => $end,
            'assignments' => $this->adapter->listAssignmentsForPerson($personId, $start, $end),
        ];
    }

    /**
     * @return array{saved: bool, assignment_count: int}
     */
    public function saveAssignments(AssignmentBatchCommand $command): array
    {
        return $this->adapter->persistAssignmentBatch($command);
    }
}
