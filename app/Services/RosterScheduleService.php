<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use DateTimeImmutable;
use PDO;

/**
 * Roster schedules — non-event posted schedules (e.g. "MTE Pick-Up Schedule
 * Apr 27–May 1", "Sunday Potbless Volunteers"). Each roster is a container
 * that holds dated or DOW slots, and each slot holds 1+ assignees.
 *
 * Direct-PDO service: there's no real "alternate backend" to hide behind an
 * adapter — schedules live in the portal DB only — so we keep it flat to
 * minimise the file count.
 */
final readonly class RosterScheduleService
{
    /**
     * lst_ID = 13 holds the Member Type custom-field options (Radical /
     * Trailblazer / G&A) referenced by `person_custom.c1`. G&A is excluded by
     * default on the editor's people-pool filter chips.
     */
    public const MEMBER_TYPE_LIST_ID = 13;
    public const DEFAULT_EXCLUDED_MEMBER_TYPE = 'g&a';

    public function __construct(
        private PDO $portal,
        private ?PDO $churchcrm = null,
    ) {
    }

    // ---------- listing ----------

    /**
     * @return list<array<string,mixed>>
     */
    public function listRosters(ActorContext $actor, ?int $ministryId = null): array
    {
        $sql = 'SELECT * FROM schedule_roster';
        $params = [];
        if ($ministryId !== null) {
            $sql .= ' WHERE ministry_id = :m';
            $params[':m'] = $ministryId;
        }
        $sql .= ' ORDER BY starts_on DESC, roster_id DESC';
        $stmt = $this->portal->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn ($r) => $this->hydrateRoster($r), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Full roster with slots and assignments, ready for the editor.
     *
     * @return array<string,mixed>|null
     */
    public function loadRoster(ActorContext $actor, int $rosterId): ?array
    {
        $stmt = $this->portal->prepare('SELECT * FROM schedule_roster WHERE roster_id = :id LIMIT 1');
        $stmt->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $roster = $this->hydrateRoster($row);

        $slotsStmt = $this->portal->prepare(
            'SELECT * FROM schedule_roster_slot WHERE roster_id = :id ORDER BY display_order ASC, slot_id ASC'
        );
        $slotsStmt->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $slotsStmt->execute();
        $slotRows = $slotsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $slotIds = array_column($slotRows, 'slot_id');
        $assignments = [];
        if ($slotIds !== []) {
            $place = implode(',', array_fill(0, count($slotIds), '?'));
            $aStmt = $this->portal->prepare(
                "SELECT * FROM schedule_roster_assignment WHERE slot_id IN ($place) ORDER BY display_order ASC, assignment_id ASC"
            );
            foreach ($slotIds as $i => $sid) $aStmt->bindValue($i + 1, $sid, PDO::PARAM_INT);
            $aStmt->execute();
            foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
                $assignments[(int) $a['slot_id']][] = [
                    'assignmentId' => (int) $a['assignment_id'],
                    'personId'     => $a['person_id'] !== null ? (int) $a['person_id'] : null,
                    'displayName'  => $a['display_name'],
                    'displayOrder' => (int) $a['display_order'],
                ];
            }
        }

        $roster['slots'] = array_map(function ($s) use ($assignments) {
            return [
                'slotId'       => (int) $s['slot_id'],
                'rosterId'     => (int) $s['roster_id'],
                'slotDate'     => $s['slot_date'],
                'slotDow'      => $s['slot_dow'] !== null ? (int) $s['slot_dow'] : null,
                'label'        => $s['label'],
                'location'     => $s['location'],
                'roleId'       => $s['role_id'] !== null ? (int) $s['role_id'] : null,
                'displayOrder' => (int) $s['display_order'],
                'notes'        => $s['notes'],
                'assignees'    => $assignments[(int) $s['slot_id']] ?? [],
            ];
        }, $slotRows);

        return $roster;
    }

    // ---------- write ops ----------

    /**
     * @param array{title:string,subtitle?:?string,ministryId?:?int,campusId?:?int,
     *               startsOn:string,endsOn:string,notes?:?string,isPublished?:bool} $data
     */
    public function createRoster(ActorContext $actor, array $data): int
    {
        $this->assertCanManage($actor, $data['ministryId'] ?? null);
        $this->validateMeta($data);
        $stmt = $this->portal->prepare(
            'INSERT INTO schedule_roster
                (title, subtitle, ministry_id, campus_id, starts_on, ends_on, notes, is_published, created_by)
             VALUES (:t, :sub, :m, :c, :s, :e, :n, :pub, :cb)'
        );
        $stmt->bindValue(':t',   trim((string) $data['title']));
        $stmt->bindValue(':sub', $data['subtitle'] ?? null, $data['subtitle'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $this->bindIntOrNull($stmt, ':m', $data['ministryId'] ?? null);
        $this->bindIntOrNull($stmt, ':c', $data['campusId']   ?? null);
        $stmt->bindValue(':s',   (string) $data['startsOn']);
        $stmt->bindValue(':e',   (string) $data['endsOn']);
        $stmt->bindValue(':n',   $data['notes'] ?? null, $data['notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':pub', !empty($data['isPublished']) ? 1 : 0, PDO::PARAM_INT);
        $this->bindIntOrNull($stmt, ':cb', $actor->actorId > 0 ? $actor->actorId : null);
        $stmt->execute();
        return (int) $this->portal->lastInsertId();
    }

    /**
     * Replace the roster's slots+assignments with the supplied set. Idempotent
     * "save the whole thing" pattern matches how the editor posts.
     *
     * @param array<int,array{
     *   slotId?:int,slotDate:?string,slotDow:?int,label:?string,location:?string,
     *   roleId:?int,displayOrder:int,notes:?string,
     *   assignees:list<array{personId:?int,displayName:?string,displayOrder:int}>
     * }> $slots
     */
    public function saveRosterSlots(ActorContext $actor, int $rosterId, array $slots): void
    {
        $stmt = $this->portal->prepare('SELECT ministry_id FROM schedule_roster WHERE roster_id = :id LIMIT 1');
        $stmt->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ValidationFailed('Roster not found.');
        }
        $this->assertCanManage($actor, $row['ministry_id'] !== null ? (int) $row['ministry_id'] : null);

        $this->portal->beginTransaction();
        try {
            // Wipe existing slots; CASCADE drops their assignments.
            $del = $this->portal->prepare('DELETE FROM schedule_roster_slot WHERE roster_id = :id');
            $del->bindValue(':id', $rosterId, PDO::PARAM_INT);
            $del->execute();

            $insSlot = $this->portal->prepare(
                'INSERT INTO schedule_roster_slot
                    (roster_id, slot_date, slot_dow, label, location, role_id, display_order, notes)
                 VALUES (:r, :d, :w, :l, :loc, :role, :ord, :n)'
            );
            $insAssn = $this->portal->prepare(
                'INSERT INTO schedule_roster_assignment
                    (slot_id, person_id, display_name, display_order)
                 VALUES (:sl, :pid, :dn, :ord)'
            );

            foreach ($slots as $i => $slot) {
                $insSlot->bindValue(':r',   $rosterId, PDO::PARAM_INT);
                $insSlot->bindValue(':d',   $slot['slotDate'] ?? null, $slot['slotDate'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $this->bindIntOrNull($insSlot, ':w',    $slot['slotDow'] ?? null);
                $insSlot->bindValue(':l',   $slot['label'] ?? null, $slot['label'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $insSlot->bindValue(':loc', $slot['location'] ?? null, $slot['location'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $this->bindIntOrNull($insSlot, ':role', $slot['roleId'] ?? null);
                $insSlot->bindValue(':ord', (int) ($slot['displayOrder'] ?? $i), PDO::PARAM_INT);
                $insSlot->bindValue(':n',   $slot['notes'] ?? null, $slot['notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $insSlot->execute();
                $newSlotId = (int) $this->portal->lastInsertId();

                foreach ($slot['assignees'] ?? [] as $j => $a) {
                    if (empty($a['personId']) && empty($a['displayName'])) continue;
                    $insAssn->bindValue(':sl', $newSlotId, PDO::PARAM_INT);
                    $this->bindIntOrNull($insAssn, ':pid', $a['personId'] ?? null);
                    $insAssn->bindValue(':dn', $a['displayName'] ?? null, $a['displayName'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $insAssn->bindValue(':ord', (int) ($a['displayOrder'] ?? $j), PDO::PARAM_INT);
                    $insAssn->execute();
                }
            }
            $this->portal->commit();
        } catch (\Throwable $e) {
            $this->portal->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function updateRosterMeta(ActorContext $actor, int $rosterId, array $patch): void
    {
        $stmt = $this->portal->prepare('SELECT ministry_id FROM schedule_roster WHERE roster_id = :id LIMIT 1');
        $stmt->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new ValidationFailed('Roster not found.');
        $this->assertCanManage($actor, $row['ministry_id'] !== null ? (int) $row['ministry_id'] : null);

        $map = [
            'title' => 'title', 'subtitle' => 'subtitle', 'ministryId' => 'ministry_id',
            'campusId' => 'campus_id', 'startsOn' => 'starts_on', 'endsOn' => 'ends_on',
            'notes' => 'notes', 'isPublished' => 'is_published',
        ];
        $sets = [];
        $params = [];
        foreach ($patch as $k => $v) {
            if (!isset($map[$k])) continue;
            $col = $map[$k];
            $sets[] = "$col = :$k";
            if ($k === 'isPublished') {
                $params[$k] = $v ? 1 : 0;
            } elseif (in_array($k, ['ministryId', 'campusId'], true) && ($v === '' || $v === null)) {
                $params[$k] = null;
            } else {
                $params[$k] = $v;
            }
        }
        if ($sets === []) return;
        $sql = 'UPDATE schedule_roster SET ' . implode(', ', $sets) . ' WHERE roster_id = :id';
        $stmt = $this->portal->prepare($sql);
        foreach ($params as $k => $v) {
            if ($v === null)        $stmt->bindValue(':' . $k, null, PDO::PARAM_NULL);
            elseif (is_int($v))     $stmt->bindValue(':' . $k, $v, PDO::PARAM_INT);
            else                    $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteRoster(ActorContext $actor, int $rosterId): void
    {
        $stmt = $this->portal->prepare('SELECT ministry_id FROM schedule_roster WHERE roster_id = :id LIMIT 1');
        $stmt->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return;
        $this->assertCanManage($actor, $row['ministry_id'] !== null ? (int) $row['ministry_id'] : null);

        $del = $this->portal->prepare('DELETE FROM schedule_roster WHERE roster_id = :id');
        $del->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $del->execute();
    }

    // ---------- people pool ----------

    /**
     * Look up the Member Type list options once so the editor can render the
     * correct chip labels and IDs without hard-coding them in the front end.
     *
     * @return list<array{id:int,name:string}>
     */
    public function listMemberTypes(): array
    {
        if ($this->churchcrm === null) return [];
        try {
            $stmt = $this->churchcrm->prepare(
                'SELECT lst_OptionID, lst_OptionName
                   FROM list_lst
                  WHERE lst_ID = :lid
               ORDER BY lst_OptionSequence'
            );
            $stmt->bindValue(':lid', self::MEMBER_TYPE_LIST_ID, PDO::PARAM_INT);
            $stmt->execute();
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $out[] = ['id' => (int) $row['lst_OptionID'], 'name' => (string) $row['lst_OptionName']];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolve the people pool the editor offers. Filters compose:
     *   - if includeAllMembers=false AND ministryId set → ministry membership
     *   - memberTypeIds filter (from person_custom.c1, list_lst lst_ID=13)
     *   - campusId filter (person_campus_affiliation, primary)
     *
     * @param list<int> $memberTypeIds
     * @return list<array{personId:int,displayName:string,memberTypeId:?int}>
     */
    public function resolvePeoplePool(
        ?int $ministryId,
        bool $includeAllMembers,
        array $memberTypeIds,
        ?int $campusId,
        ?string $search = null,
    ): array {
        if ($this->churchcrm === null) return [];

        $sql = "SELECT p.per_ID,
                       TRIM(CONCAT(COALESCE(p.per_FirstName,''),' ',COALESCE(p.per_LastName,''))) AS name,
                       pc.c1 AS member_type_id
                  FROM person_per p
             LEFT JOIN person_custom pc ON pc.per_ID = p.per_ID";
        $where = [];
        $params = [];

        if (!$includeAllMembers && $ministryId !== null) {
            $sql .= " INNER JOIN person2group2role_p2g2r r ON r.p2g2r_per_ID = p.per_ID
                                                          AND r.p2g2r_grp_ID = :gid";
            $params[':gid'] = $ministryId;
        }

        if ($campusId !== null) {
            $sql .= " INNER JOIN person_campus_affiliation pca ON pca.person_id = p.per_ID
                                                              AND pca.campus_id = :camp
                                                              AND pca.is_primary = 1";
            $params[':camp'] = $campusId;
        }

        if ($memberTypeIds !== []) {
            // Include people whose member type is in the chosen set OR who
            // have no member type at all — unticking G&A shouldn't make every
            // visitor / unclassified adult disappear too.
            $place = implode(',', array_fill(0, count($memberTypeIds), '?'));
            $where[] = "(pc.c1 IS NULL OR pc.c1 IN ($place))";
        }
        if ($search !== null && trim($search) !== '') {
            $where[] = "(p.per_FirstName LIKE :q OR p.per_LastName LIKE :q OR p.per_Email LIKE :q)";
            $params[':q'] = '%' . trim($search) . '%';
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY p.per_ID, name, pc.c1 ORDER BY p.per_LastName, p.per_FirstName LIMIT 500';

        $stmt = $this->churchcrm->prepare($sql);
        $i = 1;
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        foreach ($memberTypeIds as $id) {
            $stmt->bindValue($i++, (int) $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $name = trim((string) $row['name']);
            if ($name === '') $name = 'Person #' . (int) $row['per_ID'];
            $out[] = [
                'personId'     => (int) $row['per_ID'],
                'displayName'  => $name,
                'memberTypeId' => $row['member_type_id'] !== null ? (int) $row['member_type_id'] : null,
            ];
        }
        return $out;
    }

    // ---------- calendar feed ----------

    /**
     * Expand every roster slot whose date falls in [start, end] into one item
     * per (slot, date) pair. DOW slots recur for every matching weekday in
     * the roster's window — the calendar feed uses the resulting items as
     * all-day, non-blocking entries.
     *
     * @return list<array{
     *   rosterId:int,slotId:int,date:string,
     *   title:string,subtitle:?string,label:?string,location:?string,
     *   ministryId:?int,assignees:list<string>
     * }>
     */
    public function expandRostersInWindow(string $start, string $end): array
    {
        $startDt = new DateTimeImmutable($start);
        $endDt   = new DateTimeImmutable($end);
        if ($endDt < $startDt) return [];
        $startStr = $startDt->format('Y-m-d');
        $endStr   = $endDt->format('Y-m-d');

        $rosters = $this->portal->query(
            "SELECT r.* FROM schedule_roster r
              WHERE r.is_published = 1
                AND r.starts_on <= " . $this->portal->quote($endStr) . "
                AND r.ends_on   >= " . $this->portal->quote($startStr)
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rosters === []) return [];

        $rosterIds = array_column($rosters, 'roster_id');
        $place = implode(',', array_fill(0, count($rosterIds), '?'));

        $slotsStmt = $this->portal->prepare(
            "SELECT * FROM schedule_roster_slot WHERE roster_id IN ($place)"
        );
        foreach ($rosterIds as $i => $id) $slotsStmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        $slotsStmt->execute();
        $slots = $slotsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $slotIds = array_column($slots, 'slot_id');
        $assignees = [];
        if ($slotIds !== []) {
            $sp = implode(',', array_fill(0, count($slotIds), '?'));
            $aStmt = $this->portal->prepare(
                "SELECT slot_id, person_id, display_name FROM schedule_roster_assignment
                  WHERE slot_id IN ($sp) ORDER BY display_order ASC, assignment_id ASC"
            );
            foreach ($slotIds as $i => $id) $aStmt->bindValue($i + 1, $id, PDO::PARAM_INT);
            $aStmt->execute();
            foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
                $assignees[(int) $a['slot_id']][] = $a;
            }
        }
        // Person id → name lookup for assignment chips.
        $names = [];
        $personIds = [];
        foreach ($assignees as $slotAssignees) {
            foreach ($slotAssignees as $a) {
                if (!empty($a['person_id'])) $personIds[] = (int) $a['person_id'];
            }
        }
        $personIds = array_values(array_unique($personIds));
        if ($personIds !== [] && $this->churchcrm !== null) {
            $place2 = implode(',', array_fill(0, count($personIds), '?'));
            $nStmt = $this->churchcrm->prepare(
                "SELECT per_ID, TRIM(CONCAT(COALESCE(per_FirstName,''),' ',COALESCE(per_LastName,''))) AS name
                   FROM person_per WHERE per_ID IN ($place2)"
            );
            foreach ($personIds as $i => $id) $nStmt->bindValue($i + 1, $id, PDO::PARAM_INT);
            $nStmt->execute();
            foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $names[(int) $row['per_ID']] = trim((string) $row['name']);
            }
        }

        $rostersById = [];
        foreach ($rosters as $r) $rostersById[(int) $r['roster_id']] = $r;

        $items = [];
        foreach ($slots as $s) {
            $r = $rostersById[(int) $s['roster_id']] ?? null;
            if ($r === null) continue;
            $rosterStart = max($startDt, new DateTimeImmutable((string) $r['starts_on']));
            $rosterEnd   = min($endDt,   new DateTimeImmutable((string) $r['ends_on']));
            if ($rosterEnd < $rosterStart) continue;

            $names_ = [];
            foreach ($assignees[(int) $s['slot_id']] ?? [] as $a) {
                if (!empty($a['display_name']))      $names_[] = (string) $a['display_name'];
                elseif (!empty($a['person_id']))     $names_[] = $names[(int) $a['person_id']] ?? ('Person #' . (int) $a['person_id']);
            }

            if ($s['slot_date'] !== null) {
                $d = new DateTimeImmutable((string) $s['slot_date']);
                if ($d >= $rosterStart && $d <= $rosterEnd) {
                    $items[] = $this->itemFromSlot($r, $s, $d->format('Y-m-d'), $names_);
                }
            } elseif ($s['slot_dow'] !== null) {
                $dow = (int) $s['slot_dow'];
                $cur = $rosterStart;
                while ($cur <= $rosterEnd) {
                    if ((int) $cur->format('w') === $dow) {
                        $items[] = $this->itemFromSlot($r, $s, $cur->format('Y-m-d'), $names_);
                    }
                    $cur = $cur->modify('+1 day');
                }
            }
        }
        usort($items, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: ($a['rosterId'] - $b['rosterId']));
        return $items;
    }

    private function itemFromSlot(array $roster, array $slot, string $dateStr, array $names): array
    {
        return [
            'rosterId'   => (int) $roster['roster_id'],
            'slotId'     => (int) $slot['slot_id'],
            'date'       => $dateStr,
            'title'      => (string) $roster['title'],
            'subtitle'   => $roster['subtitle'],
            'label'      => $slot['label'],
            'location'   => $slot['location'],
            'ministryId' => $roster['ministry_id'] !== null ? (int) $roster['ministry_id'] : null,
            'assignees'  => $names,
        ];
    }

    // ---------- helpers ----------

    private function hydrateRoster(array $row): array
    {
        return [
            'rosterId'    => (int) $row['roster_id'],
            'title'       => (string) $row['title'],
            'subtitle'    => $row['subtitle'],
            'ministryId'  => $row['ministry_id'] !== null ? (int) $row['ministry_id'] : null,
            'campusId'    => $row['campus_id']   !== null ? (int) $row['campus_id']   : null,
            'startsOn'    => (string) $row['starts_on'],
            'endsOn'      => (string) $row['ends_on'],
            'notes'       => $row['notes'],
            'isPublished' => (int) $row['is_published'] === 1,
            'createdBy'   => $row['created_by']  !== null ? (int) $row['created_by']  : null,
        ];
    }

    private function bindIntOrNull(\PDOStatement $stmt, string $key, mixed $v): void
    {
        if ($v === null || $v === '') $stmt->bindValue($key, null, PDO::PARAM_NULL);
        else                          $stmt->bindValue($key, (int) $v, PDO::PARAM_INT);
    }

    private function validateMeta(array $data): void
    {
        if (empty($data['title']) || trim((string) $data['title']) === '') {
            throw new ValidationFailed('Roster title is required.');
        }
        if (empty($data['startsOn']) || empty($data['endsOn'])) {
            throw new ValidationFailed('Start and end dates are required.');
        }
        if (strtotime((string) $data['endsOn']) < strtotime((string) $data['startsOn'])) {
            throw new ValidationFailed('End date cannot be before start date.');
        }
    }

    private function assertCanManage(ActorContext $actor, ?int $ministryId): void
    {
        if ($actor->isPortalWideAdmin) return;
        if (in_array(PortalPermission::ManageSchedules, $actor->permissions, true)) {
            // Portal-wide leader/scheduler with no scope ⇒ all-access.
            if ($actor->ministryScopeIds === []) return;
            // Roster has no ministry pinned → any ministry leader can manage.
            if ($ministryId === null) return;
            if (in_array($ministryId, $actor->ministryScopeIds, true)) return;
        }
        throw new PermissionDenied('You do not have permission to manage this roster.');
    }
}
