<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What a calendar shows, worked out once and handed to whatever draws it.
 *
 * The interactive page used to do this in the browser: take the raw feed,
 * filter it, order it, group it into days and weeks, decide the overflow. That
 * left printing only one option — CSS over the same DOM — which is the
 * arrangement that makes printed calendars look like screenshots.
 *
 * So the grouping moved here. The same entries can now be drawn as a month
 * grid, an agenda, a ministry planner or a Sunday schedule, on screen or on
 * paper, without any of those renderers knowing where an entry came from.
 *
 * Pure: it is given items and returns a shape. No database, no request, no
 * clock beyond the "today" it is told about — so a print job for last August
 * renders the same in December as it did in August.
 */
final class CalendarViewModel
{
    public function __construct(
        private readonly DateTimeZone $timezone = new DateTimeZone('America/Toronto'),
    ) {
    }

    /**
     * @param list<array<string,mixed>> $items raw feed items
     * @param array{
     *   sources?:list<string>, campus?:?string, today?:?string,
     *   weekStartsOn?:int, includeEmptyDays?:bool
     * } $options
     * @return array{
     *   start:string, end:string, title:string,
     *   entries:list<array<string,mixed>>,
     *   days:list<array<string,mixed>>,
     *   weeks:list<list<array<string,mixed>>>,
     *   months:list<array<string,mixed>>,
     *   counts:array<string,int>
     * }
     */
    public function build(
        array $items,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $options = [],
    ): array {
        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $only = $options['sources'] ?? null;
        $today = isset($options['today']) ? (string) $options['today'] : null;
        $weekStart = (int) ($options['weekStartsOn'] ?? 0);   // 0 = Sunday
        $includeEmpty = (bool) ($options['includeEmptyDays'] ?? true);

        $entries = [];
        foreach ($items as $item) {
            $entry = $this->normalise($item);
            if ($entry === null) {
                continue;
            }
            if ($entry['date'] < $start->format('Y-m-d') || $entry['date'] > $end->format('Y-m-d')) {
                continue;
            }
            if ($only !== null && !in_array($entry['source'], $only, true)) {
                continue;
            }
            $entries[] = $entry;
        }

        $entries = $this->deduplicate($entries);
        usort($entries, $this->chronologically(...));

        $byDate = [];
        foreach ($entries as $entry) {
            $byDate[$entry['date']][] = $entry;
        }

        $days = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $dayEntries = $byDate[$date] ?? [];
            if ($includeEmpty || $dayEntries !== []) {
                $days[] = $this->day($cursor, $dayEntries, $today);
            }
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'title' => $this->rangeTitle($start, $end),
            'entries' => $entries,
            'days' => $days,
            'weeks' => $this->weeks($start, $end, $byDate, $today, $weekStart),
            'months' => $this->months($start, $end, $byDate, $today, $weekStart),
            'counts' => $this->counts($entries),
        ];
    }

    /**
     * One feed item in the shape every renderer can use.
     *
     * The feed carries a date, and sometimes a precise start. Both are kept:
     * a month grid wants the day, an agenda wants the time, and neither should
     * have to parse the other's field.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private function normalise(array $item): ?array
    {
        $date = (string) ($item['date'] ?? '');
        $startsAt = isset($item['starts_at']) && $item['starts_at'] !== null
            ? (string) $item['starts_at'] : null;

        if ($date === '' && $startsAt !== null) {
            $date = substr($startsAt, 0, 10);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $time = null;
        $endTime = null;
        if ($startsAt !== null && preg_match('/\d{2}:\d{2}/', $startsAt, $m) === 1) {
            $time = $m[0];
        }
        if (isset($item['ends_at']) && $item['ends_at'] !== null
            && preg_match('/\d{2}:\d{2}/', (string) $item['ends_at'], $m) === 1) {
            $endTime = $m[0];
        }
        // Midnight to midnight is how an all-day event is stored, not a
        // one-minute event at the stroke of twelve.
        if ($time === '00:00' && ($endTime === null || $endTime === '00:00')) {
            $time = null;
            $endTime = null;
        }

        return [
            'date' => $date,
            'time' => $time,
            'end_time' => $endTime,
            'all_day' => $time === null,
            'title' => $title,
            'meta' => trim((string) ($item['meta'] ?? '')),
            'kind' => (string) ($item['kind'] ?? 'custom'),
            'source' => (string) ($item['source'] ?? 'custom'),
            'source_label' => (string) ($item['source_label'] ?? ''),
            'href' => $item['href'] ?? null,
            'campus' => trim((string) ($item['campus'] ?? '')),
            'ministry' => trim((string) ($item['ministry'] ?? '')),
            'location' => trim((string) ($item['location'] ?? '')),
        ];
    }

    /**
     * Drop the same thing arriving twice.
     *
     * Loaders overlap by design — an event can reach the feed as an event and
     * again as a roster slot — and a printed calendar listing a service twice
     * is worse than one that misses it.
     *
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>>
     */
    private function deduplicate(array $entries): array
    {
        $seen = [];
        $out = [];
        foreach ($entries as $entry) {
            $key = $entry['date'] . '|' . strtolower($entry['title']) . '|' . $entry['source'] . '|' . ($entry['time'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $entry;
        }

        return $out;
    }

    /** All-day first, then by time, then by title — so a day reads top to bottom. */
    private function chronologically(array $a, array $b): int
    {
        return [$a['date'], $a['time'] ?? '', strtolower($a['title'])]
            <=> [$b['date'], $b['time'] ?? '', strtolower($b['title'])];
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    private function day(DateTimeImmutable $date, array $entries, ?string $today): array
    {
        $weekday = (int) $date->format('w');

        return [
            'date' => $date->format('Y-m-d'),
            'day' => (int) $date->format('j'),
            'month' => (int) $date->format('n'),
            'year' => (int) $date->format('Y'),
            'weekday' => $weekday,
            'weekday_short' => $date->format('D'),
            'weekday_long' => $date->format('l'),
            'month_short' => $date->format('M'),
            'label' => $date->format('D j M'),
            'is_today' => $today !== null && $date->format('Y-m-d') === $today,
            'is_weekend' => $weekday === 0 || $weekday === 6,
            'entries' => $entries,
        ];
    }

    /**
     * Whole weeks covering the range, padded out at both ends.
     *
     * A month grid needs the leading and trailing days of neighbouring months
     * to fill its first and last rows; they are marked so a renderer can grey
     * them.
     *
     * @param array<string,list<array<string,mixed>>> $byDate
     * @return list<list<array<string,mixed>>>
     */
    private function weeks(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $byDate,
        ?string $today,
        int $weekStart,
    ): array {
        $gridStart = $start;
        while ((int) $gridStart->format('w') !== $weekStart) {
            $gridStart = $gridStart->modify('-1 day');
        }
        $gridEnd = $end;
        while ((int) $gridEnd->format('w') !== ($weekStart + 6) % 7) {
            $gridEnd = $gridEnd->modify('+1 day');
        }

        $weeks = [];
        $week = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $day = $this->day($cursor, $byDate[$cursor->format('Y-m-d')] ?? [], $today);
            $day['in_range'] = $cursor >= $start && $cursor <= $end;
            $week[] = $day;
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
            $cursor = $cursor->modify('+1 day');
        }
        if ($week !== []) {
            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * The range split into calendar months, each with its own week grid.
     *
     * An annual planner is twelve of these; a monthly calendar is one. Both
     * renderers then look the same.
     *
     * @param array<string,list<array<string,mixed>>> $byDate
     * @return list<array<string,mixed>>
     */
    private function months(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $byDate,
        ?string $today,
        int $weekStart,
    ): array {
        $months = [];
        $cursor = $start->modify('first day of this month');
        $last = $end->modify('first day of this month');

        while ($cursor <= $last) {
            $monthStart = $cursor;
            $monthEnd = $cursor->modify('last day of this month');
            $window = [];
            foreach ($byDate as $date => $entries) {
                if ($date >= $monthStart->format('Y-m-d') && $date <= $monthEnd->format('Y-m-d')) {
                    $window[$date] = $entries;
                }
            }
            $months[] = [
                'year' => (int) $cursor->format('Y'),
                'month' => (int) $cursor->format('n'),
                'name' => $cursor->format('F'),
                'name_short' => $cursor->format('M'),
                'title' => $cursor->format('F Y'),
                'start' => $monthStart->format('Y-m-d'),
                'end' => $monthEnd->format('Y-m-d'),
                'weeks' => $this->weeks($monthStart, $monthEnd, $window, $today, $weekStart),
                'entries' => array_merge(...array_values($window ?: [[]])),
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /** How a range names itself, without repeating what does not change. */
    private function rangeTitle(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        if ($start->format('Y-m') === $end->format('Y-m')) {
            return $start->format('F Y');
        }
        if ($start->format('Y') === $end->format('Y')) {
            return $start->format('F') . ' – ' . $end->format('F Y');
        }

        return $start->format('M Y') . ' – ' . $end->format('M Y');
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string,int>
     */
    private function counts(array $entries): array
    {
        $counts = ['total' => count($entries)];
        foreach ($entries as $entry) {
            $counts[$entry['source']] = ($counts[$entry['source']] ?? 0) + 1;
        }

        return $counts;
    }
}
