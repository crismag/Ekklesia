<?php

declare(strict_types=1);

/**
 * Twelve monthly themes and "Automatic".
 *
 * Automatic follows the month being printed — never today's date — and a
 * multi-month print gives each month its own theme. The seasons are Canadian:
 * winter runs December to February, across the new year. Every monthly theme
 * is distinct, readable, and keeps member-type colours visible on its cells.
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
use App\Services\Calendar\PrintConfig;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

echo "One theme for every month\n";
$ids = array_map([CalendarTheme::class, 'forMonth'], range(1, 12));
check('twelve months, twelve different themes', count(array_unique($ids)) === 12);
check('each is a monthly theme that knows its month',
    array_filter($ids, static fn ($id, $i) => CalendarTheme::isSeasonal($id) && CalendarTheme::get($id)['month'] === $i + 1, ARRAY_FILTER_USE_BOTH) === $ids);
check('an unknown month is not a monthly theme', CalendarTheme::forMonth(13) === CalendarTheme::DEFAULT);

echo "\nCanadian seasons\n";
$season = static fn (array $months): array => array_values(array_unique(array_map([CalendarTheme::class, 'seasonOf'], $months)));
check('December to February is winter, across the year boundary', $season([12, 1, 2]) === ['winter']);
check('March to May is spring', $season([3, 4, 5]) === ['spring']);
check('June to August is summer', $season([6, 7, 8]) === ['summer']);
check('September to November is fall', $season([9, 10, 11]) === ['fall']);
check("each theme's season matches its month",
    array_filter($ids, static fn ($id) => CalendarTheme::get($id)['season'] === CalendarTheme::seasonOf(CalendarTheme::get($id)['month'])) === $ids);

echo "\nDistinct, readable, and fair to member types\n";
$palettes = array_map(static fn ($id) => CalendarTheme::get($id)['palette'], $ids);
foreach (['heading', 'accent', 'band'] as $token) {
    check("no two months share a $token colour", count(array_unique(array_column($palettes, $token))) === 12);
}
$lum = static function (string $hex): float {
    $c = array_map(static fn (string $h): float => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
    $c = array_map(static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$contrast = static function (string $a, string $b) use ($lum): float {
    [$x, $y] = [$lum($a), $lum($b)];
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
};
foreach ($ids as $id) {
    $p = CalendarTheme::get($id)['palette'];
    $ok = $contrast($p['heading'], $p['band']) >= 4.5 && $contrast($p['ink'], $p['cell']) >= 7.0
        && $contrast($p['heading'], $p['cell']) >= 4.5;
    foreach (MemberTypeStyle::GROUPS as $g) {
        $ok = $ok && $contrast($g['color'], $p['cell']) >= 3.0;
    }
    check("$id: title and text read on its band and cells, and member colours stay visible", $ok);
    check("$id: December is not Christmas, and every motif is our own drawing",
        !preg_match('/christmas|santa|gift|star of/i', CalendarTheme::get($id)['blurb'] . CalendarTheme::get($id)['label'])
        && !preg_match('/<(script|image|foreignObject)|href=/i', (string) CalendarTheme::get($id)['motif']));
}
check('the monthly themes share one stylesheet, which never redefines member colours',
    !str_contains((string) file_get_contents($root . '/resources/views/print/themes/seasonal.css'), '--mt-'));

echo "\nAutomatic follows the printed month\n";
check('auto resolves to the month on the sheet', CalendarTheme::resolve('auto', 10) === 'maple-colour'
    && CalendarTheme::resolve('auto', 1) === 'northern-stillness');
check('a manual choice is kept whatever the month', CalendarTheme::resolve('open-skies', 12) === 'open-skies');
check('a theme id that no longer exists falls back to Classic', CalendarTheme::resolve('retired-theme', 5) === 'classic');
check('auto is a valid saved setting', PrintConfig::fromArray(['appearance' => ['theme' => 'auto']])->get('appearance.theme') === 'auto');
check('and survives the query round trip',
    PrintConfig::fromQuery(PrintConfig::fromArray(['appearance' => ['theme' => 'auto']])->toQuery())->get('appearance.theme') === 'auto');
check('a manual monthly theme is saved as chosen',
    PrintConfig::fromArray(['appearance' => ['theme' => 'first-frost']])->get('appearance.theme') === 'first-frost');
check('a saved theme that no longer exists opens as Classic',
    PrintConfig::fromArray(['appearance' => ['theme' => 'retired-theme']])->get('appearance.theme') === 'classic');
check('auto is only for the grids it was drawn for',
    PrintConfig::fromArray(['layout' => 'agenda', 'appearance' => ['theme' => 'auto']])->get('appearance.theme') === 'classic');

$composer = new PrintComposer($root . '/resources/views');
$render = static fn (string $theme, string $from, string $to): string => $composer->render([], new DateTimeImmutable($from),
    new DateTimeImmutable($to), ['template' => 'monthly', 'theme' => $theme, 'today' => '2026-06-15']);
$oct = $render('auto', '2026-10-01', '2026-10-31');
check('October prints in October\'s theme, though "today" is June',
    str_contains($oct, 'theme-maple-colour') && !str_contains($oct, 'theme-open-skies'));
$three = $render('auto', '2026-11-01', '2027-01-31');
check('a three-month print gives each month its own theme, across the new year',
    str_contains($three, 'theme-first-frost') && str_contains($three, 'theme-evergreen-snow')
    && str_contains($three, 'theme-northern-stillness'));
check('each later month carries its own colour tokens', substr_count($three, '--th-motif:') >= 3);
$manual = $render('open-skies', '2026-11-01', '2027-01-31');
check('a manual theme stays the same across months',
    str_contains($manual, 'theme-open-skies') && !str_contains($manual, 'theme-first-frost'));
$plain = $render('classic', '2026-10-01', '2026-10-31');
check('the default is still the church\'s Classic sheet', !str_contains($plain, 'theme-seasonal'));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
