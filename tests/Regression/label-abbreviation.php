<?php

declare(strict_types=1);

/**
 * Short codes for the people directory's two narrowest columns.
 *
 * Classification and member type spelled out took more width between them than
 * a person's name, which pushed the Edit button off the right of the screen.
 * Both lists are edited by administrators, so a fixed table of abbreviations
 * would go stale the moment somebody adds a member type — the codes are
 * derived instead, and derived from the whole set at once so two labels can
 * never end up sharing one.
 */

require_once __DIR__ . '/../../app/Services/LabelAbbreviator.php';

use App\Services\LabelAbbreviator;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$a = new LabelAbbreviator();

// The values actually on this roster.
$types = $a->map(['Trailblazer', 'Radical', 'G&A']);
check('a single word takes two letters', $types['Trailblazer'] === 'Tr', json_encode($types));
check('and another', $types['Radical'] === 'Ra');

// G&A was abbreviated to "G": the split ran on punctuation first, producing
// the token "A", which the stop-word list then removed as the article "a".
check('G&A keeps its own name', $types['G&A'] === 'G&A', json_encode($types));

$cls = $a->map(['Member', 'Regular Attender', 'Guest', 'Non-Attender', 'Non-Attender (staff)']);
check('several words become initials', $cls['Regular Attender'] === 'RA', json_encode($cls));
check('a hyphenated pair counts as two words', $cls['Non-Attender'] === 'NA');
check('a parenthetical distinguishes a near-duplicate', $cls['Non-Attender (staff)'] === 'NAS');
check('and the two do not collide', $cls['Non-Attender'] !== $cls['Non-Attender (staff)']);

// "and" is dropped so three words do not become three letters.
$g = $a->map(['Gifts and Arrows']);
check('a joining word is not an initial', $g['Gifts and Arrows'] === 'GA', json_encode($g));

// Single letters collide constantly — Member and Ministry are both M.
$m = $a->map(['Member', 'Ministry', 'Men', 'Mentor']);
check('labels that start alike stay distinct', count(array_unique($m)) === count($m), json_encode($m));

// Near-identical labels must still be told apart.
$plural = $a->map(['Trailblazer', 'TrailBlazers']);
check('a singular and its plural do not collide',
    $plural['Trailblazer'] !== $plural['TrailBlazers'], json_encode($plural));

// A code has to fit the column it exists for.
$wide = $a->map(['Sunday School Class', 'Regular Attender', 'Non-Attender (staff)', 'Bible Study']);
foreach ($wide as $label => $code) {
    check('within three characters: ' . $label, mb_strlen($code) <= 3, $code);
}

// Nothing in, nothing out — and no crash.
check('an empty list yields nothing', $a->map([]) === []);
check('blank labels are skipped', $a->map(['', '   ']) === []);
check('a label of only punctuation still gets something',
    ($a->map(['---'])['---'] ?? '') !== '');

// Duplicates in the input are one label, not two competing codes.
$dupes = $a->map(['Member', 'Member', 'Guest']);
check('a repeated label appears once', count($dupes) === 2, json_encode($dupes));

// Order in, order out: the key on the page reads in the order given.
$ordered = $a->map(['Zulu', 'Alpha', 'Mike']);
check('input order is preserved', array_keys($ordered) === ['Zulu', 'Alpha', 'Mike']);

// Accents must not produce an empty code.
$accented = $a->map(['Église', 'Ministère']);
check('accented labels abbreviate', ($accented['Église'] ?? '') !== '' && ($accented['Ministère'] ?? '') !== '',
    json_encode($accented));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
