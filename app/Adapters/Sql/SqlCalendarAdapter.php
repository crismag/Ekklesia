<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\CalendarAdapter;
use DateTimeImmutable;
use PDO;

final class SqlCalendarAdapter implements CalendarAdapter
{
    public function __construct(
        private readonly ?PDO $connection = null,
    ) {
    }

    public function listSystemItems(DateTimeImmutable $start, DateTimeImmutable $end, ?int $campusId = null, array $audiences = []): array
    {
        if ($this->connection === null) {
            return [];
        }

        // A range is given as two dates and means whole days, inclusive of both.
        // It did not, and the three sources disagreed about it in three
        // different ways: the SQL treated the end as an exclusive instant at
        // midnight, so a window ending on the 30th lost every service on the
        // 30th; birthdays compared against a time taken from the system clock,
        // so they vanished from the last day at any hour except midnight; the
        // holiday file was inclusive and correct. Printing "August 1 to
        // August 31" silently dropped the 31st, which is how an end-of-month
        // birthday could be on the calendar and missing from the printout.
        //
        // Normalised once, here, so every source below reads the same window.
        $start = $start->setTime(0, 0, 0);
        // Half-open at the far end: everything up to but not including the
        // following midnight is "that day", with no dependence on how many
        // seconds a datetime happens to carry.
        $end = $end->setTime(0, 0, 0)->modify('+1 day');

        // Only events carry an audience. Birthdays and anniversaries come from
        // people and households, are already governed by campus scope, and
        // have no type to classify.
        $items = array_merge(
            $this->eventOccurrenceItems($start, $end, $campusId, $audiences),
            $this->birthdayItems($start, $end, $campusId),
            $this->anniversaryItems($start, $end, $campusId),
        );

        usort($items, static function (array $left, array $right): int {
            return strcmp($left['date'], $right['date']) ?: strcmp($left['title'], $right['title']);
        });

        return $items;
    }

    /**
     * Reads every event_occurrences row in the window, joining to events for
     * the title/url and the "this is a recurring series" badge. Honors
     * per-occurrence overrides:
     *   - cancelled rows are shown, marked cancelled. Hiding them was the
     *     old behaviour and it erased the announcement: a Sunday with no
     *     service is not the same as a Sunday that was never scheduled, and
     *     somebody turns up to find out which. Nothing was ever cancelled
     *     under the old code, so this surfaces no history — it makes the
     *     cancel action mean something.
     *   - title_override and details_override take precedence over the
     *     parent's, so one week of a series can say something the rest does not.
     *   - a modified date (moved, or overridden) surfaces a small "edited"
     *     hint in meta.
     *
     * @return list<array{kind:string,source:string,source_label:string,title:string,meta:?string,href:?string,date:string}>
     */
    private function eventOccurrenceItems(DateTimeImmutable $start, DateTimeImmutable $end, ?int $campusId, array $audiences = []): array
    {
        // Window comparison: include occurrences whose start falls in the
        // window. We compare against eo.starts_at (datetime) using
        // 'Y-m-d H:i:s'; the window endpoints are normalized to whole days
        // by the caller.
        $sql = 'SELECT e.id AS event_id,
                       e.title,
                       e.summary,
                       eo.id AS occurrence_id,
                       eo.starts_at,
                       eo.ends_at,
                       eo.original_starts_at,
                       eo.title_override,
                       eo.details_override,
                       eo.status,
                       e.repeat_frequency,
                       e.event_type_id,
                       et.slug AS type_slug,
                       et.name AS type_label,
                       et.color AS type_color
                  FROM event_occurrences eo
            INNER JOIN events e ON e.id = eo.event_id
             LEFT JOIN event_types et ON et.id = e.event_type_id
                 WHERE e.is_active = 1
                   AND eo.starts_at >= :start_at
                   AND eo.starts_at <  :end_at';

        $params = [
            ':start_at' => $start->format('Y-m-d H:i:s'),
            ':end_at'   => $end->format('Y-m-d H:i:s'),
        ];

        // Same predicate as SqlEventAdapter: the calendar is just another
        // read path, and a leader-only event must not leak through it. The
        // fallback is 'members', never 'leaders' — see the event adapter for why.
        $audienceValues = array_values(array_unique(array_filter($audiences, 'is_string')));
        if ($audienceValues === []) {
            $audienceValues = ['members'];
        }
        $audienceKeys = [];
        foreach ($audienceValues as $i => $value) {
            $key = ':aud' . $i;
            $audienceKeys[] = $key;
            $params[$key] = $value;
        }
        $sql .= ' AND COALESCE(NULLIF(et.audience, ""), "members") IN (' . implode(', ', $audienceKeys) . ')';

        if ($campusId !== null) {
            // Multi-campus: include events explicitly pinned to this campus
            // OR events with no campus association (treated as church-wide).
            $sql .= ' AND (
                        EXISTS (
                            SELECT 1 FROM event_campuses eec
                             WHERE eec.event_id = e.id
                               AND eec.campus_id = :campus_id
                        )
                        OR NOT EXISTS (
                            SELECT 1 FROM event_campuses eec2
                             WHERE eec2.event_id = e.id
                        )
                      )';
            $params[':campus_id'] = $campusId;
        }

        $sql .= ' ORDER BY eo.starts_at ASC, e.title ASC';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $start = (string) $row['starts_at'];
            $title = !empty($row['title_override'])
                ? (string) $row['title_override']
                : (string) $row['title'];

            $cancelled = (string) ($row['status'] ?? 'scheduled') === 'cancelled';
            // Modified is derived: the date says something of its own. A moved
            // time is not an edit, as it never was.
            $modified = ($row['title_override'] ?? '') !== ''
                || ($row['details_override'] ?? '') !== '';
            $meta = $this->formatOccurrenceMeta(
                $start,
                (string) $row['ends_at'],
                $row['repeat_frequency'] !== null ? (string) $row['repeat_frequency'] : null,
                $modified
            );
            if ($cancelled) {
                // In the text, not only in the styling: a strike-through alone
                // is colour-and-decoration, which a screen reader does not read
                // and a printed calendar can lose.
                $title = 'Cancelled — ' . $title;
            }

            // One layer per event type. The events: prefix is what keeps a type
            // slug of "birthdays" from silently merging with the birthdays
            // layer that already exists. An event whose type row is missing has
            // no slug, so it lands in general rather than an unnamed bucket.
            $slug = ($row['type_slug'] ?? null) !== null ? (string) $row['type_slug'] : 'general';

            $items[] = [
                'kind'         => 'event',
                'source'       => 'events:' . $slug,
                'source_label' => ($row['type_label'] ?? null) !== null ? (string) $row['type_label'] : 'Events',
                'color'        => ($row['type_color'] ?? null) !== null ? (string) $row['type_color'] : null,
                // A stable identity for the front-end's dedupe. Keying on
                // date|title|source alone dropped two genuinely different
                // events that shared a day and a name.
                'id'           => 'occ:' . (int) $row['occurrence_id'],
                'title'        => $title,
                'meta'         => $meta,
                'cancelled'    => $cancelled,
                // What this date says, which may not be what its series says.
                'description'  => (string) (($row['details_override'] ?? '') !== ''
                    ? $row['details_override']
                    : ($row['summary'] ?? '')),
                'overridden'   => $modified,
                'href'         => '/events/' . (int) $row['event_id'],
                'date'         => substr($start, 0, 10),
                // Carry the full datetime so the portal's day/week views can
                // render times — front-end falls back to "date" otherwise.
                'starts_at'    => $start,
                'ends_at'      => (string) $row['ends_at'],
            ];
        }

        return $items;
    }

    /**
     * Format a one-line meta string: "9:00 AM – 12:00 PM · Weekly" plus an
     * "edited" suffix when the occurrence has overrides.
     */
    private function formatOccurrenceMeta(string $startsAt, string $endsAt, ?string $recurrenceType, bool $isModified): string
    {
        $startTs = strtotime($startsAt);
        $endTs   = strtotime($endsAt);
        $time = $startTs !== false && $endTs !== false
            ? date('g:i A', $startTs) . ' – ' . date('g:i A', $endTs)
            : '';

        $parts = [];
        if ($time !== '') {
            $parts[] = $time;
        }
        if ($recurrenceType !== null && $recurrenceType !== '' && $recurrenceType !== 'none') {
            $parts[] = ucfirst($recurrenceType);
        }
        if ($isModified) {
            $parts[] = 'edited';
        }
        return implode(' · ', $parts);
    }

    /**
     * @return list<array{kind:string,source:string,source_label:string,title:string,meta:?string,href:?string,date:string}>
     */
    private function birthdayItems(DateTimeImmutable $start, DateTimeImmutable $end, ?int $campusId): array
    {
        $sql = 'SELECT p.id AS person_id,
                       p.first_name,
                       p.last_name,
                       p.birth_month,
                       p.birth_day,
                       p.birth_year
                  FROM people p
                 WHERE p.birth_month > 0
                   AND p.birth_day > 0';
        $params = [];
        if ($campusId !== null) {
            $sql .= ' AND p.campus_id = :campus_id';
            $params[':campus_id'] = $campusId;
        }
        $stmt = $this->connection->prepare($sql . ' ORDER BY p.birth_month ASC, p.birth_day ASC, p.last_name ASC, p.first_name ASC');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $people = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $this->expandAnnualItems(
            rows: $people,
            start: $start,
            end: $end,
            source: 'birthdays',
            sourceLabel: 'Birthdays',
            kind: 'birth',
            dateBuilder: static function (array $row, int $year): ?array {
                $parsed = self::dayInYear($year, (int) $row['birth_month'], (int) $row['birth_day']);
                if ($parsed === null) {
                    return null;
                }
                $date = $parsed->format('Y-m-d');
                $name = trim(((string) $row['first_name']) . ' ' . ((string) $row['last_name']));
                $age = (int) $row['birth_year'] > 0 ? $year - (int) $row['birth_year'] : null;
                return [
                    'title' => $age !== null && $age > 0 ? $name . ' (' . $age . ')' : $name,
                    'meta' => 'Birthday',
                    'href' => '/people/' . (int) $row['person_id'],
                ];
            },
        );
    }

    /**
     * @return list<array{kind:string,source:string,source_label:string,title:string,meta:?string,href:?string,date:string}>
     */
    private function anniversaryItems(DateTimeImmutable $start, DateTimeImmutable $end, ?int $campusId): array
    {
        $sql = 'SELECT h.id AS household_id,
                       h.name AS household_name,
                       h.wedding_date
                  FROM households h
                 WHERE h.deactivated_on IS NULL
                   AND h.wedding_date IS NOT NULL';
        $params = [];
        if ($campusId !== null) {
            $sql .= ' AND EXISTS (
                        SELECT 1
                          FROM people p
                         WHERE p.household_id = h.id
                           AND p.campus_id = :campus_id
                      )';
            $params[':campus_id'] = $campusId;
        }
        $stmt = $this->connection->prepare($sql . ' ORDER BY h.wedding_date ASC, h.name ASC');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $households = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $this->expandAnnualItems(
            rows: $households,
            start: $start,
            end: $end,
            source: 'anniversaries',
            sourceLabel: 'Anniversaries',
            kind: 'anniv',
            dateBuilder: static function (array $row, int $year): ?array {
                $weddingDate = (string) $row['wedding_date'];
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weddingDate)) {
                    return null;
                }
                [, $month, $day] = explode('-', $weddingDate);
                $parsed = self::dayInYear($year, (int) $month, (int) $day);
                if ($parsed === null) {
                    return null;
                }
                $date = $parsed->format('Y-m-d');
                $householdName = trim((string) $row['household_name']);
                $years = (int) substr($weddingDate, 0, 4) > 0 ? $year - (int) substr($weddingDate, 0, 4) : null;
                return [
                    'title' => 'Anniversary: ' . $householdName,
                    'meta' => $years !== null && $years > 0 ? $years . ' years' : 'Anniversary',
                    'href' => null,
                ];
            },
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>, int): ?array{title:string,meta:?string,href:?string} $dateBuilder
     * @return list<array{kind:string,source:string,source_label:string,title:string,meta:?string,href:?string,date:string}>
     */
    /**
     * A recurring day-of-year placed into a given year.
     *
     * The 29th of February does not exist in most years, and PHP's date parsing
     * rolls it silently to the 1st of March — so somebody born on a leap day
     * would appear on the calendar in the wrong month, with nothing to show it
     * had happened.
     *
     * Clamped to the last day the month actually has instead, which keeps a
     * February birthday in February. Deliberate, and the only place it is
     * decided.
     */
    private static function dayInYear(int $year, int $month, int $day): ?DateTimeImmutable
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        $first = DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%04d-%d-1', $year, $month));
        if ($first === false) {
            return null;
        }

        return $first->setDate($year, $month, min($day, (int) $first->format('t')));
    }

    private function expandAnnualItems(array $rows, DateTimeImmutable $start, DateTimeImmutable $end, string $source, string $sourceLabel, string $kind, callable $dateBuilder): array
    {
        $items = [];
        $startYear = (int) $start->format('Y');
        $endYear = (int) $end->format('Y');
        if ($endYear < $startYear) {
            return [];
        }

        foreach ($rows as $row) {
            for ($year = $startYear; $year <= $endYear; $year++) {
                $payload = $dateBuilder($row, $year);
                if ($payload === null) {
                    continue;
                }
                $date = $payload['date'] ?? null;
                if (!is_string($date)) {
                    $month = $row['birth_month'] ?? null;
                    $day = $row['birth_day'] ?? null;
                    if ($source === 'anniversaries') {
                        $weddingDate = (string) ($row['wedding_date'] ?? '');
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weddingDate)) {
                            continue;
                        }
                        [, $month, $day] = explode('-', $weddingDate);
                    }
                    if (!is_numeric($month) || !is_numeric($day)) {
                        continue;
                    }
                    $built = self::dayInYear($year, (int) $month, (int) $day);
                    if ($built === null) {
                        continue;
                    }
                    $date = $built->format('Y-m-d');
                }
                // "!" zeroes the time. Without it createFromFormat fills the
                // clock from *now*, so a birthday on the last day of the range
                // parsed as that afternoon and compared as later than a window
                // ending at midnight — and disappeared, unless the page
                // happened to be built at exactly 00:00:00.
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed < $start || $parsed >= $end) {
                    continue;
                }
                $items[] = [
                    'kind' => $kind,
                    'source' => $source,
                    'source_label' => $sourceLabel,
                    'title' => (string) $payload['title'],
                    'meta' => $payload['meta'] ?? null,
                    'href' => $payload['href'] ?? null,
                    'date' => $parsed->format('Y-m-d'),
                ];
            }
        }

        return $items;
    }
}
