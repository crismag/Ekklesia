<?php

declare(strict_types=1);

/**
 * The schedule as a rule, and as a sentence.
 *
 * Two translations have to hold. A preset the editor offers must become a row
 * an event's repeat columns can actually store, and a stored row must come back as
 * something an administrator would say out loud — never "MONTHLY/1/SU". The
 * brief is explicit that the internal representation stays invisible, and the
 * only way that stays true is if the describing is tested.
 *
 * The refusals matter as much as the conversions: a one-off is not a rule, and
 * a list of chosen dates is not a repetition. Writing a rule for either would
 * be worse than writing nothing, because the next reader would believe it.
 */

require_once __DIR__ . '/../../app/Services/Events/RecurrenceRule.php';

use App\Services\Events\RecurrenceRule;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

echo "Preset to stored rule\n";
// 2026-09-06 is a Sunday.
$weekly = RecurrenceRule::toStorage('weekly', '2026-09-06', '2026-10-11');
check('weekly stores its type', ($weekly['repeat_frequency'] ?? '') === 'weekly');
check('the weekday comes from the start date, not from a separate control',
    ($weekly['repeat_weekdays'] ?? '') === 'SU', json_encode($weekly));
check('the end date is kept', ($weekly['repeat_until'] ?? '') === '2026-10-11');
check('no count when an end date was given', ($weekly['repeat_count'] ?? null) === null);

$fortnight = RecurrenceRule::toStorage('biweekly', '2026-09-01', null, 10);
check('biweekly is stored as biweekly, not weekly with an interval',
    ($fortnight['repeat_frequency'] ?? '') === 'biweekly');
check('a Tuesday start gives TU', ($fortnight['repeat_weekdays'] ?? '') === 'TU');
check('a count is kept when there is no end date', ($fortnight['repeat_count'] ?? null) === 10);

$monthly = RecurrenceRule::toStorage('monthly', '2026-09-06');
check('monthly stores no weekday — it repeats on the date, not the day',
    $monthly['repeat_weekdays'] === null, json_encode($monthly));

check('a one-off is not a rule and stores nothing',
    RecurrenceRule::toStorage('one_off', '2026-09-06') === null);
check('chosen dates are not a repetition and store nothing',
    RecurrenceRule::toStorage('selected', '2026-09-06') === null);
check('an unknown pattern stores nothing rather than guessing',
    RecurrenceRule::toStorage('every-third-blue-moon', '2026-09-06') === null);
check('a missing start date leaves the weekday empty rather than inventing one',
    RecurrenceRule::toStorage('weekly', null)['repeat_weekdays'] === null);
check('a malformed end date is dropped, not stored as junk',
    RecurrenceRule::toStorage('weekly', '2026-09-06', 'next tuesday')['repeat_until'] === null);
check('a zero count is not stored as a limit of zero',
    RecurrenceRule::toStorage('weekly', '2026-09-06', null, 0)['repeat_count'] === null);

echo "\nStored rule to human sentence\n";
check('a weekly rule names its day',
    RecurrenceRule::describe($weekly, '07:30', '09:00') === 'Every Sunday · 7:30 – 9:00 AM · until 11 October 2026',
    RecurrenceRule::describe($weekly, '07:30', '09:00'));
check('a fortnightly rule reads as every other',
    str_starts_with(RecurrenceRule::describe($fortnight, '17:30', '19:00'), 'Every other Tuesday'),
    RecurrenceRule::describe($fortnight, '17:30', '19:00'));
check('a count is spoken as times, not as a number of rows',
    str_ends_with(RecurrenceRule::describe($fortnight, '17:30', '19:00'), '10 times'),
    RecurrenceRule::describe($fortnight, '17:30', '19:00'));
check('no rule reads as not repeating',
    RecurrenceRule::describe(null, '10:00', '12:00') === 'Does not repeat · 10:00 AM – 12:00 PM',
    RecurrenceRule::describe(null, '10:00', '12:00'));
check('all day is said instead of a time',
    RecurrenceRule::describe(null, null, null, true) === 'Does not repeat · All day',
    RecurrenceRule::describe(null, null, null, true));
check('a start with no end shows only the start',
    RecurrenceRule::describe(null, '17:30', null) === 'Does not repeat · 5:30 PM',
    RecurrenceRule::describe(null, '17:30', null));
check('an end equal to the start is not shown as a range',
    RecurrenceRule::describe(null, '17:30', '17:30') === 'Does not repeat · 5:30 PM',
    RecurrenceRule::describe(null, '17:30', '17:30'));
check('a range spanning noon keeps both meridiems',
    RecurrenceRule::describe(null, '10:00', '13:00') === 'Does not repeat · 10:00 AM – 1:00 PM',
    RecurrenceRule::describe(null, '10:00', '13:00'));
check('no time at all adds nothing',
    RecurrenceRule::describe(null) === 'Does not repeat', RecurrenceRule::describe(null));

// The whole point: none of the stored vocabulary reaches the reader.
$sentences = [
    RecurrenceRule::describe($weekly, '07:30', '09:00'),
    RecurrenceRule::describe($fortnight, '17:30', '19:00'),
    RecurrenceRule::describe($monthly, '10:00', '12:00'),
    RecurrenceRule::describe(['repeat_frequency' => 'yearly']),
    RecurrenceRule::describe(['repeat_frequency' => 'daily']),
];
$leaks = array_filter($sentences, static fn (string $s): bool =>
    preg_match('/\b(BYDAY|BYSETPOS|INTERVAL|recurrence_|MONTHLY|WEEKLY|SU|MO|TU|WE|TH|FR|SA)\b/', $s) === 1);
check('no sentence leaks the stored representation', $leaks === [], json_encode(array_values($leaks)));
check('every sentence is non-empty', count(array_filter($sentences)) === count($sentences));

// A type the editor never offers may still be in the column from ChurchCRM.
check('a yearly rule read back from the database is still described',
    RecurrenceRule::describe(['repeat_frequency' => 'yearly']) === 'Every year');
check('an unrecognised type degrades to "Repeats" rather than to nothing',
    RecurrenceRule::describe(['repeat_frequency' => 'lunar']) === 'Repeats');

echo "\nFirst Sunday of every month\n";
// The pattern a church wants most, and the one the column could not express
// until migration 010. Communion Sunday moves between the 1st and the 7th, so
// "every month on the same date" was never it.
$firstSunday = RecurrenceRule::toStorage('monthly_nth', '2026-09-06');   // 1st Sunday
check('an nth-weekday rule is still stored as monthly',
    ($firstSunday['repeat_frequency'] ?? '') === 'monthly');
check('with the weekday', ($firstSunday['repeat_weekdays'] ?? '') === 'SU');
check('and which one it is', ($firstSunday['repeat_week_of_month'] ?? null) === 1,
    json_encode($firstSunday));
check('and it reads as a church would say it',
    RecurrenceRule::describe($firstSunday, '10:00', '12:00')
        === 'First Sunday of every month · 10:00 AM – 12:00 PM',
    RecurrenceRule::describe($firstSunday, '10:00', '12:00'));

// 2026-09-27 is the 4th Sunday and also the last. Last wins: somebody who
// picked it meant the last one, and that keeps meaning it in four-Sunday
// months where the fourth and the last are the same date anyway.
$lastSunday = RecurrenceRule::toStorage('monthly_nth', '2026-09-27');
check('a date in the final week of its month is the last, not the fourth',
    ($lastSunday['repeat_week_of_month'] ?? null) === -1, json_encode($lastSunday));
check('and says so',
    RecurrenceRule::describe($lastSunday) === 'Last Sunday of every month',
    RecurrenceRule::describe($lastSunday));
check('the second of a weekday is the second',
    (RecurrenceRule::toStorage('monthly_nth', '2026-09-13')['repeat_week_of_month'] ?? null) === 2);
check('the third is the third',
    (RecurrenceRule::toStorage('monthly_nth', '2026-09-20')['repeat_week_of_month'] ?? null) === 3);

// The by-date monthly preset must not acquire a week-of-month, or it would
// silently change meaning for every rule already stored.
check('an ordinary monthly rule stores no week-of-month',
    RecurrenceRule::toStorage('monthly', '2026-09-06')['repeat_week_of_month'] === null);
check('and still reads as every month',
    RecurrenceRule::describe(RecurrenceRule::toStorage('monthly', '2026-09-06')) === 'Every month');
check('a monthly row stored before the column existed still reads as every month',
    RecurrenceRule::describe(['repeat_frequency' => 'monthly', 'repeat_weekdays' => 'SU'])
        === 'Every month');

echo "\nCadence read off the dates, when no rule was stored\n";
// Every event created before the rule was persisted has dates and no schedule.
// "Does not repeat" above a list of fifty-two Fridays contradicts the page.
$weekdays = static function (int $n, int $step, string $from = '2026-09-04'): array {
    $out = [];
    $d = new DateTimeImmutable($from);
    for ($i = 0; $i < $n; $i++) {
        $out[] = $d->format('Y-m-d') . ' 17:30:00';
        $d = $d->modify('+' . $step . ' days');
    }
    return $out;
};
$obsWeekly = RecurrenceRule::observe($weekdays(6, 7));
check('six evenly spaced Fridays read as weekly',
    ($obsWeekly['repeat_frequency'] ?? '') === 'weekly', json_encode($obsWeekly));
check('and name the day they fall on',
    ($obsWeekly['repeat_weekdays'] ?? '') === 'FR');
check('and end on the last one', ($obsWeekly['repeat_until'] ?? '') === '2026-10-09');
check('a fortnightly spacing reads as fortnightly',
    (RecurrenceRule::observe($weekdays(5, 14))['repeat_frequency'] ?? '') === 'biweekly');
check('the inferred rule describes itself in the same words',
    RecurrenceRule::describe($obsWeekly, '17:30', '19:00') === 'Every Friday · 5:30 – 7:00 PM · until 9 October 2026',
    RecurrenceRule::describe($obsWeekly, '17:30', '19:00'));

// The refusals: guessing a cadence that is not there would be worse than
// showing none, because the page would be asserting something false.
check('two dates are not enough to call a pattern',
    RecurrenceRule::observe(['2026-09-04 17:30:00', '2026-09-11 17:30:00']) === null);
check('uneven spacing is not a cadence',
    RecurrenceRule::observe(['2026-09-04 00:00:00', '2026-09-11 00:00:00', '2026-09-25 00:00:00']) === null);
check('a monthly spacing is not claimed — the gap is not constant in days',
    RecurrenceRule::observe(['2026-01-06 00:00:00', '2026-02-06 00:00:00', '2026-03-06 00:00:00']) === null);
check('an empty list yields nothing', RecurrenceRule::observe([]) === null);
check('two occurrences on one day count once and do not fake a daily cadence',
    RecurrenceRule::observe(['2026-09-04 09:00:00', '2026-09-04 18:00:00', '2026-09-05 09:00:00']) === null);

echo "\nPresets offered\n";
check('the editor offers only patterns the column can express',
    array_keys(RecurrenceRule::PRESETS)
        === ['one_off', 'weekly', 'biweekly', 'monthly', 'monthly_nth', 'selected'],
    json_encode(array_keys(RecurrenceRule::PRESETS)));
// The two monthly presets have to be distinguishable in the list, or somebody
// picks one meaning the other.
check('the two monthly presets say how they differ',
    str_contains(RecurrenceRule::PRESETS['monthly'], 'same date')
    && str_contains(RecurrenceRule::PRESETS['monthly_nth'], 'same weekday'),
    json_encode(RecurrenceRule::PRESETS));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
