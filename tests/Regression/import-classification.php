<?php

declare(strict_types=1);

/**
 * Everyone arriving from a spreadsheet starts as a member.
 *
 * A workbook says who is on the roster, not what their standing is, so the
 * import gives every person it creates or updates the same classification and
 * lets an administrator change it from there.
 *
 * It already did. What these tests hold is the part that was only right by
 * coincidence: resolving the classification used to fall back to option id 1
 * when the name was not found, which is Member here and would be whatever
 * happened to land at id 1 anywhere else. Silently filing a whole roster under
 * the wrong classification is expensive to notice and tedious to undo.
 */

require_once __DIR__ . '/../../app/Services/MemberImportPayload.php';

use App\Services\MemberCampusImportService;
use App\Services\MemberImportPayload;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

// The class constant is the contract: the importer's answer to "who is this
// spreadsheet full of".
require_once __DIR__ . '/../../app/Services/MemberCampusImportService.php';
check('the import classification is Member',
    MemberCampusImportService::DEFAULT_CLASSIFICATION === 'Member');

$row = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test'];
$typeIds = ['radical' => 1, 'trailblazer' => 2];
$memberClsId = 7;   // deliberately not 1, so a fallback to id 1 would show up

// A new person.
$created = MemberImportPayload::forSave($row, 3, $memberClsId, $typeIds, 0, null);
check('a newly imported person is classified', (int) $created['membership_status_id'] === $memberClsId,
    (string) ($created['membership_status_id'] ?? 'missing'));

// An existing person being updated by the import. This is the case that
// matters most: 174 of the people on the roster were classified this way.
$existing = [
    'id' => 42, 'first_name' => 'Ada', 'last_name' => 'Lovelace',
    'membership_status_id' => 0, 'email' => 'ada@example.test', 'region' => 'ON',
];
$updated = MemberImportPayload::forSave($row, 3, $memberClsId, $typeIds, 42, $existing);
check('an existing person picks the classification up too',
    (int) $updated['membership_status_id'] === $memberClsId, (string) ($updated['membership_status_id'] ?? 'missing'));

// The import is the authority on this field, so a stale value is replaced
// rather than kept.
$stale = $existing;
$stale['membership_status_id'] = 99;
$refreshed = MemberImportPayload::forSave($row, 3, $memberClsId, $typeIds, 42, $stale);
check('and it replaces whatever was there before',
    (int) $refreshed['membership_status_id'] === $memberClsId, (string) ($refreshed['membership_status_id'] ?? 'missing'));

// The classification must not depend on anything else in the row: a person
// with no email, no birth date and no member type is still classified.
$sparse = MemberImportPayload::forSave(['last_name' => 'Nobody'], 3, $memberClsId, $typeIds, 0, null);
check('a sparse row is still classified', (int) $sparse['membership_status_id'] === $memberClsId);

// It is never left at zero, which is what the directory shows as Unclassified
// and what 114 legacy records still carry.
foreach ([$created, $updated, $refreshed, $sparse] as $i => $payload) {
    check('payload ' . $i . ' is never left unclassified', (int) $payload['membership_status_id'] !== 0);
}

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
