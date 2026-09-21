<?php

declare(strict_types=1);

/**
 * The printed artifacts, and the rules that decide what goes on them.
 *
 * A printed calendar is the one output nobody can fix after the fact. It goes
 * on a noticeboard and stays there, so the tests here are mostly about what is
 * deliberately left out — a Sunday service sheet listing birthdays, or a year
 * planner repeating a weekly service fifty-two times, is not a smaller problem
 * than one that fails to render.
 */

require_once __DIR__ . '/../../app/Services/Calendar/PrintTemplates.php';
require_once __DIR__ . '/../../app/Services/Calendar/DisplayName.php';
require_once __DIR__ . '/../../app/Services/Calendar/CalendarViewModel.php';

use App\Services\Calendar\CalendarViewModel;
use App\Services\Calendar\PrintTemplates;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

/** Render a template the way PrintComposer does, and hand back its body. */
function render(string $id, array $items, string $from, string $to): string
{
    $tpl = PrintTemplates::get($id);
    [$start, $end] = PrintTemplates::rangeFor($id, new DateTimeImmutable($from), new DateTimeImmutable($to));
    $model = (new CalendarViewModel())->build($items, $start, $end, []);
    $colors = [];
    $body = '';
    $styles = '';
    require __DIR__ . '/../../resources/views/print/' . $tpl['file'];

    return $body;
}

$item = static fn (string $date, string $title, string $kind = 'event', ?string $start = null, ?string $end = null): array => [
    'kind' => $kind,
    'source' => $kind === 'holiday' ? 'holidays' : 'events:general',
    'source_label' => 'Events',
    'title' => $title,
    'meta' => '',
    'date' => $date,
    'starts_at' => $start,
    'ends_at' => $end,
];

echo "Every template is registered and has a file\n";
foreach (PrintTemplates::all() as $id => $tpl) {
    check("$id declares a file that exists",
        is_file(__DIR__ . '/../../resources/views/print/' . $tpl['file']), $tpl['file']);
    check("$id declares an orientation", in_array($tpl['orientation'], ['portrait', 'landscape'], true));
    check("$id says what range it wants", in_array($tpl['wants'], ['month', 'range', 'year'], true), $tpl['wants']);
}
check('an unknown template falls back rather than fataling',
    PrintTemplates::get('nonsense')['file'] === 'monthly.php');

echo "\nThe range is decided before the feed is fetched\n";
// A template that widens its window after the query has run draws the extra
// months empty, from data nobody asked the database for.
[$ys, $ye] = PrintTemplates::rangeFor('annual', new DateTimeImmutable('2026-09-15'), new DateTimeImmutable('2026-11-30'));
check('a year sheet asks for twelve whole months',
    $ys->format('Y-m-d') === '2026-09-01' && $ye->format('Y-m-d') === '2027-08-31',
    $ys->format('Y-m-d') . ' -> ' . $ye->format('Y-m-d'));
check('and does not shrink a longer range that was asked for',
    PrintTemplates::rangeFor('annual', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-12-31'))[1]
        ->format('Y-m-d') === '2027-12-31');
[$ms, $me] = PrintTemplates::rangeFor('monthly', new DateTimeImmutable('2026-09-15'), new DateTimeImmutable('2026-09-20'));
check('a month grid rounds out to whole months — half a grid is not a calendar',
    $ms->format('Y-m-d') === '2026-09-01' && $me->format('Y-m-d') === '2026-09-30');
[$rs, $re] = PrintTemplates::rangeFor('sunday', new DateTimeImmutable('2026-09-15'), new DateTimeImmutable('2026-09-20'));
check('a range template is given exactly what was asked for',
    $rs->format('Y-m-d') === '2026-09-15' && $re->format('Y-m-d') === '2026-09-20');

echo "\nSunday schedule\n";
// 2026-09-06 is a Sunday; 2026-09-09 is a Wednesday.
$sundayItems = [
    $item('2026-09-06', 'Sunday Worship', 'event', '2026-09-06 10:00:00', '2026-09-06 12:00:00'),
    $item('2026-09-06', 'Robert Austria (45)', 'birth'),
    $item('2026-09-06', 'The Cruzes (10 years)', 'anniv'),
    $item('2026-09-09', 'Midweek Prayer', 'event', '2026-09-09 19:00:00', '2026-09-09 20:00:00'),
];
$sunday = render('sunday', $sundayItems, '2026-09-01', '2026-09-30');
check('a Sunday appears', str_contains($sunday, 'Sunday Worship'));
check('a Wednesday does not — this sheet is for service days',
    !str_contains($sunday, 'Midweek Prayer'));
check('a birthday does not belong on a service sheet',
    !str_contains($sunday, 'Robert Austria'));
check('nor an anniversary', !str_contains($sunday, 'The Cruzes'));
check('the notes column is present and empty, because these get written on',
    str_contains($sunday, 's-note'));
check('and the serving column exists even with no roster data',
    str_contains($sunday, 'Serving'));

// A cancelled date must survive into print as a word, not as a strike-through:
// a photocopier keeps the text and can lose the rule.
$cancelled = render('sunday', [
    $item('2026-09-06', 'Cancelled — Sunday Worship', 'event', '2026-09-06 10:00:00', '2026-09-06 12:00:00'),
], '2026-09-01', '2026-09-30');
check('a cancelled service says so in words', str_contains($cancelled, 'Cancelled'));
check('and its own name is still readable', str_contains($cancelled, 'Sunday Worship'));

$emptySunday = render('sunday', [$item('2026-09-09', 'Midweek Prayer')], '2026-09-01', '2026-09-30');
check('a period with no Sundays says so rather than printing a blank sheet',
    str_contains($emptySunday, 'No Sundays'));

echo "\nYear at a glance\n";
$weekly = [];
foreach (['2026-09-06', '2026-09-13', '2026-09-20', '2026-09-27'] as $d) {
    $weekly[] = $item($d, 'Sunday Worship', 'event', $d . ' 10:00:00', $d . ' 12:00:00');
}
$weekly[] = $item('2026-12-25', 'Christmas Day', 'holiday');
// Two holiday calendars listing the same day produced "25, 25" before dates
// were keyed rather than appended.
$weekly[] = $item('2026-12-25', 'Christmas Day', 'holiday');
$weekly[] = $item('2026-09-06', 'Someone (30)', 'birth');

$annual = render('annual', $weekly, '2026-09-01', '2026-09-30');
check('a weekly service is one line, not four',
    substr_count($annual, 'Sunday Worship') === 1, (string) substr_count($annual, 'Sunday Worship'));
check('and says how many dates it covers', str_contains($annual, '4 dates'));
check('birthdays are left out of a year view', !str_contains($annual, 'Someone (30)'));
check('the same holiday from two calendars is one line',
    substr_count($annual, 'Christmas Day') === 1, (string) substr_count($annual, 'Christmas Day'));
check('a day is never listed twice for one entry',
    !preg_match('/<span class="yr-when">\s*25,\s*25/', $annual));
check('all twelve months are drawn, including the empty ones',
    substr_count($annual, 'yr-month') === 12, (string) substr_count($annual, 'yr-month'));
check('an empty month says so rather than looking broken',
    str_contains($annual, 'Nothing scheduled'));
check('the abbreviation is explained on the sheet itself',
    str_contains($annual, 'yr-legend'));

echo "\nNo template carries screen furniture into print\n";
foreach (['sunday', 'annual'] as $id) {
    $out = render($id, $weekly, '2026-09-01', '2026-09-30');
    check("$id emits no buttons", !preg_match('/<button/i', $out));
    check("$id emits no links to click", !preg_match('/<a\s/i', $out));
    check("$id sets no inline colour that a grayscale printer would flatten",
        !preg_match('/style="[^"]*color:\s*#(?!000|fff)/i', $out));
}

echo "\nA wall calendar fits its wall\n";
// One grid to a month is the whole point of the monthly template, and it was
// printing across two sheets: the row height was fixed at 1.02in but a table
// cell grows with its contents, so a busy week pushed an August landscape sheet
// to 12.24in on an 8.5in page.
$monthly = file_get_contents(__DIR__ . '/../../resources/views/print/monthly.php');
check('the grid height is derived from the paper, not assumed',
    str_contains($monthly, '$paperHeightIn') && str_contains($monthly, '$gridHeightIn'));
check('rows share that height rather than growing with their contents',
    str_contains($monthly, 'var(--grid-h') && str_contains($monthly, 'var(--rows'));
check('the constrained box is inside the cell, because a td cannot be held to a height',
    str_contains($monthly, '.cell { height:'));
$cellPlan = file_get_contents(__DIR__ . '/../../app/Services/Calendar/CellPlan.php');
// The cell budget moved out of the template and into CellPlan when each cell
// gained its own type tier. These assert the same properties against where
// they now live rather than against the shape they used to have.
check('how much a day can show still depends on how many week rows the month has',
    str_contains($monthly, '$rowHeightIn = $gridHeightIn / $rowCount')
    && str_contains($monthly, '$usableIn')
    && str_contains($monthly, 'CellPlan::plan('));
check('and the "+n more" line is still paid for in advance',
    str_contains($cellPlan, "\$usableIn - (\$moreHeightIn"));
check('the tightest tier keeps entries to one predictable line',
    str_contains($monthly, '.t-compact .ent { white-space: nowrap')
    && str_contains($monthly, 'text-overflow: ellipsis'));
check('nothing is ever dropped without saying so',
    str_contains($monthly, "+<?= (int) \$plan['hidden'] ?> more")
    && str_contains($monthly, "if (\$plan['hidden'] > 0)"));
check('and the plan always accounts for every entry it was given',
    str_contains($cellPlan, "'hidden' => \$count - \$shown"));

// The composer has to resolve the page before the template runs, or a template
// that lays out to fit the page has nothing to fit to.
$composer = file_get_contents(__DIR__ . '/../../app/Services/Calendar/PrintComposer.php');
$orientationAt = strpos($composer, "\$orientation = (string) (\$options['orientation']");
$requireAt = strpos($composer, "require \$this->viewPath . '/print/' . \$template['file']");
check('paper and orientation are resolved before the template is rendered',
    $orientationAt !== false && $requireAt !== false && $orientationAt < $requireAt);
check('and the paper size reaches the template in inches',
    str_contains($composer, '$paperHeightIn'));

echo "\nShortening a name so the day it belongs to still fits\n";
// A four-part name is most of a wall-calendar cell, and the day then reports
// "+3 more" — the long name crowds out the people listed beside it.
$n = \App\Services\Calendar\DisplayName::class;
check('a long name becomes a first name and an initial',
    $n::shorten('Clarisse Daise Manzo Bayeta (29)') === 'Clarisse B. (29)',
    $n::shorten('Clarisse Daise Manzo Bayeta (29)'));
check('two names shorten too', $n::shorten('Alvin Bibat (45)') === 'Alvin B. (45)');
// The bracketed part is the reason the entry is on the calendar at all.
check('the age is never the thing that gets dropped',
    str_contains($n::shorten('Ramon Bayeta (30)'), '(30)'));
check('an anniversary keeps its years',
    $n::shorten('The Cruz Family (10 years)') === 'The F. (10 years)',
    $n::shorten('The Cruz Family (10 years)'));
check('a single name has no initial to take and is left alone',
    $n::shorten('Prince (7)') === 'Prince (7)', $n::shorten('Prince (7)'));
check('a name with no bracket still shortens', $n::shorten('Alvin Bibat') === 'Alvin B.');
check('an empty title survives', $n::shorten('') === '');
check('surrounding whitespace does not produce a stray initial',
    $n::shorten('  Alvin   Bibat  (45) ') === 'Alvin B. (45)',
    $n::shorten('  Alvin   Bibat  (45) '));
// Accents are church names; an initial must not be mangled.
check('an accented initial is kept and uppercased',
    $n::shorten('Niño Álvarez (20)') === 'Niño Á. (20)', $n::shorten('Niño Álvarez (20)'));

check('only people are shortened — a service is not "Sunday S."',
    $n::forEntry('Sunday Service - NY', 'event', true) === 'Sunday Service - NY');
check('birthdays are', $n::forEntry('Alvin Bibat (45)', 'birth', true) === 'Alvin B. (45)');
check('and anniversaries', $n::forEntry('The Cruzes (10 years)', 'anniv', true) === 'The C. (10 years)');
check('with the choice off, nothing is shortened',
    $n::forEntry('Alvin Bibat (45)', 'birth', false) === 'Alvin Bibat (45)');

echo "\nA week at a time\n";
check('the weekly layout is offered', array_key_exists('weekly', PrintTemplates::all()));
$weekly = file_get_contents(__DIR__ . '/../../resources/views/print/weekly.php');
check('it budgets for its own chrome, not only its rows',
    str_contains($weekly, '$blockChromeIn'));
check('and measures the cell rather than estimating it',
    str_contains($weekly, '0.208') && str_contains($weekly, '0.156'));
check('reserving the "+n more" line in advance, as the month grid does',
    str_contains($weekly, '0.134'));
$presentation = file_get_contents(__DIR__ . '/../../app/Services/Calendar/EntryPresentation.php');
// Both grids reach the shortening rule through EntryPresentation now, which is
// its only caller: the rule still lives in exactly one place, and neither
// template carries a copy of it.
check('both grids share one shortening rule rather than a copy each',
    str_contains($weekly, 'EntryPresentation::of(')
    && str_contains($monthly, 'EntryPresentation::of(')
    && !str_contains($weekly, 'DisplayName::')
    && !str_contains($monthly, 'DisplayName::')
    && str_contains($presentation, 'DisplayName::forEntry')
    && str_contains($presentation, 'DisplayName::shorten'));
check('and the week grid adapts its cells on the same terms as the month',
    str_contains($weekly, 'CellPlan::plan(')
    && str_contains($weekly, "\$legacy && !\$grow ? \$perDay : null")
    && str_contains($weekly, "+<?= (int) \$plan['hidden'] ?> more"));

echo "\nPresentation choices reach the sheet\n";
$composer = file_get_contents(__DIR__ . '/../../app/Services/Calendar/PrintComposer.php');
check('a type scale is clamped rather than trusted',
    str_contains($composer, 'max(0.85, min(1.3,'));
check('the typeface comes from a fixed list, never from the query string',
    str_contains($composer, '$fontStacks')
    && str_contains($composer, "in_array(\$fontChoice, ['serif', 'sans', 'display', 'mono'], true)"));
check('and every stack is local — a printable cannot depend on a network font',
    !preg_match('/fonts\.googleapis|@import|url\(/', substr($composer, strpos($composer, '$fontStacks'), 900)));
// The colour check moved to PrintConfig when settings were consolidated: it is
// the one place a configuration is validated, and it is reached by the route,
// by the API and by a saved view alike.
$printConfig = file_get_contents(__DIR__ . '/../../app/Services/Calendar/PrintConfig.php');
check('an accent colour must be a six-digit hex before it reaches a stylesheet',
    str_contains($printConfig, "preg_match('/^#[0-9a-f]{6}\$/i'"));

echo "\nThemes dress a calendar without touching what it says\n";
$themeClass = file_get_contents(__DIR__ . '/../../app/Services/Calendar/CalendarTheme.php');
$shell = file_get_contents(__DIR__ . '/../../resources/views/print/_shell.php');
check('Classic contributes no stylesheet of its own, so it cannot regress',
    !file_exists(__DIR__ . '/../../resources/views/print/themes/classic.css')
    && str_contains($composer, "\$theme !== CalendarTheme::DEFAULT"));
foreach (['editorial', 'planner', 'celebration'] as $themeId) {
    check('the ' . $themeId . ' theme has a stylesheet on disk',
        is_file(__DIR__ . '/../../resources/views/print/themes/' . $themeId . '.css'));
}
check('a theme cannot dress a layout it was not designed for',
    str_contains($composer, 'CalendarTheme::supports($theme, $templateId)'));
check('artwork is decorative only — hidden from assistive technology, and textless',
    str_contains($composer, 'aria-hidden=') && str_contains($composer, 'class="art ')
    && !preg_match('/<text[\s>]/', (string) file_get_contents(__DIR__ . '/../../resources/views/print/artwork/garden.svg'))
    && !preg_match('/<text[\s>]/', (string) file_get_contents(__DIR__ . '/../../resources/views/print/artwork/confetti.svg')));
check('the calendar grid itself stays real HTML text, never an image',
    // Member-type symbols are small inline icons beside a name; the grid is a table of text.
    str_contains($monthly, '<table class="cal') && !str_contains($monthly, '<img'));
check('the artwork band takes real space rather than floating over the page',
    str_contains($shell, '.art { height: var(--art-h')
    && !str_contains($shell, '.art { position: absolute'));
check('ink-friendly suppresses decoration rather than redesigning the theme',
    str_contains($composer, "if (\$inkFriendly) {\n            \$artwork = 'none';")
    && is_file(__DIR__ . '/../../resources/views/print/themes/_ink.css'));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
