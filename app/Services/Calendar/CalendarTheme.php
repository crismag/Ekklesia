<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * A calendar theme: the visual design a layout is dressed in.
 *
 * Four ideas are kept apart on purpose, because collapsing them is how print
 * tools turn into unmaintainable special cases:
 *
 *   Layout        the document — monthly grid, weekly strip, year at a glance
 *   Theme         the design applied to it — Classic, Editorial, Celebration
 *   Entry display how much room the writing inside a cell may take (CellPlan)
 *   Artwork       optional decoration, which never carries meaning
 *
 * A theme is data, not code. It names a stylesheet, some recommended defaults
 * and the insets its artwork needs kept clear; it does not get to reach into
 * the renderer. That is the smallest abstraction that supports the four themes
 * actually being built, and deliberately not a theming framework.
 *
 * The one rule that outranks the rest: **Classic is the calendar this church
 * already prints.** Its stylesheet is empty because it *is* the base sheet.
 */
final class CalendarTheme
{
    public const DEFAULT = 'classic';

    /** Decoration levels, for themes that carry artwork at all. */
    public const DECORATIONS = ['minimal', 'balanced', 'full'];

    /**
     * @return array<string,array{
     *     id:string,label:string,blurb:string,layouts:list<string>,
     *     palette:array{paper:string,ink:string,heading:string,accent:string,grid:string,cell:string,band:string},
     *     ink:string,month?:int,season?:string,family?:string,motif?:string,
     *     defaults:array<string,mixed>,artwork:list<string>,chromeIn:float,cellPadIn:float,
     *     safeZone:array{top:float,right:float,bottom:float,left:float}
     * }>
     */
    public static function all(): array
    {
        return [
            'classic' => [
                'id' => 'classic',
                'palette' => ['paper' => '#ffffff', 'ink' => '#16211c', 'heading' => '#16211c', 'accent' => '#0c5a45', 'grid' => '#d3ddd7', 'cell' => '#ffffff', 'band' => '#0c5a45'],
                'ink' => 'medium',
                'label' => 'Classic',
                'blurb' => 'The church’s usual sheet — restrained, dense, and good for operational calendars.',
                'layouts' => ['monthly', 'weekly', 'sunday', 'annual'],
                'defaults' => [
                    'entryDisplay' => 'auto', 'density' => 'standard',
                    'font' => 'serif', 'titleStyle' => 'classic', 'accent' => '',
                ],
                'artwork' => ['none'],
                // Classic is the datum: its masthead is what the grid already budgets for.
                'chromeIn' => 0.0,
                'cellPadIn' => 0.0,
                'safeZone' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            ],
            'editorial' => [
                'id' => 'editorial',
                'palette' => ['paper' => '#ffffff', 'ink' => '#16211c', 'heading' => '#2a2a2a', 'accent' => '#2a2a2a', 'grid' => '#d8ded9', 'cell' => '#ffffff', 'band' => '#2a2a2a'],
                'ink' => 'low',
                'label' => 'Editorial',
                'blurb' => 'A publication rather than a notice: large title, generous margins, a quiet grid.',
                'layouts' => ['monthly', 'weekly', 'sunday', 'annual'],
                'defaults' => [
                    'entryDisplay' => 'readable', 'density' => 'standard',
                    'font' => 'serif', 'titleStyle' => 'editorial', 'accent' => '',
                ],
                'artwork' => ['none'],
                // Measured, not guessed. A 44pt centred title costs three quarters of an inch more than Classic's, and the first version of this theme did not pay for it — the wall calendar printed on two sheets.
                'chromeIn' => 0.755,
                'cellPadIn' => 0.0,
                'safeZone' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            ],
            'planner' => [
                'id' => 'planner',
                'palette' => ['paper' => '#fdf7f0', 'ink' => '#3b2a20', 'heading' => '#8a5a3c', 'accent' => '#c2643a', 'grid' => '#e2cdbb', 'cell' => '#fffdfa', 'band' => '#c2643a'],
                'ink' => 'medium',
                'label' => 'Planner',
                'blurb' => 'Warm and open, with room left to write on. Best for quieter months.',
                'layouts' => ['monthly'],
                'defaults' => [
                    'entryDisplay' => 'readable', 'density' => 'standard',
                    'font' => 'sans', 'titleStyle' => 'banner', 'accent' => '#c2643a',
                ],
                'artwork' => ['none', 'garden'],
                // Its title is smaller than Classic's; taking the difference back would change the row height for no reason.
                'chromeIn' => 0.0,
                'cellPadIn' => 0.0,
                // The bottom inset is the band the foliage lives in. It sits
                // between the grid and the colophon rather than under the
                // colophon: the artwork SVG is stretched to the sheet, so a
                // piece drawn near its foot lands at the foot of the page
                // whatever margin the footer is given — the only way to keep
                // decoration off the footer text is to give it its own band.
                'safeZone' => ['top' => 0.0, 'right' => 0.06, 'bottom' => 0.80, 'left' => 0.06],
            ],
            'celebration' => [
                'id' => 'celebration',
                'palette' => ['paper' => '#ffffff', 'ink' => '#1d3a24', 'heading' => '#1d3a24', 'accent' => '#2f6b3c', 'grid' => '#cfe0d2', 'cell' => '#f2f7f2', 'band' => '#2f6b3c'],
                'ink' => 'medium',
                'label' => 'Celebration',
                'blurb' => 'For birthdays and anniversaries: rounded cells, big names, friendly decoration.',
                'layouts' => ['monthly'],
                'defaults' => [
                    'entryDisplay' => 'showcase', 'density' => 'standard',
                    'font' => 'sans', 'titleStyle' => 'banner', 'accent' => '#2f6b3c',
                ],
                'artwork' => ['none', 'garden', 'confetti'],
                // A centred 34pt banner and the pill weekday strip.
                'chromeIn' => 0.545,
                // Rounded cells need more padding than a ruled grid, and that
                // padding comes out of the same inch the writing lives in. Left
                // undeclared, a two-line name on the 21st overran its cell by
                // exactly this much.
                'cellPadIn' => 0.075,
                'safeZone' => ['top' => 0.0, 'right' => 0.10, 'bottom' => 0.80, 'left' => 0.10],
            ],
            'hearth' => [
                'id' => 'hearth',
                'palette' => ['paper' => '#ffffff', 'ink' => '#2a2622', 'heading' => '#5b3a24', 'accent' => '#8a5a2b', 'grid' => '#e3d8cc', 'cell' => '#ffffff', 'band' => '#f4ece2'],
                'ink' => 'medium',
                'label' => 'Hearth',
                'blurb' => 'Warm and welcoming for a noticeboard: a light header band, fine rules and a small sprig in the corner.',
                'layouts' => ['monthly', 'weekly'],
                'defaults' => [
                    'entryDisplay' => 'auto', 'density' => 'standard',
                    'font' => 'serif', 'titleStyle' => 'classic', 'accent' => '#8a5a2b',
                ],
                'artwork' => ['none'],
                // The band's padding, measured against Classic's masthead.
                'chromeIn' => 0.3,
                'cellPadIn' => 0.0,
                'safeZone' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            ],
            'economy' => [
                'id' => 'economy',
                'palette' => ['paper' => '#ffffff', 'ink' => '#111111', 'heading' => '#111111', 'accent' => '#111111', 'grid' => '#9a9a9a', 'cell' => '#ffffff', 'band' => '#111111'],
                'ink' => 'low',
                'label' => 'Economy',
                'blurb' => 'The least ink: black hairlines, no fills, no decoration.',
                'layouts' => ['monthly', 'weekly', 'agenda', 'sunday', 'annual', 'planner'],
                'defaults' => [
                    'entryDisplay' => 'auto', 'density' => 'standard',
                    'font' => 'sans', 'titleStyle' => 'classic', 'accent' => '#3f3f46',
                ],
                'artwork' => ['none'],
                'chromeIn' => 0.0,
                'cellPadIn' => 0.0,
                'safeZone' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            ],
        ] + self::seasonal();
    }

    /** "Follow the month": resolved per printed month, never from today's date. */
    public const AUTO = 'auto';

    /** Canadian seasons, each three months; winter runs across the new year. */
    public const SEASONS = [
        'winter' => ['label' => 'Winter', 'months' => [12, 1, 2]],
        'spring' => ['label' => 'Spring', 'months' => [3, 4, 5]],
        'summer' => ['label' => 'Summer', 'months' => [6, 7, 8]],
        'fall' => ['label' => 'Fall', 'months' => [9, 10, 11]],
    ];

    /**
     * The twelve monthly themes.
     *
     * One structure for all twelve — the same title band, grid, key and footer,
     * set by print/themes/seasonal.css — so nobody relearns the sheet each
     * month. What changes is the palette, the small motif in the header corner,
     * and the season's title treatment. The months within a season progress:
     * March is a thaw, not a garden; November is frost and bare branches.
     * December is snow and evergreen, with nothing that makes it Christmas.
     *
     * Motifs are single-colour line drawings in the accent, at low opacity, in
     * the header only; a printer that drops background graphics drops them and
     * loses nothing.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function seasonal(): array
    {
        $months = [
            1 => ['northern-stillness', 'Northern Stillness', 'Icy blue and white: a quiet January of fresh snow.',
                ['ink' => '#1b2733', 'heading' => '#1f3a5a', 'accent' => '#4a7fb0', 'band' => '#eaf2f9', 'grid' => '#cddbe8', 'cell' => '#f6f9fc'],
                // A six-armed snowflake.
                '<path d="M24 6v36M8.4 15l31.2 18M8.4 33l31.2-18"/><path d="M20 9l4 4 4-4M20 39l4-4 4 4M10 20l5-1-1-5M38 28l-5 1 1 5M10 28l5 1-1 5M38 20l-5-1 1-5"/>'],
            2 => ['winter-warmth', 'Winter Warmth', 'Deep winter blue with a little warm lamplight.',
                ['ink' => '#1b2233', 'heading' => '#1d2d5c', 'accent' => '#b8752a', 'band' => '#eceff7', 'grid' => '#cfd5e6', 'cell' => '#f8f7f3'],
                // A frosted window pane with a low sun.
                '<rect x="8" y="8" width="32" height="32" rx="2"/><path d="M24 8v32M8 24h32"/><circle cx="33" cy="15" r="3.5"/><path d="M11 34c3-2 5-2 8 0M28 37c3-2 5-2 8 0"/>'],
            3 => ['first-thaw', 'First Thaw', 'Grey-blue melt with the first green showing through.',
                ['ink' => '#26323a', 'heading' => '#3a4a56', 'accent' => '#5f8f63', 'band' => '#eef2f3', 'grid' => '#d2dbdd', 'cell' => '#f7f9f8'],
                // A snow ridge melting, one shoot coming through.
                '<path d="M4 34c6-5 10-5 14-1s9 3 13-2 9-4 13 0"/><path d="M24 33V21M24 25c-4 0-6-3-6-6 4 0 6 3 6 6zM24 23c3 0 5-2 5-5-3 0-5 2-5 5z"/><path d="M9 39v2M16 40v2M33 39v2"/>'],
            4 => ['gentle-rain', 'Gentle Rain', 'Rain-washed blue and fresh April green.',
                ['ink' => '#1d3440', 'heading' => '#24506b', 'accent' => '#3a8a78', 'band' => '#e8f3f5', 'grid' => '#cfe2e6', 'cell' => '#f6fbfb'],
                // A small cloud and fine rain.
                '<path d="M14 22h20a6 6 0 0 0 0-12 8 8 0 0 0-15-2 6 6 0 0 0-5 14z"/><path d="M15 28l-2 5M22 28l-2 5M29 28l-2 5M18 36l-2 5M25 36l-2 5M32 36l-2 5"/>'],
            5 => ['new-blossom', 'New Blossom', 'Soft blossom and full May greenery.',
                ['ink' => '#2e2228', 'heading' => '#6b2d4a', 'accent' => '#b85f80', 'band' => '#fbeef3', 'grid' => '#ecd3dd', 'cell' => '#fdf8fa'],
                // A five-petal blossom on a sprig.
                '<circle cx="28" cy="18" r="2.5"/><path d="M28 15.5c-2-5 2-8 3-3M30.4 17c4-3 7 1 2 2.6M29.6 20.2c3 4-1 7-2.6 2.4M26.2 20c-4 3-6-2-1.6-2.8M26 16.2c-4-3 0-7 2-3"/><path d="M6 42c8-6 14-12 20-20M14 36c-3-3-2-6 1-7 1 3 0 6-1 7zM19 30c3 0 5-2 5-5-3 0-5 2-5 5z"/>'],
            6 => ['open-skies', 'Open Skies', 'Clear sky blue over bright June green.',
                ['ink' => '#16293a', 'heading' => '#1b4f7a', 'accent' => '#2a86ba', 'band' => '#e7f4fb', 'grid' => '#cce4f1', 'cell' => '#f7fbfe'],
                // Sun above a low horizon.
                '<circle cx="30" cy="16" r="6"/><path d="M30 5v3M30 24v3M19 16h3M38 16h3M22 8l2 2M36 22l2 2M38 8l-2 2M22 24l2-2"/><path d="M4 38c8-4 14-4 20-1s12 3 20-1"/>'],
            7 => ['summer-meadow', 'Summer Meadow', 'Warm meadow green and July sunshine yellow.',
                ['ink' => '#1f2d1c', 'heading' => '#2f5a2a', 'accent' => '#6f9a32', 'band' => '#f1f7e4', 'grid' => '#d9e7c4', 'cell' => '#fbfdf6'],
                // Meadow grass with one flower.
                '<path d="M6 42c2-8 3-14 1-20M13 42c0-7 2-12 6-16M20 42c1-9 0-15-3-21M28 42c0-6 3-11 8-14M35 42c1-6 0-11-2-16M42 42c0-5 1-9 3-12"/><circle cx="17" cy="18" r="3"/>'],
            8 => ['golden-days', 'Golden Days', 'Sunlit August gold and the ripe colours of late summer.',
                ['ink' => '#2d2412', 'heading' => '#6a4a12', 'accent' => '#b88322', 'band' => '#fbf3df', 'grid' => '#ecdcb4', 'cell' => '#fffbf1'],
                // A ripe wheat ear.
                '<path d="M24 44V12"/><path d="M24 14c-4-1-6-4-6-8 4 1 6 4 6 8zM24 14c4-1 6-4 6-8-4 1-6 4-6 8zM24 22c-4-1-6-4-6-8 4 1 6 4 6 8zM24 22c4-1 6-4 6-8-4 1-6 4-6 8zM24 30c-4-1-6-4-6-8 4 1 6 4 6 8zM24 30c4-1 6-4 6-8-4 1-6 4-6 8z"/>'],
            9 => ['harvest-beginning', 'Harvest Beginning', 'Late-summer green turning towards the first gold.',
                ['ink' => '#252a17', 'heading' => '#4a5a1f', 'accent' => '#a3802a', 'band' => '#f3f5e3', 'grid' => '#dfe3c3', 'cell' => '#fcfcf4'],
                // One leaf beginning to turn, and an acorn.
                '<path d="M10 38C12 22 22 12 38 10c-2 16-12 26-28 28z"/><path d="M10 38L30 18"/><path d="M34 32a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM30 32h8M34 29v3"/>'],
            10 => ['maple-colour', 'Maple Colour', 'Maple red, orange and gold: the height of the fall.',
                ['ink' => '#2e1a12', 'heading' => '#7a2a14', 'accent' => '#b44a1c', 'band' => '#fcebe2', 'grid' => '#efd1c2', 'cell' => '#fffaf6'],
                // A maple leaf.
                '<path d="M24 44V30M24 30l-9 3 2-5-9-7 4-1-2-7 7 3 1-4 6 7-1-11 4 3 3-7 3 7 4-3-1 11 6-7 1 4 7-3-2 7 4 1-9 7 2 5z"/>'],
            11 => ['first-frost', 'First Frost', 'Muted earth and frost grey, bare branches and the first snow.',
                ['ink' => '#26211d', 'heading' => '#4a4038', 'accent' => '#6f7e88', 'band' => '#f0eeeb', 'grid' => '#dcd7d1', 'cell' => '#faf9f7'],
                // A bare branch with a dusting of snow.
                '<path d="M4 38c10-2 18-8 26-16M18 32c-2-5-1-9 2-12M26 25c4-1 7-4 8-8M30 22c5 0 9 2 12 5"/><circle cx="12" cy="30" r="1"/><circle cx="36" cy="10" r="1"/><circle cx="42" cy="18" r="1"/><circle cx="22" cy="14" r="1"/>'],
            12 => ['evergreen-snow', 'Evergreen Snow', 'Evergreen boughs under fresh snow and clear winter light.',
                ['ink' => '#16261e', 'heading' => '#1f4a36', 'accent' => '#357354', 'band' => '#eaf3ee', 'grid' => '#cfe0d6', 'cell' => '#f7fbf9'],
                // A fir bough, snow along its top.
                '<path d="M6 36L42 14"/><path d="M12 32l-2-7M12 32l6 3M19 28l-2-7M19 28l6 3M26 24l-2-7M26 24l6 3M33 20l-2-7M33 20l6 3"/><path d="M9 28c3-3 6-4 9-4M23 20c3-3 6-4 9-4"/>'],
        ];
        $seasonOf = [];
        foreach (self::SEASONS as $sid => $season) {
            foreach ($season['months'] as $m) {
                $seasonOf[$m] = $sid;
            }
        }
        $out = [];
        foreach ($months as $m => [$id, $label, $blurb, $palette, $motif]) {
            $out[$id] = [
                'id' => $id,
                'palette' => ['paper' => '#ffffff', 'ink' => $palette['ink'], 'heading' => $palette['heading'],
                    'accent' => $palette['accent'], 'grid' => $palette['grid'], 'cell' => $palette['cell'],
                    'band' => $palette['band']],
                // Pale tints and a small line motif: no more ink than Classic.
                'ink' => 'medium',
                'label' => $label,
                'blurb' => $blurb,
                'month' => $m,
                'season' => $seasonOf[$m],
                'family' => 'seasonal',
                'motif' => $motif,
                'layouts' => ['monthly', 'weekly'],
                'defaults' => [
                    'entryDisplay' => 'auto', 'density' => 'standard',
                    'font' => 'serif', 'titleStyle' => 'classic', 'accent' => '',
                ],
                'artwork' => ['none'],
                // The tinted title band, as Hearth's.
                'chromeIn' => 0.3,
                'cellPadIn' => 0.0,
                'safeZone' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            ];
        }

        return $out;
    }

    /** The monthly theme for a month, 1–12. */
    public static function forMonth(int $month): string
    {
        foreach (self::seasonal() as $id => $theme) {
            if ($theme['month'] === $month) {
                return $id;
            }
        }

        return self::DEFAULT;
    }

    /** The season a month belongs to (winter spans December to February). */
    public static function seasonOf(int $month): string
    {
        foreach (self::SEASONS as $id => $season) {
            if (in_array($month, $season['months'], true)) {
                return $id;
            }
        }

        return 'winter';
    }

    /**
     * The theme a page is actually drawn in: itself, or for "auto" the theme
     * of the month being printed. Unknown ids resolve to Classic.
     */
    public static function resolve(string $id, int $month): string
    {
        if ($id === self::AUTO) {
            return self::forMonth($month);
        }

        return self::exists($id) ? $id : self::DEFAULT;
    }

    /** Whether this is one of the twelve monthly themes. */
    public static function isSeasonal(string $id): bool
    {
        return (self::all()[$id]['family'] ?? '') === 'seasonal';
    }

    /** @return array<string,mixed> the theme, or Classic if the id is unknown. */
    public static function get(string $id): array
    {
        $all = self::all();

        return $all[$id] ?? $all[self::DEFAULT];
    }

    public static function exists(string $id): bool
    {
        return isset(self::all()[$id]);
    }

    /**
     * Whether a theme can dress a layout.
     *
     * Planner and Celebration are monthly-only, and saying so is better than
     * rendering a decorative year-at-a-glance nobody designed.
     */
    public static function supports(string $id, string $layout): bool
    {
        if ($id === self::AUTO) {
            return in_array($layout, ['monthly', 'weekly'], true);
        }

        return in_array($layout, (array) self::get($id)['layouts'], true);
    }

    /**
     * How much more page furniture this theme uses than Classic, in inches.
     *
     * A grid has to be told how much of the sheet it may have, and that answer
     * changes when a theme replaces a modest masthead with a 44pt centred
     * title. Expressed as a difference from Classic rather than an absolute so
     * that Classic's own grid height is arithmetically untouched — the theme
     * system cannot regress the calendar the church already prints.
     */
    public static function chromeIn(string $id): float
    {
        return (float) (self::get($id)['chromeIn'] ?? 0.0);
    }

    /**
     * Extra padding this theme puts inside a calendar cell, in inches.
     *
     * Same reasoning as chromeIn, one level down: a cell's height budget is
     * arithmetic, and a theme that quietly spends part of it on rounded
     * padding will overrun the plan the renderer made.
     */
    public static function cellPadIn(string $id): float
    {
        return (float) (self::get($id)['cellPadIn'] ?? 0.0);
    }

    /**
     * The theme's colour tokens as CSS custom properties (`--th-paper` and so
     * on), written onto the sheet by the composer. This is the theme contract:
     * a theme stylesheet colours itself through these, and never touches the
     * member-type colours (MemberTypeStyle), which mean the same in every theme.
     */
    public static function tokenCss(string $id): string
    {
        $out = [];
        foreach ((array) (self::get($id)['palette'] ?? []) as $name => $hex) {
            if (preg_match('/^#[0-9a-f]{6}$/i', (string) $hex) === 1) {
                $out[] = '--th-' . $name . ':' . $hex;
            }
        }

        $motif = (string) (self::get($id)['motif'] ?? '');
        if ($motif !== '') {
            $out[] = '--th-motif:' . self::motifUrl($motif, (string) self::get($id)['palette']['accent']);
        }

        return implode(';', $out);
    }

    /**
     * A motif as a CSS url(): our own fixed line drawings (never user input),
     * stroked in the theme's accent.
     */
    public static function motifUrl(string $paths, string $stroke): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none" stroke="' . $stroke
            . '" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';

        return 'url("data:image/svg+xml,' . rawurlencode($svg) . '")';
    }

    /** The same palette for a gallery card's miniature. */
    public static function swatchCss(string $id): string
    {
        $p = (array) (self::get($id)['palette'] ?? []);
        // A monthly theme's band is a pale tint, which at thumbnail size makes
        // twelve cards look alike; its accent is what says which month it is.
        $band = self::isSeasonal($id) ? ($p['accent'] ?? '#0c5a45') : ($p['band'] ?? '#0c5a45');

        return '--sw-paper:' . ($p['paper'] ?? '#fff') . ';--sw-band:' . $band
            . ';--sw-accent:' . ($p['accent'] ?? '#2c6ea5') . ';--sw-grid:' . ($p['grid'] ?? '#dde5e0')
            . ';--sw-cell:' . ($p['cell'] ?? '#eef2ef');
    }

    /** The artwork sets a theme offers, always including 'none'. */
    public static function artworkFor(string $id): array
    {
        return (array) self::get($id)['artwork'];
    }

    /**
     * The theme's recommended settings.
     *
     * Recommended, not imposed: the studio applies these when the reader has
     * not touched the control in question, and leaves a deliberate choice
     * alone. Changing theme should never silently discard a decision.
     *
     * @return array<string,mixed>
     */
    public static function defaultsFor(string $id): array
    {
        return (array) self::get($id)['defaults'];
    }
}
