<?php

declare(strict_types=1);

/**
 * Suggesting a member type from age — only where there is none.
 *
 * Age carries real signal on this roster: all 62 people aged 40 or over hold
 * the same member type, and 44 of the 53 under 13 hold another. It is signal,
 * not fact. Trailblazer runs from 3 to 75 and Radical from 0 to 35, and the
 * types also turn on marital status and other affiliations that a birth year
 * knows nothing about.
 *
 * So these tests are mostly about restraint: never over an existing value,
 * never from a band too mixed to be worth acting on, and never without the
 * evidence attached.
 */

require_once __DIR__ . '/../../app/Services/MemberTypeSuggester.php';

use App\Services\MemberTypeSuggester;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$s = MemberTypeSuggester::fromFile(__DIR__ . '/../../config/member-type-age-bands.json');

// --- the shipped bands, measured from the roster --------------------------

check('a young child is suggested G&A', ($s->forAge(6)['type'] ?? '') === 'G&A');
check('a young adult is suggested Radical', ($s->forAge(20)['type'] ?? '') === 'Radical');
check('an older adult is suggested Trailblazer', ($s->forAge(55)['type'] ?? '') === 'Trailblazer');

// The evidence travels with the suggestion, so a reviewer can tell "all 62 of
// them" from "64 out of 85".
$strong = $s->forAge(55);
check('a suggestion carries its evidence',
    ($strong['matching'] ?? 0) > 0 && ($strong['total'] ?? 0) > 0, json_encode($strong));
check('and a confidence between 0 and 1',
    ($strong['confidence'] ?? -1) > 0 && ($strong['confidence'] ?? 2) <= 1);
check('the 40+ band is unanimous on this roster', ($strong['confidence'] ?? 0) === 1.0, json_encode($strong));

$mixed = $s->forAge(30);
check('the overlapping band is reported as weaker',
    ($mixed['confidence'] ?? 1) < ($strong['confidence'] ?? 0), json_encode($mixed));

// --- restraint -------------------------------------------------------------

check('an existing member type is never second-guessed',
    $s->forPerson('Radical', 1950) === null);
check('even when age would say otherwise',
    $s->forPerson('G&A', 1960) === null);
check('a blank one is suggested for', $s->forPerson('', 1960) !== null);
check('whitespace counts as blank', $s->forPerson('   ', 1960) !== null);

check('no birth year, no suggestion', $s->forPerson('', null) === null);
check('a birth year before living memory is bad data, not an elder',
    $s->forBirthYear(1200) === null);
check('a negative age suggests nothing', $s->forAge(-1) === null);
check('an implausible age suggests nothing', $s->forAge(200) === null);
check('a null age suggests nothing', $s->forAge(null) === null);

// A band whose evidence is too mixed must say nothing rather than send someone
// to check a guess that is wrong a third of the time.
$weak = new MemberTypeSuggester(
    [['from' => 0, 'to' => 99, 'type' => 'Coin Toss', 'matching' => 5, 'total' => 10]],
    0.7,
);
check('a band below the confidence floor suggests nothing', $weak->forAge(30) === null);

$justEnough = new MemberTypeSuggester(
    [['from' => 0, 'to' => 99, 'type' => 'Likely', 'matching' => 8, 'total' => 10]],
    0.7,
);
check('and one above it does', ($justEnough->forAge(30)['type'] ?? '') === 'Likely');

$empty = new MemberTypeSuggester([['from' => 0, 'to' => 99, 'type' => 'Nobody', 'matching' => 0, 'total' => 0]]);
check('a band nobody falls into suggests nothing', $empty->forAge(30) === null);

// Age is measured against a supplied year so the tests do not drift with time.
check('age is computed from the year given',
    ($s->forBirthYear(2020, 2026)['type'] ?? '') === 'G&A');
check('and the same person later falls in another band',
    ($s->forBirthYear(2020, 2066)['type'] ?? '') === 'Trailblazer');

// Configuration problems degrade to no suggestions rather than to bad ones.
$absent = MemberTypeSuggester::fromFile(__DIR__ . '/nope.json');
check('a missing config suggests nothing', $absent->forAge(30) === null);
check('a band with no type is ignored',
    (new MemberTypeSuggester([['from' => 0, 'to' => 99, 'type' => '', 'matching' => 9, 'total' => 10]]))->forAge(30) === null);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
