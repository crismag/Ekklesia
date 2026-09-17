<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * How tightly a calendar grid is set.
 *
 * A separate axis from type size, and worth keeping separate: somebody printing
 * for a noticeboard wants *large* type with *less* air around it, and a single
 * "size" control cannot express that.
 *
 * Standard is the calendar this church already prints. The tighter modes buy
 * entries per day by spending the space between them — never by shrinking the
 * type, which is the one thing a wall calendar cannot afford.
 *
 * Auto is the interesting one. It picks the *loosest* setting at which no day
 * has to hide anything behind "+n more" — so a quiet month stays airy and a
 * busy one tightens only as far as it must. If even the tightest still hides
 * entries, it says so rather than compressing further.
 */
final class PrintDensity
{
    /** In the order Auto tries them: loosest first. */
    public const LADDER = ['standard', 'compact', 'extra-compact'];

    /**
     * Per-mode measurements, in inches at 96dpi.
     *
     * `cellPad`, `numHeight`, `entHeight` and `moreHeight` are what the grid
     * budgets with; they were measured off a rendered cell rather than
     * estimated, because a budget that is optimistic by one line clips.
     *
     * @return array{cellPad:float,numHeight:float,entHeight:float,moreHeight:float,
     *               padTop:string,padBottom:string,entGap:string,lineHeight:string}
     */
    public static function metrics(string $mode): array
    {
        return match ($mode) {
            'compact' => [
                'cellPad' => 0.048, 'numHeight' => 0.160, 'entHeight' => 0.140, 'moreHeight' => 0.125,
                'padTop' => '2pt', 'padBottom' => '1.5pt', 'entGap' => '0.8pt', 'lineHeight' => '1.20',
            ],
            'extra-compact' => [
                // Tight enough to genuinely buy a line rather than merely feel
                // different: at 1.13 leading this bought nothing over compact,
                // which is a setting that costs air and returns nothing. The
                // type size itself never changes — only the space around it.
                'cellPad' => 0.030, 'numHeight' => 0.142, 'entHeight' => 0.122, 'moreHeight' => 0.114,
                'padTop' => '1.2pt', 'padBottom' => '0.8pt', 'entGap' => '0.2pt', 'lineHeight' => '1.10',
            ],
            // Standard: the numbers the current sheet actually measures.
            default => [
                'cellPad' => 0.069, 'numHeight' => 0.181, 'entHeight' => 0.149, 'moreHeight' => 0.134,
                'padTop' => '3pt', 'padBottom' => '2pt', 'entGap' => '1.4pt', 'lineHeight' => '1.26',
            ],
        };
    }

    /**
     * How many entries one cell of this height can show.
     *
     * The "+n more" line is reserved in advance because it appears exactly when
     * the day is full — which is when there is no room left for it. Two is the
     * floor: a cell showing one entry and "+6 more" tells nobody anything.
     */
    public static function perDay(string $mode, float $rowHeightIn, float $typeScale = 1.0): int
    {
        $m = self::metrics($mode);
        $content = $rowHeightIn - ($m['cellPad'] * $typeScale);
        $usable = $content - ($m['numHeight'] * $typeScale) - ($m['moreHeight'] * $typeScale);

        return max(2, (int) floor($usable / ($m['entHeight'] * $typeScale)));
    }

    /**
     * Resolve a chosen mode against the content, for Auto.
     *
     * @param list<int> $entriesPerDay how many entries each day actually holds
     * @return array{mode:string,perDay:int,hidden:int} hidden is how many
     *         entries are still behind "+n more" at the chosen mode
     */
    public static function resolve(string $chosen, array $entriesPerDay, float $rowHeightIn, float $typeScale = 1.0): array
    {
        $busiest = $entriesPerDay === [] ? 0 : max($entriesPerDay);

        $measure = static function (string $mode) use ($entriesPerDay, $rowHeightIn, $typeScale): array {
            $perDay = self::perDay($mode, $rowHeightIn, $typeScale);
            $hidden = 0;
            foreach ($entriesPerDay as $n) {
                $hidden += max(0, $n - $perDay);
            }

            return ['mode' => $mode, 'perDay' => $perDay, 'hidden' => $hidden];
        };

        if ($chosen !== 'auto') {
            $mode = in_array($chosen, self::LADDER, true) ? $chosen : 'standard';

            return $measure($mode);
        }

        // Loosest first: stop at the first that hides nothing.
        foreach (self::LADDER as $mode) {
            $result = $measure($mode);
            if ($result['hidden'] === 0 || $busiest <= $result['perDay']) {
                return $result;
            }
        }

        // Even the tightest cannot show everything. Return it, and let the
        // caller say so — compressing past this stops being readable, which
        // defeats the point of the sheet.
        $ladder = self::LADDER;

        return $measure((string) end($ladder));
    }

    /** The CSS custom properties a template sets from a mode. */
    public static function cssVars(string $mode): string
    {
        $m = self::metrics($mode);

        return '--pad-t:' . $m['padTop']
            . ';--pad-b:' . $m['padBottom']
            . ';--ent-gap:' . $m['entGap']
            . ';--ent-lh:' . $m['lineHeight'];
    }
}
