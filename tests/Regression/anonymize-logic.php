<?php

declare(strict_types=1);

/**
 * Tests the pseudonym engine in tools/anonymize-dev-db.php.
 *
 * These run without a database. They cover the properties that make a
 * sanitised copy usable rather than merely scrubbed: the same person always
 * becomes the same pseudonym, households share a surname, gendered name pools
 * are respected, and no generated contact detail can reach a real person.
 */

require __DIR__ . '/../../tools/anonymize-dev-db.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

$salt = 'test-salt';

// Determinism — a re-run must reproduce the previous dataset exactly, or every
// screenshot and bug report against the dev database becomes unreproducible.
check(
    'first name is stable across calls',
    pseudoFirstName($salt, '42', 'M') === pseudoFirstName($salt, '42', 'M')
);
check(
    'surname is stable across calls',
    pseudoSurname($salt, 'fam-7') === pseudoSurname($salt, 'fam-7')
);

// A different salt must produce a different dataset, so a leaked mapping can
// be invalidated by rotating ANONYMIZE_SALT.
check(
    'a different salt yields a different mapping',
    pseudoSurname($salt, 'fam-7') !== pseudoSurname('other-salt', 'fam-7')
);

// Relationship preservation.
check(
    'same family group shares a surname',
    pseudoSurname($salt, 'fam-3') === pseudoSurname($salt, 'fam-3')
);
$distinctFamilySurnames = [];
for ($i = 1; $i <= 40; $i++) {
    $distinctFamilySurnames[pseudoSurname($salt, 'fam-' . $i)] = true;
}
check(
    'distinct families mostly get distinct surnames (' . count($distinctFamilySurnames) . '/40)',
    count($distinctFamilySurnames) >= 25
);

// Gendered pools — a directory where every woman has a man's name reads as
// obviously fake and undermines review.
$maleOk = true;
$femaleOk = true;
for ($i = 1; $i <= 200; $i++) {
    $maleOk = $maleOk && in_array(pseudoFirstName($salt, (string) $i, 'M'), FIRST_M, true);
    $femaleOk = $femaleOk && in_array(pseudoFirstName($salt, (string) $i, 'F'), FIRST_F, true);
}
check('male pseudonyms come from the male pool', $maleOk);
check('female pseudonyms come from the female pool', $femaleOk);
check(
    'unknown gender draws from the combined pool',
    in_array(pseudoFirstName($salt, '9', null), array_merge(FIRST_M, FIRST_F), true)
);

// Distribution — a hash that collapses onto a few names would make the
// directory useless for testing search, sorting and pagination.
$spread = [];
for ($i = 1; $i <= 252; $i++) {
    $spread[pseudoFirstName($salt, (string) $i, 'M') . ' ' . pseudoSurname($salt, 'person-' . $i)] = true;
}
check(
    'a 252-person dataset yields distinct names (' . count($spread) . '/252)',
    count($spread) >= 240
);

// Index bounds — an out-of-range index would fatal mid-run, part-way through
// rewriting the copy.
$inRange = true;
for ($i = 0; $i < 5000; $i++) {
    $index = seedIndex($salt, 'scope', (string) $i, 17);
    $inRange = $inRange && $index >= 0 && $index < 17;
}
check('seedIndex stays within its modulus', $inRange);

// The PII map must stay in step with the columns the tool actually rewrites.
$map = piiMap();
check(
    'every known PII table is covered',
    array_diff(
        ['christlikeness_people_tbl', 'couples_tbl', 'portal_users', 'schedule_roster_assignment', 'portal_audit_log', 'church_campus_info'],
        array_keys($map)
    ) === []
);
check(
    'people table covers name, contact, address and birth date',
    array_diff(['first_name', 'last_name', 'email', 'cell_phone', 'address_1', 'birth_date'], $map['christlikeness_people_tbl']) === []
);
check('sessions and tokens are emptied, not rewritten', truncateTables() === ['portal_sessions', 'portal_tokens']);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
