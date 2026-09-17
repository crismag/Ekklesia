<?php

declare(strict_types=1);

/**
 * How the public sign-up and RSVP modules decide a visitor is an existing member.
 *
 * A household shares email and phone: children use a guardian's address, older
 * members a relative's. An "exact" match turns a sign-up away as already
 * registered and files an RSVP under that member, so it must need the person's
 * own name, never a shared contact and surname alone.
 */

// The two modules are standalone and declare some of the same helper names, so
// each is checked in its own PHP process.
$module = $argv[1] ?? '';
if ($module === '') {
    $failedAny = false;
    foreach (['sg', 'rv'] as $m) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $m, $status);
        $failedAny = $failedAny || $status !== 0;
    }
    exit($failedAny ? 1 : 0);
}
require __DIR__ . '/../../' . ($module === 'sg' ? 'people_signup' : 'events_rsvp') . '/includes/helpers.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

$parent = ['Maria', 'Santos', 4, 1980, ['santos.family@example.com'], ['416-555-0100']];
$cases = [
    'same person (name + email)'        => [['Maria', 'Santos', 0, 0, ['santos.family@example.com'], []], 'exact'],
    'same person (name + phone)'        => [['Maria', 'Santos', 0, 0, [], ['(416) 555-0100']], 'exact'],
    'same person (name + birth)'        => [['Maria', 'Santos', 4, 1980, [], []], 'exact'],
    'child using the family email'      => [['Lito', 'Santos', 9, 2015, ['santos.family@example.com'], []], 'possible'],
    'child using the family phone'      => [['Lito', 'Santos', 0, 0, [], ['416-555-0100']], 'possible'],
    'relative with same birth month/year' => [['Nena', 'Santos', 4, 1980, [], []], 'possible'],
    'unrelated person'                  => [['Ana', 'Cruz', 0, 0, ['ana@example.com'], []], 'none'],
];
foreach ([$module => $module === 'sg' ? 'sign-up' : 'RSVP'] as $prefix => $label0) {
    $person = $prefix . '_person';
    $components = $prefix . '_components';
    $level = $prefix . '_match_level';
    $existing = $person(...$parent);
    foreach ($cases as $label => [$args, $expected]) {
        $got = $level($components($person(...$args), $existing));
        check("{$label0}: {$label} is {$expected}" . ($got === $expected ? '' : " (got {$got})"), $got === $expected);
    }
}

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
