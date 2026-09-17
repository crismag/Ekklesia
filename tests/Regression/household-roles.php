<?php

declare(strict_types=1);

/**
 * Suggesting household roles where the household answers plainly.
 *
 * Kinship cannot be derived in general — that is the finding in
 * docs/design/27-family-model.md and the reason 292 of 306 people have no
 * family role. A subset is unambiguous: one address, one surname, exactly two
 * adults of different sex. On this roster the rule fires on 14 of 94
 * households, giving 14 couples and 7 children.
 *
 * These tests are almost entirely about the households it must refuse. Three
 * adults could be a couple and a lodger or three siblings; two adults of the
 * same sex could be siblings or flatmates; a second surname could be a partner
 * who kept her name or a boarder. Guessing any of those wrong writes a
 * relationship into somebody's record that nobody asked for.
 */

require_once __DIR__ . '/../../app/Services/HouseholdRoleSuggester.php';

use App\Services\HouseholdRoleSuggester;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$s = new HouseholdRoleSuggester();
$p = static fn (int $id, string $surname, int $gender, string $type = '', string $role = '', int $birthYear = 0): array => [
    'person_id' => $id, 'last_name' => $surname, 'gender' => $gender,
    'member_type' => $type, 'role' => $role, 'birth_year' => $birthYear,
];
$roles = static fn (array $r): array => array_combine(
    array_column($r['suggestions'], 'person_id'),
    array_column($r['suggestions'], 'role'),
);

// --- the case it exists for ------------------------------------------------

$family = $s->forHousehold([
    $p(1, 'Cruz', HouseholdRoleSuggester::MALE),
    $p(2, 'Cruz', HouseholdRoleSuggester::FEMALE),
    $p(3, 'Cruz', HouseholdRoleSuggester::MALE, 'G&A'),
    $p(4, 'Cruz', HouseholdRoleSuggester::FEMALE, 'G&A'),
]);
check('the man is the husband', ($roles($family)[1] ?? '') === 'Husband', json_encode($family));
check('the woman is the wife', ($roles($family)[2] ?? '') === 'Wife');
check('the children are children', ($roles($family)[3] ?? '') === 'Child' && ($roles($family)[4] ?? '') === 'Child');
check('and nothing was skipped', $family['skipped'] === null);
check('every suggestion says why it was made',
    array_filter(array_column($family['suggestions'], 'because')) === array_column($family['suggestions'], 'because'));

// A couple with no children is still a couple.
$couple = $s->forHousehold([$p(1, 'Cruz', 1), $p(2, 'Cruz', 2)]);
check('a childless couple is still suggested', count($couple['suggestions']) === 2, json_encode($couple));

// --- the households it must refuse -----------------------------------------

foreach ([
    'one person alone' => [$p(1, 'Cruz', 1)],
    'three adults' => [$p(1, 'Cruz', 1), $p(2, 'Cruz', 2), $p(3, 'Cruz', 1)],
    'two men' => [$p(1, 'Cruz', 1), $p(2, 'Cruz', 1)],
    'two women' => [$p(1, 'Cruz', 2), $p(2, 'Cruz', 2)],
    'a surname that differs' => [$p(1, 'Cruz', 1), $p(2, 'Santos', 2)],
    'a sex not recorded' => [$p(1, 'Cruz', 0), $p(2, 'Cruz', 2)],
    'both sexes not recorded' => [$p(1, 'Cruz', 0), $p(2, 'Cruz', 0)],
    'only children, no adults' => [$p(1, 'Cruz', 1, 'G&A'), $p(2, 'Cruz', 2, 'G&A')],
] as $label => $members) {
    $r = $s->forHousehold($members);
    check('refuses: ' . $label, $r['suggestions'] === [] && $r['skipped'] !== null, json_encode($r));
    check('  and says why: ' . $label, trim((string) $r['skipped']) !== '');
}

// --- a role already recorded is a decision ---------------------------------

$hasRoles = $s->forHousehold([
    $p(1, 'Cruz', 1, '', 'Other Relative'),
    $p(2, 'Cruz', 2, '', 'Wife'),
]);
check('an existing role is never contradicted', $hasRoles['suggestions'] === [], json_encode($hasRoles));

$halfDone = $s->forHousehold([
    $p(1, 'Cruz', 1, '', 'Husband'),
    $p(2, 'Cruz', 2),
    $p(3, 'Cruz', 1, 'G&A'),
]);
check('only the gaps are filled', count($halfDone['suggestions']) === 2, json_encode($halfDone));
check('and the person who had one is untouched',
    !in_array(1, array_column($halfDone['suggestions'], 'person_id'), true));

// --- what counts as a child is configuration, not a constant ---------------

$other = new HouseholdRoleSuggester(['Kids Church', 'G&A']);
$withOther = $other->forHousehold([
    $p(1, 'Cruz', 1), $p(2, 'Cruz', 2), $p(3, 'Cruz', 1, 'Kids Church'),
]);
check('a differently named child type is honoured',
    ($roles($withOther)[3] ?? '') === 'Child', json_encode($withOther));

$unknownType = $s->forHousehold([$p(1, 'Cruz', 1), $p(2, 'Cruz', 2), $p(3, 'Cruz', 1, 'Bible Study')]);
check('a member type nobody called a child counts as an adult',
    $unknownType['suggestions'] === [], json_encode($unknownType));

// Surname comparison must ignore punctuation and case, or a hyphen splits a
// household that is plainly one.
$messy = $s->forHousehold([$p(1, "O'Brien", 1), $p(2, 'obrien', 2)]);
check('punctuation and case do not split a household', count($messy['suggestions']) === 2, json_encode($messy));

// --- the harder households, each with its confidence -----------------------

$by = static fn (array $r, int $id): array => (function () use ($r, $id): array {
    foreach ($r['suggestions'] as $s) {
        if ($s['person_id'] === $id) {
            return $s;
        }
    }
    return [];
})();

// Rule B — the reported problem: a wife who kept her name. Two adults of
// different sex at one address could be flatmates, so children in the
// household are required before anything is said, and it is never "certain".
$kept = $s->forHousehold([
    $p(1, 'Cruz', 1), $p(2, 'Santos', 2), $p(3, 'Cruz', 1, 'G&A'),
]);
check('a couple with different surnames is found when children are present',
    ($by($kept, 1)['role'] ?? '') === 'Husband' && ($by($kept, 2)['role'] ?? '') === 'Wife', json_encode($kept));
check('and is only ever likely, never certain',
    ($by($kept, 1)['confidence'] ?? '') === HouseholdRoleSuggester::LIKELY);
check('while the child is certain regardless',
    ($by($kept, 3)['confidence'] ?? '') === HouseholdRoleSuggester::CERTAIN, json_encode($kept));

$noKids = $s->forHousehold([$p(1, 'Cruz', 1), $p(2, 'Santos', 2)]);
check('two adults, different surnames, no children: says nothing',
    $noKids['suggestions'] === [], json_encode($noKids));
check('and explains that it could be flatmates',
    str_contains((string) $noKids['skipped'], 'flatmates'), (string) $noKids['skipped']);

// A single parent and a child. An earlier version of this refused the whole
// household because it could not name a couple; rule C exists because being a
// child *of this household* does not depend on there being two adults in it.
$singleParent = $s->forHousehold([$p(1, 'Cruz', 1), $p(2, 'Cruz', 2, 'G&A')]);
check('a child living with one adult is still a child',
    count($singleParent['suggestions']) === 1
    && ($singleParent['suggestions'][0]['person_id'] ?? 0) === 2
    && ($singleParent['suggestions'][0]['role'] ?? '') === 'Child', json_encode($singleParent));
check('and the lone adult is given no spousal role',
    !in_array(1, array_column($singleParent['suggestions'], 'person_id'), true));

// Rule C — a child of the household does not depend on knowing the parents.
$twoMen = $s->forHousehold([$p(1, 'Cruz', 1), $p(2, 'Cruz', 1), $p(3, 'Cruz', 1, 'G&A')]);
check('a child is identified though the couple cannot be',
    count($twoMen['suggestions']) === 1 && ($by($twoMen, 3)['role'] ?? '') === 'Child', json_encode($twoMen));
check('and no couple is invented to go with them',
    ($by($twoMen, 1)) === [] && ($by($twoMen, 2)) === []);

$noAdults = $s->forHousehold([$p(1, 'Cruz', 1, 'G&A'), $p(2, 'Cruz', 2, 'G&A')]);
check('children with no adult present are not called children of anyone',
    $noAdults['suggestions'] === [], json_encode($noAdults));

// Rule D — three adults with a generation between them.
$multi = $s->forHousehold([
    $p(1, 'Cruz', 1, '', '', 1960), $p(2, 'Cruz', 2, '', '', 1962), $p(3, 'Cruz', 1, '', '', 1992),
]);
check('the oldest two of three adults are read as the couple',
    ($by($multi, 1)['role'] ?? '') === 'Husband' && ($by($multi, 2)['role'] ?? '') === 'Wife', json_encode($multi));
check('the adult child is read as a child of the household',
    ($by($multi, 3)['role'] ?? '') === 'Child');
check('none of it is claimed as certain',
    ($by($multi, 1)['confidence'] ?? '') === HouseholdRoleSuggester::LIKELY);

$noGap = $s->forHousehold([
    $p(1, 'Cruz', 1, '', '', 1960), $p(2, 'Cruz', 2, '', '', 1962), $p(3, 'Cruz', 1, '', '', 1970),
]);
check('three adults of one generation say nothing', $noGap['suggestions'] === [], json_encode($noGap));

// A lodger of the right age is not their child.
$lodger = $s->forHousehold([
    $p(1, 'Cruz', 1, '', '', 1960), $p(2, 'Cruz', 2, '', '', 1962), $p(3, 'Lim', 1, '', '', 1992),
]);
check('someone younger with another surname is not made their child',
    ($by($lodger, 3)) === [], json_encode($lodger));
check('though the couple above them is still read',
    ($by($lodger, 1)['role'] ?? '') === 'Husband');

// A missing birth year makes the ordering meaningless, so it must not be used.
$noAges = $s->forHousehold([
    $p(1, 'Cruz', 1, '', '', 1960), $p(2, 'Cruz', 2, '', '', 0), $p(3, 'Cruz', 1, '', '', 1992),
]);
check('an unknown birth year stops rule D rather than guessing the order',
    $noAges['suggestions'] === [], json_encode($noAges));

// The threshold has to be about the shape of the household, not a number.
//
// Parents born 1988 and 1989 with a son born 2006 is seventeen years, and an
// eighteen-year rule dropped the whole family — saying nothing at all about a
// household that reads plainly.
$closeGeneration = $s->forHousehold([
    $p(807, 'Cantimbuhan', 2, 'Trailblazer', '', 1988),
    $p(809, 'Cantimbuhan', 1, 'Trailblazer', '', 1989),
    $p(808, 'Cantimbuhan', 1, 'Radical', '', 2006),
]);
check('a seventeen-year gap is still a generation',
    ($by($closeGeneration, 809)['role'] ?? '') === 'Husband'
    && ($by($closeGeneration, 807)['role'] ?? '') === 'Wife'
    && ($by($closeGeneration, 808)['role'] ?? '') === 'Child', json_encode($closeGeneration));

// What keeps that threshold honest: the two oldest have to look like a couple.
// Two people fifteen years apart with someone fifteen years below them is a
// continuum, not a generational break.
$continuum = $s->forHousehold([
    $p(1, 'X', 1, '', '', 1970), $p(2, 'X', 2, '', '', 1992), $p(3, 'X', 1, '', '', 2010),
]);
check('two adults a generation apart are not read as a couple',
    $continuum['suggestions'] === [], json_encode($continuum));

// And a household that is evenly spaced says nothing either way.
$evenlySpaced = $s->forHousehold([
    $p(1, 'X', 1, '', '', 1980), $p(2, 'X', 2, '', '', 1988), $p(3, 'X', 1, '', '', 1996),
]);
check('an evenly spaced household is left alone', $evenlySpaced['suggestions'] === [], json_encode($evenlySpaced));

// Head of household is gone: only these three roles are ever suggested.
$everyRole = [];
foreach ([$family, $kept, $multi, $twoMen] as $result) {
    foreach ($result['suggestions'] as $one) {
        $everyRole[$one['role']] = true;
    }
}
check('only Husband, Wife and Child are ever suggested',
    array_diff(array_keys($everyRole), ['Husband', 'Wife', 'Child']) === [], implode(',', array_keys($everyRole)));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
