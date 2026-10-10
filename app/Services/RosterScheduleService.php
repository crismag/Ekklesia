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
 * that holds dated or weekday slots, and each slot holds 1+ assignees.
 *
 * Direct-PDO service: there's no real "alternate backend" to hide behind an
 * adapter — rosters live in the member database only — so we keep it flat to
 * minimise the file count.
 */
final readonly class RosterScheduleService
{
    /**
     * member_types holds the Member Type options (Radical / Trailblazer /
     * G&A) referenced by people.member_type_id. G&A is excluded by default on
     * the editor's people-pool filter chips.
     */
    public const DEFAULT_EXCLUDED_MEMBER_TYPE = 'g&a';

    public function __construct(
        private PDO $db,
    ) {
    }

    // ---------- listing ----------

    /**
     * @return list<array<string,mixed>>
     */
    public function listRosters(ActorContext $actor, ?int $ministryId = null): array
    {
        $sql = 'SELECT * FROM rosters';
        $params = [];
        if ($ministryId !== null) {
            $sql .= ' WHERE ministry_id = :m';
            $params[':m'] = $ministryId;
        }
        $sql .= ' ORDER BY starts_on DESC, id DESC';
        $stmt = $this->db->prepare($sql);
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
        $stmt = $this->db->prepare('SELECT * FROM rosters WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $roster = $this->hydrateRoster($row);

        $slotsStmt = $this->db->prepare(
            'SELECT * FROM roster_slots WHERE roster_id = :id ORDER BY sort_order ASC, id ASC'
        );
        $slotsStmt->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $slotsStmt->execute();
        $slotRows = $slotsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $slotIds = array_column($slotRows, 'id');
        $assignments = [];
        if ($slotIds !== []) {
            $place = implode(',', array_fill(0, count($slotIds), '?'));
            $aStmt = $this->db->prepare(
                "SELECT * FROM roster_assignments WHERE slot_id IN ($place) ORDER BY sort_order ASC, id ASC"
            );
            foreach ($slotIds as $i => $sid) $aStmt->bindValue($i + 1, $sid, PDO::PARAM_INT);
            $aStmt->execute();
            foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
                $assignments[(int) $a['slot_id']][] = [
                    'assignmentId' => (int) $a['id'],
                    'personId'     => $a['person_id'] !== null ? (int) $a['person_id'] : null,
                    'displayName'  => $a['display_name'],
                    'sortOrder'    => (int) $a['sort_order'],
                ];
            }
        }

        $roster['slots'] = array_map(function ($s) use ($assignments) {
            return [
                'slotId'       => (int) $s['id'],
                'rosterId'     => (int) $s['roster_id'],
                'slotDate'     => $s['slot_date'],
                'slotWeekday'  => $s['slot_weekday'] !== null ? (int) $s['slot_weekday'] : null,
                'label'        => $s['label'],
                'location'     => $s['location'],
                'roleId'       => $s['serving_role_id'] !== null ? (int) $s['serving_role_id'] : null,
                'sortOrder'    => (int) $s['sort_order'],
                'notes'        => $s['notes'],
                'assignees'    => $assignments[(int) $s['id']] ?? [],
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
        $stmt = $this->db->prepare(
            'INSERT INTO rosters
                (title, subtitle, ministry_id, campus_id, starts_on, ends_on, notes, is_published, created_by_account_id)
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
        return (int) $this->db->lastInsertId();
    }

    /**
     * Replace the roster's slots+assignments with the supplied set. Idempotent
     * "save the whole thing" pattern matches how the editor posts.
     *
     * @param array<int,array{
     *   slotId?:int,slotDate:?string,slotWeekday:?int,label:?string,location:?string,
     *   roleId:?int,sortOrder:int,notes:?string,
     *   assignees:list<array{personId:?int,displayName:?string,sortOrder:int}>
     * }> $slots
     */
    public function saveRosterSlots(ActorContext $actor, int $rosterId, array $slots): void
    {
        $stmt = $this->db->prepare('SELECT ministry_id FROM rosters WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ValidationFailed('Roster not found.');
        }
        $this->assertCanManage($actor, $row['ministry_id'] !== null ? (int) $row['ministry_id'] : null);

        $this->db->beginTransaction();
        try {
            // Wipe existing slots; CASCADE drops their assignments.
            $del = $this->db->prepare('DELETE FROM roster_slots WHERE roster_id = :id');
            $del->bindValue(':id', $rosterId, PDO::PARAM_INT);
            $del->execute();

            $insSlot = $this->db->prepare(
                'INSERT INTO roster_slots
                    (roster_id, slot_date, slot_weekday, label, location, serving_role_id, sort_order, notes)
                 VALUES (:r, :d, :w, :l, :loc, :role, :ord, :n)'
            );
            $insAssn = $this->db->prepare(
                'INSERT INTO roster_assignments
                    (slot_id, person_id, display_name, sort_order)
                 VALUES (:sl, :pid, :dn, :ord)'
            );

            foreach ($slots as $i => $slot) {
                $insSlot->bindValue(':r',   $rosterId, PDO::PARAM_INT);
                $insSlot->bindValue(':d',   $slot['slotDate'] ?? null, $slot['slotDate'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $this->bindIntOrNull($insSlot, ':w',    $slot['slotWeekday'] ?? null);
                $insSlot->bindValue(':l',   $slot['label'] ?? null, $slot['label'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $insSlot->bindValue(':loc', $slot['location'] ?? null, $slot['location'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $this->bindIntOrNull($insSlot, ':role', $slot['roleId'] ?? null);
                $insSlot->bindValue(':ord', (int) ($slot['sortOrder'] ?? $i), PDO::PARAM_INT);
                $insSlot->bindValue(':n',   $slot['notes'] ?? null, $slot['notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $insSlot->execute();
                $newSlotId = (int) $this->db->lastInsertId();

                foreach ($slot['assignees'] ?? [] as $j => $a) {
                    if (empty($a['personId']) && empty($a['displayName'])) continue;
                    $insAssn->bindValue(':sl', $newSlotId, PDO::PARAM_INT);
                    $this->bindIntOrNull($insAssn, ':pid', $a['personId'] ?? null);
                    $insAssn->bindValue(':dn', $a['displayName'] ?? null, $a['displayName'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $insAssn->bindValue(':ord', (int) ($a['sortOrder'] ?? $j), PDO::PARAM_INT);
                    $insAssn->execute();
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function updateRosterMeta(ActorContext $actor, int $rosterId, array $patch): void
    {
        $stmt = $this->db->prepare('SELECT ministry_id FROM rosters WHERE id = :id LIMIT 1');
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
        $sql = 'UPDATE rosters SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
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
        $stmt = $this->db->prepare('SELECT ministry_id FROM rosters WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $rosterId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return;
        $this->assertCanManage($actor, $row['ministry_id'] !== null ? (int) $row['ministry_id'] : null);

        $del = $this->db->prepare('DELETE FROM rosters WHERE id = :id');
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
        try {
            $stmt = $this->db->prepare(
                'SELECT id, name
                   FROM member_types
               ORDER BY sort_order, name'
            );
            $stmt->execute();
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolve the people pool the editor offers. Filters compose:
     *   - if includeAllMembers=false AND ministryId set → ministry membership
     *   - memberTypeIds filter (people.member_type_id)
     *   - campusId filter (people.campus_id)
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
        $sql = "SELECT p.id,
                       TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) AS name,
                       p.member_type_id
                  FROM people p";
        $where = [];
        $params = [];

        if (!$includeAllMembers && $ministryId !== null) {
            $sql .= " INNER JOIN ministry_members mm ON mm.person_id = p.id
                                                    AND mm.ministry_id = :gid
                                                    AND mm.status = 'confirmed'";
            $params[':gid'] = $ministryId;
        }

        if ($campusId !== null) {
            $where[] = "p.campus_id = :camp";
            $params[':camp'] = $campusId;
        }

        if ($memberTypeIds !== []) {
            // Include people whose member type is in the chosen set OR who
            // have no member type at all — unticking G&A shouldn't make every
            // visitor / unclassified adult disappear too.
            $typeKeys = [];
            foreach (array_values($memberTypeIds) as $n => $typeId) {
                $typeKeys[] = ':mt' . $n;
                $params[':mt' . $n] = (int) $typeId;
            }
            $place = implode(',', $typeKeys);
            $where[] = "(p.member_type_id IS NULL OR p.member_type_id IN ($place))";
        }
        if ($search !== null && trim($search) !== '') {
            $where[] = "(p.first_name LIKE :q1 OR p.last_name LIKE :q2 OR p.email LIKE :q3)";
            $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . trim($search) . '%';
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY p.id, name, p.member_type_id, p.last_name, p.first_name ORDER BY p.last_name, p.first_name LIMIT 500';

        $stmt = $this->db->prepare($sql);
        // Named placeholders only: PDO refuses a statement that mixes named
        // and positional ones.
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $name = trim((string) $row['name']);
            if ($name === '') $name = 'Person #' . (int) $row['id'];
            $out[] = [
                'personId'     => (int) $row['id'],
                'displayName'  => $name,
                'memberTypeId' => $row['member_type_id'] !== null ? (int) $row['member_type_id'] : null,
            ];
        }
        return $out;
    }

    // ---------- calendar feed ----------

    /**
     * Expand every roster slot whose date falls in [start, end] into one item
     * per (slot, date) pair. Weekday slots recur for every matching weekday in
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

        $rostersStmt = $this->db->prepare(
            "SELECT r.* FROM rosters r
              WHERE r.is_published = 1
                AND r.starts_on <= :window_end
                AND r.ends_on   >= :window_start"
        );
        $rostersStmt->bindValue(':window_end', $endStr, PDO::PARAM_STR);
        $rostersStmt->bindValue(':window_start', $startStr, PDO::PARAM_STR);
        $rostersStmt->execute();
        $rosters = $rostersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rosters === []) return [];

        $rosterIds = array_column($rosters, 'id');
        $place = implode(',', array_fill(0, count($rosterIds), '?'));

        $slotsStmt = $this->db->prepare(
            "SELECT * FROM roster_slots WHERE roster_id IN ($place)"
        );
        foreach ($rosterIds as $i => $id) $slotsStmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        $slotsStmt->execute();
        $slots = $slotsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $slotIds = array_column($slots, 'id');
        $assignees = [];
        if ($slotIds !== []) {
            $sp = implode(',', array_fill(0, count($slotIds), '?'));
            $aStmt = $this->db->prepare(
                "SELECT slot_id, person_id, display_name FROM roster_assignments
                  WHERE slot_id IN ($sp) ORDER BY sort_order ASC, id ASC"
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
        if ($personIds !== []) {
            $place2 = implode(',', array_fill(0, count($personIds), '?'));
            $nStmt = $this->db->prepare(
                "SELECT id, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name
                   FROM people WHERE id IN ($place2)"
            );
            foreach ($personIds as $i => $id) $nStmt->bindValue($i + 1, $id, PDO::PARAM_INT);
            $nStmt->execute();
            foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $names[(int) $row['id']] = trim((string) $row['name']);
            }
        }

        $rostersById = [];
        foreach ($rosters as $r) $rostersById[(int) $r['id']] = $r;

        $items = [];
        foreach ($slots as $s) {
            $r = $rostersById[(int) $s['roster_id']] ?? null;
            if ($r === null) continue;
            $rosterStart = max($startDt, new DateTimeImmutable((string) $r['starts_on']));
            $rosterEnd   = min($endDt,   new DateTimeImmutable((string) $r['ends_on']));
            if ($rosterEnd < $rosterStart) continue;

            $names_ = [];
            foreach ($assignees[(int) $s['id']] ?? [] as $a) {
                if (!empty($a['display_name']))      $names_[] = (string) $a['display_name'];
                elseif (!empty($a['person_id']))     $names_[] = $names[(int) $a['person_id']] ?? ('Person #' . (int) $a['person_id']);
            }

            if ($s['slot_date'] !== null) {
                $d = new DateTimeImmutable((string) $s['slot_date']);
                if ($d >= $rosterStart && $d <= $rosterEnd) {
                    $items[] = $this->itemFromSlot($r, $s, $d->format('Y-m-d'), $names_);
                }
            } elseif ($s['slot_weekday'] !== null) {
                $dow = (int) $s['slot_weekday'];
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
            'rosterId'   => (int) $roster['id'],
            'slotId'     => (int) $slot['id'],
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
            'rosterId'    => (int) $row['id'],
            'title'       => (string) $row['title'],
            'subtitle'    => $row['subtitle'],
            'ministryId'  => $row['ministry_id'] !== null ? (int) $row['ministry_id'] : null,
            'campusId'    => $row['campus_id']   !== null ? (int) $row['campus_id']   : null,
            'startsOn'    => (string) $row['starts_on'],
            'endsOn'      => (string) $row['ends_on'],
            'notes'       => $row['notes'],
            'isPublished' => (int) $row['is_published'] === 1,
            'createdByAccountId' => $row['created_by_account_id'] !== null ? (int) $row['created_by_account_id'] : null,
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
