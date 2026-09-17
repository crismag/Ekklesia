<?php

declare(strict_types=1);

/**
 * What the calendar shows, worked out once for every renderer.
 *
 * This grouping used to happen in the browser, which left printing only one
 * option — CSS over the calendar's own DOM — and that is what makes printed
 * calendars look like screenshots. Moving it here is what lets a month grid, an
 * agenda and a ministry planner be three drawings of one set of facts.
 *
 * It is pure on purpose: given items and a range it returns a shape, with no
 * database, no request and no clock beyond the "today" it is handed. A print
 * job for last August has to render in December exactly as it did in August.
 */

require_once __DIR__ . '/../../app/Services/Calendar/CalendarViewModel.php';

use App\Services\Calendar\CalendarViewModel;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$vm = new CalendarViewModel();
$aug = static fn (string $d): DateTimeImmutable => new DateTimeImmutable('2026-08-' . $d);
$item = static fn (string $date, string $title, array $extra = []): array
    => $extra + ['date' => $date, 'title' => $title, 'kind' => 'event', 'source' => 'events:general'];

// --- shaping ----------------------------------------------------------------

$model = $vm->build([
    $item('2026-08-02', 'Communion Sunday', ['starts_at' => '2026-08-02 10:00:00']),
    $item('2026-08-03', 'Civic Holiday', ['source' => 'holidays:ca-on']),
    $item('2026-08-04', 'Instruments Training', ['starts_at' => '2026-08-04 17:30:00', 'ends_at' => '2026-08-04 19:00:00']),
], $aug('01'), $aug('31'), ['today' => '2026-08-04']);

check('the range names itself', $model['title'] === 'August 2026', $model['title']);
check('every day of the month is present', count($model['days']) === 31, (string) count($model['days']));
check('the month grid is whole weeks', count($model['weeks']) === 6, (string) count($model['weeks']));
foreach ($model['weeks'] as $i => $week) {
    check('week ' . ($i + 1) . ' has seven days', count($week) === 7);
}
check('one month yields one month', count($model['months']) === 1);
check('today is marked',
    count(array_filter($model['days'], static fn (array $d): bool => $d['is_today'])) === 1);

// --- times ------------------------------------------------------------------

$byTitle = [];
foreach ($model['entries'] as $entry) {
    $byTitle[$entry['title']] = $entry;
}
check('a timed entry keeps its time', ($byTitle['Communion Sunday']['time'] ?? '') === '10:00');
check('and is not all-day', $byTitle['Communion Sunday']['all_day'] === false);
check('an end time is kept when given', ($byTitle['Instruments Training']['end_time'] ?? '') === '19:00');
check('an entry with no time is all-day', $byTitle['Civic Holiday']['all_day'] === true);

// Midnight to midnight is how an all-day event is stored, not a one-minute
// event at the stroke of twelve.
$midnight = $vm->build([$item('2026-08-05', 'All Day Thing', [
    'starts_at' => '2026-08-05 00:00:00', 'ends_at' => '2026-08-05 00:00:00',
])], $aug('01'), $aug('31'));
check('midnight to midnight reads as all-day', $midnight['entries'][0]['all_day'] === true, json_encode($midnight['entries'][0]));

// --- ordering ---------------------------------------------------------------

$ordered = $vm->build([
    $item('2026-08-09', 'Evening', ['starts_at' => '2026-08-09 18:00:00']),
    $item('2026-08-09', 'Morning', ['starts_at' => '2026-08-09 08:00:00']),
    $item('2026-08-09', 'All day thing'),
], $aug('01'), $aug('31'));
check('a day reads top to bottom: all-day, then by time',
    array_column($ordered['entries'], 'title') === ['All day thing', 'Morning', 'Evening'],
    implode(' | ', array_column($ordered['entries'], 'title')));

// --- duplicates -------------------------------------------------------------

// Loaders overlap by design; a printed calendar listing a service twice is
// worse than one that misses it.
$dupes = $vm->build([
    $item('2026-08-02', 'Worship Service', ['starts_at' => '2026-08-02 10:00:00']),
    $item('2026-08-02', 'Worship Service', ['starts_at' => '2026-08-02 10:00:00']),
], $aug('01'), $aug('31'));
check('the same thing arriving twice appears once', count($dupes['entries']) === 1);

$sameName = $vm->build([
    $item('2026-08-02', 'Worship Service', ['source' => 'events:general']),
    $item('2026-08-02', 'Worship Service', ['source' => 'rosters']),
], $aug('01'), $aug('31'));
check('but two sources saying it are two entries', count($sameName['entries']) === 2);

// --- the window -------------------------------------------------------------

$outside = $vm->build([
    $item('2026-07-31', 'Before'), $item('2026-08-15', 'Inside'), $item('2026-09-01', 'After'),
], $aug('01'), $aug('31'));
check('entries outside the range are dropped',
    array_column($outside['entries'], 'title') === ['Inside'],
    implode(',', array_column($outside['entries'], 'title')));
check('a range given backwards is read the right way round',
    count($vm->build([$item('2026-08-15', 'Inside')], $aug('31'), $aug('01'))['entries']) === 1);

// --- filtering by source ----------------------------------------------------

$filtered = $vm->build([
    $item('2026-08-02', 'Service', ['source' => 'events:general']),
    $item('2026-08-03', 'Birthday', ['source' => 'birthdays']),
], $aug('01'), $aug('31'), ['sources' => ['events:general']]);
check('only the chosen sources survive',
    array_column($filtered['entries'], 'title') === ['Service'], json_encode($filtered['entries']));
// No sources chosen means no calendar, not every calendar.
check('choosing none yields none',
    $vm->build([$item('2026-08-02', 'Service')], $aug('01'), $aug('31'), ['sources' => []])['entries'] === []);

// --- grids over more than one month -----------------------------------------

$quarter = $vm->build([], new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-10-31'));
check('three months yield three grids', count($quarter['months']) === 3, (string) count($quarter['months']));
check('and the title spans them', $quarter['title'] === 'August – October 2026', $quarter['title']);
foreach ($quarter['months'] as $month) {
    check('each month grid is whole weeks: ' . $month['name'],
        count($month['weeks']) >= 4 && array_sum(array_map('count', $month['weeks'])) % 7 === 0);
}
check('a range crossing a year says so',
    $vm->build([], new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2027-02-28'))['title'] === 'Nov 2026 – Feb 2027');

// Padding days must be marked, so a renderer can grey them rather than
// pretending August has a 26th of July in it.
$padded = $vm->build([], $aug('01'), $aug('31'));
$firstWeek = $padded['weeks'][0];
check('leading days from the previous month are marked out of range',
    ($firstWeek[0]['in_range'] ?? true) === false, json_encode(array_column($firstWeek, 'date')));
check('and days in the month are marked in range', ($firstWeek[6]['in_range'] ?? false) === true);

// --- rubbish in ------------------------------------------------------------

$junk = $vm->build([
    ['title' => 'No date'],
    ['date' => 'not-a-date', 'title' => 'Bad date'],
    ['date' => '2026-08-10', 'title' => '   '],
    ['date' => '2026-08-11', 'title' => 'Fine'],
    ['starts_at' => '2026-08-12 09:00:00', 'title' => 'Date from the timestamp'],
], $aug('01'), $aug('31'));
check('unusable items are dropped and the rest survive',
    array_column($junk['entries'], 'title') === ['Fine', 'Date from the timestamp'],
    implode(' | ', array_column($junk['entries'], 'title')));

check('an empty calendar is still a calendar',
    $vm->build([], $aug('01'), $aug('31'))['counts']['total'] === 0);
check('counts are broken down by source',
    ($filtered['counts']['events:general'] ?? 0) === 1, json_encode($filtered['counts']));

// --- empty days -------------------------------------------------------------

// An agenda drops them; a grid cannot.
$sparse = $vm->build([$item('2026-08-15', 'Only thing')], $aug('01'), $aug('31'), ['includeEmptyDays' => false]);
check('an agenda can ask for only the days with something on', count($sparse['days']) === 1);
check('while the grid still gets every day', count($model['days']) === 31);


// ---------------------------------------------------------------------------
// The last day of a range is part of the range.
//
// It was not, and the three sources disagreed about it three different ways:
// the events SQL treated the end as an exclusive instant at midnight, so a
// window ending on the 30th lost every service on the 30th; birthdays were
// parsed with createFromFormat('Y-m-d', ...) without "!", which fills the clock
// from *now*, so they vanished from the last day at any hour except midnight;
// the holiday file was inclusive and right.
//
// The symptom reported was an end-of-month birthday visible on the calendar and
// missing from the printout of the same month.
echo "\nThe end of a range is inside it\n";
$onEnd = [
    ['kind' => 'event', 'source' => 'events:general', 'title' => 'Evening service',
     'date' => '2026-08-31', 'starts_at' => '2026-08-31 18:00:00', 'ends_at' => '2026-08-31 19:30:00'],
    ['kind' => 'birth', 'source' => 'birthdays', 'title' => 'Someone (30)', 'date' => '2026-08-31'],
];
$edge = (new CalendarViewModel())->build($onEnd, new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'), []);
check('an event on the last day is kept', count(array_filter(
    $edge['entries'], static fn (array $e): bool => $e['kind'] === 'event')) === 1);
check('a birthday on the last day is kept', count(array_filter(
    $edge['entries'], static fn (array $e): bool => $e['kind'] === 'birth')) === 1);
$lastDay = end($edge['days']);
check('and the last day of the grid is the end date', $lastDay['date'] === '2026-08-31', $lastDay['date']);
check('with both of them on it', count($lastDay['entries']) === 2, (string) count($lastDay['entries']));

// The first day matters just as much, and is easier to get right by accident.
$onStart = [['kind' => 'birth', 'source' => 'birthdays', 'title' => 'Someone (30)', 'date' => '2026-08-01']];
$edge2 = (new CalendarViewModel())->build($onStart, new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'), []);
check('an item on the first day is kept', count($edge2['entries']) === 1);

// A single-day range is the sharpest case: start and end are the same date, and
// an exclusive end makes it empty.
$oneDay = (new CalendarViewModel())->build($onEnd, new DateTimeImmutable('2026-08-31'), new DateTimeImmutable('2026-08-31'), []);
check('a one-day range is not empty', count($oneDay['entries']) === 2, (string) count($oneDay['entries']));
check('and has exactly one day in it', count($oneDay['days']) === 1);

// Nothing outside the window may leak in while fixing the end.
$outside = [
    ['kind' => 'birth', 'source' => 'birthdays', 'title' => 'Too late', 'date' => '2026-09-01'],
    ['kind' => 'birth', 'source' => 'birthdays', 'title' => 'Too early', 'date' => '2026-07-31'],
];
$clean = (new CalendarViewModel())->build($outside, new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'), []);
check('the day after the range is still outside it',
    !str_contains(json_encode($clean['entries']), 'Too late'));
check('and the day before', !str_contains(json_encode($clean['entries']), 'Too early'));

// The adapter is where the three sources were reconciled; assert the rules it
// now applies, since they cannot be exercised without a database.
echo "\nThe adapter normalises the window once, for every source\n";
$adapter = file_get_contents(__DIR__ . '/../../app/Adapters/Sql/SqlCalendarAdapter.php');
check('the start is pinned to midnight', str_contains($adapter, '$start = $start->setTime(0, 0, 0);'));
check('the end becomes the following midnight, so the last day is whole',
    str_contains($adapter, "\$end = \$end->setTime(0, 0, 0)->modify('+1 day');"));
check('annual dates are parsed without a clock',
    str_contains($adapter, "createFromFormat('!Y-m-d'"));
check('and no unanchored date parse remains',
    !preg_match("/createFromFormat\(\s*'Y-m-d'/", $adapter));
check('the annual comparison is half-open at the far end, matching the SQL',
    str_contains($adapter, '$parsed >= $end'));


// --- the day inspector's read model -----------------------------------------
//
// GET /api/calendar/day composes two feeds that already exist and joins them.
// The assertions that matter are about the join and the window, because both
// have bitten this codebase before: matching on title merges two services that
// share a name, and a half-open range asked for a single date returns nothing.

$controller = (string) file_get_contents(__DIR__ . '/../../app/Http/Controllers/Api/CalendarController.php');
$routes = (string) file_get_contents(__DIR__ . '/../../routes/api.php');

echo "\nA day is a read model over sources that already exist\n";
check('the endpoint is routed', str_contains($routes, "'GET /api/calendar/day'"));
check('and is built with the schedule service, or it has no roles to show',
    str_contains($routes, 'PortalServiceProvider::makeScheduleService()'));
check('it reads the actor from the request like every other calendar endpoint',
    str_contains($controller, 'public function day(array $request): array')
    && str_contains($controller, '$actor = $this->requestContext->fromArray($request);'));
check('no new table is introduced for it',
    !preg_match('/CREATE\s+TABLE/i', $controller));

echo "\nThe day and the month cannot disagree about what is on a date\n";
check('both feeds compose their items through one method',
    substr_count($controller, '$this->composeItems(') === 2);
check('so the month feed no longer builds its own list',
    str_contains($controller, "return ['items' => \$this->composeItems("));

echo "\nRoles are joined on identity, never on a title\n";
check('the join key is the occurrence id the calendar item already carries',
    str_contains($controller, "'occ:' . \$occurrenceId"));
check('an occurrence without an id is skipped rather than guessed at',
    str_contains($controller, '$occurrenceId <= 0'));
check('a role with nobody in it survives as an unfilled line',
    str_contains($controller, "'person' => \$person !== '' ? \$person : null"));
check('and no title comparison crept in',
    !preg_match('/event_title.*===|===.*event_title/', $controller));

echo "\nOne day means midnight to midnight\n";
// The board's SQL is `starts_at >= :start AND < :end`, so asking for
// the same date twice is an empty range. Nine assignments on 12 Jul 2026
// vanished exactly this way before the window was widened.
check('the window starts at midnight', str_contains($controller, '$from = $day->setTime(0, 0, 0);'));
check('and ends at the next midnight, exclusive',
    str_contains($controller, "\$until = \$from->modify('+1 day');"));
check('the board is asked for that window, not for a zero-width one',
    str_contains($controller, 'getScheduleBoard($actor, $from, $until)'));

echo "\nA date is parsed strictly\n";
check('the format is checked before the constructor sees it',
    str_contains($controller, "createFromFormat('!Y-m-d', \$raw)"));
check('a value that does not round-trip is refused',
    str_contains($controller, "\$parsed->format('Y-m-d') !== \$raw"));
check('and refusal is a validation failure, not a 500',
    str_contains($controller, 'ValidationFailed('));

echo "\nA missing source costs its own lines, not the whole day\n";
check('no schedule service means activities without roles',
    str_contains($controller, 'if ($this->schedules === null) {'));
check('and a schedule failure is caught like the roster and holiday reads',
    substr_count($controller, 'catch (\Throwable)') >= 3);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
