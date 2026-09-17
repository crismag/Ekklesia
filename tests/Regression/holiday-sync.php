<?php

declare(strict_types=1);

/**
 * Adding, syncing and removing holiday calendars.
 *
 * Syncing is on demand, from an admin screen, because a church needs it about
 * once a year and an unattended job for something that rare fails quietly —
 * which is the worst way for a calendar to go stale.
 *
 * The fetcher is injected here so the rules can be exercised without the
 * network: what gets kept, what a failure must not destroy, and what a partial
 * failure has to admit to.
 */

require_once __DIR__ . '/../../app/Services/HolidayCalendars.php';
require_once __DIR__ . '/../../app/Services/HolidayCalendarSync.php';

use App\Services\HolidayCalendarSync;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$file = sys_get_temp_dir() . '/holidays-test-' . getmypid() . '.json';
@unlink($file);
register_shutdown_function(static fn () => @unlink($file));

$year = (int) date('Y');
$calls = 0;

/** A stand-in for the holiday service. */
$fetcher = static function (string $country, int $y) use (&$calls): array {
    $calls++;
    if ($country === 'XX') {
        throw new RuntimeException('service unavailable');
    }
    if ($country === 'ZZ') {
        return [];   // reachable, but knows nothing
    }

    return [
        ['date' => $y . '-01-01', 'name' => 'New Year', 'localName' => 'New Year', 'global' => true, 'counties' => null],
        ['date' => $y . '-03-17', 'name' => 'Regional Day', 'localName' => 'Regional Day', 'global' => false, 'counties' => ['CA-ON']],
        ['date' => $y . '-04-01', 'name' => 'Elsewhere Day', 'localName' => 'Elsewhere Day', 'global' => false, 'counties' => ['CA-BC']],
    ];
};

$sync = new HolidayCalendarSync($file, $fetcher);

// --- adding -----------------------------------------------------------------

$added = $sync->add('CA', 'CA-ON', 'Canada (Ontario)', '#b3261e', 2);
check('a calendar is added', $added['id'] === 'ca-on', json_encode($added));
check('and covers the years asked for', $added['count'] === 4, (string) $added['count']);
check('two years were fetched', $calls === 2, (string) $calls);

$names = array_column($sync->calendars()->itemsBetween(
    new DateTimeImmutable($year . '-01-01'),
    new DateTimeImmutable($year . '-12-31'),
), 'title');
check('national holidays are kept', in_array('New Year', $names, true), implode(',', $names));
check('the named region\'s holidays are kept', in_array('Regional Day', $names, true), implode(',', $names));
// Otherwise a country-wide set inherits every province's local days.
check('another region\'s holidays are not', !in_array('Elsewhere Day', $names, true), implode(',', $names));

$national = new HolidayCalendarSync($file . '.n', $fetcher);
$nat = $national->add('CA', null, 'Canada', '#000', 1);
check('with no region, only national days are kept', $nat['count'] === 1, (string) $nat['count']);
@unlink($file . '.n');

// --- adding the same one again ---------------------------------------------

$sync->setEnabled('ca-on', false);
$sync->add('CA', 'CA-ON', '', '', 2);
$again = $sync->calendars()->all()[0];
check('re-adding keeps it switched off if somebody switched it off',
    $again['enabled'] === false, json_encode($again['enabled']));
check('and keeps the name they gave it', $again['label'] === 'Canada (Ontario)', $again['label']);
check('re-adding does not create a second copy', count($sync->calendars()->all()) === 1);
$sync->setEnabled('ca-on', true);

// --- syncing ----------------------------------------------------------------

$refreshed = $sync->refresh('ca-on', 2);
check('syncing reports what it brought in', $refreshed['count'] === 4, json_encode($refreshed));
check('and stamps the date', $sync->calendars()->all()[0]['fetched_on'] === date('Y-m-d'));

// --- failure must not destroy what is cached --------------------------------

$sync->add('PH', null, 'Philippines', '#1f6b3c', 1);
$before = count($sync->calendars()->all());

$broken = new HolidayCalendarSync($file, static function (): array {
    throw new RuntimeException('service unavailable');
});
$threw = false;
try {
    $broken->refresh('ca-on', 2);
} catch (\Throwable) {
    $threw = true;
}
check('a sync that cannot reach the service fails loudly', $threw);
check('and leaves the cached holidays alone',
    count($sync->calendars()->all()) === $before
    && $sync->calendars()->all()[0]['holidays'] !== [], 'cache was damaged');

// A service that answers but knows nothing must not empty the calendar either.
$empty = new HolidayCalendarSync($file, static fn (): array => []);
$threw = false;
try {
    $empty->refresh('ca-on', 1);
} catch (\Throwable) {
    $threw = true;
}
check('an empty answer is refused rather than saved', $threw);
check('and the holidays survive it', $sync->calendars()->all()[0]['holidays'] !== []);

// --- a partial failure has to say so ---------------------------------------

$patchy = new HolidayCalendarSync($file, static function (string $country, int $y) use ($fetcher): array {
    if ($country === 'PH') {
        throw new RuntimeException('service unavailable');
    }

    return $fetcher($country, $y);
});
$result = $patchy->refreshAll(1);
check('the calendars it could reach are synced', count($result['refreshed']) === 1, json_encode($result['refreshed']));
check('and the one it could not is reported, not swallowed',
    count($result['failed']) === 1 && ($result['failed'][0]['label'] ?? '') === 'Philippines', json_encode($result['failed']));

// --- rejecting nonsense before going near the network ----------------------

foreach (['', 'C', 'CANADA', '12'] as $bad) {
    $threw = false;
    try {
        $sync->add($bad, null, '', '', 1);
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    check('a country code of "' . $bad . '" is refused', $threw);
}
$threw = false;
try {
    $sync->add('CA', 'ONTARIO', '', '', 1);
} catch (\InvalidArgumentException) {
    $threw = true;
}
check('a region that is not a code is refused', $threw);

$threw = false;
try {
    $sync->add('ZZ', null, '', '', 1);
} catch (\RuntimeException) {
    $threw = true;
}
check('a country the service knows nothing about is refused', $threw);

// --- removing ---------------------------------------------------------------

$sync->remove('ph');
check('removing takes it out', count($sync->calendars()->all()) === 1);
$threw = false;
try {
    $sync->remove('nope');
} catch (\InvalidArgumentException) {
    $threw = true;
}
check('removing something that is not there says so', $threw);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
