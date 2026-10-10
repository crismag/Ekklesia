<?php

declare(strict_types=1);

namespace App\Services\Events;

use DateTimeImmutable;

/**
 * The schedule an event repeats on, as a stored rule rather than as its output.
 *
 * The portal materialises occurrences into event_occurrences and, until now,
 * discarded the pattern that produced them: the rule table held two rows
 * against four hundred occurrences. Nothing could say "every Sunday until
 * October" because nothing kept it. That is why "Generate occurrences" had to
 * be exposed to ordinary administrators — the application had no schedule to
 * regenerate from, only rows it had already written.
 *
 * This class is the translation in both directions and nothing else. It holds
 * no database handle so it can be tested on its own, which matters: a wrong
 * answer here silently puts the wrong dates on the church calendar.
 *
 * Only patterns the events repeat columns can actually express are offered. Its
 * repeat_frequency enum is daily/weekly/biweekly/monthly/yearly with a
 * days-of-week string, and there is no week-of-month or BYSETPOS equivalent, so
 * "First Sunday of every month" is deliberately absent rather than present and
 * quietly doing something else.
 */
final class RecurrenceRule
{
    /** What the editor offers, in the order it offers it. */
    public const PRESETS = [
        'one_off'  => "Doesn't repeat",
        'weekly'   => 'Every week',
        'biweekly' => 'Every 2 weeks',
        'monthly'  => 'Every month, on the same date',
        'monthly_nth' => 'Every month, on the same weekday',
        'selected' => 'Selected dates…',
    ];

    /** Presets that expand from a single start date by a fixed step. */
    private const STEPPED = ['weekly', 'biweekly', 'monthly', 'monthly_nth'];

    private const ORDINALS = [1 => 'First', 2 => 'Second', 3 => 'Third', 4 => 'Fourth', -1 => 'Last'];

    private const DAY_NAMES = [
        'SU' => 'Sunday', 'MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday',
        'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday',
    ];

    /**
     * A preset as the repeat columns an event stores, or null for a schedule that
     * is not a rule at all.
     *
     * One-off and selected-dates return null on purpose. A list of chosen dates
     * is not a repetition, and writing a rule that cannot reproduce it would be
     * worse than writing nothing: the next reader would trust it.
     *
     * @return array{repeat_frequency:string,repeat_interval:int,repeat_weekdays:?string,repeat_until:?string,repeat_count:?int}|null
     */
    public static function toStorage(
        string $pattern,
        ?string $startDate = null,
        ?string $untilOn = null,
        ?int $count = null,
    ): ?array {
        if (!in_array($pattern, self::STEPPED, true)) {
            return null;
        }

        // Both monthly presets store repeat_frequency 'monthly'; the
        // week-of-month column is what separates "the 6th of every month" from
        // "the first Sunday of every month".
        $nth = $pattern === 'monthly_nth';
        $byDate = $pattern === 'monthly';

        return [
            // biweekly is its own enum member here rather than weekly with an
            // interval of 2. The column offers both spellings; using the one
            // the enum names keeps a reader from having to check the interval
            // to know what kind of schedule this is.
            'repeat_frequency' => $nth ? 'monthly' : $pattern,
            'repeat_interval' => 1,
            'repeat_weekdays' => $byDate ? null : self::dayCodeFor($startDate),
            'repeat_week_of_month' => $nth ? self::weekOfMonthFor($startDate) : null,
            'repeat_until' => self::normaliseDate($untilOn),
            'repeat_count' => $count !== null && $count > 0 ? $count : null,
        ];
    }

    /**
     * The rule as a sentence an administrator would say out loud.
     *
     * "Every Sunday · 7:30 – 9:00 AM · until 11 October", never
     * "MONTHLY/1/SU". The stored representation is an implementation detail and
     * the brief is explicit that it stays one.
     *
     * @param array<string,mixed>|null $stored an event's repeat columns
     */
    public static function describe(
        ?array $stored,
        ?string $startTime = null,
        ?string $endTime = null,
        bool $allDay = false,
    ): string {
        $parts = [];

        $type = $stored === null ? null : (string) ($stored['repeat_frequency'] ?? '');
        if ($type === null || $type === '') {
            $parts[] = 'Does not repeat';
        } else {
            $day = self::dayName((string) ($stored['repeat_weekdays'] ?? ''));
            $week = isset($stored['repeat_week_of_month']) && $stored['repeat_week_of_month'] !== null
                ? (int) $stored['repeat_week_of_month']
                : null;
            $parts[] = match ($type) {
                'weekly' => $day !== null ? 'Every ' . $day : 'Every week',
                'biweekly' => $day !== null ? 'Every other ' . $day : 'Every 2 weeks',
                'monthly' => $week !== null && $day !== null && isset(self::ORDINALS[$week])
                    // "First Sunday of every month", never "MONTHLY/1/SU/1".
                    ? self::ORDINALS[$week] . ' ' . $day . ' of every month'
                    : 'Every month',
                'yearly' => 'Every year',
                'daily' => 'Every day',
                default => 'Repeats',
            };
        }

        $when = self::describeTime($startTime, $endTime, $allDay);
        if ($when !== null) {
            $parts[] = $when;
        }

        $until = self::normaliseDate($stored['repeat_until'] ?? null);
        $count = isset($stored['repeat_count']) ? (int) $stored['repeat_count'] : 0;
        if ($until !== null) {
            $parts[] = 'until ' . (new DateTimeImmutable($until))->format('j F Y');
        } elseif ($count > 0) {
            $parts[] = $count . ' time' . ($count === 1 ? '' : 's');
        }

        return implode(' · ', $parts);
    }

    /** "7:30 – 9:00 AM", "7:30 AM", "All day", or null when no time is set. */
    public static function describeTime(?string $startTime, ?string $endTime, bool $allDay = false): ?string
    {
        if ($allDay) {
            return 'All day';
        }
        $start = self::clock($startTime);
        if ($start === null) {
            return null;
        }
        $end = self::clock($endTime);
        if ($end === null || $end === $start) {
            return $start;
        }
        // "7:30 – 9:00 AM" rather than "7:30 AM – 9:00 AM". Saying the meridiem
        // twice when both ends share it is noise, and these ranges are read in
        // a dense list where every repeated word costs a column.
        $startMeridiem = substr($start, -2);
        if ($startMeridiem === substr($end, -2)) {
            $start = rtrim(substr($start, 0, -2));
        }

        return $start . ' – ' . $end;
    }

    /**
     * The cadence a set of dates actually follows, when no rule was stored.
     *
     * Every event created before the rule was persisted has occurrences and no
     * schedule. Describing those as "Does not repeat" is worse than saying
     * nothing: a service with fifty-two Fridays plainly repeats, and the page
     * would be contradicting what it lists directly underneath.
     *
     * This claims nothing about intent. It reports the interval the dates are
     * actually spaced at, and only when they are spaced evenly.
     *
     * @param list<string> $starts occurrence start datetimes, ascending
     * @return array<string,mixed>|null a rule-shaped array, or null if the
     *         dates follow no even cadence
     */
    public static function observe(array $starts): ?array
    {
        $days = [];
        foreach ($starts as $raw) {
            $date = self::normaliseDate($raw);
            if ($date !== null) {
                $days[$date] = true;
            }
        }
        $days = array_keys($days);
        sort($days);
        if (count($days) < 3) {
            return null;
        }

        $gaps = [];
        for ($i = 1, $n = count($days); $i < $n; $i++) {
            $gaps[] = (int) (new DateTimeImmutable($days[$i - 1]))
                ->diff(new DateTimeImmutable($days[$i]))->days;
        }
        if (count(array_unique($gaps)) !== 1) {
            return null;
        }

        $type = match ($gaps[0]) {
            1 => 'daily',
            7 => 'weekly',
            14 => 'biweekly',
            default => null,
        };
        if ($type === null) {
            return null;
        }

        return [
            'repeat_frequency' => $type,
            'repeat_interval' => 1,
            'repeat_weekdays' => $type === 'daily' ? null : self::dayCodeFor($days[0]),
            'repeat_until' => end($days),
            'repeat_count' => null,
        ];
    }

    /**
     * Which occurrence of its own weekday a date is within its month.
     *
     * The 6th of September 2026 is the first Sunday; the 27th is the fourth and
     * also the last. Last wins when a date is both, because "last Sunday" is
     * the thing somebody who picked the 27th of a five-Sunday month meant, and
     * it keeps meaning it in months that have four.
     */
    private static function weekOfMonthFor(?string $ymd): ?int
    {
        $date = self::normaliseDate($ymd);
        if ($date === null) {
            return null;
        }
        $d = new DateTimeImmutable($date);
        $dayOfMonth = (int) $d->format('j');
        $nth = (int) ceil($dayOfMonth / 7);

        return $dayOfMonth + 7 > (int) $d->format('t') ? -1 : $nth;
    }

    /** The two-letter code repeat_weekdays stores for a date's weekday. */
    private static function dayCodeFor(?string $ymd): ?string
    {
        $date = self::normaliseDate($ymd);
        if ($date === null) {
            return null;
        }

        return strtoupper(substr((new DateTimeImmutable($date))->format('D'), 0, 2));
    }

    private static function dayName(string $codes): ?string
    {
        $first = strtoupper(trim(explode(',', $codes)[0] ?? ''));

        return self::DAY_NAMES[$first] ?? null;
    }

    private static function normaliseDate(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($raw, 0, 10));

        return $date === false ? null : $date->format('Y-m-d');
    }

    /** "17:30" as "5:30 PM"; anything unparseable as null rather than as noise. */
    private static function clock(?string $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('H:i', substr($raw, 0, 5));

        return $time === false ? null : ltrim($time->format('g:i A'), '0');
    }
}
