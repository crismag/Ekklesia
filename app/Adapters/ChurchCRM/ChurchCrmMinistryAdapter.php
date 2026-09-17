<?php

declare(strict_types=1);

namespace App\Adapters\ChurchCRM;

use App\Contracts\MinistryAdapter;
use DateTimeImmutable;
use PDO;

/**
 * ChurchCRM-specific ministry data adapter.
 */
final class ChurchCrmMinistryAdapter implements MinistryAdapter
{
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
            'SELECT g.grp_ID AS ministry_id,
                    g.grp_Name AS name,
                    mr.campus_id AS campus_id
               FROM group_grp g
               LEFT JOIN ministry_registry mr ON mr.group_id = g.grp_ID
              WHERE g.grp_ID = :ministry_id
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

        $sql = 'SELECT g.grp_ID AS ministry_id,
                       g.grp_Name AS name,
                       mr.campus_id AS campus_id
                  FROM group_grp g
                  LEFT JOIN ministry_registry mr ON mr.group_id = g.grp_ID';
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
            $sql .= ' WHERE g.grp_ID IN (' . implode(', ', $placeholders) . ')';
        } else {
            $sql .= ' WHERE g.grp_active = 1';
        }

        $sql .= ' ORDER BY g.grp_Name ASC, g.grp_ID ASC';

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
     * Admin listing of ALL ministry groups (active + inactive) with member and
     * role counts. Used by the groups_and_ministries admin page.
     *
     * @return list<array{ministry_id:int,name:string,active:bool,campus_id:?int,member_count:int,leader_count:int,role_count:int}>
     */
    public function listMinistriesAdmin(?int $campusId = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        // When a campus is selected, counts reflect that campus's primary-campus
        // members (so they match the campus-filtered roster).
        $campusMemberJoin = $campusId !== null
            ? 'INNER JOIN person_campus_affiliation pcm
                       ON pcm.person_id = j.p2g2r_per_ID
                      AND pcm.campus_id = :campus_count
                      AND pcm.is_primary = 1'
            : '';
        $campusLeaderJoin = $campusId !== null
            ? 'INNER JOIN person_campus_affiliation pcl
                       ON pcl.person_id = j.p2g2r_per_ID
                      AND pcl.campus_id = :campus_lead
                      AND pcl.is_primary = 1'
            : '';

        $memberCountSql = '(SELECT COUNT(DISTINCT j.p2g2r_per_ID)
                   FROM person2group2role_p2g2r j
                   ' . $campusMemberJoin . '
                  WHERE j.p2g2r_grp_ID = g.grp_ID)';

        // Leader = tagged in ministry_leaders OR a ChurchCRM leader-named role.
        $leaderCountSql = '(SELECT COUNT(DISTINCT j.p2g2r_per_ID)
                   FROM person2group2role_p2g2r j
                   ' . $campusLeaderJoin . '
                   LEFT JOIN roles prl
                          ON prl.role_id = j.p2g2r_rle_ID
                         AND prl.ministry_group_id = g.grp_ID
                   LEFT JOIN list_lst lll
                          ON lll.lst_ID = g.grp_RoleListID
                         AND lll.lst_OptionID = j.p2g2r_rle_ID
                   LEFT JOIN ministry_leaders mll
                          ON mll.ministry_group_id = g.grp_ID
                         AND mll.person_id = j.p2g2r_per_ID
                  WHERE j.p2g2r_grp_ID = g.grp_ID
                    AND (mll.person_id IS NOT NULL
                         OR COALESCE(lll.lst_OptionName, prl.role_name, \'\')
                            REGEXP \'leader|head|coordinator|director|pastor\'))';

        $sql = 'SELECT g.grp_ID AS ministry_id,
                       g.grp_Name AS name,
                       g.grp_active AS active,
                       mr.campus_id AS campus_id,
                       ' . $memberCountSql . ' AS member_count,
                       ' . $leaderCountSql . ' AS leader_count,
                       (SELECT COUNT(*)
                          FROM roles r
                         WHERE r.ministry_group_id = g.grp_ID) AS role_count
                  FROM group_grp g
                  LEFT JOIN ministry_registry mr ON mr.group_id = g.grp_ID';

        if ($campusId !== null) {
            // Ministries assigned to this campus, plus shared/unassigned ones.
            $sql .= ' WHERE (mr.campus_id = :campus_filter OR mr.campus_id IS NULL)';
        }

        $sql .= ' ORDER BY g.grp_active DESC, g.grp_Name ASC, g.grp_ID ASC';

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
     * filter. A leader is a member who is tagged (ministry_leaders) OR holds a
     * ChurchCRM leader-named role.
     *
     * @return list<array{ministry_id:int,person_id:int,display_name:string}>
     */
    public function listLeadersByMinistry(?int $campusId = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusMemberJoin = $campusId !== null
            ? 'INNER JOIN person_campus_affiliation pca
                       ON pca.person_id = p.per_ID
                      AND pca.campus_id = :campus_m
                      AND pca.is_primary = 1'
            : '';
        $ministryWhere = $campusId !== null
            ? ' AND (mr.campus_id = :campus_f OR mr.campus_id IS NULL)'
            : '';

        $sql = 'SELECT DISTINCT g.grp_ID AS ministry_id,
                       p.per_ID AS person_id,
                       TRIM(CONCAT_WS(\' \', p.per_FirstName, p.per_LastName)) AS display_name
                  FROM group_grp g
                  LEFT JOIN ministry_registry mr ON mr.group_id = g.grp_ID
                  INNER JOIN person2group2role_p2g2r j ON j.p2g2r_grp_ID = g.grp_ID
                  INNER JOIN person_per p ON p.per_ID = j.p2g2r_per_ID
                  ' . $campusMemberJoin . '
                  LEFT JOIN roles pr
                         ON pr.role_id = j.p2g2r_rle_ID
                        AND pr.ministry_group_id = g.grp_ID
                  LEFT JOIN list_lst ll
                         ON ll.lst_ID = g.grp_RoleListID
                        AND ll.lst_OptionID = j.p2g2r_rle_ID
                  LEFT JOIN ministry_leaders ml
                         ON ml.ministry_group_id = g.grp_ID
                        AND ml.person_id = p.per_ID
                 WHERE (ml.person_id IS NOT NULL
                        OR COALESCE(ll.lst_OptionName, pr.role_name, \'\')
                           REGEXP \'leader|head|coordinator|director|pastor\')'
                 . $ministryWhere . '
                 ORDER BY p.per_LastName ASC, p.per_FirstName ASC';

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
     * @param array{name:string,description?:string,type?:int,campus_id?:?int} $data
     * @return array<string,mixed>
     */
    public function createMinistry(array $data): array
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Database connection not available');
        }

        $stmt = $this->connection->prepare(
            'INSERT INTO group_grp (grp_Name, grp_Description, grp_Type, grp_active)
             VALUES (:name, :description, :type, 1)'
        );
        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':description', (string) ($data['description'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue(':type', (int) ($data['type'] ?? 1), PDO::PARAM_INT);
        $stmt->execute();

        $ministryId = (int) $this->connection->lastInsertId();

        $campusId = isset($data['campus_id']) && (int) $data['campus_id'] > 0 ? (int) $data['campus_id'] : null;
        $this->upsertMinistryRegistry($ministryId, $campusId);

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
     * @param array{name:string,description?:string,campus_id?:?int} $data
     * @return array<string,mixed>
     */
    public function updateMinistry(int $ministryId, array $data): array
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Database connection not available');
        }

        $stmt = $this->connection->prepare(
            'UPDATE group_grp SET grp_Name = :name, grp_Description = :description WHERE grp_ID = :id'
        );
        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':description', (string) ($data['description'] ?? ''), PDO::PARAM_STR);
        $stmt->bindValue(':id', $ministryId, PDO::PARAM_INT);
        $stmt->execute();

        if (array_key_exists('campus_id', $data)) {
            $campusId = (int) $data['campus_id'] > 0 ? (int) $data['campus_id'] : null;
            $this->upsertMinistryRegistry($ministryId, $campusId);
        }

        return $this->findMinistry($ministryId)
            ?? ['ministry_id' => $ministryId, 'name' => (string) ($data['name'] ?? ''), 'campus_id' => null];
    }

    public function setMinistryActive(int $ministryId, bool $active): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare('UPDATE group_grp SET grp_active = :a WHERE grp_ID = :id');
        $stmt->bindValue(':a', $active ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $ministryId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * Hard delete: remove schedule assignments, memberships, roles, the portal
     * registry row, and finally the group itself — inside a transaction.
     */
    public function deleteMinistry(int $ministryId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $this->connection->beginTransaction();
        try {
            $this->connection->prepare(
                'DELETE a FROM assignment a
                   INNER JOIN roles r ON r.role_id = a.role_id
                  WHERE r.ministry_group_id = :id'
            )->execute([':id' => $ministryId]);

            $this->connection->prepare('DELETE FROM person2group2role_p2g2r WHERE p2g2r_grp_ID = :id')
                ->execute([':id' => $ministryId]);

            $this->connection->prepare('DELETE FROM roles WHERE ministry_group_id = :id')
                ->execute([':id' => $ministryId]);

            $this->connection->prepare('DELETE FROM ministry_registry WHERE group_id = :id')
                ->execute([':id' => $ministryId]);

            $this->connection->prepare('DELETE FROM ministry_leaders WHERE ministry_group_id = :id')
                ->execute([':id' => $ministryId]);

            $this->connection->prepare('DELETE FROM group_grp WHERE grp_ID = :id')
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
     * Every member of a ministry group (the full person2group2role roster),
     * with each member's role resolved — preferring the portal `roles` table,
     * falling back to ChurchCRM's native role list, else blank. This is the
     * authoritative membership list (unlike the role-driven view, which only
     * sees members assigned to a portal-defined role).
     *
     * @return list<array{person_id:int,display_name:string,role_id:int,role_name:string,is_leader:bool}>
     */
    public function listMinistryMembers(int $ministryId, ?int $campusId = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusJoin = $campusId !== null
            ? ' INNER JOIN person_campus_affiliation pcaf
                     ON pcaf.person_id = p.per_ID
                    AND pcaf.campus_id = :campus_id
                    AND pcaf.is_primary = 1'
            : '';

        $sql = 'SELECT p.per_ID AS person_id,
                       TRIM(CONCAT_WS(\' \', p.per_FirstName, p.per_LastName)) AS display_name,
                       j.p2g2r_rle_ID AS role_id,
                       COALESCE(ll.lst_OptionName, pr.role_name, \'\') AS role_name,
                       (lead.person_id IS NOT NULL) AS tagged_leader
                  FROM person2group2role_p2g2r j
                  INNER JOIN person_per p ON p.per_ID = j.p2g2r_per_ID'
                  . $campusJoin . '
                  LEFT JOIN roles pr
                         ON pr.role_id = j.p2g2r_rle_ID
                        AND pr.ministry_group_id = j.p2g2r_grp_ID
                  LEFT JOIN group_grp g ON g.grp_ID = j.p2g2r_grp_ID
                  LEFT JOIN list_lst ll
                         ON ll.lst_ID = g.grp_RoleListID
                        AND ll.lst_OptionID = j.p2g2r_rle_ID
                  LEFT JOIN ministry_leaders lead
                         ON lead.ministry_group_id = j.p2g2r_grp_ID
                        AND lead.person_id = p.per_ID
                 WHERE j.p2g2r_grp_ID = :ministry_id
                 ORDER BY p.per_LastName ASC, p.per_FirstName ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        if ($campusId !== null) {
            $stmt->bindValue(':campus_id', $campusId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $roleName = (string) $r['role_name'];
            // Leader = explicitly tagged (ministry_leaders) OR a ChurchCRM
            // leader-named role. Tagging never changes the member's role.
            $isLeader = (int) $r['tagged_leader'] === 1
                || preg_match('/leader|head|coordinator|director|pastor/i', $roleName) === 1;
            $rows[] = [
                'person_id'   => (int) $r['person_id'],
                'display_name' => (string) $r['display_name'],
                'role_id'     => (int) $r['role_id'],
                'role_name'   => $roleName !== '' ? $roleName : 'Member',
                'is_leader'   => $isLeader,
            ];
        }

        return $rows;
    }

    /**
     * Group roles (member types like Member / Leader / Teacher) from the group's
     * ChurchCRM role list (grp_RoleListID -> list_lst). Distinct from ministry
     * assignment roles (the `roles` table). Empty when the group has no list.
     *
     * @return list<array{id:int,name:string,is_default:bool}>
     */
    public function listGroupRoles(int $ministryId): array
    {
        if ($this->connection === null) {
            return [];
        }

        $g = $this->connection->prepare('SELECT grp_RoleListID, grp_DefaultRole FROM group_grp WHERE grp_ID = :id');
        $g->bindValue(':id', $ministryId, PDO::PARAM_INT);
        $g->execute();
        $group = $g->fetch(PDO::FETCH_ASSOC);
        if ($group === false) {
            return [];
        }
        $roleListId = (int) $group['grp_RoleListID'];
        $defaultRole = (int) $group['grp_DefaultRole'];

        $stmt = $this->connection->prepare(
            'SELECT lst_OptionID AS id, lst_OptionName AS name
               FROM list_lst
              WHERE lst_ID = :rl
              ORDER BY lst_OptionSequence ASC, lst_OptionName ASC'
        );
        $stmt->bindValue(':rl', $roleListId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        $hasZero = false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int) $r['id'];
            if ($id === 0) {
                $hasZero = true;
            }
            $rows[] = ['id' => $id, 'name' => (string) $r['name'], 'is_default' => $id === $defaultRole];
        }

        // ChurchCRM treats a member with no explicit role (rle_ID 0) as a regular
        // "Member"; that option is not stored, so surface it as the default.
        if (!$hasZero) {
            array_unshift($rows, ['id' => 0, 'name' => 'Member', 'is_default' => $defaultRole === 0]);
        }

        return $rows;
    }

    public function addMinistryLeader(int $ministryId, int $personId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare(
            'INSERT IGNORE INTO ministry_leaders (ministry_group_id, person_id) VALUES (:g, :p)'
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
            'DELETE FROM ministry_leaders WHERE ministry_group_id = :g AND person_id = :p'
        );
        $stmt->bindValue(':g', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':p', $personId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * Set a member's single role within a ministry (person2group2role has a
     * (person, group) primary key, so each member holds exactly one role).
     * Upserts: adds the membership if absent, otherwise changes the role.
     * roleId 0 means "member, no specific role".
     */
    /**
     * Current ministry memberships for a set of people.
     *
     * Batched deliberately: the import needs this for every row it applies, and
     * a query per person turns a 250-member import into 250 round trips.
     *
     * @param list<int> $personIds
     * @return array<int,list<int>> person id => ministry group ids
     */
    public function listMinistryIdsForPeople(array $personIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $personIds), static fn (int $i): bool => $i > 0)));
        if ($this->connection === null || $ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->connection->prepare(
            'SELECT p2g2r_per_ID AS person_id, p2g2r_grp_ID AS ministry_id
               FROM person2group2role_p2g2r
              WHERE p2g2r_per_ID IN (' . $placeholders . ')'
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['person_id']][] = (int) $row['ministry_id'];
        }

        return $out;
    }

    public function setMemberRole(int $personId, int $ministryId, int $roleId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $stmt = $this->connection->prepare(
            'INSERT INTO person2group2role_p2g2r (p2g2r_per_ID, p2g2r_grp_ID, p2g2r_rle_ID)
             VALUES (:p, :g, :r)
             ON DUPLICATE KEY UPDATE p2g2r_rle_ID = VALUES(p2g2r_rle_ID)'
        );
        $stmt->bindValue(':p', $personId, PDO::PARAM_INT);
        $stmt->bindValue(':g', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':r', $roleId, PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * Remove a person from a ministry entirely (their single membership row).
     */
    public function removeMemberFromMinistry(int $personId, int $ministryId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $stmt = $this->connection->prepare(
            'DELETE FROM person2group2role_p2g2r WHERE p2g2r_per_ID = :p AND p2g2r_grp_ID = :g'
        );
        $stmt->bindValue(':p', $personId, PDO::PARAM_INT);
        $stmt->bindValue(':g', $ministryId, PDO::PARAM_INT);
        $stmt->execute();

        $this->removeMinistryLeader($ministryId, $personId);

        return true;
    }

    private function upsertMinistryRegistry(int $ministryId, ?int $campusId): void
    {
        if ($this->connection === null) {
            return;
        }

        $exists = $this->connection->prepare('SELECT 1 FROM ministry_registry WHERE group_id = :id LIMIT 1');
        $exists->execute([':id' => $ministryId]);

        if ($exists->fetchColumn() !== false) {
            $stmt = $this->connection->prepare('UPDATE ministry_registry SET campus_id = :c, is_ministry = 1 WHERE group_id = :id');
        } else {
            $stmt = $this->connection->prepare('INSERT INTO ministry_registry (group_id, is_ministry, campus_id) VALUES (:id, 1, :c)');
        }
        $stmt->bindValue(':id', $ministryId, PDO::PARAM_INT);
        if ($campusId === null) {
            $stmt->bindValue(':c', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':c', $campusId, PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    public function listCampuses(): array
    {
        if ($this->connection === null) {
            return [];
        }

        $stmt = $this->connection->query(
            'SELECT campus_id, campus_name, is_main
               FROM church_campus
              WHERE is_active = 1
              ORDER BY is_main DESC, campus_name ASC, campus_id ASC'
        );

        $rows = [];
        foreach ($stmt?->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'campus_id' => (int) $row['campus_id'],
                'campus_name' => (string) $row['campus_name'],
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
               FROM person_campus_affiliation
              WHERE person_id = :person_id
                AND is_primary = 1
              ORDER BY campus_id ASC
              LIMIT 1'
        );
        $stmt->bindValue(':person_id', $personId, PDO::PARAM_INT);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function findDisplayNameForPerson(int $personId): ?string
    {
        if ($this->connection === null) {
            return null;
        }

        $stmt = $this->connection->prepare(
            'SELECT TRIM(CONCAT_WS(" ", per_FirstName, per_LastName)) AS display_name
               FROM person_per
              WHERE per_ID = :person_id
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
            'SELECT g.grp_ID AS ministry_id,
                    g.grp_Name AS name,
                    mr.campus_id AS campus_id
               FROM group_grp g
               LEFT JOIN ministry_registry mr ON mr.group_id = g.grp_ID
              WHERE mr.campus_id = :campus_id
                 OR mr.campus_id IS NULL
              ORDER BY g.grp_Name ASC, g.grp_ID ASC'
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

        $summarySql = 'SELECT g.grp_ID AS ministry_id,
                              g.grp_Name AS name,
                              mr.campus_id AS campus_id,
                              COALESCE(SUM(CASE
                                  WHEN eo.occurrence_start >= :since_at
                                   AND eo.occurrence_start < :past_until_at
                                  THEN 1 ELSE 0 END), 0) AS past_assignment_count,
                              COALESCE(SUM(CASE
                                  WHEN eo.occurrence_start >= :upcoming_from_at
                                   AND eo.occurrence_start < :upcoming_until_at
                                  THEN 1 ELSE 0 END), 0) AS upcoming_assignment_count,
                              MIN(CASE
                                  WHEN eo.occurrence_start >= :next_from_at
                                   AND eo.occurrence_start < :next_until_at
                                  THEN eo.occurrence_start ELSE NULL END) AS next_occurrence_at,
                              MAX(CASE
                                  WHEN eo.occurrence_start >= :last_from_at
                                   AND eo.occurrence_start < :last_until_at
                                  THEN eo.occurrence_start ELSE NULL END) AS last_occurrence_at
                         FROM group_grp g
                         LEFT JOIN ministry_registry mr ON mr.group_id = g.grp_ID
                         LEFT JOIN roles r ON r.ministry_group_id = g.grp_ID AND r.active = 1
                         LEFT JOIN assignment a ON a.role_id = r.role_id
                         LEFT JOIN event_occurrence eo ON eo.occurrence_id = a.occurrence_id';

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
            $summarySql .= ' WHERE g.grp_ID IN (' . implode(', ', $placeholders) . ')';
        }

        $summarySql .= ' GROUP BY g.grp_ID, g.grp_Name, mr.campus_id
                         ORDER BY g.grp_Name ASC, g.grp_ID ASC';

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

        $occurrenceSql = 'SELECT r.ministry_group_id AS ministry_id,
                                 eo.occurrence_id AS occurrence_id,
                                 COALESCE(ee.event_title, \'\') AS event_title,
                                 eo.occurrence_start AS starts_on,
                                 eo.occurrence_end AS ends_on,
                                 COUNT(a.assignment_id) AS assignment_count
                            FROM assignment a
                            INNER JOIN roles r ON r.role_id = a.role_id
                            INNER JOIN event_occurrence eo ON eo.occurrence_id = a.occurrence_id
                            LEFT JOIN events_event ee ON ee.event_id = eo.event_id
                           WHERE eo.occurrence_start >= :upcoming_at
                             AND eo.occurrence_start < :until_at';

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
            $occurrenceSql .= ' AND r.ministry_group_id IN (' . implode(', ', $placeholders) . ')';
        }

        $occurrenceSql .= ' GROUP BY r.ministry_group_id, eo.occurrence_id, ee.event_title, eo.occurrence_start, eo.occurrence_end
                            ORDER BY r.ministry_group_id ASC, eo.occurrence_start ASC, eo.occurrence_id ASC';

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
                p.per_ID                              AS person_id,
                TRIM(CONCAT_WS(\' \' , p.per_FirstName, p.per_LastName)) AS display_name,
                    pca.campus_id                         AS primary_campus_id,
                    COALESCE(stats.assignment_count, 0)   AS assignment_count,
                    stats.last_served_at                  AS last_served_at,
                    COALESCE(stats.roles_served, \'\')    AS roles_served
                FROM person2group2role_p2g2r p2g
                INNER JOIN person_per p
                       ON p.per_ID = p2g.p2g2r_per_ID
                LEFT JOIN person_campus_affiliation pca
                       ON pca.person_id = p.per_ID
                      AND pca.is_primary = 1
                LEFT JOIN (
                    SELECT
                        a.person_id                              AS person_id,
                        COUNT(*)                                 AS assignment_count,
                        MAX(eo.occurrence_start)                 AS last_served_at,
                        GROUP_CONCAT(DISTINCT r.role_name
                                     ORDER BY r.role_order, r.role_name
                                     SEPARATOR \', \' )          AS roles_served
                    FROM assignment a
                    INNER JOIN event_occurrence eo
                            ON eo.occurrence_id = a.occurrence_id
                    INNER JOIN roles r
                            ON r.role_id = a.role_id
                    WHERE r.ministry_group_id = :ministry_b
                      AND eo.occurrence_start >= :since_at
                    GROUP BY a.person_id
                ) stats ON stats.person_id = p.per_ID';

        $params = [
            ':ministry_a' => $ministryId,
            ':ministry_b' => $ministryId,
            ':since_at' => $since->format('Y-m-d H:i:s'),
        ];

        if ($campusId !== null) {
            $sql .= ' INNER JOIN person_campus_affiliation pca_filter
                          ON pca_filter.person_id = p.per_ID
                         AND pca_filter.campus_id = :campus_id';
            $params[':campus_id'] = $campusId;
        }

        $sql .= ' WHERE p2g.p2g2r_grp_ID = :ministry_a
                  ORDER BY p.per_LastName ASC, p.per_FirstName ASC';

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

        $sql = 'SELECT p.per_ID AS person_id,
                       p.per_FirstName AS first_name,
                       p.per_LastName AS last_name,
                       TRIM(CONCAT_WS(\' \', p.per_FirstName, p.per_LastName)) AS display_name,
                       p.per_fam_ID AS family_id,
                       p.per_cls_ID AS classification_id,
                       COALESCE(cls.lst_OptionName, \'\') AS classification_name,
                       pc.c1 AS member_type_id,
                       COALESCE(member_type.lst_OptionName, \'\') AS member_type_name,
                       f.fam_Name AS family_name,
                       f.fam_DateDeactivated AS family_deactivated_at,
                       p.per_BirthMonth AS birth_month,
                       p.per_BirthDay AS birth_day,
                       p.per_BirthYear AS birth_year,
                       p.per_DateEntered AS date_entered,
                       p.per_DateLastEdited AS date_last_edited,
                       p.per_Email AS email,
                       p.per_CellPhone AS mobile_phone,
                       p.per_HomePhone AS home_phone,
                       p.per_Address1 AS address1,
                       p.per_Address2 AS address2,
                       p.per_City AS city,
                       p.per_State AS state,
                       p.per_Zip AS zip,
                       p.per_Country AS country,
                       p.per_Facebook AS facebook,
                       p.per_LinkedIn AS linkedin,
                       pca.campus_id AS primary_campus_id,
                       c.campus_name AS primary_campus_name,
                       COALESCE(stats.assignment_count, 0) AS assignment_count,
                       stats.last_served_at AS last_served_at,
                       COALESCE(stats.roles_served, \'\') AS roles_served,
                       COALESCE(memberships.ministry_count, 0) AS ministry_count,
                       COALESCE(memberships.ministries, \'\') AS ministries
                  FROM person_per p
                  LEFT JOIN family_fam f
                         ON f.fam_ID = p.per_fam_ID
                  LEFT JOIN list_lst cls
                         ON cls.lst_ID = 1
                        AND cls.lst_OptionID = p.per_cls_ID
                  LEFT JOIN person_custom pc
                         ON pc.per_ID = p.per_ID
                  LEFT JOIN list_lst member_type
                         ON member_type.lst_ID = 13
                        AND member_type.lst_OptionID = pc.c1
                  LEFT JOIN person_campus_affiliation pca
                         ON pca.person_id = p.per_ID
                        AND pca.is_primary = 1
                  LEFT JOIN church_campus c
                         ON c.campus_id = pca.campus_id
                  LEFT JOIN (
                      SELECT
                          a.person_id                              AS person_id,
                          COUNT(*)                                 AS assignment_count,
                          MAX(eo.occurrence_start)                 AS last_served_at,
                          GROUP_CONCAT(DISTINCT r.role_name
                                       ORDER BY r.role_order, r.role_name
                                       SEPARATOR \', \' )          AS roles_served
                        FROM assignment a
                        INNER JOIN event_occurrence eo
                                ON eo.occurrence_id = a.occurrence_id
                        INNER JOIN roles r
                                ON r.role_id = a.role_id
                       WHERE eo.occurrence_start >= :since_at
                       GROUP BY a.person_id
                  ) stats ON stats.person_id = p.per_ID
                  LEFT JOIN (
                      SELECT
                          p2g.p2g2r_per_ID AS person_id,
                          COUNT(DISTINCT g.grp_ID) AS ministry_count,
                          GROUP_CONCAT(DISTINCT g.grp_Name
                                       ORDER BY g.grp_Name ASC
                                       SEPARATOR \', \' ) AS ministries
                        FROM person2group2role_p2g2r p2g
                        INNER JOIN group_grp g
                                ON g.grp_ID = p2g.p2g2r_grp_ID
                       GROUP BY p2g.p2g2r_per_ID
                  ) memberships ON memberships.person_id = p.per_ID';

        $params = [
            ':since_at' => $since->format('Y-m-d H:i:s'),
        ];

        if ($campusId !== null) {
            $sql .= ' INNER JOIN person_campus_affiliation pca_filter
                          ON pca_filter.person_id = p.per_ID
                         AND pca_filter.campus_id = :campus_id
                         AND pca_filter.is_primary = 1';
            $params[':campus_id'] = $campusId;
        }

        if ($personId !== null) {
            $sql .= ' WHERE p.per_ID = :person_id';
            $params[':person_id'] = $personId;
        }

        $sql .= ' ORDER BY p.per_LastName ASC, p.per_FirstName ASC';

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
                'family_id' => $row['family_id'] === null ? null : (int) $row['family_id'],
                'classification_id' => $row['classification_id'] === null ? null : (int) $row['classification_id'],
                'classification_name' => (string) ($row['classification_name'] ?? ''),
                'member_type_id' => $row['member_type_id'] === null ? null : (int) $row['member_type_id'],
                'member_type_name' => (string) ($row['member_type_name'] ?? ''),
                'family_name' => (string) ($row['family_name'] ?? ''),
                'family_deactivated_at' => $row['family_deactivated_at'] === null ? null : (string) $row['family_deactivated_at'],
                'birth_month' => $row['birth_month'] === null ? null : (int) $row['birth_month'],
                'birth_day' => $row['birth_day'] === null ? null : (int) $row['birth_day'],
                'birth_year' => $row['birth_year'] === null ? null : (int) $row['birth_year'],
                'date_entered' => $row['date_entered'] === null ? null : new DateTimeImmutable((string) $row['date_entered']),
                'date_last_edited' => $row['date_last_edited'] === null ? null : new DateTimeImmutable((string) $row['date_last_edited']),
                'email' => (string) ($row['email'] ?? ''),
                'mobile_phone' => (string) ($row['mobile_phone'] ?? ''),
                'home_phone' => (string) ($row['home_phone'] ?? ''),
                'address1' => (string) ($row['address1'] ?? ''),
                'address2' => (string) ($row['address2'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
                'zip' => (string) ($row['zip'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'facebook' => (string) ($row['facebook'] ?? ''),
                'linkedin' => (string) ($row['linkedin'] ?? ''),
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

    public function fetchMinistryRoles(int $ministryId): array
    {
        if ($this->connection === null) {
            return [];
        }

        $sql = 'SELECT r.role_id,
                       r.role_name,
                       r.role_order AS `order`,
                       r.active,
                       COUNT(p2g2r.p2g2r_per_ID) AS assigned_count
                  FROM roles r
                  LEFT JOIN person2group2role_p2g2r p2g2r
                         ON p2g2r.p2g2r_rle_ID = r.role_id
                 WHERE r.ministry_group_id = :ministry_id
                 GROUP BY r.role_id, r.role_name, r.role_order, r.active
                 ORDER BY r.role_order ASC, r.role_name ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        $stmt->execute();

        $roles = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            // Get assigned members for each role
            $membersSql = 'SELECT p.per_ID AS person_id,
                                 TRIM(CONCAT_WS(" ", p.per_FirstName, p.per_LastName)) AS display_name
                            FROM person2group2role_p2g2r p2g2r
                            INNER JOIN person_per p ON p.per_ID = p2g2r.p2g2r_per_ID
                           WHERE p2g2r.p2g2r_rle_ID = :role_id
                           ORDER BY p.per_LastName ASC, p.per_FirstName ASC';

            $membersStmt = $this->connection->prepare($membersSql);
            $membersStmt->bindValue(':role_id', (int) $row['role_id'], PDO::PARAM_INT);
            $membersStmt->execute();

            $assignedMembers = [];
            foreach ($membersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $member) {
                $assignedMembers[] = [
                    'person_id' => (int) $member['person_id'],
                    'display_name' => (string) $member['display_name'],
                ];
            }

            $roles[] = [
                'role_id' => (int) $row['role_id'],
                'role_name' => (string) $row['role_name'],
                'order' => (int) $row['order'],
                'active' => (bool) $row['active'],
                'assigned_count' => (int) $row['assigned_count'],
                'assigned_members' => $assignedMembers,
            ];
        }

        return $roles;
    }

    public function fetchMinistryLeaders(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        $campusJoin = $campusId !== null
            ? 'INNER JOIN person_campus_affiliation pcaf
                       ON pcaf.person_id = p.per_ID
                      AND pcaf.campus_id = :campus_id
                      AND pcaf.is_primary = 1'
            : '';

        // A leader is a member who is tagged (ministry_leaders) OR whose group
        // role (list_lst via grp_RoleListID) — or a legacy portal role — matches
        // the leader-name regex. Mirrors the admin Groups & Ministries page.
        $sql = 'SELECT
                p.per_ID AS person_id,
                TRIM(CONCAT_WS(" ", p.per_FirstName, p.per_LastName)) AS display_name,
                pca.campus_id AS primary_campus_id,
                COALESCE(stats.assignment_count, 0) AS assignment_count,
                stats.last_served_at AS last_served_at,
                COALESCE(stats.roles_served, "") AS roles_served,
                COALESCE(ll.lst_OptionName, pr.role_name, "") AS role_name,
                (ml.person_id IS NOT NULL) AS tagged
            FROM person2group2role_p2g2r p2g2r
            INNER JOIN person_per p ON p.per_ID = p2g2r.p2g2r_per_ID '
            . $campusJoin . '
            LEFT JOIN group_grp g ON g.grp_ID = p2g2r.p2g2r_grp_ID
            LEFT JOIN list_lst ll
                   ON ll.lst_ID = g.grp_RoleListID
                  AND ll.lst_OptionID = p2g2r.p2g2r_rle_ID
            LEFT JOIN roles pr
                   ON pr.role_id = p2g2r.p2g2r_rle_ID
                  AND pr.ministry_group_id = p2g2r.p2g2r_grp_ID
            LEFT JOIN ministry_leaders ml
                   ON ml.ministry_group_id = p2g2r.p2g2r_grp_ID
                  AND ml.person_id = p.per_ID
            LEFT JOIN person_campus_affiliation pca
                   ON pca.person_id = p.per_ID
                  AND pca.is_primary = 1
            LEFT JOIN (
                SELECT
                    a.person_id AS person_id,
                    COUNT(*) AS assignment_count,
                    MAX(eo.occurrence_start) AS last_served_at,
                    GROUP_CONCAT(DISTINCT r_inner.role_name
                                 ORDER BY r_inner.role_order, r_inner.role_name
                                 SEPARATOR ", ") AS roles_served
                FROM assignment a
                INNER JOIN event_occurrence eo ON eo.occurrence_id = a.occurrence_id
                INNER JOIN roles r_inner ON r_inner.role_id = a.role_id
                WHERE r_inner.ministry_group_id = :ministry_id
                  AND eo.occurrence_start >= :since_at
                GROUP BY a.person_id
            ) stats ON stats.person_id = p.per_ID
            WHERE p2g2r.p2g2r_grp_ID = :ministry_group_id
              AND (ml.person_id IS NOT NULL
                   OR COALESCE(ll.lst_OptionName, pr.role_name, "")
                      REGEXP "leader|head|coordinator|director|pastor")
            ORDER BY p.per_LastName ASC, p.per_FirstName ASC';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':ministry_group_id', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':since_at', $since->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        if ($campusId !== null) {
            $stmt->bindValue(':campus_id', $campusId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $leaders = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $role = (string) $row['role_name'];
            $isLeaderRole = preg_match('/leader|head|coordinator|director|pastor/i', $role) === 1;
            $leaderRoles = $isLeaderRole ? [$role] : ((int) $row['tagged'] === 1 ? ['Leader'] : []);
            $regularRoles = (!$isLeaderRole && $role !== '') ? [$role] : [];

            $leaders[] = [
                'person_id' => (int) $row['person_id'],
                'display_name' => (string) $row['display_name'],
                'primary_campus_id' => $row['primary_campus_id'] === null ? null : (int) $row['primary_campus_id'],
                'assignment_count' => (int) $row['assignment_count'],
                'last_served_at' => $row['last_served_at'] === null ? null : new DateTimeImmutable((string) $row['last_served_at']),
                'roles_served' => (string) $row['roles_served'],
                'leader_roles' => $leaderRoles,
                'regular_roles' => $regularRoles,
            ];
        }

        return $leaders;
    }

    public function createMinistryRole(int $ministryId, array $data): array
    {
        if ($this->connection === null) {
            throw new \RuntimeException('Database connection not available');
        }

        $sql = 'INSERT INTO roles (role_name, ministry_group_id, role_order, active)
                VALUES (:name, :ministry_id, :order, :active)';

        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':ministry_id', $ministryId, PDO::PARAM_INT);
        $stmt->bindValue(':order', $data['order'] ?? 0, PDO::PARAM_INT);
        $stmt->bindValue(':active', $data['active'] ?? true, PDO::PARAM_BOOL);
        $stmt->execute();

        $roleId = (int) $this->connection->lastInsertId();

        return [
            'role_id' => $roleId,
            'role_name' => $data['name'],
            'order' => $data['order'] ?? 0,
            'active' => $data['active'] ?? true,
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
            $setParts[] = 'role_name = :name';
            $params[':name'] = $data['name'];
        }

        if (isset($data['order'])) {
            $setParts[] = 'role_order = :order';
            $params[':order'] = $data['order'];
        }

        if (isset($data['active'])) {
            $setParts[] = 'active = :active';
            $params[':active'] = $data['active'];
        }

        if (empty($setParts)) {
            throw new \InvalidArgumentException('No valid fields to update');
        }

        $sql = 'UPDATE roles SET ' . implode(', ', $setParts) . ' WHERE role_id = :role_id';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $type = $key === ':active' ? PDO::PARAM_BOOL : ($key === ':order' || $key === ':role_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();

        // Fetch the updated role data with assigned members
        $fetchSql = 'SELECT r.role_id,
                            r.role_name,
                            r.role_order AS `order`,
                            r.active,
                            COUNT(p2g2r.p2g2r_per_ID) AS assigned_count
                       FROM roles r
                       LEFT JOIN person2group2role_p2g2r p2g2r
                              ON p2g2r.p2g2r_rle_ID = r.role_id
                      WHERE r.role_id = :role_id
                      GROUP BY r.role_id, r.role_name, r.role_order, r.active';

        $fetchStmt = $this->connection->prepare($fetchSql);
        $fetchStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
        $fetchStmt->execute();

        $role = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        if ($role === false) {
            throw new \RuntimeException('Role not found after update');
        }

        // Get assigned members
        $membersSql = 'SELECT p.per_ID AS person_id,
                             TRIM(CONCAT_WS(" ", p.per_FirstName, p.per_LastName)) AS display_name
                        FROM person2group2role_p2g2r p2g2r
                        INNER JOIN person_per p ON p.per_ID = p2g2r.p2g2r_per_ID
                       WHERE p2g2r.p2g2r_rle_ID = :role_id
                       ORDER BY p.per_LastName ASC, p.per_FirstName ASC';

        $membersStmt = $this->connection->prepare($membersSql);
        $membersStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
        $membersStmt->execute();

        $assignedMembers = [];
        foreach ($membersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $member) {
            $assignedMembers[] = [
                'person_id' => (int) $member['person_id'],
                'display_name' => (string) $member['display_name'],
            ];
        }

        return [
            'role_id' => (int) $role['role_id'],
            'role_name' => (string) $role['role_name'],
            'order' => (int) $role['order'],
            'active' => (bool) $role['active'],
            'assigned_count' => (int) $role['assigned_count'],
            'assigned_members' => $assignedMembers,
        ];
    }

    public function deleteMinistryRole(int $roleId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            // First remove all person-role assignments
            $deleteAssignmentsSql = 'DELETE FROM person2group2role_p2g2r WHERE p2g2r_rle_ID = :role_id';
            $deleteAssignmentsStmt = $this->connection->prepare($deleteAssignmentsSql);
            $deleteAssignmentsStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $deleteAssignmentsStmt->execute();

            // Then remove assignments from assignment table
            $deleteScheduleAssignmentsSql = 'DELETE FROM assignment WHERE role_id = :role_id';
            $deleteScheduleAssignmentsStmt = $this->connection->prepare($deleteScheduleAssignmentsSql);
            $deleteScheduleAssignmentsStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $deleteScheduleAssignmentsStmt->execute();

            // Finally delete the role itself
            $deleteRoleSql = 'DELETE FROM roles WHERE role_id = :role_id';
            $deleteRoleStmt = $this->connection->prepare($deleteRoleSql);
            $deleteRoleStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $deleteRoleStmt->execute();

            return $deleteRoleStmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function assignPersonToRole(int $personId, int $roleId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            // Get the ministry group ID for the role
            $groupSql = 'SELECT ministry_group_id FROM roles WHERE role_id = :role_id';
            $groupStmt = $this->connection->prepare($groupSql);
            $groupStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $groupStmt->execute();

            $groupId = $groupStmt->fetchColumn();
            if ($groupId === false) {
                return false;
            }

            // Check if assignment already exists
            $checkSql = 'SELECT COUNT(*) FROM person2group2role_p2g2r
                        WHERE p2g2r_per_ID = :person_id
                          AND p2g2r_grp_ID = :group_id
                          AND p2g2r_rle_ID = :role_id';
            $checkStmt = $this->connection->prepare($checkSql);
            $checkStmt->bindValue(':person_id', $personId, PDO::PARAM_INT);
            $checkStmt->bindValue(':group_id', (int) $groupId, PDO::PARAM_INT);
            $checkStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $checkStmt->execute();

            if ((int) $checkStmt->fetchColumn() > 0) {
                return true; // Already assigned
            }

            // Insert the assignment
            $insertSql = 'INSERT INTO person2group2role_p2g2r (p2g2r_per_ID, p2g2r_grp_ID, p2g2r_rle_ID)
                         VALUES (:person_id, :group_id, :role_id)';
            $insertStmt = $this->connection->prepare($insertSql);
            $insertStmt->bindValue(':person_id', $personId, PDO::PARAM_INT);
            $insertStmt->bindValue(':group_id', (int) $groupId, PDO::PARAM_INT);
            $insertStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $insertStmt->execute();

            return $insertStmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function removePersonFromRole(int $personId, int $roleId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            $sql = 'DELETE FROM person2group2role_p2g2r
                   WHERE p2g2r_per_ID = :person_id
                     AND p2g2r_rle_ID = :role_id';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue(':person_id', $personId, PDO::PARAM_INT);
            $stmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
