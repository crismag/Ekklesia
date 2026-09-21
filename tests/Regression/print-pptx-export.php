<?php

declare(strict_types=1);

/**
 * The editable PowerPoint export (GET /calendar/export.pptx).
 *
 * The file is opened back up as a package and read as XML, the way PowerPoint
 * would: every part it names must be there, and the calendar must be text a
 * person can edit, not a picture of text. It carries the same names as the
 * printed page because both plan from MonthGridPlan.
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

use App\Services\Calendar\PptxCalendarWriter;
use App\Services\Calendar\PptxThemeReader;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

$tmp = sys_get_temp_dir() . '/ekk-pptx-export-' . bin2hex(random_bytes(4));
mkdir($tmp);
$writer = static fn (): PptxCalendarWriter => new PptxCalendarWriter($root . '/resources/print/pptx/skeleton.pptx');

/** @return array{zip:ZipArchive,slides:list<string>,path:string} */
$open = static function (string $bytes, string $name) use ($tmp): array {
    $path = "$tmp/$name.pptx";
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    $zip->open($path);
    $slides = [];
    for ($i = 1; ($x = $zip->getFromName("ppt/slides/slide$i.xml")) !== false; $i++) {
        $slides[] = $x;
    }
    return ['zip' => $zip, 'slides' => $slides, 'path' => $path];
};
/** Every run of text on a slide, in order. */
$texts = static function (string $xml): array {
    preg_match_all('#<a:t>(.*?)</a:t>#s', $xml, $m);
    return array_map(static fn (string $t): string => html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
};

$person = static fn (string $first, string $preferred, string $last, string $type): array =>
    ['first' => $first, 'preferred' => $preferred, 'last' => $last, 'memberType' => $type];
$items = [];
foreach ([['Genoveva', 'Gen', 'Arden', 'Radical', 22], ['Hermogenes', '', 'Tolentino', 'G&A', 78],
          ['Ignacia', '', 'Brightwater', 'Trailblazer', 31], ['Jessie James', 'JJ', 'Quill', 'Radical', 14],
          ['Vangie', '', 'Marchetti', '', 14]] as [$f, $p, $l, $t, $age]) {
    $items[] = ['kind' => 'birth', 'source' => 'birthdays', 'date' => '2026-09-13',
        'title' => "$f $l ($age)", 'person' => $person($f, $p, $l, $t)];
}
$items[] = ['kind' => 'event', 'source' => 'events:ministry', 'title' => 'Sunday Worship <Service> & Prayer',
    'starts_at' => '2026-09-13 10:00:00', 'ends_at' => '2026-09-13 11:30:00'];
$items[] = ['kind' => 'event', 'source' => 'events:ministry', 'title' => 'Elders meeting',
    'starts_at' => '2026-09-22 19:00:00', 'ends_at' => '2026-09-22 20:00:00'];
$start = new DateTimeImmutable('2026-09-01');
$end = new DateTimeImmutable('2026-09-30');
$base = ['template' => 'monthly', 'paper' => 'letter', 'orientation' => 'portrait', 'today' => '2026-09-21',
    'church' => 'Grace Fellowship', 'sources' => ['birthdays', 'events:ministry'], 'headerTitle' => 'Church calendar'];

echo "\nA month as a PowerPoint file\n";
$p = $open($writer()->write($items, $start, $end, $base), 'plain');
$zip = $p['zip'];
check('one slide for one month', count($p['slides']) === 1);
$types = (string) $zip->getFromName('[Content_Types].xml');
$pres = (string) $zip->getFromName('ppt/presentation.xml');
$presRels = (string) $zip->getFromName('ppt/_rels/presentation.xml.rels');
check('the slide is declared, listed and related', str_contains($types, '/ppt/slides/slide1.xml')
    && str_contains($pres, '<p:sldIdLst><p:sldId id="256" r:id="rId101"/></p:sldIdLst>')
    && str_contains($presRels, 'Id="rId101"') && str_contains($presRels, 'Target="slides/slide1.xml"'));
check('the page is letter portrait', str_contains($pres, '<p:sldSz cx="7772400" cy="10058400"/>'));
$slideRels = (string) $zip->getFromName('ppt/slides/_rels/slide1.xml.rels');
check('the slide uses a layout that exists', str_contains($slideRels, '../slideLayouts/slideLayout7.xml')
    && $zip->locateName('ppt/slideLayouts/slideLayout7.xml') !== false);
check('the skeleton thumbnail is gone, and nothing points at it', $zip->locateName('docProps/thumbnail.jpeg') === false
    && !str_contains((string) $zip->getFromName('_rels/.rels'), 'thumbnail'));
check('the document is titled for the calendar', str_contains((string) $zip->getFromName('docProps/core.xml'), '<dc:title>Church calendar</dc:title>'));
$ok = true;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    if (preg_match('/\.(xml|rels)$/', $name) === 1) {
        $ok = $ok && @simplexml_load_string((string) $zip->getFromName($name)) !== false;
    }
}
check('every part is well-formed XML', $ok);
$slide = $p['slides'][0];
$t = $texts($slide);
check('the title, church and month are text', in_array('Church calendar', $t, true)
    && in_array('September 2026', $t, true) && (bool) preg_grep('/GRACE FELLOWSHIP/', $t));
check('the weekdays and dates are text', in_array('SUN', $t, true) && in_array('SAT', $t, true)
    && in_array('13', $t, true) && in_array('30', $t, true));
check('each day is its own named shape', str_contains($slide, 'name="Day 13 Sep"') && str_contains($slide, 'name="Entries 13 Sep"'));
check('an event is its title and time, escaped', in_array('Sunday Worship <Service> & Prayer', $t, true)
    && str_contains($slide, 'Sunday Worship &lt;Service&gt; &amp; Prayer') && (bool) preg_grep('/^ · 10/', $t));
check('a celebrant goes by their preferred name', in_array('Gen', $t, true) || (bool) preg_grep('/^Gen\b/', $t));
check('JJ, not Jessie James', (bool) preg_grep('/^JJ\b/', $t) && !preg_grep('/Jessie James/', $t));
check('no ages', !preg_grep('/\(\d+\)/', $t) && !preg_grep('/\b(14|22|31|78)\b(?! ?(am|pm|:))/', array_filter($t, static fn ($s) => !ctype_digit($s))));
check('a Radical name is highlighted in its tint', preg_match('#<a:highlight><a:srgbClr val="FDE0C4"/></a:highlight>.{0,120}<a:t>Gen#s', $slide) === 1);
check('a G&A name is highlighted in its tint', preg_match('#<a:highlight><a:srgbClr val="D3E9FA"/></a:highlight>.{0,120}<a:t>Hermogenes#s', $slide) === 1);
check('a name with no member type is not highlighted', preg_match('#<a:highlight>[^/]*/></a:highlight>.{0,120}<a:t>Vangie#s', $slide) === 0);
check('the member-type key is one group', str_contains($slide, 'name="Member-type key"') && in_array('Radical', $t, true)
    && in_array('Not recorded', $t, true));
check('text is not flattened into a picture', !str_contains($slide, '<p:pic>') && $zip->locateName('ppt/media/image1.png') === false);
check('days shrink their own text rather than spill', substr_count($slide, '<a:normAutofit/>') >= 2);
check('runs keep DrawingML order: fill, highlight, font', preg_match('#<a:highlight>.*?</a:highlight><a:latin#s', $slide) === 1
    && preg_match('#</a:highlight><a:solidFill#', $slide) === 0);
$zip->close();

echo "\nMember types as text colour\n";
$p = $open($writer()->write($items, $start, $end, $base + ['memberMark' => 'text']), 'text');
check('a Radical name is set in its ink, not highlighted', preg_match('#<a:srgbClr val="A14D06"/></a:solidFill><a:latin[^>]*/></a:rPr><a:t>Gen#', $p['slides'][0]) === 1
    && !str_contains($p['slides'][0], '<a:highlight>'));
$p['zip']->close();

echo "\nSeveral months, and growing\n";
$p = $open($writer()->write($items, new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-11-30'), $base), 'three');
check('one slide per month', count($p['slides']) === 3 && str_contains((string) $p['zip']->getFromName('ppt/presentation.xml'), 'id="258" r:id="rId103"'));
check('each slide names its month', in_array('October 2026', $texts($p['slides'][1]), true) && in_array('November 2026', $texts($p['slides'][2]), true));
$p['zip']->close();
$busy = $items;
for ($d = 1; $d <= 30; $d++) {
    for ($k = 0; $k < 9; $k++) {
        $busy[] = ['kind' => 'event', 'source' => 'events:ministry', 'title' => "Meeting $k with a long enough name",
            'starts_at' => sprintf('2026-09-%02d %02d:00:00', $d, 8 + $k), 'ends_at' => sprintf('2026-09-%02d %02d:30:00', $d, 8 + $k)];
    }
}
$p = $open($writer()->write($busy, $start, $end, $base + ['pageHeight' => 'grow']), 'grow');
check('growing to fit continues on another slide', count($p['slides']) >= 2 && (bool) preg_grep('/\(continued\)/', $texts($p['slides'][1])));
check('growing hides nothing', !preg_grep('/^\+\d+ more$/', array_merge(...array_map($texts, $p['slides']))));
$p['zip']->close();
$p = $open($writer()->write($busy, $start, $end, $base), 'fit');
check('one page per month keeps the month on one slide, with "+n more"', count($p['slides']) === 1
    && (bool) preg_grep('/^\+\d+ more$/', $texts($p['slides'][0])));
$p['zip']->close();

echo "\nA background picture\n";
$bg = ['url' => '/x', 'width' => 1200, 'height' => 900, 'fit' => 'cover', 'x' => 'center', 'y' => 'center', 'opacity' => 0.4, 'overlay' => 0.5];
$p = $open($writer()->write($items, $start, $end, $base + ['background' => $bg], null, $root . '/tests/fixtures/print-background.jpg'), 'bg');
$slide = $p['slides'][0];
check('the picture is in the package once, as a JPEG', $p['zip']->locateName('ppt/media/image1.jpeg') !== false
    && str_contains((string) $p['zip']->getFromName('ppt/slides/_rels/slide1.xml.rels'), 'Target="../media/image1.jpeg"'));
check('it keeps its transparency and sits under a white wash', str_contains($slide, '<a:alphaModFix amt="40000"/>')
    && preg_match('#name="Readability wash".*?<a:srgbClr val="FFFFFF"><a:alpha val="50000"/>#s', $slide) === 1);
check('it is cropped to cover the page', str_contains($slide, '<a:srcRect l='));
check('it is behind the calendar', strpos($slide, 'Background picture') < strpos($slide, 'name="Title"'));
$p['zip']->close();

echo "\nA PowerPoint theme\n";
$read = PptxThemeReader::read($root . '/tests/fixtures/print-theme-example.pptx', 'example.pptx');
check('the example theme reads', $read['ok'] === true);
$assetDir = "$tmp/assets";
mkdir($assetDir);
// Stored as the theme service stores a version: files named with their
// extension, and the model's references to match.
$model = $read['model'];
foreach ($read['assets'] as $key => $asset) {
    file_put_contents("$assetDir/$key.{$asset['ext']}", $asset['data']);
}
foreach ($model['elements'] as &$el) {
    if (isset($el['asset'])) {
        $el['asset'] .= '.' . $read['assets'][$el['asset']]['ext'];
    }
}
unset($el);
$files = static fn (string $name): ?string => is_file("$assetDir/" . basename($name)) ? "$assetDir/" . basename($name) : null;
$pptx = ['model' => $model, 'assetBase' => '/x/', 'name' => 'Example', 'version' => 1, 'current' => 1];
$p = $open($writer()->write($items, $start, $end, $base + ['pptx' => $pptx], $files), 'theme');
$slide = $p['slides'][0];
$grid = $read['model']['regions']['CALENDAR_GRID'];
check('the page is the theme\'s', str_contains((string) $p['zip']->getFromName('ppt/presentation.xml'),
    'cx="' . (int) round($read['model']['page']['w'] * 914400) . '"'));
check('the theme\'s artwork is native objects', str_contains($slide, 'name="Theme: '));
check('its picture is in the package', $p['zip']->locateName('ppt/media/image1.png') !== false
    || $p['zip']->locateName('ppt/media/image1.jpeg') !== false);
check('the grid starts at the theme\'s grid region', str_contains($slide, '<a:off x="' . (int) round($grid['x'] * 914400) . '" y="' . (int) round($grid['y'] * 914400) . '"/>'));
check('the artwork is behind the calendar', strpos($slide, 'Theme: ') < strpos($slide, 'name="Day 1 Sep"'));
check('guide boxes are not exported', !preg_match('/name="[^"]*GUIDE/i', $slide));
$p['zip']->close();

array_map('unlink', glob("$assetDir/*") ?: []);
rmdir($assetDir);
array_map('unlink', glob("$tmp/*") ?: []);
rmdir($tmp);
printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
