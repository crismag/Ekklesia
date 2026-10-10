<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * How big the writing in one calendar cell is allowed to be.
 *
 * The wall calendar had exactly one answer for this: 7.4pt, always. That is the
 * right size for a Sunday with four services on it and a poor one for a
 * birthday sheet, where a single celebrant sat as a tiny line in the corner of
 * an otherwise empty inch-and-a-quarter square. The information was correct and
 * looked like a database annotation.
 *
 * So the size is now chosen per cell, from a *ladder of four named tiers* — not
 * by shrinking type until something fits. That distinction is the whole design:
 *
 *  - A tier is a deterministic set of measurements. Cells at the same tier look
 *    identical, and a tier can be expressed as one CSS class, so the stylesheet
 *    keeps doing the typography and print output stays predictable.
 *  - The loosest tier whose content genuinely fits is the one chosen. A day
 *    with one name gets the big treatment; the day beside it with five entries
 *    gets today's compact rows. Busy days looking busy is information, not
 *    inconsistency.
 *  - Nothing is ever silently dropped. When even the tightest tier cannot hold
 *    the day, the surplus is counted and reported as "+n more", and the room
 *    for that line is reserved *before* the entries are placed.
 *
 * There is deliberately no fit-to-size loop. The bottom of the ladder is a
 * readable size and the answer below it is "+n more", not six point.
 */
final class CellPlan
{
    /**
     * Loosest first. The names are the vocabulary the stylesheet uses too.
     *
     * `compact` reproduces the calendar this church already prints, to the
     * point of sharing PrintDensity's measurements: 7.4pt with the density's
     * own leading. Changing that would change what everybody already has.
     */
    public const TIERS = ['showcase', 'readable', 'normal', 'compact'];

    /**
     * Per-tier type, in points at type-scale 1.0.
     *
     * `wrap` is how many lines the primary text may take. Only the roomy tiers
     * may wrap: on a compact row a wrapped title makes the cell's height
     * unpredictable, which is what used to push the grid onto a second sheet.
     *
     * @return array{font:float,leading:float,gapFactor:float,wrap:int,secondary:bool}
     */
    public static function tier(string $tier): array
    {
        return match ($tier) {
            'showcase' => ['font' => 15.0, 'leading' => 1.14, 'gapFactor' => 2.6, 'wrap' => 3, 'secondary' => true],
            'readable' => ['font' => 11.0, 'leading' => 1.18, 'gapFactor' => 1.9, 'wrap' => 2, 'secondary' => true],
            'normal' => ['font' => 9.0, 'leading' => 1.22, 'gapFactor' => 1.35, 'wrap' => 2, 'secondary' => true],
            default => ['font' => 7.4, 'leading' => 1.26, 'gapFactor' => 1.0, 'wrap' => 1, 'secondary' => true],
        };
    }

    /**
     * Which tiers a display mode is willing to use, loosest first.
     *
     * Compact is a single-tier ladder on purpose: it is the escape hatch for
     * anybody who wants the old sheet back exactly, and an escape hatch that
     * quietly adapts is not one.
     *
     * Auto stops at `readable`. A Classic church calendar with one 15pt cell
     * beside a 7.4pt one reads as a mistake; reaching the top of the ladder is
     * something a reader asks for by choosing Showcase, and something the
     * Celebration theme asks for on their behalf.
     *
     * @return list<string>
     */
    public static function ladder(string $mode): array
    {
        return match ($mode) {
            'compact' => ['compact'],
            'readable' => ['readable', 'normal', 'compact'],
            'showcase' => ['showcase', 'readable', 'normal', 'compact'],
            default => ['readable', 'normal', 'compact'],
        };
    }

    /**
     * Plan one cell.
     *
     * @param list<array{primary:string,secondary:string}> $items the projected
     *        entries, already reduced to what will actually be set
     * @param float $usableIn height left in the cell once the date number and
     *        the cell's own padding are paid for
     * @param float $cellWidthIn the text column's width, which decides wrapping
     * @param float $moreHeightIn what a "+n more" line costs
     * @return array{tier:string,shown:int,hidden:int,state:string}
     *         `state` is the deterministic class the template renders:
     *         sparse | normal | dense | overflow
     */
    public static function plan(
        array $items,
        string $mode,
        float $usableIn,
        float $cellWidthIn,
        float $typeScale = 1.0,
        float $moreHeightIn = 0.134,
        ?int $hardCap = null,
    ): array {
        $count = count($items);
        if ($count === 0) {
            return ['tier' => 'compact', 'wrap' => false, 'shown' => 0, 'hidden' => 0, 'state' => 'empty'];
        }

        $ladder = self::ladder($mode);

        // Two passes, in this order, because they encode a priority:
        //
        //   1. Show every name in full. A wrapped name at nine point beats a
        //      truncated one at fifteen — nobody wants to be "Kimberly Nguye"
        //      on the noticeboard.
        //   2. Only if no tier can hold them whole, allow the ellipsis — and
        //      then take the *largest* tier that fits, because an ellipsis at
        //      nine point is still better than one at seven and a half.
        //
        // A single interleaved pass gets this wrong in both directions: it
        // truncated names it had room to wrap, and it dropped to the smallest
        // type when it had to truncate anyway.
        foreach ([true, false] as $whole) {
            foreach ($ladder as $tier) {
                if ($whole && !self::rendersWhole($items, $tier, $cellWidthIn)) {
                    continue;
                }
                $total = 0.0;
                foreach ($items as $item) {
                    $total += self::entryHeightIn($item, $tier, $cellWidthIn, $typeScale, $whole);
                }
                if ($total <= $usableIn) {
                    // Compact hands down a hard cap so that it reproduces the
                    // calendar this church already prints entry for entry. The
                    // measurement here is slightly more generous than the one
                    // that cap came from, and "slightly more generous" is still
                    // a change to everybody's noticeboard.
                    $shown = $hardCap === null ? $count : min($count, max(1, $hardCap));

                    return [
                        'tier' => $tier,
                        'wrap' => $whole,
                        'shown' => $shown,
                        'hidden' => $count - $shown,
                        'state' => $count - $shown > 0 ? 'overflow' : self::state($tier, $count),
                    ];
                }
            }
        }

        // Nothing on the ladder holds the whole day, so the tightest tier takes
        // as many as it can and says how many it could not. The "+n more" line
        // is paid for first: it appears exactly when the cell is full, which is
        // exactly when there would otherwise be no room left to print it.
        $tier = 'compact';
        $wrap = false;
        // Every tier has been tried whole and truncated, so the day genuinely
        // does not fit. Nothing is dropped quietly: the tightest tier takes as
        // many as it can hold and the rest are counted.
        $room = max(0.0, $usableIn - ($moreHeightIn * $typeScale));
        $shown = 0;
        $used = 0.0;
        foreach ($items as $item) {
            $used += self::entryHeightIn($item, $tier, $cellWidthIn, $typeScale, false);
            if ($used > $room) {
                break;
            }
            $shown++;
        }
        // One entry beside "+6 more" tells nobody anything; show two and count
        // the rest, even if that costs a hair of overflow the cell can absorb.
        $shown = max(1, min($count, $shown));

        return [
            'tier' => $tier,
            'wrap' => $wrap,
            'shown' => $shown,
            'hidden' => $count - $shown,
            'state' => $count - $shown > 0 ? 'overflow' : self::state($tier, $count),
        ];
    }

    /**
     * Whether this tier can set every headline in full.
     *
     * A tier's `wrap` is a ceiling on lines, so text needing more than that
     * ceiling would be cut off — which is what disqualifies compact for a
     * 27-character name, and disqualifies every tier for a name long enough
     * that nothing can hold it whole.
     */
    private static function rendersWhole(array $items, string $tier, float $cellWidthIn): bool
    {
        $wrap = (int) self::tier($tier)['wrap'];
        foreach ($items as $item) {
            if (self::naturalLines((string) ($item['primary'] ?? ''), $tier, $cellWidthIn) > $wrap) {
                return false;
            }
        }

        return true;
    }

    /** Lines this text wants at a tier, before the tier's ceiling is applied. */
    public static function naturalLines(string $text, string $tier, float $cellWidthIn): int
    {
        $t = self::tier($tier);
        $charWidthIn = ($t['font'] * 0.5) / 72.0;
        $perLine = max(6, (int) floor(max(0.4, $cellWidthIn) / $charWidthIn));

        return max(1, (int) ceil(max(1, mb_strlen(trim($text))) / $perLine));
    }

    /** How tall one entry is at a tier, in inches, wrapping included. */
    public static function entryHeightIn(
        array $item,
        string $tier,
        float $cellWidthIn,
        float $typeScale = 1.0,
        bool $wrap = true,
    ): float {
        $t = self::tier($tier);
        $lines = $wrap ? self::linesFor((string) ($item['primary'] ?? ''), $tier, $cellWidthIn) : 1;
        if ($t['secondary'] && trim((string) ($item['secondary'] ?? '')) !== '') {
            // The two roomy tiers always set the secondary on its own line.
            // Compact and normal keep it inline after the headline — but only
            // while the headline is one line: once it wraps, the browser puts
            // the trailing "· 20" on a line of its own too, and a budget that
            // did not expect that overran the cell by six pixels.
            if (self::secondaryIsBlock($tier) || $lines > 1) {
                $lines += ($t['font'] - 2.0) / $t['font'];
            }
        }

        $gapPt = 1.4 * $t['gapFactor'];

        // A browser rounds every line box up to a whole device pixel, so an
        // arithmetically exact budget is optimistic by up to a pixel a line —
        // enough, at two entries of two lines each, to overrun a cell by four.
        // A pixel per line is the allowance; it is a measurement, not padding.
        $rounding = ceil($lines) / 96.0;

        return ((($t['font'] * $t['leading'] * $lines) + $gapPt) / 72.0 * $typeScale) + $rounding;
    }

    /**
     * How many lines a string takes at a tier.
     *
     * An average glyph is about half the type size wide in the faces this
     * document uses. That is an estimate, and it is deliberately the only one:
     * it decides *which named tier* to use, and being a character out simply
     * picks the next tier down rather than clipping anything — the tier's own
     * `wrap` ceiling and the overflow count do the actual protecting.
     */
    public static function linesFor(string $text, string $tier, float $cellWidthIn): int
    {
        $t = self::tier($tier);
        $charWidthIn = ($t['font'] * 0.5) / 72.0;
        $perLine = max(6, (int) floor(max(0.4, $cellWidthIn) / $charWidthIn));
        $len = max(1, mb_strlen(trim($text)));

        return max(1, min((int) $t['wrap'], (int) ceil($len / $perLine)));
    }

    /** Whether a tier sets the secondary text on its own line. */
    public static function secondaryIsBlock(string $tier): bool
    {
        return $tier === 'readable' || $tier === 'showcase';
    }

    /** The presentation state a template turns into a class. */
    private static function state(string $tier, int $count): string
    {
        if ($tier === 'showcase' || ($tier === 'readable' && $count <= 2)) {
            return 'sparse';
        }

        return $count >= 4 ? 'dense' : 'normal';
    }

    /** The CSS custom properties one tier needs. */
    public static function cssVars(string $tier): string
    {
        $t = self::tier($tier);

        return '--e-fs:' . rtrim(rtrim(number_format($t['font'], 2, '.', ''), '0'), '.') . 'pt'
            . ';--e-lh:' . number_format($t['leading'], 2, '.', '')
            . ';--e-gap:' . number_format(1.4 * $t['gapFactor'], 2, '.', '') . 'pt'
            . ';--e-wrap:' . (int) $t['wrap'];
    }
}
