<?php

declare(strict_types=1);

/**
 * PowerPoint calendar themes: what is let in, and what is made of it.
 *
 * Every hostile or broken file here is built from the official starter at run
 * time (macros, embedded objects, linked content, encryption, archive bombs,
 * DTDs, wrong sizes, missing or cramped regions), so the checks stay honest as
 * the starter changes. The example theme in tests/fixtures was decorated from
 * the starter the way a church user would.
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

use App\Services\Calendar\PptxThemeReader;
use App\Services\Calendar\PptxThemeRenderer;
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

$starter = $root . '/resources/print/starters/ekklesia-calendar-theme-letter-portrait.pptx';
$tmp = sys_get_temp_dir() . '/ekk-pptx-' . bin2hex(random_bytes(4));
mkdir($tmp);
/** A copy of the starter with parts changed: name => new content (null deletes). */
$variant = static function (string $name, array $changes) use ($starter, $tmp): string {
    $path = "$tmp/$name";
    copy($starter, $path);
    $zip = new ZipArchive();
    $zip->open($path);
    foreach ($changes as $part => $content) {
        if (is_callable($content)) {
            $content = $content((string) $zip->getFromName($part));
        }
        $content === null ? $zip->deleteName($part) : $zip->addFromString($part, $content);
    }
    $zip->close();
    return $path;
};
$refusal = static function (string $path, string $name = 'theme.pptx', ?int $bytes = null): string {
    $r = PptxThemeReader::read($path, $name, $bytes);
    return $r['ok'] ? '' : implode(' ', $r['errors']);
};

echo "The official starters are valid themes\n";
foreach (glob($root . '/resources/print/starters/*.pptx') ?: [] as $file) {
    $r = PptxThemeReader::read($file, basename($file));
    check(basename($file) . ' is accepted with every region', $r['ok']
        && array_diff(PptxThemeReader::REQUIRED_REGIONS, array_keys($r['model']['regions'])) === []);
}
$ok = PptxThemeReader::read($starter, 'x.pptx');
check('the page is read from the slide size', $ok['model']['page']['paper'] === 'letter' && $ok['model']['page']['orientation'] === 'portrait');
check('guides and regions are not drawn as artwork', array_filter($ok['model']['elements'],
    static fn ($e) => preg_match('/^(GUIDE|CALENDAR_|MONTH_|SUBTITLE|LEGEND|FOOTER)/i', (string) $e['name']) === 1) === []);
$ex = PptxThemeReader::read($root . '/tests/fixtures/print-theme-example.pptx', 'example.pptx');
check('a decorated theme is accepted, its picture re-encoded', $ex['ok'] && count($ex['assets']) === 1
    && in_array(array_values($ex['assets'])[0]['ext'], ['png', 'jpg'], true));
check('an unavailable font is named and substituted', str_contains(implode(' ', $ex['warnings']), 'brush script mt'));
check('decorative text and rotation are kept', (bool) array_filter($ex['model']['elements'],
    static fn ($e) => isset($e['text']) && (float) $e['rot'] !== 0.0));

echo "\nWhat is refused\n";
check('a .pptm file', str_contains($refusal($starter, 'theme.pptm'), 'Macro-enabled'));
check('a file that is not .pptx', str_contains($refusal($starter, 'theme.pdf'), '.pptx'));
check('macro content types', str_contains($refusal($variant('m.pptx', ['[Content_Types].xml' => static fn ($x) =>
    str_replace('presentationml.presentation.main+xml', 'presentationml.presentation.macroEnabled.main+xml', $x)])), 'Macro-enabled'));
check('a VBA project part', str_contains($refusal($variant('v.pptx', ['ppt/vbaProject.bin' => 'x'])), 'macros or embedded'));
check('an embedded object', str_contains($refusal($variant('o.pptx', ['ppt/embeddings/oleObject1.bin' => 'x'])), 'macros or embedded'));
check('a linked picture fetched from elsewhere', str_contains($refusal($variant('l.pptx', [
    'ppt/slides/_rels/slide1.xml.rels' => static fn ($x) => str_replace('</Relationships>',
        '<Relationship Id="rId99" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
        . 'Target="http://example.invalid/x.png" TargetMode="External"/></Relationships>', $x)])), 'links to content'));
check('video or sound', str_contains($refusal($variant('a.pptx', [
    'ppt/slides/_rels/slide1.xml.rels' => static fn ($x) => str_replace('</Relationships>',
        '<Relationship Id="rId98" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/video" '
        . 'Target="../media/v.mp4"/></Relationships>', $x)])), 'video or sound'));
file_put_contents("$tmp/enc.pptx", "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 512));
check('a password-protected presentation', str_contains($refusal("$tmp/enc.pptx"), 'password-protected'));
file_put_contents("$tmp/text.pptx", 'hello');
check('a file that is not a presentation', str_contains($refusal("$tmp/text.pptx"), 'not a PowerPoint'));
file_put_contents("$tmp/cut.pptx", substr((string) file_get_contents($starter), 0, 4000));
check('a damaged archive', $refusal("$tmp/cut.pptx") !== '');
check('a file over 25 MB', str_contains($refusal($starter, 'big.pptx', 26 * 1024 * 1024), '25 MB'));
check('an archive bomb', str_contains($refusal($variant('bomb.pptx', ['ppt/media/zeros.png' => str_repeat("\0", 8 * 1024 * 1024)])), 'expands suspiciously'));
check('a DTD in a part (entity expansion)', !PptxThemeReader::read($variant('dtd.pptx', ['ppt/slides/slide1.xml' => static fn ($x) =>
    preg_replace('/^(<\?xml[^>]*\?>)/', '$1<!DOCTYPE x [<!ENTITY a "aaaa">]>', $x)]), 't.pptx')['ok']);
check('an unsupported slide size', str_contains($refusal($variant('size.pptx', ['ppt/presentation.xml' => static fn ($x) =>
    preg_replace('/<p:sldSz cx="\d+" cy="\d+"/', '<p:sldSz cx="9144000" cy="5143500"', $x)])), 'slide is'));
check('a missing required region, by name', str_contains($refusal($variant('miss.pptx', ['ppt/slides/slide1.xml' => static fn ($x) =>
    str_replace('name="CALENDAR_GRID"', 'name="Box 12"', $x)])), 'no shape named CALENDAR_GRID'));
check('a calendar area too small to read', str_contains($refusal($variant('small.pptx', ['ppt/slides/slide1.xml' => static fn ($x) =>
    preg_replace('/(name="CALENDAR_GRID".*?<a:ext )cx="\d+" cy="\d+"/s', '$1cx="1828800" cy="1828800"', $x, 1)])), 'too small'));
check('a title placed over the calendar', str_contains($refusal($variant('over.pptx', ['ppt/slides/slide1.xml' => static fn ($x) =>
    preg_replace('/(name="CALENDAR_TITLE".*?<a:off )x="\d+" y="\d+"/s', '$1x="914400" y="3657600"', $x, 1)])), 'overlaps CALENDAR_GRID'));
check('printer settings in an ordinary file are allowed', PptxThemeReader::read($starter, 's.pptx')['ok']);

echo "\nWhat is drawn\n";
$model = $ok['model'];
$model['elements'][] = ['kind' => 'shape', 'geom' => 'rect', 'name' => 'x', 'x' => 1, 'y' => 1, 'w' => 1, 'h' => 1,
    'rot' => 0, 'flipH' => false, 'flipV' => false, 'text' => ['anchor' => 't', 'paras' => [['align' => 'l',
    'runs' => [['t' => '<script>alert(1)</script>', 'sz' => 12, 'b' => false, 'i' => false, 'color' => '#000000', 'family' => 'sans']]]]]];
$html = PptxThemeRenderer::artwork($model, '/print/themes/1/1/');
check('slide text is written as text, never markup', !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'));
check('the artwork is hidden from assistive technology', str_contains($html, 'class="pptx-art" aria-hidden="true"'));

$composer = new PrintComposer($root . '/resources/views');
$page = $composer->render([], new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'), [
    'template' => 'monthly', 'paper' => 'a4', 'orientation' => 'landscape',
    'pptx' => ['model' => $ex['model'], 'assetBase' => '/print/themes/9/1/', 'name' => 'Harvest', 'version' => 1, 'current' => 2],
]);
check('the title and month are written into their regions', str_contains($page, 'class="rg rg-title"') && str_contains($page, 'class="rg rg-month"'));
check('the calendar flows from the top of the grid region', preg_match('/class="rg-flow" style="padding-top:1\.650in/', $page) === 1);
check('the page follows the theme, not the Paper setting', str_contains($page, 'size: 8.5in 11in') && str_contains($page, 'follows the theme'));
check('a newer version is mentioned, the pinned one used', str_contains($page, 'version 2 is available'));
check('the grid keeps a readable wash over artwork', str_contains($page, '.theme-pptx .cal td { background: rgba(255,255,255,.9)'));

echo "\nSaved designs\n";
check('a PowerPoint theme is a valid choice, pinned to a version',
    PrintConfig::fromArray(['appearance' => ['theme' => 'pptx:4', 'themeVersion' => 2]])->get('appearance.themeVersion') === 2);
check('and survives the query round trip', PrintConfig::fromQuery(PrintConfig::fromArray(['appearance' =>
    ['theme' => 'pptx:4', 'themeVersion' => 2]])->toQuery())->get('appearance.theme') === 'pptx:4');
check('a malformed theme id falls back to Classic', PrintConfig::fromArray(['appearance' => ['theme' => 'pptx:abc']])->get('appearance.theme') === 'classic');
check('a PowerPoint theme is only for the grids', PrintConfig::fromArray(['layout' => 'agenda',
    'appearance' => ['theme' => 'pptx:4']])->get('appearance.theme') === 'classic');

array_map('unlink', glob("$tmp/*") ?: []);
rmdir($tmp);
printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
