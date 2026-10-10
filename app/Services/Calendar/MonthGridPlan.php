<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * The monthly grid, decided once: how big it is, how tightly it is set, and
 * for every day which entries are printed, at what type tier, and how many
 * are left behind "+n more".
 *
 * The printed page (print/monthly.php) and the editable PowerPoint export
 * (PptxCalendarWriter) both draw from this plan, so a calendar cannot show
 * one set of names on paper and another in PowerPoint.
 */
final class MonthGridPlan
{
    /**
     * The plan for a composed page (PrintComposer::context()), so the page and
     * the export plan from exactly the same inputs.
     *
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    public static function fromContext(array $ctx): array
    {
        return self::build($ctx['model'], [
            'paperWidthIn' => (float) ($ctx['paperWidthIn'] ?? 11.0),
            'paperHeightIn' => (float) ($ctx['paperHeightIn'] ?? 8.5),
            'mastheadIn' => (float) ($ctx['mastheadIn'] ?? 0.66),
            'legend' => ($ctx['legendRows'] ?? []) !== [],
            'pageHeight' => (string) ($ctx['pageHeight'] ?? 'fit'),
            'themeChromeIn' => (float) ($ctx['themeChromeIn'] ?? 0.0),
            'safeZone' => is_array($ctx['safeZone'] ?? null) ? $ctx['safeZone'] : null,
            'gridBox' => is_array($ctx['gridBox'] ?? null) ? $ctx['gridBox'] : null,
            'legendInRegion' => isset($ctx['pptx']['model']['regions']['LEGEND']),
            'density' => (string) ($ctx['density'] ?? 'standard'),
            'typeScale' => (float) ($ctx['typeScale'] ?? 1.0),
            'themeCellPadIn' => (float) ($ctx['themeCellPadIn'] ?? 0.0),
            'entryDisplay' => (string) ($ctx['entryDisplay'] ?? 'auto'),
            'shortNames' => ($ctx['nameStyle'] ?? 'full') === 'short',
        ]);
    }

    /**
     * @param array<string,mixed> $model CalendarViewModel output
     * @param array{
     *   paperWidthIn:float, paperHeightIn:float, mastheadIn?:float, legend?:bool,
     *   pageHeight?:string, themeChromeIn?:float, safeZone?:array<string,float>,
     *   gridBox?:?array<string,float>, legendInRegion?:bool, density?:string,
     *   typeScale?:float, themeCellPadIn?:float, entryDisplay?:string, shortNames?:bool
     * } $o
     * @return array<string,mixed>
     */
    public static function build(array $model, array $o): array
    {
        $ts = (float) ($o['typeScale'] ?? 1.0);
        $entryMode = (string) ($o['entryDisplay'] ?? 'auto');
        $shortNames = (bool) ($o['shortNames'] ?? false);
        $grow = ($o['pageHeight'] ?? 'fit') === 'grow';
        $paperHeightIn = (float) $o['paperHeightIn'];
        $paperWidthIn = (float) $o['paperWidthIn'];

        // Page margin (0.945in), the screen sheet's padding (1.102in, kept as
        // slack), the masthead, the weekday row and a little rounding slack.
        $gridHeightIn = max(3.0, $paperHeightIn - 0.945 - 1.102 - (float) ($o['mastheadIn'] ?? 0.66) - 0.222 - 0.05);
        if (!empty($o['legend'])) {
            $gridHeightIn -= 0.24;
        }
        $gridHeightIn = max(3.0, $gridHeightIn - (float) ($o['themeChromeIn'] ?? 0.0));
        $safe = is_array($o['safeZone'] ?? null) ? $o['safeZone'] : ['top' => 0.0, 'bottom' => 0.0, 'left' => 0.0, 'right' => 0.0];
        $gridHeightIn = max(3.0, $gridHeightIn - (float) $safe['top'] - (float) $safe['bottom']);
        $gridWidthIn = max(3.0, $paperWidthIn - 0.945 - 1.102 - (float) $safe['left'] - (float) $safe['right']);
        // A PowerPoint theme gives the grid its region instead.
        if (is_array($o['gridBox'] ?? null)) {
            $inRegionKey = !empty($o['legend']) && empty($o['legendInRegion']) ? 0.24 : 0.0;
            $gridHeightIn = max(2.0, (float) $o['gridBox']['h'] - 0.222 - $inRegionKey);
            $gridWidthIn = max(3.0, (float) $o['gridBox']['w']);
        }

        $rowCount = 1;
        foreach ($model['months'] as $m) {
            $rowCount = max($rowCount, count($m['weeks']));
        }
        $rowHeightIn = $gridHeightIn / $rowCount;
        // The text column inside one day: less the cell's padding and the
        // entry's rule and indent.
        $cellTextWidthIn = max(0.5, ($gridWidthIn / 7) - (11.0 / 72.0));

        $busiest = [];
        foreach ($model['months'] as $m) {
            foreach ($m['weeks'] as $week) {
                foreach ($week as $day) {
                    $busiest[] = count($day['entries']);
                }
            }
        }
        $resolved = PrintDensity::resolve((string) ($o['density'] ?? 'standard'), $busiest, $rowHeightIn, $ts);
        $densityMode = $resolved['mode'];
        $dm = PrintDensity::metrics($densityMode);
        $usableIn = max(0.1, $rowHeightIn - (($dm['cellPad'] + (float) ($o['themeCellPadIn'] ?? 0.0)) * $ts) - ($dm['numHeight'] * $ts));
        $legacy = $entryMode === 'compact';

        $hidden = 0;
        $months = [];
        foreach ($model['months'] as $month) {
            $weeks = [];
            foreach ($month['weeks'] as $week) {
                $days = [];
                foreach ($week as $day) {
                    $items = [];
                    foreach ($day['entries'] as $entry) {
                        $items[] = EntryPresentation::of($entry, $shortNames, !$legacy, !$legacy)
                            + ['source' => $entry['source'], 'href' => (string) ($entry['href'] ?? '')];
                    }
                    // Growing, a day may take a quarter as much height again
                    // before its type tightens.
                    $plan = CellPlan::plan(
                        $items, $entryMode, $grow ? $usableIn * 1.25 : $usableIn, $cellTextWidthIn, $ts, $dm['moreHeight'],
                        $legacy && !$grow ? PrintDensity::perDay($densityMode, $rowHeightIn, $ts) : null,
                    );
                    if ($grow) {
                        $plan['shown'] = count($items);
                        $plan['hidden'] = 0;
                        $plan['wrap'] = true;
                        if ($plan['state'] === 'overflow') {
                            $plan['state'] = 'dense';
                        }
                    }
                    $hidden += $plan['hidden'];
                    $days[] = ['day' => $day, 'items' => $items, 'plan' => $plan];
                }
                $weeks[] = $days;
            }
            $months[] = ['month' => $month, 'weeks' => $weeks];
        }

        return [
            'grow' => $grow,
            'legacy' => $legacy,
            'typeScale' => $ts,
            'gridHeightIn' => $gridHeightIn,
            'gridWidthIn' => $gridWidthIn,
            'rowHeightIn' => $rowHeightIn,
            'cellTextWidthIn' => $cellTextWidthIn,
            'densityMode' => $densityMode,
            'hidden' => $hidden,
            'months' => $months,
        ];
    }
}
