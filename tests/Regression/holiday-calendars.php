<?php

declare(strict_types=1);

/**
 * Public holiday calendars.
 *
 * Fetched rather than computed. Deriving Ontario's list from its rules looked
 * straightforward and produced two holidays Ontario does not observe: Easter
 * Monday, which is for federal employees, and Remembrance Day, which is
 * statutory in nine other provinces. The rules also move — National Day for
 * Truth and Reconciliation did not exist before 2021 — so they are pulled from
 * a public source and cached.
 *
 * Cached rather than fetched at render time, because the calendar has to draw
 * with no network and a year-old holiday list is still a correct holiday list.
 */

require_once __DIR__ . '/../../app/Services/HolidayCalendars.php';

use App\Services\HolidayCalendars;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$shipped = HolidayCalendars::fromFile(__DIR__ . '/../../config/holidays.json');

// --- what ships -------------------------------------------------------------

check('holiday calendars are cached', $shipped->all() !== []);
$ids = array_column($shipped->all(), 'id');
check('Canada/Ontario is one of them', in_array('ca-on', $ids, true), implode(',', $ids));

$thisYear = (int) date('Y');
$ontario = $shipped->itemsBetween(
    new DateTimeImmutable($thisYear . '-01-01'),
    new DateTimeImmutable($thisYear . '-12-31'),
);
$ontarioNames = array_map(
    static fn (array $i): string => $i['title'],
    array_values(array_filter($ontario, static fn (array $i): bool => $i['source'] === 'holidays:ca-on')),
);
foreach (['New Year\'s Day', 'Family Day', 'Good Friday', 'Victoria Day', 'Canada Day', 'Labour Day', 'Thanksgiving', 'Christmas Day'] as $expected) {
    check('Ontario has ' . $expected, in_array($expected, $ontarioNames, true), implode(', ', $ontarioNames));
}

// The two that a rules-based version got wrong. Both are real holidays
// somewhere in Canada and neither is statutory in Ontario.
check('Ontario does not list Easter Monday', !in_array('Easter Monday', $ontarioNames, true), implode(', ', $ontarioNames));
check('Ontario does not list Remembrance Day', !in_array('Remembrance Day', $ontarioNames, true), implode(', ', $ontarioNames));

// --- layers -----------------------------------------------------------------

$layers = $shipped->layers();
check('every enabled calendar gets a layer', count($layers) === count($shipped->enabled()));
foreach ($layers as $layer) {
    check('the layer is filterable: ' . $layer['source'],
        str_starts_with($layer['source'], 'holidays:') && $layer['group'] === 'holidays' && $layer['kind'] === 'holiday');
}

// A calendar switched off must produce neither a chip nor an item. A chip for
// something the feed will never contain teaches people the filter is broken.
$off = new HolidayCalendars([
    ['id' => 'ca-on', 'label' => 'Canada', 'enabled' => true, 'holidays' => [['date' => '2026-07-01', 'name' => 'Canada Day', 'national' => true]]],
    ['id' => 'ph', 'label' => 'Philippines', 'enabled' => false, 'holidays' => [['date' => '2026-06-12', 'name' => 'Araw ng Kalayaan', 'national' => true]]],
]);
check('a calendar switched off has no chip', count($off->layers()) === 1, json_encode($off->layers()));
$year = $off->itemsBetween(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));
check('and contributes no items', count($year) === 1, json_encode(array_column($year, 'title')));
check('while the enabled one still does', ($year[0]['title'] ?? '') === 'Canada Day');

// --- the window -------------------------------------------------------------

$one = new HolidayCalendars([['id' => 'x', 'label' => 'X', 'enabled' => true, 'holidays' => [
    ['date' => '2026-01-01', 'name' => 'Start', 'national' => true],
    ['date' => '2026-06-15', 'name' => 'Middle', 'national' => true],
    ['date' => '2026-12-31', 'name' => 'End', 'national' => true],
]]]);
check('both ends of the window are included',
    count($one->itemsBetween(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'))) === 3);
check('a narrow window takes only what falls in it',
    count($one->itemsBetween(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-30'))) === 1);
check('a window with nothing in it yields nothing',
    $one->itemsBetween(new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-03-31')) === []);
check('a window given backwards is read the right way round',
    count($one->itemsBetween(new DateTimeImmutable('2026-12-31'), new DateTimeImmutable('2026-01-01'))) === 3);

// --- the cache running out --------------------------------------------------

// A calendar showing no holidays looks identical whether the year is quiet or
// the cache simply stops, so it has to be able to say which.
check('a cache still in range reports nothing exhausted',
    $one->exhausted(new DateTimeImmutable('2026-06-01')) === []);
$out = $one->exhausted(new DateTimeImmutable('2027-06-01'));
check('a cache that has run out says so', count($out) === 1, json_encode($out));
check('and says how far it reached', ($out[0]['through'] ?? '') === '2026-12-31');
check('the shipped cache still covers today', $shipped->exhausted(new DateTimeImmutable()) === []);

// --- bad input --------------------------------------------------------------

check('a missing file yields no calendars',
    HolidayCalendars::fromFile(__DIR__ . '/nope.json')->all() === []);
$junk = new HolidayCalendars([
    ['id' => '', 'label' => 'No id', 'holidays' => []],
    ['id' => 'ok', 'label' => '', 'holidays' => []],
    ['id' => 'good', 'label' => 'Good', 'enabled' => true, 'holidays' => [
        ['date' => 'not-a-date', 'name' => 'Nonsense', 'national' => true],
        ['date' => '2026-05-05', 'name' => '', 'national' => true],
        ['date' => '2026-05-06', 'name' => 'Real', 'national' => true],
    ]],
]);
check('calendars with no id or label are dropped', count($junk->all()) === 1, json_encode(array_column($junk->all(), 'id')));
check('and unusable dates and names with them',
    count($junk->itemsBetween(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'))) === 1);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
