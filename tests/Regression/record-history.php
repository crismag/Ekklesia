<?php

declare(strict_types=1);

/**
 * Record history: the audit trail of person and household records.
 *
 * Admin-only, read-only. These pin the rules that live in the service rather
 * than in SQL: who may read it, how request parameters become criteria (a bad
 * link must show everything rather than error or show nothing), how pages are
 * clamped, and how a row is described (who, what, which record).
 */

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

use App\Contracts\RecordHistoryRepository;
use App\Exceptions\PermissionDenied;
use App\Services\RecordHistoryService;

$passed = 0;
$failed = 0;
function rh_check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

final class FakeRecordHistoryRepository implements RecordHistoryRepository
{
    /** @var list<array<string,mixed>> */
    public array $criteriaSeen = [];
    /** @var list<array{0:int,1:int}> */
    public array $windows = [];

    /** @param list<array<string,mixed>> $rows */
    public function __construct(public array $rows = []) {}

    public function count(array $criteria): int
    {
        $this->criteriaSeen[] = $criteria;

        return count($this->rows);
    }

    public function find(array $criteria, int $limit, int $offset): array
    {
        $this->windows[] = [$limit, $offset];

        return array_slice($this->rows, $offset, $limit);
    }

    public function actions(): array
    {
        return ['person.updated', 'household.merged', 'person.some_new_thing'];
    }

    public function peopleWithHistory(): array
    {
        return [['id' => 4, 'first_name' => 'Ana', 'last_name' => 'Cruz'], ['id' => 9, 'first_name' => '', 'last_name' => '']];
    }

    public function personName(int $personId): ?string
    {
        return $personId === 4 ? 'Ana Cruz' : null;
    }

    public function householdName(int $householdId): ?string
    {
        return null;
    }
}

$row = static fn (array $over = []): array => $over + [
    'id' => 1, 'occurred_at' => '2026-09-01 10:00:00', 'action' => 'person.updated',
    'target_type' => 'person', 'target_id' => '4', 'summary' => null, 'details' => null,
    'account_id' => 3, 'account_email' => 'admin@example.invalid', 'account_name' => 'Office',
    'actor_person_id' => 7, 'actor_first_name' => 'Ruth', 'actor_last_name' => 'Lim',
    'target_first_name' => 'Ana', 'target_last_name' => 'Cruz', 'target_household_name' => null,
];

$admin = ['actorId' => 3, 'isPortalWideAdmin' => true, 'permissions' => []];
$leader = ['actorId' => 5, 'isPortalWideAdmin' => false, 'permissions' => ['manage_ministry_roles', 'manage_events']];

// Authorization -------------------------------------------------------------
$svc = new RecordHistoryService(new FakeRecordHistoryRepository([$row()]));
foreach (['anonymous' => null, 'a leader with other permissions' => $leader] as $who => $actor) {
    foreach (['page' => fn () => $svc->page($actor, []), 'recent' => fn () => $svc->recent($actor, 'person', 4),
              'filterOptions' => fn () => $svc->filterOptions($actor), 'recordName' => fn () => $svc->recordName($actor, 'person', 4)] as $m => $fn) {
        $refused = false;
        try { $fn(); } catch (PermissionDenied) { $refused = true; }
        rh_check("$who is refused $m()", $refused);
    }
}
rh_check('an administrator may read', RecordHistoryService::mayRead($admin) && $svc->page($admin, [])['total'] === 1);

// Criteria ------------------------------------------------------------------
$c = RecordHistoryService::criteria([]);
rh_check('no parameters: both record types, no narrowing, page 1',
    $c['types'] === ['person', 'household'] && $c['personId'] === null && $c['householdId'] === null
    && $c['action'] === null && $c['from'] === null && $c['to'] === null && $c['page'] === 1);
$c = RecordHistoryService::criteria(['type' => 'household', 'person' => '12', 'household' => '5', 'action' => 'person.updated', 'from' => '2026-01-01', 'to' => '2026-02-01', 'page' => '3']);
rh_check('well-formed parameters are kept',
    $c['types'] === ['household'] && $c['personId'] === 12 && $c['householdId'] === 5
    && $c['action'] === 'person.updated' && $c['from'] === '2026-01-01' && $c['to'] === '2026-02-01' && $c['page'] === 3);
$c = RecordHistoryService::criteria(['type' => 'user_account', 'person' => '12abc', 'household' => '-4', 'action' => "x' OR 1=1", 'from' => '2026-02-30', 'to' => 'yesterday', 'page' => '0']);
rh_check('malformed parameters are dropped, not guessed',
    $c['types'] === ['person', 'household'] && $c['personId'] === null && $c['householdId'] === null
    && $c['action'] === null && $c['from'] === null && $c['to'] === null && $c['page'] === 1, json_encode($c));
$c = RecordHistoryService::criteria(['from' => '2026-05-01', 'to' => '2026-04-01']);
rh_check('a reversed date range is put the right way round', $c['from'] === '2026-04-01' && $c['to'] === '2026-05-01');
$c = RecordHistoryService::criteria(['person' => ['1']]);
rh_check('an array where an id belongs is ignored', $c['personId'] === null);

// Paging --------------------------------------------------------------------
$many = array_map(static fn (int $i): array => $row(['id' => $i]), range(1, 120));
$repo = new FakeRecordHistoryRepository($many);
$page = (new RecordHistoryService($repo))->page($admin, ['page' => '99']);
rh_check('a page past the end is clamped to the last page', $page['page'] === 3 && $page['pages'] === 3 && count($page['entries']) === 20);
rh_check('the window asked of the repository follows the page', end($repo->windows) === [50, 100]);
$empty = new FakeRecordHistoryRepository([]);
$page = (new RecordHistoryService($empty))->page($admin, []);
rh_check('no history: one empty page and no row query', $page['total'] === 0 && $page['pages'] === 1 && $empty->windows === []);

// Recent for a record -------------------------------------------------------
$repo = new FakeRecordHistoryRepository($many);
$recent = (new RecordHistoryService($repo))->recent($admin, 'person', 4, 5);
rh_check('recent entries are limited and count everything',
    count($recent['entries']) === 5 && $recent['total'] === 120
    && $repo->criteriaSeen[0]['personId'] === 4 && $repo->criteriaSeen[0]['types'] === ['person']);
$recent = (new RecordHistoryService($repo))->recent($admin, 'household', 0);
rh_check('a record without an id has no history', $recent === ['entries' => [], 'total' => 0]);

// Describing rows -----------------------------------------------------------
$d = $svc->describe($row());
rh_check('who is the account\'s person, what is labelled, record is named',
    $d['who'] === 'Ruth Lim' && $d['whoPersonId'] === 7 && $d['actionLabel'] === 'Record edited'
    && $d['recordType'] === 'person' && $d['recordId'] === 4 && $d['recordName'] === 'Ana Cruz');
$d = $svc->describe($row(['actor_first_name' => null, 'actor_last_name' => null, 'actor_person_id' => null]));
rh_check('an account without a person falls back to its display name', $d['who'] === 'Office' && $d['whoPersonId'] === null);
$d = $svc->describe($row(['actor_first_name' => null, 'actor_last_name' => null, 'account_name' => '', 'account_email' => null, 'account_id' => null]));
rh_check('nobody recorded says so', $d['who'] === 'Not recorded');
$d = $svc->describe($row(['target_first_name' => null, 'target_last_name' => null]));
rh_check('a deleted person has no name (the page says so)', $d['recordName'] === null);
$d = $svc->describe($row(['action' => 'household.merged', 'target_type' => 'household', 'target_id' => '8',
    'target_first_name' => null, 'target_last_name' => null, 'target_household_name' => 'Cruz household',
    'details' => '{"merged_household_id": 31, "people_moved": 2}']));
rh_check('a merge is summarised from its details',
    $d['recordName'] === 'Cruz household' && $d['summary'] === 'Household #31 merged into this one; 2 people moved.', $d['summary']);
$d = $svc->describe($row(['summary' => 'Added to group: Choir']));
rh_check('a stored summary is used as written', $d['summary'] === 'Added to group: Choir');
rh_check('an unknown action is still readable', RecordHistoryService::actionLabel('person.some_new_thing') === 'Some new thing');

$opts = $svc->filterOptions($admin);
rh_check('filter options: labelled actions and named people',
    in_array(['value' => 'household.merged', 'label' => 'Households merged'], $opts['actions'], true)
    && $opts['people'][0] === ['id' => 4, 'name' => 'Cruz, Ana'] && $opts['people'][1]['name'] === 'Person #9');

echo "\n  Passed: $passed; failed: $failed\n";
if ($failed > 0) {
    exit(1);
}
