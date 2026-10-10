<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\MinistryAdapter;
use DateTimeImmutable;
use PDO;

/**
 * Ministry data adapter over the member database: ministries,
 * ministry_members (role 'member' | 'leader'), ministry_member_positions and
 * serving_roles.
 */
final class SqlMinistryAdapter implements MinistryAdapter
{
    /**
     * A membership that has ended is history, not a current member. Every
     * roster, count and leader list reads only the rows this admits.
     */
    private const CURRENT_MEMBER = "status <> 'ended'";

    public function __construct(
        private readonly ?PDO $connection = null,
    ) {
    }

    public function findMinistry(int $ministryId): ?array
    {
        if ($this->connection === null) {
            return null;
        }

        $stmt = $this->connection->prepare(
            'SELECT m.id AS ministry_id,
                    m.name AS name,
                    m.campus_id AS campus_id
               FROM ministries m
              WHERE m.id = :ministry_id
              LIMIT 1'
        );
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'ministry_id' => (int) $row['ministry_id'],
            'name' => (string) $row['name'],
            'campus_id' => $row['campus_id'] === null ? null : (int) $row['campus_id'],
        ];
    }

    public function listMinistries(array $ministryIds = []): array
    {
        if ($this->connection === null) {
            return [];
        }

        $sql = 'SELECT m.id AS ministry_id,
                       m.name AS name,
                       m.campus_id AS campus_id
                  FROM ministries m';
        $params = [];

        $normalizedIds = [];
        foreach ($ministryIds as $ministryId) {
            $ministryId = (int) $ministryId;
            if ($ministryId > 0) {
                $normalizedIds[$ministryId] = $ministryId;
            }
        }

        if ($normalizedIds !== []) {
            $placeholders = [];
            foreach (array_values($normalizedIds) as $index => $ministryId) {
                $placeholder = ':ministry_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $ministryId;
            }
            $sql .= ' WHERE m.id IN (' . implode(', ', $placeholders) . ')';
        } else {
            $sql .= ' WHERE m.is_active = 1';
        }

        $sql .= ' ORDER BY m.name ASC, m.id ASC';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'ministry_id' => (int) $row['ministry_id'],
                'name' => (string) $row['name'],
                'campus_id' => $row['campus_id'] === null ? null : (int) $row['campus_id'],
            ];
        }

        return $rows;
    }

    /**
     * Admin listing of ALL ministries (active + inactive) with member, leader
     * and serving-role counts. Used by the Members & leaders admin page.
     *
     * @return list<array{ministry_id:int,name:string,active:bool,campus_id:?int,member_count:int,leader_count:int,role_count:int}>
     */
    public function listMinistriesAdmin(?int $campusId = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        // When a campus is selected, counts reflect that campus's members (so
        // they match the campus-filtered roster).
        $campusMemberJoin = $campusId !== null
            ? 'INNER JOIN people pcm
                       ON pcm.id = mm.person_id
                      AND pcm.campus_id = :campus_count'
            : '';
        $campusLeaderJoin = $campusId !== null
            ? 'INNER JOIN people pcl
                       ON pcl.id = mm.person_id
                      AND pcl.campus_id = :campus_lead'
            : '';

        $memberCountSql = '(SELECT COUNT(DISTINCT mm.person_id)
                   FROM ministry_members mm
                   ' . $campusMemberJoin . '
                  WHERE mm.ministry_id = m.id
                    AND mm.' . self::CURRENT_MEMBER . ')';

        $leaderCountSql = '(SELECT COUNT(DISTINCT mm.person_id)
                   FROM ministry_members mm
                   ' . $campusLeaderJoin . '
                  WHERE mm.ministry_id = m.id
                    AND mm.role = \'leader\'
                    AND mm.' . self::CURRENT_MEMBER . ')';

        $sql = 'SELECT m.id AS ministry_id,
                       m.name AS name,
                       m.is_active AS active,
                       m.campus_id AS campus_id,
                       ' . $memberCountSql . ' AS member_count,
                       ' . $leaderCountSql . ' AS leader_count,
                       (SELECT COUNT(*)
                          FROM serving_roles r
                         WHERE r.ministry_id = m.id) AS role_count
                  FROM ministries m';

        if ($campusId !== null) {
            // Ministries assigned to this campus, plus shared/unassigned ones.
            $sql .= ' WHERE (m.campus_id = :campus_filter OR m.campus_id IS NULL)';
        }

        $sql .= ' ORDER BY m.is_active DESC, m.name ASC, m.id ASC';

        $stmt = $this->connection->prepare($sql);
        if ($campusId !== null) {
            $stmt->bindValue(':campus_count', $campusId, PDO::PARAM_INT);
            $stmt->bindValue(':campus_lead', $campusId, PDO::PARAM_INT);
            $stmt->bindValue(':campus_filter', $campusId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'ministry_id'  => (int) $row['ministry_id'],
                'name'         => (string) $row['name'],
                'active'       => (bool) $row['active'],
                'campus_id'    => $row['campus_id'] === null ? null : (int) $row['campus_id'],
                'member_count' => (int) $row['member_count'],
                'leader_count' => (int) $row['leader_count'],
                'role_count'   => (int) $row['role_count'],
            ];
        }

        return $rows;
    }

    /**
     * All leaders across ministries (for the overview), respecting the campus
     * filter. A leader is a member whose ministry_members.role is 'leader'.
     *
     * @return list<array{ministry_id:int,person_id:int,display_name:string}>
     */
    public function listLeadersByMinistry(?int $campusId = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusWhere = $campusId !== null
            ? ' AND p.campus_id = :campus_m
                AND (m.campus_id = :campus_f OR m.campus_id IS NULL)'
            : '';

        $sql = 'SELECT DISTINCT m.id AS ministry_id,
                       p.id AS person_id,
                       TRIM(CONCAT_WS(\' \', p.first_name, p.last_name)) AS display_name,
                       p.last_name, p.first_name
                  FROM ministries m
                  INNER JOIN ministry_members mm ON mm.ministry_id = m.id
                  INNER JOIN people p ON p.id = mm.person_id
                 WHERE mm.role = \'leader\'
                   AND mm.' . self::CURRENT_MEMBER
                 . $campusWhere . '
                 ORDER BY p.last_name ASC, p.first_name ASC';

        $stmt = $this->connection->prepare($sql);
        if ($campusId !== null) {
            $stmt->bindValue(':campus_m', $campusId, PDO::PARAM_INT);
            $stmt->bindValue(':campus_f', $campusId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rows[] = [
                'ministry_id'  => (int) $r['ministry_id'],
                'person_id'    => (int) $r['person_id'],
                'display_name' => (string) $r['display_name'],
            ];
        }

        return $rows;
    }

    /**
     * @param array{name:string,description?:string,campus_id?:?int} $data
     * @return array<string,mixed>
     */
    public function createMinistry(array $data): array
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Database connection not available');
        }

        $campusId = isset($data['campus_id']) && (int) $data['campus_id'] > 0 ? (int) $data['campus_id'] : null;
        $description = trim((string) ($data['description'] ?? ''));

        $stmt = $this->connection->prepare(
            'INSERT INTO ministries (name, slug, description, campus_id, is_active)
             VALUES (:name, :slug, :description, :campus_id, 1)'
        );
        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':slug', $this->uniqueSlug((string) $data['name']), PDO::PARAM_STR);
        $stmt->bindValue(':description', $description === '' ? null : $description, $description === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':campus_id', $campusId, $campusId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        $ministryId = (int) $this->connection->lastInsertId();

        return [
            'ministry_id'  => $ministryId,
            'name'         => (string) $data['name'],
            'active'       => true,
            'campus_id'    => $campusId,
            'member_count' => 0,
            'role_count'   => 0,
        ];
    }

    /**
     * The slug is set once, when the ministry is created, and kept on rename
     * so links built from it do not break.
     *
     * @param array{name:string,description?:string,campus_id?:?int} $data
     * @return array<string,mixed>
     */
    public function updateMinistry(int $ministryId, array $data): array
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Database connection not available');
        }

        $description = trim((string) ($data['description'] ?? ''));
        $stmt = $this->connection->prepare(
            'UPDATE ministries SET name = :name, description = :description WHERE id = :id'
        );
        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':description', $description === '' ? null : $description, $description === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $ministryId, PDO::PARAM_INT);
        $stmt->execute();

        if (array_key_exists('campus_id', $data)) {
            $campusId = (int) $data['campus_id'] > 0 ? (int) $data['campus_id'] : null;
            $campus = $this->connection->prepare('UPDATE ministries SET campus_id = :c WHERE id = :id');
            $campus->bindValue(':c', $campusId, $campusId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $campus->bindValue(':id', $ministryId, PDO::PARAM_INT);
            $campus->execute();
        }

        return $this->findMinistry($ministryId)
            ?? ['ministry_id' => $ministryId, 'name' => (string) ($data['name'] ?? ''), 'campus_id' => null];
    }

    public function setMinistryActive(int $ministryId, bool $active): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare('UPDATE ministries SET is_active = :a WHERE id = :id');
        $stmt->bindValue(':a', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $ministryId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * Hard delete: remove schedule assignments for its serving roles, the
     * memberships (positions cascade), the serving roles, and finally the
     * ministry itself — inside a transaction. Events and rosters that named
     * the ministry keep existing with no ministry (ON DELETE SET NULL).
     */
    public function deleteMinistry(int $ministryId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $this->connection->beginTransaction();
        try {
            $this->connection->prepare(
                'DELETE a FROM assignments a
                   INNER JOIN serving_roles r ON r.id = a.serving_role_id
                  WHERE r.ministry_id = :id'
            )->execute([':id' => $ministryId]);

            $this->connection->prepare('DELETE FROM ministry_members WHERE ministry_id = :id')
                ->execute([':id' => $ministryId]);

            $this->connection->prepare('DELETE FROM serving_roles WHERE ministry_id = :id')
                ->execute([':id' => $ministryId]);

            $this->connection->prepare('DELETE FROM ministries WHERE id = :id')
                ->execute([':id' => $ministryId]);

            $this->connection->commit();

            return true;
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Every current member of a ministry with their membership role
     * ('member' | 'leader') and positions ("Usher", "Emcee").
     *
     * @return list<array{person_id:int,display_name:string,role:string,role_name:string,is_leader:bool,positions:list<string>}>
     */
    public function listMinistryMembers(int $ministryId, ?int $campusId = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusWhere = $campusId !== null ? ' AND p.campus_id = :campus_id' : '';

        $sql = 'SELECT mm.id AS member_id,
                       p.id AS person_id,
                       TRIM(CONCAT_WS(\' \', p.first_name, p.last_name)) AS display_name,
                       mm.role AS role
                  FROM ministry_members mm
                  INNER JOIN people p ON p.id = mm.person_id
                 WHERE mm.ministry_id = :ministry_id
                   AND mm.' . self::CURRENT_MEMBER
                 . $campusWhere . '
                 ORDER BY p.last_name ASC, p.first_name ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        if ($campusId !== null) {
            $stmt->bindValue(':campus_id', $campusId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $positions = $this->positionsByMember(array_map(static fn (array $r): int => (int) $r['member_id'], $members));

        $rows = [];
        foreach ($members as $r) {
            $isLeader = (string) $r['role'] === 'leader';
            $rows[] = [
                'person_id'    => (int) $r['person_id'],
                'display_name' => (string) $r['display_name'],
                'role'         => $isLeader ? 'leader' : 'member',
                'role_name'    => $isLeader ? 'Leader' : 'Member',
                'is_leader'    => $isLeader,
                'positions'    => $positions[(int) $r['member_id']] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $memberIds ministry_members ids
     * @return array<int,list<string>>
     */
    private function positionsByMember(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_filter($memberIds, static fn (int $i): bool => $i > 0)));
        if ($this->connection === null || $memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->connection->prepare(
            'SELECT ministry_member_id, name
               FROM ministry_member_positions
              WHERE ministry_member_id IN (' . $placeholders . ')
              ORDER BY name ASC'
        );
        $stmt->execute($memberIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['ministry_member_id']][] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * The membership roles a person can hold in a ministry. Fixed: a member
     * or a leader of it.
     *
     * @return list<array{id:string,name:string,is_default:bool}>
     */
    public function listGroupRoles(int $ministryId): array
    {
        return [
            ['id' => 'member', 'name' => 'Member', 'is_default' => true],
            ['id' => 'leader', 'name' => 'Leader', 'is_default' => false],
        ];
    }

    /**
     * Make a current member a leader. Someone who is not a member is not made
     * one by this: the admin page tags leaders from the member list.
     */
    public function addMinistryLeader(int $ministryId, int $personId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare(
            "UPDATE ministry_members SET role = 'leader'
              WHERE ministry_id = :g AND person_id = :p AND " . self::CURRENT_MEMBER
        );
        $stmt->bindValue(':g', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':p', $personId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    public function removeMinistryLeader(int $ministryId, int $personId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare(
            "UPDATE ministry_members SET role = 'member'
              WHERE ministry_id = :g AND person_id = :p AND role = 'leader'"
        );
        $stmt->bindValue(':g', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':p', $personId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * Current ministry memberships for a set of people.
     *
     * Batched deliberately: the import needs this for every row it applies, and
     * a query per person turns a 250-member import into 250 round trips.
     *
     * @param list<int> $personIds
     * @return array<int,list<int>> person id => ministry ids
     */
    public function listMinistryIdsForPeople(array $personIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $personIds), static fn (int $i): bool => $i > 0)));
        if ($this->connection === null || $ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->connection->prepare(
            'SELECT person_id, ministry_id
               FROM ministry_members
              WHERE person_id IN (' . $placeholders . ')
                AND ' . self::CURRENT_MEMBER
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['person_id']][] = (int) $row['ministry_id'];
        }

        return $out;
    }

    /**
     * Set a person's membership role in a ministry ('member' | 'leader').
     * Upserts: adds the membership if absent, otherwise changes the role. A
     * membership that had ended is current again.
     */
    public function setMemberRole(int $personId, int $ministryId, string $role): bool
    {
        if ($this->connection === null) {
            return false;
        }
        if ($role !== 'member' && $role !== 'leader') {
            throw new \InvalidArgumentException("Unknown ministry membership role: $role");
        }

        // ended_on is assigned before status so it still sees the old status.
        $stmt = $this->connection->prepare(
            "INSERT INTO ministry_members (person_id, ministry_id, role, status)
             VALUES (:p, :g, :r, 'confirmed')
             ON DUPLICATE KEY UPDATE role = VALUES(role),
                                     ended_on = IF(status = 'ended', NULL, ended_on),
                                     status = IF(status = 'ended', 'confirmed', status)"
        );
        $stmt->bindValue(':p', $personId, PDO::PARAM_INT);
        $stmt->bindValue(':g', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':r', $role, PDO::PARAM_STR);
        $stmt->execute();

        return true;
    }

    /**
     * Replace a member's positions in a ministry with exactly $positions.
     * Returns false when the person is not a member of that ministry.
     *
     * @param list<string> $positions
     */
    public function setMemberPositions(int $personId, int $ministryId, array $positions): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $find = $this->connection->prepare(
            'SELECT id FROM ministry_members WHERE person_id = :p AND ministry_id = :g LIMIT 1'
        );
        $find->bindValue(':p', $personId, PDO::PARAM_INT);
        $find->bindValue(':g', $ministryId, PDO::PARAM_INT);
        $find->execute();
        $memberId = $find->fetchColumn();
        if ($memberId === false) {
            return false;
        }
        $memberId = (int) $memberId;

        // One entry per name, compared case-insensitively as the column is.
        $wanted = [];
        foreach ($positions as $position) {
            $name = mb_substr(trim((string) $position), 0, 60);
            if ($name !== '') {
                $wanted[mb_strtolower($name)] ??= $name;
            }
        }

        // The member import calls this inside its own transaction; PDO cannot
        // nest one, so join the caller's when there is one.
        $ownTx = !$this->connection->inTransaction();
        if ($ownTx) {
            $this->connection->beginTransaction();
        }
        try {
            $delete = $this->connection->prepare('DELETE FROM ministry_member_positions WHERE ministry_member_id = :m');
            $delete->bindValue(':m', $memberId, PDO::PARAM_INT);
            $delete->execute();

            $insert = $this->connection->prepare(
                'INSERT INTO ministry_member_positions (ministry_member_id, name) VALUES (:m, :n)'
            );
            foreach ($wanted as $name) {
                $insert->bindValue(':m', $memberId, PDO::PARAM_INT);
                $insert->bindValue(':n', $name, PDO::PARAM_STR);
                $insert->execute();
            }

            if ($ownTx) {
                $this->connection->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }

        return true;
    }

    /**
     * Remove a person from a ministry entirely (their membership row; its
     * positions go with it).
     */
    public function removeMemberFromMinistry(int $personId, int $ministryId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $stmt = $this->connection->prepare(
            'DELETE FROM ministry_members WHERE person_id = :p AND ministry_id = :g'
        );
        $stmt->bindValue(':p', $personId, PDO::PARAM_INT);
        $stmt->bindValue(':g', $ministryId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    public function listCampuses(): array
    {
        if ($this->connection === null) {
            return [];
        }

        $stmt = $this->connection->query(
            'SELECT id, name, is_main
               FROM campuses
              WHERE is_active = 1
              ORDER BY is_main DESC, name ASC, id ASC'
        );

        $rows = [];
        foreach ($stmt?->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'campus_id' => (int) $row['id'],
                'campus_name' => (string) $row['name'],
                'is_main' => (int) ($row['is_main'] ?? 0) === 1,
            ];
        }

        return $rows;
    }

    public function findPrimaryCampusIdForPerson(int $personId): ?int
    {
        if ($this->connection === null) {
            return null;
        }

        $stmt = $this->connection->prepare(
            'SELECT campus_id
               FROM people
              WHERE id = :person_id
              LIMIT 1'
        );
        $stmt->bindValue(':person_id', $personId, PDO::PARAM_INT);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    public function findDisplayNameForPerson(int $personId): ?string
    {
        if ($this->connection === null) {
            return null;
        }

        $stmt = $this->connection->prepare(
            'SELECT TRIM(CONCAT_WS(\' \', first_name, last_name)) AS display_name
               FROM people
              WHERE id = :person_id
              LIMIT 1'
        );
        $stmt->bindValue(':person_id', $personId, PDO::PARAM_INT);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }

        $displayName = trim((string) $value);
        return $displayName !== '' ? $displayName : null;
    }

    public function resolveScheduleRoute(string $campusSlug, string $ministrySlug): ?array
    {
        if ($this->connection === null) {
            return null;
        }

        $campus = null;
        foreach ($this->listCampuses() as $row) {
            if (self::normalizePathSegment($row['campus_name']) !== self::normalizePathSegment($campusSlug)) {
                continue;
            }
            $campus = $row;
            break;
        }

        if ($campus === null) {
            return null;
        }

        $stmt = $this->connection->prepare(
            'SELECT m.id AS ministry_id,
                    m.name AS name,
                    m.campus_id AS campus_id
               FROM ministries m
              WHERE m.campus_id = :campus_id
                 OR m.campus_id IS NULL
              ORDER BY m.name ASC, m.id ASC'
        );
        $stmt->bindValue(':campus_id', $campus['campus_id'], PDO::PARAM_INT);
        $stmt->execute();

        $preferred = null;
        $fallback = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (self::normalizePathSegment((string) $row['name']) !== self::normalizePathSegment($ministrySlug)) {
                continue;
            }

            $candidate = [
                'campus_id' => (int) $campus['campus_id'],
                'campus_name' => (string) $campus['campus_name'],
                'ministry_id' => (int) $row['ministry_id'],
                'name' => (string) $row['name'],
            ];

            if ($row['campus_id'] !== null && (int) $row['campus_id'] === (int) $campus['campus_id']) {
                $preferred = $candidate;
                break;
            }
            $fallback ??= $candidate;
        }

        return $preferred ?? $fallback;
    }

    public function resolveScheduleRouteByMinistry(string $ministrySlug, array $campusIds = []): ?array
    {
        if ($this->connection === null) {
            return null;
        }

        $normalizedCampusIds = $this->normalizeCampusIds($campusIds);
        $matches = [];
        foreach ($this->listMinistries() as $row) {
            if (self::normalizePathSegment($row['name']) !== self::normalizePathSegment($ministrySlug)) {
                continue;
            }
            $matches[] = $row;
        }

        if ($matches === []) {
            return null;
        }

        if ($normalizedCampusIds !== []) {
            foreach ($matches as $match) {
                if ($match['campus_id'] !== null && in_array($match['campus_id'], $normalizedCampusIds, true)) {
                    return $match;
                }
            }
            foreach ($matches as $match) {
                if ($match['campus_id'] === null) {
                    return $match;
                }
            }
            return null;
        }

        foreach ($matches as $match) {
            if ($match['campus_id'] === null) {
                return $match;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function resolveCampuses(array $campusSlugs): array
    {
        if ($this->connection === null) {
            return [];
        }

        $wanted = [];
        foreach ($campusSlugs as $campusSlug) {
            $normalized = self::normalizePathSegment($campusSlug);
            if ($normalized === '' || $normalized === 'all-campus' || $normalized === 'all-campuses') {
                continue;
            }
            $wanted[$normalized] = true;
        }

        if ($wanted === []) {
            return [];
        }

        $resolved = [];
        foreach ($this->listCampuses() as $row) {
            $normalized = self::normalizePathSegment($row['campus_name']);
            if (!isset($wanted[$normalized])) {
                continue;
            }
            $resolved[(int) $row['campus_id']] = $row;
        }

        return array_values($resolved);
    }

    public function listMinistryDashboard(
        array $ministryIds,
        DateTimeImmutable $since,
        DateTimeImmutable $upcomingStart,
        DateTimeImmutable $until,
    ): array {
        if ($this->connection === null) {
            return [];
        }

        $normalizedIds = [];
        foreach ($ministryIds as $ministryId) {
            $ministryId = (int) $ministryId;
            if ($ministryId > 0) {
                $normalizedIds[$ministryId] = $ministryId;
            }
        }

        $summarySql = 'SELECT m.id AS ministry_id,
                              m.name AS name,
                              m.campus_id AS campus_id,
                              COALESCE(SUM(CASE
                                  WHEN eo.starts_at >= :since_at
                                   AND eo.starts_at < :past_until_at
                                  THEN 1 ELSE 0 END), 0) AS past_assignment_count,
                              COALESCE(SUM(CASE
                                  WHEN eo.starts_at >= :upcoming_from_at
                                   AND eo.starts_at < :upcoming_until_at
                                  THEN 1 ELSE 0 END), 0) AS upcoming_assignment_count,
                              MIN(CASE
                                  WHEN eo.starts_at >= :next_from_at
                                   AND eo.starts_at < :next_until_at
                                  THEN eo.starts_at ELSE NULL END) AS next_occurrence_at,
                              MAX(CASE
                                  WHEN eo.starts_at >= :last_from_at
                                   AND eo.starts_at < :last_until_at
                                  THEN eo.starts_at ELSE NULL END) AS last_occurrence_at
                         FROM ministries m
                         LEFT JOIN serving_roles r ON r.ministry_id = m.id AND r.is_active = 1
                         LEFT JOIN assignments a ON a.serving_role_id = r.id
                         LEFT JOIN event_occurrences eo ON eo.id = a.occurrence_id';

        $summaryParams = [
            ':since_at' => $since->format('Y-m-d H:i:s'),
            ':past_until_at' => $upcomingStart->format('Y-m-d H:i:s'),
            ':upcoming_from_at' => $upcomingStart->format('Y-m-d H:i:s'),
            ':upcoming_until_at' => $until->format('Y-m-d H:i:s'),
            ':next_from_at' => $upcomingStart->format('Y-m-d H:i:s'),
            ':next_until_at' => $until->format('Y-m-d H:i:s'),
            ':last_from_at' => $since->format('Y-m-d H:i:s'),
            ':last_until_at' => $upcomingStart->format('Y-m-d H:i:s'),
        ];

        if ($normalizedIds !== []) {
            $placeholders = [];
            foreach (array_values($normalizedIds) as $index => $ministryId) {
                $placeholder = ':dashboard_ministry_' . $index;
                $placeholders[] = $placeholder;
                $summaryParams[$placeholder] = $ministryId;
            }
            $summarySql .= ' WHERE m.id IN (' . implode(', ', $placeholders) . ')';
        }

        $summarySql .= ' GROUP BY m.id, m.name, m.campus_id
                         ORDER BY m.name ASC, m.id ASC';

        $summaryStmt = $this->connection->prepare($summarySql);
        foreach ($summaryParams as $key => $value) {
            $summaryStmt->bindValue($key, $value, str_starts_with($key, ':dashboard_ministry_') ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $summaryStmt->execute();

        $cards = [];
        foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cards[(int) $row['ministry_id']] = [
                'ministry_id' => (int) $row['ministry_id'],
                'name' => (string) $row['name'],
                'campus_id' => $row['campus_id'] === null ? null : (int) $row['campus_id'],
                'past_assignment_count' => (int) $row['past_assignment_count'],
                'upcoming_assignment_count' => (int) $row['upcoming_assignment_count'],
                'next_occurrence_at' => $row['next_occurrence_at'] === null ? null : new DateTimeImmutable((string) $row['next_occurrence_at']),
                'last_occurrence_at' => $row['last_occurrence_at'] === null ? null : new DateTimeImmutable((string) $row['last_occurrence_at']),
                'upcoming_occurrences' => [],
            ];
        }

        $occurrenceSql = 'SELECT r.ministry_id AS ministry_id,
                                 eo.id AS occurrence_id,
                                 COALESCE(e.title, \'\') AS event_title,
                                 eo.starts_at AS starts_on,
                                 eo.ends_at AS ends_on,
                                 COUNT(a.id) AS assignment_count
                            FROM assignments a
                            INNER JOIN serving_roles r ON r.id = a.serving_role_id
                            INNER JOIN event_occurrences eo ON eo.id = a.occurrence_id
                            LEFT JOIN events e ON e.id = eo.event_id
                           WHERE eo.starts_at >= :upcoming_at
                             AND eo.starts_at < :until_at';

        $occurrenceParams = [
            ':upcoming_at' => $upcomingStart->format('Y-m-d H:i:s'),
            ':until_at' => $until->format('Y-m-d H:i:s'),
        ];

        if ($normalizedIds !== []) {
            $placeholders = [];
            foreach (array_values($normalizedIds) as $index => $ministryId) {
                $placeholder = ':upcoming_ministry_' . $index;
                $placeholders[] = $placeholder;
                $occurrenceParams[$placeholder] = $ministryId;
            }
            $occurrenceSql .= ' AND r.ministry_id IN (' . implode(', ', $placeholders) . ')';
        }

        $occurrenceSql .= ' GROUP BY r.ministry_id, eo.id, e.title, eo.starts_at, eo.ends_at
                            ORDER BY r.ministry_id ASC, eo.starts_at ASC, eo.id ASC';

        $occurrenceStmt = $this->connection->prepare($occurrenceSql);
        foreach ($occurrenceParams as $key => $value) {
            $occurrenceStmt->bindValue($key, $value, str_starts_with($key, ':upcoming_ministry_') ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $occurrenceStmt->execute();

        $perMinistryCounts = [];
        foreach ($occurrenceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ministryId = (int) $row['ministry_id'];
            if (!isset($cards[$ministryId])) {
                continue;
            }

            $perMinistryCounts[$ministryId] = ($perMinistryCounts[$ministryId] ?? 0) + 1;
            if ($perMinistryCounts[$ministryId] > 3) {
                continue;
            }

            $cards[$ministryId]['upcoming_occurrences'][] = [
                'occurrence_id' => (int) $row['occurrence_id'],
                'event_title' => (string) $row['event_title'],
                'starts_on' => new DateTimeImmutable((string) $row['starts_on']),
                'ends_on' => new DateTimeImmutable((string) $row['ends_on']),
                'assignment_count' => (int) $row['assignment_count'],
            ];
        }

        return array_values($cards);
    }

    public function listMinistryRoster(
        int $ministryId,
        DateTimeImmutable $since,
        ?int $campusId = null,
    ): array {
        if ($this->connection === null) {
            return [];
        }

        $sql = 'SELECT DISTINCT
                    p.id                                  AS person_id,
                    TRIM(CONCAT_WS(\' \' , p.first_name, p.last_name)) AS display_name,
                    p.campus_id                           AS primary_campus_id,
                    COALESCE(stats.assignment_count, 0)   AS assignment_count,
                    stats.last_served_at                  AS last_served_at,
                    COALESCE(stats.roles_served, \'\')    AS roles_served,
                    p.last_name, p.first_name
                FROM ministry_members mm
                INNER JOIN people p
                       ON p.id = mm.person_id
                LEFT JOIN (
                    SELECT
                        a.person_id                              AS person_id,
                        COUNT(*)                                 AS assignment_count,
                        MAX(eo.starts_at)                        AS last_served_at,
                        GROUP_CONCAT(DISTINCT r.name
                                     ORDER BY r.sort_order, r.name
                                     SEPARATOR \', \' )          AS roles_served
                    FROM assignments a
                    INNER JOIN event_occurrences eo
                            ON eo.id = a.occurrence_id
                    INNER JOIN serving_roles r
                            ON r.id = a.serving_role_id
                    WHERE r.ministry_id = :ministry_b
                      AND eo.starts_at >= :since_at
                    GROUP BY a.person_id
                ) stats ON stats.person_id = p.id
                WHERE mm.ministry_id = :ministry_a
                  AND mm.' . self::CURRENT_MEMBER;

        $params = [
            ':ministry_a' => $ministryId,
            ':ministry_b' => $ministryId,
            ':since_at' => $since->format('Y-m-d H:i:s'),
        ];

        if ($campusId !== null) {
            $sql .= ' AND p.campus_id = :campus_id';
            $params[':campus_id'] = $campusId;
        }

        $sql .= ' ORDER BY p.last_name ASC, p.first_name ASC';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $key === ':since_at' ? PDO::PARAM_STR : PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'person_id' => (int) $row['person_id'],
                'display_name' => (string) $row['display_name'],
                'primary_campus_id' => $row['primary_campus_id'] === null ? null : (int) $row['primary_campus_id'],
                'assignment_count' => (int) $row['assignment_count'],
                'last_served_at' => $row['last_served_at'] === null ? null : new DateTimeImmutable((string) $row['last_served_at']),
                'roles_served' => (string) $row['roles_served'],
            ];
        }

        return $rows;
    }

    public function listPeopleDirectory(
        DateTimeImmutable $since,
        ?int $campusId = null,
        ?int $personId = null,
    ): array {
        if ($this->connection === null) {
            return [];
        }

        $sql = 'SELECT p.id AS person_id,
                       p.first_name AS first_name,
                       p.last_name AS last_name,
                       TRIM(CONCAT_WS(\' \', p.first_name, p.last_name)) AS display_name,
                       p.household_id AS household_id,
                       p.membership_status_id AS membership_status_id,
                       COALESCE(ms.name, \'\') AS membership_status_name,
                       p.member_type_id AS member_type_id,
                       COALESCE(mt.name, \'\') AS member_type_name,
                       h.name AS household_name,
                       h.deactivated_on AS household_deactivated_on,
                       p.birth_month AS birth_month,
                       p.birth_day AS birth_day,
                       p.birth_year AS birth_year,
                       p.created_at AS created_at,
                       p.updated_at AS updated_at,
                       p.email AS email,
                       p.mobile_phone AS mobile_phone,
                       p.home_phone AS home_phone,
                       p.address_line1 AS address_line1,
                       p.address_line2 AS address_line2,
                       p.city AS city,
                       p.region AS region,
                       p.postal_code AS postal_code,
                       p.country AS country,
                       p.campus_id AS primary_campus_id,
                       c.name AS primary_campus_name,
                       COALESCE(stats.assignment_count, 0) AS assignment_count,
                       stats.last_served_at AS last_served_at,
                       COALESCE(stats.roles_served, \'\') AS roles_served,
                       COALESCE(memberships.ministry_count, 0) AS ministry_count,
                       COALESCE(memberships.ministries, \'\') AS ministries
                  FROM people p
                  LEFT JOIN households h
                         ON h.id = p.household_id
                  LEFT JOIN membership_statuses ms
                         ON ms.id = p.membership_status_id
                  LEFT JOIN member_types mt
                         ON mt.id = p.member_type_id
                  LEFT JOIN campuses c
                         ON c.id = p.campus_id
                  LEFT JOIN (
                      SELECT
                          a.person_id                              AS person_id,
                          COUNT(*)                                 AS assignment_count,
                          MAX(eo.starts_at)                        AS last_served_at,
                          GROUP_CONCAT(DISTINCT r.name
                                       ORDER BY r.sort_order, r.name
                                       SEPARATOR \', \' )          AS roles_served
                        FROM assignments a
                        INNER JOIN event_occurrences eo
                                ON eo.id = a.occurrence_id
                        INNER JOIN serving_roles r
                                ON r.id = a.serving_role_id
                       WHERE eo.starts_at >= :since_at
                       GROUP BY a.person_id
                  ) stats ON stats.person_id = p.id
                  LEFT JOIN (
                      SELECT
                          mm.person_id AS person_id,
                          COUNT(DISTINCT m.id) AS ministry_count,
                          GROUP_CONCAT(DISTINCT m.name
                                       ORDER BY m.name ASC
                                       SEPARATOR \', \' ) AS ministries
                        FROM ministry_members mm
                        INNER JOIN ministries m
                                ON m.id = mm.ministry_id
                       WHERE mm.' . self::CURRENT_MEMBER . '
                       GROUP BY mm.person_id
                  ) memberships ON memberships.person_id = p.id';

        $params = [
            ':since_at' => $since->format('Y-m-d H:i:s'),
        ];

        $where = [];
        if ($campusId !== null) {
            $where[] = 'p.campus_id = :campus_id';
            $params[':campus_id'] = $campusId;
        }

        if ($personId !== null) {
            $where[] = 'p.id = :person_id';
            $params[':person_id'] = $personId;
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY p.last_name ASC, p.first_name ASC';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $key === ':since_at' ? PDO::PARAM_STR : PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'person_id' => (int) $row['person_id'],
                'first_name' => (string) $row['first_name'],
                'last_name' => (string) $row['last_name'],
                'display_name' => (string) $row['display_name'],
                'household_id' => $row['household_id'] === null ? null : (int) $row['household_id'],
                'membership_status_id' => $row['membership_status_id'] === null ? null : (int) $row['membership_status_id'],
                'membership_status_name' => (string) ($row['membership_status_name'] ?? ''),
                'member_type_id' => $row['member_type_id'] === null ? null : (int) $row['member_type_id'],
                'member_type_name' => (string) ($row['member_type_name'] ?? ''),
                'household_name' => (string) ($row['household_name'] ?? ''),
                'household_deactivated_on' => $row['household_deactivated_on'] === null ? null : (string) $row['household_deactivated_on'],
                'birth_month' => $row['birth_month'] === null ? null : (int) $row['birth_month'],
                'birth_day' => $row['birth_day'] === null ? null : (int) $row['birth_day'],
                'birth_year' => $row['birth_year'] === null ? null : (int) $row['birth_year'],
                'created_at' => $row['created_at'] === null ? null : new DateTimeImmutable((string) $row['created_at']),
                'updated_at' => $row['updated_at'] === null ? null : new DateTimeImmutable((string) $row['updated_at']),
                'email' => (string) ($row['email'] ?? ''),
                'mobile_phone' => (string) ($row['mobile_phone'] ?? ''),
                'home_phone' => (string) ($row['home_phone'] ?? ''),
                'address_line1' => (string) ($row['address_line1'] ?? ''),
                'address_line2' => (string) ($row['address_line2'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'region' => (string) ($row['region'] ?? ''),
                'postal_code' => (string) ($row['postal_code'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'primary_campus_id' => $row['primary_campus_id'] === null ? null : (int) $row['primary_campus_id'],
                'primary_campus_name' => (string) ($row['primary_campus_name'] ?? ''),
                'assignment_count' => (int) $row['assignment_count'],
                'last_served_at' => $row['last_served_at'] === null ? null : new DateTimeImmutable((string) $row['last_served_at']),
                'roles_served' => (string) $row['roles_served'],
                'ministry_count' => (int) $row['ministry_count'],
                'ministries' => (string) $row['ministries'],
            ];
        }

        return $rows;
    }

    private static function normalizePathSegment(string $value): string
    {
        $decoded = rawurldecode($value);
        $ascii = function_exists('iconv')
            ? (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $decoded) ?: $decoded)
            : $decoded;
        $normalized = strtolower((string) $ascii);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        return trim($normalized, '-');
    }

    /**
     * A slug for a new ministry that no other ministry already has.
     */
    private function uniqueSlug(string $name): string
    {
        $base = self::normalizePathSegment($name);
        if ($base === '') {
            $base = 'ministry';
        }
        $base = substr($base, 0, 90);

        $check = $this->connection?->prepare('SELECT 1 FROM ministries WHERE slug = :slug LIMIT 1');
        $slug = $base;
        for ($n = 2; $check !== null; $n++) {
            $check->execute([':slug' => $slug]);
            if ($check->fetchColumn() === false) {
                break;
            }
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

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
     * The serving roles of a ministry. "Assigned" is who the serving schedule
     * has put in the role: a serving role is not a membership.
     */
    public function fetchMinistryRoles(int $ministryId): array
    {
        if ($this->connection === null) {
            return [];
        }

        $sql = 'SELECT r.id,
                       r.name,
                       r.sort_order,
                       r.is_active,
                       COUNT(DISTINCT a.person_id) AS assigned_count
                  FROM serving_roles r
                  LEFT JOIN assignments a
                         ON a.serving_role_id = r.id
                 WHERE r.ministry_id = :ministry_id
                 GROUP BY r.id, r.name, r.sort_order, r.is_active
                 ORDER BY r.sort_order ASC, r.name ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        $stmt->execute();

        $roles = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $roles[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'sort_order' => (int) $row['sort_order'],
                'is_active' => (bool) $row['is_active'],
                'assigned_count' => (int) $row['assigned_count'],
                'assigned_members' => $this->assignedMembers((int) $row['id']),
            ];
        }

        return $roles;
    }

    /**
     * @return list<array{person_id:int,display_name:string}>
     */
    private function assignedMembers(int $servingRoleId): array
    {
        if ($this->connection === null) {
            return [];
        }

        $stmt = $this->connection->prepare(
            'SELECT DISTINCT p.id AS person_id,
                    TRIM(CONCAT_WS(\' \', p.first_name, p.last_name)) AS display_name,
                    p.last_name, p.first_name
               FROM assignments a
               INNER JOIN people p ON p.id = a.person_id
              WHERE a.serving_role_id = :role_id
              ORDER BY p.last_name ASC, p.first_name ASC'
        );
        $stmt->bindValue(':role_id', $servingRoleId, PDO::PARAM_INT);
        $stmt->execute();

        $members = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $member) {
            $members[] = [
                'person_id' => (int) $member['person_id'],
                'display_name' => (string) $member['display_name'],
            ];
        }

        return $members;
    }

    public function fetchMinistryLeaders(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusWhere = $campusId !== null ? ' AND p.campus_id = :campus_id' : '';

        $sql = 'SELECT
                p.id AS person_id,
                TRIM(CONCAT_WS(\' \', p.first_name, p.last_name)) AS display_name,
                p.campus_id AS primary_campus_id,
                COALESCE(stats.assignment_count, 0) AS assignment_count,
                stats.last_served_at AS last_served_at,
                COALESCE(stats.roles_served, \'\') AS roles_served
            FROM ministry_members mm
            INNER JOIN people p ON p.id = mm.person_id
            LEFT JOIN (
                SELECT
                    a.person_id AS person_id,
                    COUNT(*) AS assignment_count,
                    MAX(eo.starts_at) AS last_served_at,
                    GROUP_CONCAT(DISTINCT r_inner.name
                                 ORDER BY r_inner.sort_order, r_inner.name
                                 SEPARATOR \', \') AS roles_served
                FROM assignments a
                INNER JOIN event_occurrences eo ON eo.id = a.occurrence_id
                INNER JOIN serving_roles r_inner ON r_inner.id = a.serving_role_id
                WHERE r_inner.ministry_id = :ministry_id
                  AND eo.starts_at >= :since_at
                GROUP BY a.person_id
            ) stats ON stats.person_id = p.id
            WHERE mm.ministry_id = :ministry_member_id
              AND mm.role = \'leader\'
              AND mm.' . self::CURRENT_MEMBER
            . $campusWhere . '
            ORDER BY p.last_name ASC, p.first_name ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':ministry_member_id', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':since_at', $since->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        if ($campusId !== null) {
            $stmt->bindValue(':campus_id', $campusId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $leaders = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $leaders[] = [
                'person_id' => (int) $row['person_id'],
                'display_name' => (string) $row['display_name'],
                'primary_campus_id' => $row['primary_campus_id'] === null ? null : (int) $row['primary_campus_id'],
                'assignment_count' => (int) $row['assignment_count'],
                'last_served_at' => $row['last_served_at'] === null ? null : new DateTimeImmutable((string) $row['last_served_at']),
                'roles_served' => (string) $row['roles_served'],
                'leader_roles' => ['Leader'],
                'regular_roles' => [],
            ];
        }

        return $leaders;
    }

    public function createMinistryRole(int $ministryId, array $data): array
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Database connection not available');
        }

        $sql = 'INSERT INTO serving_roles (name, ministry_id, sort_order, is_active)
                VALUES (:name, :ministry_id, :sort_order, :is_active)';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':sort_order', (int) ($data['order'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':is_active', ($data['active'] ?? true) ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        $roleId = (int) $this->connection->lastInsertId();

        return [
            'id' => $roleId,
            'name' => (string) $data['name'],
            'sort_order' => (int) ($data['order'] ?? 0),
            'is_active' => (bool) ($data['active'] ?? true),
        ];
    }

    public function updateMinistryRole(int $roleId, array $data): array
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Database connection not available');
        }

        $setParts = [];
        $params = [':role_id' => $roleId];

        if (isset($data['name'])) {
            $setParts[] = 'name = :name';
            $params[':name'] = $data['name'];
        }

        if (isset($data['order'])) {
            $setParts[] = 'sort_order = :sort_order';
            $params[':sort_order'] = (int) $data['order'];
        }

        if (isset($data['active'])) {
            $setParts[] = 'is_active = :is_active';
            $params[':is_active'] = $data['active'] ? 1 : 0;
        }

        if (empty($setParts)) {
            throw new \InvalidArgumentException('No valid fields to update');
        }

        $sql = 'UPDATE serving_roles SET ' . implode(', ', $setParts) . ' WHERE id = :role_id';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $key === ':name' ? PDO::PARAM_STR : PDO::PARAM_INT);
        }
        $stmt->execute();

        $fetchSql = 'SELECT r.id,
                            r.name,
                            r.sort_order,
                            r.is_active,
                            COUNT(DISTINCT a.person_id) AS assigned_count
                       FROM serving_roles r
                       LEFT JOIN assignments a
                              ON a.serving_role_id = r.id
                      WHERE r.id = :role_id
                      GROUP BY r.id, r.name, r.sort_order, r.is_active';

        $fetchStmt = $this->connection->prepare($fetchSql);
        $fetchStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
        $fetchStmt->execute();

        $role = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        if ($role === false) {
            throw new \RuntimeException('Role not found after update');
        }

        return [
            'id' => (int) $role['id'],
            'name' => (string) $role['name'],
            'sort_order' => (int) $role['sort_order'],
            'is_active' => (bool) $role['is_active'],
            'assigned_count' => (int) $role['assigned_count'],
            'assigned_members' => $this->assignedMembers($roleId),
        ];
    }

    public function findMinistryIdForRole(int $roleId): ?int
    {
        if ($this->connection === null) {
            return null;
        }
        $stmt = $this->connection->prepare('SELECT ministry_id FROM serving_roles WHERE id = :role_id LIMIT 1');
        $stmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id === false || $id === null ? null : (int) $id;
    }

    public function deleteMinistryRole(int $roleId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            // Schedule assignments in the role go with it; roster slots that
            // named it keep their place with no role (ON DELETE SET NULL).
            $deleteScheduleAssignmentsStmt = $this->connection->prepare('DELETE FROM assignments WHERE serving_role_id = :role_id');
            $deleteScheduleAssignmentsStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $deleteScheduleAssignmentsStmt->execute();

            $deleteRoleStmt = $this->connection->prepare('DELETE FROM serving_roles WHERE id = :role_id');
            $deleteRoleStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $deleteRoleStmt->execute();

            return $deleteRoleStmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Put a person in a serving role's ministry. A serving role has no
     * membership of its own — who serves in it on a date is the schedule's
     * business — so this makes the person a member of the role's ministry
     * (as the legacy person-to-role row did) and leaves an existing
     * membership as it is.
     */
    public function assignPersonToRole(int $personId, int $roleId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            $ministryStmt = $this->connection->prepare('SELECT ministry_id FROM serving_roles WHERE id = :role_id');
            $ministryStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $ministryStmt->execute();

            $ministryId = $ministryStmt->fetchColumn();
            if ($ministryId === false) {
                return false;
            }

            $insertStmt = $this->connection->prepare(
                "INSERT IGNORE INTO ministry_members (person_id, ministry_id, role, status)
                 VALUES (:person_id, :ministry_id, 'member', 'confirmed')"
            );
            $insertStmt->bindValue(':person_id', $personId, PDO::PARAM_INT);
            $insertStmt->bindValue(':ministry_id', (int) $ministryId, PDO::PARAM_INT);
            $insertStmt->execute();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Nothing ties a person to a serving role outside the schedule, so there
     * is nothing to remove here: membership is removed with
     * removeMemberFromMinistry, and dates in the role on the serving schedule.
     */
    public function removePersonFromRole(int $personId, int $roleId): bool
    {
        return false;
    }
}
