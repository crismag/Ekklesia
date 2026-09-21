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
     *     ink:string,
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
                'blurb' => 'The least ink: black hairlines, no fills, no decoration. Member types are told apart by their symbols.',
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
        ];
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

        return implode(';', $out);
    }

    /** The same palette for a gallery card's miniature. */
    public static function swatchCss(string $id): string
    {
        $p = (array) (self::get($id)['palette'] ?? []);

        return '--sw-paper:' . ($p['paper'] ?? '#fff') . ';--sw-band:' . ($p['band'] ?? '#0c5a45')
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
