<?php

declare(strict_types=1);

/**
 * Symbols for member types in the people directory.
 *
 * The column showed the full name (too wide — it pushed Edit off the screen),
 * then a two-letter code (Tr, Ra, G — read like chemical symbols).
 *
 * A symbol marks the member type recorded on the person and nothing more. It
 * is explicitly NOT an age band: the roster has Trailblazer running 3 to 75 and
 * Radical 0 to 35, and the types overlap on marital status and other
 * affiliations, so any reading of "adult" or "child" into these marks would be
 * inventing data.
 */

require_once __DIR__ . '/../../app/Services/MemberTypeIcons.php';

use App\Services\MemberTypeIcons;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$icons = MemberTypeIcons::fromFile(__DIR__ . '/../../config/member-type-icons.json');

check('the shipped mapping covers G&A', $icons->symbolFor('G&A') !== null);
check('and Trailblazer', $icons->symbolFor('Trailblazer') !== null);
check('and Radical', $icons->symbolFor('Radical') !== null);

// Every type must be told apart from every other; a shared symbol would make
// two different records look identical.
$assigned = array_map(
    static fn (string $t): ?string => $icons->symbolFor($t),
    ['G&A', 'Trailblazer', 'Radical'],
);
check('no two member types share a symbol',
    count(array_unique($assigned)) === count($assigned), json_encode($assigned));

// Punctuation and case must not decide whether a person gets a symbol.
check('G and A matches G&A', $icons->symbolFor('G and A') === $icons->symbolFor('G&A'));
check('the spelled-out name matches too',
    $icons->symbolFor('Gifts and Arrows') === $icons->symbolFor('G&A'));
check('case does not matter', $icons->symbolFor('trailblazer') === $icons->symbolFor('Trailblazer'));
check('surrounding space does not matter', $icons->symbolFor('  Radical  ') === $icons->symbolFor('Radical'));
check('the plural matches the singular', $icons->symbolFor('Radicals') === $icons->symbolFor('Radical'));

// An unmapped type must return null so the caller can fall back to text,
// rather than silently picking a symbol that means something else.
check('an unmapped type has no symbol', $icons->symbolFor('Bible Study') === null);
check('and neither does an empty one', $icons->symbolFor('') === null);

// Drawing.
foreach (['G&A', 'Trailblazer', 'Radical'] as $type) {
    $svg = $icons->svg((string) $icons->symbolFor($type));
    check('a symbol is drawn for ' . $type, str_starts_with($svg, '<svg') && str_contains($svg, '<path'));
    check('and is hidden from assistive tech, which reads the name instead: ' . $type,
        str_contains($svg, 'aria-hidden="true"'));
}
check('an unknown symbol draws nothing rather than an empty box', $icons->svg('nonsense') === '');

// A configuration that names a symbol with no drawing must not leave a blank
// cell: symbolFor() reports null so the caller falls back to text.
$broken = new MemberTypeIcons(['Ghost' => 'no-such-symbol'], []);
check('a symbol with no drawing is treated as unmapped', $broken->symbolFor('Ghost') === null);

// A missing or unreadable file degrades to no symbols at all, not a crash.
$absent = MemberTypeIcons::fromFile(__DIR__ . '/does-not-exist.json');
check('a missing config yields no symbols', $absent->symbolFor('Trailblazer') === null);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
