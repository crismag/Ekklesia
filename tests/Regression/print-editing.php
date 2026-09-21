<?php

declare(strict_types=1);

/**
 * Editing a printed calendar's own text, and nothing else.
 *
 * The title, the line under it, the notes and the footer note are print-only
 * text and may be typed straight onto the sheet in the studio. Calendar
 * entries are data: they are never editable there, and offer the event itself.
 * Whatever the browser sends back is judged by RichText, which keeps a few
 * tags and exactly two alignment classes.
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

use App\Services\Calendar\PrintComposer;
use App\Services\Calendar\RichText;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

echo "What the browser sends back is judged\n";
check('centring from the toolbar is kept as a class',
    RichText::sanitize('<p style="text-align: center;">Hi</p>') === '<p class="al-center">Hi</p>');
check('right alignment on a heading is kept', RichText::sanitize('<h3 style="text-align:right">T</h3>') === '<h3 class="al-right">T</h3>');
check('any other class or style is dropped', RichText::sanitize('<p class="al-center big" style="color:red">A</p>') === '<p class="al-center">A</p>');
check('alignment is only for paragraphs and headings', RichText::sanitize('<li style="text-align:center">x</li>') === '<li>x</li>');
check('event handlers never survive', !str_contains(RichText::sanitize('<p onclick="x()" style="text-align:center">y</p>'), 'onclick'));
check('script, images and links are removed', RichText::sanitize('<script>a()</script><img src=x onerror=b()><a href="javascript:c()">d</a>') === 'd');
check('bold and italic are kept', RichText::sanitize('<p><b>a</b> <i>b</i></p>') === '<p><b>a</b> <i>b</i></p>');

echo "\nEditing marks appear only while editing\n";
$composer = new PrintComposer($root . '/resources/views');
$items = [['kind' => 'event', 'source' => 'events:ministry', 'title' => 'Youth Fellowship',
    'starts_at' => '2026-09-13 14:00:00', 'href' => '/events/4']];
$render = static fn (bool $editable): string => $composer->render($items, new DateTimeImmutable('2026-09-01'),
    new DateTimeImmutable('2026-09-30'), ['template' => 'monthly', 'editable' => $editable, 'headerTitle' => 'Autumn']);
$editing = $render(true);
$printing = $render(false);
foreach (['title', 'subtitle', 'top', 'bottom', 'footer'] as $field) {
    check("the $field is editable on the sheet", str_contains($editing, 'data-edit="' . $field . '"'));
}
check('calendar entries are not editable, they link to the event', str_contains($editing, 'data-href="/events/4"')
    && !preg_match('/class="ent[^"]*"[^>]*data-edit/', $editing));
check('printing carries no editing marks', !str_contains($printing, 'data-edit="') && !str_contains($printing, 'data-href="'));
check('an empty note takes no room when printing', !str_contains($printing, 'doc-info--top'));
check('empty editable regions are hidden on paper', str_contains($editing, '[data-edit]:empty, .doc-info:empty { display: none !important; }'));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
