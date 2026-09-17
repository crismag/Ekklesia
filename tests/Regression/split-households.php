<?php

declare(strict_types=1);

/**
 * One household entered as two family records.
 *
 * Several shapes, one mistake. A married couple as two one-person families. A
 * son split off from his parents and siblings. A surname typed two ways.
 * Thirteen households on this roster were split like that.
 *
 * The postcode is the anchor rather than the street line: it is granular and
 * hard to mistype into another valid one, whereas street text is written
 * differently every time. One pair gave street numbers a digit apart at the
 * same unit, and no grouping on address text would have put them together.
 *
 * Most of these tests are about the pairs it must refuse — above all two homes
 * in one building, which read almost identically and are not one household.
 */

require_once __DIR__ . '/../../app/Services/SplitHouseholdFinder.php';

use App\Services\SplitHouseholdFinder;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$f = new SplitHouseholdFinder();
$fam = static fn (int $id, string $surname, string $address, string $postcode, int $members = 1): array => [
    'family_id' => $id, 'label' => $surname, 'address' => $address,
    'postcode' => $postcode, 'members' => $members, 'surnames' => [$surname],
];
$one = static fn (array $pairs): array => $pairs[0] ?? [];

// --- the shapes it must find ------------------------------------------------

// Two one-person families, same surname, same address: a couple split in two.
$couple = $f->find([$fam(650, 'Reyes', '9136 Dufferin St', 'L4K 5M2'), $fam(653, 'Reyes', '9136 Dufferin St', 'L4K 5M2')]);
check('a couple filed as two families is found', count($couple) === 1, json_encode($couple));
check('and is certain when the addresses match',
    ($one($couple)['confidence'] ?? '') === SplitHouseholdFinder::CERTAIN);

// A person split off from a larger family — not a couple at all, still one
// household. Restricting this to pairs of one-person families missed it.
$split = $f->find([$fam(656, 'Rosario', '185 Barrie Rd', 'L3V 2P6', 3), $fam(657, 'Rosario', '185 Barrie Rd', 'L3V 2P6')]);
check('someone split off from a larger family is found', count($split) === 1, json_encode($split));

// A digit out in the street number, same unit and postcode.
$typo = $f->find([$fam(651, 'Reyes', '5740 Yonge St #602', 'M2M 0B1'), $fam(652, 'Reyes', '5741 Yonge St #602', 'M2M 0B1')]);
check('a street number a digit out is likely, not certain',
    ($one($typo)['confidence'] ?? '') === SplitHouseholdFinder::LIKELY, json_encode($typo));
check('and the difference is named, because merging discards one of them',
    str_contains(implode(' ', $one($typo)['differs'] ?? []), '5741'), json_encode($typo));

// The same street written two ways.
$spelling = $f->find([$fam(598, 'Landicho', '65 Reiner Rd', 'M3H 4H8'), $fam(599, 'Landicho', '65 Reiner Road', 'M3H 4H8', 2)]);
check('the same street spelled two ways is likely',
    ($one($spelling)['confidence'] ?? '') === SplitHouseholdFinder::LIKELY, json_encode($spelling));

// A surname typed two ways at one address.
$name = $f->find([
    ['family_id' => 538, 'label' => 'Cantimbuhan', 'address' => '59 Kenmark', 'postcode' => 'M1K 3N1', 'members' => 3, 'surnames' => ['Cantimbuhan']],
    ['family_id' => 547, 'label' => 'Catimbuhan', 'address' => '59 Kenmark', 'postcode' => 'M1K 3N1', 'members' => 1, 'surnames' => ['Catimbuhan']],
]);
check('a surname typed two ways at one address is found', count($name) === 1, json_encode($name));

// --- the pairs it must refuse ----------------------------------------------

// The important one. Two apartments in one building read almost identically
// and are two homes.
$units = $f->find([$fam(618, 'Maningo', '2895 Bathurst St #405', 'M6B 3B7'), $fam(619, 'Maningo', '2895 Bathurst St #106', 'M6B 3B7')]);
check('different units are never a merge',
    ($one($units)['confidence'] ?? '') === SplitHouseholdFinder::POSSIBLE, json_encode($units));
check('and the reason names both units',
    str_contains(implode(' ', $one($units)['differs'] ?? []), '405')
    && str_contains(implode(' ', $one($units)['differs'] ?? []), '106'), json_encode($units));

foreach (['Unit 405' => 'Unit 106', 'Apt 405' => 'Apt 106', '405-2895 Bathurst St' => '106-2895 Bathurst St'] as $left => $right) {
    $written = $f->find([$fam(1, 'Cruz', $left, 'M6B 3B7'), $fam(2, 'Cruz', $right, 'M6B 3B7')]);
    check('units written as "' . $left . '" are still read',
        ($one($written)['confidence'] ?? '') === SplitHouseholdFinder::POSSIBLE, json_encode($written));
}

// A postcode can cover a whole tower.
$building = $f->find([$fam(1, 'Cruz', '10 Main St', 'M6B 3B7'), $fam(2, 'Cruz', '900 Other Ave', 'M6B 3B7')]);
check('one postcode and nothing else is only possible',
    ($one($building)['confidence'] ?? '') === SplitHouseholdFinder::POSSIBLE, json_encode($building));

check('different surnames at one address are not a household',
    $f->find([$fam(1, 'Cruz', '10 Main St', 'M6B 3B7'), $fam(2, 'Delacruz', '10 Main St', 'M6B 3B7')]) === []);
check('different postcodes are never compared',
    $f->find([$fam(1, 'Cruz', '10 Main St', 'M6B 3B7'), $fam(2, 'Cruz', '10 Main St', 'L4K 5M2')]) === []);
check('a family with no postcode is left out',
    $f->find([$fam(1, 'Cruz', '10 Main St', ''), $fam(2, 'Cruz', '10 Main St', '')]) === []);
check('a family on its own yields nothing', $f->find([$fam(1, 'Cruz', '10 Main St', 'M6B 3B7')]) === []);
check('an empty roster yields nothing', $f->find([]) === []);

// Ordering: the ones worth acting on without opening records come first.
$mixed = $f->find([
    $fam(1, 'Cruz', '900 Other Ave', 'M6B 3B7'),
    $fam(2, 'Cruz', '10 Main St', 'M6B 3B7'),
    $fam(3, 'Cruz', '10 Main St', 'M6B 3B7'),
]);
check('the strongest candidate is offered first',
    ($mixed[0]['confidence'] ?? '') === SplitHouseholdFinder::CERTAIN, json_encode(array_column($mixed, 'confidence')));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
