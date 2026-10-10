<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use SimpleXMLElement;
use ZipArchive;

/**
 * Reads an uploaded PowerPoint theme into a plain, safe description.
 *
 * The .pptx is treated as an untrusted ZIP of XML. Nothing in it is executed,
 * rendered by another program, or fetched: this class checks the package,
 * then reads a deliberately small part of slide 1 —
 *
 *   - the slide size, which must be one of the supported papers;
 *   - named shapes marking where Ekklesia writes (the "safe regions");
 *   - artwork: a background colour or picture, pictures, rectangles, rounded
 *     rectangles and ovals (solid colour, outline, rotation, flips), and
 *     decorative text.
 *
 * Everything else (charts, tables, SmartArt, video, custom drawings,
 * gradients, effects) is left out with a warning naming it. Pictures are
 * decoded and re-encoded, so no original bytes survive. The result is data
 * for PptxThemeRenderer; the calendar itself is always drawn by Ekklesia.
 *
 * The contract is the one the starters teach (tools/print-theme-starters.py).
 */
final class PptxThemeReader
{
    public const MAX_BYTES = 25 * 1024 * 1024;
    public const MAX_ENTRIES = 1000;
    public const MAX_EXPANDED = 150 * 1024 * 1024;
    public const MAX_RATIO = 200;
    public const MAX_SLIDES = 10;
    public const MAX_ELEMENTS = 300;
    public const MAX_PICTURES = 30;

    public const REQUIRED_REGIONS = ['CALENDAR_TITLE', 'MONTH_HEADING', 'CALENDAR_GRID'];
    public const OPTIONAL_REGIONS = ['SUBTITLE', 'FOOTER', 'LEGEND', 'PRINT_NOTE'];

    /** Supported pages, portrait inches, as the starters provide them. */
    public const PAGES = [
        'letter' => [8.5, 11.0], 'a4' => [8.27, 11.69], 'tabloid' => [11.0, 17.0], 'legal' => [8.5, 14.0],
    ];

    /** Fonts the page can set anywhere; anything else is substituted. */
    public const FONTS = [
        'arial' => 'sans', 'helvetica' => 'sans', 'calibri' => 'sans', 'segoe ui' => 'sans', 'verdana' => 'sans',
        'tahoma' => 'sans', 'trebuchet ms' => 'sans', 'century gothic' => 'sans', 'aptos' => 'sans',
        'georgia' => 'serif', 'times new roman' => 'serif', 'cambria' => 'serif', 'garamond' => 'serif',
        'palatino linotype' => 'serif', 'book antiqua' => 'serif', 'constantia' => 'serif',
    ];

    private const EMU = 914400;
    private const NS = [
        'p' => 'http://schemas.openxmlformats.org/presentationml/2006/main',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'rel' => 'http://schemas.openxmlformats.org/package/2006/relationships',
        'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006',
    ];
    private const FORBIDDEN_REL_TYPES = ['oleObject', 'package', 'activeXControl', 'control', 'video', 'audio', 'media',
        'vbaProject', 'attachedTemplate', 'externalLinkPath', 'frame'];

    private ZipArchive $zip;
    /** @var list<string> */
    private array $errors = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var array<string,string> scheme name => hex */
    private array $scheme = [];
    /** @var array{major:string,minor:string} */
    private array $themeFonts = ['major' => 'Calibri', 'minor' => 'Calibri'];
    /** @var array<string,array{data:string,ext:string}> */
    private array $assets = [];
    /** @var array<string,true> */
    private array $fontsSeen = [];
    private int $elementCount = 0;

    /**
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,model:?array<string,mixed>,
     *               assets:array<string,array{data:string,ext:string}>,title:string}
     */
    public static function read(string $path, string $originalName, ?int $bytes = null): array
    {
        return (new self())->run($path, $originalName, $bytes);
    }

    /** @return array<string,mixed> */
    private function run(string $path, string $originalName, ?int $bytes): array
    {
        $fail = fn (string $why): array => ['ok' => false, 'errors' => [$why], 'warnings' => [], 'model' => null, 'assets' => [], 'title' => ''];

        // ---- The file -------------------------------------------------------
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'pptm' || $ext === 'potm' || $ext === 'ppsm') {
            return $fail('Macro-enabled presentations (.' . $ext . ') are not accepted. Save it as a PowerPoint Presentation (.pptx).');
        }
        if ($ext !== 'pptx') {
            return $fail('Upload a PowerPoint file saved as .pptx.');
        }
        $size = $bytes ?? (int) @filesize($path);
        if ($size <= 0) {
            return $fail('That file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            return $fail('That presentation is larger than 25 MB. Use smaller pictures and save again.');
        }
        $head = (string) @file_get_contents($path, false, null, 0, 8);
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            return $fail('That presentation is password-protected or in the old .ppt format. Remove the password, or save it as .pptx, and try again.');
        }
        if (!str_starts_with($head, "PK\x03\x04")) {
            return $fail('That is not a PowerPoint (.pptx) file, or it is damaged.');
        }

        // ---- The package ----------------------------------------------------
        $this->zip = new ZipArchive();
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            return $fail('That presentation could not be opened. It may be damaged; save it again from PowerPoint.');
        }
        try {
            $problem = $this->checkPackage();
            if ($problem !== null) {
                return $fail($problem);
            }
            $model = $this->readModel();
        } finally {
            $this->zip->close();
        }

        return [
            'ok' => $this->errors === [] && $model !== null,
            'errors' => $this->errors,
            'warnings' => array_values(array_unique($this->warnings)),
            'model' => $model,
            'assets' => $this->errors === [] ? $this->assets : [],
            'title' => (string) ($model['title'] ?? ''),
        ];
    }

    /** The whole package, before any part of it is interpreted. */
    private function checkPackage(): ?string
    {
        $count = $this->zip->numFiles;
        if ($count > self::MAX_ENTRIES) {
            return 'That presentation has too many parts to be a calendar theme.';
        }
        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $st = $this->zip->statIndex($i);
            if ($st === false) {
                return 'That presentation is damaged.';
            }
            $name = (string) $st['name'];
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                return 'That presentation contains unsafe file paths and was rejected.';
            }
            $total += (int) $st['size'];
            if ($total > self::MAX_EXPANDED) {
                return 'That presentation expands to more than 150 MB and was rejected.';
            }
            if ((int) $st['size'] > 1024 * 1024 && (int) $st['comp_size'] > 0
                && (int) $st['size'] / (int) $st['comp_size'] > self::MAX_RATIO) {
                return 'That presentation contains a part that expands suspiciously and was rejected.';
            }
            $lower = strtolower($name);
            // PowerPoint stores the chosen printer's settings as a .bin part in
            // ordinary files. It is never read here; any other .bin is refused.
            $printerSettings = preg_match('#^ppt/printersettings/printersettings\d+\.bin$#', $lower) === 1;
            if (str_contains($lower, 'vbaproject') || (str_ends_with($lower, '.bin') && !$printerSettings)
                || str_starts_with($lower, 'ppt/embeddings/')
                || str_starts_with($lower, 'ppt/activex/')) {
                return 'That presentation contains macros or embedded objects, which are not accepted. '
                    . 'Save a copy without them (File ▸ Save As ▸ PowerPoint Presentation) and try again.';
            }
        }
        $types = $this->part('[Content_Types].xml');
        if ($types === null || !str_contains($types, 'presentationml.presentation.main+xml')) {
            return str_contains((string) $types, 'macroEnabled')
                ? 'Macro-enabled presentations are not accepted. Save it as a PowerPoint Presentation (.pptx).'
                : 'That file is not a PowerPoint presentation.';
        }
        if (str_contains($types, 'macroEnabled') || str_contains($types, 'vbaProject')) {
            return 'Macro-enabled presentations are not accepted. Save it as a PowerPoint Presentation (.pptx).';
        }
        // Relationships: no embedded programs, no linked content that would be
        // fetched from somewhere else. Hyperlinks are harmless and allowed.
        for ($i = 0; $i < $count; $i++) {
            $name = (string) $this->zip->getNameIndex($i);
            if (!str_ends_with($name, '.rels')) {
                continue;
            }
            $xml = $this->xml($name);
            if ($xml === null) {
                return 'That presentation is damaged (' . $name . ').';
            }
            foreach ($xml->children()->Relationship ?? [] as $rel) {
                $type = (string) $rel['Type'];
                $short = substr($type, (int) strrpos($type, '/') + 1);
                if (in_array($short, self::FORBIDDEN_REL_TYPES, true)) {
                    return 'That presentation contains ' . ($short === 'video' || $short === 'audio' || $short === 'media'
                        ? 'video or sound' : 'an embedded object') . ', which is not accepted in a calendar theme.';
                }
                if (strtolower((string) $rel['TargetMode']) === 'external' && $short !== 'hyperlink') {
                    return 'That presentation links to content elsewhere (such as a linked picture), which is not accepted. '
                        . 'Insert pictures into the slide instead of linking them.';
                }
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function readModel(): ?array
    {
        $pres = $this->xml('ppt/presentation.xml');
        if ($pres === null) {
            $this->errors[] = 'That presentation has no slides.';
            return null;
        }
        $p = $pres->children();
        $sz = $p->sldSz;
        $w = (int) ($sz['cx'] ?? 0) / self::EMU;
        $h = (int) ($sz['cy'] ?? 0) / self::EMU;
        $page = $this->matchPage($w, $h);
        if ($page === null) {
            $this->errors[] = sprintf('The slide is %.2f × %.2f inches. Use one of the Ekklesia starters (Letter, A4, Legal or Tabloid) without changing the slide size.', $w, $h);
            return null;
        }
        $ids = [];
        foreach ($p->sldIdLst->sldId ?? [] as $sid) {
            $ids[] = (string) $sid['r_id'];
        }
        if ($ids === []) {
            $this->errors[] = 'That presentation has no slides.';
            return null;
        }
        if (count($ids) > self::MAX_SLIDES) {
            $this->errors[] = 'A theme has one design slide; this presentation has ' . count($ids) . ' slides. Keep slide 1 and try again.';
            return null;
        }
        $rels = $this->rels('ppt/presentation.xml');
        $slidePath = $rels[$ids[0]]['target'] ?? null;
        if ($slidePath === null) {
            $this->errors[] = 'Slide 1 could not be found in that presentation.';
            return null;
        }
        $slide = $this->xml($slidePath);
        if ($slide === null) {
            $this->errors[] = 'Slide 1 is damaged.';
            return null;
        }
        $slideRels = $this->rels($slidePath);
        $layoutPath = $this->relOfType($slideRels, 'slideLayout');
        $masterPath = $layoutPath !== null ? $this->relOfType($this->rels($layoutPath), 'slideMaster') : null;
        $themePath = $masterPath !== null ? $this->relOfType($this->rels($masterPath), 'theme') : null;
        if ($themePath !== null) {
            $this->readTheme($themePath);
        }

        // Background: the slide's own, else its layout's, else its master's.
        $background = $this->background($slide, $slidePath, $slideRels);
        foreach ([$layoutPath, $masterPath] as $inherit) {
            if ($background === null && $inherit !== null && ($x = $this->xml($inherit)) !== null) {
                $background = $this->background($x, $inherit, $this->rels($inherit));
            }
        }

        $regions = [];
        $elements = [];
        $tree = $slide->children()->cSld->spTree;
        $this->walk($tree, [0.0, 0.0, 1.0, 1.0], $slidePath, $slideRels, $regions, $elements);

        foreach (self::REQUIRED_REGIONS as $name) {
            if (!isset($regions[$name])) {
                $this->errors[] = 'Slide 1 has no shape named ' . $name . '. Keep the dashed boxes from the starter '
                    . '(Home ▸ Arrange ▸ Selection Pane shows their names).';
            }
        }
        if ($this->errors === []) {
            $this->checkRegions($regions, $page['w'], $page['h'], $elements);
        }
        $unknown = array_diff(array_keys($this->fontsSeen), array_keys(self::FONTS));
        foreach ($unknown as $font) {
            $this->warnings[] = 'The font “' . $font . '” is not available everywhere; that text prints in a '
                . ($this->fontFamily($font) === 'serif' ? 'serif' : 'plain') . ' font instead.';
        }

        $core = $this->xml('docProps/core.xml');
        $title = $core !== null ? trim((string) ($core->children()->title ?? '')) : '';

        return [
            'contract' => 1,
            'page' => $page,
            'regions' => $regions,
            'background' => $background,
            'elements' => $elements,
            'palette' => $this->palette(),
            'title' => $title === 'Ekklesia calendar theme' ? '' : $title,
        ];
    }

    /** @return array{paper:string,orientation:string,w:float,h:float}|null */
    private function matchPage(float $w, float $h): ?array
    {
        foreach (self::PAGES as $paper => [$pw, $ph]) {
            foreach (['portrait' => [$pw, $ph], 'landscape' => [$ph, $pw]] as $orientation => [$ew, $eh]) {
                if (abs($w - $ew) / $ew < 0.03 && abs($h - $eh) / $eh < 0.03) {
                    return ['paper' => $paper, 'orientation' => $orientation, 'w' => round($ew, 3), 'h' => round($eh, 3)];
                }
            }
        }

        return null;
    }

    /**
     * @param array{0:float,1:float,2:float,3:float} $t group transform: offset x, y and scale x, y (inches)
     * @param array<string,array<string,float>> $regions
     * @param list<array<string,mixed>> $elements
     */
    private function walk(SimpleXMLElement $tree, array $t, string $partPath, array $rels, array &$regions, array &$elements): void
    {
        foreach ($tree->children() as $tag => $node) {
            if ($this->elementCount >= self::MAX_ELEMENTS) {
                $this->warnings[] = 'The slide has more than ' . self::MAX_ELEMENTS . ' items; the rest were left out.';
                return;
            }
            switch ($tag) {
                case 'sp':
                    $this->shape($node, $t, $partPath, $rels, $regions, $elements);
                    break;
                case 'pic':
                    $this->picture($node, $t, $partPath, $rels, $elements);
                    break;
                case 'grpSp':
                    $xfrm = $node->children()->grpSpPr->children()->xfrm;
                    $a = self::NS['a'];
                    $off = $xfrm->children($a)->off;
                    $ext = $xfrm->children($a)->ext;
                    $chOff = $xfrm->children($a)->chOff;
                    $chExt = $xfrm->children($a)->chExt;
                    $sx = (float) ($chExt['cx'] ?? 0) > 0 ? (float) $ext['cx'] / (float) $chExt['cx'] : 1.0;
                    $sy = (float) ($chExt['cy'] ?? 0) > 0 ? (float) $ext['cy'] / (float) $chExt['cy'] : 1.0;
                    // Child x in inches = parent offset + (x - chOff) * scale.
                    $inner = [
                        $t[0] + ((float) $off['x'] - (float) $chOff['x'] * $sx) / self::EMU * $t[2],
                        $t[1] + ((float) $off['y'] - (float) $chOff['y'] * $sy) / self::EMU * $t[3],
                        $t[2] * $sx,
                        $t[3] * $sy,
                    ];
                    if ((int) ($xfrm['rot'] ?? 0) !== 0) {
                        $this->warnings[] = 'A rotated group was drawn without its rotation.';
                    }
                    $this->walk($node, $inner, $partPath, $rels, $regions, $elements);
                    break;
                case 'graphicFrame':
                    $this->warnings[] = 'Charts, tables and SmartArt are not used in a calendar theme and were left out.';
                    break;
                case 'cxnSp':
                    $this->warnings[] = 'Connector lines are not used in a calendar theme and were left out.';
                    break;
                case 'AlternateContent':
                    $this->warnings[] = 'Some newer PowerPoint content (such as 3D models or icons with effects) was left out.';
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * @param array{0:float,1:float,2:float,3:float} $t
     * @return array{x:float,y:float,w:float,h:float,rot:float,flipH:bool,flipV:bool}|null
     */
    private function box(?SimpleXMLElement $spPr, array $t): ?array
    {
        if ($spPr === null) {
            return null;
        }
        $xfrm = $spPr->children()->xfrm;
        if ($xfrm === null || $xfrm->count() === 0) {
            return null;
        }
        $off = $xfrm->children()->off;
        $ext = $xfrm->children()->ext;

        return [
            'x' => round($t[0] + (float) $off['x'] / self::EMU * $t[2], 4),
            'y' => round($t[1] + (float) $off['y'] / self::EMU * $t[3], 4),
            'w' => round((float) $ext['cx'] / self::EMU * $t[2], 4),
            'h' => round((float) $ext['cy'] / self::EMU * $t[3], 4),
            'rot' => round((int) ($xfrm['rot'] ?? 0) / 60000, 2),
            'flipH' => (string) ($xfrm['flipH'] ?? '') === '1',
            'flipV' => (string) ($xfrm['flipV'] ?? '') === '1',
        ];
    }

    /** @param array{0:float,1:float,2:float,3:float} $t */
    private function shape(SimpleXMLElement $sp, array $t, string $partPath, array $rels, array &$regions, array &$elements): void
    {
        $p = $sp->children();
        $name = trim((string) ($p->nvSpPr->cNvPr['name'] ?? ''));
        $key = strtoupper((string) preg_replace('/[\s\-]+/', '_', $name));
        $box = $this->box($p->spPr, $t);
        if (in_array($key, array_merge(self::REQUIRED_REGIONS, self::OPTIONAL_REGIONS), true)) {
            if ($box !== null) {
                $regions[$key] = ['x' => $box['x'], 'y' => $box['y'], 'w' => $box['w'], 'h' => $box['h']];
            }
            return;
        }
        if (str_starts_with($key, 'GUIDE') || $box === null || $box['w'] <= 0 || $box['h'] <= 0) {
            return;
        }
        $a = $p->spPr->children();
        $geom = 'rect';
        if ($a->custGeom !== null && $a->custGeom->count() > 0) {
            $this->warnings[] = 'Freeform drawings were left out (use rectangles, rounded rectangles, ovals or pictures).';
            return;
        }
        if ($a->prstGeom !== null && $a->prstGeom->count() > 0) {
            $geom = (string) $a->prstGeom['prst'];
        }
        $geomMap = ['rect' => 'rect', 'roundRect' => 'round', 'ellipse' => 'ellipse', 'snip1Rect' => 'rect',
            'flowChartProcess' => 'rect', 'flowChartAlternateProcess' => 'round', 'flowChartConnector' => 'ellipse'];
        $text = $this->text($p->txBody);
        if (!isset($geomMap[$geom])) {
            $this->warnings[] = 'Shapes of type “' . $geom . '” were left out (use rectangles, rounded rectangles or ovals).';
            if ($text === null) {
                return;
            }
            $geom = 'rect';
        }
        $style = $p->style;
        $fill = $this->fill($a, $style, 'fillRef', $partPath, $rels);
        $line = $this->line($a, $style);
        if ($fill === null && $line === null && $text === null) {
            return;
        }
        $this->elementCount++;
        $el = $box + ['kind' => 'shape', 'geom' => $geomMap[$geom], 'name' => mb_substr($name, 0, 60)];
        if ($fill !== null) {
            $el['fill'] = $fill;
        }
        if ($line !== null) {
            $el['line'] = $line;
        }
        if ($text !== null) {
            $el['text'] = $text;
        }
        $elements[] = $el;
    }

    /** @param array{0:float,1:float,2:float,3:float} $t */
    private function picture(SimpleXMLElement $pic, array $t, string $partPath, array $rels, array &$elements): void
    {
        $p = $pic->children();
        $name = trim((string) ($p->nvPicPr->cNvPr['name'] ?? ''));
        if (str_starts_with(strtoupper($name), 'GUIDE')) {
            return;
        }
        $box = $this->box($p->spPr, $t);
        $blipFill = $p->blipFill;
        if ($box === null || $blipFill === null) {
            return;
        }
        $asset = $this->blip($blipFill->children()->blip, $partPath, $rels);
        if ($asset === null) {
            return;
        }
        $el = $box + ['kind' => 'picture', 'asset' => $asset['key'], 'name' => mb_substr($name, 0, 60), 'opacity' => $asset['opacity']];
        $crop = $blipFill->children()->srcRect;
        if (isset($crop[0])) {
            $c = [];
            foreach (['l', 't', 'r', 'b'] as $side) {
                $c[$side] = max(0.0, min(0.95, (int) ($crop[$side] ?? 0) / 100000));
            }
            if (array_sum($c) > 0) {
                $el['crop'] = $c;
            }
        }
        $prst = (string) ($p->spPr->children()->prstGeom['prst'] ?? 'rect');
        $el['geom'] = $prst === 'ellipse' ? 'ellipse' : ($prst === 'roundRect' ? 'round' : 'rect');
        $this->elementCount++;
        $elements[] = $el;
    }

    /**
     * One picture from the package, decoded and re-encoded.
     *
     * @return array{key:string,opacity:float}|null
     */
    private function blip(?SimpleXMLElement $blip, string $partPath, array $rels): ?array
    {
        if ($blip === null) {
            return null;
        }
        $rid = (string) ($blip['r_embed'] ?? '');
        $target = $rels[$rid]['target'] ?? null;
        if ($target === null) {
            if ((string) ($blip['r_link'] ?? '') !== '') {
                $this->warnings[] = 'A linked (not inserted) picture was left out.';
            }
            return null;
        }
        if (count($this->assets) >= self::MAX_PICTURES) {
            $this->warnings[] = 'Only the first ' . self::MAX_PICTURES . ' pictures were used.';
            return null;
        }
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'bmp'], true)) {
            $this->warnings[] = 'A picture in ' . strtoupper($ext) . ' format was left out (use JPEG or PNG).';
            return null;
        }
        $bytes = $this->zip->getFromName($target);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $key = substr(hash('sha256', $bytes), 0, 16);
        if (!isset($this->assets[$key])) {
            $clean = self::reencode($bytes);
            if ($clean === null) {
                $this->warnings[] = 'A picture could not be read and was left out.';
                return null;
            }
            $this->assets[$key] = $clean;
        }
        $opacity = 1.0;
        $alpha = $blip->children()->alphaModFix;
        if ($alpha !== null && isset($alpha['amt'])) {
            $opacity = max(0.0, min(1.0, (int) $alpha['amt'] / 100000));
        }
        if ($blip->children()->count() > ($alpha !== null ? 1 : 0)) {
            $this->warnings[] = 'Picture effects (recolouring, glow, shadows) are not reproduced.';
        }

        return ['key' => $key, 'opacity' => round($opacity, 2)];
    }

    /**
     * Decode and re-encode a picture: PNG stays PNG (keeping transparency),
     * everything else becomes JPEG. Nothing of the original survives.
     *
     * @return array{data:string,ext:string}|null
     */
    public static function reencode(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || (int) $info[0] < 1 || (int) $info[0] > 8000 || (int) $info[1] > 8000) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }
        ob_start();
        if (($info['mime'] ?? '') === 'image/png' || ($info['mime'] ?? '') === 'image/gif') {
            imagesavealpha($img, true);
            imagepng($img, null, 6);
            $ext = 'png';
        } else {
            imagejpeg($img, null, 88);
            $ext = 'jpg';
        }
        $data = (string) ob_get_clean();
        imagedestroy($img);

        return ['data' => $data, 'ext' => $ext];
    }

    /**
     * Solid fill of a shape or background, including a picture fill.
     *
     * @return array<string,mixed>|null
     */
    private function fill(SimpleXMLElement $a, ?SimpleXMLElement $style, string $ref, string $partPath, array $rels): ?array
    {
        if (isset($a->noFill[0])) {
            return null;
        }
        if (isset($a->solidFill[0])) {
            $c = $this->color($a->solidFill);
            return $c !== null ? ['color' => $c['hex'], 'alpha' => $c['alpha']] : null;
        }
        if (isset($a->gradFill[0])) {
            $stop = $a->gradFill->children()->gsLst->gs[0] ?? null;
            $c = $stop !== null ? $this->color($stop) : null;
            $this->warnings[] = 'Gradients are printed as a single colour.';
            return $c !== null ? ['color' => $c['hex'], 'alpha' => $c['alpha']] : null;
        }
        if (isset($a->blipFill[0])) {
            $asset = $this->blip($a->blipFill->children()->blip, $partPath, $rels);
            return $asset !== null ? ['asset' => $asset['key'], 'alpha' => $asset['opacity']] : null;
        }
        if (isset($a->pattFill[0])) {
            $this->warnings[] = 'Pattern fills are printed as a single colour.';
            $c = $this->color($a->pattFill->children()->fgClr);
            return $c !== null ? ['color' => $c['hex'], 'alpha' => $c['alpha']] : null;
        }
        // No explicit fill: the shape's style says which theme colour.
        if ($style !== null && isset($style->children()->{$ref}[0])) {
            $r = $style->children()->{$ref};
            if ((int) ($r['idx'] ?? 0) > 0) {
                $c = $this->color($r);
                return $c !== null ? ['color' => $c['hex'], 'alpha' => $c['alpha']] : null;
            }
        }

        return null;
    }

    /** @return array{color:string,width:float,alpha:float}|null */
    private function line(SimpleXMLElement $a, ?SimpleXMLElement $style): ?array
    {
        $ln = $a->ln;
        $width = isset($ln[0]) && isset($ln['w']) ? (int) $ln['w'] / 12700 : 0.75;
        if (isset($ln[0])) {
            $l = $ln->children();
            if (isset($l->noFill[0])) {
                return null;
            }
            if (isset($l->solidFill[0])) {
                $c = $this->color($l->solidFill);
                return $c !== null ? ['color' => $c['hex'], 'width' => round($width, 2), 'alpha' => $c['alpha']] : null;
            }
        }
        if ($style !== null && isset($style->children()->lnRef[0])) {
            $r = $style->children()->lnRef;
            if ((int) ($r['idx'] ?? 0) > 0) {
                $c = $this->color($r);
                return $c !== null ? ['color' => $c['hex'], 'width' => round($width, 2), 'alpha' => $c['alpha']] : null;
            }
        }

        return null;
    }

    /**
     * Decorative text: paragraphs of runs, with size, weight, style, colour,
     * font family (approved stacks only) and alignment.
     *
     * @return array<string,mixed>|null
     */
    private function text(?SimpleXMLElement $txBody): ?array
    {
        if ($txBody === null || !isset($txBody[0])) {
            return null;
        }
        $a = $txBody->children();
        $paras = [];
        $chars = 0;
        foreach ($a->p ?? [] as $para) {
            $pa = $para->children();
            $align = (string) ($pa->pPr['algn'] ?? 'l');
            $runs = [];
            foreach ($pa->r ?? [] as $run) {
                $ra = $run->children();
                $str = (string) $ra->t;
                if ($str === '') {
                    continue;
                }
                $chars += mb_strlen($str);
                $rPr = $ra->rPr;
                $font = isset($rPr[0]) ? (string) ($rPr->children()->latin['typeface'] ?? '') : '';
                $font = $this->resolveFont($font);
                $color = isset($rPr[0]) && isset($rPr->children()->solidFill[0])
                    ? $this->color($rPr->children()->solidFill) : null;
                $runs[] = [
                    't' => mb_substr($str, 0, 500),
                    'sz' => isset($rPr[0]) && isset($rPr['sz']) ? round((int) $rPr['sz'] / 100, 1) : 18.0,
                    'b' => isset($rPr[0]) && (string) ($rPr['b'] ?? '') === '1',
                    'i' => isset($rPr[0]) && (string) ($rPr['i'] ?? '') === '1',
                    'color' => $color['hex'] ?? '#1b1b1b',
                    'family' => $this->fontFamily($font),
                ];
            }
            $paras[] = ['align' => in_array($align, ['l', 'ctr', 'r', 'just'], true) ? $align : 'l', 'runs' => $runs];
            if ($chars > 2000) {
                $this->warnings[] = 'Long text on the slide was shortened.';
                break;
            }
        }
        if ($chars === 0) {
            return null;
        }
        $body = $a->bodyPr;
        $anchor = (string) ($body['anchor'] ?? 't');

        return ['paras' => $paras, 'anchor' => in_array($anchor, ['t', 'ctr', 'b'], true) ? $anchor : 't'];
    }

    private function resolveFont(string $font): string
    {
        $font = trim($font);
        if ($font === '' || $font === '+mn-lt') {
            $font = $this->themeFonts['minor'];
        } elseif ($font === '+mj-lt') {
            $font = $this->themeFonts['major'];
        }
        $this->fontsSeen[strtolower($font)] = true;

        return $font;
    }

    private function fontFamily(string $font): string
    {
        $key = strtolower(trim($font));
        if (isset(self::FONTS[$key])) {
            return self::FONTS[$key];
        }

        return preg_match('/serif|roman|garamond|book|times|georgia|minion|baskerville/i', $font) === 1
            && !str_contains($key, 'sans') ? 'serif' : 'sans';
    }

    /**
     * A colour element's colour: srgbClr or schemeClr, with alpha and the
     * lumMod/lumOff tints PowerPoint's colour picker produces.
     *
     * @return array{hex:string,alpha:float}|null
     */
    private function color(?SimpleXMLElement $holder): ?array
    {
        if ($holder === null) {
            return null;
        }
        $a = $holder->children();
        $node = null;
        $hex = null;
        if (isset($a->srgbClr[0])) {
            $node = $a->srgbClr;
            $hex = '#' . strtolower((string) $node['val']);
        } elseif (isset($a->schemeClr[0])) {
            $node = $a->schemeClr;
            $name = (string) $node['val'];
            $name = ['bg1' => 'lt1', 'tx1' => 'dk1', 'bg2' => 'lt2', 'tx2' => 'dk2', 'phClr' => 'accent1'][$name] ?? $name;
            $hex = $this->scheme[$name] ?? null;
        } elseif (isset($a->sysClr[0])) {
            $node = $a->sysClr;
            $hex = '#' . strtolower((string) ($node['lastClr'] ?? '000000'));
        } elseif (isset($a->prstClr[0])) {
            $node = $a->prstClr;
            $hex = ['black' => '#000000', 'white' => '#ffffff', 'red' => '#ff0000', 'blue' => '#0000ff', 'green' => '#008000'][(string) $node['val']] ?? '#000000';
        }
        if ($hex === null || preg_match('/^#[0-9a-f]{6}$/', $hex) !== 1) {
            return null;
        }
        $alpha = 1.0;
        $mod = null;
        $off = null;
        foreach ($node->children() as $tag => $adj) {
            $v = (int) ($adj['val'] ?? 0) / 100000;
            if ($tag === 'alpha') {
                $alpha = $v;
            } elseif ($tag === 'lumMod') {
                $mod = $v;
            } elseif ($tag === 'lumOff') {
                $off = $v;
            } elseif ($tag === 'tint') {
                $hex = self::mix($hex, '#ffffff', 1 - $v);
            } elseif ($tag === 'shade') {
                $hex = self::mix($hex, '#000000', 1 - $v);
            }
        }
        if ($mod !== null || $off !== null) {
            $hex = self::lum($hex, $mod ?? 1.0, $off ?? 0.0);
        }

        return ['hex' => $hex, 'alpha' => round(max(0.0, min(1.0, $alpha)), 2)];
    }

    /** @return array<string,mixed>|null */
    private function background(SimpleXMLElement $part, string $partPath, array $rels): ?array
    {
        $bg = $part->children()->cSld->bg;
        if (!isset($bg[0])) {
            return null;
        }
        $pr = $bg->children()->bgPr;
        if (isset($pr[0])) {
            // bgPr holds its fill as DrawingML children, as a shape's spPr does.
            return $this->fill($pr->children(), null, 'fillRef', $partPath, $rels);
        }
        $ref = $bg->children()->bgRef;
        if (isset($ref[0])) {
            $c = $this->color($ref);
            return $c !== null ? ['color' => $c['hex'], 'alpha' => 1.0] : null;
        }

        return null;
    }

    private function readTheme(string $path): void
    {
        $theme = $this->xml($path);
        if ($theme === null) {
            return;
        }
        $a = $theme->children();
        $elements = $a->themeElements->children();
        foreach ($elements->clrScheme->children() as $name => $node) {
            $n = $node->children();
            if (isset($n->srgbClr[0])) {
                $this->scheme[$name] = '#' . strtolower((string) $n->srgbClr['val']);
            } elseif (isset($n->sysClr[0])) {
                $this->scheme[$name] = '#' . strtolower((string) ($n->sysClr['lastClr'] ?? '000000'));
            }
        }
        $fonts = $elements->fontScheme->children();
        $major = (string) ($fonts->majorFont->children()->latin['typeface'] ?? '');
        $minor = (string) ($fonts->minorFont->children()->latin['typeface'] ?? '');
        $this->themeFonts = ['major' => $major !== '' ? $major : 'Calibri', 'minor' => $minor !== '' ? $minor : 'Calibri'];
    }

    /**
     * Colours Ekklesia writes in, taken from the design's own theme so the
     * calendar belongs to it, but never at a contrast that hurts reading.
     *
     * @return array<string,string>
     */
    private function palette(): array
    {
        $dk1 = $this->scheme['dk1'] ?? '#1b1b1b';
        $dk2 = $this->scheme['dk2'] ?? '#1f3a2e';
        $accent = $this->scheme['accent1'] ?? '#0c5a45';
        $readable = static fn (string $hex): bool => self::contrast($hex, '#ffffff') >= 4.5;

        return [
            'paper' => '#ffffff',
            'ink' => $readable($dk1) ? $dk1 : '#16211c',
            'heading' => $readable($dk2) ? $dk2 : ($readable($dk1) ? $dk1 : '#16211c'),
            'accent' => $readable($accent) ? $accent : self::lum($accent, 0.6, 0.0),
            'grid' => self::mix($readable($dk2) ? $dk2 : '#16211c', '#ffffff', 0.78),
            'cell' => '#ffffff',
            'band' => self::mix($accent, '#ffffff', 0.9),
        ];
    }

    /**
     * @param array<string,array{x:float,y:float,w:float,h:float}> $regions
     * @param list<array<string,mixed>> $elements
     */
    private function checkRegions(array $regions, float $pw, float $ph, array $elements): void
    {
        foreach ($regions as $name => $r) {
            if ($r['x'] < -0.05 || $r['y'] < -0.05 || $r['x'] + $r['w'] > $pw + 0.05 || $r['y'] + $r['h'] > $ph + 0.05) {
                $this->errors[] = $name . ' reaches outside the slide. Move it fully onto the page.';
            }
        }
        $grid = $regions['CALENDAR_GRID'];
        if ($grid['w'] < max(4.0, $pw * 0.55) || $grid['h'] < max(3.0, $ph * 0.4)) {
            $this->errors[] = sprintf(
                'CALENDAR_GRID is %.1f × %.1f inches, too small for seven days of names. Make it at least %.1f × %.1f inches.',
                $grid['w'], $grid['h'], max(4.0, $pw * 0.55), max(3.0, $ph * 0.4),
            );
        }
        if ($regions['CALENDAR_TITLE']['h'] < 0.35 || $regions['CALENDAR_TITLE']['w'] < 2.0) {
            $this->errors[] = 'CALENDAR_TITLE is too small for a title. Make it at least 2 inches wide and 0.35 inches tall.';
        }
        foreach (['CALENDAR_TITLE', 'MONTH_HEADING', 'SUBTITLE', 'LEGEND', 'FOOTER'] as $other) {
            if (isset($regions[$other]) && self::overlap($regions[$other], $grid) > 0.05) {
                $this->errors[] = $other . ' overlaps CALENDAR_GRID. Move it clear of the calendar area.';
            }
        }
        // Artwork printed over the calendar area makes names hard to read.
        $covered = 0.0;
        foreach ($elements as $el) {
            $opaque = isset($el['asset']) ? (float) ($el['opacity'] ?? 1) : (float) ($el['fill']['alpha'] ?? 0);
            if ($opaque >= 0.35 && (isset($el['asset']) || isset($el['fill']))) {
                $covered += self::overlap($el, $grid);
            }
        }
        $share = $covered / max(0.01, $grid['w'] * $grid['h']);
        if ($share > 0.25) {
            $this->warnings[] = sprintf('Artwork covers about %d%% of the calendar area. Names printed over it may be hard to read; '
                . 'Ekklesia adds a light wash under the calendar, but a plainer area prints best.', min(100, (int) round($share * 100)));
        }
    }

    /** @param array<string,float> $a @param array<string,float> $b */
    private static function overlap(array $a, array $b): float
    {
        $w = min($a['x'] + $a['w'], $b['x'] + $b['w']) - max($a['x'], $b['x']);
        $h = min($a['y'] + $a['h'], $b['y'] + $b['h']) - max($a['y'], $b['y']);

        return $w > 0 && $h > 0 ? $w * $h : 0.0;
    }

    public static function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $c = array_map(static fn (string $h): float => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
            $c = array_map(static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$x, $y] = [$lum($a), $lum($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private static function mix(string $a, string $b, float $t): string
    {
        $pa = array_map('hexdec', str_split(ltrim($a, '#'), 2));
        $pb = array_map('hexdec', str_split(ltrim($b, '#'), 2));

        return '#' . implode('', array_map(static fn ($x, $y) => sprintf('%02x', (int) round($x + ($y - $x) * $t)), $pa, $pb));
    }

    /** PowerPoint's luminance modulation, in HSL. */
    private static function lum(string $hex, float $mod, float $off): string
    {
        [$r, $g, $b] = array_map(static fn ($h) => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $h = 0.0;
        $s = 0.0;
        if ($d > 0) {
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            $h = match (true) {
                $max === $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
                $max === $g => ($b - $r) / $d + 2,
                default => ($r - $g) / $d + 4,
            } / 6;
        }
        $l = max(0.0, min(1.0, $l * $mod + $off));
        $hue = static function (float $p, float $q, float $t): float {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };
        if ($s == 0.0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = $hue($p, $q, $h + 1 / 3);
            $g = $hue($p, $q, $h);
            $b = $hue($p, $q, $h - 1 / 3);
        }

        return sprintf('#%02x%02x%02x', (int) round($r * 255), (int) round($g * 255), (int) round($b * 255));
    }

    private function part(string $name): ?string
    {
        $data = $this->zip->getFromName($name);
        if ($data === false) {
            return null;
        }

        return $data;
    }

    /** Parsed XML of a part, refusing any DTD (entity expansion, external entities). */
    private function xml(string $name): ?SimpleXMLElement
    {
        $data = $this->part($name);
        if ($data === null || $data === '' || stripos($data, '<!DOCTYPE') !== false || stripos($data, '<!ENTITY') !== false) {
            return null;
        }
        // Namespaces are removed before parsing: only the parts' own local names
        // are read, and a prefixed attribute becomes prefix_name (r:embed is
        // r_embed). SimpleXML's namespace handling otherwise makes plain
        // attribute reads come back empty after a namespaced step.
        $data = (string) preg_replace('/\sxmlns(?::[A-Za-z0-9]+)?="[^"]*"/', '', $data);
        $data = (string) preg_replace('/(<\/?)[A-Za-z0-9]+:/', '$1', $data);
        $data = (string) preg_replace('/(\s)([A-Za-z0-9]+):([A-Za-z0-9]+=)/', '$1$2_$3', $data);
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $xml === false ? null : $xml;
    }

    /** @return array<string,array{type:string,target:string}> relationship id => resolved target */
    private function rels(string $partPath): array
    {
        $dir = dirname($partPath);
        $relsPath = ($dir === '.' ? '' : $dir . '/') . '_rels/' . basename($partPath) . '.rels';
        $xml = $this->xml($relsPath);
        if ($xml === null) {
            return [];
        }
        $out = [];
        foreach ($xml->children()->Relationship ?? [] as $rel) {
            if (strtolower((string) $rel['TargetMode']) === 'external') {
                continue;
            }
            $out[(string) $rel['Id']] = [
                'type' => substr((string) $rel['Type'], (int) strrpos((string) $rel['Type'], '/') + 1),
                'target' => self::resolve($dir, (string) $rel['Target']),
            ];
        }

        return $out;
    }

    /** @param array<string,array{type:string,target:string}> $rels */
    private function relOfType(array $rels, string $type): ?string
    {
        foreach ($rels as $rel) {
            if ($rel['type'] === $type) {
                return $rel['target'];
            }
        }

        return null;
    }

    private static function resolve(string $dir, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        $parts = [];
        foreach (explode('/', ($dir === '.' ? '' : $dir . '/') . $target) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }

        return implode('/', $parts);
    }
}
