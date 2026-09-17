#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Assignment Scheduling event scope.
 *
 * Calendar inclusion is not assignment-scheduling inclusion. The grid is
 * date + campus + selected event IDs from the loaded window. Campus is a
 * hard boundary; the campus Sunday Service is the default; other activities
 * in those dates are opt-in. The assignment tick only nominates that default.
 *
 * Run: php tests/Regression/schedule-event-scope.php
 */

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $root . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Contracts\ScheduleRepository;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\DTO\Events\EventCreateCommand;
use App\DTO\Events\EventUpdateCommand;
use App\DTO\Schedules\AssignmentBatchCommand;
use App\DTO\Schedules\ScheduleAssignment;
use App\Exceptions\PermissionDenied;
use App\Services\ScheduleService;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

final class ScopeScheduleRepository implements ScheduleRepository
{
    public const NY = 1;
    public const SC = 2;
    public const SUNDAY_NY = 10;
    public const SUNDAY_SC = 20;
    public const PRAYER_NY = 11;
    public const YOUTH_NY = 12;
    public const TRAINING_NY = 13;
    public const SCARB_ONLY = 21;

    /** @var list<array{id:int,title:string,campus_ids:list<int>,eligible:bool,is_default:bool}> */
    public array $events = [];
    /** @var list<array{id:int,event_id:int,title:string,starts_on:DateTimeImmutable,ends_on:DateTimeImmutable}> */
    public array $occurrences = [];
    /** @var list<array{id:int,occurrence_id:int,event_id:int,person_id:int,serving_role_id:int}> */
    public array $assignments = [];

    /** @var list<int>|null */
    public ?array $lastGridEventIds = null;
    /** @var list<int> */
    public array $lastGridCampusIds = [];
    /** @var list<int>|null */
    public ?array $lastSaveEventIds = null;
    /** @var list<int> */
    public array $deletedAssignmentIds = [];

    public function __construct()
    {
        $this->events = [
            ['id' => self::SUNDAY_NY, 'title' => 'Sunday Service - NY', 'campus_ids' => [self::NY], 'eligible' => true, 'is_default' => true],
            ['id' => self::PRAYER_NY, 'title' => 'NY Prayer Meeting', 'campus_ids' => [self::NY], 'eligible' => true, 'is_default' => false],
            ['id' => self::YOUTH_NY, 'title' => 'NY Youth Activity', 'campus_ids' => [self::NY], 'eligible' => false, 'is_default' => false],
            ['id' => self::TRAINING_NY, 'title' => 'Instrument Training', 'campus_ids' => [self::NY], 'eligible' => false, 'is_default' => false],
            ['id' => self::SUNDAY_SC, 'title' => 'Sunday Service - SC', 'campus_ids' => [self::SC], 'eligible' => true, 'is_default' => true],
            ['id' => self::SCARB_ONLY, 'title' => 'Scarborough-only activity', 'campus_ids' => [self::SC], 'eligible' => true, 'is_default' => false],
        ];
        for ($i = 1; $i <= 12; $i++) {
            $this->events[] = [
                'id' => 100 + $i,
                'title' => 'Ordinary calendar event ' . $i,
                'campus_ids' => [self::NY],
                'eligible' => false,
                'is_default' => false,
            ];
        }

        $t = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s);
        $this->occurrences = [
            ['id' => 501, 'event_id' => self::SUNDAY_NY, 'title' => 'Sunday Service - NY', 'starts_on' => $t('2026-05-03 09:00'), 'ends_on' => $t('2026-05-03 11:00')],
            ['id' => 502, 'event_id' => self::PRAYER_NY, 'title' => 'NY Prayer Meeting', 'starts_on' => $t('2026-05-06 19:00'), 'ends_on' => $t('2026-05-06 20:00')],
            ['id' => 503, 'event_id' => self::YOUTH_NY, 'title' => 'NY Youth Activity', 'starts_on' => $t('2026-05-09 18:00'), 'ends_on' => $t('2026-05-09 20:00')],
            ['id' => 504, 'event_id' => self::TRAINING_NY, 'title' => 'Instrument Training', 'starts_on' => $t('2026-05-10 10:00'), 'ends_on' => $t('2026-05-10 12:00')],
            ['id' => 601, 'event_id' => self::SUNDAY_SC, 'title' => 'Sunday Service - SC', 'starts_on' => $t('2026-05-03 09:00'), 'ends_on' => $t('2026-05-03 11:00')],
            ['id' => 602, 'event_id' => self::SCARB_ONLY, 'title' => 'Scarborough-only activity', 'starts_on' => $t('2026-05-04 19:00'), 'ends_on' => $t('2026-05-04 21:00')],
        ];
        foreach ($this->events as $event) {
            if ($event['id'] >= 100) {
                $this->occurrences[] = [
                    'id' => 700 + $event['id'],
                    'event_id' => $event['id'],
                    'title' => $event['title'],
                    'starts_on' => $t('2026-05-12 19:00'),
                    'ends_on' => $t('2026-05-12 20:00'),
                ];
            }
        }

        $this->assignments = [
            ['id' => 1, 'occurrence_id' => 501, 'event_id' => self::SUNDAY_NY, 'person_id' => 42, 'serving_role_id' => 7],
            ['id' => 2, 'occurrence_id' => 502, 'event_id' => self::PRAYER_NY, 'person_id' => 43, 'serving_role_id' => 7],
        ];
    }

    /** @param list<int> $campusIds */
    private function eventVisible(array $event, array $campusIds): bool
    {
        if ($campusIds === []) {
            return true;
        }
        if ($event['campus_ids'] === []) {
            return true;
        }

        return array_intersect($event['campus_ids'], $campusIds) !== [];
    }

    public function listEligibleSchedulingEvents(array $campusIds = []): array
    {
        $out = [];
        foreach ($this->events as $event) {
            if (!$event['eligible'] || !$this->eventVisible($event, $campusIds)) {
                continue;
            }
            $out[] = [
                'id' => $event['id'],
                'title' => $event['title'],
                'is_default' => (bool) $event['is_default'],
            ];
        }

        return $out;
    }

    /**
     * Everything visible on this campus with an occurrence in the window,
     * regardless of the eligibility flag — the picker's real source now.
     */
    public function listSchedulableEventsInRange(array $campusIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $from = $start->setTime(0, 0, 0);
        $until = $end->setTime(0, 0, 0)->modify('+1 day');
        $out = [];
        foreach ($this->events as $event) {
            if (!$this->eventVisible($event, $campusIds)) {
                continue;
            }
            $inRange = false;
            foreach ($this->occurrences as $occ) {
                if ((int) $occ['event_id'] !== (int) $event['id']) {
                    continue;
                }
                if ($occ['starts_on'] >= $from && $occ['starts_on'] < $until) {
                    $inRange = true;
                    break;
                }
            }
            if (!$inRange) {
                continue;
            }
            $out[] = [
                'id' => $event['id'],
                'title' => $event['title'],
                'is_default' => (bool) $event['is_default'],
            ];
        }

        return $out;
    }

    public function listDefaultSchedulingEventIds(array $campusIds = []): array
    {
        $ids = [];
        foreach ($this->listEligibleSchedulingEvents($campusIds) as $event) {
            if ($event['is_default']) {
                $ids[] = $event['id'];
            }
        }

        return $ids;
    }

    public function fetchScheduleGrid(int $ministryId, DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], ?array $eventIds = null): array
    {
        $this->lastGridCampusIds = $campusIds;
        $this->lastGridEventIds = $eventIds;

        $visibleEventIds = [];
        foreach ($this->events as $event) {
            if ($this->eventVisible($event, $campusIds)) {
                $visibleEventIds[$event['id']] = true;
            }
        }

        $wanted = $eventIds === null ? null : array_fill_keys($eventIds, true);
        $occurrences = [];
        foreach ($this->occurrences as $occ) {
            if (!isset($visibleEventIds[$occ['event_id']])) {
                continue;
            }
            if ($wanted !== null && !isset($wanted[$occ['event_id']])) {
                continue;
            }
            if ($occ['starts_on'] < $start || $occ['starts_on'] >= $end) {
                continue;
            }
            $occurrences[] = [
                'id' => $occ['id'],
                'event_id' => $occ['event_id'],
                'event_title' => $occ['title'],
                'starts_on' => $occ['starts_on'],
                'ends_on' => $occ['ends_on'],
            ];
        }

        $occurrenceIds = array_fill_keys(array_map(static fn (array $o): int => $o['id'], $occurrences), true);
        $assignments = [];
        foreach ($this->assignments as $row) {
            if (!isset($occurrenceIds[$row['occurrence_id']])) {
                continue;
            }
            $assignments[] = [
                'id' => $row['id'],
                'occurrence_id' => $row['occurrence_id'],
                'person_id' => $row['person_id'],
                'serving_role_id' => $row['serving_role_id'],
                'starts_on' => $start,
                'label' => '',
                'display_name' => 'Person',
            ];
        }

        return [
            'ministry_id' => $ministryId,
            'start' => $start,
            'end' => $end,
            'roles' => [['id' => 7, 'name' => 'Usher']],
            'people' => [['id' => 42, 'display_name' => 'A Person']],
            'special_candidates' => [],
            'occurrences' => $occurrences,
            'assignments' => $assignments,
            'conflicts' => [],
            'warnings' => [],
        ];
    }

    public function fetchScheduleBoard(DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], array $ministryIds = []): array
    {
        return [];
    }

    public function fetchMySchedule(int $personId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return ['person_id' => $personId, 'start' => $start, 'end' => $end, 'assignments' => []];
    }

    public function saveAssignments(AssignmentBatchCommand $command): array
    {
        $this->lastSaveEventIds = $command->eventIds;
        $this->deletedAssignmentIds = [];
        $scope = array_fill_keys($command->eventIds ?? [], true);
        $touched = [];
        foreach ($command->assignments as $assignment) {
            if ($assignment->id !== null) {
                $touched[$assignment->id] = true;
            }
        }
        if ($command->eventIds !== null && $command->eventIds !== []) {
            foreach ($this->assignments as $row) {
                if (!isset($scope[$row['event_id']])) {
                    continue;
                }
                if (!isset($touched[$row['id']])) {
                    $this->deletedAssignmentIds[] = $row['id'];
                }
            }
        }

        return ['saved' => true, 'assignment_count' => count($command->assignments)];
    }
}

$start = new DateTimeImmutable('2026-05-01');
$end = new DateTimeImmutable('2026-05-31');
$ny = new ActorContext(
    actorId: 1,
    personId: 42,
    displayName: 'Scheduler',
    permissions: [PortalPermission::ManageSchedules],
    ministryScopeIds: [10],
    campusScopeIds: [ScopeScheduleRepository::NY, ScopeScheduleRepository::SC],
    currentCampusId: ScopeScheduleRepository::NY,
    currentCampusIds: [ScopeScheduleRepository::NY],
);
$sc = $ny->withCurrentCampusIds([ScopeScheduleRepository::SC]);

$titles = static function ($grid): array {
    $out = [];
    foreach ($grid->occurrences as $o) {
        $out[] = $o->eventTitle;
    }

    return $out;
};
$ids = static function ($grid): array {
    $out = [];
    foreach ($grid->occurrences as $o) {
        $out[] = $o->eventId;
    }

    return $out;
};

echo "Campus is the hard boundary\n";
$repo = new ScopeScheduleRepository();
$service = new ScheduleService($repo);
$nyGrid = $service->getScheduleGrid($ny, 10, $start, $end);
$nyPool = array_map(static fn ($e) => $e->title, $nyGrid->schedulingEvents);
check('NY pool includes Sunday Service - NY', in_array('Sunday Service - NY', $nyPool, true));
check('NY pool includes NY Prayer Meeting (eligible, opt-in)', in_array('NY Prayer Meeting', $nyPool, true));
check('NY pool never includes Sunday Service - SC', !in_array('Sunday Service - SC', $nyPool, true));
check('NY pool never includes Scarborough-only activities', !in_array('Scarborough-only activity', $nyPool, true));
check('NY grid campus filter is [NY]', $repo->lastGridCampusIds === [ScopeScheduleRepository::NY]);

$scGrid = $service->getScheduleGrid($sc, 10, $start, $end);
$scPool = array_map(static fn ($e) => $e->title, $scGrid->schedulingEvents);
check('SC pool includes Sunday Service - SC', in_array('Sunday Service - SC', $scPool, true));
check('SC pool never includes Sunday Service - NY', !in_array('Sunday Service - NY', $scPool, true));
check('SC pool never includes NY Prayer Meeting', !in_array('NY Prayer Meeting', $scPool, true));
check('SC grid campus filter is [SC]', $repo->lastGridCampusIds === [ScopeScheduleRepository::SC]);

echo "Default = campus Sunday Service only\n";
check('NY default selects Sunday Service - NY', $nyGrid->selectedEventIds === [ScopeScheduleRepository::SUNDAY_NY]);
check('NY default occurrences are only Sunday Service - NY', $ids($nyGrid) === [ScopeScheduleRepository::SUNDAY_NY]);
check('SC default selects Sunday Service - SC', $scGrid->selectedEventIds === [ScopeScheduleRepository::SUNDAY_SC]);
check('SC default occurrences are only Sunday Service - SC', $ids($scGrid) === [ScopeScheduleRepository::SUNDAY_SC]);

echo "Additional campus events do not flood the scheduler\n";
check('NY default does not include Prayer Meeting occurrences', !in_array(ScopeScheduleRepository::PRAYER_NY, $ids($nyGrid), true));
check('NY default does not include Youth Activity', !in_array('NY Youth Activity', $titles($nyGrid), true));
check('NY default does not include Instrument Training', !in_array('Instrument Training', $titles($nyGrid), true));
$ordinary = array_filter($titles($nyGrid), static fn (string $t): bool => str_starts_with($t, 'Ordinary calendar event'));
check('Many ordinary calendar events exist but none appear by default', $ordinary === []);
check('NY default workspace has a single occurrence', count($nyGrid->occurrences) === 1);

echo "Opt-in selection\n";
$withPrayer = $service->getScheduleGrid($ny, 10, $start, $end, [ScopeScheduleRepository::SUNDAY_NY, ScopeScheduleRepository::PRAYER_NY]);
check(
    'Selecting Prayer Meeting adds its occurrences',
    in_array(ScopeScheduleRepository::SUNDAY_NY, $ids($withPrayer), true)
        && in_array(ScopeScheduleRepository::PRAYER_NY, $ids($withPrayer), true),
);
check('Selected IDs echo the valid subset', $withPrayer->selectedEventIds === [ScopeScheduleRepository::SUNDAY_NY, ScopeScheduleRepository::PRAYER_NY]);
$withoutPrayer = $service->getScheduleGrid($ny, 10, $start, $end, [ScopeScheduleRepository::SUNDAY_NY]);
check('Deselecting Prayer Meeting removes its occurrences', $ids($withoutPrayer) === [ScopeScheduleRepository::SUNDAY_NY]);
$empty = $service->getScheduleGrid($ny, 10, $start, $end, []);
check('Explicit empty selection is an empty workspace, not every event', $empty->occurrences === [] && $empty->selectedEventIds === []);

echo "Client event IDs are not trusted\n";
$cross = $service->getScheduleGrid($ny, 10, $start, $end, [ScopeScheduleRepository::SUNDAY_SC, ScopeScheduleRepository::SCARB_ONLY]);
check('Other-campus event IDs are ignored', $cross->selectedEventIds === [] && $ids($cross) === []);
$mixed = $service->getScheduleGrid($ny, 10, $start, $end, [ScopeScheduleRepository::SUNDAY_SC, ScopeScheduleRepository::SUNDAY_NY]);
check('Valid IDs are kept when mixed with another campus', $mixed->selectedEventIds === [ScopeScheduleRepository::SUNDAY_NY]);
// The boundary moved, deliberately. It used to be "was this event ticked";
// it is now "does this event happen in these weeks, on this campus". A
// scheduler may pick any activity in the window — the tick only decides which
// one the workspace opens with — so an unticked event in range is now a valid
// choice rather than a rejected one.
$unticked = $service->getScheduleGrid($ny, 10, $start, $end, [ScopeScheduleRepository::YOUTH_NY]);
check('An unticked activity in range may be scheduled', $unticked->selectedEventIds === [ScopeScheduleRepository::YOUTH_NY]);

// What must still be refused: dates outside the window the grid was built for.
$farStart = new DateTimeImmutable('2026-09-01');
$farEnd = new DateTimeImmutable('2026-09-30');
$outOfRange = $service->getScheduleGrid($ny, 10, $farStart, $farEnd, [ScopeScheduleRepository::SUNDAY_NY]);
check('An event with no occurrence in the window is not offered',
    $outOfRange->selectedEventIds === [] && $ids($outOfRange) === []);
check('and the pool for that window is empty rather than every event',
    $outOfRange->schedulingEvents === []);

echo "Existing assignments are not wiped when another event is unselected\n";
$saved = $service->saveAssignments($ny, new AssignmentBatchCommand(
    ministryId: 10,
    assignments: [
        new ScheduleAssignment(1, 42, 7, new DateTimeImmutable('2026-05-03 09:00'), '', 501),
    ],
    start: $start,
    end: $end,
    eventIds: [ScopeScheduleRepository::SUNDAY_NY],
));
check('Sunday-only save succeeds', $saved->saved === true);
check('Save is scoped to Sunday Service - NY', $repo->lastSaveEventIds === [ScopeScheduleRepository::SUNDAY_NY]);
check('Prayer Meeting assignment is not deleted', !in_array(2, $repo->deletedAssignmentIds, true));
check('Payload assignment is not treated as leftover', !in_array(1, $repo->deletedAssignmentIds, true));

$crossSave = $service->saveAssignments($ny, new AssignmentBatchCommand(
    ministryId: 10,
    assignments: [
        new ScheduleAssignment(1, 42, 7, new DateTimeImmutable('2026-05-03 09:00'), '', 501),
    ],
    start: $start,
    end: $end,
    eventIds: [ScopeScheduleRepository::SUNDAY_SC],
));
check('Save with only a foreign event ID does not scope deletes to SC', $repo->lastSaveEventIds === []);

echo "Public occurrence lookup is not limited to the campus default\n";
$public = $service->getScheduleGrid($ny, 10, $start, $end, null, false);
check(
    'Unrestricted grid still sees campus-valid non-default events',
    in_array(ScopeScheduleRepository::PRAYER_NY, $ids($public), true)
        && in_array(ScopeScheduleRepository::YOUTH_NY, $ids($public), true)
        && !in_array(ScopeScheduleRepository::SUNDAY_SC, $ids($public), true),
);

echo "Event DTOs carry the scheduling flag without title matching\n";
$create = (new EventCreateCommand(title: 'Choir practice', usesServingSchedule: true))->toArray();
check('Create payload includes uses_serving_schedule', $create['uses_serving_schedule'] === true);
$update = (new EventUpdateCommand(usesServingSchedule: false))->toArray();
check('Update payload can turn the flag off', $update['uses_serving_schedule'] === false);
$leave = (new EventUpdateCommand(title: 'Choir practice'))->toArray();
check('Update omits a decision when the flag is not supplied', $leave['uses_serving_schedule'] === null);

echo "Source contracts\n";
$schema = (string) file_get_contents($root . '/database/members/001_schema.sql');
check('Schema has events.uses_serving_schedule', str_contains($schema, 'uses_serving_schedule'));
check('Schema has campuses.default_scheduling_event_id', str_contains($schema, 'default_scheduling_event_id'));

$phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app'));
$runtimeTitleMatch = [];
foreach ($phpFiles as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $text = (string) file_get_contents($file->getPathname());
    if (preg_match('/LIKE\s+[\'"]%?Sunday Service/i', $text) === 1) {
        $runtimeTitleMatch[] = $file->getPathname();
    }
}
check('No runtime PHP matches events by Sunday Service title', $runtimeTitleMatch === [], implode(', ', $runtimeTitleMatch));

$adapter = (string) file_get_contents($root . '/app/Adapters/Sql/SqlScheduleAdapter.php');
check('Schedule adapter lists only uses_serving_schedule events', str_contains($adapter, 'uses_serving_schedule'));
check('Schedule adapter filters occurrences by event id', str_contains($adapter, 'function eventIdPredicate'));
check('Save diffs are scoped by event id', str_contains($adapter, 'save_event_'));

$editor = (string) file_get_contents($root . '/resources/views/schedule-editor.php');
check('Scheduler has an event picker, not a permanent checkbox wall', str_contains($editor, 'eventSearch') && str_contains($editor, 'Events being scheduled'));
check('Scheduler sends event_ids to the grid API', str_contains($editor, 'event_ids[]'));
check('Scheduler sends selected eventIds on save', str_contains($editor, 'eventIds: selectedEventIds'));
check('Empty picker blames the date window, not the assignment tick', str_contains($editor, 'No activities happen on this campus in these dates'));
check('Empty picker does not tell people to tick Allow ministry assignments', !str_contains($editor, 'No events on this campus are set up for assignments yet'));

$eventEditor = (string) file_get_contents($root . '/resources/views/_event-editor.php');
check('Event editor can opt an event into assignment scheduling', str_contains($eventEditor, 'eeAssignmentScheduling'));
check('Event editor copy distinguishes calendar from assignments', str_contains($eventEditor, 'Allow ministry assignments for this event'));
check('Event editor copy says the tick nominates a campus default', str_contains($eventEditor, 'nominates a default'));

$campusAdmin = (string) file_get_contents($root . '/resources/views/admin-campuses.php');
check('Campus admin can set the default assignment event', str_contains($campusAdmin, 'default_scheduling_event_id'));

$outOfScope = new ActorContext(
    actorId: 9,
    personId: 9,
    displayName: 'Member',
    permissions: [PortalPermission::ViewOwnAssignments],
    ministryScopeIds: [],
);
try {
    $service->getScheduleGrid($outOfScope, 10, $start, $end);
    check('Out-of-scope actor cannot read the grid', false);
} catch (PermissionDenied) {
    check('Out-of-scope actor cannot read the grid', true);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
