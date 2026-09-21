#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The print configuration, and the promises it has to keep.
 *
 * Two of these matter more than the rest.
 *
 * A reader who saves nothing and changes nothing must get exactly the calendar
 * this church already prints. Every capability added here is off until asked
 * for, and that is the assertion that stops a new feature quietly changing
 * everybody's Sunday noticeboard.
 *
 * And a view saved today must still open next year. Settings are added
 * constantly; a configuration that fails on an absent key would break every
 * saved view the first time somebody adds a checkbox.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/app/Services/Calendar/RichText.php';
require_once $root . '/app/Services/Calendar/CalendarTheme.php';
require_once $root . '/app/Services/Calendar/MemberTypeStyle.php';
require_once $root . '/app/Services/Calendar/PrintConfig.php';

use App\Services\Calendar\PrintConfig;
use App\Services\Calendar\RichText;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

echo "Defaults are the calendar the church already prints\n";
$d = PrintConfig::fromArray([]);
check('the monthly wall calendar', $d->get('layout') === 'monthly');
check('standard density — the current proportions', $d->get('appearance.density') === 'standard');
check('normal type size', $d->get('appearance.typeScale') === 1.0);
// A celebrant is printed by the name they go by, never the full legal name.
check('celebrants by the name they go by', $d->get('appearance.names') === 'first');
check('old "in full" views load as first names',
    App\Services\Calendar\PrintConfig::fromArray(['appearance' => ['names' => 'full']])->get('appearance.names') === 'first');
check('old "shortened" views load as first name and initial',
    App\Services\Calendar\PrintConfig::fromArray(['appearance' => ['names' => 'short']])->get('appearance.names') === 'initial');
check('a new calendar grows to fit its busy days', $d->get('page.height') === 'grow');
check('a view saved before page height existed keeps its one fitted sheet',
    App\Services\Calendar\PrintConfig::fromArray(['page' => ['paper' => 'a4', 'orientation' => '']])->get('page.height') === 'fit');
check('page height round-trips through the query',
    App\Services\Calendar\PrintConfig::fromQuery(App\Services\Calendar\PrintConfig::fromArray(['page' => ['height' => 'fit']])->toQuery())->get('page.height') === 'fit');
check('tabloid paper is accepted',
    App\Services\Calendar\PrintConfig::fromArray(['page' => ['paper' => 'tabloid']])->get('page.paper') === 'tabloid');
check('the member-type legend is on by default and can be turned off',
    $d->get('appearance.legend') === true
    && App\Services\Calendar\PrintConfig::fromQuery(['legend' => '0'])->get('appearance.legend') === false);
check('the classic serif', $d->get('appearance.font') === 'serif');
check('the church accent, not an override', $d->get('appearance.accent') === '');
check('the classic title', $d->get('appearance.titleStyle') === 'classic');
check('Letter, orientation left to the layout',
    $d->get('page.paper') === 'letter' && $d->get('page.orientation') === '');

// The optional regions are the ones most likely to creep into the default.
check('no information above the calendar', $d->get('additional.top.enabled') === false);
check('and nothing stored for it', $d->get('additional.top.html') === '');
check('no information below the calendar', $d->get('additional.bottom.enabled') === false);
check('no background', $d->get('background.mode') === 'none');

// The header and footer default to exactly what the sheet shows today.
check('the header shows the church, place, period and document type',
    $d->get('header.show') === ['church' => true, 'location' => true, 'period' => true, 'docType' => true],
    json_encode($d->get('header.show')));
check('no custom title', $d->get('header.title') === '' && $d->get('header.subtitle') === '');
check('the footer prints the date and the website, and nothing else',
    $d->get('footer.show') === ['printed' => true, 'website' => true, 'church' => false, 'page' => false],
    json_encode($d->get('footer.show')));

echo "\nA configuration saved before a setting existed still opens\n";
// The shape a view saved in an earlier version would have.
$old = PrintConfig::fromArray([
    'version' => 1,
    'layout' => 'agenda',
    'date' => ['mode' => 'next-month'],
    'content' => ['sources' => ['birthdays']],
]);
check('what it did say is kept', $old->get('layout') === 'agenda'
    && $old->get('date.mode') === 'next-month');
check('and everything it never mentioned is defaulted',
    $old->get('appearance.density') === 'standard' && $old->get('footer.show.printed') === true);
check('including sections that did not exist at all',
    is_array($old->get('background')) && $old->get('background.mode') === 'none');
check('and screen keys a print view never set',
    $old->get('screen.view') === '' && $old->get('screen.left') === '');
check('the version is stamped with the current model', $old->get('version') === PrintConfig::VERSION);
check('total nonsense still opens as defaults',
    PrintConfig::fromArray(['layout' => 12345, 'appearance' => 'not an array'])->get('appearance.density') === 'standard');

echo "\nA saved view says \"this month\", not August\n";
// The difference between a view somebody reopens every month and one that
// quietly prints last year.
$today = new DateTimeImmutable('2026-08-27');
$cases = [
    'this-month' => ['2026-08-01', '2026-08-31'],
    'next-month' => ['2026-09-01', '2026-09-30'],
    'quarter'    => ['2026-08-01', '2026-10-31'],
    'year'       => ['2026-01-01', '2026-12-31'],
];
foreach ($cases as $mode => [$from, $to]) {
    [$s, $e] = PrintConfig::fromArray(['date' => ['mode' => $mode]])->resolveRange($today);
    check("$mode resolves against today", $s->format('Y-m-d') === $from && $e->format('Y-m-d') === $to,
        $s->format('Y-m-d') . ' -> ' . $e->format('Y-m-d'));
}
// The same view, opened four months later, must move with the calendar.
[$s, $e] = PrintConfig::fromArray(['date' => ['mode' => 'this-month']])
    ->resolveRange(new DateTimeImmutable('2026-12-03'));
check('and again in December', $s->format('Y-m-d') === '2026-12-01' && $e->format('Y-m-d') === '2026-12-31');

$fixed = PrintConfig::fromArray(['date' => ['mode' => 'custom', 'from' => '2026-03-05', 'to' => '2026-03-09']]);
[$s, $e] = $fixed->resolveRange($today);
check('a range somebody typed is honoured exactly',
    $s->format('Y-m-d') === '2026-03-05' && $e->format('Y-m-d') === '2026-03-09');
[$s, $e] = PrintConfig::fromArray(['date' => ['mode' => 'custom', 'from' => '2026-03-09', 'to' => '2026-03-05']])
    ->resolveRange($today);
check('a backwards range is read the way it was meant', $s->format('Y-m-d') === '2026-03-05');
[$s, $e] = PrintConfig::fromArray(['date' => ['mode' => 'custom']])->resolveRange($today);
check('custom with no dates falls back to this month rather than to nothing',
    $s->format('Y-m-d') === '2026-08-01');

echo "\nValues that reach a stylesheet are checked, not trusted\n";
check('an accent must be six-digit hex',
    PrintConfig::fromArray(['appearance' => ['accent' => 'red; }body{display:none']])->get('appearance.accent') === '');
check('a valid one is kept, lowercased',
    PrintConfig::fromArray(['appearance' => ['accent' => '#13307C']])->get('appearance.accent') === '#13307c');
check('the type scale is clamped',
    PrintConfig::fromArray(['appearance' => ['typeScale' => 99]])->get('appearance.typeScale') === 1.3);
check('and cannot be zero', PrintConfig::fromArray(['appearance' => ['typeScale' => 0]])->get('appearance.typeScale') === 1.0);
check('an unknown density falls back to standard',
    PrintConfig::fromArray(['appearance' => ['density' => 'microscopic']])->get('appearance.density') === 'standard');
check('an unknown paper falls back to Letter',
    PrintConfig::fromArray(['page' => ['paper' => 'A0']])->get('page.paper') === 'letter');

echo "\nAn empty optional region takes no page\n";
// Reserving height for an empty box is the one thing these must never do.
$blank = PrintConfig::fromArray(['additional' => ['top' => ['enabled' => true, 'html' => '']]]);
check('enabled with nothing in it is not enabled', $blank->get('additional.top.enabled') === false);
foreach (['<p></p>', '<p>&nbsp;</p>', "<p>  </p>\n", '<ul></ul>'] as $empty) {
    $c = PrintConfig::fromArray(['additional' => ['bottom' => ['enabled' => true, 'html' => $empty]]]);
    check('nor is ' . json_encode($empty), $c->get('additional.bottom.enabled') === false);
}
$real = PrintConfig::fromArray(['additional' => ['top' => ['enabled' => true, 'html' => '<p>Bring a friend.</p>']]]);
check('a region with words in it is', $real->get('additional.top.enabled') === true);
check('and keeps them', str_contains((string) $real->get('additional.top.html'), 'Bring a friend'));

echo "\nEditorial notes carry formatting, not code\n";
check('a script tag and its contents are removed',
    !str_contains(RichText::sanitize('<p>Hi</p><script>alert(1)</script>'), 'alert'));
check('but the sentence survives',
    str_contains(RichText::sanitize('<p>Hi</p><script>alert(1)</script>'), 'Hi'));
check('a style block goes too',
    !str_contains(RichText::sanitize('<style>*{display:none}</style><p>Hi</p>'), 'display'));
check('an event handler cannot ride in on an allowed tag',
    !str_contains(RichText::sanitize('<p onclick="alert(1)">Hi</p>'), 'onclick'));
check('nor a style attribute',
    !str_contains(RichText::sanitize('<p style="position:fixed">Hi</p>'), 'style'));
check('a link is not a tag a printed note needs',
    !str_contains(RichText::sanitize('<a href="http://x">Hi</a>'), '<a'));
check('an iframe is removed entirely',
    !str_contains(RichText::sanitize('<iframe src="x"></iframe>Hi'), 'iframe'));
check('a comment cannot hide markup',
    !str_contains(RichText::sanitize('<!-- <script>x</script> -->Hi'), 'script'));
foreach (['strong', 'em', 'ul', 'li', 'h3', 'p'] as $keep) {
    check("<$keep> is kept — a note needs it",
        str_contains(RichText::sanitize("<$keep>Text</$keep>"), '<' . $keep . '>'));
}
check('a note longer than the limit is cut, not refused',
    mb_strlen(RichText::sanitize('<p>' . str_repeat('a', 9000) . '</p>')) <= RichText::MAX_LENGTH);

echo "\nTwo configurations that describe the same sheet are equal\n";
check('identical input compares equal',
    PrintConfig::fromArray(['layout' => 'agenda'])->equals(PrintConfig::fromArray(['layout' => 'agenda'])));
check('and key order does not matter',
    PrintConfig::fromArray(['layout' => 'agenda', 'page' => ['orientation' => 'portrait', 'paper' => 'a4']])
        ->equals(PrintConfig::fromArray(['page' => ['paper' => 'a4', 'orientation' => 'portrait'], 'layout' => 'agenda'])));
check('nor does the order sources were ticked in',
    PrintConfig::fromArray(['content' => ['sources' => ['b', 'a']]])
        ->equals(PrintConfig::fromArray(['content' => ['sources' => ['a', 'b']]])));
check('a real difference is a difference',
    !PrintConfig::fromArray(['layout' => 'agenda'])->equals(PrintConfig::fromArray(['layout' => 'monthly'])));


echo "\nA theme is part of the publication, so a saved view carries it\n";
$themed = PrintConfig::fromArray(['layout' => 'monthly', 'appearance' => [
    'theme' => 'celebration', 'entryDisplay' => 'showcase',
    'artwork' => 'garden', 'decoration' => 'minimal', 'inkFriendly' => true,
]]);
check('the theme survives', $themed->get('appearance.theme') === 'celebration');
check('so does the entry display', $themed->get('appearance.entryDisplay') === 'showcase');
check('and the artwork', $themed->get('appearance.artwork') === 'garden');
check('and how much of it', $themed->get('appearance.decoration') === 'minimal');
check('and the ink-friendly choice', $themed->get('appearance.inkFriendly') === true);
check('a round trip through the query form loses none of it',
    PrintConfig::fromQuery($themed->toQuery())->equals($themed));

echo "\nNothing reaches a stylesheet that was not offered\n";
$bogus = PrintConfig::fromArray(['appearance' => [
    'theme' => 'whatever', 'entryDisplay' => 'enormous', 'decoration' => 'lavish',
]]);
check('an unknown theme falls back to Classic', $bogus->get('appearance.theme') === 'classic');
check('an unknown entry display falls back to Auto', $bogus->get('appearance.entryDisplay') === 'auto');
check('an unknown decoration falls back to Balanced', $bogus->get('appearance.decoration') === 'balanced');
// Artwork belongs to a theme, so asking Classic for the Celebration set is not
// merely invalid — it would name a file that theme does not carry.
$wrongArt = PrintConfig::fromArray(['appearance' => ['theme' => 'classic', 'artwork' => 'garden']]);
check('artwork a theme does not carry is refused', $wrongArt->get('appearance.artwork') === 'none');
$wrongLayout = PrintConfig::fromArray(['layout' => 'annual', 'appearance' => ['theme' => 'celebration']]);
check('a theme that cannot dress the layout falls back to Classic',
    $wrongLayout->get('appearance.theme') === 'classic');

echo "\nThe default is still the calendar this church already prints\n";
check('Classic', $d->get('appearance.theme') === 'classic');
check('no decoration', $d->get('appearance.artwork') === 'none');
check('full colour', $d->get('appearance.inkFriendly') === false);
// Auto rather than compact: this is the one default that deliberately changed,
// and it is what lets a single celebrant use the room their day is not using.
check('and entries adapt to the room they have', $d->get('appearance.entryDisplay') === 'auto');
check('a default configuration still emits a short, readable link',
    count($d->toQuery()) <= 4);

echo "\nA saved view can restore the screen calendar without a second table\n";
$screen = PrintConfig::fromArray([
    'content' => ['sources' => ['birthdays', 'events:ministry']],
    'screen' => ['view' => 'week', 'left' => 'collapsed'],
]);
check('month/week/day/agenda is kept', $screen->get('screen.view') === 'week');
check('the left rail matches portal_shell_mods', $screen->get('screen.left') === 'collapsed');
check('an unknown view is treated as unset, not as a crash',
    PrintConfig::fromArray(['screen' => ['view' => 'gantt']])->get('screen.view') === '');
check('an unknown rail is treated as unset',
    PrintConfig::fromArray(['screen' => ['left' => 'hidden']])->get('screen.left') === '');
check('print-only views still load after the screen keys existed',
    PrintConfig::fromArray(['layout' => 'monthly'])->get('screen.view') === '');
$round = PrintConfig::fromQuery($screen->toQuery());
check('screen keys survive the query form under names that are not view=',
    $round->get('screen.view') === 'week' && $round->get('screen.left') === 'collapsed'
    && !array_key_exists('view', $screen->toQuery()));
check('view= stays a saved-row id and does not become the screen switcher',
    PrintConfig::fromQuery(['view' => 'month'])->get('screen.view') === '');
check('a default print link still omits empty screen keys',
    !array_key_exists('screenView', $d->toQuery()) && !array_key_exists('screenLeft', $d->toQuery()));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
