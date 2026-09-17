<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use DateTimeImmutable;

/**
 * Turns a set of choices into a printable page.
 *
 * The choices are ordinary — a range, a campus, which sources, which layout —
 * and the output is a whole HTML document built for paper. It shares the
 * calendar's data and none of its layout, which is the arrangement that lets a
 * printed calendar be designed rather than exported.
 *
 * Given items rather than fetching them, so the same composer serves a request,
 * a test and, later, anything generating a PDF on a schedule.
 */
final class PrintComposer
{
    public function __construct(
        private readonly string $viewPath,
        private readonly CalendarViewModel $viewModel = new CalendarViewModel(),
    ) {
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array{
     *   template?:string, paper?:string, orientation?:string,
     *   sources?:?list<string>, colors?:array<string,string>,
     *   church?:string, subtitle?:string, footer?:string, website?:string,
     *   accent?:string, today?:?string, weekStartsOn?:int
     * } $options
     */
    public function render(
        array $items,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $options = [],
    ): string {
        $templateId = (string) ($options['template'] ?? 'monthly');
        if (!PrintTemplates::exists($templateId)) {
            $templateId = 'monthly';
        }
        $template = PrintTemplates::get($templateId);

        // The same widening the caller applied before fetching, so the model
        // covers exactly the window the items came from.
        [$start, $end] = PrintTemplates::rangeFor($templateId, $start, $end);

        $model = $this->viewModel->build($items, $start, $end, [
            'sources' => $options['sources'] ?? null,
            'today' => $options['today'] ?? null,
            'weekStartsOn' => (int) ($options['weekStartsOn'] ?? 0),
        ]);

        $colors = $options['colors'] ?? [];
        $accent = (string) ($options['accent'] ?? '#0c5a45');

        // Presentation choices, already validated by the caller. Passed down as
        // plain values so a template can size itself around them — a type scale
        // that only the stylesheet knows about would leave the monthly grid
        // budgeting for the wrong row height.
        $typeScale = (float) ($options['typeScale'] ?? 1.0);
        $typeScale = max(0.85, min(1.3, $typeScale ?: 1.0));
        $nameStyle = ($options['nameStyle'] ?? 'full') === 'short' ? 'short' : 'full';
        // How tightly the grid is set. A separate axis from type size: a reader
        // may want the same large type with less air around it.
        // Read once, then validate. Reading it again inside the ternary meant
        // an absent key raised an undefined-index warning on every render that
        // simply wanted the default.
        $density = (string) ($options['density'] ?? 'standard');
        $density = in_array($density, ['standard', 'compact', 'extra-compact', 'auto'], true)
            ? $density : 'standard';
        $fontChoice = (string) ($options['font'] ?? 'serif');
        $fontChoice = in_array($fontChoice, ['serif', 'sans', 'display', 'mono'], true) ? $fontChoice : 'serif';
        // One stack per choice. Nothing here is fetched: a printable must set
        // in the church office with the network unplugged.
        $fontStacks = [
            'serif' => '"Iowan Old Style", "Palatino Linotype", Palatino, Georgia, "Times New Roman", serif',
            'sans' => '"Inter", "Helvetica Neue", Helvetica, "Segoe UI", Roboto, Arial, sans-serif',
            'display' => '"Archivo", "Haettenschweiler", "Arial Narrow", "Segoe UI", Impact, sans-serif',
            'mono' => '"SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace',
        ];
        $fontStack = $fontStacks[$fontChoice];

        // Resolved before the template runs, not after: a template that has to
        // lay content out to fit the page cannot do it without knowing what the
        // page is. The monthly grid was guessing, and printed a wall calendar
        // across two sheets.
        $orientation = (string) ($options['orientation'] ?? $template['orientation']);
        $paper = strtolower((string) ($options['paper'] ?? 'letter'));
        // Inches, portrait. The shell owns the CSS @page size; this is the same
        // fact in a form a template can compute with.
        $paperSizes = ['letter' => [8.5, 11.0], 'legal' => [8.5, 14.0],
                       'a4' => [8.27, 11.69], 'a3' => [11.69, 16.54]];
        [$paperWidthIn, $paperHeightIn] = $paperSizes[$paper] ?? $paperSizes['letter'];
        if ($orientation === 'landscape') {
            [$paperWidthIn, $paperHeightIn] = [$paperHeightIn, $paperWidthIn];
        }

        require_once __DIR__ . '/CalendarTheme.php';

        // The theme, and the presentation settings it has opinions about.
        // Validated in PrintConfig; re-checked here because this class is also
        // reachable from the ministry-schedule preview, which builds options
        // by hand rather than from a PrintConfig.
        $theme = (string) ($options['theme'] ?? CalendarTheme::DEFAULT);
        if (!CalendarTheme::exists($theme) || !CalendarTheme::supports($theme, $templateId)) {
            $theme = CalendarTheme::DEFAULT;
        }
        $entryDisplay = (string) ($options['entryDisplay'] ?? 'auto');
        $entryDisplay = in_array($entryDisplay, ['compact', 'readable', 'showcase', 'auto'], true)
            ? $entryDisplay : 'auto';
        $artwork = (string) ($options['artwork'] ?? 'none');
        $artwork = in_array($artwork, CalendarTheme::artworkFor($theme), true) ? $artwork : 'none';
        $decoration = (string) ($options['decoration'] ?? 'balanced');
        $decoration = in_array($decoration, CalendarTheme::DECORATIONS, true) ? $decoration : 'balanced';
        $inkFriendly = (bool) ($options['inkFriendly'] ?? false);
        // Ink-friendly is a print-economy switch, not a theme: it suppresses
        // the decoration and the page fills, and leaves the design otherwise
        // exactly as drawn.
        if ($inkFriendly) {
            $artwork = 'none';
        }
        // The safe zone is what the artwork costs the grid, so a theme printed
        // without artwork is not charged for a band nothing is drawn in.
        $safeZone = $artwork === 'none'
            ? ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0]
            : CalendarTheme::get($theme)['safeZone'];
        $themeChromeIn = CalendarTheme::chromeIn($theme);
        $themeCellPadIn = CalendarTheme::cellPadIn($theme);

        // Templates reach for it by fully-qualified name; requiring it here
        // keeps the composer the one place that knows the view path.
        require_once __DIR__ . '/PrintDensity.php';
        require_once __DIR__ . '/CellPlan.php';
        require_once __DIR__ . '/EntryPresentation.php';

        // Each template writes $body and $styles from $model and $colors.
        $body = '';
        $styles = '';
        require $this->viewPath . '/print/' . $template['file'];

        $branding = [
            'church' => (string) ($options['church'] ?? 'Church Portal'),
            'subtitle' => (string) ($options['subtitle'] ?? ''),
            'period' => $model['title'],
            'period_note' => $template['label'],
            'footer' => (string) ($options['footer'] ?? ''),
            'website' => (string) ($options['website'] ?? ''),
            'accent' => $accent,
        ];

        $title = trim($branding['church'] . ' — ' . $model['title']);

        // Document regions, handed to the shell as plain values. The shell
        // renders furniture; it does not decide policy, and it certainly does
        // not sanitise — the editorial HTML arrived already cleaned by
        // PrintConfig, which is the only place that judgement is made.
        $headerShow = $options['headerShow'] ?? ['church' => true, 'location' => true, 'period' => true, 'docType' => true];
        $footerShow = $options['footerShow'] ?? ['printed' => true, 'website' => true, 'church' => false, 'page' => false];
        $headerTitle = (string) ($options['headerTitle'] ?? '');
        $headerSubtitle = (string) ($options['headerSubtitle'] ?? '');
        $footerNote = (string) ($options['footerNote'] ?? '');
        $topInfo = (string) ($options['topInfo'] ?? '');
        $bottomInfo = (string) ($options['bottomInfo'] ?? '');
        $titleStyle = (string) ($options['titleStyle'] ?? 'classic');

        // The theme's own stylesheet and its decoration, layered *behind* the
        // document rather than baked into it: the grid stays real HTML text so
        // it prints sharp, wraps properly and can still be read aloud.
        $themeCss = $this->themeCss($theme, $inkFriendly);
        $artworkSvg = $artwork === 'none' ? '' : $this->artworkSvg($artwork, $decoration, $accent);

        ob_start();
        require $this->viewPath . '/print/_shell.php';

        return (string) ob_get_clean();
    }

    /**
     * A theme's stylesheet, read from disk and inlined.
     *
     * Inlined because a printable has to set with the network unplugged, and
     * read from disk because a theme is a design somebody maintains in a CSS
     * file, not a string buried in a PHP class. Classic has no file: it *is*
     * the base sheet, and giving it an empty override is the guarantee that
     * the church's existing calendar cannot regress when a theme is added.
     */
    private function themeCss(string $theme, bool $inkFriendly): string
    {
        $css = '';
        $file = $this->viewPath . '/print/themes/' . $theme . '.css';
        if ($theme !== CalendarTheme::DEFAULT && is_file($file)) {
            $css = (string) file_get_contents($file);
        }
        if ($inkFriendly) {
            $ink = $this->viewPath . '/print/themes/_ink.css';
            if (is_file($ink)) {
                $css .= "\n" . (string) file_get_contents($ink);
            }
        }

        return $css;
    }

    /**
     * The decorative layer, as inline SVG.
     *
     * SVG because decoration is the one part of the page that genuinely is a
     * picture — it scales to any paper, prints sharp and costs no request. The
     * calendar itself stays HTML: dates, names and assignments have to wrap,
     * reflow with the month and be readable by a screen reader.
     *
     * It is marked aria-hidden and carries no text, so nothing here can be the
     * only place a piece of information appears.
     */
    private function artworkSvg(string $set, string $decoration, string $accent): string
    {
        $file = $this->viewPath . '/print/artwork/' . $set . '.svg';
        if (!is_file($file)) {
            return '';
        }
        $svg = trim((string) file_get_contents($file));
        // Minimal / balanced / full is one curated dial, not a pile of
        // per-element controls: it fades the whole layer and, at minimal,
        // drops the pieces a designer marked as the first to go.
        $opacity = match ($decoration) {
            'minimal' => '0.45',
            'full' => '1',
            default => '0.8',
        };

        return '<div class="art art--' . htmlspecialchars($set, ENT_QUOTES, 'UTF-8')
            . ' art--' . htmlspecialchars($decoration, ENT_QUOTES, 'UTF-8')
            . '" aria-hidden="true" style="--art-o:' . $opacity
            . ';--art-accent:' . htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') . '">' . $svg . '</div>';
    }
}
