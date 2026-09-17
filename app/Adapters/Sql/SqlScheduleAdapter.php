<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\ScheduleAdapter;
use App\DTO\Schedules\AssignmentBatchCommand;
use DateTimeImmutable;
use PDO;

/**
 * Serving schedule data adapter.
 *
 * Owns all SQL for the serving schedule (serving_roles, assignments,
 * event_occurrences, people, ministry_members, campuses). This is the ONLY
 * layer below services that is permitted to know those table names.
 *
 * A different store is bound via the service provider; nothing above this
 * layer changes.
 */
final class SqlScheduleAdapter implements ScheduleAdapter
{
    public function __construct(
        private readonly ?PDO $connection = null,
    ) {
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function listRolesForMinistry(int $ministryId): array
    {
        if ($this->connection === null) {
            return [];
        }

        $stmt = $this->connection->prepare(
            'SELECT id, name
               FROM serving_roles
              WHERE ministry_id = :ministry_id
                AND is_active = 1
              ORDER BY sort_order ASC, name ASC'
        );
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        return $rows;
    }

    /**
     * @return list<array{id:int,display_name:string}>
     */
    public function listMinistryMembers(int $ministryId, array $campusIds = []): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusIds = $this->normalizeCampusIds($campusIds);

        // Who may be scheduled: the ministry's confirmed members. A pending
        // request to join is not yet membership, and an ended one no longer is.
        $sql = 'SELECT DISTINCT
                    p.id AS id,
                    TRIM(CONCAT_WS(\' \', p.first_name, p.last_name)) AS display_name,
                    p.last_name, p.first_name
                FROM ministry_members mm
                INNER JOIN people p ON p.id = mm.person_id';

        $params = [':ministry_id' => $ministryId];

        $sql .= ' WHERE mm.ministry_id = :ministry_id
                    AND mm.status = \'confirmed\'';

        if ($campusIds !== []) {
            [$campusSql, $campusParams] = $this->buildCampusInList($campusIds, 'member_campus_');
            $sql .= ' AND p.campus_id IN (' . $campusSql . ')';
            $params += $campusParams;
        }

        $sql .= ' ORDER BY p.last_name ASC, p.first_name ASC';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'display_name' => (string) $row['display_name'],
            ];
        }
        return $rows;
    }

    /**
     * @return list<array{id:int,display_name:string}>
     */
    public function listSpecialCandidates(int $ministryId, array $campusIds = []): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusIds = $this->normalizeCampusIds($campusIds);

        $sql = 'SELECT DISTINCT
                    p.id AS id,
                    TRIM(CONCAT_WS(\' \', p.first_name, p.last_name)) AS display_name,
                    p.last_name, p.first_name
                FROM people p';

        $params = [':ministry_id' => $ministryId];

        $sql .= ' WHERE NOT EXISTS (
                    SELECT 1
                      FROM ministry_members mm
                     WHERE mm.ministry_id = :ministry_id
                       AND mm.person_id = p.id
                       AND mm.status = \'confirmed\'
                  )';

        if ($campusIds !== []) {
            [$campusSql, $campusParams] = $this->buildCampusInList($campusIds, 'special_campus_');
            $sql .= ' AND p.campus_id IN (' . $campusSql . ')';
            $params += $campusParams;
        }

        $sql .= ' ORDER BY p.last_name ASC, p.first_name ASC';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'display_name' => (string) $row['display_name'],
            ];
        }
        return $rows;
    }

    /**
     * @return list<array{
     *   id:int,
     *   occurrence_id:int,
     *   person_id:int,
     *   serving_role_id:int,
    *   starts_on:DateTimeImmutable,
    *   label:string,
    *   display_name:string
     * }>
     */
    public function listAssignmentsInRange(int $ministryId, DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], ?array $eventIds = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        if ($eventIds !== null && $eventIds === []) {
            return [];
        }

        $campusIds = $this->normalizeCampusIds($campusIds);

        // An assignment is a person or an open role. The label is always empty:
        // free-text assignee names are not kept.
        $sql = 'SELECT a.id                 AS id,
                        a.occurrence_id      AS occurrence_id,
                        a.person_id          AS person_id,
                        a.serving_role_id    AS serving_role_id,
                        eo.starts_at         AS starts_on,
                        \'\'                   AS label,
                        CASE
                            WHEN a.person_id IS NOT NULL THEN TRIM(CONCAT_WS(\' \', p.first_name, p.last_name))
                            ELSE \'\'
                        END AS display_name
                   FROM assignments a
                   INNER JOIN event_occurrences eo ON eo.id = a.occurrence_id
                   INNER JOIN serving_roles r      ON r.id  = a.serving_role_id
                   LEFT JOIN people p              ON p.id  = a.person_id
                  WHERE r.ministry_id = :ministry_id
                    AND eo.starts_at >= :start_at
                    AND eo.starts_at <  :end_at';

        $predicateParams = [];
        if ($campusIds !== []) {
            [$predicate, $campusParams] = $this->eventCampusPredicate('eo.event_id', $campusIds, 'assignment_campus_');
            $sql .= $predicate;
            $predicateParams = $campusParams;
        }
        if ($eventIds !== null) {
            [$predicate, $eventParams] = $this->eventIdPredicate('eo.event_id', $eventIds, 'assignment_event_');
            $sql .= $predicate;
            $predicateParams = array_merge($predicateParams, $eventParams);
        }

        $sql .= ' ORDER BY eo.starts_at ASC, r.sort_order ASC, a.id ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':start_at', $start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':end_at', $end->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        foreach ($predicateParams ?? [] as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id'            => (int) $row['id'],
                'occurrence_id' => (int) $row['occurrence_id'],
                'person_id'     => $row['person_id'] === null ? 0 : (int) $row['person_id'],
                'serving_role_id' => (int) $row['serving_role_id'],
                'starts_on'     => new DateTimeImmutable((string) $row['starts_on']),
                'label'         => (string) $row['label'],
                'display_name'  => (string) $row['display_name'],
            ];
        }
        return $rows;
    }

    /**
     * @return list<array{
     *   id:int,
     *   event_id:int,
     *   event_title:string,
     *   starts_on:DateTimeImmutable,
     *   ends_on:DateTimeImmutable
     * }>
     */
    public function listOccurrencesInRange(DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], ?array $eventIds = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        if ($eventIds !== null && $eventIds === []) {
            return [];
        }

        $campusIds = $this->normalizeCampusIds($campusIds);

        $sql = 'SELECT eo.id                 AS id,
                    eo.event_id           AS event_id,
                    COALESCE(ee.title, \'\') AS event_title,
                    eo.starts_at          AS starts_on,
                    eo.ends_at            AS ends_on
               FROM event_occurrences eo
               LEFT JOIN events ee ON ee.id = eo.event_id
              WHERE eo.starts_at >= :start_at
                AND eo.starts_at <  :end_at';

        $predicateParams = [];
        if ($campusIds !== []) {
            [$predicate, $campusParams] = $this->eventCampusPredicate('eo.event_id', $campusIds, 'occurrence_campus_');
            $sql .= $predicate;
            $predicateParams = $campusParams;
        }
        if ($eventIds !== null) {
            [$predicate, $eventParams] = $this->eventIdPredicate('eo.event_id', $eventIds, 'occurrence_event_');
            $sql .= $predicate;
            $predicateParams = array_merge($predicateParams, $eventParams);
        }

        $sql .= ' ORDER BY eo.starts_at ASC, eo.id ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':start_at', $start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':end_at',   $end->format('Y-m-d H:i:s'),   PDO::PARAM_STR);
        foreach ($predicateParams ?? [] as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id'          => (int) $row['id'],
                'event_id'    => (int) $row['event_id'],
                'event_title' => (string) $row['event_title'],
                'starts_on'   => new DateTimeImmutable((string) $row['starts_on']),
                'ends_on'     => new DateTimeImmutable((string) $row['ends_on']),
            ];
        }
        return $rows;
    }

    /**
     * @param list<int> $campusIds
     * @return list<array{id:int,title:string,is_default:bool}>
     */
    public function listEligibleSchedulingEvents(array $campusIds = []): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusIds = $this->normalizeCampusIds($campusIds);
        $defaults = array_fill_keys($this->listDefaultSchedulingEventIds($campusIds), true);

        $sql = 'SELECT e.id AS id,
                       COALESCE(e.title, \'\') AS title
                  FROM events e
                 WHERE e.uses_serving_schedule = 1';

        $predicateParams = [];
        if ($campusIds !== []) {
            [$predicate, $predicateParams] = $this->eventCampusPredicate('e.id', $campusIds, 'eligible_campus_');
            $sql .= $predicate;
        }

        $sql .= ' ORDER BY e.title ASC, e.id ASC';

        $stmt = $this->connection->prepare($sql);
        foreach ($predicateParams as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['id'];
            $rows[] = [
                'id' => $id,
                'title' => (string) $row['title'],
                'is_default' => isset($defaults[$id]),
            ];
        }

        return $rows;
    }

    /**
     * Everything with an occurrence in the window, on this campus.
     *
     * The picker's source. It is not filtered by uses_serving_schedule:
     * that flag decides which event a campus opens with, and using it to gate
     * the search meant the only thing on offer was the thing already chosen.
     *
     * Joined through event_occurrences rather than events alone, so an
     * event that exists but never happens in the chosen weeks is not offered —
     * picking it would add an empty column to the grid.
     *
     * @param list<int> $campusIds
     * @return list<array{id:int,title:string,is_default:bool}>
     */
    public function listSchedulableEventsInRange(
        array $campusIds,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        if ($this->connection === null) {
            return [];
        }

        $campusIds = $this->normalizeCampusIds($campusIds);
        $defaults = array_fill_keys($this->listDefaultSchedulingEventIds($campusIds), true);

        // Half-open on whole days, the same window the grid itself uses: a
        // closed end would drop everything happening on the last date.
        $from = $start->setTime(0, 0, 0);
        $until = $end->setTime(0, 0, 0)->modify('+1 day');

        $sql = 'SELECT DISTINCT e.id AS id,
                       COALESCE(e.title, \'\') AS title
                  FROM events e
            INNER JOIN event_occurrences eo ON eo.event_id = e.id
                 WHERE eo.starts_at >= :range_start
                   AND eo.starts_at <  :range_end';

        $predicateParams = [];
        if ($campusIds !== []) {
            [$predicate, $predicateParams] = $this->eventCampusPredicate('e.id', $campusIds, 'inrange_campus_');
            $sql .= $predicate;
        }

        $sql .= ' ORDER BY e.title ASC, e.id ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':range_start', $from->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':range_end', $until->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        foreach ($predicateParams as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['id'];
            $rows[] = [
                'id' => $id,
                'title' => (string) $row['title'],
                'is_default' => isset($defaults[$id]),
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $campusIds
     * @return list<int>
     */
    public function listDefaultSchedulingEventIds(array $campusIds = []): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusIds = $this->normalizeCampusIds($campusIds);
        $sql = 'SELECT default_scheduling_event_id AS event_id
                  FROM campuses
                 WHERE default_scheduling_event_id IS NOT NULL
                   AND default_scheduling_event_id > 0';
        $params = [];
        if ($campusIds !== []) {
            [$campusSql, $params] = $this->buildCampusInList($campusIds, 'default_campus_');
            $sql .= ' AND id IN (' . $campusSql . ')';
        }

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['event_id'];
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @return list<array{
     *   id:int,
     *   person_id:int,
     *   serving_role_id:int,
     *   role_name:string,
     *   ministry_id:int,
     *   ministry_name:string,
     *   event_id:int,
     *   event_title:string,
     *   starts_on:DateTimeImmutable,
     *   ends_on:DateTimeImmutable,
     *   status:string
     * }>
     */
    public function listAssignmentsForPerson(int $personId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($this->connection === null) {
            return [];
        }

        $stmt = $this->connection->prepare(
            'SELECT a.id                       AS id,
                    a.person_id                AS person_id,
                    a.serving_role_id          AS serving_role_id,
                    r.name                     AS role_name,
                    r.ministry_id              AS ministry_id,
                    COALESCE(g.name, \'\')     AS ministry_name,
                    eo.event_id                AS event_id,
                    COALESCE(ee.title, \'\')   AS event_title,
                    eo.starts_at               AS starts_on,
                    eo.ends_at                 AS ends_on,
                    a.status                   AS status
               FROM assignments a
               INNER JOIN event_occurrences eo ON eo.id = a.occurrence_id
               INNER JOIN serving_roles r      ON r.id  = a.serving_role_id
               LEFT  JOIN events ee            ON ee.id = eo.event_id
               LEFT  JOIN ministries g         ON g.id  = r.ministry_id
              WHERE a.person_id = :person_id
                AND eo.starts_at >= :start_at
                AND eo.starts_at <  :end_at
              ORDER BY eo.starts_at ASC, r.sort_order ASC, a.id ASC'
        );
        $stmt->bindValue(':person_id', $personId, PDO::PARAM_INT);
        $stmt->bindValue(':start_at', $start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':end_at', $end->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'person_id' => (int) $row['person_id'],
                'serving_role_id' => (int) $row['serving_role_id'],
                'role_name' => (string) $row['role_name'],
                'ministry_id' => (int) $row['ministry_id'],
                'ministry_name' => (string) $row['ministry_name'],
                'event_id' => (int) $row['event_id'],
                'event_title' => (string) $row['event_title'],
                'starts_on' => new DateTimeImmutable((string) $row['starts_on']),
                'ends_on' => new DateTimeImmutable((string) $row['ends_on']),
                'status' => (string) $row['status'],
            ];
        }
        return $rows;
    }

    /**
     * All assignments in a date range across every ministry (optionally filtered
     * to a set), enriched with ministry, event, role and person — for the public
     * schedule board grouped by date → ministry. Dates returned as ATOM strings.
     *
     * @param list<int> $campusIds
     * @param list<int> $ministryIds
     * @return list<array<string,mixed>>
     */
    public function listScheduleBoard(DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], array $ministryIds = []): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusIds = $this->normalizeCampusIds($campusIds);

        $sql = 'SELECT a.id                  AS id,
                       a.occurrence_id       AS occurrence_id,
                       a.person_id           AS person_id,
                       CASE WHEN a.person_id IS NOT NULL
                            THEN TRIM(CONCAT_WS(\' \', p.first_name, p.last_name))
                            ELSE \'\' END AS person_name,
                       a.serving_role_id     AS serving_role_id,
                       r.name                AS role_name,
                       r.sort_order          AS role_sort_order,
                       r.ministry_id         AS ministry_id,
                       COALESCE(g.name, \'\') AS ministry_name,
                       eo.event_id           AS event_id,
                       COALESCE(ee.title, \'\') AS event_title,
                       eo.starts_at          AS starts_on,
                       eo.ends_at            AS ends_on
                  FROM assignments a
                  INNER JOIN event_occurrences eo ON eo.id = a.occurrence_id
                  INNER JOIN serving_roles r      ON r.id  = a.serving_role_id
                  LEFT  JOIN people p             ON p.id  = a.person_id
                  LEFT  JOIN events ee            ON ee.id = eo.event_id
                  LEFT  JOIN ministries g         ON g.id  = r.ministry_id
                 WHERE eo.starts_at >= :start_at
                   AND eo.starts_at <  :end_at';

        $params = [];
        $ministryIds = array_values(array_unique(array_filter(array_map('intval', $ministryIds), static fn ($x): bool => $x > 0)));
        if ($ministryIds !== []) {
            $ph = [];
            foreach ($ministryIds as $i => $mid) { $ph[] = ':bm' . $i; $params[':bm' . $i] = $mid; }
            $sql .= ' AND r.ministry_id IN (' . implode(', ', $ph) . ')';
        }

        $predicateParams = [];
        if ($campusIds !== []) {
            [$predicate, $predicateParams] = $this->eventCampusPredicate('eo.event_id', $campusIds, 'board_campus_');
            $sql .= $predicate;
        }

        $sql .= ' ORDER BY eo.starts_at ASC, g.name ASC, r.sort_order ASC, a.id ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':start_at', $start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':end_at',   $end->format('Y-m-d H:i:s'),   PDO::PARAM_STR);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v, PDO::PARAM_INT); }
        foreach ($predicateParams as $k => $v) { $stmt->bindValue($k, $v, PDO::PARAM_INT); }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'occurrence_id' => (int) $row['occurrence_id'],
                'event_id'      => (int) $row['event_id'],
                'event_title'   => (string) $row['event_title'],
                'starts_on'     => (new DateTimeImmutable((string) $row['starts_on']))->format(DATE_ATOM),
                'ends_on'       => (new DateTimeImmutable((string) $row['ends_on']))->format(DATE_ATOM),
                'ministry_id'   => (int) $row['ministry_id'],
                'ministry_name' => (string) $row['ministry_name'],
                'serving_role_id' => (int) $row['serving_role_id'],
                'role_name'     => (string) $row['role_name'],
                'person_id'     => $row['person_id'] === null ? null : (int) $row['person_id'],
                'person_name'   => (string) $row['person_name'],
            ];
        }

        return $rows;
    }

    /**
     * Persist the desired-state batch.
     *
     * If the command provides $start/$end, this method performs a full diff
     * of (ministry, [start,end)): existing rows in that window that aren't
     * present in $command->assignments are deleted; new rows are inserted;
     * matching rows with a changed person_id are updated.
     *
     * If $start/$end are null, only inserts/updates run (legacy/test path).
     *
     * @return array{saved: bool, assignment_count: int}
     */
    public function persistAssignmentBatch(AssignmentBatchCommand $command): array
    {
        if ($this->connection === null) {
            return ['saved' => true, 'assignment_count' => count($command->assignments)];
        }

        $useDiff = $command->start !== null && $command->end !== null;
        $eventIds = $command->eventIds === null ? null : $this->normalizeCampusIds($command->eventIds);
        if ($eventIds === []) {
            // Explicit empty event scope: insert/update the payload, but never
            // treat the rest of the date window as leftovers to delete.
            $useDiff = false;
        }

        // Pre-load the current window of assignments so we can diff in PHP.
        // SELECT FOR UPDATE keeps the diff atomic against concurrent edits.
        //
        // Multiple assignments are permitted per (occurrence_id, serving_role_id)
        // — the "extra assignees" pattern (one primary assignee plus one or
        // more helpers in the same role). We match desired rows in two passes:
        //
        //   pass 1: id-matched (most specific). These can't be ambiguous.
        //   pass 2: key-matched. Queue per (occ, role) key, popped per match
        //           so two desired rows for the same key consume two distinct
        //           existing rows.
        //
        // Untouched existing rows are deleted at the end.
        /** @var array<int, array<string, mixed>> */
        $existingById  = [];

        $this->connection->beginTransaction();
        try {
            if ($useDiff) {
                $saveCampusIds = $this->normalizeCampusIds(
                    $command->campusIds !== [] ? $command->campusIds : ($command->campusId !== null ? [$command->campusId] : [])
                );
                $saveSql = 'SELECT a.id                 AS id,
                            a.occurrence_id      AS occurrence_id,
                            a.person_id          AS person_id,
                            a.serving_role_id    AS serving_role_id
                       FROM assignments a
                       INNER JOIN event_occurrences eo ON eo.id = a.occurrence_id
                       INNER JOIN serving_roles r      ON r.id  = a.serving_role_id
                      WHERE r.ministry_id = :ministry_id
                        AND eo.starts_at >= :start_at
                        AND eo.starts_at <  :end_at';
                $saveParams = [];
                if ($saveCampusIds !== []) {
                    [$predicate, $campusParams] = $this->eventCampusPredicate('eo.event_id', $saveCampusIds, 'save_campus_');
                    $saveSql .= $predicate;
                    $saveParams = $campusParams;
                }
                if ($eventIds !== null) {
                    [$predicate, $eventParams] = $this->eventIdPredicate('eo.event_id', $eventIds, 'save_event_');
                    $saveSql .= $predicate;
                    $saveParams = array_merge($saveParams, $eventParams);
                }
                $saveSql .= ' FOR UPDATE';
                $sel = $this->connection->prepare($saveSql);
                $sel->bindValue(':ministry_id', $command->ministryId, PDO::PARAM_INT);
                $sel->bindValue(':start_at', $command->start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
                $sel->bindValue(':end_at',   $command->end->format('Y-m-d H:i:s'),   PDO::PARAM_STR);
                foreach ($saveParams as $key => $value) {
                    $sel->bindValue($key, $value, PDO::PARAM_INT);
                }
                $sel->execute();
                foreach ($sel->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $row = [
                        'id'            => (int) $r['id'],
                        'occurrence_id' => (int) $r['occurrence_id'],
                        'person_id'     => $r['person_id'] === null ? 0 : (int) $r['person_id'],
                        'serving_role_id' => (int) $r['serving_role_id'],
                    ];
                    $existingById[$row['id']] = $row;
                }
            }

            /** @var array<int, true> ids that survive the diff (insert or matched-update) */
            $touchedIds = [];

            $insert = $this->connection->prepare(
                'INSERT INTO assignments
                     (occurrence_id, serving_role_id, person_id, status, assigned_at)
                  VALUES
                     (:occ, :role, :person, :status, :now)'
            );
            $update = $this->connection->prepare(
                'UPDATE assignments
                    SET person_id = :person,
                        status = :status,
                        assigned_at = :now
                  WHERE id = :id'
            );

            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

            // Pass 1: id-matched updates. We process these first so the key
            // queues for pass 2 only contain rows that haven't been claimed
            // by an explicit id reference in the batch.
            $idLessRows = [];
            foreach ($command->assignments as $a) {
                if ($a->occurrenceId === null) {
                    throw new \RuntimeException('AssignmentBatchCommand requires occurrenceId on each assignment for persistence.');
                }
                if ($a->id === null) {
                    $idLessRows[] = $a;
                    continue;
                }
                $existing = $existingById[$a->id] ?? null;
                if ($existing === null) {
                    // Caller passed an id we no longer have (concurrent delete?).
                    // Fall through to pass 2 by treating it as id-less.
                    $idLessRows[] = $a;
                    continue;
                }
                $this->upsertExisting($update, $existing, $a, $now);
                $touchedIds[$existing['id']] = true;
            }

            // Pass 2: build per-key queues from un-claimed existing rows,
            // then process id-less desired rows.
            /** @var array<string, list<array<string, mixed>>> */
            $existingByKey = [];
            foreach ($existingById as $id => $row) {
                if (isset($touchedIds[$id])) continue;
                $key = $row['occurrence_id'] . ':' . $row['serving_role_id'];
                $existingByKey[$key] ??= [];
                $existingByKey[$key][] = $row;
            }

            foreach ($idLessRows as $a) {
                $key = $a->occurrenceId . ':' . $a->roleId;
                $personId = $a->personId > 0 ? $a->personId : null;
                $status   = $personId !== null ? 'assigned' : 'open';

                if (!empty($existingByKey[$key])) {
                    $existing = array_shift($existingByKey[$key]);
                    $this->upsertExisting($update, $existing, $a, $now);
                    $touchedIds[$existing['id']] = true;
                } else {
                    $insert->bindValue(':occ',    $a->occurrenceId, PDO::PARAM_INT);
                    $insert->bindValue(':role',   $a->roleId,       PDO::PARAM_INT);
                    $insert->bindValue(':person', $personId, $personId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $insert->bindValue(':status', $status,          PDO::PARAM_STR);
                    $insert->bindValue(':now',    $now,             PDO::PARAM_STR);
                    $insert->execute();
                    $touchedIds[(int) $this->connection->lastInsertId()] = true;
                }
            }

            if ($useDiff) {
                $del = $this->connection->prepare('DELETE FROM assignments WHERE id = :id');
                foreach ($existingById as $id => $_row) {
                    if (isset($touchedIds[$id])) continue;
                    $del->bindValue(':id', $id, PDO::PARAM_INT);
                    $del->execute();
                }
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return [
            'saved'            => true,
            'assignment_count' => count($command->assignments),
        ];
    }

    /**
     * Update one row only when something actually changed (no-op the UPDATE
     * otherwise so we don't churn assigned_at on every save).
     *
     * @param array<string, mixed> $existing
     */
    private function upsertExisting(\PDOStatement $update, array $existing, \App\DTO\Schedules\ScheduleAssignment $a, string $now): void
    {
        $personId = $a->personId > 0 ? $a->personId : null;
        $status   = $personId !== null ? 'assigned' : 'open';
        if ($existing['person_id'] === ($personId ?? 0)) {
            return; // unchanged
        }
        $update->bindValue(':person', $personId, $personId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $update->bindValue(':status', $status,   PDO::PARAM_STR);
        $update->bindValue(':now',    $now,      PDO::PARAM_STR);
        $update->bindValue(':id',     $existing['id'], PDO::PARAM_INT);
        $update->execute();
    }

    /**
     * @param list<int> $campusIds
     * @return array{0:string,1:array<string,int>}
     */
    private function eventCampusPredicate(string $eventIdSql, array $campusIds, string $placeholderPrefix): array
    {
        [$campusSql, $params] = $this->buildCampusInList($campusIds, $placeholderPrefix);

        return [' AND (
                    NOT EXISTS (
                        SELECT 1
                          FROM event_campuses eec_any
                         WHERE eec_any.event_id = ' . $eventIdSql . '
                    )
                    OR EXISTS (
                        SELECT 1
                          FROM event_campuses eec_match
                         WHERE eec_match.event_id = ' . $eventIdSql . '
                           AND eec_match.campus_id IN (' . $campusSql . ')
                    )
                )', $params];
    }

    /**
     * @param list<int> $eventIds
     * @return array{0:string,1:array<string,int>}
     */
    private function eventIdPredicate(string $eventIdSql, array $eventIds, string $placeholderPrefix): array
    {
        $eventIds = $this->normalizeCampusIds($eventIds);
        if ($eventIds === []) {
            return [' AND 1=0', []];
        }

        [$eventSql, $params] = $this->buildCampusInList($eventIds, $placeholderPrefix);

        return [' AND ' . $eventIdSql . ' IN (' . $eventSql . ')', $params];
    }

    /** @param list<int>|array<int, int|string> $campusIds */
    private function normalizeCampusIds(array $campusIds): array
    {
        $normalized = [];
        foreach ($campusIds as $campusId) {
            $campusId = (int) $campusId;
            if ($campusId <= 0) {
                continue;
            }
            $normalized[$campusId] = $campusId;
        }

        return array_values($normalized);
    }

    /**
     * @param list<int> $campusIds
     * @return array{0:string,1:array<string,int>}
     */
    private function buildCampusInList(array $campusIds, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach ($this->normalizeCampusIds($campusIds) as $index => $campusId) {
            $placeholder = ':' . $prefix . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $campusId;
        }

        return [implode(', ', $placeholders), $params];
    }

    /**
     * @param list<int> $occurrenceIds
     * @return list<array{person_id:int, grid_occurrence_id:int, conflict_label:string}>
     */
    public function listConflictsForOccurrences(int $ministryId, array $occurrenceIds): array
    {
        if ($this->connection === null || $occurrenceIds === []) {
            return [];
        }

        // Build distinct placeholder names — EMULATE_PREPARES=false requires
        // each named placeholder to appear exactly once.
        $placeholders = [];
        $params       = [];
        foreach ($occurrenceIds as $idx => $id) {
            $key            = ':occ' . $idx;
            $placeholders[] = $key;
            $params[$key]   = (int) $id;
        }
        $inSql = implode(', ', $placeholders);

        $stmt = $this->connection->prepare(
            'SELECT DISTINCT
                    a.person_id                              AS person_id,
                    current_eo.id                            AS grid_occurrence_id,
                    CONCAT(
                        COALESCE(g.name, \'\'),
                        CASE WHEN COALESCE(g.name, \'\') = \'\' THEN \'\' ELSE \' / \' END,
                        r.name,
                        CASE
                            WHEN a.occurrence_id = current_eo.id THEN \' (same occurrence)\'
                            ELSE \'\'
                        END
                    ) AS conflict_label
               FROM assignments a
               INNER JOIN event_occurrences other_eo   ON other_eo.id = a.occurrence_id
               INNER JOIN serving_roles r              ON r.id        = a.serving_role_id
               LEFT  JOIN ministries g                 ON g.id        = r.ministry_id
               INNER JOIN event_occurrences current_eo ON current_eo.id IN (' . $inSql . ')
              WHERE a.person_id IS NOT NULL
                AND (
                    (
                        a.occurrence_id <> current_eo.id
                        AND other_eo.starts_at < current_eo.ends_at
                        AND other_eo.ends_at   > current_eo.starts_at
                    )
                    OR (
                        a.occurrence_id = current_eo.id
                        AND r.ministry_id <> :ministry_id
                    )
                )
              ORDER BY current_eo.id ASC, a.person_id ASC'
        );

        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'person_id'          => (int) $row['person_id'],
                'grid_occurrence_id' => (int) $row['grid_occurrence_id'],
                'conflict_label'     => (string) $row['conflict_label'],
            ];
        }
        return $rows;
    }
}
