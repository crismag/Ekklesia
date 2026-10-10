<?php

declare(strict_types=1);

/**
 * Import de-duplication: who counts as the same person.
 *
 * Written after a North York import reported 23 "duplicates" that included two
 * pairs of genuinely different people — a mother and daughter with different
 * first names and different surnames, and two siblings sharing a surname. Both
 * pairs shared a household email address, and the identity key preferred email
 * over name, so any two people reachable at the same inbox were merged.
 *
 * That is not a cosmetic miscount: fillFrom() copies the removed person's
 * email, phone and address onto the kept record, so one member's contact
 * details are written onto another member's entry and the removed member never
 * reaches the directory.
 *
 * Names here are invented. Real member data does not belong in the repository.
 */

require __DIR__ . '/../../app/Services/MemberWorkbookParser.php';
require __DIR__ . '/../../app/Services/MemberMatchRules.php';
require __DIR__ . '/../../app/Services/MemberImportDeduper.php';

use App\Services\MemberImportDeduper;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

$row = static fn (string $last, string $first, string $email = '', string $phone = '', string $addr = ''): array => [
    'last_name' => $last, 'first_name' => $first, 'email' => $email,
    'phone' => $phone, 'address_line1' => $addr, 'name_raw' => trim("{$last}, {$first}"),
];

$d = new MemberImportDeduper();

// --- the reported defect ---------------------------------------------------
$household = 'one.inbox@example.invalid';

$result = $d->dedupe([
    $row('Dumbrevoix', 'Auvrelia', $household, '555-0100', '1 Elm St'),
    $row('Morvettari-Dumbrevoix', 'Rovvelind', $household, '555-0101', '1 Elm St'),
]);
check('different people sharing a household email are NOT merged', count($result['rows']) === 2);
check('and nothing is reported as removed', $result['removed'] === []);

$siblings = $d->dedupe([
    $row('Hobbleton', 'Embrick', $household, '555-0102'),
    $row('Hobbleton', 'Edvorra', $household, '555-0103'),
]);
check('siblings sharing a surname and an email are NOT merged', count($siblings['rows']) === 2);

// A merge must never move one person's contact details onto another.
$kept = $result['rows'];
$byLast = [];
foreach ($kept as $r) {
    $byLast[$r['last_name']] = $r;
}
check('each kept record keeps its own phone number',
    ($byLast['Dumbrevoix']['phone'] ?? '') === '555-0100'
    && ($byLast['Morvettari-Dumbrevoix']['phone'] ?? '') === '555-0101');

// --- genuine duplicates must still collapse --------------------------------
$exact = $d->dedupe([
    $row('Wimblethorne', 'Cavrelan', '', '', ''),
    $row('Wimblethorne', 'Cavrelan', 'cavrelan@example.invalid', '555-0104', '9 Oak Rd'),
]);
check('the same person entered twice IS merged', count($exact['rows']) === 1);
check('the merge keeps the copy with more filled fields',
    ($exact['rows'][0]['email'] ?? '') === 'cavrelan@example.invalid');
check('and the merge is reported', count($exact['removed']) === 1);

// A middle name on one copy only is still the same person: nameKey compares the
// first token, which is what makes "Mivvelo" and "Mivvelo Jovaskel" one member.
$middle = $d->dedupe([
    $row('Fennowhistle', 'Tovvick', 'tovvick@example.invalid'),
    $row('Fennowhistle', 'Tovvick Emberquil', ''),
]);
check('a first name with an extra middle name is the same person', count($middle['rows']) === 1);

// Different first names under one surname are different members, with or
// without any email at all.
$noEmail = $d->dedupe([
    $row('Rookmantle', 'Ivrelle'),
    $row('Rookmantle', 'Iggramus'),
]);
check('different first names under one surname stay separate', count($noEmail['rows']) === 2);

// --- rows with no usable name ----------------------------------------------
$nameless = $d->dedupe([
    ['last_name' => '', 'first_name' => '', 'email' => 'shared@example.invalid', 'name_raw' => ''],
    ['last_name' => '', 'first_name' => '', 'email' => 'shared@example.invalid', 'name_raw' => ''],
]);
check('nameless rows may still fall back to email', count($nameless['rows']) === 1);

$blank = $d->dedupe([
    ['last_name' => '', 'first_name' => '', 'email' => '', 'name_raw' => ''],
    ['last_name' => '', 'first_name' => '', 'email' => '', 'name_raw' => ''],
]);
check('two entirely blank rows are never merged into one', count($blank['rows']) === 2);

// --- an initial IS an abbreviation, and must still fold ---------------------
// This is the case the narrow rule exists for: one person entered once in full
// and once with an initial, at the same address under the same surname.
$initial = $d->dedupe([
    $row('Rookmantle', 'Ivrelle', 'ivrelle@example.invalid'),
    $row('Rookmantle', 'I', 'ivrelle@example.invalid', '555-0110'),
]);
check('an initial folds into the spelled-out name', count($initial['rows']) === 1);
check('and the spelled-out name is the one kept',
    ($initial['rows'][0]['first_name'] ?? '') === 'Ivrelle');

// The abbreviation rule needs BOTH signals. Neither alone may merge.
$initialNoEmail = $d->dedupe([
    $row('Rookmantle', 'Ivrelle'),
    $row('Rookmantle', 'I'),
]);
check('an initial without a shared email does not fold', count($initialNoEmail['rows']) === 2);

$initialOtherSurname = $d->dedupe([
    $row('Rookmantle', 'Ivrelle', 'shared2@example.invalid'),
    $row('Wimblethorne', 'I', 'shared2@example.invalid'),
]);
check('an initial under a different surname does not fold', count($initialOtherSurname['rows']) === 2);

// Sharing only a first letter is not an abbreviation — this is the exact shape
// of the sibling pair that was wrongly merged.
$firstLetterOnly = $d->dedupe([
    $row('Hobbleton', 'Embrick', 'shared3@example.invalid'),
    $row('Hobbleton', 'Edvorra', 'shared3@example.invalid'),
]);
check('names sharing only a first letter never fold', count($firstLetterOnly['rows']) === 2);

// --- the key itself --------------------------------------------------------
$k1 = $d->identityKey($row('Dumbrevoix', 'Auvrelia', $household));
$k2 = $d->identityKey($row('Morvettari-Dumbrevoix', 'Rovvelind', $household));
check('a shared email does not produce a shared identity key', $k1 !== $k2);
check('the identity key is derived from the name', str_starts_with($k1, 'n:'));

// --- unusable email addresses already on file -------------------------------
// "Apply ready rows" failed with "An email address looks invalid" and never
// said whose. The address was not in the workbook: it was already on a person
// record, and merging the existing row carried it into a payload that
// PersonAdminService then refused, aborting the whole batch on data the import
// never touched.
require_once __DIR__ . '/../../app/Services/MemberImportPayload.php';

$broken = [
    'id' => 1, 'last_name' => 'Thistlewick', 'first_name' => 'Cavrelle',
    'email' => 'not-an-address', 'mobile_phone' => '555-0120',
];
check('an unusable stored email is detected',
    array_keys(App\Services\MemberImportPayload::unusableEmails($broken)) === ['email']);

$merged = App\Services\MemberImportPayload::forSave(
    ['last_name' => 'Thistlewick', 'first_name' => 'Cavrelle', 'email' => '', 'phone' => ''],
    1, 1, [], 1, $broken
);
check('it is not carried into the save payload', ($merged['email'] ?? null) === '');
check('the rest of the existing record survives', ($merged['mobile_phone'] ?? '') === '555-0120');

$replaced = App\Services\MemberImportPayload::forSave(
    ['last_name' => 'Thistlewick', 'first_name' => 'Cavrelle', 'email' => 'cavrelle@example.invalid'],
    1, 1, [], 1, $broken
);
check('a valid incoming address replaces it', ($replaced['email'] ?? '') === 'cavrelle@example.invalid');

$fine = ['id' => 2, 'last_name' => 'Oakhurst', 'email' => 'oak@example.invalid'];
check('a valid stored address is left alone',
    App\Services\MemberImportPayload::unusableEmails($fine) === []);
$keptOk = App\Services\MemberImportPayload::forSave(
    ['last_name' => 'Oakhurst', 'first_name' => 'Brov', 'email' => ''], 1, 1, [], 2, $fine
);
check('and is still carried forward', ($keptOk['email'] ?? '') === 'oak@example.invalid');

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
