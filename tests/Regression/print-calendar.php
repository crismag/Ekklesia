<?php

declare(strict_types=1);

/**
 * The printed calendar's promises about people and pages.
 *
 *  - A celebrant is printed by the name they go by (preferred, else first),
 *    never with an age, in every layout that prints birthdays.
 *  - Member types keep fixed, distinguishable marks: a colour and a symbol,
 *    with a neutral mark for a type that is not one of the three.
 *  - "Grow to fit" hides nothing; "One page" still reports "+n more".
 *  - Every theme honours the token contract and leaves member colours alone.
 *
 * Rendered through PrintComposer with invented people, so it needs no database.
 */

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

use App\Services\Calendar\CalendarTheme;
use App\Services\Calendar\MemberTypeStyle;
use App\Services\Calendar\PrintComposer;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

echo "Member types\n";
check('G&A, however it is written, is the sky-blue group',
    MemberTypeStyle::group('G&A') === 'gna' && MemberTypeStyle::group('G and A') === 'gna'
    && MemberTypeStyle::group('GNA') === 'gna' && MemberTypeStyle::group('Gifts & Arrows') === 'gna');
check('Trailblazer and Radical match in either number',
    MemberTypeStyle::group('Trailblazers') === 'trailblazer' && MemberTypeStyle::group('radicals') === 'radical');
check('anything else is not guessed', MemberTypeStyle::group('Seniors') === null && MemberTypeStyle::group('') === null);
check('the colours are sky blue, green and orange, in one place',
    array_keys(MemberTypeStyle::GROUPS) === ['gna', 'trailblazer', 'radical']
    && str_contains(MemberTypeStyle::cssVars(), '--mt-gna:') && str_contains(MemberTypeStyle::cssVars(), '--mt-none:'));

// Non-text contrast: a rule or symbol needs 3:1 against the white page.
$lum = static function (string $hex): float {
    $c = array_map(static fn (string $h): float => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
    $c = array_map(static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
foreach (MemberTypeStyle::GROUPS as $id => $g) {
    check("the $id colour has at least 3:1 contrast on white", (1.05) / ($lum($g['color']) + 0.05) >= 3.0);
}
$styles = MemberTypeStyle::fromConfig($root . '/config/member-type-icons.json');
check('each of the three types has its own symbol, so greyscale still tells them apart',
    count(array_unique([$styles->symbol('G&A'), $styles->symbol('Trailblazer'), $styles->symbol('Radical')])) === 3
    && $styles->symbol('G&A') !== '');
check('an unknown type gets no symbol', $styles->symbol('Seniors') === '');
$legend = $styles->legend(['Radical', 'G&A', 'Radical', 'Seniors']);
check('the legend lists only the types on the sheet, in a fixed order, named as stored',
    array_column($legend, 'group') === ['gna', 'radical'] && $legend[0]['label'] === 'G&A');

echo "\nThemes keep the contract\n";
foreach (CalendarTheme::all() as $id => $theme) {
    $palette = (array) ($theme['palette'] ?? []);
    check("$id declares every colour token",
        array_keys($palette) === ['paper', 'ink', 'heading', 'accent', 'grid', 'cell', 'band']
        && count(array_filter($palette, static fn ($h) => preg_match('/^#[0-9a-f]{6}$/i', (string) $h) === 1)) === 7);
    check("$id says how much ink it uses", in_array($theme['ink'] ?? '', ['low', 'medium'], true));
    $css = $root . '/resources/views/print/themes/' . $id . '.css';
    check("$id never redefines a member-type colour", !is_file($css) || !str_contains((string) file_get_contents($css), '--mt-'));
}
check('there is a low-ink theme', in_array('low', array_column(CalendarTheme::all(), 'ink'), true));

echo "\nThe title says what the sheet is\n";
check('birthdays alone are "Birthdays"', PrintComposer::docTitle(['birthdays', 'holidays:ca-on'], [], 'Monthly calendar') === 'Birthdays');
check('one calendar is named after it', PrintComposer::docTitle(['events:ministry'], ['events:ministry' => 'Ministry'], 'Monthly calendar') === 'Ministry');
check('a mixture is the layout', PrintComposer::docTitle(['birthdays', 'events:ministry'], [], 'Monthly calendar') === 'Monthly calendar');

echo "\nA printed month\n";
$person = static fn (string $first, string $preferred, string $last, string $type): array =>
    ['first' => $first, 'preferred' => $preferred, 'last' => $last, 'memberType' => $type];
$items = [];
// One very busy day: six celebrants and four events on the 13th.
foreach ([['Genoveva', 'Gen', 'Arden', 'Radical', 22], ['Hermogenes', '', 'Tolentino', 'G&A', 78],
          ['Ignacia', '', 'Brightwater', 'Trailblazer', 31], ['Jessie James', 'JJ', 'Quill', 'Radical', 14],
          ['Jessie', '', 'Quill', 'Trailblazer', 51], ['Vangie', '', 'Marchetti', '', 14]] as [$f, $p, $l, $t, $age]) {
    $items[] = ['kind' => 'birth', 'source' => 'birthdays', 'date' => '2026-09-13',
        'title' => "$f $l ($age)", 'person' => $person($f, $p, $l, $t)];
}
foreach (['Sunday Worship Service', 'Youth Fellowship', 'Choir rehearsal', 'Elders meeting'] as $i => $t) {
    $items[] = ['kind' => 'event', 'source' => 'events:ministry', 'title' => $t,
        'starts_at' => '2026-09-13 ' . (10 + $i * 3) . ':00:00', 'ends_at' => '2026-09-13 ' . (11 + $i * 3) . ':00:00'];
}
$items[] = ['kind' => 'birth', 'source' => 'birthdays', 'date' => '2026-09-03', 'title' => 'Beatriz Halloran (17)',
    'person' => $person('Beatriz', 'Bea', 'Halloran', 'Radical')];

$composer = new PrintComposer($root . '/resources/views');
$start = new DateTimeImmutable('2026-09-01');
$end = new DateTimeImmutable('2026-09-30');
$base = ['template' => 'monthly', 'paper' => 'letter', 'orientation' => 'portrait', 'today' => '2026-09-21'];
$grow = $composer->render($items, $start, $end, $base + ['pageHeight' => 'grow']);
$fit = $composer->render($items, $start, $end, $base + ['pageHeight' => 'fit', 'entryDisplay' => 'compact']);
$text = static fn (string $html): string => html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

check('a celebrant with a preferred name is printed by it', str_contains($text($grow), 'JJ') && str_contains($text($grow), 'Bea'));
check('otherwise by their first name alone', str_contains($text($grow), 'Hermogenes') && !str_contains($text($grow), 'Hermogenes Tolentino'));
check('the full legal name is not printed', !str_contains($text($grow), 'Jessie James Quill') && !str_contains($text($grow), 'Beatriz'));
foreach (['grow' => $grow, 'fit' => $fit] as $mode => $html) {
    // An age would be "(51)" inline, or a bare number on the entry's second line.
    check("no age is printed ($mode)", preg_match('/\((\d{1,3})\)/', $text($html)) === 0
        && !preg_match('/class="w">[\s(]*\d{1,3}[\s)]*</', $html));
}
check('member types are marked by class', str_contains($grow, 'mt-radical') && str_contains($grow, 'mt-gna') && str_contains($grow, 'mt-trailblazer'));
check('a birthday with no type gets the neutral mark only', preg_match('/k-birth"[^>]*>\s*<span class="t">Vangie/', $grow) === 1);
check('the key is printed under a sheet with birthdays', str_contains($grow, 'class="legend"') && str_contains($grow, 'Not recorded'));
check('and can be left off',
    !str_contains($composer->render($items, $start, $end, $base + ['legend' => false]), 'class="legend"'));
check('growing, nothing is hidden behind "+n more"', !preg_match('/\+\d+ more/', $grow) && str_contains($grow, 'is-grow'));
check('on one page, a day that cannot fit still says how much is missing', preg_match('/\+\d+ more/', $fit) === 1);
check('the title leads the masthead', str_contains($grow, '<h1 class="doc-title">'));

foreach (['weekly', 'agenda', 'planner'] as $layout) {
    $html = $composer->render($items, $start, $end, ['template' => $layout] + $base);
    check("$layout prints the celebrant without an age",
        str_contains($text($html), 'JJ') && preg_match('/\((\d{1,3})\)/', $text($html)) === 0
        && !str_contains($text($html), 'Jessie James Quill'));
}

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
