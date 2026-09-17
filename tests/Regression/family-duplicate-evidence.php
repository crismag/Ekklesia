<?php

declare(strict_types=1);

/**
 * Whether a group of family records is actually one family.
 *
 * The duplicates page grouped by surname and offered every collision as a
 * candidate. Twenty-seven surnames on this roster belong to more than one
 * family, covering 62 of 157 — a page opening with 27 undifferentiated
 * candidates is how the real one gets missed.
 *
 * Sharing an address is not evidence either, in the opposite direction:
 * seventeen addresses hold more than one family, and adult children, a lodger
 * and a hosted parent are all ordinary. Two families at one address is usually
 * correct, and the page should not imply otherwise.
 */

require_once __DIR__ . '/../../app/Services/FamilyDuplicateEvidence.php';

use App\Services\FamilyDuplicateEvidence;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$e = new FamilyDuplicateEvidence();
$fam = static fn (string $name, string $addr = '', string $email = ''): array
    => ['name' => $name, 'addr' => $addr, 'email' => $email];

// --- the thing they were grouped by is never evidence for the grouping ------

$surnameOnly = $e->assess([$fam('Cruz', '1 A St'), $fam('Cruz', '99 B Rd')], 'surname');
check('a shared surname alone is no signal', $surnameOnly['rank'] === FamilyDuplicateEvidence::NO_SIGNAL, json_encode($surnameOnly));
check('and offers no evidence to show', $surnameOnly['evidence'] === []);

$addressOnly = $e->assess([$fam('Cruz', '1 A St'), $fam('Santos', '1 A St')], 'address');
check('a shared address alone is no signal', $addressOnly['rank'] === FamilyDuplicateEvidence::NO_SIGNAL, json_encode($addressOnly));
check('the grouping criterion is not repeated back as evidence',
    !in_array('the same address', $addressOnly['evidence'], true), json_encode($addressOnly));

// --- one other thing agreeing is worth a look ------------------------------

$sameAddress = $e->assess([$fam('Cruz', '1 A St'), $fam('Cruz', '1 A St')], 'surname');
check('same surname and same address is worth opening',
    $sameAddress['rank'] === FamilyDuplicateEvidence::WORTH_A_LOOK, json_encode($sameAddress));
check('and says what agreed', in_array('the same address', $sameAddress['evidence'], true));

$sameEmail = $e->assess([$fam('Cruz', '1 A St', 'c@x.test'), $fam('Cruz', '99 B Rd', 'c@x.test')], 'surname');
check('a shared email is worth opening even at different addresses',
    $sameEmail['rank'] === FamilyDuplicateEvidence::WORTH_A_LOOK, json_encode($sameEmail));

$sameSurnameAtAddress = $e->assess([$fam('Cruz', '1 A St'), $fam('Cruz', '1 A St')], 'address');
check('same address and same surname is worth opening',
    $sameSurnameAtAddress['rank'] === FamilyDuplicateEvidence::WORTH_A_LOOK, json_encode($sameSurnameAtAddress));

// --- the same person in both is the strong one ------------------------------

$sharedPerson = $e->assess(
    [$fam('Cruz', '1 A St'), $fam('Cruz', '1 A St')],
    'surname',
    ['Maria Cruz'],
);
check('the same person plus another agreement is likely one family',
    $sharedPerson['rank'] === FamilyDuplicateEvidence::LIKELY, json_encode($sharedPerson));
check('and names the person, so it can be checked',
    str_contains(implode(' ', $sharedPerson['evidence']), 'Maria Cruz'), json_encode($sharedPerson));

// A shared person on its own is suggestive but not conclusive: two households
// can each legitimately hold a record for the same-named person.
$personOnly = $e->assess([$fam('Cruz', '1 A St'), $fam('Cruz', '99 B Rd')], 'surname', ['Maria Cruz']);
check('the same person alone is worth opening, not certain',
    $personOnly['rank'] === FamilyDuplicateEvidence::WORTH_A_LOOK, json_encode($personOnly));

$manyShared = $e->assess([$fam('Cruz', '1 A St'), $fam('Cruz', '1 A St')], 'surname', ['Maria Cruz', 'Jose Cruz']);
check('several shared people are summarised rather than listed',
    str_contains(implode(' ', $manyShared['evidence']), '2 members'), json_encode($manyShared));

// --- one name typed twice --------------------------------------------------

// A household split in two because a daughter's surname was typed without an
// "n". Both records sat at the same address with the same postcode, and the
// page filed them under "share an address and nothing else" — which is exactly
// when somebody most needs telling.
$typo = $e->assess([$fam('Cantimbuhan', '59 Kenmark'), $fam('Catimbuhan', '59 Kenmark')], 'address');
check('a surname a typo apart counts as evidence',
    $typo['rank'] === FamilyDuplicateEvidence::WORTH_A_LOOK, json_encode($typo));
check('and both spellings are shown, so the right one can be picked',
    str_contains(implode(' ', $typo['evidence']), 'Cantimbuhan')
    && str_contains(implode(' ', $typo['evidence']), 'Catimbuhan'), json_encode($typo));

check('a longer name one edit apart also counts',
    $e->assess([$fam('Santos', '1 A'), $fam('Santoso', '1 A')], 'address')['rank']
        === FamilyDuplicateEvidence::WORTH_A_LOOK);

// On a short name two edits is a different name, not a slip.
check('two edits on a short name is a different family',
    $e->assess([$fam('Cruz', '1 A'), $fam('Cruse', '1 A')], 'address')['rank']
        === FamilyDuplicateEvidence::NO_SIGNAL);
check('and unrelated names stay unrelated',
    $e->assess([$fam('Reyes', '1 A'), $fam('Delacruz', '1 A')], 'address')['rank']
        === FamilyDuplicateEvidence::NO_SIGNAL);

// A typo is a thing that happens between two records. Three spellings at one
// address is something else, and guessing which is canonical is not this
// function's job.
check('three families at an address are not a typo pair',
    !str_contains(
        implode(' ', $e->assess([$fam('Cruz', '1 A'), $fam('Cruze', '1 A'), $fam('Cruzz', '1 A')], 'address')['evidence']),
        'typo',
    ));

// Grouped by surname, the names are identical by construction, so this must
// not fire and claim it found something.
check('the typo check does not run when the grouping is the surname',
    !str_contains(
        implode(' ', $e->assess([$fam('Cruz', '1 A'), $fam('Cruz', '9 B')], 'surname')['evidence']),
        'typo',
    ));

// --- agreement must be unanimous -------------------------------------------

// Two of three families sharing an address says nothing about the third, and
// treating it as agreement would merge a record unrelated to the others.
$partial = $e->assess([$fam('Cruz', '1 A St'), $fam('Cruz', '1 A St'), $fam('Cruz', '99 B Rd')], 'surname');
check('agreement between only some of the group does not count',
    $partial['rank'] === FamilyDuplicateEvidence::NO_SIGNAL, json_encode($partial));

// An empty field is not agreement. Three families with no email recorded have
// not "all got the same email".
$blank = $e->assess([$fam('Cruz', '1 A St', ''), $fam('Cruz', '99 B Rd', '')], 'surname');
check('a field nobody filled in is not agreement',
    !in_array('the same email address', $blank['evidence'], true), json_encode($blank));

// --- shapes that must not crash --------------------------------------------

check('a group of one is no signal', $e->assess([$fam('Cruz')], 'surname')['rank'] === FamilyDuplicateEvidence::NO_SIGNAL);
check('an empty group is no signal', $e->assess([], 'surname')['rank'] === FamilyDuplicateEvidence::NO_SIGNAL);

// Formatting and punctuation must not decide whether two addresses agree.
$messy = $e->assess([$fam('Cruz', '1 A St.'), $fam('Cruz', '1  a  st')], 'surname');
check('punctuation and case do not break agreement',
    $messy['rank'] === FamilyDuplicateEvidence::WORTH_A_LOOK, json_encode($messy));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
