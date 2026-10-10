<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use DateTimeImmutable;
use ZipArchive;

/**
 * The printed calendar as an editable PowerPoint file.
 *
 * Built from the same composition as the printed page (PrintComposer::context
 * and MonthGridPlan), so the names, dates, member-type marks and "+n more"
 * are the ones on paper. Everything is a native PowerPoint object that can be
 * edited, moved or restyled:
 *
 *   title, subtitle, church line, month      text boxes
 *   weekday names, date numbers              text boxes
 *   each day's events and birthdays          one text box per day, a paragraph each
 *   the grid                                 a rectangle per day
 *   member-type key                          a group of swatches and labels
 *   a PowerPoint theme's artwork             its own shapes, pictures and text
 *   a background picture                     a picture with its transparency,
 *                                            under a white wash rectangle
 *
 * Not carried over: the built-in themes' small line drawings and the
 * Planner/Celebration artwork (SVG), and the automatic fit-to-page scaling
 * (PowerPoint's own "shrink text on overflow" is set on every day instead).
 *
 * The package starts from resources/print/pptx/skeleton.pptx (a presentation
 * saved by python-pptx: master, layouts and theme), so only slides, pictures
 * and the part lists are written here.
 */
final class PptxCalendarWriter
{
    private const EMU = 914400;
    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';
    private const FONTS = ['serif' => 'Georgia', 'sans' => 'Arial', 'display' => 'Arial Black', 'mono' => 'Courier New'];
    private const WEEKDAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

    /** @var list<array{name:string,data:string}> media parts */
    private array $media = [];
    /** @var list<array{file:string,rid:string}> the current slide's picture relationships */
    private array $slideRels = [];
    private int $shapeId = 2;

    public function __construct(private readonly string $skeleton)
    {
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $options the print job's options
     * @param callable(string):?string|null $themeFile path of a PowerPoint theme's stored picture
     * @param ?string $backgroundFile the background picture's stored file
     * @return string the .pptx bytes
     */
    public function write(array $items, DateTimeImmutable $start, DateTimeImmutable $end, array $options,
        ?callable $themeFile = null, ?string $backgroundFile = null): string
    {
        $composer = new PrintComposer(dirname(__DIR__, 3) . '/resources/views');
        $options['template'] = 'monthly';
        $ctx = $composer->context($items, $start, $end, $options);
        $plan = MonthGridPlan::fromContext($ctx);

        $pw = (float) $ctx['paperWidthIn'];
        $ph = (float) $ctx['paperHeightIn'];
        $pptx = is_array($ctx['pptx'] ?? null) ? $ctx['pptx'] : null;
        $palette = $this->palette($ctx);
        $font = self::FONTS[$ctx['fontChoice'] ?? 'serif'] ?? 'Georgia';

        $slides = [];
        foreach ($plan['months'] as $mi => $planned) {
            // A multi-month "auto" print gives each month its own theme colours.
            $monthPalette = $palette;
            $key = $planned['month']['year'] . '-' . $planned['month']['month'];
            if (isset($ctx['monthThemes'][$key])) {
                $monthPalette = (array) CalendarTheme::get($ctx['monthThemes'][$key])['palette'] + $palette;
            }
            foreach ($this->pagesOf($planned, $plan, $ctx, $pptx, $ph) as $pi => $rows) {
                $slides[] = $this->slide($ctx, $plan, $planned, $rows, $mi === 0 && $pi === 0, $pi > 0,
                    $monthPalette, $font, $pw, $ph, $pptx, $themeFile, $backgroundFile);
            }
        }

        return $this->package($slides, $pw, $ph, (string) ($ctx['docTitle'] ?? 'Calendar'));
    }

    /**
     * Which week rows go on which slide. One page per month keeps a month on
     * one slide; growing to fit continues on further slides, between weeks.
     *
     * @param array<string,mixed> $planned
     * @return list<array{weeks:list<array<int,mixed>>,heights:list<float>}>
     */
    private function pagesOf(array $planned, array $plan, array $ctx, ?array $pptx, float $ph): array
    {
        $weeks = $planned['weeks'];
        $rowMin = (float) $plan['rowHeightIn'];
        if (!$plan['grow']) {
            return [['weeks' => $weeks, 'heights' => array_fill(0, count($weeks), $rowMin)]];
        }
        $ts = (float) $plan['typeScale'];
        $heights = [];
        foreach ($weeks as $week) {
            $h = $rowMin;
            foreach ($week as $cell) {
                $need = 0.28 * $ts;
                foreach ($cell['items'] as $item) {
                    $need += CellPlan::entryHeightIn($item, $cell['plan']['tier'], (float) $plan['cellTextWidthIn'], $ts, true);
                }
                $h = max($h, $need + 0.06);
            }
            $heights[] = $h;
        }
        $top = $pptx !== null ? (float) $pptx['model']['regions']['CALENDAR_GRID']['y'] + 0.25 : 1.75;
        $room = $pptx !== null ? (float) $pptx['model']['regions']['CALENDAR_GRID']['h'] - 0.25 : $ph - $top - 1.0;
        $pages = [];
        $cur = ['weeks' => [], 'heights' => []];
        $used = 0.0;
        foreach ($weeks as $i => $week) {
            if ($cur['weeks'] !== [] && $used + $heights[$i] > $room) {
                $pages[] = $cur;
                $cur = ['weeks' => [], 'heights' => []];
                $used = 0.0;
                $room = $ph - 1.25;
            }
            $cur['weeks'][] = $week;
            $cur['heights'][] = $heights[$i];
            $used += $heights[$i];
        }
        $pages[] = $cur;

        return $pages;
    }

    /** @return array<string,string> hex colours without '#' */
    private function palette(array $ctx): array
    {
        $p = is_array($ctx['pptx'] ?? null) ? (array) ($ctx['pptx']['model']['palette'] ?? [])
            : (array) (CalendarTheme::get((string) $ctx['theme'])['palette'] ?? []);
        $p += ['paper' => '#ffffff', 'ink' => '#16211c', 'heading' => '#16211c', 'accent' => '#0c5a45',
            'grid' => '#d3ddd7', 'cell' => '#ffffff', 'band' => '#eef4f0'];
        if (!empty($ctx['inkFriendly'])) {
            $p['band'] = '#ffffff';
            $p['cell'] = '#ffffff';
        }

        return $p;
    }

    /**
     * One slide's XML and its picture relationships.
     *
     * @param array<string,mixed> $pageRows
     * @return array{xml:string,rels:list<array{file:string,rid:string}>}
     */
    private function slide(array $ctx, array $plan, array $planned, array $pageRows, bool $first, bool $continued,
        array $pal, string $font, float $pw, float $ph, ?array $pptx, ?callable $themeFile, ?string $backgroundFile): array
    {
        $this->slideRels = [];
        $this->shapeId = 2;
        $x = '';
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $hex = static fn (string $c): string => strtoupper(ltrim($c, '#'));

        // ---- behind everything ------------------------------------------
        $hasBg = $backgroundFile !== null && is_file($backgroundFile) && is_array($ctx['background'] ?? null);
        if ($hasBg) {
            $bg = $ctx['background'];
            $x .= $this->picture('Background picture', $backgroundFile, 0, 0, $pw, $ph, (float) $bg['opacity'],
                (string) $bg['fit'], (int) $bg['width'], (int) $bg['height'], 0.0, null,
                (string) ($bg['x'] ?? 'center'), (string) ($bg['y'] ?? 'center'));
            $x .= $this->shape('Readability wash', 0, 0, $pw, $ph, ['fill' => 'FFFFFF', 'alpha' => (float) $bg['overlay']]);
        }
        if ($pptx !== null) {
            $x .= $this->artwork($pptx['model'], $themeFile, $font);
        }

        // ---- the heading ------------------------------------------------
        $show = (array) ($ctx['headerShow'] ?? []);
        $title = (string) (($ctx['headerTitle'] ?? '') !== '' ? $ctx['headerTitle'] : ($ctx['docTitle'] ?? ''));
        $period = (string) $planned['month']['title'];
        $kicker = [];
        if (!empty($show['church'])) {
            $kicker[] = (string) ($ctx['branding']['church'] ?? '');
        }
        if (!empty($show['location']) && ($ctx['branding']['subtitle'] ?? '') !== '') {
            $kicker[] = (string) $ctx['branding']['subtitle'];
        }
        $subtitle = trim((string) ($ctx['headerSubtitle'] ?? ''));
        $rg = $pptx['model']['regions'] ?? null;
        $m = 0.5;
        if ($continued) {
            $x .= $this->text('Month (continued)', $m, 0.35, $pw - 2 * $m, 0.4,
                [[['t' => $period . ' (continued)', 'sz' => 14, 'b' => true, 'color' => $hex($pal['heading']), 'font' => $font]]]);
        } else {
            $tBox = $rg['CALENDAR_TITLE'] ?? ['x' => $m, 'y' => 0.45, 'w' => $pw - 2 * $m - 2.6, 'h' => 0.85];
            $paras = [];
            if ($kicker !== []) {
                $paras[] = [['t' => strtoupper(implode('  ·  ', array_filter($kicker))), 'sz' => 8, 'b' => true,
                    'color' => $hex($pal['accent']), 'font' => $font, 'spc' => 150]];
            }
            if (!empty($show['docType']) && $title !== '') {
                $paras[] = [['t' => $title, 'sz' => 28, 'b' => true, 'color' => $hex($pal['heading']), 'font' => $font]];
            }
            if ($subtitle !== '' && !isset($rg['SUBTITLE'])) {
                $paras[] = [['t' => $subtitle, 'sz' => 11, 'color' => $hex($pal['ink']), 'font' => $font]];
            }
            if ($paras !== []) {
                $x .= $this->text('Title', $tBox['x'], $tBox['y'], $tBox['w'], $tBox['h'], $paras, 'b', true);
            }
            if (isset($rg['SUBTITLE']) && $subtitle !== '') {
                $s = $rg['SUBTITLE'];
                $x .= $this->text('Subtitle', $s['x'], $s['y'], $s['w'], $s['h'], [[['t' => $subtitle, 'sz' => 11,
                    'color' => $hex($pal['ink']), 'font' => $font]]], 'ctr', true);
            }
            if (!empty($show['period'])) {
                $mBox = $rg['MONTH_HEADING'] ?? ['x' => $pw - $m - 2.5, 'y' => 0.8, 'w' => 2.5, 'h' => 0.5];
                $x .= $this->text('Month', $mBox['x'], $mBox['y'], $mBox['w'], $mBox['h'], [[['t' => $period, 'sz' => 18,
                    'color' => $hex($pal['heading']), 'font' => $font]]], $rg !== null ? 'ctr' : 'b', true, $rg !== null ? 'l' : 'r');
            }
            if ($rg === null) {
                $x .= $this->shape('Heading rule', $m, 1.36, $pw - 2 * $m, 0.02, ['fill' => $hex($pal['accent'])]);
            }
        }

        // ---- the grid ---------------------------------------------------
        $gBox = $rg['CALENDAR_GRID'] ?? ['x' => $m, 'y' => 1.5, 'w' => $pw - 2 * $m, 'h' => $ph - 1.5 - 1.0];
        $top = $continued ? 0.85 : (float) $gBox['y'];
        $left = (float) $gBox['x'];
        $width = (float) $gBox['w'];
        $notesTop = trim(strip_tags(str_replace(['</p>', '<br>', '<br />'], "\n", (string) ($ctx['topInfo'] ?? ''))));
        if (!$continued && $notesTop !== '') {
            $lines = max(1, (int) ceil(mb_strlen($notesTop) / max(20, $width * 14)));
            $h = 0.18 * $lines + 0.1;
            $x .= $this->text('Note above the calendar', $left, $top, $width, $h, $this->plainParas($notesTop, 9, $hex($pal['ink']), $font), 't', true);
            $top += $h + 0.05;
        }
        $headH = 0.25;
        $colW = $width / 7;
        foreach (self::WEEKDAYS as $i => $d) {
            $x .= $this->text('Weekday ' . $d, $left + $i * $colW, $top, $colW, $headH, [[['t' => $d, 'sz' => 7.5, 'b' => true,
                'color' => $hex($pal['heading']), 'font' => $font, 'spc' => 100]]], 'b');
        }
        $x .= $this->shape('Weekday rule', $left, $top + $headH, $width, 0.012, ['fill' => $hex($pal['heading'])]);
        $y = $top + $headH + 0.02;
        // Room for the rows: the plan's heights, shrunk proportionally if the
        // slide (or the theme's grid region) has less.
        $below = ($ctx['legendRows'] ?? []) !== [] && !isset($rg['LEGEND']) ? 0.35 : 0.0;
        $bottomNote = trim(strip_tags(str_replace(['</p>', '<br>', '<br />'], "\n", (string) ($ctx['bottomInfo'] ?? ''))));
        $limit = $rg !== null && !$continued ? (float) $gBox['y'] + (float) $gBox['h'] - $below
            : $ph - 0.55 - $below - ($bottomNote !== '' ? 0.6 : 0.0) - 0.35;
        $heights = $pageRows['heights'];
        $scale = min(1.0, ($limit - $y) / max(0.1, array_sum($heights)));
        $ts = (float) $plan['typeScale'];
        $mark = (string) ($ctx['memberMark'] ?? 'highlight');
        foreach ($pageRows['weeks'] as $wi => $week) {
            $rowH = $heights[$wi] * $scale;
            foreach ($week as $di => $cell) {
                $day = $cell['day'];
                $out = !($day['in_range'] ?? true);
                $cx = $left + $di * $colW;
                $label = (int) $day['day'] . ' ' . ($day['month_short'] ?? '');
                $quiet = $out || !empty($day['is_weekend']);
                // Over a background picture, as on paper: the month's days
                // are clear and the quiet ones a light wash, so the picture
                // shows through evenly.
                $cellStyle = $hasBg
                    ? ($quiet ? ['fill' => 'FFFFFF', 'alpha' => 0.4] : [])
                    : ['fill' => $quiet ? $hex($pal['cell'] === '#ffffff' ? '#f7f8f7' : $pal['cell']) : 'FFFFFF', 'alpha' => $pptx !== null ? 0.9 : 1.0];
                $x .= $this->shape('Day ' . $label, $cx, $y, $colW, $rowH, $cellStyle + ['line' => $hex($pal['grid']), 'lineW' => 0.5]);
                $x .= $this->text('Date ' . $label, $cx + 0.04, $y + 0.02, 0.6, 0.22, [[['t' => (string) (int) $day['day'],
                    'sz' => 10 * $ts, 'b' => true, 'color' => $out ? 'B3BFB8' : $hex($pal['heading']), 'font' => $font]]]);
                $paras = [];
                $tier = CellPlan::tier((string) $cell['plan']['tier']);
                $sz = round((float) $tier['font'] * $ts * min(1.0, max(0.75, $scale)), 1);
                foreach (array_slice($cell['items'], 0, (int) $cell['plan']['shown']) as $item) {
                    $paras[] = $this->entryRuns($item, $sz, $font, $mark, $pal);
                }
                if ((int) $cell['plan']['hidden'] > 0) {
                    $paras[] = [['t' => '+' . (int) $cell['plan']['hidden'] . ' more', 'sz' => max(6, $sz - 1), 'i' => true, 'color' => '6B7A72', 'font' => $font]];
                }
                if ($paras !== []) {
                    $x .= $this->text('Entries ' . $label, $cx + 0.02, $y + 0.24, $colW - 0.04, max(0.1, $rowH - 0.26), $paras, 't', true);
                }
            }
            $y += $rowH;
        }

        // ---- under the grid ---------------------------------------------
        if (($ctx['legendRows'] ?? []) !== []) {
            $lBox = $rg['LEGEND'] ?? ['x' => $left, 'y' => $y + 0.08, 'w' => $width, 'h' => 0.26];
            $x .= $this->legend($ctx['legendRows'], !empty($ctx['legendNeutral']), $lBox, $mark, $font);
        }
        if ($bottomNote !== '') {
            $x .= $this->text('Note below the calendar', $left, $ph - 0.55 - 0.35 - 0.55, $width, 0.55,
                $this->plainParas($bottomNote, 9, $hex($pal['ink']), $font), 't', true);
        }
        $foot = [];
        $fShow = (array) ($ctx['footerShow'] ?? []);
        if (!empty($fShow['printed']) && ($ctx['branding']['footer'] ?? '') !== '') {
            $foot[] = (string) $ctx['branding']['footer'];
        }
        if (!empty($fShow['church'])) {
            $foot[] = (string) ($ctx['branding']['church'] ?? '');
        }
        if (trim((string) ($ctx['footerNote'] ?? '')) !== '') {
            $foot[] = trim((string) $ctx['footerNote']);
        }
        if (!empty($fShow['website']) && ($ctx['branding']['website'] ?? '') !== '') {
            $foot[] = (string) $ctx['branding']['website'];
        }
        if ($foot !== []) {
            $fBox = $rg['FOOTER'] ?? ['x' => $m, 'y' => $ph - 0.5, 'w' => $pw - 2 * $m, 'h' => 0.3];
            $x .= $this->text('Footer', $fBox['x'], $fBox['y'], $fBox['w'], $fBox['h'], [[['t' => implode('   ·   ', array_filter($foot)),
                'sz' => 7.5, 'color' => '6B7A72', 'font' => $font]]], 'ctr', true);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sld ' . self::NS . '><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
            . $x . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';

        return ['xml' => $xml, 'rels' => $this->slideRels];
    }

    /**
     * One entry as a paragraph. A birthday's name is highlighted (or coloured)
     * by member type, as on paper; an event is its title and time.
     *
     * @param array<string,mixed> $item
     * @param array<string,string> $pal
     * @return list<array<string,mixed>>
     */
    private function entryRuns(array $item, float $sz, string $font, string $mark, array $pal): array
    {
        $ink = strtoupper(ltrim($pal['ink'], '#'));
        $group = $item['group'] ?? null;
        if (($item['category'] ?? '') === 'birth') {
            $run = ['t' => (string) $item['primary'], 'sz' => $sz, 'b' => true, 'color' => $ink, 'font' => $font];
            if ($group !== null) {
                $g = MemberTypeStyle::GROUPS[$group];
                if ($mark === 'text') {
                    $run['color'] = strtoupper(ltrim($g['ink'], '#'));
                } else {
                    $run['highlight'] = strtoupper(ltrim($g['tint'], '#'));
                }
            }
            return [$run];
        }
        $runs = [['t' => (string) $item['primary'], 'sz' => $sz, 'b' => true, 'color' => $ink, 'font' => $font]];
        if (($item['secondary'] ?? '') !== '') {
            $runs[] = ['t' => ' · ' . $item['secondary'], 'sz' => max(6, $sz - 0.5), 'color' => '5C6B63', 'font' => $font];
        }

        return $runs;
    }

    /** @return list<list<array<string,mixed>>> */
    private function plainParas(string $text, float $sz, string $color, string $font): array
    {
        $out = [];
        foreach (preg_split('/\n+/', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [] as $line) {
            if (trim($line) !== '') {
                $out[] = [['t' => trim($line), 'sz' => $sz, 'color' => $color, 'font' => $font]];
            }
        }

        return $out;
    }

    /**
     * The member-type key: a group of swatches and labels.
     *
     * @param list<array{group:string,label:string}> $rows
     * @param array<string,float> $box
     */
    private function legend(array $rows, bool $neutral, array $box, string $mark, string $font): string
    {
        $children = '';
        $x = (float) $box['x'];
        $y = (float) $box['y'] + max(0.0, ((float) $box['h'] - 0.2) / 2);
        $children .= $this->text('Key title', $x, $y - 0.03, 1.1, 0.26, [[['t' => 'MEMBER TYPE', 'sz' => 7, 'b' => true, 'color' => '46534C', 'font' => $font, 'spc' => 80]]]);
        $x += 1.1;
        foreach ($rows as $row) {
            $g = MemberTypeStyle::GROUPS[$row['group']];
            $children .= $this->shape('Key swatch ' . $row['label'], $x, $y + 0.03, 0.16, 0.14,
                ['fill' => strtoupper(ltrim($mark === 'text' ? $g['ink'] : $g['tint'], '#')), 'line' => strtoupper(ltrim($g['color'], '#')), 'lineW' => 1]);
            $children .= $this->text('Key ' . $row['label'], $x + 0.2, $y - 0.03, 1.2, 0.26, [[['t' => $row['label'], 'sz' => 8,
                'color' => '46534C', 'font' => $font]]]);
            $x += 1.35;
        }
        if ($neutral) {
            $children .= $this->shape('Key swatch not recorded', $x, $y + 0.03, 0.04, 0.14, ['fill' => '8B9590']);
            $children .= $this->text('Key not recorded', $x + 0.1, $y - 0.03, 1.2, 0.26, [[['t' => 'Not recorded', 'sz' => 8, 'color' => '46534C', 'font' => $font]]]);
            $x += 1.3;
        }
        $id = $this->shapeId++;
        $o = $this->emu((float) $box['x']);
        $oy = $this->emu((float) $box['y']);
        $w = $this->emu(max(0.1, $x - (float) $box['x']));
        $h = $this->emu(max(0.26, (float) $box['h']));

        return '<p:grpSp><p:nvGrpSpPr><p:cNvPr id="' . $id . '" name="Member-type key"/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="' . $o . '" y="' . $oy . '"/><a:ext cx="' . $w . '" cy="' . $h . '"/>'
            . '<a:chOff x="' . $o . '" y="' . $oy . '"/><a:chExt cx="' . $w . '" cy="' . $h . '"/></a:xfrm></p:grpSpPr>'
            . $children . '</p:grpSp>';
    }

    /**
     * A PowerPoint theme's artwork, rebuilt as native objects from its model.
     *
     * @param array<string,mixed> $model
     */
    private function artwork(array $model, ?callable $themeFile, string $font): string
    {
        $x = '';
        $pw = (float) $model['page']['w'];
        $ph = (float) $model['page']['h'];
        $bg = $model['background'] ?? null;
        if (is_array($bg) && isset($bg['color'])) {
            $x .= $this->shape('Theme background', 0, 0, $pw, $ph, ['fill' => strtoupper(ltrim((string) $bg['color'], '#')), 'alpha' => (float) ($bg['alpha'] ?? 1)]);
        } elseif (is_array($bg) && isset($bg['asset']) && $themeFile !== null && ($f = $themeFile((string) $bg['asset'])) !== null) {
            $x .= $this->picture('Theme background', $f, 0, 0, $pw, $ph, (float) ($bg['alpha'] ?? 1), 'stretch', 0, 0);
        }
        foreach ((array) ($model['elements'] ?? []) as $el) {
            $name = 'Theme: ' . (string) ($el['name'] ?? 'shape');
            if (($el['kind'] ?? '') === 'picture') {
                if ($themeFile !== null && ($f = $themeFile((string) $el['asset'])) !== null) {
                    $x .= $this->picture($name, $f, (float) $el['x'], (float) $el['y'], (float) $el['w'], (float) $el['h'],
                        (float) ($el['opacity'] ?? 1), 'stretch', 0, 0, (float) ($el['rot'] ?? 0), $el['crop'] ?? null);
                }
                continue;
            }
            $style = ['rot' => (float) ($el['rot'] ?? 0), 'flipH' => !empty($el['flipH']), 'flipV' => !empty($el['flipV']),
                'geom' => ['rect' => 'rect', 'round' => 'roundRect', 'ellipse' => 'ellipse'][$el['geom'] ?? 'rect'] ?? 'rect'];
            // A picture fill becomes the picture, with the shape (outline,
            // text) drawn over it.
            if (isset($el['fill']['asset']) && $themeFile !== null && ($f = $themeFile((string) $el['fill']['asset'])) !== null) {
                $x .= $this->picture($name . ' picture', $f, (float) $el['x'], (float) $el['y'], (float) $el['w'], (float) $el['h'],
                    (float) ($el['fill']['alpha'] ?? 1), 'stretch', 0, 0, (float) ($el['rot'] ?? 0));
            }
            if (isset($el['fill']['color'])) {
                $style['fill'] = strtoupper(ltrim((string) $el['fill']['color'], '#'));
                $style['alpha'] = (float) ($el['fill']['alpha'] ?? 1);
            }
            if (isset($el['line']['color'])) {
                $style['line'] = strtoupper(ltrim((string) $el['line']['color'], '#'));
                $style['lineW'] = (float) ($el['line']['width'] ?? 0.75);
            }
            $paras = [];
            $anchor = 't';
            $align = 'l';
            if (is_array($el['text'] ?? null)) {
                $anchor = (string) ($el['text']['anchor'] ?? 't');
                foreach ((array) $el['text']['paras'] as $para) {
                    $align = (string) ($para['align'] ?? 'l');
                    $runs = [];
                    foreach ((array) $para['runs'] as $r) {
                        $runs[] = ['t' => (string) $r['t'], 'sz' => (float) $r['sz'], 'b' => !empty($r['b']), 'i' => !empty($r['i']),
                            'color' => strtoupper(ltrim((string) $r['color'], '#')), 'font' => ($r['family'] ?? 'sans') === 'serif' ? 'Georgia' : 'Arial'];
                    }
                    $paras[] = $runs;
                }
            }
            $x .= $this->shape($name, (float) $el['x'], (float) $el['y'], (float) $el['w'], (float) $el['h'], $style, $paras, $anchor, $align);
        }

        return $x;
    }

    // ---- DrawingML ---------------------------------------------------------

    private function emu(float $inches): int
    {
        return (int) round($inches * self::EMU);
    }

    /**
     * A shape: rectangle, rounded rectangle or oval, optionally with text.
     *
     * @param array<string,mixed> $s fill, alpha, line, lineW, geom, rot, flipH, flipV
     * @param list<list<array<string,mixed>>> $paras
     */
    private function shape(string $name, float $x, float $y, float $w, float $h, array $s, array $paras = [],
        string $anchor = 't', string $align = 'l'): string
    {
        $id = $this->shapeId++;
        $rot = (float) ($s['rot'] ?? 0) !== 0.0 ? ' rot="' . (int) round((float) $s['rot'] * 60000) . '"' : '';
        $flip = (!empty($s['flipH']) ? ' flipH="1"' : '') . (!empty($s['flipV']) ? ' flipV="1"' : '');
        $fill = isset($s['fill']) ? '<a:solidFill>' . $this->color((string) $s['fill'], (float) ($s['alpha'] ?? 1)) . '</a:solidFill>' : '<a:noFill/>';
        $line = isset($s['line']) ? '<a:ln w="' . (int) round((float) ($s['lineW'] ?? 0.75) * 12700) . '"><a:solidFill>'
            . $this->color((string) $s['line'], 1.0) . '</a:solidFill></a:ln>' : '<a:ln><a:noFill/></a:ln>';

        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $this->x($name) . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm' . $rot . $flip . '><a:off x="' . $this->emu($x) . '" y="' . $this->emu($y) . '"/>'
            . '<a:ext cx="' . $this->emu(max(0.01, $w)) . '" cy="' . $this->emu(max(0.01, $h)) . '"/></a:xfrm>'
            . '<a:prstGeom prst="' . (string) ($s['geom'] ?? 'rect') . '"><a:avLst/></a:prstGeom>' . $fill . $line . '</p:spPr>'
            . ($paras !== [] ? $this->body($paras, $anchor, true, $align) : '') . '</p:sp>';
    }

    /**
     * A text box.
     *
     * @param list<list<array<string,mixed>>> $paras
     */
    private function text(string $name, float $x, float $y, float $w, float $h, array $paras, string $anchor = 't',
        bool $autofit = false, string $align = 'l'): string
    {
        $id = $this->shapeId++;

        return '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="' . $this->x($name) . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . $this->emu($x) . '" y="' . $this->emu($y) . '"/><a:ext cx="' . $this->emu(max(0.05, $w))
            . '" cy="' . $this->emu(max(0.05, $h)) . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            . $this->body($paras, $anchor, $autofit, $align) . '</p:sp>';
    }

    /** @param list<list<array<string,mixed>>> $paras */
    private function body(array $paras, string $anchor, bool $autofit, string $align): string
    {
        $algn = ['l' => 'l', 'ctr' => 'ctr', 'r' => 'r', 'just' => 'just'][$align] ?? 'l';
        $out = '<p:txBody><a:bodyPr wrap="square" lIns="45720" tIns="18288" rIns="45720" bIns="18288" anchor="'
            . (in_array($anchor, ['t', 'ctr', 'b'], true) ? $anchor : 't') . '">' . ($autofit ? '<a:normAutofit/>' : '<a:noAutofit/>')
            . '</a:bodyPr><a:lstStyle/>';
        foreach ($paras as $runs) {
            $out .= '<a:p><a:pPr algn="' . $algn . '"/>';
            $last = 1200;
            foreach ($runs as $r) {
                $sz = (int) round(max(1.0, (float) ($r['sz'] ?? 12)) * 100);
                $last = $sz;
                $out .= '<a:r><a:rPr lang="en-US" sz="' . $sz . '" b="' . (!empty($r['b']) ? 1 : 0) . '" i="' . (!empty($r['i']) ? 1 : 0) . '"'
                    . (isset($r['spc']) ? ' spc="' . (int) $r['spc'] . '"' : '') . ' dirty="0">'
                    . '<a:solidFill>' . $this->color((string) ($r['color'] ?? '16211C'), 1.0) . '</a:solidFill>'
                    . (isset($r['highlight']) ? '<a:highlight>' . $this->color((string) $r['highlight'], 1.0) . '</a:highlight>' : '')
                    . '<a:latin typeface="' . $this->x((string) ($r['font'] ?? 'Arial')) . '"/></a:rPr>'
                    . '<a:t>' . $this->x((string) $r['t']) . '</a:t></a:r>';
            }
            $out .= '<a:endParaRPr lang="en-US" sz="' . $last . '" dirty="0"/></a:p>';
        }
        if ($paras === []) {
            $out .= '<a:p><a:endParaRPr lang="en-US" dirty="0"/></a:p>';
        }

        return $out . '</p:txBody>';
    }

    /**
     * A picture, stored once in the package however often it is used.
     *
     * @param array<string,float>|null $crop
     */
    private function picture(string $name, string $file, float $x, float $y, float $w, float $h, float $alpha,
        string $fit, int $pxW, int $pxH, float $rot = 0.0, ?array $crop = null, string $posX = 'center', string $posY = 'center'): string
    {
        $data = (string) @file_get_contents($file);
        if ($data === '') {
            return '';
        }
        $isPng = str_starts_with($data, "\x89PNG");
        $mediaName = 'image' . (count($this->media) + 1) . ($isPng ? '.png' : '.jpeg');
        foreach ($this->media as $m) {
            if ($m['data'] === $data) {
                $mediaName = $m['name'];
                break;
            }
        }
        if (!in_array($mediaName, array_column($this->media, 'name'), true)) {
            $this->media[] = ['name' => $mediaName, 'data' => $data];
        }
        $rid = 'rId' . (count($this->slideRels) + 2);
        $this->slideRels[] = ['file' => $mediaName, 'rid' => $rid];

        // Cover crops the picture to the box; contain shrinks the box to it.
        $src = '';
        if ($fit === 'cover' && $pxW > 0 && $pxH > 0) {
            $pic = $pxW / $pxH;
            $box = $w / max(0.01, $h);
            // The cropped-off share goes to the side away from the
            // picture's chosen position, as background-position does.
            $share = static fn (string $pos, string $near): float => $pos === $near ? 0.0 : ($pos === 'center' ? 0.5 : 1.0);
            if ($pic > $box) {
                $c = (1 - $box / $pic) * 100000;
                $l = $c * $share($posX, 'left');
                $src = '<a:srcRect l="' . (int) $l . '" r="' . (int) ($c - $l) . '"/>';
            } elseif ($pic < $box) {
                $c = (1 - $pic / $box) * 100000;
                $t = $c * $share($posY, 'top');
                $src = '<a:srcRect t="' . (int) $t . '" b="' . (int) ($c - $t) . '"/>';
            }
        } elseif ($fit === 'contain' && $pxW > 0 && $pxH > 0) {
            $scale = min($w / $pxW, $h / $pxH);
            [$nw, $nh] = [$pxW * $scale, $pxH * $scale];
            $at = static fn (string $pos, string $near): float => $pos === $near ? 0.0 : ($pos === 'center' ? 0.5 : 1.0);
            [$x, $y, $w, $h] = [$x + ($w - $nw) * $at($posX, 'left'), $y + ($h - $nh) * $at($posY, 'top'), $nw, $nh];
        } elseif (is_array($crop)) {
            $src = '<a:srcRect l="' . (int) ($crop['l'] * 100000) . '" t="' . (int) ($crop['t'] * 100000)
                . '" r="' . (int) ($crop['r'] * 100000) . '" b="' . (int) ($crop['b'] * 100000) . '"/>';
        }
        $id = $this->shapeId++;
        $rotAttr = $rot !== 0.0 ? ' rot="' . (int) round($rot * 60000) . '"' : '';

        return '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="' . $this->x($name) . '"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="' . $rid . '">' . ($alpha < 1.0 ? '<a:alphaModFix amt="' . (int) round(max(0, $alpha) * 100000) . '"/>' : '')
            . '</a:blip>' . $src . '<a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            . '<p:spPr><a:xfrm' . $rotAttr . '><a:off x="' . $this->emu($x) . '" y="' . $this->emu($y) . '"/><a:ext cx="' . $this->emu($w)
            . '" cy="' . $this->emu($h) . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    private function color(string $hex, float $alpha): string
    {
        $hex = strtoupper(ltrim($hex, '#'));
        if (preg_match('/^[0-9A-F]{6}$/', $hex) !== 1) {
            $hex = '000000';
        }

        return $alpha < 1.0
            ? '<a:srgbClr val="' . $hex . '"><a:alpha val="' . (int) round(max(0, $alpha) * 100000) . '"/></a:srgbClr>'
            : '<a:srgbClr val="' . $hex . '"/>';
    }

    private function x(string $s): string
    {
        // XML 1.0 forbids most control characters even when escaped.
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Put the slides and pictures into a copy of the skeleton and return it.
     *
     * @param list<array{xml:string,rels:list<array{file:string,rid:string}>}> $slides
     */
    private function package(array $slides, float $pw, float $ph, string $title): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ekk-pptx-');
        copy($this->skeleton, $tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new \RuntimeException('The PowerPoint template could not be opened.');
        }
        $types = (string) $zip->getFromName('[Content_Types].xml');
        $presRels = (string) $zip->getFromName('ppt/_rels/presentation.xml.rels');
        $pres = (string) $zip->getFromName('ppt/presentation.xml');

        $ids = '';
        foreach ($slides as $i => $slide) {
            $n = $i + 1;
            $zip->addFromString('ppt/slides/slide' . $n . '.xml', $slide['xml']);
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout7.xml"/>';
            foreach ($slide['rels'] as $r) {
                $rels .= '<Relationship Id="' . $r['rid'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $r['file'] . '"/>';
            }
            $zip->addFromString('ppt/slides/_rels/slide' . $n . '.xml.rels', $rels . '</Relationships>');
            $types = str_replace('</Types>', '<Override PartName="/ppt/slides/slide' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/></Types>', $types);
            $presRels = str_replace('</Relationships>', '<Relationship Id="rId' . (100 + $n) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $n . '.xml"/></Relationships>', $presRels);
            $ids .= '<p:sldId id="' . (255 + $n) . '" r:id="rId' . (100 + $n) . '"/>';
        }
        foreach ($this->media as $m) {
            $zip->addFromString('ppt/media/' . $m['name'], $m['data']);
        }
        if (!str_contains($types, 'Extension="png"')) {
            $types = str_replace('<Default Extension="rels"', '<Default Extension="png" ContentType="image/png"/><Default Extension="rels"', $types);
        }
        $pres = (string) preg_replace('#<p:sldSz[^>]*/>#', '<p:sldSz cx="' . $this->emu($pw) . '" cy="' . $this->emu($ph) . '"/>', $pres);
        $pres = str_replace('</p:sldMasterIdLst>', '</p:sldMasterIdLst><p:sldIdLst>' . $ids . '</p:sldIdLst>', $pres);
        $zip->addFromString('ppt/presentation.xml', $pres);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presRels);

        // The skeleton's own thumbnail is not a picture of this calendar.
        $zip->deleteName('docProps/thumbnail.jpeg');
        $root = (string) $zip->getFromName('_rels/.rels');
        $root = (string) preg_replace('#<Relationship [^>]*Target="docProps/thumbnail.jpeg"/>#', '', $root);
        $zip->addFromString('_rels/.rels', $root);
        $core = (string) $zip->getFromName('docProps/core.xml');
        $core = (string) preg_replace('#<dc:title>.*?</dc:title>#s', '<dc:title>' . $this->x($title) . '</dc:title>', $core);
        $zip->addFromString('docProps/core.xml', $core);
        $app = (string) $zip->getFromName('docProps/app.xml');
        if ($app !== '') {
            $app = (string) preg_replace('#<Slides>\d+</Slides>#', '<Slides>' . count($slides) . '</Slides>', $app);
            $zip->addFromString('docProps/app.xml', $app);
        }
        $zip->addFromString('[Content_Types].xml', $types);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
