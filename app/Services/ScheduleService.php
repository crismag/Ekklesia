<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ScheduleRepository;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\DTO\MySchedule\MyAssignment;
use App\DTO\MySchedule\MyScheduleView;
use App\DTO\Schedules\AssignmentBatchCommand;
use App\DTO\Schedules\AssignmentSaveResult;
use App\DTO\Schedules\ScheduleAssignment;
use App\DTO\Schedules\ScheduleGrid;
use App\DTO\Schedules\ScheduleOccurrence;
use App\DTO\Schedules\SchedulePerson;
use App\DTO\Schedules\ScheduleRole;
use App\DTO\Schedules\SchedulingEventOption;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use DateTimeImmutable;

final readonly class ScheduleService
{
    public function __construct(
        private ScheduleRepository $scheduleRepository,
    ) {
    }

    public function getScheduleGrid(
        ActorContext $context,
        int $ministryId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?array $requestedEventIds = null,
        bool $restrictToSchedulingEvents = true,
    ): ScheduleGrid {
        $this->ensureScheduleScope($context, $ministryId, readOnly: true);

        if ($end < $start) {
            throw new ValidationFailed('Schedule end date cannot be before start date.');
        }

        $campusIds = $context->currentCampusIds;
        $schedulingEvents = [];
        $selectedEventIds = null;
        $queryEventIds = null;

        if ($restrictToSchedulingEvents) {
            // What may be picked is "anything happening in these weeks, on this
            // campus" — not "anything somebody ticked". The tick decides what
            // opens first; it was never meant to decide what can be searched.
            $schedulingEvents = $this->schedulingEventOptions($campusIds, $start, $end);
            $selectedEventIds = $this->resolveSelectedEventIds($campusIds, $requestedEventIds, $schedulingEvents);
            $queryEventIds = $selectedEventIds;
        }

        $rawGrid = $this->scheduleRepository->fetchScheduleGrid(
            $ministryId,
            $start,
            $end,
            $campusIds,
            $queryEventIds,
        );

        return new ScheduleGrid(
            ministryId: (int) $rawGrid['ministry_id'],
            start: $rawGrid['start'],
            end: $rawGrid['end'],
            roles: array_map(
                fn (array $role): ScheduleRole => new ScheduleRole((int) $role['id'], (string) $role['name']),
                $rawGrid['roles'],
            ),
            people: array_map(
                fn (array $person): SchedulePerson => new SchedulePerson(
                    (int) $person['id'],
                    (string) $person['display_name'],
                ),
                $rawGrid['people'],
            ),
            specialCandidates: array_map(
                fn (array $person): SchedulePerson => new SchedulePerson(
                    (int) $person['id'],
                    (string) $person['display_name'],
                ),
                $rawGrid['special_candidates'] ?? [],
            ),
            assignments: array_map(
                fn (array $assignment): ScheduleAssignment => new ScheduleAssignment(
                    id: isset($assignment['id']) ? (int) $assignment['id'] : null,
                    personId: (int) $assignment['person_id'],
                    roleId: (int) $assignment['role_id'],
                    startsOn: $assignment['starts_on'],
                    label: (string) ($assignment['label'] ?? ''),
                    displayName: (string) ($assignment['display_name'] ?? ''),
                    occurrenceId: isset($assignment['occurrence_id']) ? (int) $assignment['occurrence_id'] : null,
                ),
                $rawGrid['assignments'],
            ),
            warnings: $rawGrid['warnings'] ?? [],
            occurrences: array_map(
                fn (array $o): ScheduleOccurrence => new ScheduleOccurrence(
                    id:         (int) $o['id'],
                    eventId:    (int) $o['event_id'],
                    eventTitle: (string) $o['event_title'],
                    startsOn:   $o['starts_on'],
                    endsOn:     $o['ends_on'],
                ),
                $rawGrid['occurrences'] ?? [],
            ),
            conflicts: $rawGrid['conflicts'] ?? [],
            schedulingEvents: $schedulingEvents,
            selectedEventIds: $selectedEventIds ?? [],
        );
    }

    /**
     * Eligible assignment-scheduling events for the given campuses.
     *
     * @param list<int> $campusIds
     * @return list<SchedulingEventOption>
     */
    public function listEligibleSchedulingEvents(ActorContext $context, array $campusIds = []): array
    {
        if (
            !$context->isPortalWideAdmin
            && !$context->hasPermission(PortalPermission::ManageEvents)
            && !$context->hasPermission(PortalPermission::ManageSchedules)
            && !$context->hasPermission(PortalPermission::ViewMinistrySchedule)
        ) {
            throw new PermissionDenied('Actor lacks permission to list scheduling events.');
        }

        // Still the flagged set, and deliberately so. This feeds the campus's
        // "default assignment event" chooser, which asks which event a scheduler
        // should open with — a standing choice with no date range behind it. The
        // picker in the grid is the one that had to stop using this.
        return $this->flaggedEventOptions($campusIds !== [] ? $campusIds : $context->currentCampusIds);
    }

    /**
     * The flagged pool: events an administrator marked for assignments.
     *
     * @param list<int> $campusIds
     * @return list<SchedulingEventOption>
     */
    private function flaggedEventOptions(array $campusIds): array
    {
        return array_map(
            static fn (array $row): SchedulingEventOption => new SchedulingEventOption(
                id: (int) $row['id'],
                title: (string) $row['title'],
                isDefault: (bool) ($row['is_default'] ?? false),
            ),
            $this->scheduleRepository->listEligibleSchedulingEvents($campusIds),
        );
    }

    /**
     * Member-facing "My Schedule": one person's assignments across every
     * ministry they serve in, within [start, end). The actor must be the same
     * person whose schedule is being requested (or a portal-wide admin, which
     * is useful for support / impersonation contexts).
     */
    public function getMySchedule(
        ActorContext $context,
        int $personId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): MyScheduleView {
        if ($end < $start) {
            throw new ValidationFailed('Schedule end date cannot be before start date.');
        }

        if (!$context->hasPermission(PortalPermission::ViewOwnAssignments)) {
            throw new PermissionDenied('Actor lacks permission to view own assignments.');
        }

        if (!$context->isPortalWideAdmin && $context->personId !== $personId) {
            throw new PermissionDenied('Actor may only view their own schedule.');
        }

        $raw = $this->scheduleRepository->fetchMySchedule($personId, $start, $end);

        $assignments = array_map(
            static fn (array $row): MyAssignment => new MyAssignment(
                id: (int) $row['id'],
                personId: (int) $row['person_id'],
                roleId: (int) $row['role_id'],
                roleName: (string) $row['role_name'],
                ministryId: (int) $row['ministry_id'],
                ministryName: (string) $row['ministry_name'],
                eventId: (int) $row['event_id'],
                eventTitle: (string) $row['event_title'],
                startsOn: $row['starts_on'],
                endsOn: $row['ends_on'],
                status: (string) $row['status'],
            ),
            $raw['assignments'],
        );

        return new MyScheduleView(
            personId: (int) $raw['person_id'],
            start: $raw['start'],
            end: $raw['end'],
            assignments: $assignments,
        );
    }

    public function saveAssignments(
        ActorContext $context,
        AssignmentBatchCommand $command,
    ): AssignmentSaveResult {
        $this->ensureScheduleScope($context, $command->ministryId, readOnly: false);

        if ($command->assignments === []) {
            throw new ValidationFailed('At least one assignment is required.');
        }

        // Saving validates against the same window the grid was built from, so
        // a client cannot post an event id from another campus or from outside
        // the dates it is saving.
        //
        // The range is optional on this command (legacy and test paths omit it).
        // Without one there is no window to check against, so the pool falls
        // back to the flagged set — the stricter of the two. A write path is the
        // wrong place to guess generously.
        $pool = $command->start !== null && $command->end !== null
            ? $this->schedulingEventOptions($context->currentCampusIds, $command->start, $command->end)
            : $this->flaggedEventOptions($context->currentCampusIds);
        $eventIds = $this->resolveSelectedEventIds(
            $context->currentCampusIds,
            $command->eventIds,
            $pool,
        );

        $rawResult = $this->scheduleRepository->saveAssignments(new AssignmentBatchCommand(
            ministryId: $command->ministryId,
            assignments: $command->assignments,
            start: $command->start,
            end: $command->end,
            campusId: $context->currentCampusId,
            campusIds: $context->currentCampusIds,
            reason: $command->reason,
            eventIds: $eventIds,
        ));

        return new AssignmentSaveResult(
            saved: (bool) $rawResult['saved'],
            assignmentCount: (int) $rawResult['assignment_count'],
        );
    }

    /**
     * Public schedule board: all assignments in [start,end], enriched with
     * ministry / event / role / person, for grouping by date → ministry.
     *
     * @return list<array<string,mixed>>
     */
    public function getScheduleBoard(ActorContext $context, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($end < $start) {
            throw new ValidationFailed('Schedule end date cannot be before start date.');
        }

        return $this->scheduleRepository->fetchScheduleBoard($start, $end, $context->currentCampusIds);
    }

    private function ensureScheduleScope(ActorContext $context, int $ministryId, bool $readOnly): void
    {
        if ($readOnly && (
            $context->hasPermission(PortalPermission::ManageSchedules)
            || $context->hasPermission(PortalPermission::ViewMinistrySchedule)
            || $context->hasPermission(PortalPermission::ViewMinistryDashboard)
        )) {
            return;
        }

        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }

        if (!$readOnly && $context->hasPermission(PortalPermission::ManageSchedules)) {
            return;
        }

        throw new PermissionDenied('Actor lacks schedule permission for this workflow.');
    }

    /**
     * @param list<int> $campusIds
     * @return list<SchedulingEventOption>
     */
    private function schedulingEventOptions(
        array $campusIds,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        return array_map(
            static fn (array $row): SchedulingEventOption => new SchedulingEventOption(
                id: (int) $row['id'],
                title: (string) $row['title'],
                isDefault: (bool) ($row['is_default'] ?? false),
            ),
            $this->scheduleRepository->listSchedulableEventsInRange($campusIds, $start, $end),
        );
    }

    /**
     * Client event IDs are never trusted. Drop anything outside the eligible
     * pool for the active campus. Omitted/null means "use campus defaults".
     * An explicit empty list means an empty workspace.
     *
     * @param list<int> $campusIds
     * @param list<int>|null $requestedEventIds
     * @param list<SchedulingEventOption> $eligible
     * @return list<int>
     */
    private function resolveSelectedEventIds(array $campusIds, ?array $requestedEventIds, array $eligible): array
    {
        $eligibleIds = [];
        $defaultIds = [];
        foreach ($eligible as $event) {
            $eligibleIds[$event->id] = $event->id;
            if ($event->isDefault) {
                $defaultIds[$event->id] = $event->id;
            }
        }

        if ($requestedEventIds === null) {
            if ($defaultIds !== []) {
                return array_values($defaultIds);
            }

            // Configured defaults may exist on church_campus even if the
            // adapter's is_default flag lagged; still intersect with eligible.
            $configured = [];
            foreach ($this->scheduleRepository->listDefaultAssignmentEventIds($campusIds) as $id) {
                if (isset($eligibleIds[$id])) {
                    $configured[$id] = $id;
                }
            }

            return array_values($configured);
        }

        $selected = [];
        foreach ($requestedEventIds as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($eligibleIds[$id]) && !isset($selected[$id])) {
                $selected[$id] = $id;
            }
        }

        return array_values($selected);
    }
}
