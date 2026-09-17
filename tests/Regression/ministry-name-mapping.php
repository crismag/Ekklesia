#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Which ministry the workbook meant.
 *
 * Two faults made a Scarborough import look like a mapping problem when only
 * one of them was, and both are pinned here.
 *
 * The catalog's canonical name is not always what the church called the group.
 * "MTE" is canonical for a group recorded as "More than Enough"; the lookup
 * used the canonical only, so every member of that ministry — and of Field
 * Ministry — was reported unmatched however the sheet spelled them.
 *
 * And an unrecognised name was reported in a sentence and then forgotten, so
 * the same name was ignored on the next import and the one after that. A
 * decision now outranks the catalog and survives.
 */

$root = dirname(__DIR__, 2) . '/church_portal';
if (!is_dir($root)) {
    $root = dirname(__DIR__, 2);
}
require_once $root . '/app/Services/MinistryCatalog.php';
require_once $root . '/app/Services/MinistryNameMap.php';
require_once $root . '/app/Services/MemberMinistryAssigner.php';

use App\Services\MemberMinistryAssigner;
use App\Services\MinistryCatalog;
use App\Services\MinistryNameMap;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}

// A church whose group names differ from the catalog's canonical names in
// exactly the way the real one does.
$groups = [
    1 => 'Psalmists', 2 => 'More than Enough', 3 => 'Facilities', 4 => 'Victuals',
    6 => 'Guest Services', 9 => 'Creatives', 10 => 'Field Ministry', 11 => 'Gift and Arrows',
];
$catalog = MinistryCatalog::fromFile($root . '/config/ministry-catalog.json');
$list = static fn (): array => array_map(
    static fn (int $id, string $n): array => ['ministry_id' => $id, 'name' => $n],
    array_keys($groups),
    array_values($groups),
);
$tmp = sys_get_temp_dir() . '/ministry-name-map-test-' . getmypid() . '.json';
@unlink($tmp);
$map = MinistryNameMap::fromFile($tmp);
$make = static fn (?MinistryNameMap $m = null): MemberMinistryAssigner
    => new MemberMinistryAssigner($catalog, $list, $m);
$names = static fn (array $ids): array => array_map(static fn (int $i): string => $groups[$i] ?? ('#' . $i), $ids);

echo "The church may rename a ministry; the sheet keeps its own words\n";
// Scarborough renamed "Field Ministry" to "Field" and added "Dance". Renaming
// fixed Field by itself — the group now equals the catalog's canonical. Adding
// Dance did the opposite: the group is "Dance" while the canonical is "Dance
// Ministry", so that one only resolves through the alias lookup. Both spellings
// have to keep working either way, because a workbook is not reissued when a
// ministry is renamed.
$renamed = [
    1 => 'Psalmists', 2 => 'More than Enough', 3 => 'Facilities', 4 => 'Victuals',
    6 => 'Guest Services', 9 => 'Creatives', 10 => 'Field', 11 => 'Gift and Arrows',
    17 => 'Dance',
];
$afterRename = new MemberMinistryAssigner($catalog, static fn (): array => array_map(
    static fn (int $id, string $n): array => ['ministry_id' => $id, 'name' => $n],
    array_keys($renamed),
    array_values($renamed),
));
$rn = static fn (array $ids): array => array_map(static fn (int $i): string => $renamed[$i] ?? ('#' . $i), $ids);
foreach ([['Field', 'Field'], ['Field Ministry', 'Field'], ['Dance', 'Dance'], ['Dance Ministry', 'Dance']] as [$cell, $want]) {
    $res = $afterRename->resolve($cell);
    check('"' . $cell . '" reaches ' . $want, $rn($res['ids']) === [$want], json_encode($res));
}
$res = $afterRename->resolve('MTE');
check('and MTE still reaches More than Enough, which was not renamed',
    $rn($res['ids']) === ['More than Enough']);

echo "\nA canonical name finds its group under any name the catalog knows\n";
$r = $make()->resolve('MTE');
check('MTE reaches More than Enough', $names($r['ids']) === ['More than Enough'], json_encode($r));
check('and is not reported as unmatched', $r['unmatched'] === []);
$r = $make()->resolve('More Than Enough');
check('so does the spelled-out form', $names($r['ids']) === ['More than Enough']);
$r = $make()->resolve('Field');
check('Field reaches Field Ministry', $names($r['ids']) === ['Field Ministry'], json_encode($r));
$r = $make()->resolve('Psalmist');
check('a plural group still matches its singular canonical', $names($r['ids']) === ['Psalmists']);
$r = $make()->resolve('G&A');
check('an abbreviation alias still works', $names($r['ids']) === ['Gift and Arrows']);

echo "\nA name nobody has claimed is reported, not guessed at\n";
$r = $make()->resolve('Dance Ministry');
check('an unknown ministry matches nothing', $r['ids'] === []);
check('and is named so somebody can decide', $r['unmatched'] === ['Dance Ministry']);
$r = $make()->resolve('Dance Ministry, Victuals');
check('the rest of the cell is still honoured', $names($r['ids']) === ['Victuals']);

echo "\nWhat matched is reported too, not only what failed\n";
$r = $make()->resolve('Victuals, Psalmist');
check('matched names come back with their ministry',
    isset($r['matched']['Victuals'], $r['matched']['Psalmist']), json_encode($r['matched']));
check('a review can therefore show where a name went, not only that it went',
    $r['matched']['Victuals'] === 4);

echo "\nAn administrator's decision outranks the catalog and survives\n";
$map->decide('Dance Ministry', 9);
$r = $make($map)->resolve('Dance Ministry');
check('a decided name maps where they said', $names($r['ids']) === ['Creatives']);
check('and stops being reported', $r['unmatched'] === []);
$map->decide('Victuals', 9);
$r = $make($map)->resolve('Victuals');
check('a decision overrules an automatic match', $names($r['ids']) === ['Creatives']);
$map->forget('Victuals');
$r = $make($map)->resolve('Victuals');
check('clearing it hands the name back to the catalog', $names($r['ids']) === ['Victuals']);

echo "\n\"Not a ministry\" means stop asking\n";
$map->decide('Worship Team', 0);
$r = $make($map)->resolve('Worship Team, Victuals');
check('an ignored name adds no ministry', $names($r['ids']) === ['Victuals']);
check('and is not reported again', $r['unmatched'] === [], json_encode($r['unmatched']));

echo "\nOne decision covers the spellings of one name\n";
check('"G & A", "g&a" and "G and A" are one key',
    MinistryNameMap::key('G & A') === MinistryNameMap::key('g&a')
    && MinistryNameMap::key('g&a') === MinistryNameMap::key('G and A'));
check('but two different names stay two', MinistryNameMap::key('Dance') !== MinistryNameMap::key('Dance Ministry'));

echo "\nThe review table shows the work, worst first\n";
$map2 = MinistryNameMap::fromFile($tmp);
$map2->observe('Dance Ministry', 12, null, 'unmatched');
$map2->observe('Victuals', 30, 4, 'matched');
$map2->observe('Worship Team', 5, null, 'unmatched');
$rows = $map2->rows();
check('names needing a decision come first', $rows[0]['needsDecision'] === true, json_encode(array_column($rows, 'raw')));
check('and among those the widest impact leads', $rows[0]['raw'] === 'Dance Ministry');
check('a matched name is listed too, so the mapping can be checked',
    in_array('Victuals', array_column($rows, 'raw'), true));
check('the pending count matches the rows that need one', $map2->pendingCount() === 2);
$map2->decide('Dance Ministry', 9);
$map2->decide('Worship Team', 0);
check('deciding clears the backlog', $map2->pendingCount() === 0);

echo "\nThe import writes what it saw where somebody can act on it\n";
$svc = (string) file_get_contents($root . '/app/Services/MemberCampusImportService.php');
check('observations are recorded for matched and unmatched alike',
    str_contains($svc, "\$this->nameMap->observe((string) \$name, (int) \$info['count'], (int) \$info['id'], 'matched')")
    && str_contains($svc, "\$this->nameMap->observe((string) \$name, (int) \$count, null, 'unmatched')"));
check('and the warning points at the form rather than ending the conversation',
    str_contains($svc, 'Say what they mean under'));

echo "\nA created person gets their ministries on the run that creates them\n";
check('the id save() returns is kept', str_contains($svc, "\$plan['create'][\$i]['person_id'] = \$newId;"));
check('so assignMinistries no longer skips every new row',
    !str_contains($svc, 'their memberships resolve on the next import'));

@unlink($tmp);
printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
