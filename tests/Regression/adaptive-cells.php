#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Adaptive calendar cells: does the writing use the room the day is not using?
 *
 * The defect this guards against is a design one rather than a crash. A single
 * celebrant on an otherwise empty day used to print as a 7.4pt line in the top
 * corner of an inch-and-a-quarter square — correct, and unreadable from the
 * other side of a hall. The fix was to let each cell pick a *named tier*, so
 * the assertions here are about that choice: it must get looser when a day is
 * quiet, tighter when a day is busy, and it must never lose an entry to make
 * either of those true.
 *
 * The other half is a regression guarantee. Compact is the calendar this church
 * already prints; a cell at compact must still fit exactly what it fits today,
 * because a great many noticeboards depend on it.
 */

$root = dirname(__DIR__, 2) . '/church_portal';
if (!is_dir($root)) {
    $root = dirname(__DIR__, 2);
}
require_once $root . '/app/Services/Calendar/DisplayName.php';
require_once $root . '/app/Services/Calendar/CellPlan.php';
require_once $root . '/app/Services/Calendar/MemberTypeStyle.php';
require_once $root . '/app/Services/Calendar/EntryPresentation.php';
require_once $root . '/app/Services/Calendar/PrintDensity.php';

use App\Services\Calendar\CellPlan;
use App\Services\Calendar\EntryPresentation;
use App\Services\Calendar\PrintDensity;

$passed = 0;
$failed = 0;
$group = static function (string $name): void { echo "\n" . $name . "\n"; };
$ok = static function (string $what, bool $cond) use (&$passed, &$failed): void {
    if ($cond) { $passed++; echo "  ok  " . $what . "\n"; }
    else { $failed++; echo "  FAIL " . $what . "\n"; }
};

/**
 * A landscape Letter month, which is what the church actually prints: six week
 * rows across 5.52in of grid, seven columns across 8.95in of page.
 */
$dm = PrintDensity::metrics('standard');
$rowHeightIn = 5.521 / 6;
$usableIn = $rowHeightIn - $dm['cellPad'] - $dm['numHeight'];
$widthIn = (8.953 / 7) - (11.0 / 72.0);

$names = static fn (string ...$n): array => array_map(
    static fn (string $s): array => ['primary' => $s, 'secondary' => ''],
    $n,
);

$group('A quiet day uses the room it has');
$one = CellPlan::plan($names('Alfrea Perez'), 'auto', $usableIn, $widthIn);
$ok('one celebrant is not set at 7.4pt', $one['tier'] !== 'compact');
$ok('one celebrant is readable', $one['tier'] === 'readable');
$ok('and the cell is marked sparse, so it can compose rather than list', $one['state'] === 'sparse');
$ok('nothing is hidden', $one['hidden'] === 0 && $one['shown'] === 1);

$showcase = CellPlan::plan($names('Alfrea Perez'), 'showcase', $usableIn, $widthIn);
$ok('Showcase reaches the top of the ladder for a single name', $showcase['tier'] === 'showcase');
$ok('and still hides nothing', $showcase['hidden'] === 0);

$group('The tier tightens as the day fills, and never skips a step backwards');
$seen = [];
foreach ([1, 2, 3, 4, 5, 6, 7, 9] as $n) {
    $items = $names(...array_fill(0, $n, 'Dani Martinez'));
    $plan = CellPlan::plan($items, 'auto', $usableIn, $widthIn);
    $seen[$n] = $plan;
}
$rank = static fn (string $t): int => (int) array_search($t, CellPlan::TIERS, true);
$monotonic = true;
$prev = -1;
foreach ($seen as $plan) {
    if ($rank($plan['tier']) < $prev) { $monotonic = false; }
    $prev = $rank($plan['tier']);
}
$ok('more entries never buy a looser tier', $monotonic);
$ok('two entries still get room', $seen[2]['tier'] === 'readable' || $seen[2]['tier'] === 'normal');
$ok('a four-entry day is denser than a two-entry one', $rank($seen[4]['tier']) >= $rank($seen[2]['tier']));
$ok('a nine-entry day is compact', $seen[9]['tier'] === 'compact');

$group('Nothing is ever lost silently');
foreach ($seen as $n => $plan) {
    if ($plan['shown'] + $plan['hidden'] !== $n) {
        $ok('every entry is either shown or counted (' . $n . ')', false);
    }
}
$ok('every entry is either shown or counted', array_reduce(
    array_keys($seen),
    static fn (bool $c, int $n): bool => $c && $seen[$n]['shown'] + $seen[$n]['hidden'] === $n,
    true,
));
$ok('a day that overflows says so in its state', $seen[9]['hidden'] === 0 || $seen[9]['state'] === 'overflow');
$ok('an overflowing cell still shows at least two entries', $seen[9]['shown'] >= 2);

$group('Compact is the calendar this church already prints');
$legacy = CellPlan::plan($names(...array_fill(0, 9, 'Sunday Service')), 'compact', $usableIn, $widthIn);
$ok('compact never leaves its own tier', $legacy['tier'] === 'compact');
$ok('compact fits what PrintDensity says it fits',
    $legacy['shown'] === PrintDensity::perDay('standard', $rowHeightIn, 1.0));
$sparseAtCompact = CellPlan::plan($names('Alfrea Perez'), 'compact', $usableIn, $widthIn);
$ok('and a quiet day at compact is unchanged too', $sparseAtCompact['tier'] === 'compact');

$group('A long name is wrapped, not shrunk and not clipped');
$long = 'Clarisse Daise Manzo Bayeta';
$ok('a long name takes two lines at readable', CellPlan::linesFor($long, 'readable', $widthIn) === 2);
$ok('a short one takes one', CellPlan::linesFor('Ram B.', 'readable', $widthIn) === 1);
$ok('no tier ever asks for more lines than it allows',
    CellPlan::linesFor(str_repeat('x', 400), 'compact', $widthIn) === 1
    && CellPlan::linesFor(str_repeat('x', 400), 'showcase', $widthIn) === 3);
$twoLong = CellPlan::plan($names($long, 'Bartholomew Featherstonehaugh'), 'auto', $usableIn, $widthIn);
$ok('two long names still both appear', $twoLong['shown'] === 2 && $twoLong['hidden'] === 0);
$ok('and they are given a tier that can wrap them', $twoLong['tier'] !== 'compact');

$group('Type size is never the thing that gives way');
foreach (CellPlan::TIERS as $tier) {
    $ok('tier "' . $tier . '" is at least 7.4pt', CellPlan::tier($tier)['font'] >= 7.4);
}

$group('An entry is projected into a hierarchy, not printed as a raw title');
$birthday = ['title' => 'Ramon Bayeta (29)', 'kind' => 'birth', 'all_day' => true, 'time' => null];
$p = EntryPresentation::of($birthday, false, true);
// A feed with no name parts: the title is the last resort, and the age still goes.
$ok('without name parts, the title is the headline with the age taken off', $p['primary'] === 'Ramon Bayeta');
$ok('the age is not printed anywhere', $p['secondary'] === '' && !str_contains(implode(' ', [$p['primary'], $p['meta']]), '29'));
$ok('and it is marked as a person', $p['person'] === true);
$short = EntryPresentation::of($birthday, true, false);
$ok('a last initial can still be asked for', $short['primary'] === 'Ramon B.');

$withParts = $birthday + ['person' => ['first' => 'Ramon', 'preferred' => '', 'last' => 'Bayeta', 'memberType' => 'Trailblazer']];
$ok('with name parts, the first name alone', EntryPresentation::of($withParts, false, true)['primary'] === 'Ramon');
$ok('with the last initial when asked', EntryPresentation::of($withParts, true, true)['primary'] === 'Ramon B.');
$preferred = ['title' => 'Jessie James Quill (14)', 'kind' => 'birth', 'all_day' => true,
    'person' => ['first' => 'Jessie James', 'preferred' => 'JJ', 'last' => 'Quill', 'memberType' => 'Radical']];
$ok('the preferred name wins over the first name', EntryPresentation::of($preferred, false, true)['primary'] === 'JJ');
$ok('compact (unsplit) rows print no age either', EntryPresentation::of($preferred, false, false, false)['primary'] === 'JJ'
    && EntryPresentation::of($preferred, false, false, false)['secondary'] === '');
$ok('the member type travels with the entry', EntryPresentation::of($preferred, false, true)['group'] === 'radical');

$event = ['title' => 'Leadership Meeting', 'kind' => 'event', 'all_day' => false,
          'time' => '19:00', 'location' => 'Fellowship Hall'];
$pe = EntryPresentation::of($event, true, true);
$ok('an event keeps its own title as the headline', $pe['primary'] === 'Leadership Meeting');
$ok('the time is secondary', $pe['secondary'] === '19:00');
$ok('the place is third', $pe['meta'] === 'Fellowship Hall');
$ok('and an event is never shortened like a person', $pe['person'] === false);

$group('Nothing invents information the loader did not supply');
$noAge = EntryPresentation::of(['title' => 'Ramon Bayeta', 'kind' => 'birth', 'all_day' => true], false, true);
$ok('a birthday with no age displayed still has none', $noAge['secondary'] === '');
$ok('an unknown member type is not guessed', EntryPresentation::of(['title' => 'A', 'kind' => 'birth',
    'person' => ['first' => 'A', 'preferred' => '', 'last' => 'B', 'memberType' => 'Seniors']], false, true)['group'] === null);

echo "\nPassed: " . $passed . '; failed: ' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
