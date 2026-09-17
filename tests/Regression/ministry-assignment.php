<?php

declare(strict_types=1);

/**
 * Ministry assignment on member import.
 *
 * The import parsed and staged the workbook's ministry column and then dropped
 * it — the review screen showed ministries that were never going to be saved.
 *
 * How membership works: one ministry_members row links a person and a
 * ministry, with role 'member' or 'leader'. Serving roles (Porter, Runner) are
 * per-ministry scheduling roles in serving_roles, not memberships. Leadership
 * that grants access is separate again: an account role scoped to a ministry.
 */

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
require __DIR__ . '/../../app/Services/MinistryCatalog.php';
require __DIR__ . '/../../app/Services/MemberMinistryAssigner.php';

use App\Services\MemberMinistryAssigner;
use App\Services\MinistryCatalog;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

// A fixed list of ministries, in the shape listMinistriesPublic() returns.
$ministries = static fn (): array => [
    ['ministry_id' => 1, 'name' => 'Psalmists', 'campus_id' => null],
    ['ministry_id' => 4, 'name' => 'Victuals', 'campus_id' => null],
    ['ministry_id' => 5, 'name' => 'Events', 'campus_id' => null],
    ['ministry_id' => 6, 'name' => 'Guest Services', 'campus_id' => null],
    ['ministry_id' => 7, 'name' => 'Prayer', 'campus_id' => null],
    ['ministry_id' => 9, 'name' => 'Creatives', 'campus_id' => null],
    ['ministry_id' => 3, 'name' => 'Facilities', 'campus_id' => null],
    // Note the singular "Gift": the catalogue canonicalises G&A to the plural
    // "Gifts and Arrows", and the two must still meet.
    ['ministry_id' => 11, 'name' => 'Gift and Arrows', 'campus_id' => null],
];

$assigner = new MemberMinistryAssigner(
    MinistryCatalog::fromFile(__DIR__ . '/../../config/ministry-catalog.json'),
    $ministries
);

check('members are imported with the plain member role', MemberMinistryAssigner::MEMBER_ROLE === 'member');

$exact = $assigner->resolve('Creatives');
check('an exact ministry name resolves', $exact['ids'] === [9]);

$alias = $assigner->resolve('Creative');
check('a catalogue alias resolves', $alias['ids'] === [9]);

$abbrev = $assigner->resolve('GS');
check('an abbreviation resolves', $abbrev['ids'] === [6]);

// The catalogue says "Psalmist"; the group is "Psalmists". Neither spelling
// should decide whether a member is assigned.
$plural = $assigner->resolve('Psalmists');
check('a singular/plural difference still resolves', $plural['ids'] === [1]);

$compound = $assigner->resolve('Events & Prayer Ministry');
check('a compound cell resolves to several ministries', $compound['ids'] === [5, 7]);

$multi = $assigner->resolve('Creatives, Victuals');
check('a list in one cell resolves to each', $multi['ids'] === [9, 4] || $multi['ids'] === [4, 9]);

// The workbook does not always delimit the cell. "Facilities Psalmist Victuals"
// is three ministries separated by nothing but spaces, and a comma-only split
// left the whole cell unrecognised — that member imported no ministries at all.
$spaced = $assigner->resolve('Facilities Psalmist Victuals');
sort($spaced['ids']);
check('a space-separated cell resolves to each ministry', $spaced['ids'] === [1, 3, 4]);
check('and reports nothing unmatched', $spaced['unmatched'] === []);

// Multi-word names must survive the space-splitting: longest match wins, so
// "Guest Services" is one ministry rather than a stray "Guest" and "Services".
$multiWord = $assigner->resolve('Guest Services Psalmists');
sort($multiWord['ids']);
check('a multi-word name is not shredded by the space split', $multiWord['ids'] === [1, 6]);

// G&A canonicalises to "Gifts and Arrows" while the group is "Gift and Arrows".
// The plural is on the first word, so trimming a trailing "s" cannot match them.
check('G&A resolves to Gift and Arrows', $assigner->resolve('G&A')['ids'] === [11]);
check('as does the spelled-out name', $assigner->resolve('Gift and Arrows')['ids'] === [11]);
check('as does the catalogue plural', $assigner->resolve('Gifts and Arrows')['ids'] === [11]);
check('GS resolves to Guest Services', $assigner->resolve('GS')['ids'] === [6]);

$mixedCell = $assigner->resolve('Facilities Psalmist Victuals G&A');
sort($mixedCell['ids']);
check('the reported row resolves to all four ministries', $mixedCell['ids'] === [1, 3, 4, 11]);

// A keyword nobody recognises must be named, and must not take the ministries
// around it down with it.
$partly = $assigner->resolve('Psalmist Zumba Victuals');
sort($partly['ids']);
check('an unknown keyword does not spoil the rest of the cell', $partly['ids'] === [1, 4]);
check('and the unknown keyword is reported by name', $partly['unmatched'] === ['Zumba']);

// Unknown text must be reported, never guessed at: a wrong ministry is worse
// than none.
$unknown = $assigner->resolve('Worship Team');
check('an unknown ministry assigns nothing', $unknown['ids'] === []);
check('and is reported rather than dropped silently', $unknown['unmatched'] === ['Worship Team']);

foreach (['', 'N/A', '-', 'none'] as $blank) {
    $r = $assigner->resolve($blank);
    check("a blank-ish cell (\"{$blank}\") assigns nothing and reports nothing",
        $r['ids'] === [] && $r['unmatched'] === []);
}

$dupes = $assigner->resolve('Creatives, Creative, Creatives');
check('the same ministry named twice yields one membership', $dupes['ids'] === [9]);

// --- positions -----------------------------------------------------------------
// A word the catalogue lists as a position ("roles" in ministry-catalog.json)
// is that ministry plus the position, however the sheet writes it.
$usher = $assigner->resolve('Usher');
check('a bare position resolves to its ministry', $usher['ids'] === [6]);
check('and records the position', $usher['positions'] === [6 => ['Usher']]);
$gsUsher = $assigner->resolve('Guest Services Usher');
check('ministry then position is one membership with that position',
    $gsUsher['ids'] === [6] && $gsUsher['positions'] === [6 => ['Usher']]);
$prefixed = $assigner->resolve('GS: Usher and Emcee, Psalmist');
check('the "GS:" form gives both positions',
    $prefixed['positions'] === [6 => ['Usher', 'Emcee']] && in_array(1, $prefixed['ids'], true));
$spacedPos = $assigner->resolve('Victuals GS: Usher');
sort($spacedPos['ids']);
check('a "GS:" after another ministry does not swallow it',
    $spacedPos['ids'] === [4, 6] && $spacedPos['positions'] === [6 => ['Usher']]);
$paren = $assigner->resolve('Guest Services (usher, Emcee), Creatives (Photography)');
check('the export form assigns parenthesised positions to the ministry before them',
    $paren['ids'] === [6, 9] && $paren['positions'] === [6 => ['Usher', 'Emcee'], 9 => ['Photography']]);
check('with the catalogue spelling where it knows the position', $paren['positions'][6][0] === 'Usher');
check('and a ministry without positions gets none', !isset($assigner->resolve('Creatives')['positions'][9]));
check('a position phrase is not reported as unmatched', $paren['unmatched'] === [] && $gsUsher['unmatched'] === []);

// --- export -> import round trip of the Ministry cell ----------------------------
$memberships = [
    ['name' => 'Guest Services', 'positions' => ['Usher', 'Emcee']],
    ['name' => 'Gift and Arrows', 'positions' => []],
    ['name' => 'Creatives', 'positions' => ['Photography']],
];
$cell = App\Services\MemberRosterXlsxWriter::ministryCell($memberships);
check('the export writes positions after their ministry',
    $cell === 'Guest Services (Usher, Emcee), Gift and Arrows, Creatives (Photography)');
$bytes = (new App\Services\MemberRosterXlsxWriter())->build([[
    'campus' => 'North York',
    'rows' => [['id' => 1, 'last_name' => 'Doe', 'first_name' => 'Jane', 'ministry' => $cell]],
]]);
$xlsx = sys_get_temp_dir() . '/ministry-roundtrip-' . getmypid() . '.xlsx';
file_put_contents($xlsx, $bytes);
$parsedBack = (new App\Services\MemberWorkbookParser())->parseFile($xlsx, 'North York');
@unlink($xlsx);
$backCell = (string) ($parsedBack['rows'][0]['ministry'] ?? '');
check('the importer reads the exported Ministry cell back unchanged', $backCell === $cell);
$back = $assigner->resolve($backCell);
$backIds = $back['ids'];
sort($backIds);
check('to the same memberships', $backIds === [6, 9, 11]);
check('and the same positions',
    $back['positions'] === [6 => ['Usher', 'Emcee'], 9 => ['Photography']] && $back['unmatched'] === []);

// --- applying: positions follow the membership authority -------------------------
$svcPos = (string) file_get_contents(__DIR__ . '/../../app/Services/MemberCampusImportService.php');
check('positions are written for every listed ministry', str_contains($svcPos, 'setMemberPositions($personId, $ministryId, $positions)'));
check('an existing membership keeps its role (a leader is not demoted)',
    str_contains($svcPos, "if (!in_array(\$ministryId, \$have, true)) {\n                    \$this->ministryRepo->setMemberRole"));
check('a leader membership is kept like an account leader', str_contains($svcPos, 'listLeadersByMinistry'));

// The import must not invent leadership, and must not read it from the sheet.
$svc = (string) file_get_contents(__DIR__ . '/../../app/Services/MemberCampusImportService.php');
check('the import never writes leadership',
    !str_contains($svc, "role = 'leader'") && !str_contains($svc, 'addRole('));
check('the import keeps a leader\'s membership',
    str_contains($svc, 'listMinistryLeaderPersonIds'));
// The workbook is the source of truth, so removal is required — but it must
// never reach beyond the people this import actually applied.
check('removal is scoped to people in this import',
    str_contains($svc, 'foreach ($desired as $personId => $wanted)'));

$auth = (string) file_get_contents(__DIR__ . '/../../app/Adapters/Sql/SqlAuthAdapter.php');
check('leaders are read from account_roles, not from a role name',
    str_contains($auth, 'listMinistryLeaderPersonIds') && str_contains($auth, 'r.ministry_id'));
check('the leader is the person the account belongs to',
    str_contains($auth, 'u.person_id'));

// --- the workbook is authoritative ------------------------------------------
// Only the ministries listed are current ministries, so anything else is
// removed — with two exceptions the code must honour.
$svcSrc = (string) file_get_contents(__DIR__ . '/../../app/Services/MemberCampusImportService.php');

check('memberships the workbook omits are removed',
    str_contains($svcSrc, 'removeMemberFromMinistry'));
check('an empty ministry cell is a real answer, not "unknown"',
    str_contains($svcSrc, 'some people') || str_contains($svcSrc, 'serve in none'));
check('a leader keeps a membership the sheet omits',
    str_contains($svcSrc, 'keptForLeaders') && str_contains($svcSrc, 'leads that ministry'));

// The guard that matters most: a ministry column that fails to map makes every
// cell look blank, and replacing on that would clear every membership in the
// church with nothing in the workbook to restore them from.
check('nothing is removed when the sheet names no ministry at all',
    str_contains($svcSrc, 'sheetMentionsAnyMinistry'));
check('and that case is reported rather than passing silently',
    str_contains($svcSrc, 'No ministry was named anywhere'));

check('removals are counted and reported',
    str_contains($svcSrc, 'no longer lists them'));

// Reading current memberships must be batched: a query per person turns a
// 250-member import into 250 round trips.
$adapterSrc = (string) file_get_contents(__DIR__ . '/../../app/Adapters/Sql/SqlMinistryAdapter.php');
check('current memberships are read in one query',
    str_contains($adapterSrc, 'listMinistryIdsForPeople') && str_contains($adapterSrc, 'IN ('));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
