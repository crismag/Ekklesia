#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Product-invariant regression checks that do not need ChurchCRM.
 *
 * Run: php tests/Regression/run.php
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

use App\Contracts\EventRepository;
use App\Contracts\MinistryRepository;
use App\Contracts\ScheduleRepository;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\DTO\Events\EventCreateCommand;
use App\DTO\Schedules\AssignmentBatchCommand;
use App\DTO\Schedules\ScheduleAssignment;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use App\Services\EventService;
use App\Services\MinistryService;
use App\Services\ScheduleService;
use DateTimeImmutable;

$failures = 0;
$passed = 0;

function assert_true(bool $ok, string $message): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        echo "  ok  {$message}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$message}\n";
}

function assert_throws(string $class, callable $fn, string $message): void
{
    try {
        $fn();
        assert_true(false, $message . ' (no exception)');
    } catch (Throwable $e) {
        assert_true($e instanceof $class, $message . ' (' . $e::class . ': ' . $e->getMessage() . ')');
    }
}

final class FakeEventRepository implements EventRepository
{
    public bool $created = false;

    /** @var list<array<string,mixed>> rows listUpcoming() should hand back */
    public array $upcomingRows = [];

    /**
     * Audience this fake's single event belongs to. Stands in for the SQL
     * predicate: rows are withheld unless the caller asked for this audience,
     * which is what the real adapter's WHERE clause does.
     */
    public string $audience = 'members';

    /** @var list<string>|null the audiences the last call actually passed */
    public ?array $lastAudiences = null;

    /** @param list<string> $audiences */
    private function visibleTo(array $audiences): bool
    {
        $this->lastAudiences = $audiences;
        return in_array($this->audience, $audiences, true);
    }

    public function listOccurrences(string $from, string $to, ?int $campusId, int $limit, bool $descending = false, array $audiences = []): array
    {
        return $this->visibleTo($audiences) ? $this->upcomingRows : [];
    }

    public function listUpcoming(int $limit, ?int $campusId = null, string $range = 'upcoming', ?string $anchor = null, array $audiences = []): array
    {
        return $this->visibleTo($audiences) ? $this->upcomingRows : [];
    }

    public function findOccurrenceAudience(int $occurrenceId): ?array
    {
        return ['event_id' => 1, 'audience' => $this->audience];
    }

    public function findEvent(int $eventId, DateTimeImmutable $start, DateTimeImmutable $end, array $audiences = []): ?array
    {
        if (!$this->visibleTo($audiences)) {
            return null;
        }

        return [
            'event_id' => $eventId,
            'event_title' => 'x',
            'event_desc' => null,
            'occurrences' => [],
            'campus_ids' => [],
            'available_campuses' => [],
            'is_multi_campus' => false,
        ];
    }

    public function createEvent(array $cmd, DateTimeImmutable $now): int
    {
        $this->created = true;
        return 1;
    }

    public function updateEvent(int $eventId, array $cmd, DateTimeImmutable $now): bool
    {
        return true;
    }

    /** @var list<array<string,mixed>> the rows the service last generated */
    public array $lastOccurrenceRows = [];

    public function insertOccurrences(int $eventId, array $rows): array
    {
        $this->lastOccurrenceRows = $rows;

        return $rows;
    }

    /** @var array<int,bool> occurrence id => cancelled */
    public array $cancelledFlags = [];

    public function setOccurrenceCancelled(int $occurrenceId, bool $cancelled): bool
    {
        $this->cancelledFlags[$occurrenceId] = $cancelled;

        return true;
    }

    /** @var array<int,array{title:?string,desc:?string,modified:bool}> */
    public array $overrides = [];

    public function setOccurrenceOverrides(int $occurrenceId, ?string $title, ?string $desc): bool
    {
        // Mirrors the adapter: is_modified is derived from the two columns
        // rather than passed in, so a test can assert on it meaningfully.
        $this->overrides[$occurrenceId] = [
            'title' => ($title ?? '') === '' ? null : $title,
            'desc' => ($desc ?? '') === '' ? null : $desc,
            'modified' => ($title ?? '') !== '' || ($desc ?? '') !== '',
        ];

        return true;
    }

    /** @var list<array{slug:string,label:string}> */
    public array $tags = [];

    public function listTags(): array
    {
        return array_map(static fn (array $t): array =>
            $t + ['tag_id' => 1, 'usage_count' => 1], $this->tags);
    }

    public function tagsForEvent(int $eventId): array
    {
        return array_map(static fn (array $t): array => $t + ['tag_id' => 1], $this->tags);
    }

    public function setEventTags(int $eventId, array $tags): array
    {
        $this->tags = $tags;

        return $this->tagsForEvent($eventId);
    }

    public function eventIdsWithTag(string $slug): array
    {
        return [];
    }

    public function findOccurrence(int $occurrenceId): ?array
    {
        foreach ($this->series as $row) {
            if ($row['occurrence_id'] === $occurrenceId) {
                return $row + ($this->overrides[$occurrenceId] ?? []);
            }
        }

        return null;
    }

    public bool $occurrenceDeleted = false;

    public function deleteOccurrence(int $occurrenceId): bool
    {
        $this->occurrenceDeleted = true;

        return true;
    }

    public function countAssignmentsForOccurrence(int $occurrenceId): int
    {
        return 0;
    }

    /**
     * A small in-memory series, so the retime and bulk-delete tests can assert
     * on what actually moved rather than on the fact a call was made.
     *
     * @var list<array{occurrence_id:int,occurrence_start:string,occurrence_end:string}>
     */
    public array $series = [];

    /** Assignments the fake should claim exist across a batch. */
    public int $batchAssignments = 0;

    public function listEventOccurrences(int $eventId): array
    {
        return $this->series;
    }

    public function updateOccurrenceTimes(int $occurrenceId, string $start, string $end): bool
    {
        foreach ($this->series as $i => $row) {
            if ($row['occurrence_id'] === $occurrenceId) {
                $this->series[$i]['occurrence_start'] = $start;
                $this->series[$i]['occurrence_end'] = $end;

                return true;
            }
        }

        return false;
    }

    public function deleteOccurrencesForEvent(int $eventId, array $occurrenceIds): int
    {
        $before = count($this->series);
        $drop = array_flip(array_map('intval', $occurrenceIds));
        $this->series = array_values(array_filter(
            $this->series,
            static fn (array $r): bool => !isset($drop[$r['occurrence_id']]),
        ));

        return $before - count($this->series);
    }

    public function countAssignmentsForOccurrences(array $occurrenceIds): int
    {
        return $this->batchAssignments;
    }

    public function updateOccurrenceTimesBatch(int $eventId, array $rows): int
    {
        $n = 0;
        foreach ($rows as $r) {
            $n += $this->updateOccurrenceTimes($r['occurrence_id'], $r['occurrence_start'], $r['occurrence_end']) ? 1 : 0;
        }

        return $n;
    }

    public bool $eventDeleted = false;

    /** @var array<string,mixed>|null the rule the service last stored */
    public ?array $recurrence = null;
    public bool $recurrenceWritten = false;

    public function saveRecurrence(int $eventId, ?array $rule): void
    {
        $this->recurrenceWritten = true;
        $this->recurrence = $rule;
    }

    public function findRecurrence(int $eventId): ?array
    {
        return $this->recurrence;
    }

    public function deleteEvent(int $eventId): bool
    {
        $this->eventDeleted = true;
        $this->series = [];

        return true;
    }
}

/**
 * Only listMinistriesAdmin() carries data — it is what MinistryService::
 * listMinistriesPublic() reads, and its ministry_id key is the whole point of
 * the test below. Every other method is a stub, but all of them must exist or
 * PHP fatals before a single assertion runs.
 */
final class FakeMinistryNamesRepository implements MinistryRepository
{
    public function listMinistriesAdmin(?int $campusId = null): array
    {
        return [['ministry_id' => 7, 'name' => 'Worship', 'campus_id' => null, 'active' => true]];
    }

    public function listMinistries(array $ministryIds = []): array { return []; }
    public function listCampuses(): array { return []; }
    public function findPrimaryCampusIdForPerson(int $personId): ?int { return null; }
    public function findDisplayNameForPerson(int $personId): ?string { return null; }
    public function findMinistry(int $ministryId): ?array { return null; }
    public function resolveScheduleRoute(string $campusSlug, string $ministrySlug): ?array { return null; }
    public function resolveScheduleRouteByMinistry(string $ministrySlug, array $campusIds = []): ?array { return null; }
    public function resolveCampuses(array $campusSlugs): array { return []; }
    public function fetchDashboard(array $ministryIds, DateTimeImmutable $since, DateTimeImmutable $upcomingStart, DateTimeImmutable $until): array { return []; }
    public function fetchRoster(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array { return []; }
    public function fetchPeopleDirectory(DateTimeImmutable $since, ?int $campusId = null, ?int $personId = null): array { return []; }
    public function fetchMinistryRoles(int $ministryId): array { return []; }
    public function fetchMinistryLeaders(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array { return []; }
    public function createMinistryRole(int $ministryId, array $data): array { return []; }
    public function updateMinistryRole(int $roleId, array $data): array { return []; }
    public function deleteMinistryRole(int $roleId): bool { return true; }
    public function assignPersonToRole(int $personId, int $roleId): bool { return true; }
    public function removePersonFromRole(int $personId, int $roleId): bool { return true; }
    public function createMinistry(array $data): array { return []; }
    public function updateMinistry(int $ministryId, array $data): array { return []; }
    public function setMinistryActive(int $ministryId, bool $active): bool { return true; }
    public function deleteMinistry(int $ministryId): bool { return true; }
    public function listMinistryMembers(int $ministryId, ?int $campusId = null): array { return []; }
    public function listMinistryIdsForPeople(array $personIds): array
    {
        // Contract stub: these suites exercise service rules, not repository
        // behaviour. An empty map means "no current memberships".
        return [];
    }

    public function setMemberRole(int $personId, int $ministryId, int $roleId): bool { return true; }
    public function removeMemberFromMinistry(int $personId, int $ministryId): bool { return true; }
    public function addMinistryLeader(int $ministryId, int $personId): bool { return true; }
    public function removeMinistryLeader(int $ministryId, int $personId): bool { return true; }
    public function listLeadersByMinistry(?int $campusId = null): array { return []; }
    public function listGroupRoles(int $ministryId): array { return []; }
}

final class FakeEventTypeRepository implements \App\Contracts\EventTypeRepository
{
    /** @var list<array<string,mixed>> */
    public array $rows = [
        ['type_id' => 1, 'type_name' => 'General', 'type_active' => true, 'portal_slug' => 'general',
         'portal_label' => 'General', 'portal_audience' => 'members', 'portal_color' => '#2c6ea5',
         'portal_sort' => 10, 'portal_is_default' => true, 'usage_count' => 4],
        ['type_id' => 3, 'type_name' => 'Ministry event', 'type_active' => true, 'portal_slug' => 'ministry',
         'portal_label' => 'Ministry events', 'portal_audience' => 'leaders', 'portal_color' => '#117b6d',
         'portal_sort' => 20, 'portal_is_default' => false, 'usage_count' => 0],
        ['type_id' => 4, 'type_name' => 'Leadership', 'type_active' => true, 'portal_slug' => 'leadership',
         'portal_label' => 'Leadership', 'portal_audience' => 'leaders', 'portal_color' => '#7b2445',
         'portal_sort' => 30, 'portal_is_default' => false, 'usage_count' => 2],
    ];
    public bool $deleted = false;
    public bool $inserted = false;

    public function listAll(): array { return $this->rows; }
    public function find(int $typeId): ?array
    {
        foreach ($this->rows as $r) { if ((int) $r['type_id'] === $typeId) { return $r; } }
        return null;
    }
    public function insert(array $data): int { $this->inserted = true; return 99; }
    public function update(int $typeId, array $data): bool { return true; }
    public function delete(int $typeId): bool { $this->deleted = true; return true; }
    public function countEventsOfType(int $typeId): int { return (int) ($this->find($typeId)['usage_count'] ?? 0); }
    public function slugExists(string $slug, ?int $exceptTypeId = null): bool
    {
        foreach ($this->rows as $r) {
            if ($r['portal_slug'] === $slug && (int) $r['type_id'] !== $exceptTypeId) { return true; }
        }
        return false;
    }
    public function setDefault(int $typeId): bool { return true; }
    public function listOrphanedEvents(): array { return []; }
}

final class FakeScheduleRepository implements ScheduleRepository
{
    public bool $saved = false;

    public function fetchScheduleGrid(int $ministryId, DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], ?array $eventIds = null): array
    {
        return [
            'ministry_id' => $ministryId,
            'start' => $start,
            'end' => $end,
            'roles' => [],
            'people' => [],
            'assignments' => [],
        ];
    }

    public function listEligibleSchedulingEvents(array $campusIds = []): array
    {
        return [];
    }

    public function listSchedulableEventsInRange(array $campusIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [];
    }

    public function listDefaultAssignmentEventIds(array $campusIds = []): array
    {
        return [];
    }

    public function fetchScheduleBoard(DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], array $ministryIds = []): array
    {
        return [];
    }

    public function fetchMySchedule(int $personId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [
            'person_id' => $personId,
            'start' => $start,
            'end' => $end,
            'assignments' => [],
        ];
    }

    public function saveAssignments(AssignmentBatchCommand $command): array
    {
        $this->saved = true;
        return ['saved' => true, 'assignment_count' => count($command->assignments)];
    }
}

echo "People privacy source contract\n";
$peopleSrc = (string) file_get_contents($root . '/app/Http/Controllers/Api/PeopleController.php');
assert_true($peopleSrc !== '', 'PeopleController is readable');
assert_true(str_contains($peopleSrc, 'private function maskedName'), 'Public directory masking lives in maskedName()');
assert_true(str_contains($peopleSrc, 'strtoupper(substr($lastName, 0, 1))'), 'Non-privileged display names use last-name initial only');
assert_true(str_contains($peopleSrc, 'if ($privileged)'), 'Contact/address serialization is gated on privileged');
assert_true(str_contains($peopleSrc, "'contact'") && str_contains($peopleSrc, "'address'"), 'Privileged payload includes contact and address keys');
assert_true(str_contains($peopleSrc, "\$base['lastName'] = \$lastName;"), 'Full last name is serialized only for privileged callers');

echo "Campus cookie / compact-nav source contract\n";
$shell = (string) file_get_contents($root . '/resources/views/_portal-shell.php');
assert_true($shell !== '', '_portal-shell.php is readable');
assert_true(str_contains($shell, 'portal_campus_id'), 'Campus context cookie is portal_campus_id');
assert_true(str_contains($shell, 'id="campusSelect"'), 'Campus control id is campusSelect');
assert_true(
    str_contains($shell, '820px') && str_contains($shell, '.topbar-campus'),
    'Campus selector is visually hidden at max-width 820px, not removed from markup by CSS',
);

echo "Destructive admin confirmations (current intended UX)\n";
// What a confirmation has to do, rather than what it happens to say.
//
// This used to pin the exact phrase — "Delete user" — which made the test fail
// when the wording improved to "Delete the account for …" and would have passed
// a dialog reading "Delete user. Are you sure?". The requirement is that the
// dialog names the destructive act and states its consequence, so that is what
// is checked: the verb, and language describing what happens.
$confirmFiles = [
    'resources/views/admin-users.php' => 'delete',
    'resources/views/admin-person-view.php' => 'delete',
    'resources/views/admin-family-edit.php' => 'delete',
    'resources/views/admin-campuses.php' => 'delete',
    'resources/views/admin-family-duplicates.php' => 'merge',
    'resources/views/admin-options.php' => 'delete',
    'resources/views/admin-event-types.php' => 'delete',
];
$consequenceWords = [
    'cannot be undone', 'removes', 'removed', 'are moved', 'will be moved',
    'no longer', 'only works', 'deleted', 'is not affected',
];
foreach ($confirmFiles as $file => $verb) {
    $src = (string) file_get_contents($root . '/' . $file);
    assert_true(str_contains($src, 'confirm('), "{$file} uses confirm() before destructive submit");

    preg_match_all('/confirm\(\s*[\x27"](.*?)[\x27"]\s*\)/s', $src, $matches);
    $copy = strtolower(implode(' ', $matches[1]));
    assert_true($copy !== '', "{$file} confirm copy could be read");
    assert_true(str_contains($copy, $verb), "{$file} confirm copy names the action ({$verb})");

    $saysWhatHappens = false;
    foreach ($consequenceWords as $word) {
        if (str_contains($copy, $word)) {
            $saysWhatHappens = true;
            break;
        }
    }
    assert_true($saysWhatHappens, "{$file} confirm copy states the consequence, not just 'are you sure'");
    assert_true(!str_contains($copy, 'are you sure'), "{$file} confirm copy avoids a bare 'are you sure'");
}

echo "HTML vs API authorization comments stay honest\n";
$web = (string) file_get_contents($root . '/routes/web.php');
assert_true(str_contains($web, "'GET /admin'"), 'GET /admin is a registered HTML route');
assert_true(str_contains($web, 'isPortalWideAdmin'), 'Admin POST handlers consult isPortalWideAdmin');
assert_true(str_contains($web, 'schedule_editor_denied'), 'Schedule editor deny uses schedule_editor_denied');

$auth = (string) file_get_contents($root . '/app/Http/Controllers/Api/AuthController.php');
assert_true(str_contains($auth, 'mustChangePassword'), 'Login payload exposes mustChangePassword');

echo "Ministry name resolves onto event rows (migration 008 linkage)\n";
// listMinistriesPublic() returns rows keyed ministry_id, but EventService read
// 'ministryId' first and fell through to 0, so the id > 0 guard skipped every
// row and ministryName was always null. The same copy of the loop existed
// twice. This asserts the resolved name, which is what actually reaches the UI.
$mrepo = new FakeMinistryNamesRepository();
$erepo = new FakeEventRepository();
$erepo->upcomingRows = [[
    'event_id' => 1, 'event_title' => 'Practice', 'event_desc' => null,
    'occurrence_count' => 1, 'next_occurrence_at' => '2026-09-01 09:00:00',
    'next_occurrence_end' => null, 'campus_names' => null, 'host_campus_name' => null,
    'location_name' => null, 'ministry_id' => 7,
]];
$withMinistries = new EventService($erepo, new MinistryService($mrepo));
$viewer = new ActorContext(
    actorId: 5, personId: 42, displayName: 'Member',
    permissions: PortalPermission::forRole('member'), ministryScopeIds: [],
);
$summaries = $withMinistries->listUpcoming($viewer);
assert_true(count($summaries) === 1, 'listUpcoming returns the row');
assert_true(
    ($summaries[0]->ministryId ?? null) === 7,
    'ministryId survives onto EventSummary',
);
assert_true(
    ($summaries[0]->ministryName ?? null) === 'Worship',
    'ministryName resolves from the ministry_id key (was always null)',
);

echo "Event types gate their own audience (EventTypeService)\n";
$typeRepo = new FakeEventTypeRepository();
$typeSvc = new \App\Services\EventTypeService($typeRepo);
$typeMember = new ActorContext(
    actorId: 20, personId: 20, displayName: 'Member',
    permissions: PortalPermission::forRole('member'), ministryScopeIds: [],
);
$typeLeader = new ActorContext(
    actorId: 21, personId: 21, displayName: 'Leader',
    permissions: PortalPermission::forRole('leader'), ministryScopeIds: [],
);
$typeAdmin = new ActorContext(
    actorId: 22, personId: 22, displayName: 'Admin',
    permissions: [], ministryScopeIds: [], isPortalWideAdmin: true,
);

// A member must not even learn that a Leadership layer exists: the chip label
// alone would confirm events were withheld.
$memberLayers = array_column($typeSvc->listLayers($typeMember), 'source');
assert_true($memberLayers === ['events:general'], 'member is offered only member-audience layers');
$leaderLayers = array_column($typeSvc->listLayers($typeLeader), 'source');
assert_true(
    $leaderLayers === ['events:general', 'events:ministry', 'events:leadership'],
    'leader is offered every layer, in sort order',
);
assert_true(
    array_column($typeSvc->listForPicker($typeMember), 'label') === ['General'],
    'member cannot file an event under a type they could not then read',
);
assert_true($typeSvc->defaultTypeId() === 1, 'default type is resolved for new events');
assert_true($typeSvc->actorMayUseType($typeLeader, 4), 'leader may use the Leadership type');
assert_true(!$typeSvc->actorMayUseType($typeMember, 4), 'member may not use the Leadership type');

assert_throws(
    PermissionDenied::class,
    static fn () => $typeSvc->allForAdmin($typeMember),
    'only a portal-wide admin may manage event types',
);
assert_throws(
    ValidationFailed::class,
    static fn () => $typeSvc->delete($typeAdmin, 4),
    'a type still used by events cannot be deleted',
);
assert_true($typeRepo->deleted === false, 'refused delete never reaches the repository');
assert_throws(
    ValidationFailed::class,
    static fn () => $typeSvc->delete($typeAdmin, 1),
    'the default type cannot be deleted',
);
// The default lands on every unclassified event, so a leaders-only default
// would hide the back catalogue from the congregation by accident.
assert_throws(
    ValidationFailed::class,
    static fn () => $typeSvc->makeDefault($typeAdmin, 4),
    'a leaders-only type cannot become the default',
);
assert_throws(
    ValidationFailed::class,
    static fn () => $typeSvc->add($typeAdmin, 'Anything', 'members', 'teal', 50),
    'a colour that is not six-digit hex is rejected, not silently corrected',
);
assert_throws(
    ValidationFailed::class,
    static fn () => $typeSvc->add($typeAdmin, 'Anything', 'everyone', '#112233', 50),
    'an unknown audience is rejected',
);
assert_throws(
    ValidationFailed::class,
    static fn () => $typeSvc->add($typeAdmin, 'Leadership', 'leaders', '#112233', 50),
    'a duplicate layer key is rejected',
);
assert_true($typeRepo->inserted === false, 'no rejected add reached the repository');
$typeSvc->add($typeAdmin, 'Youth Night', 'leaders', '#AA3355', 70);
assert_true($typeRepo->inserted === true, 'a valid add does reach the repository');

echo "Leader-audience events are invisible to members\n";
// Ministry and Leadership share the 'leaders' audience: one access level, two
// categories. Enforcement is a SQL predicate on every read path, so this
// asserts the service actually asks for the right audience set and honours an
// empty answer — not that a template hides a row.
$leaderRepo = new FakeEventRepository();
$leaderRepo->audience = 'leaders';
$leaderRepo->upcomingRows = [[
    'event_id' => 9, 'event_title' => 'Elders meeting', 'event_desc' => null,
    'occurrence_count' => 1, 'next_occurrence_at' => '2026-09-02 19:00:00',
    'next_occurrence_end' => null, 'campus_names' => null, 'host_campus_name' => null,
    'location_name' => null, 'ministry_id' => null,
]];
$leaderEvents = new EventService($leaderRepo);

$asMember = new ActorContext(
    actorId: 5, personId: 42, displayName: 'Member',
    permissions: PortalPermission::forRole('member'), ministryScopeIds: [],
);
$asLeader = new ActorContext(
    actorId: 6, personId: 43, displayName: 'Leader',
    permissions: PortalPermission::forRole('leader'), ministryScopeIds: [],
);

assert_true(
    !in_array(PortalPermission::ViewLeaderEvents, PortalPermission::forRole('member'), true),
    'member role is not granted ViewLeaderEvents',
);
assert_true(
    in_array(PortalPermission::ViewLeaderEvents, PortalPermission::forRole('leader'), true),
    'leader role is granted ViewLeaderEvents',
);
assert_true(
    !in_array(PortalPermission::ViewLeaderEvents, PortalPermission::forRole('scheduler'), true),
    'scheduler manages events but is not granted ViewLeaderEvents',
);

assert_true($leaderEvents->listUpcoming($asMember) === [], 'member sees no leader-audience events');
assert_true(
    $leaderRepo->lastAudiences === ['public', 'members'],
    'member asks the repository for public+members only',
);
assert_true(count($leaderEvents->listUpcoming($asLeader)) === 1, 'leader sees the leader-audience event');
assert_true(
    in_array('leaders', $leaderRepo->lastAudiences ?? [], true),
    'leader asks the repository for the leaders audience',
);

// A denied event and a nonexistent one must be indistinguishable, or the
// difference between /events/9 and /events/9999 enumerates hidden events.
$now = new DateTimeImmutable('2026-09-01');
assert_true(
    $leaderEvents->getEvent($asMember, 9, $now, $now) === null,
    'member gets null for a leader-audience event, exactly as for a missing one',
);
assert_true(
    $leaderEvents->getEvent($asLeader, 9, $now, $now) !== null,
    'leader gets the event detail',
);

// A portal-wide admin bypasses the permission check entirely.
$asPortalAdmin = new ActorContext(
    actorId: 7, personId: 44, displayName: 'Admin',
    permissions: [], ministryScopeIds: [], isPortalWideAdmin: true,
);
assert_true(
    count($leaderEvents->listUpcoming($asPortalAdmin)) === 1,
    'portal-wide admin sees leader-audience events without the permission',
);

// The write side: cancelling an occurrence of an unseeable event must be
// refused, not silently allowed because deleteOccurrence takes a bare id.
$cancelRepo = new FakeEventRepository();
$cancelRepo->audience = 'leaders';
$cancelEvents = new EventService($cancelRepo);
$asScheduler = new ActorContext(
    actorId: 8, personId: 45, displayName: 'Scheduler',
    permissions: PortalPermission::forRole('scheduler'), ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $cancelEvents->cancelOccurrence($asScheduler, 501),
    'scheduler cannot cancel an occurrence of a leader-audience event',
);

echo "First Sunday of every month produces the right dates (EventService)\n";
// The dates matter more than the rule: a schedule that stores correctly and
// expands wrongly puts the wrong Sundays on the church calendar.
$nthRepo = new FakeEventRepository();
$nthEvents = new EventService($nthRepo);
$nthAdmin = new ActorContext(
    actorId: 20, personId: 69, displayName: 'Admin',
    permissions: PortalPermission::forRole('admin'), ministryScopeIds: [],
    isPortalWideAdmin: true,
);
$datesOf = static function (FakeEventRepository $repo): array {
    return array_map(
        static fn (array $r): string => substr((string) $r['occurrence_start'], 0, 10),
        $repo->lastOccurrenceRows,
    );
};

// 2026-09-06 is the first Sunday of September.
$nthEvents->createEvent($nthAdmin, new EventCreateCommand(
    title: 'Communion Sunday', startDate: '2026-09-06', startTime: '10:00', endTime: '12:00',
    pattern: 'monthly_nth', count: 5,
));
assert_true($datesOf($nthRepo) === ['2026-09-06', '2026-10-04', '2026-11-01', '2026-12-06', '2027-01-03'],
    'first Sunday of each month, and the date moves: ' . json_encode($datesOf($nthRepo)));
assert_true(($nthRepo->recurrence['recurrence_week_of_month'] ?? null) === 1,
    'and the rule records which Sunday');

// The last of a weekday is not the fourth. November 2026 has five Sundays.
$lastRepo = new FakeEventRepository();
$lastEvents = new EventService($lastRepo);
$lastEvents->createEvent($nthAdmin, new EventCreateCommand(
    title: 'Leaders meeting', startDate: '2026-09-27', startTime: '19:00',
    pattern: 'monthly_nth', count: 4,
));
assert_true($datesOf($lastRepo) === ['2026-09-27', '2026-10-25', '2026-11-29', '2026-12-27'],
    'last Sunday of each month, including a five-Sunday November: ' . json_encode($datesOf($lastRepo)));

// A fifth of a weekday does not exist every month. The month is skipped, not
// spilled into the next one.
$fifthRepo = new FakeEventRepository();
$fifthEvents = new EventService($fifthRepo);
$fifthEvents->createEvent($nthAdmin, new EventCreateCommand(
    title: 'Fifth Wednesday', startDate: '2026-09-02', startTime: '19:00',
    pattern: 'monthly_nth', untilOn: '2026-12-31',
));
$fifthDates = $datesOf($fifthRepo);
assert_true($fifthDates === ['2026-09-02', '2026-10-07', '2026-11-04', '2026-12-02'],
    'first Wednesday of each month: ' . json_encode($fifthDates));
foreach ($fifthDates as $d) {
    assert_true((int) (new DateTimeImmutable($d))->format('w') === 3, $d . ' is a Wednesday');
}

// An ordinary monthly rule still means the same date each month.
$byDateRepo = new FakeEventRepository();
$byDateEvents = new EventService($byDateRepo);
$byDateEvents->createEvent($nthAdmin, new EventCreateCommand(
    title: 'Rent due', startDate: '2026-09-06', pattern: 'monthly', count: 3, allDay: true,
));
assert_true($datesOf($byDateRepo) === ['2026-09-06', '2026-10-06', '2026-11-06'],
    'monthly by date is unchanged: ' . json_encode($datesOf($byDateRepo)));

echo "Tagging an event (EventService)\n";
$tagRepo = new FakeEventRepository();
$tagEvents = new EventService($tagRepo);
$tagAdmin = new ActorContext(
    actorId: 26, personId: 75, displayName: 'Admin',
    permissions: PortalPermission::forRole('admin'), ministryScopeIds: [],
    isPortalWideAdmin: true,
);

$saved = $tagEvents->setEventTags($tagAdmin, 1, ['Christmas', 'christmas', 'Family']);
assert_true(count($saved) === 2, 'the same tag typed twice is stored once');
assert_true(array_column($tagRepo->tags, 'slug') === ['christmas', 'family'],
    'and identity is the slug: ' . json_encode(array_column($tagRepo->tags, 'slug')));
assert_true($tagRepo->tags[0]['label'] === 'Christmas',
    'while the label keeps the casing that was typed');

// Sending the complete list is how a tag gets removed; an add-only write leaves
// no way to take one off.
$tagEvents->setEventTags($tagAdmin, 1, ['Family']);
assert_true(array_column($tagRepo->tags, 'slug') === ['family'],
    'saving a shorter list removes the others');
$tagEvents->setEventTags($tagAdmin, 1, []);
assert_true($tagRepo->tags === [], 'and an empty list clears them');

assert_throws(
    ValidationFailed::class,
    static fn () => $tagEvents->setEventTags($tagAdmin, 1, [str_repeat('a', 200)]),
    'a tag too long for the column is refused rather than truncated',
);
assert_throws(
    ValidationFailed::class,
    static fn () => $tagEvents->setEventTags($tagAdmin, 1, array_map(
        static fn (int $i): string => 'tag' . $i, range(1, 13))),
    'thirteen tags on one event is refused — past that they stop helping anyone find it',
);
assert_true($tagRepo->tags === [], 'and neither refusal wrote anything');

// Blank entries are dropped, not refused: a trailing comma in a text field is
// a typo, not an error worth stopping on.
$tagEvents->setEventTags($tagAdmin, 1, ['Music', '', '   ']);
assert_true(array_column($tagRepo->tags, 'slug') === ['music'],
    'blank entries are dropped quietly');

$tagGateRepo = new FakeEventRepository();
$tagGateRepo->audience = 'leaders';
$tagGate = new EventService($tagGateRepo);
$tagScheduler = new ActorContext(
    actorId: 27, personId: 76, displayName: 'Scheduler',
    permissions: PortalPermission::forRole('scheduler'), ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $tagGate->setEventTags($tagScheduler, 1, ['Sneaky']),
    'a scheduler cannot tag an event they may not read',
);
assert_true($tagGateRepo->tags === [], 'and the refusal wrote nothing');
assert_true($tagGate->tagsForEvent($tagScheduler, 1) === [],
    'nor can they read its tags — reported as empty, the same as a missing event');

echo "One date of a series saying something of its own (EventService)\n";
// "This Sunday we meet at the park" is a normal thing for a church to say about
// one week of a service that otherwise runs unchanged. Both columns have
// existed since the schema was created and the calendar has always read
// override_title — nothing could ever set it, so the only way to say it was to
// break the date out of its series.
$ovRepo = new FakeEventRepository();
$ovEvents = new EventService($ovRepo);
$ovAdmin = new ActorContext(
    actorId: 23, personId: 72, displayName: 'Admin',
    permissions: PortalPermission::forRole('admin'), ministryScopeIds: [],
    isPortalWideAdmin: true,
);

$res = $ovEvents->overrideOccurrence($ovAdmin, 601, 'Outdoor Worship Service',
    'This Sunday we meet at the park.');
assert_true($res['overridden'] === true, 'an override is reported as one');
assert_true($ovRepo->overrides[601]['title'] === 'Outdoor Worship Service', 'the title is stored');
assert_true($ovRepo->overrides[601]['desc'] === 'This Sunday we meet at the park.', 'so is the description');
assert_true($ovRepo->overrides[601]['modified'] === true,
    'is_modified is derived, so a reader can tell this date differs without comparing strings');

// Reversible: an override that cannot be undone is a trap.
$ovEvents->clearOccurrenceOverride($ovAdmin, 601);
assert_true($ovRepo->overrides[601]['title'] === null && $ovRepo->overrides[601]['desc'] === null,
    'clearing lets the date inherit from its event again');
assert_true($ovRepo->overrides[601]['modified'] === false, 'and it stops being marked as modified');

// One field without the other is a legitimate thing to want.
$ovEvents->overrideOccurrence($ovAdmin, 602, null, 'Bring a chair.');
assert_true($ovRepo->overrides[602]['title'] === null && $ovRepo->overrides[602]['desc'] === 'Bring a chair.',
    'a description alone overrides only the description');
assert_true($ovRepo->overrides[602]['modified'] === true, 'and still counts as modified');

// Whitespace is not a title. Storing "   " would put a blank line on the
// calendar where the series name should be.
$ovEvents->overrideOccurrence($ovAdmin, 603, '   ', "  \n ");
assert_true($ovRepo->overrides[603]['title'] === null && $ovRepo->overrides[603]['desc'] === null,
    'whitespace clears rather than overriding with nothing');
assert_true($ovRepo->overrides[603]['modified'] === false, 'and does not mark the date modified');

// The column is varchar(255); truncating silently would put half a sentence on
// the calendar.
assert_throws(
    ValidationFailed::class,
    static fn () => $ovEvents->overrideOccurrence($ovAdmin, 604, str_repeat('a', 256), null),
    'a title longer than the column is refused rather than truncated',
);
assert_true(!isset($ovRepo->overrides[604]), 'and the refused override wrote nothing');
$ovEvents->overrideOccurrence($ovAdmin, 605, str_repeat('a', 255), null);
assert_true($ovRepo->overrides[605]['title'] !== null, 'exactly 255 is allowed');

// Same gate as every other occurrence write.
$ovGateRepo = new FakeEventRepository();
$ovGateRepo->audience = 'leaders';
$ovGate = new EventService($ovGateRepo);
$ovScheduler = new ActorContext(
    actorId: 24, personId: 73, displayName: 'Scheduler',
    permissions: PortalPermission::forRole('scheduler'), ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $ovGate->overrideOccurrence($ovScheduler, 606, 'Sneaky', null),
    'a scheduler cannot rename an occurrence of an event they may not read',
);
assert_throws(
    PermissionDenied::class,
    static fn () => $ovGate->clearOccurrenceOverride($ovScheduler, 606),
    'nor clear one',
);
assert_true($ovGateRepo->overrides === [], 'and neither refusal wrote anything');

$ovMember = new ActorContext(
    actorId: 25, personId: 74, displayName: 'Member',
    permissions: PortalPermission::forRole('member'), ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $ovEvents->overrideOccurrence($ovMember, 607, 'Nope', null),
    'a member without ManageEvents cannot override an occurrence',
);

// An override says nothing about whether the date is on, and cancelling says
// nothing about what it is called. The two are independent.
$bothRepo = new FakeEventRepository();
$bothEvents = new EventService($bothRepo);
$bothEvents->overrideOccurrence($ovAdmin, 608, 'Outdoor Worship', null);
$bothEvents->cancelOccurrence($ovAdmin, 608);
assert_true($bothRepo->overrides[608]['title'] === 'Outdoor Worship',
    'cancelling a date does not erase what it was called');
assert_true(($bothRepo->cancelledFlags[608] ?? null) === true, 'and it is still cancelled');
$bothEvents->clearOccurrenceOverride($ovAdmin, 608);
assert_true(($bothRepo->cancelledFlags[608] ?? null) === true,
    'clearing the override does not quietly un-cancel the date');

echo "Changing a schedule, and the history it must not rewrite (EventService)\n";
// Retiming keeps the dates and moves the clock. Replacing the schedule changes
// which dates exist — and must never touch the ones already past, because they
// record what actually happened.
$schRepo = new FakeEventRepository();
$schEvents = new EventService($schRepo);
$schAdmin = new ActorContext(
    actorId: 21, personId: 70, displayName: 'Admin',
    permissions: PortalPermission::forRole('admin'), ministryScopeIds: [],
    isPortalWideAdmin: true,
);
$seedMixed = static function (FakeEventRepository $repo): void {
    $repo->series = [];
    $id = 1;
    foreach ([-21, -14, -7, 7, 14, 21] as $offset) {
        $d = (new DateTimeImmutable('today'))->modify($offset . ' days')->setTime(7, 30);
        $repo->series[] = [
            'occurrence_id' => $id++,
            'occurrence_start' => $d->format('Y-m-d H:i:s'),
            'occurrence_end' => $d->modify('+90 minutes')->format('Y-m-d H:i:s'),
        ];
    }
    $repo->lastOccurrenceRows = [];
};

$seedMixed($schRepo);
$nextWeek = (new DateTimeImmutable('today'))->modify('+7 days');
$res = $schEvents->replaceSchedule($schAdmin, 1, new EventCreateCommand(
    title: 'schedule', startDate: $nextWeek->format('Y-m-d'),
    startTime: '19:00', endTime: '20:30', pattern: 'biweekly', count: 4,
));
assert_true($res['removed'] === 3, 'the three upcoming dates are replaced (removed ' . $res['removed'] . ')');
assert_true(count($schRepo->series) === 3, 'and the three past dates survive');
foreach ($schRepo->series as $row) {
    assert_true(str_contains($row['occurrence_start'], '07:30:00'),
        'a past date keeps its original time — history is not rewritten');
}
assert_true($res['created'] === 4, 'the new rule produced its dates (created ' . $res['created'] . ')');
assert_true(($schRepo->recurrence['recurrence_type'] ?? '') === 'biweekly',
    'and the stored rule is the new one, not the old one');

// A rule whose dates are all in the past would wipe the future and add nothing.
$seedMixed($schRepo);
assert_throws(
    ValidationFailed::class,
    static fn () => $schEvents->replaceSchedule($schAdmin, 1, new EventCreateCommand(
        title: 'schedule',
        startDate: (new DateTimeImmutable('today'))->modify('-60 days')->format('Y-m-d'),
        startTime: '19:00', pattern: 'one_off',
    )),
    'a schedule with no dates from today onward is refused rather than emptying the calendar',
);
assert_true(count($schRepo->series) === 6, 'and the refused replacement removed nothing');

// Same assignment guard the bulk delete applies, for the same reason.
$seedMixed($schRepo);
$schRepo->batchAssignments = 2;
assert_throws(
    ValidationFailed::class,
    static fn () => $schEvents->replaceSchedule($schAdmin, 1, new EventCreateCommand(
        title: 'schedule', startDate: $nextWeek->format('Y-m-d'), startTime: '19:00', pattern: 'weekly', count: 2,
    )),
    'replacing dates that carry assignments needs confirmation',
);
assert_true(count($schRepo->series) === 6, 'and the refused replacement removed nothing');
$forced = $schEvents->replaceSchedule($schAdmin, 1, new EventCreateCommand(
    title: 'schedule', startDate: $nextWeek->format('Y-m-d'), startTime: '19:00', pattern: 'weekly', count: 2,
), true);
assert_true($forced['removed'] === 3, 'confirming goes ahead');
$schRepo->batchAssignments = 0;

$schMember = new ActorContext(
    actorId: 22, personId: 71, displayName: 'Member',
    permissions: PortalPermission::forRole('member'), ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $schEvents->replaceSchedule($schMember, 1, new EventCreateCommand(
        title: 'schedule', startDate: $nextWeek->format('Y-m-d'), pattern: 'weekly',
    )),
    'a member without ManageEvents cannot change a schedule',
);

echo "This and following, without touching what came before (EventService)\n";
$folRepo = new FakeEventRepository();
$folEvents = new EventService($folRepo);
$seedMixed($folRepo);
$pivot = $folRepo->series[3]['occurrence_start'];   // the first upcoming date
$moved = $folEvents->retimeEventOccurrences($folAdmin ?? $schAdmin, 1, '19:00', null, 'following', $pivot);
assert_true($moved === 3, 'the pivot and everything after it moves (moved ' . $moved . ')');
assert_true(str_contains($folRepo->series[2]['occurrence_start'], '07:30:00'),
    'the date before the pivot is left alone');
assert_true(str_contains($folRepo->series[3]['occurrence_start'], '19:00:00'), 'the pivot itself moves');
assert_throws(
    ValidationFailed::class,
    static fn () => $folEvents->retimeEventOccurrences($schAdmin, 1, '19:00', null, 'following', null),
    'following without a date to start from is refused rather than silently meaning all',
);

echo "Cancelling a date is not deleting it (EventService)\n";
// A Sunday with no service is not the same as a Sunday that was never
// scheduled, and somebody turns up to find out which. is_cancelled has existed
// since the schema was created and the application only ever deleted, so the
// difference could not be expressed at all.
$cxRepo = new FakeEventRepository();
$cxEvents = new EventService($cxRepo);
$cxAdmin = new ActorContext(
    actorId: 18, personId: 67, displayName: 'Admin',
    permissions: PortalPermission::forRole('admin'), ministryScopeIds: [],
    isPortalWideAdmin: true,
);
$cxEvents->cancelOccurrence($cxAdmin, 501);
assert_true(($cxRepo->cancelledFlags[501] ?? null) === true, 'cancelling marks the occurrence cancelled');
assert_true($cxRepo->occurrenceDeleted === false, 'and does not delete the row');

$cxEvents->restoreOccurrence($cxAdmin, 501);
assert_true(($cxRepo->cancelledFlags[501] ?? null) === false, 'restoring puts the date back');

$cxEvents->deleteOccurrence($cxAdmin, 501);
assert_true($cxRepo->occurrenceDeleted === true, 'deleting still deletes');

// Cancelling destroys nothing, so it is not gated on assignments the way
// deleting is — the roster survives if the date is restored.
$asgRepo = new FakeEventRepository();
$asgEvents = new EventService($asgRepo);
$asgEvents->cancelOccurrence($cxAdmin, 502);
assert_true(($asgRepo->cancelledFlags[502] ?? null) === true,
    'an occurrence with assignments can still be cancelled without confirmation');

// Both paths keep the audience gate the destructive one already had.
$gateRepo = new FakeEventRepository();
$gateRepo->audience = 'leaders';
$gateEvents = new EventService($gateRepo);
$gateScheduler = new ActorContext(
    actorId: 19, personId: 68, displayName: 'Scheduler',
    permissions: PortalPermission::forRole('scheduler'), ministryScopeIds: [],
);
foreach ([
    ['cancelOccurrence', 'cancel'],
    ['restoreOccurrence', 'restore'],
    ['deleteOccurrence', 'delete'],
] as [$method, $word]) {
    assert_throws(
        PermissionDenied::class,
        static fn () => $gateEvents->{$method}($gateScheduler, 503),
        "a scheduler cannot {$word} an occurrence of a leader-audience event",
    );
}
assert_true($gateRepo->cancelledFlags === [] && $gateRepo->occurrenceDeleted === false,
    'and none of the refused calls wrote anything');

echo "Creating an event keeps the schedule that made it (EventService)\n";
// The portal used to expand a pattern into occurrence rows and throw the
// pattern away, which is why regenerating them had to be a manual step: there
// was no schedule left to regenerate from.
$schedRepo = new FakeEventRepository();
$schedEvents = new EventService($schedRepo);
$schedAdmin = new ActorContext(
    actorId: 17, personId: 66, displayName: 'Admin',
    permissions: PortalPermission::forRole('admin'), ministryScopeIds: [],
    isPortalWideAdmin: true,
);
$schedEvents->createEvent($schedAdmin, new EventCreateCommand(
    title: 'Sunday Worship', startDate: '2026-09-06', startTime: '07:30', endTime: '09:00',
    pattern: 'weekly', untilOn: '2026-10-11',
));
assert_true($schedRepo->recurrence !== null, 'a repeating event stores its rule');
assert_true(($schedRepo->recurrence['recurrence_type'] ?? '') === 'weekly', 'and the rule says weekly');
assert_true(($schedRepo->recurrence['recurrence_days_of_week'] ?? '') === 'SU',
    'and which day, taken from the start date');
assert_true(($schedRepo->recurrence['recurrence_until'] ?? '') === '2026-10-11', 'and when it ends');

$oneOffRepo = new FakeEventRepository();
$oneOffEvents = new EventService($oneOffRepo);
$oneOffEvents->createEvent($schedAdmin, new EventCreateCommand(
    title: 'Civic Holiday', startDate: '2026-08-03', allDay: true, pattern: 'one_off',
));
assert_true($oneOffRepo->recurrenceWritten === true,
    'a one-off still calls through, so any rule left from before is cleared');
assert_true($oneOffRepo->recurrence === null, 'but stores no rule, because a single date is not one');

// Monthly steps by month, not by a fixed number of days.
$monthRepo = new FakeEventRepository();
$monthEvents = new EventService($monthRepo);
$monthDetail = $monthEvents->createEvent($schedAdmin, new EventCreateCommand(
    title: 'Leaders meeting', startDate: '2026-01-31', startTime: '19:00',
    pattern: 'monthly', count: 3,
));
assert_true(($monthRepo->recurrence['recurrence_type'] ?? '') === 'monthly', 'monthly stores monthly');
assert_true($monthRepo->recurrence['recurrence_days_of_week'] === null,
    'monthly stores no weekday — it repeats on the date');

echo "Repairing a mis-entered series (EventService)\n";
// The mistake this exists for: a weekly service generated at 07:30 that meets
// at 19:00. Fifty-two occurrences are wrong, the dates are all correct, and
// before this the only remedy was deleting them one at a time.
$fixRepo = new FakeEventRepository();
$fixEvents = new EventService($fixRepo);
$fixAdmin = new ActorContext(
    actorId: 11, personId: 60, displayName: 'Admin',
    permissions: PortalPermission::forRole('admin'), ministryScopeIds: [],
    isPortalWideAdmin: true,
);
$seedSeries = static function () use ($fixRepo): void {
    $past = (new DateTimeImmutable('today'))->modify('-14 days');
    $soon = (new DateTimeImmutable('today'))->modify('+7 days');
    $later = (new DateTimeImmutable('today'))->modify('+14 days');
    $fixRepo->series = [];
    foreach ([[1, $past], [2, $soon], [3, $later]] as [$id, $day]) {
        $start = $day->setTime(7, 30);
        $fixRepo->series[] = [
            'occurrence_id' => $id,
            'occurrence_start' => $start->format('Y-m-d H:i:s'),
            'occurrence_end' => $start->modify('+90 minutes')->format('Y-m-d H:i:s'),
        ];
    }
};

$seedSeries();
$moved = $fixEvents->retimeEventOccurrences($fixAdmin, 1, '19:00', null, 'upcoming');
assert_true($moved === 2, 'retiming upcoming moves only the future occurrences (moved ' . $moved . ')');
assert_true(str_contains($fixRepo->series[0]['occurrence_start'], '07:30:00'),
    'a past occurrence is a record of what happened and is left alone');
assert_true(str_contains($fixRepo->series[1]['occurrence_start'], '19:00:00'),
    'an upcoming occurrence takes the corrected time');
assert_true(str_contains($fixRepo->series[1]['occurrence_end'], '20:30:00'),
    'its length is preserved when no new duration is given');
$dates = array_map(static fn (array $r): string => substr($r['occurrence_start'], 0, 10), $fixRepo->series);
$seedSeries();
$fixEvents->retimeEventOccurrences($fixAdmin, 1, '19:00', null, 'all');
assert_true($dates === array_map(static fn (array $r): string => substr($r['occurrence_start'], 0, 10), $fixRepo->series),
    'retiming never moves a date — only the time of day was mistyped');

$seedSeries();
$fixEvents->retimeEventOccurrences($fixAdmin, 1, '19:00', 60, 'all');
assert_true(str_contains($fixRepo->series[2]['occurrence_end'], '20:00:00'),
    'a supplied duration resizes the occurrence');

$seedSeries();
assert_throws(
    ValidationFailed::class,
    static fn () => $fixEvents->retimeEventOccurrences($fixAdmin, 1, '7:30 PM', null, 'all'),
    'a time that is not 24-hour HH:MM is refused rather than guessed at',
);
assert_throws(
    ValidationFailed::class,
    static fn () => $fixEvents->retimeEventOccurrences($fixAdmin, 1, '19:00', 0, 'all'),
    'a zero-length occurrence is refused',
);
assert_true(str_contains($fixRepo->series[1]['occurrence_start'], '07:30:00'),
    'a refused retime writes nothing');

// An event may hold only one occurrence per start time. Collapsing a series
// onto one time of day can create such a pair — the mistake that showed up the
// moment this was tried against a real event, where a placeholder occurrence
// shared a date with the generated series.
$seedSeries();
$fixRepo->series[] = [
    'occurrence_id' => 9,
    'occurrence_start' => (new DateTimeImmutable('today'))->modify('+7 days')->setTime(19, 0)->format('Y-m-d H:i:s'),
    'occurrence_end' => (new DateTimeImmutable('today'))->modify('+7 days')->setTime(20, 30)->format('Y-m-d H:i:s'),
];
assert_throws(
    ValidationFailed::class,
    static fn () => $fixEvents->retimeEventOccurrences($fixAdmin, 1, '19:00', null, 'upcoming'),
    'a retime that would put two occurrences on the same start is refused',
);
assert_true(str_contains($fixRepo->series[1]['occurrence_start'], '07:30:00'),
    'the refused retime is all-or-nothing — nothing was moved');
assert_throws(
    ValidationFailed::class,
    static fn () => $fixEvents->rescheduleOccurrence($fixAdmin, 3, new DateTimeImmutable(
        (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d') . ' 19:00:00'
    )),
    'moving one occurrence onto an existing start is refused too',
);

// Moving one occurrence, which is the single-mistake case.
$seedSeries();
$fixEvents->rescheduleOccurrence($fixAdmin, 2, new DateTimeImmutable(
    (new DateTimeImmutable('today'))->modify('+8 days')->format('Y-m-d') . ' 19:00:00'
));
assert_true(str_contains($fixRepo->series[1]['occurrence_start'], '19:00:00'),
    'one occurrence can be moved to a different day and time');
assert_true(str_contains($fixRepo->series[1]['occurrence_end'], '20:30:00'),
    'moving one occurrence keeps the length it already had');

// Bulk delete, and its assignment guard.
$seedSeries();
$deleted = $fixEvents->deleteEventOccurrences($fixAdmin, 1, 'upcoming');
assert_true($deleted === 2, 'deleting upcoming removes the future occurrences (deleted ' . $deleted . ')');
assert_true(count($fixRepo->series) === 1, 'the past occurrence survives a scoped delete');

$seedSeries();
$deleted = $fixEvents->deleteEventOccurrences($fixAdmin, 1, 'selected', [1, 3]);
assert_true($deleted === 2 && $fixRepo->series[0]['occurrence_id'] === 2,
    'a chosen set is deleted and nothing else is');

$seedSeries();
$fixRepo->batchAssignments = 4;
assert_throws(
    ValidationFailed::class,
    static fn () => $fixEvents->deleteEventOccurrences($fixAdmin, 1, 'all'),
    'occurrences carrying assignments are not deleted without confirmation',
);
assert_true(count($fixRepo->series) === 3, 'the refused bulk delete removed nothing');
assert_true($fixEvents->deleteEventOccurrences($fixAdmin, 1, 'all', [], true) === 3,
    'confirming deletes them');
$fixRepo->batchAssignments = 0;

// Authorization: the same two conditions the single-occurrence path applies.
$seedSeries();
$fixRepo->audience = 'leaders';
$repairScheduler = new ActorContext(
    actorId: 12, personId: 61, displayName: 'Scheduler',
    permissions: PortalPermission::forRole('scheduler'), ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $fixEvents->retimeEventOccurrences($repairScheduler, 1, '19:00', null, 'all'),
    'a scheduler cannot retime a series of an event they may not read',
);
assert_throws(
    PermissionDenied::class,
    static fn () => $fixEvents->deleteEventOccurrences($repairScheduler, 1, 'all'),
    'a scheduler cannot bulk-delete occurrences of an event they may not read',
);
assert_throws(
    PermissionDenied::class,
    static fn () => $fixEvents->rescheduleOccurrence($repairScheduler, 2, new DateTimeImmutable('2026-12-01 19:00:00')),
    'a scheduler cannot move an occurrence of an event they may not read',
);
assert_true(str_contains($fixRepo->series[1]['occurrence_start'], '07:30:00')
    && count($fixRepo->series) === 3, 'the refused writes changed nothing');
$fixRepo->audience = 'members';

$repairMember = new ActorContext(
    actorId: 13, personId: 62, displayName: 'Member',
    permissions: PortalPermission::forRole('member'), ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $fixEvents->retimeEventOccurrences($repairMember, 1, '19:00', null, 'all'),
    'a member without ManageEvents cannot retime a series',
);
assert_throws(
    PermissionDenied::class,
    static fn () => $fixEvents->deleteEventOccurrences($repairMember, 1, 'all'),
    'a member without CancelOccurrences cannot bulk-delete occurrences',
);

echo "Deleting an event filed by mistake (EventService)\n";
$delRepo = new FakeEventRepository();
$delEvents = new EventService($delRepo);
$delAdmin = new ActorContext(
    actorId: 14, personId: 63, displayName: 'Admin',
    permissions: PortalPermission::forRole('admin'), ministryScopeIds: [],
    isPortalWideAdmin: true,
);
$delRepo->series = [['occurrence_id' => 1, 'occurrence_start' => '2026-09-06 19:00:00', 'occurrence_end' => '2026-09-06 20:30:00']];
$delRepo->batchAssignments = 3;
assert_throws(
    ValidationFailed::class,
    static fn () => $delEvents->deleteEvent($delAdmin, 1),
    'an event whose occurrences carry assignments is not deleted without confirmation',
);
assert_true($delRepo->eventDeleted === false, 'the refused delete never reached the repository');
assert_true($delEvents->deleteEvent($delAdmin, 1, true) === true, 'confirming deletes it');

$delRepo2 = new FakeEventRepository();
$delEvents2 = new EventService($delRepo2);
$delRepo2->audience = 'leaders';
$delScheduler = new ActorContext(
    actorId: 15, personId: 64, displayName: 'Scheduler',
    permissions: PortalPermission::forRole('scheduler'), ministryScopeIds: [],
);
assert_true($delEvents2->deleteEvent($delScheduler, 1) === false,
    'an event the actor may not read reports missing, not forbidden — the id must not be probeable');
assert_true($delRepo2->eventDeleted === false, 'and it is not deleted');

$delMember = new ActorContext(
    actorId: 16, personId: 65, displayName: 'Member',
    permissions: PortalPermission::forRole('member'), ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $delEvents2->deleteEvent($delMember, 1),
    'a member without ManageEvents cannot delete events',
);

echo "Member cannot create events (EventService)\n";
$repo = new FakeEventRepository();
$events = new EventService($repo);
$member = new ActorContext(
    actorId: 5,
    personId: 42,
    displayName: 'Member',
    permissions: PortalPermission::forRole('member'),
    ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $events->createEvent($member, new EventCreateCommand(title: 'Should not persist', startDate: '2026-09-01', allDay: true)),
    'Member without ManageEvents cannot create events',
);
assert_true($repo->created === false, 'Rejected event create must not call EventRepository::createEvent');
assert_throws(
    PermissionDenied::class,
    static fn () => $events->cancelOccurrence($member, 99),
    'Member without CancelOccurrences cannot cancel occurrences',
);

$admin = new ActorContext(
    actorId: 1,
    personId: 1,
    displayName: 'Admin',
    permissions: PortalPermission::forRole('admin'),
    ministryScopeIds: [],
    isPortalWideAdmin: true,
);
$events->createEvent($admin, new EventCreateCommand(title: 'Allowed', startDate: '2026-09-01', allDay: true));
assert_true($repo->created === true, 'Portal-wide admin may create events');

echo "Campus scoping on event create\n";
$campusLeader = new ActorContext(
    actorId: 9,
    personId: 9,
    displayName: 'Campus leader',
    permissions: PortalPermission::forRole('leader'),
    ministryScopeIds: [],
    isPortalWideAdmin: false,
    campusScopeIds: [1],
);
$repo->created = false;
assert_throws(
    PermissionDenied::class,
    static fn () => $events->createEvent($campusLeader, new EventCreateCommand(title: 'Other campus', startDate: '2026-09-01', allDay: true, campusIds: [2])),
    'Leader scoped to campus 1 cannot create events for campus 2',
);
assert_true($repo->created === false, 'Out-of-scope event create must not persist');
$events->createEvent($campusLeader, new EventCreateCommand(title: 'Home campus', startDate: '2026-09-01', allDay: true, campusIds: [1]));
assert_true($repo->created === true, 'Leader may create events for an in-scope campus');

echo "Schedule writes require ManageSchedules + ministry scope\n";
$schedules = new ScheduleService(new FakeScheduleRepository());
$batch = new AssignmentBatchCommand(
    ministryId: 4,
    assignments: [
        new ScheduleAssignment(
            id: null,
            personId: 1,
            roleId: 1,
            startsOn: new DateTimeImmutable('2099-01-01'),
        ),
    ],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $schedules->saveAssignments($member, $batch),
    'Member cannot save schedule assignments',
);
$outOfMinistry = new ActorContext(
    actorId: 8,
    personId: 8,
    displayName: 'Leader',
    permissions: PortalPermission::forRole('leader'),
    ministryScopeIds: [4],
);
assert_throws(
    PermissionDenied::class,
    static fn () => $schedules->saveAssignments($outOfMinistry, new AssignmentBatchCommand(
        ministryId: 99,
        assignments: $batch->assignments,
    )),
    'Leader cannot save assignments for a ministry outside scope',
);

echo "Role maps\n";
$memberPerms = PortalPermission::forRole('member');
assert_true(!in_array(PortalPermission::ManageSchedules, $memberPerms, true), 'member role does not include ManageSchedules');
assert_true(!in_array(PortalPermission::ManageEvents, $memberPerms, true), 'member role does not include ManageEvents');
assert_true(in_array(PortalPermission::ViewOwnAssignments, $memberPerms, true), 'member role includes ViewOwnAssignments');

echo "Member workbook parser\n";
$parser = new \App\Services\MemberWorkbookParser();
$fixture = $root . '/tests/fixtures/members-hub-sample.csv';
$parsed = $parser->parseFile($fixture, 'Sheet1');
assert_true(count($parsed['rows']) === 3, 'sample CSV yields 3 member rows');
assert_true(($parsed['updated'] ?? '') !== '', 'sample CSV captures last-updated note');
$first = $parsed['rows'][0];
assert_true($first['last_name'] === 'Abalahon', 'official last name parsed');
assert_true($first['first_name'] === 'Vince Cedric', 'official first name parsed');
assert_true($first['email'] === 'vincedricabalahon@gmail.com', 'email normalized');
assert_true($first['member_type'] === 'Radical', 'member type Radical');
assert_true(($first['birthday']['iso'] ?? '') === '2005-04-26', 'Excel serial birthday converted');
assert_true($first['city'] === 'North York', 'address city parsed');
assert_true($first['zip'] === 'M2M 0B1', 'postal code collapsed');
$zach = $parsed['rows'][1];
assert_true(($zach['birthday']['iso'] ?? '') === '2019-01-19', 'misspelled January birthday parsed');
assert_true($zach['member_type'] === 'Gifts & Arrows', 'G&A member type normalized');

$xlsx = $root . '/tests/fixtures/members-hub-sample.xlsx';
$names = $parser->sheetNames($xlsx);
assert_true(in_array('Christlikeness Hub - NY', $names, true), 'sample workbook lists Hub NY sheet');
$hub = $parser->parseFile($xlsx, \App\Services\MemberWorkbookParser::DEFAULT_SHEET);
assert_true(count($hub['rows']) === 1, 'sample xlsx has one member row');
assert_true($hub['rows'][0]['last_name'] === 'Abalahon', 'sample xlsx first row is Abalahon');
assert_true(($hub['rows'][0]['birthday']['iso'] ?? '') === '2005-04-26', 'sample xlsx serial birthday converted');

$mergedXlsx = $root . '/tests/fixtures/members-merged-address.xlsx';
$household = $parser->parseFile($mergedXlsx, \App\Services\MemberWorkbookParser::DEFAULT_SHEET);
assert_true(count($household['rows']) === 3, 'merged-address fixture has three household members');
$addrs = array_map(static fn ($r) => (string) $r['address_raw'], $household['rows']);
assert_true($addrs[0] !== '' && $addrs[0] === $addrs[1] && $addrs[1] === $addrs[2], 'merged household address is copied onto every member');
assert_true($household['rows'][2]['email'] === 'justin.arceo@yahoo.com', 'unmerged email on a later household row is kept');
assert_true($parser->normalizePhone('4.168333616E9') === '416-833-3616', 'scientific-notation phone digits are recovered');

$mergedCsv = $root . '/tests/fixtures/members-merged-address.csv';
$csvHousehold = $parser->parseFile($mergedCsv, 'Sheet1');
assert_true(count($csvHousehold['rows']) === 3, 'CSV household has three rows');
assert_true(trim((string) $csvHousehold['rows'][0]['address_raw']) !== '', 'CSV first household row keeps the address');
assert_true(trim((string) $csvHousehold['rows'][1]['address_raw']) === '', 'CSV second row has no merge metadata so address stays blank');
assert_true(trim((string) $csvHousehold['rows'][2]['address_raw']) === '', 'CSV third row has no merge metadata so address stays blank');

$nameSheet = $parser->parseGrid([
    ['Name', 'Email'],
    ['Santos, Ana', 'ana@example.com'],
], 'Scarborough Members 2026');
assert_true($nameSheet['rows'][0]['last_name'] === 'Santos', 'Name header is accepted as the name column');

$junk = $parser->parseGrid([
    ['LAST NAME, FIRST NAME', 'Address'],
    ['71 Adults', ''],
    ['62 volunteers', ''],
    ['Santos, Ana', '10 King St, Toronto, ON M5V 1A1'],
], 'Hub');
assert_true(count($junk['rows']) === 1 && $junk['rows'][0]['last_name'] === 'Santos', 'summary Adult/volunteer rows are skipped');

$blankHousehold = $parser->parseGrid([
    ['LAST NAME, FIRST NAME', 'Address'],
    ['Castelvi, Jermaine', '131 Firgrove Crescent, Toronto, ON M3N 1K5'],
    ['Castelvi, Nathaniel', ''],
    ['Castelvi, Noah', ''],
], 'Sheet1');
assert_true(trim((string) $blankHousehold['rows'][1]['address_raw']) === '', 'Castelvi, Nathaniel keeps a genuinely blank address');
assert_true(trim((string) $blankHousehold['rows'][2]['address_raw']) === '', 'Castelvi, Noah keeps a genuinely blank address');

$liveHub = '/tmp/members.xlsx';
if (is_file($liveHub)) {
    $live = $parser->parseFile($liveHub, 'Christlikeness Hub - NY');
    $arceo = array_values(array_filter($live['rows'], static fn ($r) => str_starts_with(strtolower((string) $r['name_raw']), 'arceo,')));
    if ($arceo !== []) {
        $withAddr = array_filter($arceo, static fn ($r) => trim((string) $r['address_raw']) !== '');
        assert_true(count($withAddr) === count($arceo), 'live Hub NY Arceo household all receive the merged address');
    }
    $nats = array_values(array_filter(
        $live['rows'],
        static fn ($r) => str_starts_with(strtolower(trim((string) $r['name_raw'])), 'castelvi, nathaniel')
    ));
    $blankNats = array_filter($nats, static fn ($r) => trim((string) $r['address_raw']) === '');
    assert_true($blankNats !== [], 'unmerged Castelvi, Nathaniel row stays without an address');
}

echo "Member import planner\n";
$planner = new \App\Services\MemberImportPlanner($parser);
$plan = $planner->plan($parsed['rows'], [
    ['id' => 10, 'first_name' => 'Vince', 'last_name' => 'Abalahon', 'email' => 'vincedricabalahon@gmail.com', 'campus_id' => 3],
    ['id' => 11, 'first_name' => 'Old', 'last_name' => 'Member', 'email' => 'old@example.com', 'campus_id' => 3],
    ['id' => 12, 'first_name' => 'Other', 'last_name' => 'Campus', 'email' => 'other@example.com', 'campus_id' => 2],
], 3);
assert_true(count($plan['update']) === 1, 'email match updates existing person');
assert_true((int) $plan['update'][0]['person_id'] === 10, 'matched Abalahon id 10');
assert_true(count($plan['create']) === 2, 'unmatched file rows create');
assert_true(count($plan['remove']) === 1, 'campus leftover is removed from campus');
assert_true((int) $plan['remove'][0]['person_id'] === 11, 'old campus member scheduled for unlink');

echo "Member sheet merger (Hub latest + North York extras)\n";
$merger = new \App\Services\MemberSheetMerger($parser);
$hubRows = [
    [
        'name_raw' => 'Abalahon, Vince Cedric', 'last_name' => 'Abalahon', 'first_name' => 'Vince Cedric',
        'middle_name' => '', 'preferred_name' => 'Cedric', 'email' => 'vincedricabalahon@gmail.com',
        'phone' => '', 'address_raw' => '602-5740 Yonge St, North York, ON M2M 0B1',
        'address1' => '602-5740 Yonge St', 'city' => 'North York', 'state' => 'Ontario', 'zip' => 'M2M 0B1',
        'country' => 'CA', 'birthday' => ['year' => 2005, 'month' => 4, 'day' => 26, 'iso' => '2005-04-26'],
        'member_since' => ['iso' => '2023-12-01'], 'member_type' => 'Radical', 'ministry' => '', 'confirmed' => '0',
    ],
    [
        'name_raw' => 'Castelvi, Jermaine Jhay', 'last_name' => 'Castelvi', 'first_name' => 'Jermaine Jhay',
        'middle_name' => '', 'preferred_name' => '', 'email' => '',
        'phone' => '', 'address_raw' => '', 'address1' => '', 'city' => '', 'state' => 'Ontario', 'zip' => '',
        'country' => 'CA', 'birthday' => ['year' => null, 'month' => null, 'day' => null, 'iso' => null],
        'member_since' => ['iso' => null], 'member_type' => 'Trailblazer', 'ministry' => '', 'confirmed' => '0',
    ],
];
$nyRows = [
    [
        'name_raw' => 'Abalahon, Vince Cedric', 'last_name' => 'Abalahon', 'first_name' => 'Vince Cedric',
        'middle_name' => '', 'preferred_name' => '', 'email' => 'vincedricabalahon@gmail.com',
        'phone' => '437-604-2979', 'address_raw' => '5740 Yonge St, North York, ON M2M 0B1',
        'address1' => '5740 Yonge St', 'city' => 'North York', 'state' => 'Ontario', 'zip' => 'M2M 0B1',
        'country' => 'CA', 'birthday' => ['year' => 2005, 'month' => 4, 'day' => 26, 'iso' => '2005-04-26'],
        'member_since' => ['iso' => '2023-12-01'], 'member_type' => 'Radical', 'ministry' => 'Facilities, Victuals', 'confirmed' => '',
    ],
    [
        'name_raw' => 'Castelvi, Jermaine', 'last_name' => 'Castelvi', 'first_name' => 'Jermaine',
        'middle_name' => '', 'preferred_name' => '', 'email' => '',
        'phone' => '416-555-0199', 'address_raw' => '1 King St', 'address1' => '1 King St', 'city' => 'Toronto',
        'state' => 'Ontario', 'zip' => '', 'country' => 'CA',
        'birthday' => ['year' => 1990, 'month' => 1, 'day' => 2, 'iso' => '1990-01-02'],
        'member_since' => ['iso' => null], 'member_type' => 'Trailblazer', 'ministry' => 'Psalmist', 'confirmed' => '',
    ],
    [
        'name_raw' => 'Onlyny, Pat', 'last_name' => 'Onlyny', 'first_name' => 'Pat',
        'middle_name' => '', 'preferred_name' => '', 'email' => 'pat@example.com',
        'phone' => '', 'address_raw' => '', 'address1' => '', 'city' => '', 'state' => 'Ontario', 'zip' => '',
        'country' => 'CA', 'birthday' => ['year' => null, 'month' => null, 'day' => null, 'iso' => null],
        'member_since' => ['iso' => null], 'member_type' => 'Radical', 'ministry' => '', 'confirmed' => '',
    ],
];
$merged = $merger->merge($hubRows, $nyRows);
assert_true(count($merged) === 3, 'merged list keeps Hub people plus NY-only');
$aba = $merged[0];
assert_true($aba['preferred_name'] === 'Cedric', 'Hub preferred name is kept');
assert_true($aba['phone'] === '437-604-2979', 'blank Hub phone is filled from North York');
assert_true($aba['address1'] === '602-5740 Yonge St', 'Hub address wins over North York');
assert_true($aba['ministry'] === 'Facilities, Victuals', 'blank Hub ministry is filled from North York');
assert_true($aba['source'] === 'merged', 'filled extras mark the row as merged');
assert_true($aba['status'] === 'ready', 'Hub members start ready');
$cas = $merged[1];
assert_true($cas['first_name'] === 'Jermaine Jhay', 'Hub official first name wins');
assert_true($cas['phone'] === '416-555-0199', 'fuzzy last+first-token match fills NY extras');
$only = $merged[2];
assert_true($only['source'] === 'ny' && $only['status'] === 'draft', 'North York-only rows stay draft until confirmed');

echo "Member import deduper\n";
$deduper = new \App\Services\MemberImportDeduper($parser);
$duped = $deduper->dedupe([
    [
        'last_name' => 'Arceo', 'first_name' => 'Gabriel', 'name_raw' => 'Arceo, Gabriel',
        'email' => '', 'phone' => '', 'address_raw' => '801 Bathurst', 'address1' => '801 Bathurst',
        'member_type' => '', 'confirmed' => '', 'source' => 'hub', 'birth_month' => null, 'notes' => '',
    ],
    [
        'last_name' => 'Arceo', 'first_name' => 'Gabriel', 'name_raw' => 'Arceo, Gabriel',
        'email' => '', 'phone' => '', 'address_raw' => '', 'address1' => '',
        'member_type' => '', 'confirmed' => '', 'source' => 'hub', 'birth_month' => null, 'notes' => '',
    ],
    [
        'last_name' => 'Castelvi', 'first_name' => 'Nathaniel', 'name_raw' => 'Castelvi, Nathaniel',
        'email' => '', 'phone' => '', 'address_raw' => '131 Firgrove Crescent', 'address1' => '131 Firgrove',
        'member_type' => 'Gifts & Arrows', 'confirmed' => '', 'source' => 'hub', 'birth_month' => null, 'notes' => '',
    ],
    [
        'last_name' => 'Castelvi', 'first_name' => 'Nathaniel', 'name_raw' => 'Castelvi, Nathaniel',
        'email' => '', 'phone' => '', 'address_raw' => '', 'address1' => '',
        'member_type' => '', 'confirmed' => '', 'source' => 'hub', 'birth_month' => null, 'notes' => '',
    ],
]);
assert_true(count($duped['rows']) === 2, 'duplicate names collapse to one row each');
assert_true(count($duped['removed']) === 2, 'two extra worksheet copies are dropped');
assert_true($duped['rows'][0]['address_raw'] === '801 Bathurst', 'kept Arceo copy is the one with the address');
assert_true($duped['rows'][1]['address_raw'] === '131 Firgrove Crescent', 'kept Castelvi copy is the one with the address');
assert_true($duped['warnings'] !== [], 'dedupe emits a warning before any record update');

$sameEmail = $deduper->dedupe([
    [
        'last_name' => 'Santos', 'first_name' => 'Ana', 'name_raw' => 'Santos, Ana',
        'email' => 'ana@example.com', 'phone' => '', 'address_raw' => '',
        'source' => 'hub', 'birth_month' => null, 'notes' => '',
    ],
    [
        'last_name' => 'Santos', 'first_name' => 'A', 'name_raw' => 'Santos, A',
        'email' => 'ana@example.com', 'phone' => '416-555-0100', 'address_raw' => '',
        'source' => 'ny', 'birth_month' => null, 'notes' => '',
    ],
]);
assert_true(count($sameEmail['rows']) === 1, 'same email is treated as one person');
assert_true($sameEmail['rows'][0]['phone'] === '416-555-0100', 'dropped duplicate still fills blank fields on the kept row');

$wb = $parser->parseWorkbook($xlsx, \App\Services\MemberWorkbookParser::HUB_SHEET, '');
assert_true($wb['hub'] !== null && count($wb['hub']['rows']) === 1, 'parseWorkbook reads Hub from sample xlsx');
assert_true($wb['ny'] === null, 'sample xlsx has no secondary sheet when omitted');
assert_true($wb['warnings'] === [], 'omitting the secondary worksheet is not an error');
$wbNyMissing = $parser->parseWorkbook($xlsx, \App\Services\MemberWorkbookParser::HUB_SHEET, \App\Services\MemberWorkbookParser::NY_SHEET);
assert_true($wbNyMissing['warnings'] !== [], 'an explicit missing secondary sheet is reported');

echo "Member import payload overlay\n";
$keepPhone = \App\Services\MemberImportPayload::forSave(
    [
        'last_name' => 'Abalahon', 'first_name' => 'Vince Cedric', 'middle_name' => '',
        'email' => '', 'phone' => '', 'address1' => '', 'city' => '', 'state' => '', 'zip' => '',
        'country' => '', 'member_type' => '',
        'birthday' => ['month' => null, 'day' => null, 'year' => null],
        'member_since' => ['iso' => null],
    ],
    3,
    1,
    ['radical' => 2],
    10,
    [
        'per_ID' => 10, 'per_FirstName' => 'Vince', 'per_LastName' => 'Abalahon',
        'per_Email' => 'keep@example.com', 'per_CellPhone' => '416-555-0100',
        'per_Address1' => '1 King', 'per_City' => 'Toronto',
    ]
);
assert_true($keepPhone['per_Email'] === 'keep@example.com', 'blank Hub email does not wipe CRM email');
assert_true($keepPhone['per_CellPhone'] === '416-555-0100', 'blank Hub phone does not wipe CRM phone');
assert_true($keepPhone['per_Address1'] === '1 King', 'blank Hub address does not wipe CRM address');
assert_true($keepPhone['per_FirstName'] === 'Vince Cedric', 'Hub official first name still updates');
assert_true($keepPhone['primary_campus_id'] === 3, 'apply always sets the target campus');

$cli = $root . '/tools/member-import.php';
$json = shell_exec('php ' . escapeshellarg($cli) . ' --source=' . escapeshellarg($mergedXlsx) . ' --primary=' . escapeshellarg(\App\Services\MemberWorkbookParser::DEFAULT_SHEET) . ' --dump-json');
$cliOut = json_decode((string) $json, true);
assert_true(is_array($cliOut) && (int) ($cliOut['row_count'] ?? 0) === 3, 'CLI dump-json reads the merged-address workbook');
$cliAddrs = array_column($cliOut['rows'] ?? [], 'address_raw');
assert_true($cliAddrs !== [] && $cliAddrs[0] !== '' && $cliAddrs[0] === $cliAddrs[1] && $cliAddrs[1] === $cliAddrs[2], 'CLI dump-json copies merged household addresses');

if (is_file('/tmp/members.xlsx')) {
    $nyOnly = $parser->parseWorkbook('/tmp/members.xlsx', 'Christlikeness Hub - NY', '');
    assert_true(count($nyOnly['hub']['rows'] ?? []) === 184, 'live NY Hub primary-only yields 184 members');
    assert_true(($nyOnly['warnings'] ?? ['x']) === [], 'live NY Hub primary-only has no secondary warning');
    $liveDeduped = $deduper->dedupe($merger->merge($nyOnly['hub']['rows'] ?? [], []));
    $keys = [];
    foreach ($liveDeduped['rows'] as $r) {
        $keys[] = $deduper->identityKey($r);
    }
    assert_true(count($keys) === count(array_unique($keys)), 'live NY Hub has unique people after dedupe');
    assert_true($liveDeduped['removed'] !== [], 'live NY Hub duplicate worksheet rows are reported');
}
if (is_file('/tmp/scarborough.xlsx')) {
    $scOnly = $parser->parseWorkbook('/tmp/scarborough.xlsx', 'Christlikeness Hub-SC', '');
    $scRows = $scOnly['hub']['rows'] ?? [];
    $junkLeft = array_filter($scRows, static fn ($r) => preg_match('/adults|volunteers/i', (string) $r['name_raw']));
    assert_true($junkLeft === [], 'live SC Hub skips Adults/volunteers summary rows');
    assert_true(count($scRows) === 82, 'live SC Hub primary-only yields 82 members');
}

$archiveRoot = sys_get_temp_dir() . '/portal-private-' . bin2hex(random_bytes(4));
$store = new \App\Services\PrivateArchiveStore($archiveRoot, new DateTimeZone('America/Toronto'));
$at = new DateTimeImmutable('2026-08-25 14:22:33', new DateTimeZone('America/Toronto'));
$slot = $store->write('mysql', 'people', 'sql', "-- dump\n", $at);
assert_true($slot['relative'] === '2026/08/mysql.people.08_25.1422.sql', 'archive path is YYYY/mm/category.type.mm_dd.HHMM.ext');
assert_true(is_file($slot['absolute']) && (string) file_get_contents($slot['absolute']) === "-- dump\n", 'archive file is written under the private root');
assert_throws(\InvalidArgumentException::class, static fn () => $store->absolute('../etc/passwd'), 'archive download refuses parent-directory paths');

$xlsxBytes = (new \App\Services\MemberRosterXlsxWriter())->build([
    [
        'campus' => 'North York',
        'rows' => [[
            'last_name' => 'Doe',
            'first_name' => 'Jane',
            'email' => 'jane@example.com',
            'cell' => '416-555-0100',
            'address1' => '1 King St',
            'city' => 'Toronto',
            'bm' => 3,
            'bd' => 15,
            'by2' => 1990,
            'member_type' => 'Member',
        ]],
    ],
]);
$xlsxPath = $archiveRoot . '/sample.xlsx';
file_put_contents($xlsxPath, $xlsxBytes);
$zip = new ZipArchive();
assert_true($zip->open($xlsxPath) === true, 'exported workbook is a zip xlsx');
assert_true($zip->locateName('xl/styles.xml') !== false, 'xlsx includes styles.xml');
$sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
assert_true(str_contains($sheet, 'LAST NAME, FIRST NAME'), 'xlsx uses Hub-like headers');
assert_true(str_contains($sheet, 'Doe, Jane'), 'xlsx writes last, first name');
assert_true(str_contains($sheet, 'mergeCell ref="A1:K1"'), 'xlsx merges the title row');

$annFile = sys_get_temp_dir() . '/portal-announcements-' . bin2hex(random_bytes(4)) . '.json';
$ann = new \App\Services\AnnouncementSettingsService($annFile);
assert_true($ann->publishedNow() === [], 'empty announcements file publishes nothing');
$saved = $ann->save([
    'items' => [
        ['id' => 'live1', 'title' => 'Choir practice', 'body' => 'Thursday 7pm.', 'tag' => 'Ministry', 'published' => true, 'startsOn' => '2026-01-01', 'endsOn' => '2026-12-31'],
        ['id' => 'draft1', 'title' => 'Draft', 'body' => 'Hidden.', 'tag' => 'Church', 'published' => false, 'startsOn' => '', 'endsOn' => ''],
        ['id' => 'old1', 'title' => 'Expired', 'body' => 'Past.', 'tag' => 'Church', 'published' => true, 'startsOn' => '2020-01-01', 'endsOn' => '2020-02-01'],
        ['id' => 'future1', 'title' => 'Future', 'body' => 'Later.', 'tag' => 'Church', 'published' => true, 'startsOn' => '2099-01-01', 'endsOn' => ''],
    ],
]);
assert_true(count($saved['items']) === 4, 'announcement save keeps all authored items');
$live = $ann->publishedNow(new DateTimeImmutable('2026-08-25'));
assert_true(count($live) === 1 && $live[0]['id'] === 'live1', 'home feed hides drafts, expired, and future notes');
assert_throws(\App\Exceptions\ValidationFailed::class, static fn () => $ann->save([
    'items' => [['title' => 'No body', 'body' => '', 'tag' => 'Church', 'published' => true]],
]), 'announcement without a body is rejected');
@unlink($annFile);

echo "Public home composition\n";
$home = \App\Services\HomePageService::compose([
    'church' => ['name' => 'Christlikeness', 'address' => '4544 Dufferin St.', 'city' => 'NorthYork', 'state' => 'ON', 'zip' => 'L4K 5M5', 'phone' => '555'],
    'announcements' => [['id' => 'live1', 'title' => 'Choir', 'body' => 'Thursday', 'tag' => 'Ministry']],
    'events' => [
        ['event_id' => 9, 'title' => 'Sunday Service', 'occurrence_count' => 4, 'next_occurrence_at' => '2026-08-30 09:00:00'],
        'skip-me',
    ],
    'ministries' => [
        ['ministry_id' => 4, 'name' => 'Victuals', 'campus_id' => 1],
        ['ministryId' => 2, 'name' => 'Psalmist'],
        ['ministry_id' => 0, 'name' => 'Broken'],
    ],
    'hero' => ['slides' => [['kicker' => 'Welcome', 'title' => 'Hello', 'lead' => 'Come']]],
]);
assert_true($home['church']['name'] === 'Christlikeness', 'home church name is public identity');
assert_true(str_contains($home['addressLine'], '4544 Dufferin'), 'home formats a visit address');
assert_true(count($home['events']) === 1 && $home['events'][0]['event_id'] === 9, 'home keeps upcoming event rows and drops junk');
assert_true($home['ministries'][0]['name'] === 'Psalmist' && $home['ministries'][1]['name'] === 'Victuals', 'home ministries are sorted by name');
assert_true(count($home['announcements']) === 1, 'home announcements pass through');
$emptyHome = \App\Services\HomePageService::empty();
assert_true($emptyHome['church']['name'] === 'Church Portal' && $emptyHome['events'] === [] && $emptyHome['ministries'] === [], 'empty home still has a public church name');

$publicRepo = new FakeEventRepository();
$publicRepo->audience = 'public';
$publicRepo->upcomingRows = [[
    'event_id' => 3,
    'event_title' => 'Open house',
    'event_desc' => null,
    'occurrence_count' => 1,
    'next_occurrence_at' => null,
]];
$publicSvc = new EventService($publicRepo);
$publicEvents = $publicSvc->listUpcomingPublic(5);
assert_true(count($publicEvents) === 1 && $publicEvents[0]->eventId === 3, 'public event list does not require an actor');
assert_true($publicRepo->lastAudiences === ['public'], 'public list asks only for the public audience');
$publicRepo->audience = 'members';
assert_true($publicSvc->listUpcomingPublic(5) === [], 'public list hides member-only events');

echo "Hub ministry catalog\n";
$catalog = \App\Services\MinistryCatalog::fromFile($root . '/config/ministry-catalog.json');
assert_true(count($catalog->servingNames()) === 13, 'catalog has 13 unique serving ministries');
assert_true($catalog->parseHubCell('Facilities, Victuals, Psalmist, G&A') === ['Facilities', 'Victuals', 'Psalmist', 'Gifts and Arrows'], 'first Hub row uniquifies to four teams');
$gsBoth = $catalog->parseHubAssignments('GS: Usher and Emcee, Psalmist, G&A');
assert_true($gsBoth['ministries'] === ['Guest Services', 'Psalmist', 'Gifts and Arrows'], 'GS: prefix is Guest Services, not extra ministries');
assert_true(($gsBoth['roles']['Guest Services'] ?? []) === ['Usher', 'Emcee'], 'text after GS: is Usher and Emcee roles');
assert_true($catalog->parseHubCell('Events & Prayer Ministry') === ['Events', 'Prayer'], 'Events & Prayer is two ministries');
assert_true($catalog->parseHubCell('Gift and Arrows, Psalmists, Field Ministry, More than Enough, Guest Services') === ['Gifts and Arrows', 'Psalmist', 'Field', 'MTE', 'Guest Services'], 'portal sample names alias onto Hub names');
$usher = $catalog->parseHubAssignments('G&A, Usher, Victuals');
assert_true($usher['ministries'] === ['Gifts and Arrows', 'Guest Services', 'Victuals'], 'bare Usher is a Guest Services role');
assert_true(($usher['roles']['Guest Services'] ?? []) === ['Usher'], 'bare Usher maps to the Usher role');
assert_true($catalog->canonicalize('Dance') === 'Dance Ministry', 'Dance aliases to Dance Ministry');
assert_true($catalog->canonicalize('GS') === 'Guest Services', 'GS expands to Guest Services');
assert_true($catalog->parseHubCell('Banana, Facilities') === ['Facilities'], 'unknown Hub tags are dropped');

// The suites in this directory that run as their own scripts. They were
// reachable only by being named on the command line, which meant nothing
// noticed when they broke. Folding their exit codes in here makes them part of
// "the tests pass" rather than something to remember.
foreach (glob(__DIR__ . '/*.php') ?: [] as $suite) {
    if (basename($suite) === basename(__FILE__)) {
        continue;
    }
    echo "\n" . basename($suite, '.php') . "\n";
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($suite) . ' 2>&1', $output, $status);
    $summary = trim((string) end($output));
    if ($status === 0) {
        $passed++;
        echo "  ok  {$summary}\n";
    } else {
        $failures++;
        echo "  FAIL  {$summary}\n";
        foreach ($output as $line) {
            if (str_contains($line, 'FAIL') || str_contains($line, 'Fatal')) {
                echo "        {$line}\n";
            }
        }
    }
}

echo "\nPassed: {$passed}; failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
