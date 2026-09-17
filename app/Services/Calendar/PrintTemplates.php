<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use DateTimeImmutable;

/**
 * The printable layouts, and what each is for.
 *
 * A registry rather than a switch, so adding a seasonal or ministry-specific
 * layout is a file and a row here — the renderer never learns their names.
 *
 * Every template is handed the same view model. None of them knows where an
 * entry came from, which is the whole point: a Sunday schedule and a wall
 * calendar are two drawings of one set of facts.
 */
final class PrintTemplates
{
    /**
     * @return array<string,array{
     *   label:string, blurb:string, file:string,
     *   orientation:string, wants:string, needs_times:bool
     * }>
     */
    public static function all(): array
    {
        return [
            'monthly' => [
                'label' => 'Monthly calendar',
                'blurb' => 'A wall calendar. One grid to a month, for the notice board.',
                'file' => 'monthly.php',
                'orientation' => 'landscape',
                'wants' => 'month',
                'needs_times' => false,
            ],
            'weekly' => [
                'label' => 'Weekly calendar',
                'blurb' => 'The same grid at a week to a row, so a day has room for what is actually on it.',
                'file' => 'weekly.php',
                'orientation' => 'landscape',
                'wants' => 'range',
                'needs_times' => true,
            ],
            'agenda' => [
                'label' => 'Agenda',
                'blurb' => 'Date by date, in order. Reads faster than a grid and fits more on a page.',
                'file' => 'agenda.php',
                'orientation' => 'portrait',
                'wants' => 'range',
                'needs_times' => true,
            ],
            'sunday' => [
                'label' => 'Sunday schedule',
                'blurb' => 'A working sheet for the people running the service: what, when, who is on, and room to write.',
                'file' => 'sunday.php',
                'orientation' => 'portrait',
                'wants' => 'range',
                'needs_times' => true,
            ],
            'annual' => [
                'label' => 'Year at a glance',
                'blurb' => 'Twelve months on one sheet, for planning. Series collapse to a line; birthdays are left out.',
                'file' => 'annual.php',
                'orientation' => 'landscape',
                'wants' => 'year',
                'needs_times' => false,
            ],
            'planner' => [
                'label' => 'Ministry planner',
                'blurb' => 'A table for leaders: date, what, when, where. Made to be marked up.',
                'file' => 'planner.php',
                'orientation' => 'portrait',
                'wants' => 'range',
                'needs_times' => true,
            ],
        ];
    }

    /**
     * The range a template actually needs, given the one that was asked for.
     *
     * This has to be decided before the feed is fetched, not while the document
     * is being composed: the year sheet widens its window to twelve months, and
     * widening it after the query returned means nine of those months are drawn
     * empty from data nobody asked the database for. The composer and the route
     * both call this so there is one answer.
     *
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
     */
    public static function rangeFor(string $id, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $wants = self::get($id)['wants'];

        if ($wants === 'month' || $wants === 'year') {
            // Half a grid is not a calendar.
            $start = $start->modify('first day of this month');
            $end = $end->modify('last day of this month');
        }
        if ($wants === 'year') {
            // "A year at a glance" showing four months is not the document
            // anybody meant to print.
            $twelve = $start->modify('+11 months')->modify('last day of this month');
            if ($end < $twelve) {
                $end = $twelve;
            }
        }

        return [$start, $end];
    }

    /** @return array{label:string,blurb:string,file:string,orientation:string,wants:string,needs_times:bool} */
    public static function get(string $id): array
    {
        $all = self::all();

        return $all[$id] ?? $all['monthly'];
    }

    public static function exists(string $id): bool
    {
        return isset(self::all()[$id]);
    }
}
