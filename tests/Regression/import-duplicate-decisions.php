<?php

declare(strict_types=1);

/**
 * The importer decides each duplicate; match rules decide what "duplicate" is.
 *
 * Written after a parent "Jessie" and a child "Jessie James" — same surname,
 * email, phone and address — were keyed as one person (surname + first word of
 * the first name) and the parent was dropped before staging, with nothing left
 * to undo. Now every row is staged, each duplicate group carries a reversible
 * decision, and the importer can require more to agree than the name.
 *
 * Names here are invented. Real member data does not belong in the repository.
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

use App\Contracts\MemberImportRepository;
use App\Services\MemberCampusImportService;
use App\Services\MemberImportDeduper;
use App\Services\MemberImportPlanner;
use App\Services\MemberMatchRules;
use App\Services\PersonAdminService;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

$row = static fn (string $last, string $first, array $more = []): array => [
    'last_name' => $last, 'first_name' => $first, 'name_raw' => "{$last}, {$first}",
    'email' => 'fam@example.invalid', 'phone' => '416-555-0100', 'address_line1' => '1 Elm St',
] + $more;

// --- match rules -----------------------------------------------------------
$default = new MemberMatchRules();
check('default rule: first word of the first name', $default->nameKey('Quill', 'Jessie James') === $default->nameKey('Quill', 'Jessie'));
$full = new MemberMatchRules([MemberMatchRules::FULL_FIRST_NAME]);
check('full first name: "Jessie" and "Jessie James" differ', $full->nameKey('Quill', 'Jessie James') !== $full->nameKey('Quill', 'Jessie'));
check('unknown rules are ignored and order is fixed',
    (new MemberMatchRules(['phone', 'nonsense', 'birthday']))->toArray() === ['birthday', 'phone']);
$bday = new MemberMatchRules([MemberMatchRules::BIRTHDAY]);
check('different birthdays disagree', !$bday->agree(['birth_month' => 3, 'birth_day' => 5], ['birth_month' => 7, 'birth_day' => 1]));
check('a blank birthday is not a difference', $bday->agree(['birth_month' => 3, 'birth_day' => 5], ['birth_month' => null, 'birth_day' => null]));
check('a birthday from a parsed row compares with a stored one',
    $bday->agree(['birthday' => ['year' => 1970, 'month' => 3, 'day' => 5]], ['birth_year' => 1970, 'birth_month' => 3, 'birth_day' => 5]));
check('same day, different known years disagree',
    !$bday->agree(['birth_year' => 1970, 'birth_month' => 3, 'birth_day' => 5], ['birth_year' => 2005, 'birth_month' => 3, 'birth_day' => 5]));
$phone = new MemberMatchRules([MemberMatchRules::PHONE]);
check('phone compares the local number', $phone->agree(['phone' => '+1 (416) 555-0100'], ['mobile_phone' => '416-555-0100']));
check('different phones disagree', !$phone->agree(['phone' => '416-555-0100'], ['phone' => '416-555-0199']));
$type = new MemberMatchRules([MemberMatchRules::MEMBER_TYPE]);
check('member type ignores case', $type->agree(['member_type' => 'Radical'], ['member_type' => 'radical']));
check('different member types disagree', !$type->agree(['member_type' => 'Radical'], ['member_type' => 'Trailblazer']));

// --- grouping --------------------------------------------------------------
$d = new MemberImportDeduper();
$jessies = [
    $row('Quill', 'Jessie', ['birth_year' => 1975, 'birth_month' => 4, 'birth_day' => 2, 'member_type' => 'Trailblazer']),
    $row('Quill', 'Jessie James', ['birth_year' => 2012, 'birth_month' => 9, 'birth_day' => 30, 'member_type' => 'Radical']),
];
check('default rule groups the parent and child (the reported case)', $d->groups($jessies) === [[0, 1]]);
check('full first name keeps them apart', $d->groups($jessies, $full) === []);
check('birthday keeps them apart', $d->groups($jessies, $bday) === []);
check('member type keeps them apart', $d->groups($jessies, $type) === []);
check('shared email and phone do not keep them apart',
    $d->groups($jessies, new MemberMatchRules(['email', 'phone'])) === [[0, 1]]);
check('dedupe (preview) no longer drops either under a rule', count($d->dedupe($jessies, $bday)['rows']) === 2);

$three = [
    $row('Quill', 'Jessie', ['birth_month' => 4, 'birth_day' => 2]),
    $row('Quill', 'Jessie', ['birth_month' => 9, 'birth_day' => 30]),
    $row('Quill', 'Jessie', ['birth_month' => 4, 'birth_day' => 2, 'phone' => '']),
];
check('a row joins only the group it agrees with', $d->groups($three, $bday) === [[0, 2]]);
check('an initial does not fold into a full name under "full first name"',
    $d->groups([$row('Quill', 'J'), $row('Quill', 'Jessie')], $full) === []);
check('an initial does not fold across different birthdays',
    $d->groups([$row('Quill', 'J', ['birth_month' => 1, 'birth_day' => 1]), $row('Quill', 'Jessie', ['birth_month' => 2, 'birth_day' => 2])], $bday) === []);

// --- matching existing people ----------------------------------------------
$planner = new MemberImportPlanner();
$people = [
    ['id' => 1, 'first_name' => 'Jessie', 'last_name' => 'Quill', 'email' => 'fam@example.invalid', 'campus_id' => 3,
        'phone' => '416-555-0100', 'birth_year' => 1975, 'birth_month' => 4, 'birth_day' => 2, 'member_type' => 'Trailblazer'],
    ['id' => 2, 'first_name' => 'Jessie James', 'last_name' => 'Quill', 'email' => 'fam@example.invalid', 'campus_id' => 3,
        'phone' => '416-555-0100', 'birth_year' => 2012, 'birth_month' => 9, 'birth_day' => 30, 'member_type' => 'Radical'],
];
$byRow = static function (array $plan): array {
    $out = [];
    foreach ($plan['update'] as $u) {
        $out[$u['row']['first_name']] = (int) $u['person_id'];
    }
    return $out;
};
// Child listed first: by name and email alone the child used to take the parent's record.
$plan = $planner->plan([$jessies[1], $jessies[0]], $people, 3);
check('the full first name picks the right record even under the default rule',
    $byRow($plan) === ['Jessie James' => 2, 'Jessie' => 1]);
check('update entries carry their row index', array_map(static fn ($u) => $u['index'], $plan['update']) === [0, 1]);

$plan = $planner->plan([$jessies[1]], [$people[0]], 3, $bday);
check('a row never matches a person with a different birthday', $plan['update'] === [] && count($plan['create']) === 1);
check('and the importer is told why', str_contains(implode(' ', $plan['warnings']), 'differs on birthday'));
$plan = $planner->plan([$row('Quill-Barnes', 'Jessie')], [$people[0]], 3, $full);
check('"full first name" turns off matching a renamed person by email', $plan['update'] === []);

// --- staging decisions -----------------------------------------------------
/** In memory, with the JSON round trip the SQL adapter does. */
final class MemoryImportRepository implements MemberImportRepository
{
    public array $batches = [];
    public array $rows = [];

    public function assertSchemaReady(): void {}
    public function createBatch(array $batch, array $rows): int
    {
        $id = count($this->batches) + 1;
        $batch['id'] = $id;
        $batch['status'] = 'staging';
        $batch['match_rules'] = ($batch['match_rules'] ?? []) !== [] ? json_encode($batch['match_rules']) : null;
        $this->batches[$id] = $batch;
        foreach ($rows as $r) {
            $r['id'] = count($this->rows) + 1;
            $r['batch_id'] = $id;
            $this->rows[$r['id']] = $r;
        }
        return $id;
    }
    public function saveDuplicateReport(int $batchId, array $report): void
    {
        $this->batches[$batchId]['duplicate_report'] = json_encode($report);
    }
    public function listBatches(int $limit): array { return array_values($this->batches); }
    public function findBatch(int $id): ?array
    {
        if (!isset($this->batches[$id])) {
            return null;
        }
        $b = $this->batches[$id];
        $b['duplicate_report'] = json_decode((string) ($b['duplicate_report'] ?? ''), true) ?: [];
        return $b;
    }
    public function listRows(int $batchId, string $status = ''): array
    {
        return array_values(array_filter($this->rows, static fn ($r) => $r['batch_id'] === $batchId && ($status === '' || $r['status'] === $status)));
    }
    public function rowCounts(int $batchId): array { return []; }
    public function findRowWithBatchStatus(int $rowId): ?array { return null; }
    public function updateRowField(int $rowId, string $field, mixed $value, array $allowedFields): void
    {
        if (!in_array($field, $allowedFields, true)) {
            throw new InvalidArgumentException($field);
        }
        $this->rows[$rowId][$field] = $value;
    }
    public function markReadyMissing(int $batchId): int { return 0; }
    public function markBatchApplied(int $batchId): void { $this->batches[$batchId]['status'] = 'applied'; }
    public function deleteBatch(int $batchId): void {}
    public function findCampus(int $campusId): ?array { return ['id' => $campusId, 'name' => 'North York']; }
}

$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE member_types (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, campus_id INT,
    mobile_phone TEXT, birth_year INT, birth_month INT, birth_day INT, member_type_id INT)');

$csv = tempnam(sys_get_temp_dir(), 'dupes') . '.csv';
file_put_contents($csv, implode("\n", [
    '"LAST NAME, FIRST NAME","Birthday (MONTH/DAY/YEAR)","Address","Contact Information","Email",""',
    '"Quill, Jessie","April 2 1975","1 Elm St, North York, ON M2M 0B1","416-555-0100","fam@example.invalid","Trailblazer"',
    '"Quill, Jessie James","September 30 2012","1 Elm St, North York, ON M2M 0B1","","fam@example.invalid","Radical"',
    '"Arden, Tomas","","","","tomas@example.invalid","Radical"',
]) . "\n");

$ingest = static function (MemberMatchRules $rules) use ($db, $csv): array {
    $repo = new MemoryImportRepository();
    $svc = new MemberCampusImportService($db, $repo, new PersonAdminService($db));
    $svc->ingest($csv, 3, 0, 'Sheet1', '', 'test', true, $rules);
    return [$svc, $repo];
};

[$svc, $repo] = $ingest(new MemberMatchRules());
$staged = $repo->listRows(1);
check('every row is staged, the duplicate included', count($staged) === 3);
$groups = $svc->duplicateGroups(1);
check('the parent and child form one group', count($groups) === 1 && count($groups[0]['rows']) === 2);
$g = $groups[0];
// By birth year: keeping a row can expand its first name from the other.
$parentId = (int) array_values(array_filter($staged, static fn ($r) => (int) $r['birth_year'] === 1975))[0]['id'];
$childId = (int) array_values(array_filter($staged, static fn ($r) => (int) $r['birth_year'] === 2012))[0]['id'];
check('the suggestion (more filled fields) is applied: parent kept', $g['decision'] === 'keep' && $g['keep'] === $parentId);
check('the other row is set to Skip, not deleted', $repo->rows[$childId]['status'] === 'skip');
check('rows outside a group have no group', $repo->rows[3]['duplicate_group'] === null);
$parentStatus = $repo->rows[$parentId]['status'];
check('keeping fills the kept row from the other (name expansion)', $repo->rows[$parentId]['first_name'] === 'Jessie James');
$shown = array_values(array_filter($svc->duplicateGroups(1)[0]['rows'], static fn ($r) => (int) $r['id'] === $parentId))[0];
check('the report shows the kept row as the workbook had it', $shown['first_name'] === 'Jessie');
check('and lists what the merge filled in', $svc->duplicateGroups(1)[0]['filled'] === ['first_name' => 'Jessie James']);

$svc->resolveDuplicate(1, $g['group'], 'separate');
check('"different people" restores the skipped row', $repo->rows[$childId]['status'] !== 'skip');
check('and undoes what the merge filled in', $repo->rows[$parentId]['first_name'] === 'Jessie');
check('the parent keeps its own birthday', (int) $repo->rows[$parentId]['birth_year'] === 1975);
check('the decision is recorded', $svc->duplicateGroups(1)[0]['decision'] === 'separate');

$svc->resolveDuplicate(1, $g['group'], 'keep', $childId);
check('keeping the other row skips the parent instead', $repo->rows[$parentId]['status'] === 'skip' && $repo->rows[$childId]['status'] !== 'skip');
check('the kept row fills its blank phone from the other', $repo->rows[$childId]['phone'] === '416-555-0100');

$repo->updateRowField($childId, 'phone', '416-555-0199', ['phone']);
$svc->resolveDuplicate(1, $g['group'], 'separate');
check('an edit made by hand after the merge is not undone', $repo->rows[$childId]['phone'] === '416-555-0199');
check('the parent is back to its original status', $repo->rows[$parentId]['status'] === $parentStatus);

$threw = false;
try {
    $svc->resolveDuplicate(1, $g['group'], 'keep', 3);
} catch (InvalidArgumentException) {
    $threw = true;
}
check('a row outside the group cannot be chosen', $threw);
$repo->markBatchApplied(1);
$threw = false;
try {
    $svc->resolveDuplicate(1, $g['group'], 'separate');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('an applied batch cannot be changed', $threw);

[$svc, $repo] = $ingest(new MemberMatchRules([MemberMatchRules::BIRTHDAY]));
check('with the birthday rule, no group is formed', $svc->duplicateGroups(1) === []);
check('the rules are stored on the batch', $svc->batchRules($repo->findBatch(1))->toArray() === ['birthday']);
check('an old batch report reads as legacy, not as groups',
    $svc->legacyDuplicates(['duplicate_report' => [['name' => 'A', 'kept' => 'B', 'reason' => '']]]) !== []);

@unlink($csv);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
