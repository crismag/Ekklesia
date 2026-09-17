<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\EventAdapter;
use DateTimeImmutable;
use PDO;

final class SqlEventAdapter implements EventAdapter
{
    /**
     * Every audience value, for internal reads that the service has already
     * authorized. Never use this to answer a request.
     */
    private const ALL_AUDIENCES = ['public', 'members', 'leaders'];

    public function __construct(
        private readonly ?PDO $connection = null,
    ) {
    }

    /**
     * @param int|null $campusId  restrict to one campus (topbar campus context)
     * @param string   $range     upcoming|past|month|quarter|all
     * @param string|null $anchor Y-m-d inside the month/quarter being browsed
     */
    /**
     * The audience predicate, as a bare boolean expression over an already
     * joined `event_types et`.
     *
     * COALESCE(NULLIF(...), 'members') over a LEFT JOIN rather than an INNER
     * JOIN on purpose: an event whose type row is missing must not vanish for
     * everyone, which is a data-loss-shaped bug. The fallback is 'members',
     * never 'leaders', so an unclassified event stays visible to signed-in
     * users and can never become restricted by accident.
     *
     * @param list<string>        $audiences
     * @param array<string,mixed> $bind      receives the generated placeholders
     */
    /**
     * The type new events fall back to when the caller named none.
     *
     * Returns 0 when no default is configured, which is the pre-migration
     * behaviour rather than an invented id — the COALESCE in audienceExpr()
     * still treats such an event as 'members'.
     */
    private function defaultTypeId(): int
    {
        if ($this->connection === null) {
            return 0;
        }
        $stmt = $this->connection->query(
            'SELECT id FROM event_types WHERE is_default = 1 LIMIT 1'
        );
        $row = $stmt === false || $stmt === null ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['id'];
    }

    private function audienceExpr(array $audiences, array &$bind): string
    {
        // Fail closed. An empty list is a caller that forgot to pass one, not
        // a caller asking to see everything.
        $values = array_values(array_unique(array_filter($audiences, 'is_string')));
        if ($values === []) {
            $values = ['members'];
        }
        $keys = [];
        foreach ($values as $i => $value) {
            $key = ':aud' . $i;
            $keys[] = $key;
            $bind[$key] = $value;
        }

        return 'COALESCE(NULLIF(et.audience, ""), "members") IN (' . implode(', ', $keys) . ')';
    }

    /**
     * Occurrences in a window, newest-first for the past and oldest-first
     * otherwise, each carrying enough of its event to render an agenda row.
     *
     * The Events page groups by date, so it needs occurrences rather than
     * events: one weekly event contributes many rows across a month, and
     * several different events share a single Sunday.
     *
     * @return list<array<string,mixed>>
     */
    public function listOccurrences(string $from, string $to, ?int $campusId, int $limit, bool $descending = false, array $audiences = []): array
    {
        if ($this->connection === null) {
            return [];
        }
        $audBind = [];
        $audienceExpr = $this->audienceExpr($audiences, $audBind);
        $campusJoin = '';
        if ($campusId !== null && $campusId > 0) {
            $campusJoin = 'JOIN event_campuses fc ON fc.event_id = e.id AND fc.campus_id = :campus_id';
        }
        $sql = 'SELECT o.id AS occurrence_id, o.starts_at, o.ends_at, o.status,
                       COALESCE(NULLIF(o.title_override, ""), e.title) AS title,
                       e.id AS event_id, e.summary, e.location_name, e.ministry_id,
                       e.event_type_id, et.slug AS type_slug,
                       et.name AS type_label, et.color AS type_color,
                       (SELECT COUNT(*) FROM event_occurrences oc WHERE oc.event_id = e.id) AS occurrence_count,
                       (SELECT GROUP_CONCAT(c.name ORDER BY ec.is_host DESC, c.name SEPARATOR ", ")
                          FROM event_campuses ec JOIN campuses c ON c.id = ec.campus_id
                         WHERE ec.event_id = e.id) AS campus_names,
                       (SELECT c2.name FROM event_campuses ec2
                          JOIN campuses c2 ON c2.id = ec2.campus_id
                         WHERE ec2.event_id = e.id AND ec2.is_host = 1 LIMIT 1) AS host_campus_name
                  FROM event_occurrences o
                  JOIN events e ON e.id = o.event_id
             LEFT JOIN event_types et ON et.id = e.event_type_id
                       ' . $campusJoin . '
                 WHERE o.starts_at >= :from AND o.starts_at < :to
                   AND ' . $audienceExpr . '
              ORDER BY o.starts_at ' . ($descending ? 'DESC' : 'ASC') . ', title ASC
                 LIMIT :limit';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':from', $from, PDO::PARAM_STR);
        $stmt->bindValue(':to', $to, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        if ($campusId !== null && $campusId > 0) {
            $stmt->bindValue(':campus_id', $campusId, PDO::PARAM_INT);
        }
        foreach ($audBind as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rows[] = [
                'occurrence_id' => (int) $r['occurrence_id'],
                'event_id' => (int) $r['event_id'],
                'title' => (string) $r['title'],
                'summary' => $r['summary'] === null ? null : (string) $r['summary'],
                'starts_at' => (string) $r['starts_at'],
                'ends_at' => $r['ends_at'] === null ? null : (string) $r['ends_at'],
                // Derived from status, for the views that ask a yes/no question.
                'is_cancelled' => (string) ($r['status'] ?? 'scheduled') === 'cancelled',
                'occurrence_count' => (int) $r['occurrence_count'],
                'campus_names' => $r['campus_names'] === null ? null : (string) $r['campus_names'],
                'host_campus_name' => $r['host_campus_name'] === null ? null : (string) $r['host_campus_name'],
                'location_name' => ($r['location_name'] ?? '') === '' ? null : (string) $r['location_name'],
                'ministry_id' => $r['ministry_id'] === null ? null : (int) $r['ministry_id'],
                'event_type_id' => (int) ($r['event_type_id'] ?? 0),
                'type_slug' => $r['type_slug'] === null ? null : (string) $r['type_slug'],
                'type_label' => $r['type_label'] === null ? null : (string) $r['type_label'],
                'type_color' => $r['type_color'] === null ? null : (string) $r['type_color'],
            ];
        }

        return $rows;
    }

    public function listUpcoming(int $limit, ?int $campusId = null, string $range = 'upcoming', ?string $anchor = null, array $audiences = []): array
    {
        if ($this->connection === null) {
            return [];
        }
        // Which slice of time the browser is showing. Past reads backwards so the
        // most recent is first, which is what someone checking history wants.
        $bind = [];
        $audienceExpr = $this->audienceExpr($audiences, $bind);
        $window = match ($range) {
            'past' => 'eo.starts_at < CURDATE()',
            'all' => '1 = 1',
            'month', 'quarter' => 'eo.starts_at >= :win_from AND eo.starts_at < :win_to',
            default => 'eo.starts_at >= CURDATE()',
        };
        if ($range === 'month' || $range === 'quarter') {
            $base = $anchor !== null && $anchor !== '' ? new \DateTimeImmutable($anchor) : new \DateTimeImmutable('today');
            if ($range === 'month') {
                $from = $base->modify('first day of this month')->setTime(0, 0);
                $to = $from->modify('+1 month');
            } else {
                $q = (int) floor(((int) $base->format('n') - 1) / 3);
                $from = $base->setDate((int) $base->format('Y'), $q * 3 + 1, 1)->setTime(0, 0);
                $to = $from->modify('+3 months');
            }
            $bind[':win_from'] = $from->format('Y-m-d H:i:s');
            $bind[':win_to'] = $to->format('Y-m-d H:i:s');
        }

        // The topbar campus selector is the portal's context control; the events
        // list ignored it entirely, so switching campus changed nothing here.
        $campusJoin = '';
        if ($campusId !== null && $campusId > 0) {
            $campusJoin = 'JOIN event_campuses fc ON fc.event_id = e.id AND fc.campus_id = :campus_id';
            $bind[':campus_id'] = $campusId;
        }

        // The list is a management surface, so it needs enough to scan a whole
        // schedule: when it next runs and for how long, which campus it is for,
        // where it is held if that is not a campus, and how often it repeats.
        $sql = 'SELECT e.id AS event_id, e.title, e.summary,
                       e.location_name,
                       e.ministry_id,
                       e.event_type_id, et.slug AS type_slug,
                       et.name AS type_label, et.color AS type_color,
                       COUNT(eo.id) AS occurrence_count,
                       MIN(eo.starts_at) AS next_occurrence_at,
                       (SELECT o2.ends_at FROM event_occurrences o2
                         WHERE o2.event_id = e.id AND o2.starts_at >= CURDATE()
                         ORDER BY o2.starts_at ASC LIMIT 1) AS next_occurrence_end,
                       (SELECT GROUP_CONCAT(c.name ORDER BY ec.is_host DESC, c.name SEPARATOR ", ")
                          FROM event_campuses ec
                          JOIN campuses c ON c.id = ec.campus_id
                         WHERE ec.event_id = e.id) AS campus_names,
                       (SELECT c2.name FROM event_campuses ec2
                          JOIN campuses c2 ON c2.id = ec2.campus_id
                         WHERE ec2.event_id = e.id AND ec2.is_host = 1 LIMIT 1) AS host_campus_name
                  FROM events e
             LEFT JOIN event_occurrences eo ON eo.event_id = e.id AND ' . $window . '
             LEFT JOIN event_types et ON et.id = e.event_type_id
                   ' . $campusJoin . '
                 WHERE ' . $audienceExpr . '
              GROUP BY e.id, e.title, e.summary, e.location_name, e.ministry_id,
                       e.event_type_id, et.slug, et.name, et.color
             HAVING next_occurrence_at IS NOT NULL
              ORDER BY next_occurrence_at ' . ($range === 'past' ? 'DESC' : 'ASC') . '
              LIMIT :limit';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach ($bind as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'event_id' => (int) $row['event_id'],
                'title' => (string) $row['title'],
                'summary' => $row['summary'] === null ? null : (string) $row['summary'],
                'occurrence_count' => (int) $row['occurrence_count'],
                'next_occurrence_at' => $row['next_occurrence_at'] === null ? null : (string) $row['next_occurrence_at'],
                'next_occurrence_end' => $row['next_occurrence_end'] === null ? null : (string) $row['next_occurrence_end'],
                'campus_names' => $row['campus_names'] === null ? null : (string) $row['campus_names'],
                'host_campus_name' => $row['host_campus_name'] === null ? null : (string) $row['host_campus_name'],
                'location_name' => ($row['location_name'] ?? '') === '' ? null : (string) $row['location_name'],
                'ministry_id' => $row['ministry_id'] === null ? null : (int) $row['ministry_id'],
                'event_type_id' => (int) ($row['event_type_id'] ?? 0),
                'type_slug' => $row['type_slug'] === null ? null : (string) $row['type_slug'],
                'type_label' => $row['type_label'] === null ? null : (string) $row['type_label'],
                'type_color' => $row['type_color'] === null ? null : (string) $row['type_color'],
            ];
        }
        return $rows;
    }

    public function findEvent(int $eventId, DateTimeImmutable $start, DateTimeImmutable $end, array $audiences = []): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        // A denied event returns null, exactly as a nonexistent one does. If
        // the two differed, the response to /events/117 versus /events/99999
        // would confirm that a hidden event exists at 117.
        $audBind = [];
        $audienceExpr = $this->audienceExpr($audiences, $audBind);
        $sql = 'SELECT e.id AS event_id, e.title, e.summary,
                       ((SELECT COUNT(*) FROM event_campuses mc WHERE mc.event_id = e.id) > 1) AS is_multi_campus,
                       e.ministry_id,
                       e.event_type_id, et.slug AS type_slug,
                       et.name AS type_label, et.color AS type_color,
                       e.uses_serving_schedule
                  FROM events e
             LEFT JOIN event_types et ON et.id = e.event_type_id
                 WHERE e.id = :event_id
                   AND ' . $audienceExpr . '
                 LIMIT 1';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        foreach ($audBind as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $stmt2 = $this->connection->prepare(
            'SELECT id, starts_at, ends_at, original_starts_at,
                      status, title_override, details_override
               FROM event_occurrences
              WHERE event_id = :event_id
                AND starts_at BETWEEN :start_at AND :end_at
              ORDER BY starts_at ASC'
        );
        $stmt2->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt2->bindValue(':start_at', $start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt2->bindValue(':end_at', $end->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt2->execute();
        $occ = [];
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $occ[] = [
                'occurrence_id' => (int) $r['id'],
                'starts_at' => (string) $r['starts_at'],
                'ends_at' => (string) $r['ends_at'],
                'is_cancelled' => (string) $r['status'] === 'cancelled',
                // What this one date says, when it says something of its own.
                'is_modified' => self::isModified($r),
                'title_override' => ($r['title_override'] ?? '') !== '' ? (string) $r['title_override'] : null,
                'details_override' => ($r['details_override'] ?? '') !== '' ? (string) $r['details_override'] : null,
            ];
        }

        $campusIdsStmt = $this->connection->prepare(
            'SELECT campus_id
               FROM event_campuses
              WHERE event_id = :event_id
              ORDER BY is_host DESC, campus_id ASC'
        );
        $campusIdsStmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $campusIdsStmt->execute();
        $campusIds = array_map(
            fn (array $campusRow): int => (int) $campusRow['campus_id'],
            $campusIdsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );

        $campusesStmt = $this->connection->query(
            'SELECT id, name
               FROM campuses
              WHERE is_active = 1
              ORDER BY is_main DESC, name ASC, id ASC'
        );
        $availableCampuses = [];
        foreach ($campusesStmt?->fetchAll(PDO::FETCH_ASSOC) ?: [] as $campusRow) {
            $availableCampuses[] = [
                'id' => (int) $campusRow['id'],
                'name' => (string) $campusRow['name'],
            ];
        }

        return [
            'event_id' => (int) $row['event_id'],
            'title' => (string) $row['title'],
            'summary' => $row['summary'] === null ? null : (string) $row['summary'],
            'occurrences' => $occ,
            'campus_ids' => $campusIds,
            'available_campuses' => $availableCampuses,
            'is_multi_campus' => (bool) $row['is_multi_campus'],
            'ministry_id' => $row['ministry_id'] === null ? null : (int) $row['ministry_id'],
            'event_type_id' => (int) ($row['event_type_id'] ?? 0),
            'type_slug' => $row['type_slug'] === null ? null : (string) $row['type_slug'],
            'type_label' => $row['type_label'] === null ? null : (string) $row['type_label'],
            'type_color' => $row['type_color'] === null ? null : (string) $row['type_color'],
            'uses_serving_schedule' => (int) ($row['uses_serving_schedule'] ?? 0) === 1,
        ];
    }

    /**
     * The audience of the event an occurrence belongs to.
     *
     * deleteOccurrence() and countAssignmentsForOccurrence() take a bare
     * occurrence id and never resolve the parent event, so without this an
     * actor holding CancelOccurrences could cancel an occurrence of an event
     * they are not allowed to see — and the assignment count would confirm the
     * id exists. The read side is filtered in SQL; this is what lets the write
     * side be filtered too.
     *
     * @return array{event_id:int,audience:string}|null
     */
    public function findOccurrenceAudience(int $occurrenceId): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        $stmt = $this->connection->prepare(
            'SELECT e.id AS event_id,
                    COALESCE(NULLIF(et.audience, ""), "members") AS audience
               FROM event_occurrences o
               JOIN events e ON e.id = o.event_id
          LEFT JOIN event_types et ON et.id = e.event_type_id
              WHERE o.id = :occurrence_id
              LIMIT 1'
        );
        $stmt->bindValue(':occurrence_id', $occurrenceId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return ['event_id' => (int) $row['event_id'], 'audience' => (string) $row['audience']];
    }

    public function createEvent(array $cmd, DateTimeImmutable $now): int
    {
        if ($this->connection === null) {
            return 0;
        }
        $campusIds = $this->normalizeCampusIds($cmd['campus_ids'] ?? []);

        // The caller supplies the first date's window; the event keeps its
        // date and clock times, and each occurrence keeps its own datetimes.
        $start = (string) ($cmd['starts_at'] ?? '');
        $end = (string) ($cmd['ends_at'] ?? '');
        if ($start === '' || $end === '') {
            throw new \InvalidArgumentException('An event needs a start and end datetime.');
        }
        [$startsOn, $startTime, $endTime, $allDay] = self::splitWindow($start, $end);

        $sql = 'INSERT INTO events
                    (title, summary, starts_on, start_time, end_time, all_day,
                     location_name, location_address, ministry_id, event_type_id,
                     uses_serving_schedule, source_app)
                VALUES (:title, :summary, :starts_on, :start_time, :end_time, :all_day,
                        :location_name, :location_address, :ministry_id, :event_type_id,
                        :uses_serving_schedule, :source_app)';
        $stmt = $this->connection->prepare($sql);
        $this->connection->beginTransaction();
        try {
            $stmt->bindValue(':title', $cmd['title'] ?? '', PDO::PARAM_STR);
            $summary = ($cmd['summary'] ?? '') === '' ? null : (string) $cmd['summary'];
            $stmt->bindValue(':summary', $summary, $summary === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':starts_on', $startsOn, PDO::PARAM_STR);
            $stmt->bindValue(':start_time', $startTime, $startTime === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':end_time', $endTime, $endTime === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':all_day', $allDay ? 1 : 0, PDO::PARAM_INT);
            // Location is not campus: an event held somewhere other than its
            // campus has somewhere to say so.
            $locName = ($cmd['location_name'] ?? '') === '' ? null : (string) $cmd['location_name'];
            $locAddr = ($cmd['location_address'] ?? '') === '' ? null : (string) $cmd['location_address'];
            $stmt->bindValue(':location_name', $locName, $locName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':location_address', $locAddr, $locAddr === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            // NULL means church-wide, which is right for a holiday or Communion.
            $ministryId = isset($cmd['ministry_id']) && (int) $cmd['ministry_id'] > 0 ? (int) $cmd['ministry_id'] : null;
            $stmt->bindValue(':ministry_id', $ministryId, $ministryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            // Falling back to the configured default keeps the audience join
            // meaningful instead of relying on the COALESCE safety net.
            $typeId = isset($cmd['event_type_id']) && (int) $cmd['event_type_id'] > 0
                ? (int) $cmd['event_type_id']
                : $this->defaultTypeId();
            $stmt->bindValue(':event_type_id', $typeId, PDO::PARAM_INT);
            $stmt->bindValue(
                ':uses_serving_schedule',
                !empty($cmd['uses_serving_schedule']) ? 1 : 0,
                PDO::PARAM_INT,
            );
            // Events created here are the portal's own; a calendar sync from
            // another application writes its own source_app and external_id.
            $stmt->bindValue(':source_app', 'portal', PDO::PARAM_STR);
            $stmt->execute();
            $eventId = (int) $this->connection->lastInsertId();
            $this->syncEventCampuses($eventId, $campusIds, isset($cmd['host_campus_id']) ? (int) $cmd['host_campus_id'] : null);
            $this->connection->commit();
            return $eventId;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function updateEvent(int $eventId, array $cmd, DateTimeImmutable $now): bool
    {
        if ($this->connection === null) {
            return false;
        }
        // Read the current row across every audience. The service has already
        // decided this actor may edit this event; filtering again here would
        // make a leaders-only event silently un-editable by the very people
        // allowed to edit it.
        $current = $this->findEvent(
            $eventId,
            new DateTimeImmutable('-7 days'),
            new DateTimeImmutable('+365 days'),
            self::ALL_AUDIENCES,
        );
        if ($current === null) {
            return false;
        }

        // A null campus_ids means "leave them alone", not "remove them all".
        // EventUpdateCommand::toArray() always emits the key, so testing only
        // for its presence meant editing anything else about an event — the
        // title, or now the type — silently dropped every campus it was
        // assigned to.
        $campusIds = ($cmd['campus_ids'] ?? null) !== null
            ? $this->normalizeCampusIds($cmd['campus_ids'])
            : array_values(array_map('intval', $current['campus_ids'] ?? []));

        $sql = 'UPDATE events
                   SET title = :title,
                       summary = :summary,
                       event_type_id = :event_type_id,
                       ministry_id = :ministry_id,
                       uses_serving_schedule = :uses_serving_schedule
                 WHERE id = :event_id';
        $stmt = $this->connection->prepare($sql);
        $this->connection->beginTransaction();
        try {
            $stmt->bindValue(':title', $cmd['title'] ?? $current['title'] ?? '', PDO::PARAM_STR);
            $summary = array_key_exists('summary', $cmd) ? ($cmd['summary'] ?? null) : ($current['summary'] ?? null);
            $summary = $summary === '' ? null : $summary;
            $stmt->bindValue(':summary', $summary, $summary === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            // A null event_type_id means "leave it alone", not "clear it".
            $typeId = isset($cmd['event_type_id']) && (int) $cmd['event_type_id'] > 0
                ? (int) $cmd['event_type_id']
                : (int) ($current['event_type_id'] ?? 0);
            $stmt->bindValue(':event_type_id', $typeId, PDO::PARAM_INT);
            // Null leaves the ministry alone; 0 clears it to church-wide. The
            // two have to stay distinguishable or "no particular ministry"
            // becomes unreachable once one has been set.
            if (array_key_exists('ministry_id', $cmd) && $cmd['ministry_id'] !== null) {
                $ministryId = (int) $cmd['ministry_id'];
                $stmt->bindValue(':ministry_id', $ministryId > 0 ? $ministryId : null,
                    $ministryId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            } else {
                $existing = isset($current['ministry_id']) ? (int) $current['ministry_id'] : 0;
                $stmt->bindValue(':ministry_id', $existing > 0 ? $existing : null,
                    $existing > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            }
            if (array_key_exists('uses_serving_schedule', $cmd) && $cmd['uses_serving_schedule'] !== null) {
                $stmt->bindValue(
                    ':uses_serving_schedule',
                    $cmd['uses_serving_schedule'] ? 1 : 0,
                    PDO::PARAM_INT,
                );
            } else {
                $stmt->bindValue(
                    ':uses_serving_schedule',
                    !empty($current['uses_serving_schedule']) ? 1 : 0,
                    PDO::PARAM_INT,
                );
            }
            $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            $ok = $stmt->execute();
            // How many campuses an event is for is read from event_campuses,
            // so rewriting them is all "multi-campus" needs.
            $this->syncEventCampuses($eventId, $campusIds);
            $this->connection->commit();
            return $ok;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function insertOccurrences(int $eventId, array $rows): array
    {
        if ($this->connection === null) {
            return [];
        }
        $this->connection->beginTransaction();
        try {
            $ids = [];
            // original_starts_at records the date as it was first scheduled and
            // is never written again, so a moved date can still be recognised.
            $sql = 'INSERT INTO event_occurrences (event_id, original_starts_at, starts_at, ends_at, status)
                    VALUES (:event_id, :original_starts_at, :starts_at, :ends_at, "scheduled")';
            $stmt = $this->connection->prepare($sql);
            foreach ($rows as $r) {
                $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
                $stmt->bindValue(':original_starts_at', $r['starts_at'], PDO::PARAM_STR);
                $stmt->bindValue(':starts_at', $r['starts_at'], PDO::PARAM_STR);
                $stmt->bindValue(':ends_at', $r['ends_at'], PDO::PARAM_STR);
                $stmt->execute();
                $ids[] = (int) $this->connection->lastInsertId();
            }
            $this->connection->commit();
            return $ids;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function listTags(): array
    {
        if ($this->connection === null) {
            return [];
        }
        // A tag is its slug. The label shown is the first one it was given,
        // which is the one every use of it shares.
        $stmt = $this->connection->query(
            'SELECT t.slug, MIN(t.label) AS label, COUNT(*) AS usage_count
               FROM event_tags t
           GROUP BY t.slug
           ORDER BY label ASC'
        );

        return array_map(static fn (array $r): array => [
            'slug' => (string) $r['slug'],
            'label' => (string) $r['label'],
            'usage_count' => (int) $r['usage_count'],
        ], $stmt?->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function tagsForEvent(int $eventId): array
    {
        if ($this->connection === null) {
            return [];
        }
        $stmt = $this->connection->prepare(
            'SELECT t.slug, t.label
               FROM event_tags t
              WHERE t.event_id = :event_id
           ORDER BY t.label ASC'
        );
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'slug' => (string) $r['slug'],
            'label' => (string) $r['label'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function setEventTags(int $eventId, array $tags): array
    {
        if ($this->connection === null) {
            return [];
        }
        $this->connection->beginTransaction();
        try {
            $wanted = [];
            foreach ($tags as $tag) {
                $slug = (string) $tag['slug'];
                $label = (string) $tag['label'];
                // The slug is what makes a tag one tag, so it is what the
                // lookup is keyed on. An existing tag keeps the label it was
                // first given: renaming every past use because somebody typed
                // it differently today would be a surprise, not a correction.
                $find = $this->connection->prepare('SELECT label FROM event_tags WHERE slug = :slug LIMIT 1');
                $find->bindValue(':slug', $slug, PDO::PARAM_STR);
                $find->execute();
                $row = $find->fetch(PDO::FETCH_ASSOC);
                $wanted[$slug] ??= $row !== false ? (string) $row['label'] : $label;
            }

            // Replace rather than merge: the caller sent the complete list, and
            // an "add only" write gives no way to remove one. A tag nothing
            // carries any more stops existing with its last row.
            $del = $this->connection->prepare('DELETE FROM event_tags WHERE event_id = :event_id');
            $del->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            $del->execute();

            if ($wanted !== []) {
                $ins = $this->connection->prepare(
                    'INSERT INTO event_tags (event_id, slug, label) VALUES (:event_id, :slug, :label)'
                );
                foreach ($wanted as $slug => $label) {
                    $ins->bindValue(':event_id', $eventId, PDO::PARAM_INT);
                    $ins->bindValue(':slug', (string) $slug, PDO::PARAM_STR);
                    $ins->bindValue(':label', $label, PDO::PARAM_STR);
                    $ins->execute();
                }
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->tagsForEvent($eventId);
    }

    public function eventIdsWithTag(string $slug): array
    {
        if ($this->connection === null) {
            return [];
        }
        $stmt = $this->connection->prepare('SELECT event_id FROM event_tags WHERE slug = :slug');
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function setOccurrenceOverrides(int $occurrenceId, ?string $title, ?string $desc): bool
    {
        if ($this->connection === null) {
            return false;
        }
        // is_modified is derived on read (a moved date, or an override), so
        // only the overrides themselves are written.
        $stmt = $this->connection->prepare(
            'UPDATE event_occurrences
                SET title_override = :title,
                    details_override = :details
              WHERE id = :id'
        );
        $stmt->bindValue(':title', $title === '' ? null : $title,
            $title === null || $title === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':details', $desc === '' ? null : $desc,
            $desc === null || $desc === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function findOccurrence(int $occurrenceId): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        $stmt = $this->connection->prepare(
            'SELECT id, event_id, starts_at, ends_at, original_starts_at,
                    status, title_override, details_override
               FROM event_occurrences WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'occurrence_id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'starts_at' => (string) $row['starts_at'],
            'ends_at' => (string) $row['ends_at'],
            'original_starts_at' => (string) $row['original_starts_at'],
            'status' => (string) $row['status'],
            'is_cancelled' => (string) $row['status'] === 'cancelled',
            'is_modified' => self::isModified($row),
            'title_override' => $row['title_override'],
            'details_override' => $row['details_override'],
        ];
    }

    public function setOccurrenceCancelled(int $occurrenceId, bool $cancelled): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare(
            'UPDATE event_occurrences SET status = :status WHERE id = :id'
        );
        $stmt->bindValue(':status', $cancelled ? 'cancelled' : 'scheduled', PDO::PARAM_STR);
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteOccurrence(int $occurrenceId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        // The service has already refused unless nobody is scheduled or the
        // deletion was confirmed. Assignments refuse to be orphaned, so a
        // confirmed deletion takes them with the date, in one transaction.
        $this->connection->beginTransaction();
        try {
            $del = $this->connection->prepare('DELETE FROM assignments WHERE occurrence_id = :id');
            $del->bindValue(':id', $occurrenceId, PDO::PARAM_INT);
            $del->execute();
            $stmt = $this->connection->prepare('DELETE FROM event_occurrences WHERE id = :id');
            $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);
            $ok = $stmt->execute();
            $this->connection->commit();

            return $ok;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function listEventOccurrences(int $eventId): array
    {
        if ($this->connection === null) {
            return [];
        }
        $stmt = $this->connection->prepare(
            'SELECT id, starts_at, ends_at
               FROM event_occurrences
              WHERE event_id = :event_id
              ORDER BY starts_at ASC'
        );
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'occurrence_id' => (int) $row['id'],
                'starts_at' => (string) $row['starts_at'],
                'ends_at' => (string) $row['ends_at'],
            ];
        }

        return $out;
    }

    public function updateOccurrenceTimes(int $occurrenceId, string $start, string $end): bool
    {
        if ($this->connection === null) {
            return false;
        }
        // original_starts_at is left alone: it is what the date was first.
        $stmt = $this->connection->prepare(
            'UPDATE event_occurrences SET starts_at = :starts_at, ends_at = :ends_at
              WHERE id = :id'
        );
        $stmt->bindValue(':starts_at', $start, PDO::PARAM_STR);
        $stmt->bindValue(':ends_at', $end, PDO::PARAM_STR);
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Keep the repeat rule on the event itself.
     *
     * A null rule is "this event no longer repeats": the frequency goes back
     * to 'none' and every other repeat column is cleared, so a stale end date
     * or weekday cannot outlive the rule it belonged to.
     */
    public function saveRecurrence(int $eventId, ?array $rule): void
    {
        if ($this->connection === null) {
            return;
        }
        $stmt = $this->connection->prepare(
            'UPDATE events
                SET repeat_frequency = :frequency, repeat_interval = :interval,
                    repeat_weekdays = :weekdays, repeat_week_of_month = :week,
                    repeat_until = :until, repeat_count = :cnt
              WHERE id = :event_id'
        );
        $weekdays = $rule['repeat_weekdays'] ?? null;
        $week = $rule['repeat_week_of_month'] ?? null;
        $until = $rule['repeat_until'] ?? null;
        $count = $rule['repeat_count'] ?? null;
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->bindValue(':frequency', $rule === null ? 'none' : (string) $rule['repeat_frequency'], PDO::PARAM_STR);
        $stmt->bindValue(':interval', $rule === null ? 1 : (int) ($rule['repeat_interval'] ?? 1), PDO::PARAM_INT);
        $stmt->bindValue(':weekdays', $weekdays, $weekdays === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':week', $week, $week === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':until', $until, $until === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':cnt', $count, $count === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    }

    public function findRecurrence(int $eventId): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        $stmt = $this->connection->prepare(
            'SELECT id AS event_id, repeat_frequency, repeat_interval, repeat_weekdays,
                    repeat_week_of_month, repeat_until, repeat_count
               FROM events
              WHERE id = :event_id AND repeat_frequency <> "none"
              LIMIT 1'
        );
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Remove an event and everything hanging off it.
     *
     * Assignments and occurrences are removed here rather than left to a
     * foreign key: occurrences refuse to cascade, because they are what
     * assignments attach to. Campus links and tags cascade with the event.
     *
     * Assignments are the caller's problem — the service refuses to reach this
     * method while any exist unless the deletion was explicitly confirmed.
     */
    public function deleteEvent(int $eventId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $this->connection->beginTransaction();
        try {
            foreach ([
                'DELETE FROM assignments WHERE occurrence_id IN (SELECT id FROM event_occurrences WHERE event_id = :event_id)',
                'DELETE FROM event_occurrences WHERE event_id = :event_id',
                'DELETE FROM event_campuses WHERE event_id = :event_id',
                'DELETE FROM events WHERE id = :event_id',
            ] as $sql) {
                $stmt = $this->connection->prepare($sql);
                $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
                $stmt->execute();
                $affected = $stmt->rowCount();
                if (str_starts_with($sql, 'DELETE FROM events WHERE') && $affected === 0) {
                    $this->connection->rollBack();

                    return false;
                }
            }
            $this->connection->commit();

            return true;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function updateOccurrenceTimesBatch(int $eventId, array $rows): int
    {
        if ($this->connection === null || $rows === []) {
            return 0;
        }
        $this->connection->beginTransaction();
        try {
            // The event id is in the WHERE clause so an occurrence of another
            // event cannot be moved through this call, however its id arrived.
            $stmt = $this->connection->prepare(
                'UPDATE event_occurrences SET starts_at = :starts_at, ends_at = :ends_at
                  WHERE id = :id AND event_id = :event_id'
            );
            $updated = 0;
            foreach ($rows as $r) {
                $stmt->bindValue(':starts_at', $r['starts_at'], PDO::PARAM_STR);
                $stmt->bindValue(':ends_at', $r['ends_at'], PDO::PARAM_STR);
                $stmt->bindValue(':id', (int) $r['occurrence_id'], PDO::PARAM_INT);
                $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
                $stmt->execute();
                $updated += $stmt->rowCount();
            }
            $this->connection->commit();

            return $updated;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function deleteOccurrencesForEvent(int $eventId, array $occurrenceIds): int
    {
        $ids = $this->intIds($occurrenceIds);
        if ($this->connection === null || $ids === []) {
            return 0;
        }
        // One statement, one transaction: a half-deleted series leaves the
        // calendar showing some of the wrong times and none of the right ones.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->connection->beginTransaction();
        try {
            // Confirmed by the service when anybody was scheduled; the
            // assignments go with their dates rather than blocking the delete.
            $assigned = $this->connection->prepare(
                'DELETE a FROM assignments a
                   JOIN event_occurrences o ON o.id = a.occurrence_id
                  WHERE o.event_id = ? AND o.id IN (' . $placeholders . ')'
            );
            $assigned->execute(array_merge([$eventId], $ids));
            $stmt = $this->connection->prepare(
                'DELETE FROM event_occurrences WHERE event_id = ? AND id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$eventId], $ids));
            $deleted = $stmt->rowCount();
            $this->connection->commit();

            return $deleted;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function countAssignmentsForOccurrences(array $occurrenceIds): int
    {
        $ids = $this->intIds($occurrenceIds);
        if ($this->connection === null || $ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->connection->prepare(
            'SELECT COUNT(*) AS c FROM assignments WHERE occurrence_id IN (' . $placeholders . ')'
        );
        $stmt->execute($ids);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['c'];
    }

    /**
     * Ids as positive integers, deduplicated.
     *
     * These reach an IN() list built from their own count, so they are cast
     * here and bound positionally; nothing from the request is interpolated.
     *
     * @param array<int, int|string> $ids
     * @return list<int>
     */
    private function intIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    public function countAssignmentsForOccurrence(int $occurrenceId): int
    {
        if ($this->connection === null) {
            return 0;
        }
        $stmt = $this->connection->prepare('SELECT COUNT(*) AS c FROM assignments WHERE occurrence_id = :id');
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? 0 : (int) $row['c'];
    }

    /**
     * A date differs from its series when it was moved or says something of
     * its own. Derived, never stored, so it cannot disagree with the row.
     *
     * @param array<string,mixed> $row
     */
    private static function isModified(array $row): bool
    {
        return (string) ($row['starts_at'] ?? '') !== (string) ($row['original_starts_at'] ?? $row['starts_at'] ?? '')
            || ($row['title_override'] ?? '') !== ''
            || ($row['details_override'] ?? '') !== '';
    }

    /**
     * An event's first window as the event row stores it: a date, clock times
     * and an all-day flag. A window that starts and ends at midnight is all
     * day, which is how an all-day date has always been written.
     *
     * @return array{0:string,1:?string,2:?string,3:bool}
     */
    private static function splitWindow(string $start, string $end): array
    {
        $startsOn = substr($start, 0, 10);
        $startTime = strlen($start) > 10 ? substr($start, 11, 8) : '00:00:00';
        $endTime = strlen($end) > 10 ? substr($end, 11, 8) : '00:00:00';
        if (strlen($startTime) === 5) {
            $startTime .= ':00';
        }
        if (strlen($endTime) === 5) {
            $endTime .= ':00';
        }
        if ($startTime === '00:00:00' && $endTime === '00:00:00') {
            return [$startsOn, null, null, true];
        }

        return [$startsOn, $startTime, $endTime, false];
    }

    /** @param list<int>|array<int, int|string> $campusIds
      * @return list<int>
      */
    private function normalizeCampusIds(array $campusIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $campusIds),
            fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @param list<int> $campusIds campuses the event is relevant to
     * @param int|null  $hostCampusId the campus that physically hosts it
     */
    private function syncEventCampuses(int $eventId, array $campusIds, ?int $hostCampusId = null): void
    {
        $deleteStmt = $this->connection->prepare('DELETE FROM event_campuses WHERE event_id = :event_id');
        $deleteStmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $deleteStmt->execute();

        // A joint service can be *for* Scarborough but *held at* North York.
        // The host campus therefore needs a row of its own even when it is not
        // in the relevance list, or is_host lands on nothing and the "held at"
        // choice is silently discarded.
        if ($hostCampusId !== null && $hostCampusId > 0 && !in_array($hostCampusId, $campusIds, true)) {
            $campusIds[] = $hostCampusId;
        }

        if ($campusIds === []) {
            return;
        }

        $insertStmt = $this->connection->prepare(
            'INSERT INTO event_campuses (event_id, campus_id, is_host)
                  VALUES (:event_id, :campus_id, :is_host)'
        );
        foreach ($campusIds as $index => $campusId) {
            // Only an explicitly chosen host is recorded. Defaulting to the
            // first campus made every church-wide event claim to be "held at"
            // whichever campus happened to sort first, which is a fact nobody
            // entered.
            $isHost = $hostCampusId !== null && $campusId === $hostCampusId;
            $insertStmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            $insertStmt->bindValue(':campus_id', $campusId, PDO::PARAM_INT);
            $insertStmt->bindValue(':is_host', $isHost ? 1 : 0, PDO::PARAM_INT);
            $insertStmt->execute();
        }
    }
}
