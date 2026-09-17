<?php

declare(strict_types=1);

namespace App\Adapters\ChurchCRM;

use App\Contracts\CalendarAdapter;
use DateTimeImmutable;
use PDO;

final class ChurchCrmCalendarAdapter implements CalendarAdapter
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
        // person_per and family_fam, are already governed by campus scope, and
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
     * Reads every event_occurrence row in the window, joining to events_event
     * for the title/url and to event_recurrence for the "this is a recurring
     * series" badge. Honors per-occurrence overrides:
     *   - is_cancelled rows are shown, marked cancelled. Hiding them was the
     *     old behaviour and it erased the announcement: a Sunday with no
     *     service is not the same as a Sunday that was never scheduled, and
     *     somebody turns up to find out which. Nothing was ever cancelled
     *     under the old code, so this surfaces no history — it makes the
     *     cancel action mean something.
     *   - override_title and override_desc take precedence over the parent's,
     *     so one week of a series can say something the rest does not.
     *   - is_modified surfaces a small "edited" hint in meta.
     *
     * Mirrors the FullCalendar feed in api/routes/calendar/calendar.php so
     * the portal calendar shows the same data as the ChurchCRM v2 calendar.
     *
     * @return list<array{kind:string,source:string,source_label:string,title:string,meta:?string,href:?string,date:string}>
     */
    private function eventOccurrenceItems(DateTimeImmutable $start, DateTimeImmutable $end, ?int $campusId, array $audiences = []): array
    {
        // Window comparison: include occurrences whose start falls in the
        // window. We compare against eo.occurrence_start (datetime) using
        // 'Y-m-d H:i:s'; the window endpoints are normalized to whole days
        // by the caller, so this matches the v2/calendar feed semantics.
        $sql = 'SELECT e.event_id,
                       e.event_title,
                       e.event_desc,
                       e.inactive,
                       eo.occurrence_id,
                       eo.occurrence_start,
                       eo.occurrence_end,
                       eo.override_title,
                       eo.override_desc,
                       eo.is_modified,
                       eo.is_cancelled,
                       r.recurrence_type,
                       e.event_type AS event_type_id,
                       et.portal_slug AS type_slug,
                       et.portal_label AS type_label,
                       et.portal_color AS type_color
                  FROM event_occurrence eo
            INNER JOIN events_event e ON e.event_id = eo.event_id
             LEFT JOIN event_recurrence r ON r.event_id = e.event_id
             LEFT JOIN event_types et ON et.type_id = e.event_type
                 WHERE e.inactive = 0
                   AND eo.occurrence_start >= :start_at
                   AND eo.occurrence_start <  :end_at';

        $params = [
            ':start_at' => $start->format('Y-m-d H:i:s'),
            ':end_at'   => $end->format('Y-m-d H:i:s'),
        ];

        // Same predicate as ChurchCrmEventAdapter: the calendar is just another
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
        $sql .= ' AND COALESCE(NULLIF(et.portal_audience, ""), "members") IN (' . implode(', ', $audienceKeys) . ')';

        if ($campusId !== null) {
            // Multi-campus: include events explicitly pinned to this campus
            // OR events with no campus association (treated as church-wide).
            $sql .= ' AND (
                        EXISTS (
                            SELECT 1 FROM events_event_campus eec
                             WHERE eec.event_id = e.event_id
                               AND eec.campus_id = :campus_id
                        )
                        OR NOT EXISTS (
                            SELECT 1 FROM events_event_campus eec2
                             WHERE eec2.event_id = e.event_id
                        )
                      )';
            $params[':campus_id'] = $campusId;
        }

        $sql .= ' ORDER BY eo.occurrence_start ASC, e.event_title ASC';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $start = (string) $row['occurrence_start'];
            $title = !empty($row['override_title'])
                ? (string) $row['override_title']
                : (string) $row['event_title'];

            $cancelled = (int) ($row['is_cancelled'] ?? 0) === 1;
            $meta = $this->formatOccurrenceMeta(
                $start,
                (string) $row['occurrence_end'],
                $row['recurrence_type'] !== null ? (string) $row['recurrence_type'] : null,
                (int) $row['is_modified'] === 1
            );
            if ($cancelled) {
                // In the text, not only in the styling: a strike-through alone
                // is colour-and-decoration, which a screen reader does not read
                // and a printed calendar can lose.
                $title = 'Cancelled — ' . $title;
            }

            // One layer per event type. The events: prefix is what keeps a type
            // slug of "birthdays" from silently merging with the birthdays
            // layer that already exists. A type the portal has not claimed has
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
                'description'  => (string) (($row['override_desc'] ?? '') !== ''
                    ? $row['override_desc']
                    : ($row['event_desc'] ?? '')),
                'overridden'   => (int) ($row['is_modified'] ?? 0) === 1,
                'href'         => '/events/' . (int) $row['event_id'],
                'date'         => substr($start, 0, 10),
                // Carry the full datetime so the portal's day/week views can
                // render times — front-end falls back to "date" otherwise.
                'starts_at'    => $start,
                'ends_at'      => (string) $row['occurrence_end'],
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
        $sql = 'SELECT p.per_ID AS person_id,
                       p.per_FirstName AS first_name,
                       p.per_LastName AS last_name,
                       p.per_BirthMonth AS birth_month,
                       p.per_BirthDay AS birth_day,
                       p.per_BirthYear AS birth_year
                  FROM person_per p
                 WHERE p.per_BirthMonth > 0
                   AND p.per_BirthDay > 0';
        $params = [];
        if ($campusId !== null) {
            $sql .= ' AND EXISTS (
                        SELECT 1
                          FROM person_campus_affiliation pca
                         WHERE pca.person_id = p.per_ID
                           AND pca.campus_id = :campus_id
                           AND pca.is_primary = 1
                      )';
            $params[':campus_id'] = $campusId;
        }
        $stmt = $this->connection->prepare($sql . ' ORDER BY p.per_BirthMonth ASC, p.per_BirthDay ASC, p.per_LastName ASC, p.per_FirstName ASC');
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
        $sql = 'SELECT f.fam_ID AS family_id,
                       f.fam_Name AS family_name,
                       f.fam_WeddingDate AS wedding_date
                  FROM family_fam f
                 WHERE f.fam_DateDeactivated IS NULL
                   AND f.fam_WeddingDate IS NOT NULL
                   AND f.fam_WeddingDate <> "0000-00-00"';
        $params = [];
        if ($campusId !== null) {
            $sql .= ' AND EXISTS (
                        SELECT 1
                          FROM person_per p
                          JOIN person_campus_affiliation pca ON pca.person_id = p.per_ID
                         WHERE p.per_fam_ID = f.fam_ID
                           AND pca.campus_id = :campus_id
                           AND pca.is_primary = 1
                      )';
            $params[':campus_id'] = $campusId;
        }
        $stmt = $this->connection->prepare($sql . ' ORDER BY f.fam_WeddingDate ASC, f.fam_Name ASC');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $families = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $this->expandAnnualItems(
            rows: $families,
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
                $familyName = trim((string) $row['family_name']);
                $years = (int) substr($weddingDate, 0, 4) > 0 ? $year - (int) substr($weddingDate, 0, 4) : null;
                return [
                    'title' => 'Anniversary: ' . $familyName,
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
