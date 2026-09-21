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
        // "initial" adds a last initial to a celebrant's name; "short" is the
        // same choice as older links spelled it.
        $nameStyle = in_array($options['nameStyle'] ?? 'first', ['initial', 'short'], true) ? 'short' : 'full';
        // Whether a month may run past one sheet (see PrintConfig::PAGE_HEIGHTS).
        $pageHeight = ($options['pageHeight'] ?? 'grow') === 'fit' ? 'fit' : 'grow';
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
        require_once __DIR__ . '/PrintConfig.php';
        $paperSizes = PrintConfig::PAPERS;
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
        // "Auto" follows the month being printed, never today's date: each
        // month of the model gets its own monthly theme, and the page itself
        // (masthead, weekly strips) takes the first month's.
        $monthThemes = [];
        if ($theme === CalendarTheme::AUTO && CalendarTheme::supports($theme, $templateId)) {
            foreach ($model['months'] as $m) {
                $monthThemes[$m['year'] . '-' . $m['month']] = CalendarTheme::forMonth((int) $m['month']);
            }
            $theme = $monthThemes !== [] ? reset($monthThemes) : CalendarTheme::forMonth((int) $start->format('n'));
        } elseif (!CalendarTheme::exists($theme) || !CalendarTheme::supports($theme, $templateId)) {
            $theme = CalendarTheme::DEFAULT;
        }
        // Classes the sheet carries for its theme: the id, and for a monthly
        // theme its family and season.
        $themeClasses = static fn (string $id): string => 'theme-' . $id
            . (CalendarTheme::isSeasonal($id) ? ' theme-seasonal season-' . CalendarTheme::get($id)['season'] : '');
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
        require_once __DIR__ . '/MemberTypeStyle.php';
        require_once __DIR__ . '/EntryPresentation.php';

        // Member types: fixed colours plus the directory's own symbols.
        $memberTypes = MemberTypeStyle::fromConfig(dirname($this->viewPath, 2) . '/config/member-type-icons.json');

        // The page's title leads the masthead, so it must say what the page
        // is. A title the reader typed wins; otherwise it is worked out from
        // what is on the sheet (see docTitle()).
        $headerTitle = trim((string) ($options['headerTitle'] ?? ''));
        $docTitle = $headerTitle !== '' ? $headerTitle
            : self::docTitle((array) ($options['sources'] ?? []), (array) ($options['sourceLabels'] ?? []), (string) $template['label']);
        // Height of that masthead, in inches, for templates that budget a
        // fitted page. Measured off the rendered Classic masthead.
        $mastheadIn = 0.98;
        // A long title wraps rather than shrinking, and each extra line of a
        // 30pt title costs about 0.43in the grid has to give up. Estimated from
        // its length at half an em a character, beside the month (~2.2in).
        if ($docTitle !== '') {
            $titleWidthIn = max(2.0, $paperWidthIn - 1.1 - 2.2);
            $perLine = max(8, (int) floor($titleWidthIn / (30 * 0.52 / 72)));
            $mastheadIn += 0.43 * (max(1, (int) ceil(mb_strlen($docTitle) / $perLine)) - 1);
        }

        // The studio's "Edit text on the sheet": editable regions and event
        // links are marked up. Screen only; nothing about it prints.
        $editable = !empty($options['editable']);

        // A background picture: validated values only, and a screen-only note
        // when it is missing or will print soft at this paper size.
        $background = is_array($options['background'] ?? null) ? $options['background'] : null;
        $printNotes = [];
        if (!empty($options['backgroundMissing'])) {
            $printNotes[] = 'The background picture could not be found or is not shared with you, so this calendar prints without it.';
        }
        if ($background !== null) {
            require_once __DIR__ . '/ImageIntake.php';
            $dpi = ImageIntake::printDpi((int) $background['width'], (int) $background['height'], $paperWidthIn, $paperHeightIn);
            if ($dpi > 0 && $dpi < 150) {
                $printNotes[] = 'The background picture gives about ' . $dpi . ' dpi at this paper size, so it may print soft. '
                    . 'A larger picture (' . (int) ceil($paperWidthIn * 150) . ' × ' . (int) ceil($paperHeightIn * 150) . ' pixels or more) will print sharp.';
            }
        }

        // The member-type key, for layouts that print birthdays at all.
        $legendRows = [];
        $legendNeutral = false;
        if (($options['legend'] ?? true) && !in_array($templateId, ['annual', 'sunday'], true)) {
            $printedTypes = [];
            foreach ($model['entries'] as $entry) {
                if (($entry['kind'] ?? '') === 'birth' && isset($entry['person'])) {
                    $printedTypes[] = (string) $entry['person']['memberType'];
                }
            }
            $legendRows = $memberTypes->legend($printedTypes);
            // "Not recorded" is listed only when a celebrant on the sheet has no type.
            $legendNeutral = $legendRows !== [] && array_filter(
                $printedTypes,
                static fn (string $t): bool => MemberTypeStyle::group($t) === null,
            ) !== [];
        }

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
     * The title a sheet gets when nobody typed one.
     *
     * Named after what is on it, ignoring holiday calendars (they decorate a
     * sheet; they are not what it is for): birthdays alone are "Birthdays",
     * one calendar is that calendar, and anything broader is the layout.
     *
     * @param list<string> $sources
     * @param array<string,string> $labels source => the layer's label
     */
    public static function docTitle(array $sources, array $labels, string $layoutLabel): string
    {
        $main = array_values(array_filter($sources, static fn (string $s): bool => !str_starts_with($s, 'holidays:')));
        if (count($main) === 1) {
            return $main[0] === 'birthdays' ? 'Birthdays' : (string) ($labels[$main[0]] ?? $layoutLabel);
        }

        return $layoutLabel;
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
        // The twelve monthly themes share one stylesheet; the rest have their own.
        $file = $this->viewPath . '/print/themes/'
            . (CalendarTheme::isSeasonal($theme) ? 'seasonal' : $theme) . '.css';
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
