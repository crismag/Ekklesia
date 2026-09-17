<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../../app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Contracts\AvailabilityRepository;
use App\Contracts\MinistryRepository;
use App\Contracts\ScheduleRepository;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\DTO\Availability\AvailabilityCommand;
use App\DTO\Schedules\AssignmentBatchCommand;
use App\DTO\Schedules\ScheduleAssignment;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use App\Services\AvailabilityService;
use App\Services\MinistryService;
use App\Services\ScheduleService;

final class FakeScheduleRepository implements ScheduleRepository
{
    public array $savedCommands = [];
    public array $lastGridCampusIds = [];
    /** @var list<int>|null */
    public ?array $lastGridEventIds = null;
    /** @var list<int> */
    public array $lastEligibleCampusIds = [];

    /** @var list<array{id:int,title:string,is_default:bool}> */
    public array $eligibleEvents = [
        ['id' => 4, 'title' => 'Sunday Service', 'is_default' => true],
    ];

    /** @var list<int> */
    public array $lastBoardCampusIds = [];
    /** @var list<int> */
    public array $lastBoardMinistryIds = [];

    public function fetchScheduleGrid(int $ministryId, DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], ?array $eventIds = null): array
    {
        $this->lastGridCampusIds = $campusIds;
        $this->lastGridEventIds = $eventIds;

        $occurrences = [
            [
                'id'          => 501,
                'event_id'    => 4,
                'event_title' => 'Sunday Service',
                'starts_on'   => new DateTimeImmutable('2026-05-03 09:00'),
                'ends_on'     => new DateTimeImmutable('2026-05-03 11:00'),
            ],
        ];
        if ($eventIds !== null) {
            $wanted = array_fill_keys($eventIds, true);
            $occurrences = array_values(array_filter(
                $occurrences,
                static fn (array $o): bool => isset($wanted[(int) $o['event_id']]),
            ));
        }

        return [
            'ministry_id' => $ministryId,
            'start' => $start,
            'end' => $end,
            'roles' => [
                ['id' => 7, 'name' => 'Usher'],
            ],
            'people' => [
                ['id' => 42, 'display_name' => 'A Person'],
            ],
            'special_candidates' => [
                ['id' => 77, 'display_name' => 'Pool Helper'],
            ],
            'occurrences' => $occurrences,
            'assignments' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param list<int> $campusIds
     * @return list<array{id:int,title:string,is_default:bool}>
     */
    public function listEligibleSchedulingEvents(array $campusIds = []): array
    {
        $this->lastEligibleCampusIds = $campusIds;

        return $this->eligibleEvents;
    }

    /** The picker's source: everything in the window, not only the flagged set. */
    public function listSchedulableEventsInRange(array $campusIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->listEligibleSchedulingEvents($campusIds);
    }

    /**
     * @param list<int> $campusIds
     * @return list<int>
     */
    public function listDefaultSchedulingEventIds(array $campusIds = []): array
    {
        $ids = [];
        foreach ($this->eligibleEvents as $event) {
            if (!empty($event['is_default'])) {
                $ids[] = (int) $event['id'];
            }
        }

        return $ids;
    }

    /**
     * Added to satisfy ScheduleRepository::fetchScheduleBoard, which the fake
     * had drifted behind. Mirrors the real row shape so boundary assertions
     * stay meaningful; campus/ministry filters are recorded for inspection.
     */
    public function fetchScheduleBoard(DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], array $ministryIds = []): array
    {
        $this->lastBoardCampusIds = $campusIds;
        $this->lastBoardMinistryIds = $ministryIds;

        return [
            'start' => $start,
            'end' => $end,
            'ministries' => [],
            'occurrences' => [],
            'assignments' => [],
            'warnings' => [],
        ];
    }

    public function fetchMySchedule(int $personId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [
            'person_id' => $personId,
            'start' => $start,
            'end' => $end,
            'assignments' => [
                [
                    'id' => 101,
                    'person_id' => $personId,
                    'serving_role_id' => 7,
                    'role_name' => 'Usher',
                    'ministry_id' => 10,
                    'ministry_name' => 'Hospitality',
                    'event_id' => 4,
                    'event_title' => 'Sunday Service',
                    'starts_on' => new DateTimeImmutable('2026-05-03 09:00'),
                    'ends_on' => new DateTimeImmutable('2026-05-03 11:00'),
                    'status' => 'assigned',
                ],
            ],
        ];
    }

    public function saveAssignments(AssignmentBatchCommand $command): array
    {
        $this->savedCommands[] = $command;

        return [
            'saved' => true,
            'assignment_count' => count($command->assignments),
        ];
    }
}

final class FakeAvailabilityRepository implements AvailabilityRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $nextId = 100;

    public function listForPerson(int $personId, ?DateTimeImmutable $activeFrom = null): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['person_id'] !== $personId) continue;
            if ($activeFrom !== null && $row['ends_on'] < $activeFrom) continue;
            $out[] = $row;
        }
        return $out;
    }

    public function findById(int $unavailabilityId): ?array
    {
        return $this->rows[$unavailabilityId] ?? null;
    }

    public function create(AvailabilityCommand $command, int $createdByAccountId, DateTimeImmutable $now): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'person_id' => $command->personId,
            'starts_on' => $command->startsOn,
            'ends_on'   => $command->endsOn,
            'reason'    => $command->reason,
            'created_by_account_id' => $createdByAccountId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        return $id;
    }

    public function delete(int $unavailabilityId): bool
    {
        if (!isset($this->rows[$unavailabilityId])) return false;
        unset($this->rows[$unavailabilityId]);
        return true;
    }
}

final class FakeMinistryRepository implements MinistryRepository
{
    public function listMinistries(array $ministryIds = []): array
    {
        $rows = [
            ['ministry_id' => 10, 'name' => 'Hospitality', 'campus_id' => 2],
            ['ministry_id' => 11, 'name' => 'Care Team', 'campus_id' => null],
        ];
        if ($ministryIds === []) {
            return $rows;
        }
        $allowed = array_map('intval', $ministryIds);
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array((int) $row['ministry_id'], $allowed, true),
        ));
    }

    public function fetchPeopleDirectory(
        DateTimeImmutable $since,
        ?int $campusId = null,
        ?int $personId = null,
    ): array {
        $rows = [
            [
                'person_id' => 42,
                'display_name' => 'A Person',
                'primary_campus_id' => $campusId ?? 2,
                'assignment_count' => 3,
                'last_served_at' => new DateTimeImmutable('2026-04-26 13:00'),
                'roles_served' => 'Usher, Greeter',
                'ministry_count' => 2,
                'ministries' => 'Care Team, Hospitality',
            ],
            [
                'person_id' => 43,
                'display_name' => 'B Person',
                'primary_campus_id' => null,
                'assignment_count' => 0,
                'last_served_at' => null,
                'roles_served' => '',
                'ministry_count' => 0,
                'ministries' => '',
            ],
        ];

        if ($personId !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) $row['person_id'] === $personId,
            ));
        }

        return $rows;
    }

    public function listCampuses(): array
    {
        return [
            ['campus_id' => 2, 'campus_name' => 'Scarborough'],
            ['campus_id' => 3, 'campus_name' => 'North York'],
        ];
    }

    public function findPrimaryCampusIdForPerson(int $personId): ?int
    {
        return $personId === 700 ? 3 : null;
    }

    public function findDisplayNameForPerson(int $personId): ?string
    {
        return match ($personId) {
            42 => 'A Person',
            43 => 'B Person',
            700 => 'Grace Hopper',
            default => null,
        };
    }

    public function findMinistry(int $ministryId): ?array
    {
        if ($ministryId === 10) {
            return ['ministry_id' => 10, 'name' => 'Hospitality', 'campus_id' => 2];
        }
        return null;
    }

    public function resolveScheduleRoute(string $campusSlug, string $ministrySlug): ?array
    {
        if ($campusSlug === 'scarborough' && $ministrySlug === 'hospitality') {
            return [
                'campus_id' => 2,
                'campus_name' => 'Scarborough',
                'ministry_id' => 10,
                'name' => 'Hospitality',
            ];
        }

        return null;
    }

    public function resolveScheduleRouteByMinistry(string $ministrySlug, array $campusIds = []): ?array
    {
        if ($ministrySlug !== 'hospitality') {
            return null;
        }

        return [
            'ministry_id' => 10,
            'name' => 'Hospitality',
            'campus_id' => $campusIds[0] ?? null,
        ];
    }

    public function resolveCampuses(array $campusSlugs): array
    {
        $map = [
            'scarborough' => ['campus_id' => 2, 'campus_name' => 'Scarborough'],
            'northyork' => ['campus_id' => 3, 'campus_name' => 'North York'],
        ];

        $resolved = [];
        foreach ($campusSlugs as $campusSlug) {
            if (isset($map[$campusSlug])) {
                $resolved[] = $map[$campusSlug];
            }
        }

        return $resolved;
    }

    public function fetchDashboard(
        array $ministryIds,
        DateTimeImmutable $since,
        DateTimeImmutable $upcomingStart,
        DateTimeImmutable $until,
    ): array {
        $rows = [
            [
                'ministry_id' => 10,
                'name' => 'Hospitality',
                'campus_id' => 2,
                'past_assignment_count' => 4,
                'upcoming_assignment_count' => 3,
                'next_occurrence_at' => new DateTimeImmutable('2026-05-03 09:00'),
                'last_occurrence_at' => new DateTimeImmutable('2026-04-26 09:00'),
                'upcoming_occurrences' => [
                    [
                        'occurrence_id' => 501,
                        'event_title' => 'Sunday Service',
                        'starts_on' => new DateTimeImmutable('2026-05-03 09:00'),
                        'ends_on' => new DateTimeImmutable('2026-05-03 11:00'),
                        'assignment_count' => 3,
                    ],
                ],
            ],
            [
                'ministry_id' => 11,
                'name' => 'Care Team',
                'campus_id' => null,
                'past_assignment_count' => 1,
                'upcoming_assignment_count' => 0,
                'next_occurrence_at' => null,
                'last_occurrence_at' => new DateTimeImmutable('2026-04-20 18:00'),
                'upcoming_occurrences' => [],
            ],
        ];

        if ($ministryIds === []) {
            return $rows;
        }

        $allowed = array_map('intval', $ministryIds);
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array((int) $row['ministry_id'], $allowed, true),
        ));
    }

    public function fetchRoster(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array
    {
        return [
            'ministry_id'   => $ministryId,
            'ministry_name' => 'Hospitality',
            'campus_id'     => 2,
            'since'         => $since,
            'members'       => [
                [
                    'person_id'         => 42,
                    'display_name'      => 'A Person',
                    'primary_campus_id' => $campusId ?? 2,
                    'assignment_count'  => 3,
                    'last_served_at'    => new DateTimeImmutable('2026-04-26 13:00'),
                    'roles_served'      => 'Usher, Greeter',
                ],
                [
                    'person_id'         => 43,
                    'display_name'      => 'B Person',
                    'primary_campus_id' => null,
                    'assignment_count'  => 0,
                    'last_served_at'    => null,
                    'roles_served'      => '',
                ],
            ],
        ];
    }
    public function fetchMinistryRoles(int $ministryId): array
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return [];
    }
    public function fetchMinistryLeaders(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return [];
    }
    public function createMinistryRole(int $ministryId, array $data): array
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return [];
    }
    public function updateMinistryRole(int $roleId, array $data): array
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return [];
    }
    public function deleteMinistryRole(int $roleId): bool
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return true;
    }
    public function assignPersonToRole(int $personId, int $roleId): bool
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return true;
    }
    public function removePersonFromRole(int $personId, int $roleId): bool
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return true;
    }
    public function listMinistriesAdmin(?int $campusId = null): array
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return [];
    }
    public function createMinistry(array $data): array
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return [];
    }
    public function updateMinistry(int $ministryId, array $data): array
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return [];
    }
    public function setMinistryActive(int $ministryId, bool $active): bool
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return true;
    }
    public function deleteMinistry(int $ministryId): bool
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return true;
    }
    public function listMinistryMembers(int $ministryId, ?int $campusId = null): array
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return [];
    }
    public function listMinistryIdsForPeople(array $personIds): array
    {
        // Contract stub: these suites exercise service rules, not repository
        // behaviour. An empty map means "no current memberships".
        return [];
    }

    public function setMemberRole(int $personId, int $ministryId, string $role): bool
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return true;
    }
    public function setMemberPositions(int $personId, int $ministryId, array $positions): bool
    {
        // Contract stub, as above.
        return true;
    }
    public function removeMemberFromMinistry(int $personId, int $ministryId): bool
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return true;
    }
    public function addMinistryLeader(int $ministryId, int $personId): bool
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return true;
    }
    public function removeMinistryLeader(int $ministryId, int $personId): bool
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return true;
    }
    public function listLeadersByMinistry(?int $campusId = null): array
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return [];
    }
    public function listGroupRoles(int $ministryId): array
    {
        // Contract stub: the boundary tests exercise service-layer rules, not
        // repository behaviour. Returning an inert value keeps the fake
        // satisfying the interface without asserting fake data as truth.
        return [];
    }

}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_throws(string $expected, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $expected) {
            return;
        }

        throw new RuntimeException($message . ' Got ' . $exception::class);
    }

    throw new RuntimeException($message . ' No exception was thrown.');
}

$serviceReflection = new ReflectionClass(ScheduleService::class);
$constructorParameter = $serviceReflection->getConstructor()->getParameters()[0] ?? null;
assert_true(
    $constructorParameter?->getType()?->__toString() === ScheduleRepository::class,
    'ScheduleService must depend on ScheduleRepository contract.',
);

$scheduleControllerFiles = [
    __DIR__ . '/../../app/Http/Controllers/Api/ScheduleController.php',
    __DIR__ . '/../../app/Http/Controllers/Api/MyScheduleController.php',
    __DIR__ . '/../../app/Http/Controllers/SchedulePageController.php',
];
foreach ($scheduleControllerFiles as $controllerFile) {
    $controllerText = file_get_contents($controllerFile);
    assert_true(str_contains($controllerText, 'ScheduleService'), $controllerFile . ' must call ScheduleService.');
    assert_true(!str_contains($controllerText, 'ScheduleRepository'), $controllerFile . ' must not import repositories.');
}

// Generic: no controller may import any repository or adapter directly.
$controllerRoot = __DIR__ . '/../../app/Http/Controllers';
$controllerIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllerRoot));
foreach ($controllerIterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $text = file_get_contents($file->getPathname());
    assert_true(
        !preg_match('/use\s+App\\\\Repositories\\\\/', $text),
        $file->getPathname() . ' must not import any repository.',
    );
    assert_true(
        !preg_match('/use\s+App\\\\Adapters\\\\/', $text),
        $file->getPathname() . ' must not import any adapter.',
    );
}

// SQL is forbidden in: controllers/handlers, services, repositories, frontend.
// SQL is permitted ONLY in: app/Adapters/** (the source-specific boundary).
$forbiddenRoots = [
    __DIR__ . '/../../app/Http',
    __DIR__ . '/../../app/Services',
    __DIR__ . '/../../app/Repositories',
    __DIR__ . '/../../resources/js',
];
$forbiddenSqlPatterns = [
    '/\bselect\b.+\bfrom\b/s',
    '/\binsert\s+into\b/',
    '/\bupdate\s+[a-z0-9_`]+\s+set\b/',
    '/\bdelete\s+from\b/',
];
// ---------------------------------------------------------------------------
// Known debt ratchet.
//
// These seven services were written with direct SQL and predate this rule being
// enforceable — the suite has been failing to even load since
// FakeScheduleRepository drifted behind its contract, so the violations
// accumulated unseen. Moving ~51 statements out of admin services that perform
// destructive operations (family merge, person delete, user delete) is a real
// refactor and is tracked separately, not attempted here.
//
// The rule is NOT relaxed. This list only exempts these exact files, and the
// assertions below guarantee the list can only shrink:
//   * any OTHER file with direct SQL still fails;
//   * a listed file that has been cleaned must be removed from the list, or the
//     test fails — so the debt cannot be quietly retained once fixed.
$sqlDebtBaseline = [
    // Infrastructure exception, not domain debt.
    //
    // MaintenanceBackupService performs generic schema- and table-level
    // database operations — SHOW FULL TABLES, SHOW CREATE TABLE, and a
    // row-by-row dump of whatever tables exist. It does not persist any domain
    // entity, so there is no domain repository it could sit behind: an
    // interface like BackupRepository::showCreateTable(string $table) would
    // relocate the SQL without abstracting anything, and would make the
    // architecture read as if a domain boundary existed where it does not.
    //
    // The general rule is NOT relaxed by this entry. Domain persistence in a
    // service still fails, which is why MemberCampusImportService was
    // refactored behind MemberImportRepository rather than listed here.
    //
    // Follow-up recorded in docs/design/18-architecture-debt.md: moving this
    // class to an infrastructure/database namespace would express the
    // distinction in the directory structure rather than in a comment.
    'app/Services/MaintenanceBackupService.php',

    'app/Services/CampusAdminService.php',
    'app/Services/FamilyAdminService.php',
    'app/Services/OptionAdminService.php',
    'app/Services/PersonIdentityResolver.php',
    'app/Services/PersonAdminService.php',
    'app/Services/RosterScheduleService.php',
    'app/Services/SystemUserService.php',
];
$projectRoot = realpath(__DIR__ . '/../..');
$stillViolating = [];

foreach ($forbiddenRoots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $extension = $file->getExtension();
        if (!in_array($extension, ['php', 'js'], true)) {
            continue;
        }

        $relative = ltrim(str_replace($projectRoot, '', realpath($file->getPathname())), '/\\');
        $relative = str_replace('\\', '/', $relative);
        $text = strtolower(file_get_contents($file->getPathname()));

        $violates = false;
        foreach ($forbiddenSqlPatterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $violates = true;
                break;
            }
        }

        if ($violates) {
            $stillViolating[] = $relative;
            assert_true(
                in_array($relative, $sqlDebtBaseline, true),
                $relative . ' contains direct SQL. SQL is permitted only in app/Adapters/**.'
                    . ' New violations are not accepted; see docs/design/18-architecture-debt.md.',
            );
        }
    }
}

// The baseline must shrink, never linger. If a listed file no longer contains
// SQL, it must be removed from the list in the same change that cleaned it.
$fixedButStillListed = array_values(array_diff($sqlDebtBaseline, $stillViolating));
assert_true(
    $fixedButStillListed === [],
    'These files no longer contain direct SQL and must be removed from $sqlDebtBaseline: '
        . implode(', ', $fixedButStillListed),
);

// Repository layer: must depend on the adapter contract (source-agnostic),
// not on a concrete adapter or DB connection.
require_once __DIR__ . '/../../app/Repositories/DefaultScheduleRepository.php';
$repositoryReflection = new ReflectionClass(\App\Repositories\DefaultScheduleRepository::class);
$repoCtorParam = $repositoryReflection->getConstructor()->getParameters()[0] ?? null;
assert_true(
    $repoCtorParam?->getType()?->__toString() === \App\Contracts\ScheduleAdapter::class,
    'DefaultScheduleRepository must depend on ScheduleAdapter contract, not a concrete adapter.',
);

// Repositories must not import or reference any concrete adapter — only the contract.
$repoText = file_get_contents(__DIR__ . '/../../app/Repositories/DefaultScheduleRepository.php');
assert_true(
    !preg_match('/use\s+App\\\\Adapters\\\\/', $repoText),
    'DefaultScheduleRepository must not import concrete adapters from App\\Adapters\\*.',
);

// Auth layering mirrors scheduling: service → repo (contract) → adapter (contract).
require_once __DIR__ . '/../../app/Repositories/DefaultAuthRepository.php';
$authRepoReflection = new ReflectionClass(\App\Repositories\DefaultAuthRepository::class);
$authRepoCtorParam  = $authRepoReflection->getConstructor()->getParameters()[0] ?? null;
assert_true(
    $authRepoCtorParam?->getType()?->__toString() === \App\Contracts\AuthAdapter::class,
    'DefaultAuthRepository must depend on AuthAdapter contract, not a concrete adapter.',
);
$authRepoText = file_get_contents(__DIR__ . '/../../app/Repositories/DefaultAuthRepository.php');
assert_true(
    !preg_match('/use\s+App\\\\Adapters\\\\/', $authRepoText),
    'DefaultAuthRepository must not import concrete adapters from App\\Adapters\\*.',
);

$context = new ActorContext(
    actorId: 1,
    personId: 42,
    displayName: null,
    permissions: [PortalPermission::ManageSchedules],
    ministryScopeIds: [10],
    campusScopeIds: [2],
    currentCampusId: 2,
    currentCampusIds: [2],
);
$scheduleRepo = new FakeScheduleRepository();
$service = new ScheduleService($scheduleRepo);
$grid = $service->getScheduleGrid($context, 10, new DateTimeImmutable('2026-05-03'), new DateTimeImmutable('2026-05-31'));
assert_true($grid->viewMode === 'compact_grid', 'Schedule grid must preserve compact grid mode.');
assert_true($grid->roles[0]->name === 'Usher', 'Schedule grid must normalize role DTOs.');
assert_true($scheduleRepo->lastGridCampusIds === [2], 'Schedule grid must pass current campus scope into the repository.');
assert_true($scheduleRepo->lastGridEventIds === [4], 'Schedule grid must request the campus default scheduling event, not every calendar occurrence.');
assert_true(
    count($grid->occurrences) === 1
        && $grid->occurrences[0]->id === 501
        && $grid->occurrences[0]->eventTitle === 'Sunday Service',
    'Schedule grid must surface occurrences for the column axis.',
);
assert_true(
    count($grid->schedulingEvents) === 1 && $grid->selectedEventIds === [4],
    'Schedule grid must advertise the eligible pool and the resolved selection.',
);

$blockedContext = new ActorContext(
    actorId: 2,
    personId: 99,
    displayName: null,
    permissions: [PortalPermission::ViewOwnAssignments],
    ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    fn () => $service->getScheduleGrid($blockedContext, 10, new DateTimeImmutable('2026-05-03'), new DateTimeImmutable('2026-05-31')),
    'Out-of-scope member must not read ministry schedule grid.',
);

assert_throws(
    ValidationFailed::class,
    fn () => $service->saveAssignments($context, new AssignmentBatchCommand(10, [])),
    'Empty assignment batch must fail validation.',
);

$result = $service->saveAssignments(
    $context,
    new AssignmentBatchCommand(10, [
        new ScheduleAssignment(null, 42, 7, new DateTimeImmutable('2026-05-03'), '', 501),
    ]),
);
assert_true($result->saved === true && $result->assignmentCount === 1, 'Valid assignment batch must save.');
assert_true(
    count($scheduleRepo->savedCommands) === 1 && $scheduleRepo->savedCommands[0]->campusIds === [2],
    'Schedule saves must stay scoped to the current campus filter.',
);

// My Schedule: a member viewing their own assignments succeeds.
$memberContext = new ActorContext(
    actorId: 5,
    personId: 42,
    displayName: null,
    permissions: [PortalPermission::ViewMinistryDashboard, PortalPermission::ViewOwnAssignments],
    ministryScopeIds: [],
);
$mySchedule = $service->getMySchedule(
    $memberContext,
    42,
    new DateTimeImmutable('2026-05-01'),
    new DateTimeImmutable('2026-05-31'),
);
assert_true($mySchedule->personId === 42, 'My Schedule must echo back the person id.');
assert_true(count($mySchedule->assignments) === 1, 'My Schedule must return adapter rows shaped as DTOs.');
assert_true(
    $mySchedule->assignments[0]->roleName === 'Usher'
        && $mySchedule->assignments[0]->ministryName === 'Hospitality'
        && $mySchedule->assignments[0]->eventTitle === 'Sunday Service',
    'My Schedule DTO must carry role/ministry/event labels.',
);

// Cross-person access by a non-admin member is denied.
assert_throws(
    PermissionDenied::class,
    fn () => $service->getMySchedule(
        $memberContext,
        99,
        new DateTimeImmutable('2026-05-01'),
        new DateTimeImmutable('2026-05-31'),
    ),
    'Member must not view another person\'s schedule.',
);

// Portal-wide admin may pull any person's schedule (support / impersonation case).
$adminContext = new ActorContext(
    actorId: 1,
    personId: 957,
    displayName: null,
    permissions: [PortalPermission::ManageSchedules, PortalPermission::ViewMinistrySchedule, PortalPermission::ViewOwnAssignments],
    ministryScopeIds: [],
    isPortalWideAdmin: true,
);
$adminView = $service->getMySchedule(
    $adminContext,
    99,
    new DateTimeImmutable('2026-05-01'),
    new DateTimeImmutable('2026-05-31'),
);
assert_true($adminView->personId === 99, 'Admin may resolve another person\'s schedule.');

// Missing the base permission is rejected even for self.
$noPermContext = new ActorContext(
    actorId: 6,
    personId: 42,
    displayName: null,
    permissions: [],
    ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    fn () => $service->getMySchedule(
        $noPermContext,
        42,
        new DateTimeImmutable('2026-05-01'),
        new DateTimeImmutable('2026-05-31'),
    ),
    'Actor without ViewOwnAssignments must be denied.',
);

// MinistryService: same layered guarantees as ScheduleService.
$ministryServiceReflection = new ReflectionClass(MinistryService::class);
$ministryCtorParam = $ministryServiceReflection->getConstructor()->getParameters()[0] ?? null;
assert_true(
    $ministryCtorParam?->getType()?->__toString() === MinistryRepository::class,
    'MinistryService must depend on MinistryRepository contract.',
);

require_once __DIR__ . '/../../app/Repositories/DefaultMinistryRepository.php';
$minRepoReflection = new ReflectionClass(\App\Repositories\DefaultMinistryRepository::class);
$minRepoCtorParam  = $minRepoReflection->getConstructor()->getParameters()[0] ?? null;
assert_true(
    $minRepoCtorParam?->getType()?->__toString() === \App\Contracts\MinistryAdapter::class,
    'DefaultMinistryRepository must depend on MinistryAdapter contract, not a concrete adapter.',
);
$minRepoText = file_get_contents(__DIR__ . '/../../app/Repositories/DefaultMinistryRepository.php');
assert_true(
    !preg_match('/use\s+App\\\\Adapters\\\\/', $minRepoText),
    'DefaultMinistryRepository must not import concrete adapters from App\\Adapters\\*.',
);

$ministryService = new MinistryService(new FakeMinistryRepository());

// Leader scoped to ministry 10 may read its roster.
$leaderContext = new ActorContext(
    actorId: 7,
    personId: 700,
    displayName: null,
    permissions: [PortalPermission::ViewMinistrySchedule, PortalPermission::ViewOwnAssignments],
    ministryScopeIds: [10],
);
$leaderMinistries = $ministryService->listAccessibleMinistries($leaderContext);
assert_true(
    count($leaderMinistries) === 1
        && $leaderMinistries[0]['ministryId'] === 10
        && $leaderMinistries[0]['canViewPeople'] === true,
    'Leader ministry selector must list only in-scope ministries.',
);

$allMinistries = $ministryService->listAllMinistries($leaderContext);
assert_true(
    count($allMinistries) === 2,
    'Directory ministry selector must list the full ministry catalog.',
);

$leaderCampuses = $ministryService->getCampusSelector($leaderContext);
assert_true(
    $leaderCampuses['defaultCampusId'] === 3,
    'Campus selector must prefer the linked person primary campus when available.',
);

$leaderDashboard = $ministryService->listDashboardCards(
    $leaderContext,
    new DateTimeImmutable('2026-01-01'),
    new DateTimeImmutable('2026-12-31'),
);
assert_true(
    count($leaderDashboard) === 2
        && $leaderDashboard[0]->canOpenSchedule === true
        && $leaderDashboard[1]->canOpenSchedule === false,
    'Dashboard must list all ministries, but leader actions remain scoped.',
);

$roster = $ministryService->getRoster(
    $leaderContext,
    10,
    new DateTimeImmutable('2026-01-01'),
);
assert_true(
    $roster->ministryId === 10
        && $roster->ministryName === 'Hospitality'
        && count($roster->members) === 2,
    'Leader must read roster for in-scope ministry.',
);
assert_true(
    $roster->members[0]->rolesServed === 'Usher, Greeter'
        && $roster->members[0]->assignmentCount === 3
        && $roster->members[1]->lastServedAt === null,
    'Roster DTOs must carry serve-history aggregates.',
);

$directory = $ministryService->listPeopleDirectory($leaderContext, new DateTimeImmutable('2026-01-01'));
assert_true(
    count($directory) === 2
        && $directory[0]['ministryCount'] === 2
        && $directory[1]['ministryCount'] === 0,
    'People directory must include all members regardless of ministry membership.',
);

$singlePerson = $ministryService->findPeopleDirectoryPerson($leaderContext, 42, new DateTimeImmutable('2026-01-01'));
assert_true(
    $singlePerson !== null && $singlePerson['personId'] === 42,
    'People directory single-person lookup must resolve by person id.',
);

// Member without ministry scope is denied.
$memberContext = new ActorContext(
    actorId: 5,
    personId: 42,
    displayName: null,
    permissions: [PortalPermission::ViewMinistryDashboard, PortalPermission::ViewOwnAssignments],
    ministryScopeIds: [],
);
assert_throws(
    PermissionDenied::class,
    fn () => $ministryService->getRoster($memberContext, 10, new DateTimeImmutable('2026-01-01')),
    'Member without ViewMinistrySchedule must be denied.',
);

$memberDashboard = $ministryService->listDashboardCards(
    $memberContext,
    new DateTimeImmutable('2026-01-01'),
    new DateTimeImmutable('2026-12-31'),
);
assert_true(
    count($memberDashboard) === 2
        && $memberDashboard[0]->canOpenSchedule === false
        && $memberDashboard[0]->upcomingAssignmentCount === 3,
    'Member dashboard must list all ministries with read-only schedule summaries.',
);

// Leader with scope but for a different ministry is denied.
$otherMinistryLeader = new ActorContext(
    actorId: 8,
    personId: 800,
    displayName: null,
    permissions: [PortalPermission::ViewMinistrySchedule],
    ministryScopeIds: [11],
);
assert_throws(
    PermissionDenied::class,
    fn () => $ministryService->getRoster($otherMinistryLeader, 10, new DateTimeImmutable('2026-01-01')),
    'Leader scoped to a different ministry must be denied.',
);

// Portal-wide admin bypasses scope.
$adminCtx = new ActorContext(
    actorId: 1,
    personId: 957,
    displayName: null,
    permissions: [PortalPermission::ManageSchedules, PortalPermission::ViewMinistrySchedule, PortalPermission::ViewOwnAssignments],
    ministryScopeIds: [],
    isPortalWideAdmin: true,
);
$adminMinistries = $ministryService->listAccessibleMinistries($adminCtx);
assert_true(
    count($adminMinistries) === 2,
    'Portal-wide admin ministry selector may list all ministries.',
);

$adminCampuses = $ministryService->getCampusSelector($adminCtx);
// This previously required a fallback to Scarborough by name. That hardcoded
// one congregation as the default for every user with no campus of their own,
// which is why the dashboard, docs and every admin page opened scoped to a
// single campus and "All campuses" would not stick. Which campus leads is
// configuration (church_campus.is_main); with none chosen, All campuses is the
// honest answer rather than a guess.
assert_true(
    $adminCampuses['defaultCampusId'] === null,
    'With no main campus configured, the selector defaults to All campuses rather than guessing one.',
);
assert_true(
    count($adminCampuses['campuses']) === 2,
    'A portal-wide admin still sees every campus in the selector.',
);

$adminRoster = $ministryService->getRoster($adminCtx, 10, new DateTimeImmutable('2026-01-01'), campusId: 2);
assert_true(
    $adminRoster->campusFilter === 2 && count($adminRoster->members) === 2,
    'Admin may read any ministry; campus filter must propagate.',
);

// Out-of-scope campus filter is denied for a campus-scoped leader.
$campusScopedLeader = new ActorContext(
    actorId: 9,
    personId: 900,
    displayName: null,
    permissions: [PortalPermission::ViewMinistrySchedule],
    ministryScopeIds: [10],
    campusScopeIds: [2],
);
assert_throws(
    PermissionDenied::class,
    fn () => $ministryService->getRoster(
        $campusScopedLeader,
        10,
        new DateTimeImmutable('2026-01-01'),
        campusId: 99,
    ),
    'Campus-scoped leader must not read roster filtered by an out-of-scope campus.',
);

// AvailabilityService: layered guarantees + permission semantics.
$availServiceReflection = new ReflectionClass(AvailabilityService::class);
$availCtorParam = $availServiceReflection->getConstructor()->getParameters()[0] ?? null;
assert_true(
    $availCtorParam?->getType()?->__toString() === AvailabilityRepository::class,
    'AvailabilityService must depend on AvailabilityRepository contract.',
);

require_once __DIR__ . '/../../app/Repositories/DefaultAvailabilityRepository.php';
$availRepoReflection = new ReflectionClass(\App\Repositories\DefaultAvailabilityRepository::class);
$availRepoCtorParam  = $availRepoReflection->getConstructor()->getParameters()[0] ?? null;
assert_true(
    $availRepoCtorParam?->getType()?->__toString() === \App\Contracts\AvailabilityAdapter::class,
    'DefaultAvailabilityRepository must depend on AvailabilityAdapter contract.',
);
$availRepoText = file_get_contents(__DIR__ . '/../../app/Repositories/DefaultAvailabilityRepository.php');
assert_true(
    !preg_match('/use\s+App\\\\Adapters\\\\/', $availRepoText),
    'DefaultAvailabilityRepository must not import concrete adapters.',
);

require_once __DIR__ . '/../../app/Repositories/DefaultEventRepository.php';
$eventRepoReflection = new ReflectionClass(\App\Repositories\DefaultEventRepository::class);
$eventRepoCtorParam  = $eventRepoReflection->getConstructor()->getParameters()[0] ?? null;
assert_true(
    $eventRepoCtorParam?->getType()?->__toString() === \App\Contracts\EventAdapter::class,
    'DefaultEventRepository must depend on EventAdapter contract.',
);
$eventRepoText = file_get_contents(__DIR__ . '/../../app/Repositories/DefaultEventRepository.php');
assert_true(
    !preg_match('/use\s+App\\\\Adapters\\\\/', $eventRepoText),
    'DefaultEventRepository must not import concrete adapters.',
);

// AvailabilityCommand input validation.
assert_throws(
    ValidationFailed::class,
    fn () => new AvailabilityCommand(
        personId: 0,
        startsOn: new DateTimeImmutable('2026-06-01'),
        endsOn:   new DateTimeImmutable('2026-06-08'),
        reason:   null,
    ),
    'AvailabilityCommand must reject person_id <= 0.',
);
assert_throws(
    ValidationFailed::class,
    fn () => new AvailabilityCommand(
        personId: 42,
        startsOn: new DateTimeImmutable('2026-06-08'),
        endsOn:   new DateTimeImmutable('2026-06-01'),
        reason:   null,
    ),
    'AvailabilityCommand must reject ends_on before starts_on.',
);

$repo = new FakeAvailabilityRepository();
$availService = new AvailabilityService($repo);

// Member acts on their own person id.
$memberCtx = new ActorContext(
    actorId: 5,
    personId: 42,
    displayName: null,
    permissions: [PortalPermission::ViewOwnAssignments, PortalPermission::ManageOwnAvailability],
    ministryScopeIds: [],
);
$entry = $availService->create($memberCtx, new AvailabilityCommand(
    personId: 42,
    startsOn: new DateTimeImmutable('2026-06-01'),
    endsOn:   new DateTimeImmutable('2026-06-08'),
    reason:   'Vacation',
));
assert_true(
    $entry->personId === 42 && $entry->reason === 'Vacation' && $entry->createdByAccountId === 5,
    'Member may create their own unavailability; createdBy must reflect the actor.',
);

// Member cannot create entry for someone else.
assert_throws(
    PermissionDenied::class,
    fn () => $availService->create($memberCtx, new AvailabilityCommand(
        personId: 99,
        startsOn: new DateTimeImmutable('2026-06-01'),
        endsOn:   new DateTimeImmutable('2026-06-08'),
        reason:   null,
    )),
    'Member must not create entries for another person.',
);

// Member can read their own list.
$myList = $availService->listForPerson($memberCtx, 42);
assert_true(count($myList) === 1, 'Member must read own unavailability list.');

// Member cannot read someone else's list.
assert_throws(
    PermissionDenied::class,
    fn () => $availService->listForPerson($memberCtx, 99),
    'Member must not read another person\'s unavailability.',
);

// Leader/scheduler can read others (ViewPersonAvailability) but not create writes for them
// without ManageSchedules. Our role mapping gives leaders both, so build a context that
// has only ViewPersonAvailability + ManageOwnAvailability (no ManageSchedules) to confirm
// the cross-person write is blocked.
$pseudoLeader = new ActorContext(
    actorId: 9,
    personId: 700,
    displayName: null,
    permissions: [PortalPermission::ViewPersonAvailability, PortalPermission::ManageOwnAvailability],
    ministryScopeIds: [],
);
$othersList = $availService->listForPerson($pseudoLeader, 42);
assert_true(count($othersList) === 1, 'ViewPersonAvailability allows cross-person reads.');

assert_throws(
    PermissionDenied::class,
    fn () => $availService->create($pseudoLeader, new AvailabilityCommand(
        personId: 42,
        startsOn: new DateTimeImmutable('2026-06-09'),
        endsOn:   new DateTimeImmutable('2026-06-10'),
        reason:   null,
    )),
    'Cross-person writes require ManageSchedules, not just ViewPersonAvailability.',
);

// Full leader (with ManageSchedules) may create entries for others.
$fullLeader = new ActorContext(
    actorId: 10,
    personId: 701,
    displayName: null,
    permissions: [
        PortalPermission::ManageSchedules,
        PortalPermission::ViewPersonAvailability,
        PortalPermission::ManageOwnAvailability,
    ],
    ministryScopeIds: [],
);
// Entry for a *different* subject (person 99), to test the cross-person path.
$crossPersonEntry = $availService->create($fullLeader, new AvailabilityCommand(
    personId: 99,
    startsOn: new DateTimeImmutable('2026-06-09'),
    endsOn:   new DateTimeImmutable('2026-06-10'),
    reason:   'Logged by scheduler for someone else',
));
assert_true(
    $crossPersonEntry->personId === 99 && $crossPersonEntry->createdByAccountId === 10,
    'Scheduler with ManageSchedules may create entries for another person; audit field reflects actor.',
);

// memberCtx is person 42 — must not delete an entry that belongs to person 99.
assert_throws(
    PermissionDenied::class,
    fn () => $availService->delete($memberCtx, $crossPersonEntry->id),
    'Member must not delete entries belonging to another person.',
);
// Authorized delete by the scheduler succeeds.
$availService->delete($fullLeader, $crossPersonEntry->id);
assert_true(
    $repo->findById($crossPersonEntry->id) === null,
    'Authorized delete must remove the row.',
);

// Self-delete for member should still work (member deleting their own entry).
$availService->delete($memberCtx, $entry->id);
assert_true(
    $repo->findById($entry->id) === null,
    'Member must be able to delete their own entry.',
);

echo "Architecture boundary tests passed.\n";
