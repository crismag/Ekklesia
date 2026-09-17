<?php

declare(strict_types=1);

namespace App\Adapters\ChurchCRM;

use App\Contracts\EventAdapter;
use DateTimeImmutable;
use PDO;

final class ChurchCrmEventAdapter implements EventAdapter
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
     * COALESCE(NULLIF(...), 'members') rather than an INNER JOIN on purpose:
     * events_event.event_type has carried dangling ids for years, and
     * ChurchCRM's own EditEventTypes.php can delete a type row at any time. An
     * INNER JOIN would make those events vanish for everyone, which is a
     * data-loss-shaped bug. The fallback is 'members', never 'leaders', so an
     * unclassified event stays visible to signed-in users and can never become
     * restricted by accident.
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
            'SELECT type_id FROM event_types WHERE portal_is_default = 1 LIMIT 1'
        );
        $row = $stmt === false || $stmt === null ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['type_id'];
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

        return 'COALESCE(NULLIF(et.portal_audience, ""), "members") IN (' . implode(', ', $keys) . ')';
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
            $campusJoin = 'JOIN events_event_campus fc ON fc.event_id = e.event_id AND fc.campus_id = :campus_id';
        }
        $sql = 'SELECT o.occurrence_id, o.occurrence_start, o.occurrence_end, o.is_cancelled,
                       COALESCE(NULLIF(o.override_title, ""), e.event_title) AS title,
                       e.event_id, e.event_desc, e.custom_location_name AS location_name, e.ministry_id,
                       e.event_type AS event_type_id, et.portal_slug AS type_slug,
                       et.portal_label AS type_label, et.portal_color AS type_color,
                       (SELECT COUNT(*) FROM event_occurrence oc WHERE oc.event_id = e.event_id) AS occurrence_count,
                       (SELECT GROUP_CONCAT(c.campus_name ORDER BY ec.is_host DESC, c.campus_name SEPARATOR ", ")
                          FROM events_event_campus ec JOIN church_campus c ON c.campus_id = ec.campus_id
                         WHERE ec.event_id = e.event_id) AS campus_names,
                       (SELECT c2.campus_name FROM events_event_campus ec2
                          JOIN church_campus c2 ON c2.campus_id = ec2.campus_id
                         WHERE ec2.event_id = e.event_id AND ec2.is_host = 1 LIMIT 1) AS host_campus_name
                  FROM event_occurrence o
                  JOIN events_event e ON e.event_id = o.event_id
             LEFT JOIN event_types et ON et.type_id = e.event_type
                       ' . $campusJoin . '
                 WHERE o.occurrence_start >= :from AND o.occurrence_start < :to
                   AND ' . $audienceExpr . '
              ORDER BY o.occurrence_start ' . ($descending ? 'DESC' : 'ASC') . ', title ASC
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
                'description' => $r['event_desc'] === null ? null : (string) $r['event_desc'],
                'starts_at' => (string) $r['occurrence_start'],
                'ends_at' => $r['occurrence_end'] === null ? null : (string) $r['occurrence_end'],
                'is_cancelled' => (int) ($r['is_cancelled'] ?? 0) === 1,
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
            'past' => 'eo.occurrence_start < CURDATE()',
            'all' => '1 = 1',
            'month', 'quarter' => 'eo.occurrence_start >= :win_from AND eo.occurrence_start < :win_to',
            default => 'eo.occurrence_start >= CURDATE()',
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
            $campusJoin = 'JOIN events_event_campus fc ON fc.event_id = e.event_id AND fc.campus_id = :campus_id';
            $bind[':campus_id'] = $campusId;
        }

        // The list is a management surface, so it needs enough to scan a whole
        // schedule: when it next runs and for how long, which campus it is for,
        // where it is held if that is not a campus, and how often it repeats.
        $sql = 'SELECT e.event_id AS event_id, e.event_title AS event_title, e.event_desc AS event_desc,
                       e.custom_location_name AS location_name,
                       e.ministry_id AS ministry_id,
                       e.event_type AS event_type_id, et.portal_slug AS type_slug,
                       et.portal_label AS type_label, et.portal_color AS type_color,
                       COUNT(eo.occurrence_id) AS occurrence_count,
                       MIN(eo.occurrence_start) AS next_occurrence_at,
                       (SELECT o2.occurrence_end FROM event_occurrence o2
                         WHERE o2.event_id = e.event_id AND o2.occurrence_start >= CURDATE()
                         ORDER BY o2.occurrence_start ASC LIMIT 1) AS next_occurrence_end,
                       (SELECT GROUP_CONCAT(c.campus_name ORDER BY ec.is_host DESC, c.campus_name SEPARATOR ", ")
                          FROM events_event_campus ec
                          JOIN church_campus c ON c.campus_id = ec.campus_id
                         WHERE ec.event_id = e.event_id) AS campus_names,
                       (SELECT c2.campus_name FROM events_event_campus ec2
                          JOIN church_campus c2 ON c2.campus_id = ec2.campus_id
                         WHERE ec2.event_id = e.event_id AND ec2.is_host = 1 LIMIT 1) AS host_campus_name
                  FROM events_event e
             LEFT JOIN event_occurrence eo ON eo.event_id = e.event_id AND ' . $window . '
             LEFT JOIN event_types et ON et.type_id = e.event_type
                   ' . $campusJoin . '
                 WHERE ' . $audienceExpr . '
              GROUP BY e.event_id, e.event_title, e.event_desc, e.custom_location_name, e.ministry_id,
                       e.event_type, et.portal_slug, et.portal_label, et.portal_color
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
                'event_title' => (string) $row['event_title'],
                'event_desc' => $row['event_desc'] === null ? null : (string) $row['event_desc'],
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
        $sql = 'SELECT e.event_id AS event_id, e.event_title AS event_title, e.event_desc AS event_desc,
                       COALESCE(e.is_multi_campus, 0) AS is_multi_campus,
                       e.ministry_id AS ministry_id,
                       e.event_type AS event_type_id, et.portal_slug AS type_slug,
                       et.portal_label AS type_label, et.portal_color AS type_color,
                       COALESCE(e.assignment_scheduling_enabled, 0) AS assignment_scheduling_enabled
                  FROM events_event e
             LEFT JOIN event_types et ON et.type_id = e.event_type
                 WHERE e.event_id = :event_id
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
            'SELECT occurrence_id, occurrence_start, occurrence_end,
                      is_cancelled, is_modified, override_title, override_desc
               FROM event_occurrence
              WHERE event_id = :event_id
                AND occurrence_start BETWEEN :start_at AND :end_at
              ORDER BY occurrence_start ASC'
        );
        $stmt2->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt2->bindValue(':start_at', $start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt2->bindValue(':end_at', $end->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt2->execute();
        $occ = [];
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $occ[] = [
                'occurrence_id' => (int) $r['occurrence_id'],
                'occurrence_start' => (string) $r['occurrence_start'],
                'occurrence_end' => (string) $r['occurrence_end'],
                'is_cancelled' => (int) ($r['is_cancelled'] ?? 0) === 1,
                // What this one date says, when it says something of its own.
                'is_modified' => (int) ($r['is_modified'] ?? 0) === 1,
                'override_title' => ($r['override_title'] ?? '') !== '' ? (string) $r['override_title'] : null,
                'override_desc' => ($r['override_desc'] ?? '') !== '' ? (string) $r['override_desc'] : null,
            ];
        }

        $campusIdsStmt = $this->connection->prepare(
            'SELECT campus_id
               FROM events_event_campus
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
            'SELECT campus_id AS id, campus_name AS name
               FROM church_campus
              WHERE is_active = 1
              ORDER BY is_main DESC, campus_name ASC, campus_id ASC'
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
            'event_title' => (string) $row['event_title'],
            'event_desc' => $row['event_desc'] === null ? null : (string) $row['event_desc'],
            'occurrences' => $occ,
            'campus_ids' => $campusIds,
            'available_campuses' => $availableCampuses,
            'is_multi_campus' => (bool) $row['is_multi_campus'],
            'ministry_id' => $row['ministry_id'] === null ? null : (int) $row['ministry_id'],
            'event_type_id' => (int) ($row['event_type_id'] ?? 0),
            'type_slug' => $row['type_slug'] === null ? null : (string) $row['type_slug'],
            'type_label' => $row['type_label'] === null ? null : (string) $row['type_label'],
            'type_color' => $row['type_color'] === null ? null : (string) $row['type_color'],
            'assignment_scheduling_enabled' => (int) ($row['assignment_scheduling_enabled'] ?? 0) === 1,
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
            'SELECT e.event_id AS event_id,
                    COALESCE(NULLIF(et.portal_audience, ""), "members") AS audience
               FROM event_occurrence o
               JOIN events_event e ON e.event_id = o.event_id
          LEFT JOIN event_types et ON et.type_id = e.event_type
              WHERE o.occurrence_id = :occurrence_id
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

        // event_start / event_end are NOT NULL with no default. This used to
        // insert NULL into both, so creation always failed on an integrity
        // constraint. The caller now supplies a real window.
        $start = (string) ($cmd['event_start'] ?? '');
        $end = (string) ($cmd['event_end'] ?? '');
        if ($start === '' || $end === '') {
            throw new \InvalidArgumentException('An event needs a start and end datetime.');
        }

        $sql = 'INSERT INTO events_event
                    (event_title, event_desc, event_start, event_end, is_multi_campus,
                     custom_location_name, custom_location_address, ministry_id, event_type,
                     assignment_scheduling_enabled)
                VALUES (:title, :desc, :start, :end, :is_multi_campus, :loc_name, :loc_addr, :ministry_id, :event_type,
                        :assignment_scheduling_enabled)';
        $stmt = $this->connection->prepare($sql);
        $this->connection->beginTransaction();
        try {
            $stmt->bindValue(':title', $cmd['title'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':desc', $cmd['description'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':start', $start, PDO::PARAM_STR);
            $stmt->bindValue(':end', $end, PDO::PARAM_STR);
            $stmt->bindValue(':is_multi_campus', count($campusIds) > 1 ? 1 : 0, PDO::PARAM_INT);
            // Location is not campus. These columns already existed and were
            // never written, so an event held somewhere other than its campus
            // had nowhere to say so.
            $stmt->bindValue(':loc_name', $cmd['location_name'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':loc_addr', $cmd['location_address'] ?? null, PDO::PARAM_STR);
            // NULL means church-wide, which is right for a holiday or Communion.
            $ministryId = isset($cmd['ministry_id']) && (int) $cmd['ministry_id'] > 0 ? (int) $cmd['ministry_id'] : null;
            $stmt->bindValue(':ministry_id', $ministryId, $ministryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            // event_type was missing from this INSERT entirely, so every event
            // the portal created landed with 0 — an id matching no event_types
            // row. That is why both the column and the table sat inert, and why
            // ChurchCRM's attendance counts never worked for portal events.
            // Falling back to the configured default keeps the audience join
            // meaningful instead of relying on the COALESCE safety net.
            $typeId = isset($cmd['event_type_id']) && (int) $cmd['event_type_id'] > 0
                ? (int) $cmd['event_type_id']
                : $this->defaultTypeId();
            $stmt->bindValue(':event_type', $typeId, PDO::PARAM_INT);
            $stmt->bindValue(
                ':assignment_scheduling_enabled',
                !empty($cmd['assignment_scheduling_enabled']) ? 1 : 0,
                PDO::PARAM_INT,
            );
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

        $sql = 'UPDATE events_event
                   SET event_title = :title,
                       event_desc = :desc,
                       is_multi_campus = :is_multi_campus,
                       event_type = :event_type,
                       ministry_id = :ministry_id,
                       assignment_scheduling_enabled = :assignment_scheduling_enabled
                 WHERE event_id = :event_id';
        $stmt = $this->connection->prepare($sql);
        $this->connection->beginTransaction();
        try {
            $stmt->bindValue(':title', $cmd['title'] ?? $current['event_title'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':desc', array_key_exists('description', $cmd) ? ($cmd['description'] ?? null) : ($current['event_desc'] ?? null), PDO::PARAM_STR);
            $stmt->bindValue(':is_multi_campus', count($campusIds) > 1 ? 1 : 0, PDO::PARAM_INT);
            // A null event_type_id means "leave it alone", not "clear it".
            $typeId = isset($cmd['event_type_id']) && (int) $cmd['event_type_id'] > 0
                ? (int) $cmd['event_type_id']
                : (int) ($current['event_type_id'] ?? 0);
            $stmt->bindValue(':event_type', $typeId, PDO::PARAM_INT);
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
            if (array_key_exists('assignment_scheduling_enabled', $cmd) && $cmd['assignment_scheduling_enabled'] !== null) {
                $stmt->bindValue(
                    ':assignment_scheduling_enabled',
                    $cmd['assignment_scheduling_enabled'] ? 1 : 0,
                    PDO::PARAM_INT,
                );
            } else {
                $stmt->bindValue(
                    ':assignment_scheduling_enabled',
                    !empty($current['assignment_scheduling_enabled']) ? 1 : 0,
                    PDO::PARAM_INT,
                );
            }
            $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            $ok = $stmt->execute();
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
            $sql = 'INSERT INTO event_occurrence (event_id, occurrence_start, occurrence_end) VALUES (:event_id, :start_at, :end_at)';
            $stmt = $this->connection->prepare($sql);
            foreach ($rows as $r) {
                $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
                $stmt->bindValue(':start_at', $r['occurrence_start'], PDO::PARAM_STR);
                $stmt->bindValue(':end_at', $r['occurrence_end'], PDO::PARAM_STR);
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
        $stmt = $this->connection->query(
            'SELECT t.tag_id, t.tag_slug, t.tag_label,
                    (SELECT COUNT(*) FROM event_tag_map m WHERE m.tag_id = t.tag_id) AS usage_count
               FROM event_tag t
           ORDER BY t.tag_label ASC'
        );

        return array_map(static fn (array $r): array => [
            'tag_id' => (int) $r['tag_id'],
            'slug' => (string) $r['tag_slug'],
            'label' => (string) $r['tag_label'],
            'usage_count' => (int) $r['usage_count'],
        ], $stmt?->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function tagsForEvent(int $eventId): array
    {
        if ($this->connection === null) {
            return [];
        }
        $stmt = $this->connection->prepare(
            'SELECT t.tag_id, t.tag_slug, t.tag_label
               FROM event_tag_map m
               JOIN event_tag t ON t.tag_id = m.tag_id
              WHERE m.event_id = :event_id
           ORDER BY t.tag_label ASC'
        );
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => [
            'tag_id' => (int) $r['tag_id'],
            'slug' => (string) $r['tag_slug'],
            'label' => (string) $r['tag_label'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function setEventTags(int $eventId, array $tags): array
    {
        if ($this->connection === null) {
            return [];
        }
        $this->connection->beginTransaction();
        try {
            $ids = [];
            foreach ($tags as $tag) {
                $slug = (string) $tag['slug'];
                $label = (string) $tag['label'];
                // The slug is what makes a tag one tag, so it is what the
                // lookup is keyed on. An existing tag keeps the label it was
                // first given: renaming every past use because somebody typed
                // it differently today would be a surprise, not a correction.
                $find = $this->connection->prepare('SELECT tag_id FROM event_tag WHERE tag_slug = :slug LIMIT 1');
                $find->bindValue(':slug', $slug, PDO::PARAM_STR);
                $find->execute();
                $row = $find->fetch(PDO::FETCH_ASSOC);
                if ($row !== false) {
                    $ids[] = (int) $row['tag_id'];
                    continue;
                }
                $ins = $this->connection->prepare(
                    'INSERT INTO event_tag (tag_slug, tag_label, created_at) VALUES (:slug, :label, NOW())'
                );
                $ins->bindValue(':slug', $slug, PDO::PARAM_STR);
                $ins->bindValue(':label', $label, PDO::PARAM_STR);
                $ins->execute();
                $ids[] = (int) $this->connection->lastInsertId();
            }

            // Replace rather than merge: the caller sent the complete list, and
            // an "add only" write gives no way to remove one.
            $del = $this->connection->prepare('DELETE FROM event_tag_map WHERE event_id = :event_id');
            $del->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            $del->execute();

            if ($ids !== []) {
                $map = $this->connection->prepare(
                    'INSERT INTO event_tag_map (event_id, tag_id) VALUES (:event_id, :tag_id)'
                );
                foreach (array_unique($ids) as $tagId) {
                    $map->bindValue(':event_id', $eventId, PDO::PARAM_INT);
                    $map->bindValue(':tag_id', $tagId, PDO::PARAM_INT);
                    $map->execute();
                }
            }

            // A tag nothing carries any more is not a tag. Leaving them behind
            // fills the picker with things somebody typed once by mistake.
            $this->connection->exec(
                'DELETE FROM event_tag WHERE tag_id NOT IN (SELECT tag_id FROM event_tag_map)'
            );

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
        $stmt = $this->connection->prepare(
            'SELECT m.event_id FROM event_tag_map m
               JOIN event_tag t ON t.tag_id = m.tag_id
              WHERE t.tag_slug = :slug'
        );
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function setOccurrenceOverrides(int $occurrenceId, ?string $title, ?string $desc): bool
    {
        if ($this->connection === null) {
            return false;
        }
        // is_modified is derived, never supplied. It exists so a reader can
        // tell "this date differs from its series" without comparing strings,
        // and the only way it stays true is if one statement owns both.
        $modified = ($title !== null && $title !== '') || ($desc !== null && $desc !== '');
        $stmt = $this->connection->prepare(
            'UPDATE event_occurrence
                SET override_title = :title,
                    override_desc = :desc,
                    is_modified = :modified
              WHERE occurrence_id = :id'
        );
        $stmt->bindValue(':title', $title === '' ? null : $title,
            $title === null || $title === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':desc', $desc === '' ? null : $desc,
            $desc === null || $desc === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':modified', $modified ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function findOccurrence(int $occurrenceId): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        $stmt = $this->connection->prepare(
            'SELECT occurrence_id, event_id, occurrence_start, occurrence_end,
                    is_cancelled, is_modified, override_title, override_desc
               FROM event_occurrence WHERE occurrence_id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function setOccurrenceCancelled(int $occurrenceId, bool $cancelled): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare(
            'UPDATE event_occurrence SET is_cancelled = :cancelled WHERE occurrence_id = :id'
        );
        $stmt->bindValue(':cancelled', $cancelled ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteOccurrence(int $occurrenceId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare('DELETE FROM event_occurrence WHERE occurrence_id = :id');
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function listEventOccurrences(int $eventId): array
    {
        if ($this->connection === null) {
            return [];
        }
        $stmt = $this->connection->prepare(
            'SELECT occurrence_id, occurrence_start, occurrence_end
               FROM event_occurrence
              WHERE event_id = :event_id
              ORDER BY occurrence_start ASC'
        );
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'occurrence_id' => (int) $row['occurrence_id'],
                'occurrence_start' => (string) $row['occurrence_start'],
                'occurrence_end' => (string) $row['occurrence_end'],
            ];
        }

        return $out;
    }

    public function updateOccurrenceTimes(int $occurrenceId, string $start, string $end): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare(
            'UPDATE event_occurrence SET occurrence_start = :start_at, occurrence_end = :end_at
              WHERE occurrence_id = :id'
        );
        $stmt->bindValue(':start_at', $start, PDO::PARAM_STR);
        $stmt->bindValue(':end_at', $end, PDO::PARAM_STR);
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Remove an event and everything hanging off it.
     *
     * Occurrences and campus links are removed here rather than left to a
     * foreign key: the campus link table is written by this adapter and the
     * occurrence rows are what the calendar reads, so an event deleted without
     * them would vanish from the events list and go on appearing in the
     * calendar.
     *
     * Assignments are the caller's problem — the service refuses to reach this
     * method while any exist unless the deletion was explicitly confirmed.
     */
    public function saveRecurrence(int $eventId, ?array $rule): void
    {
        if ($this->connection === null) {
            return;
        }
        // Replace rather than upsert. event_recurrence is keyed by event_id
        // alone, so one delete plus at most one insert says exactly what is
        // meant — including "this event no longer repeats", which an upsert
        // cannot express.
        $this->connection->beginTransaction();
        try {
            $del = $this->connection->prepare('DELETE FROM event_recurrence WHERE event_id = :event_id');
            $del->bindValue(':event_id', $eventId, PDO::PARAM_INT);
            $del->execute();

            if ($rule !== null) {
                $stmt = $this->connection->prepare(
                    'INSERT INTO event_recurrence
                        (event_id, recurrence_type, recurrence_interval, recurrence_days_of_week,
                         recurrence_week_of_month, recurrence_until, recurrence_count,
                         created_at, updated_at)
                     VALUES (:event_id, :type, :interval, :dow, :week, :until, :cnt, NOW(), NOW())'
                );
                $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
                $stmt->bindValue(':type', (string) $rule['recurrence_type'], PDO::PARAM_STR);
                $stmt->bindValue(':interval', (int) ($rule['recurrence_interval'] ?? 1), PDO::PARAM_INT);
                $stmt->bindValue(':dow', $rule['recurrence_days_of_week'] ?? null,
                    $rule['recurrence_days_of_week'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':week', $rule['recurrence_week_of_month'] ?? null,
                    ($rule['recurrence_week_of_month'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':until', $rule['recurrence_until'] ?? null,
                    ($rule['recurrence_until'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':cnt', $rule['recurrence_count'] ?? null,
                    ($rule['recurrence_count'] ?? null) === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->execute();
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function findRecurrence(int $eventId): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        $stmt = $this->connection->prepare('SELECT * FROM event_recurrence WHERE event_id = :event_id LIMIT 1');
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function deleteEvent(int $eventId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $this->connection->beginTransaction();
        try {
            foreach ([
                'DELETE FROM assignment WHERE occurrence_id IN (SELECT occurrence_id FROM event_occurrence WHERE event_id = :event_id)',
                'DELETE FROM event_occurrence WHERE event_id = :event_id',
                'DELETE FROM events_event_campus WHERE event_id = :event_id',
                'DELETE FROM events_event WHERE event_id = :event_id',
            ] as $sql) {
                $stmt = $this->connection->prepare($sql);
                $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
                $stmt->execute();
                $affected = $stmt->rowCount();
                if (str_contains($sql, 'events_event WHERE') && $affected === 0) {
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
                'UPDATE event_occurrence SET occurrence_start = :start_at, occurrence_end = :end_at
                  WHERE occurrence_id = :id AND event_id = :event_id'
            );
            $updated = 0;
            foreach ($rows as $r) {
                $stmt->bindValue(':start_at', $r['occurrence_start'], PDO::PARAM_STR);
                $stmt->bindValue(':end_at', $r['occurrence_end'], PDO::PARAM_STR);
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
            $stmt = $this->connection->prepare(
                'DELETE FROM event_occurrence WHERE event_id = ? AND occurrence_id IN (' . $placeholders . ')'
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
            'SELECT COUNT(*) AS c FROM assignment WHERE occurrence_id IN (' . $placeholders . ')'
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
        $stmt = $this->connection->prepare('SELECT COUNT(*) AS c FROM assignment WHERE occurrence_id = :id');
        $stmt->bindValue(':id', $occurrenceId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? 0 : (int) $row['c'];
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

    /** @param list<int> $campusIds */
    /**
     * @param list<int> $campusIds campuses the event is relevant to
     * @param int|null  $hostCampusId the campus that physically hosts it
     */
    private function syncEventCampuses(int $eventId, array $campusIds, ?int $hostCampusId = null): void
    {
        $deleteStmt = $this->connection->prepare('DELETE FROM events_event_campus WHERE event_id = :event_id');
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
            'INSERT INTO events_event_campus (event_id, campus_id, is_host)
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
