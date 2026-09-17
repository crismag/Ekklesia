<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\DTO\Schedules\AssignmentBatchCommand;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use App\Http\Requests\PortalRequestContext;
use App\Services\ScheduleService;
use DateInterval;
use DateTimeImmutable;

final readonly class ScheduleController
{
    public function __construct(
        private ScheduleService $scheduleService,
        private PortalRequestContext $requestContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function grid(array $request): array
    {
        $grid = $this->scheduleService->getScheduleGrid(
            context: $this->requestContext->fromArray($request),
            ministryId: (int) $request['ministry_id'],
            start: new DateTimeImmutable((string) $request['start']),
            end: new DateTimeImmutable((string) $request['end']),
            requestedEventIds: $this->eventIdsFromRequest($request),
        );

        return $grid->toArray();
    }

    /**
     * GET /api/schedules/staffing?date=YYYY-MM-DD
     *
     * The day inspector's write surface. Phase 3's day JSON names roles but
     * does not carry ministry/role/assignment ids (CalendarController is
     * frozen for this track). This endpoint is a read over the same grid the
     * serving editor already uses, sliced to one midnight-to-midnight day,
     * so the inspector can fill a role through POST /api/schedules/assignments
     * rather than inventing a second write path.
     *
     * People lists are returned only for ministries the actor may write
     * (ManageSchedules + canAccessMinistry). Members see the inspector's
     * read-only role lines from the day API and get nothing extra here.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function staffing(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $canStaff = $actor->hasPermission(PortalPermission::ManageSchedules);
        if (
            !$canStaff
            && !$actor->hasPermission(PortalPermission::ViewMinistrySchedule)
            && !$actor->hasPermission(PortalPermission::ViewMinistryDashboard)
            && !$actor->isPortalWideAdmin
        ) {
            throw new PermissionDenied('Actor lacks permission to read ministry staffing.');
        }

        $day = $this->requestedDay($request);
        $from = $day->setTime(0, 0, 0);
        $until = $from->modify('+1 day');

        $board = $this->scheduleService->getScheduleBoard($actor, $from, $until);
        $ministryNames = [];
        foreach ($board as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ministryId = (int) ($row['ministry_id'] ?? 0);
            if ($ministryId <= 0 || isset($ministryNames[$ministryId])) {
                continue;
            }
            $ministryNames[$ministryId] = (string) ($row['ministry_name'] ?? '');
        }

        $ministries = [];
        foreach ($ministryNames as $ministryId => $ministryName) {
            $canWrite = $canStaff && $actor->canAccessMinistry($ministryId);
            try {
                $grid = $this->scheduleService->getScheduleGrid(
                    context: $actor,
                    ministryId: $ministryId,
                    start: $from,
                    end: $until,
                    requestedEventIds: null,
                    restrictToSchedulingEvents: false,
                )->toArray();
            } catch (\Throwable) {
                // One ministry outside scope or a missing grid must not blank
                // the rest of the day's staffing.
                continue;
            }

            $roleNames = [];
            foreach (($grid['roles'] ?? []) as $role) {
                if (!is_array($role)) {
                    continue;
                }
                $roleNames[(int) ($role['id'] ?? 0)] = (string) ($role['name'] ?? '');
            }

            $assignments = [];
            foreach (($grid['assignments'] ?? []) as $assignment) {
                if (!is_array($assignment)) {
                    continue;
                }
                $roleId = (int) ($assignment['roleId'] ?? 0);
                $assignments[] = [
                    'id' => isset($assignment['id']) && $assignment['id'] !== null && $assignment['id'] !== ''
                        ? (int) $assignment['id']
                        : null,
                    'occurrenceId' => (int) ($assignment['occurrenceId'] ?? 0),
                    'roleId' => $roleId,
                    'roleName' => $roleNames[$roleId] ?? '',
                    'personId' => (int) ($assignment['personId'] ?? 0),
                    'displayName' => (string) ($assignment['displayName'] ?? ''),
                    'label' => (string) ($assignment['label'] ?? ''),
                ];
            }

            $ministries[] = [
                'ministryId' => $ministryId,
                'ministryName' => $ministryName !== '' ? $ministryName : (string) ($grid['ministryId'] ?? $ministryId),
                'canWrite' => $canWrite,
                'people' => $canWrite ? array_values(array_filter(
                    (array) ($grid['people'] ?? []),
                    static fn (mixed $person): bool => is_array($person),
                )) : [],
                'conflicts' => $grid['conflicts'] ?? [],
                'occurrences' => $grid['occurrences'] ?? [],
                'roles' => $grid['roles'] ?? [],
                'assignments' => $assignments,
            ];
        }

        return [
            'date' => $day->format('Y-m-d'),
            'canStaff' => $canStaff,
            'ministries' => $ministries,
        ];
    }

    /**
     * Public, read-only view of one posted ministry schedule occurrence.
     *
     * The ministry board is intentionally public because ministries print and
     * post these schedules. This endpoint still uses ScheduleService so the
     * data path stays centralized and UI code does not query the database.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function publicOccurrence(array $request): array
    {
        $ministryId = (int) ($request['ministry_id'] ?? 0);
        $occurrenceId = (int) ($request['occurrence_id'] ?? 0);
        if ($ministryId <= 0 || $occurrenceId <= 0) {
            throw new ValidationFailed('ministry_id and occurrence_id are required.');
        }

        $start = new DateTimeImmutable((string) ($request['start'] ?? 'today'));
        $end = new DateTimeImmutable((string) ($request['end'] ?? $start->format(DATE_ATOM)));
        if ($end <= $start) {
            $end = $start->add(new DateInterval('P1D'));
        }

        $publicContext = new ActorContext(
            actorId: 0,
            personId: null,
            displayName: 'Public ministry board',
            permissions: [PortalPermission::ViewMinistrySchedule],
            ministryScopeIds: [],
            isPortalWideAdmin: true,
            campusScopeIds: [],
        );

        $grid = $this->scheduleService->getScheduleGrid(
            context: $publicContext,
            ministryId: $ministryId,
            start: $start,
            end: $end,
            restrictToSchedulingEvents: false,
        )->toArray();

        $occurrence = null;
        foreach ($grid['occurrences'] as $candidate) {
            if ((int) $candidate['id'] === $occurrenceId) {
                $occurrence = $candidate;
                break;
            }
        }

        if ($occurrence === null) {
            throw new ValidationFailed('Schedule occurrence was not found in the requested window.');
        }

        $rolesById = [];
        foreach ($grid['roles'] as $role) {
            $rolesById[(int) $role['id']] = [
                'id' => (int) $role['id'],
                'name' => (string) $role['name'],
                'assignments' => [],
            ];
        }

        foreach ($grid['assignments'] as $assignment) {
            if ((int) ($assignment['occurrenceId'] ?? 0) !== $occurrenceId) {
                continue;
            }

            $roleId = (int) ($assignment['roleId'] ?? 0);
            if (!isset($rolesById[$roleId])) {
                continue;
            }

            $rolesById[$roleId]['assignments'][] = [
                'id' => $assignment['id'] ?? null,
                'personId' => (int) ($assignment['personId'] ?? 0),
                'name' => (string) (($assignment['displayName'] ?? '') ?: ($assignment['label'] ?? 'Assigned')),
                'label' => (string) ($assignment['label'] ?? ''),
            ];
        }

        return [
            'ministryId' => $ministryId,
            'occurrence' => $occurrence,
            'roles' => array_values(array_filter(
                $rolesById,
                static fn (array $role): bool => $role['assignments'] !== [],
            )),
        ];
    }

    /**
     * POST /api/schedules/assignments
     * Body: {
     *   ministryId, start, end,
     *   assignments: [{ id?, occurrenceId, personId, roleId, label? }, …]
     * }
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function saveAssignments(array $request): array
    {
        $ministryId = (int) ($request['ministryId'] ?? $request['ministry_id'] ?? 0);
        if ($ministryId <= 0) {
            throw new \App\Exceptions\ValidationFailed('ministryId is required.');
        }

        $start = isset($request['start']) ? new DateTimeImmutable((string) $request['start']) : null;
        $end   = isset($request['end'])   ? new DateTimeImmutable((string) $request['end'])   : null;

        $assignmentsRaw = $request['assignments'] ?? [];
        if (!is_array($assignmentsRaw)) {
            throw new \App\Exceptions\ValidationFailed('assignments must be an array.');
        }

        $assignments = [];
        foreach ($assignmentsRaw as $row) {
            if (!is_array($row)) continue;
            $occId = isset($row['occurrenceId']) ? (int) $row['occurrenceId'] : (int) ($row['occurrence_id'] ?? 0);
            $roleId = (int) ($row['roleId'] ?? $row['serving_role_id'] ?? 0);
            $personId = (int) ($row['personId'] ?? $row['person_id'] ?? 0);
            $label = (string) ($row['label'] ?? '');
            $id = isset($row['id']) && $row['id'] !== null && $row['id'] !== '' ? (int) $row['id'] : null;
            if ($occId <= 0 || $roleId <= 0) {
                throw new \App\Exceptions\ValidationFailed('Each assignment requires occurrenceId and roleId.');
            }
            $startsOn = isset($row['startsOn'])
                ? new DateTimeImmutable((string) $row['startsOn'])
                : new DateTimeImmutable(); // server-side derived from occurrence on save; kept for DTO completeness
            $assignments[] = new \App\DTO\Schedules\ScheduleAssignment(
                id: $id,
                personId: $personId,
                roleId: $roleId,
                startsOn: $startsOn,
                label: $label,
                occurrenceId: $occId,
            );
        }

        $command = new AssignmentBatchCommand(
            ministryId: $ministryId,
            assignments: $assignments,
            start: $start,
            end: $end,
            eventIds: $this->eventIdsFromRequest($request),
        );

        // The serving editor sends the full window (start/end + every row) so
        // the adapter can diff-delete leftovers. The day inspector staffs one
        // role: it must omit start/end, or a one-row body would wipe the rest
        // of the ministry's week. Same POST either way — not a second write API.
        $result = $this->scheduleService->saveAssignments(
            context: $this->requestContext->fromArray($request),
            command: $command,
        );

        return [
            'saved' => $result->saved,
            'assignmentCount' => $result->assignmentCount,
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return list<int>|null
     */
    private function eventIdsFromRequest(array $request): ?array
    {
        if (!array_key_exists('event_ids', $request) && !array_key_exists('eventIds', $request)) {
            return null;
        }
        $raw = $request['event_ids'] ?? $request['eventIds'];
        if (is_string($raw)) {
            $raw = preg_split('/[,\s]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The date asked for, or today. Strict Y-m-d — the same rule as the day
     * inspector's read model, so a staffing fetch cannot drift onto "next
     * Tuesday" because PHP's constructor is lenient.
     */
    private function requestedDay(array $request): DateTimeImmutable
    {
        $raw = trim((string) ($request['date'] ?? ''));
        if ($raw === '') {
            return new DateTimeImmutable('today');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
            throw new ValidationFailed('Date must be formatted YYYY-MM-DD.');
        }

        return $parsed;
    }
}
