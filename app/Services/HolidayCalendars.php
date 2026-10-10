<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/**
 * Public holiday calendars, read from a cached list.
 *
 * More than one, because a congregation keeps more than one country's holidays
 * in view. Each set is a layer viewers can switch off, the same as birthdays or
 * rosters.
 *
 * Fetched rather than computed, and cached rather than fetched at render time.
 *
 * Computing is tempting because the rules look simple — some fixed dates, some
 * "third Monday of", Easter and its neighbours. They are not. Deriving
 * Ontario's list from first principles produced Easter Monday and Remembrance
 * Day, and neither is a statutory holiday here: one is for federal employees,
 * the other is observed in nine other provinces. Nor do the rules hold still —
 * National Day for Truth and Reconciliation did not exist before 2021.
 *
 * Fetching at render time would put a third party's uptime in front of a page
 * people open constantly. A holiday list a year old is still a correct holiday
 * list, so tools/fetch-holidays.php refreshes it and the result is committed,
 * exactly like the postal areas.
 *
 * The one thing a cache owes anyone is to admit when it has run out, which is
 * what exhausted() reports: a calendar showing no holidays looks identical
 * whether the year is quiet or the list simply stops.
 */
final class HolidayCalendars
{
    /** @var list<array{id:string,label:string,color:string,enabled:bool,region:?string,fetched_on:string,holidays:list<array{date:string,name:string,national:bool}>}> */
    private array $calendars;

    /** @param list<array<string,mixed>> $calendars */
    public function __construct(array $calendars = [])
    {
        $clean = [];
        foreach ($calendars as $calendar) {
            $id = strtolower(trim((string) ($calendar['id'] ?? '')));
            $label = trim((string) ($calendar['label'] ?? ''));
            if ($id === '' || $label === '') {
                continue;
            }
            $holidays = [];
            foreach ((array) ($calendar['holidays'] ?? []) as $holiday) {
                $date = trim((string) ($holiday['date'] ?? ''));
                $name = trim((string) ($holiday['name'] ?? ''));
                if ($name === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    continue;
                }
                $holidays[] = ['date' => $date, 'name' => $name, 'national' => (bool) ($holiday['national'] ?? false)];
            }
            usort($holidays, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

            $clean[] = [
                'id' => $id,
                'label' => $label,
                'color' => (string) ($calendar['color'] ?? '#8a4b12'),
                'enabled' => (bool) ($calendar['enabled'] ?? true),
                'region' => isset($calendar['region']) && $calendar['region'] !== null
                    ? (string) $calendar['region'] : null,
                'fetched_on' => (string) ($calendar['fetched_on'] ?? ''),
                'holidays' => $holidays,
            ];
        }
        $this->calendars = $clean;
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return new self();
        }

        return new self(is_array($decoded['calendars'] ?? null) ? $decoded['calendars'] : []);
    }

    /**
     * Layer chips, one per enabled calendar.
     *
     * A calendar switched off produces no chip. Showing a chip for something
     * the feed will never contain teaches people the filter is broken.
     *
     * @return list<array{source:string,label:string,color:string,kind:string,group:string}>
     */
    public function layers(): array
    {
        $out = [];
        foreach ($this->enabled() as $calendar) {
            $out[] = [
                'source' => 'holidays:' . $calendar['id'],
                'label' => $calendar['label'],
                'color' => $calendar['color'],
                'kind' => 'holiday',
                'group' => 'holidays',
            ];
        }

        return $out;
    }

    /**
     * Calendar items for the window, from every enabled calendar.
     *
     * @return list<array{kind:string,source:string,source_label:string,title:string,meta:string,href:null,date:string}>
     */
    public function itemsBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $from = $start->format('Y-m-d');
        $to = $end->format('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $items = [];
        foreach ($this->enabled() as $calendar) {
            foreach ($calendar['holidays'] as $holiday) {
                if ($holiday['date'] < $from || $holiday['date'] > $to) {
                    continue;
                }
                $items[] = [
                    'kind' => 'holiday',
                    'source' => 'holidays:' . $calendar['id'],
                    'source_label' => $calendar['label'],
                    'title' => $holiday['name'],
                    // Which country's holiday it is, since several may show at
                    // once and the names do not always say.
                    'meta' => $calendar['label'],
                    'href' => null,
                    'date' => $holiday['date'],
                ];
            }
        }

        return $items;
    }

    /**
     * Calendars whose cache no longer reaches the date given.
     *
     * @return list<array{id:string,label:string,through:?string}>
     */
    public function exhausted(DateTimeImmutable $when): array
    {
        $limit = $when->format('Y-m-d');
        $out = [];
        foreach ($this->enabled() as $calendar) {
            $last = end($calendar['holidays']);
            $through = $last === false ? null : $last['date'];
            if ($through === null || $through < $limit) {
                $out[] = ['id' => $calendar['id'], 'label' => $calendar['label'], 'through' => $through];
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->calendars;
    }

    /** @return list<array<string,mixed>> */
    public function enabled(): array
    {
        return array_values(array_filter($this->calendars, static fn (array $c): bool => $c['enabled']));
    }
}
