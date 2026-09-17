#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The Ministries workspace: the directory, a ministry's overview, positions,
 * and who is offered what.
 *
 * What matters here is that the redesign widened nothing: leader names stay
 * with the people who could already read a ministry's members, inactive
 * ministries stay with administrators, and position editing is held to the
 * membership editor's rule. Nothing touches a database.
 */

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Contracts\MinistryRepository;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use App\Services\Ministry\ServingDates;
use App\Services\MinistryService;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}
function throws(string $class, callable $fn, string $label): void
{
    try {
        $fn();
        check($label, false, 'no exception');
    } catch (Throwable $e) {
        check($label, $e instanceof $class, $e::class . ': ' . $e->getMessage());
    }
}

final class FakeWorkspaceMinistries implements MinistryRepository
{
    /** @var list<array{0:int,1:int,2:list<string>}> */
    public array $positionsSaved = [];

    public function listMinistriesAdmin(?int $campusId = null): array
    {
        return [
            ['ministry_id' => 4, 'name' => 'Victuals', 'campus_id' => null, 'active' => true, 'member_count' => $campusId === null ? 50 : 20, 'leader_count' => 2, 'role_count' => 3],
            ['ministry_id' => 1, 'name' => 'Psalmist', 'campus_id' => 2, 'active' => true, 'member_count' => 41, 'leader_count' => 3, 'role_count' => 0],
            ['ministry_id' => 14, 'name' => 'Bishops', 'campus_id' => null, 'active' => false, 'member_count' => 0, 'leader_count' => 0, 'role_count' => 0],
        ];
    }

    public function fetchDashboard(array $ministryIds, DateTimeImmutable $since, DateTimeImmutable $upcomingStart, DateTimeImmutable $until): array
    {
        $rows = [[
            'ministry_id' => 4, 'name' => 'Victuals', 'campus_id' => null,
            'upcoming_occurrences' => [
                ['occurrence_id' => 9, 'event_title' => 'Sunday Service', 'starts_on' => new DateTimeImmutable('2026-09-20 08:00'), 'ends_on' => new DateTimeImmutable('2026-09-20 10:00'), 'assignment_count' => 5],
                ['occurrence_id' => 10, 'event_title' => 'Sunday Service', 'starts_on' => new DateTimeImmutable('2026-09-27 08:00'), 'ends_on' => new DateTimeImmutable('2026-09-27 10:00'), 'assignment_count' => 4],
            ],
        ]];

        return $ministryIds === [] ? $rows : array_values(array_filter($rows, static fn (array $r): bool => in_array($r['ministry_id'], $ministryIds, true)));
    }

    public function listLeadersByMinistry(?int $campusId = null): array
    {
        return [
            ['ministry_id' => 4, 'person_id' => 70, 'display_name' => 'Anne Leader'],
            ['ministry_id' => 1, 'person_id' => 71, 'display_name' => 'Psalm Leader'],
        ];
    }

    public function listMinistryIdsForPeople(array $personIds): array
    {
        return in_array(953, $personIds, true) ? [953 => [4]] : [];
    }

    public function listMinistryMembers(int $ministryId, ?int $campusId = null): array
    {
        return [
            ['person_id' => 70, 'display_name' => 'Anne Leader', 'role' => 'leader', 'role_name' => 'Leader', 'is_leader' => true, 'positions' => ['Usher']],
            ['person_id' => 953, 'display_name' => 'Gemma Member', 'role' => 'member', 'role_name' => 'Member', 'is_leader' => false, 'positions' => ['Usher', 'Emcee']],
        ];
    }

    public function fetchMinistryRoles(int $ministryId): array
    {
        return [['id' => 5, 'name' => 'Porter', 'sort_order' => 1, 'is_active' => true, 'assigned_count' => 7, 'assigned_members' => []]];
    }

    public function setMemberPositions(int $personId, int $ministryId, array $positions): bool
    {
        $this->positionsSaved[] = [$personId, $ministryId, $positions];

        return $personId !== 1;
    }

    public function listMinistries(array $ministryIds = []): array { return []; }
    public function listCampuses(): array { return []; }
    public function findPrimaryCampusIdForPerson(int $personId): ?int { return null; }
    public function findDisplayNameForPerson(int $personId): ?string { return null; }
    public function findMinistry(int $ministryId): ?array { return null; }
    public function resolveScheduleRoute(string $campusSlug, string $ministrySlug): ?array { return null; }
    public function resolveScheduleRouteByMinistry(string $ministrySlug, array $campusIds = []): ?array { return null; }
    public function resolveCampuses(array $campusSlugs): array { return []; }
    public function fetchRoster(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array { return []; }
    public function fetchPeopleDirectory(DateTimeImmutable $since, ?int $campusId = null, ?int $personId = null): array { return []; }
    public function fetchMinistryLeaders(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array { return []; }
    public function createMinistryRole(int $ministryId, array $data): array { return []; }
    public function updateMinistryRole(int $roleId, array $data): array { return []; }
    public function findMinistryIdForRole(int $roleId): ?int { return [5 => 4, 6 => 1][$roleId] ?? null; }
    public function deleteMinistryRole(int $roleId): bool { return true; }
    public function assignPersonToRole(int $personId, int $roleId): bool { return true; }
    public function removePersonFromRole(int $personId, int $roleId): bool { return true; }
    public function createMinistry(array $data): array { return []; }
    public function updateMinistry(int $ministryId, array $data): array { return []; }
    public function setMinistryActive(int $ministryId, bool $active): bool { return true; }
    public function deleteMinistry(int $ministryId): bool { return true; }
    public function setMemberRole(int $personId, int $ministryId, string $role): bool { return true; }
    public function removeMemberFromMinistry(int $personId, int $ministryId): bool { return true; }
    public function addMinistryLeader(int $ministryId, int $personId): bool { return true; }
    public function removeMinistryLeader(int $ministryId, int $personId): bool { return true; }
    public function listGroupRoles(int $ministryId): array { return []; }
}

function actorFor(string $role, array $scope = [], ?int $personId = null, bool $admin = false, array $campuses = []): ActorContext
{
    return new ActorContext(
        actorId: 1,
        personId: $personId,
        displayName: $role,
        permissions: PortalPermission::forRole($role),
        ministryScopeIds: $scope,
        isPortalWideAdmin: $admin,
        campusScopeIds: $campuses,
    );
}

$repo = new FakeWorkspaceMinistries();
$service = new MinistryService($repo);
$today = new DateTimeImmutable('2026-09-17');

$member = actorFor('member', [], 953);
$leader4 = actorFor('leader', [4], 70);
$scheduler4 = actorFor('scheduler', [4]);
$admin = actorFor('admin', [], null, true);

echo "Who is offered what in a ministry\n";
$a = $service->workspaceAccess($member, 4);
check('a member sees no people and manages nothing', !$a['canViewPeople'] && !$a['canManageMembers'] && !$a['canManageRoles'] && !$a['canEditSchedule'] && !$a['canPrintSchedule']);
$a = $service->workspaceAccess($leader4, 4);
check('a leader of the ministry sees and manages it', $a['canViewPeople'] && $a['canManageMembers'] && $a['canManageRoles'] && $a['canEditSchedule']);
check('but does not manage ministries themselves', !$a['canManageMinistries']);
$a = $service->workspaceAccess($leader4, 1);
check('a leader of another ministry neither sees its people nor manages it', !$a['canViewPeople'] && !$a['canManageMembers'] && !$a['canEditSchedule']);
$a = $service->workspaceAccess($scheduler4, 4);
check('a scheduler edits the schedule but is not offered member management', $a['canEditSchedule'] && $a['canViewPeople'] && !$a['canManageMembers']);
$a = $service->workspaceAccess($admin, 1);
check('an administrator is offered everything', !in_array(false, $a, true));

echo "Directory\n";
$dir = $service->listDirectory($member, null, $today);
$byId = array_column($dir, null, 'ministryId');
check('lists active ministries by name', array_column($dir, 'name') === ['Psalmist', 'Victuals']);
check('an inactive ministry is not listed for a member', !isset($byId[14]));
check('leader names are withheld from a member', $byId[4]['leaders'] === null && $byId[1]['leaders'] === null);
check('counts and scheduling are shown to everyone signed in', $byId[4]['memberCount'] === 50 && $byId[4]['schedules'] && !$byId[1]['schedules']);
check('the next serving date comes from the dashboard', $byId[4]['nextDate']?->format('Y-m-d') === '2026-09-20' && $byId[4]['upcomingCount'] === 2 && $byId[4]['nextTitle'] === 'Sunday Service');
check('a member\'s own ministries are marked', $byId[4]['isMine'] && !$byId[1]['isMine']);
$byId = array_column($service->listDirectory($leader4, null, $today), null, 'ministryId');
check('a leader sees leader names for their ministry only', $byId[4]['leaders'] === ['Anne Leader'] && $byId[1]['leaders'] === null);
check('and the ministries they lead are marked', $byId[4]['leadsIt'] && $byId[4]['isMine']);
$all = array_column($service->listDirectory($admin, null, $today), null, 'ministryId');
check('an administrator also sees inactive ministries', isset($all[14]) && $all[14]['active'] === false);
check('a campus narrows counts', array_column($service->listDirectory($admin, 2, $today), null, 'ministryId')[4]['memberCount'] === 20);
throws(PermissionDenied::class, static fn () => $service->listDirectory(actorFor('member', [], null, false, [1]), 2, $today), 'a campus outside the actor\'s scope is refused');

echo "Overview\n";
check('no ministry, no overview', $service->getWorkspaceOverview($member, 999, null, $today) === null);
check('an inactive ministry is not found for a member', $service->getWorkspaceOverview($member, 14, null, $today) === null);
check('but is for an administrator', ($service->getWorkspaceOverview($admin, 14, null, $today)['active'] ?? null) === false);
$o = $service->getWorkspaceOverview($member, 4, null, $today);
check('a member gets counts and upcoming dates', $o['memberCount'] === 50 && count($o['upcoming']) === 2);
check('but no leaders, positions or roles', $o['leaders'] === null && $o['positions'] === null && $o['roles'] === null);
$o = $service->getWorkspaceOverview($leader4, 4, null, $today);
check('a leader gets leaders by name', $o['leaders'] === [['personId' => 70, 'name' => 'Anne Leader']]);
check('positions are summarised with how many hold each', $o['positions'] === ['Emcee' => 1, 'Usher' => 2]);
check('serving roles carry how many have served', $o['roles'][0]['name'] === 'Porter' && $o['roles'][0]['assignedCount'] === 7);

echo "Positions\n";
throws(PermissionDenied::class, static fn () => $service->setMemberPositions($member, 4, 953, ['Usher']), 'a member cannot set positions');
throws(PermissionDenied::class, static fn () => $service->setMemberPositions($leader4, 1, 953, ['Usher']), 'a leader cannot set positions in another ministry');
throws(PermissionDenied::class, static fn () => $service->setMemberPositions($scheduler4, 4, 953, ['Usher']), 'a scheduler without ministry-role management cannot set positions');
$repo->positionsSaved = [];
check('a leader sets positions, trimmed and without blanks', $service->setMemberPositions($leader4, 4, 953, [' Usher ', '', 'Emcee']) === true
    && $repo->positionsSaved === [[953, 4, ['Usher', 'Emcee']]]);
check('an empty list clears them', $service->setMemberPositions($leader4, 4, 953, []) === true);
check('someone who is not a member is reported, not added', $service->setMemberPositions($leader4, 4, 1, ['Usher']) === false);
throws(ValidationFailed::class, static fn () => $service->setMemberPositions($leader4, 4, 953, [['nested']]), 'a position must be a name');
throws(ValidationFailed::class, static fn () => $service->setMemberPositions($leader4, 4, 953, [str_repeat('x', 61)]), 'a position name is at most 60 characters');
throws(ValidationFailed::class, static fn () => $service->setMemberPositions($leader4, 4, 953, array_fill(0, 21, 'x')), 'at most 20 positions');

echo "Role-by-id writes are held to the role's ministry\n";
// Role 5 belongs to Victuals (4), role 6 to Psalmist (1).
throws(PermissionDenied::class, static fn () => $service->updateRole($leader4, 6, ['name' => 'X']), 'a leader of A cannot rename a role of B');
throws(PermissionDenied::class, static fn () => $service->deleteRole($leader4, 6), 'a leader of A cannot delete a role of B');
throws(PermissionDenied::class, static fn () => $service->assignPersonToRole($leader4, 953, 6), 'a leader of A cannot assign into a role of B');
throws(PermissionDenied::class, static fn () => $service->removePersonFromRole($leader4, 953, 6), 'a leader of A cannot unassign from a role of B');
check('a leader of A still deletes a role of A', $service->deleteRole($leader4, 5) === true);
check('and assigns and unassigns in it', $service->assignPersonToRole($leader4, 953, 5) && $service->removePersonFromRole($leader4, 953, 5));
check('an administrator may delete a role of any ministry', $service->deleteRole($admin, 6) === true);
throws(ValidationFailed::class, static fn () => $service->deleteRole($admin, 999), 'an unknown role is refused, not silently ignored');
throws(PermissionDenied::class, static fn () => $service->deleteRole($member, 5), 'a member still cannot delete roles');

echo "Old addresses\n";
check('a name finds its ministry however it is written', $service->findMinistryIdBySlug('victuals') === 4 && $service->findMinistryIdBySlug('VICTUALS') === 4);
check('an unknown or empty name finds nothing', $service->findMinistryIdBySlug('nope') === null && $service->findMinistryIdBySlug('--') === null);

echo "Serving dates\n";
$dates = ServingDates::fromGrid([
    'roles' => [['id' => 1, 'name' => 'Porter'], ['id' => 2, 'name' => 'Server'], ['id' => 3, 'name' => 'Set-Up']],
    'occurrences' => [
        ['id' => 20, 'eventTitle' => 'Evening', 'startsOn' => '2026-09-27T18:00:00-04:00'],
        ['id' => 10, 'eventTitle' => 'Sunday Service', 'startsOn' => '2026-09-20T08:00:00-04:00'],
    ],
    'assignments' => [
        ['occurrenceId' => 10, 'roleId' => 2, 'displayName' => 'Bea'],
        ['occurrenceId' => 10, 'roleId' => 1, 'displayName' => 'Al'],
        ['occurrenceId' => 10, 'roleId' => 1, 'displayName' => 'Al'],
        ['occurrenceId' => 10, 'roleId' => 1, 'displayName' => '', 'label' => 'Guest: Cy'],
        ['occurrenceId' => 99, 'roleId' => 1, 'displayName' => 'Nobody'],
    ],
]);
check('dates come in date order', array_column($dates, 'occurrenceId') === [10, 20]);
check('roles follow the grid order with their people, once each', $dates[0]['roles'] === [['role' => 'Porter', 'people' => ['Al', 'Guest: Cy']], ['role' => 'Server', 'people' => ['Bea']]]);
check('unfilled roles are named', $dates[0]['unfilled'] === ['Set-Up'] && $dates[1]['roles'] === [] && count($dates[1]['unfilled']) === 3);
check('an assignment to an unknown date is ignored', !str_contains(json_encode($dates), 'Nobody'));
check('an empty grid is no dates', ServingDates::fromGrid([]) === []);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
