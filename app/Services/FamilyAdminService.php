<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Family administration — CRUD over family_fam (+ member lookups from person_per)
 * in the shared database. Direct-PDO, self-contained: NO dependency on any
 * ChurchCRM PHP so it keeps working after ChurchCRM is decommissioned.
 */
final readonly class FamilyAdminService
{
    private const STR_FIELDS = [
        'fam_Name', 'fam_Address1', 'fam_Address2', 'fam_City', 'fam_State',
        'fam_Zip', 'fam_Country', 'fam_HomePhone', 'fam_Email',
    ];

    public function __construct(private PDO $db)
    {
    }

    /** @return array{total:int,rows:list<array<string,mixed>>} */
    public function list(string $search = '', int $page = 1, int $perPage = 25): array
    {
        $where = '';
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $where = ' WHERE CONCAT(f.fam_Name, " ", COALESCE(f.fam_City, ""), " ", COALESCE(f.fam_Email, "")) LIKE :s';
            $params[':s'] = '%' . $search . '%';
        }
        $total = (int) (function () use ($where, $params) {
            $st = $this->db->prepare('SELECT COUNT(*) FROM family_fam f' . $where);
            $st->execute($params);
            return $st->fetchColumn();
        })();

        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT f.fam_ID AS id, f.fam_Name AS name, f.fam_City AS city, f.fam_State AS state,
                       f.fam_Email AS email, f.fam_HomePhone AS phone, f.fam_DateDeactivated AS deactivated,
                       (SELECT COUNT(*) FROM person_per p WHERE p.per_fam_ID = f.fam_ID) AS members
                  FROM family_fam f' . $where
             . ' ORDER BY f.fam_Name ASC LIMIT :limit OFFSET :offset';
        $st = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();
        return ['total' => $total, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    /** @return array{total:int,active:int} */
    public function stats(): array
    {
        $total = (int) $this->db->query('SELECT COUNT(*) FROM family_fam')->fetchColumn();
        $active = (int) $this->db->query('SELECT COUNT(*) FROM family_fam WHERE fam_DateDeactivated IS NULL')->fetchColumn();
        return ['total' => $total, 'active' => $active];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $st = $this->db->prepare('SELECT * FROM family_fam WHERE fam_ID = :id');
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function blank(): array
    {
        return [
            'fam_ID' => 0, 'fam_Name' => '', 'fam_Address1' => '', 'fam_Address2' => '',
            'fam_City' => '', 'fam_State' => 'Ontario', 'fam_Zip' => '', 'fam_Country' => 'CA',
            'fam_HomePhone' => '', 'fam_Email' => '', 'fam_WeddingDate' => null,
            'fam_SendNewsLetter' => 'FALSE', 'fam_DateDeactivated' => null,
        ];
    }

    /** @return list<array<string,mixed>> members of a family with role labels. */
    public function members(int $famId): array
    {
        if ($famId <= 0) {
            return [];
        }
        $st = $this->db->prepare(
            'SELECT p.per_ID AS id, p.per_FirstName AS first_name, p.per_LastName AS last_name,
                    p.per_Email AS email, p.per_CellPhone AS cell,
                    fmr.lst_OptionName AS family_role, p.per_fmr_ID AS role_id
               FROM person_per p
               LEFT JOIN list_lst fmr ON fmr.lst_ID = 2 AND fmr.lst_OptionID = p.per_fmr_ID
              WHERE p.per_fam_ID = :fam
              ORDER BY p.per_fmr_ID ASC, p.per_LastName ASC, p.per_FirstName ASC'
        );
        $st->execute([':fam' => $famId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Other active families sharing this family's street address (residence),
     * excluding itself. Treats "residence" as distinct from "family" — matching
     * addresses do NOT imply one family, they just share a home.
     *
     * @return list<array{id:int,name:string,members:int}>
     */
    public function residenceMates(int $famId): array
    {
        $fam = $this->find($famId);
        $addr = trim((string) ($fam['fam_Address1'] ?? ''));
        if ($fam === null || $addr === '') {
            return [];
        }
        $st = $this->db->prepare(
            'SELECT f.fam_ID AS id, f.fam_Name AS name,
                    (SELECT COUNT(*) FROM person_per p WHERE p.per_fam_ID = f.fam_ID) AS members
               FROM family_fam f
              WHERE f.fam_ID <> :self AND f.fam_DateDeactivated IS NULL
                AND TRIM(f.fam_Address1) = :addr
              ORDER BY f.fam_Name'
        );
        $st->execute([':self' => $famId, ':addr' => $addr]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'members' => (int) $r['members'],
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Resolve family ids to {id,name}. @param list<int> $ids @return array<int,string> */
    public function namesFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($n) => $n > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("SELECT fam_ID, fam_Name FROM family_fam WHERE fam_ID IN ($in)");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['fam_ID']] = (string) $r['fam_Name'];
        }
        return $out;
    }

    /** Active families for the "link a family" dropdown. @return list<array{id:int,name:string}> */
    public function pickList(int $excludeId = 0): array
    {
        $st = $this->db->prepare('SELECT fam_ID AS id, fam_Name AS name FROM family_fam WHERE fam_DateDeactivated IS NULL AND fam_ID <> :ex ORDER BY fam_Name');
        $st->execute([':ex' => $excludeId]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Create or update a family. Name is required. Returns the family id.
     * @param array<string,mixed> $in
     */
    public function save(array $in, int $actorId): int
    {
        $name = trim((string) ($in['fam_Name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Family name is required.');
        }
        $email = trim((string) ($in['fam_Email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email address looks invalid.');
        }

        $values = [];
        foreach (self::STR_FIELDS as $f) {
            $v = trim((string) ($in[$f] ?? ''));
            $values[$f] = $v === '' ? null : $v;
        }
        $values['fam_Name'] = $name;
        $wed = trim((string) ($in['fam_WeddingDate'] ?? ''));
        $values['fam_WeddingDate'] = $wed !== '' && strtotime($wed) ? date('Y-m-d', (int) strtotime($wed)) : null;
        $values['fam_SendNewsLetter'] = (int) ($in['fam_SendNewsLetter'] ?? 0) === 1 ? 'TRUE' : 'FALSE';
        // Active flag: deactivated when the checkbox is unchecked.
        $active = (int) ($in['is_active'] ?? 1) === 1;

        $id = (int) ($in['fam_ID'] ?? 0);
        if ($id > 0) {
            $set = implode(', ', array_map(static fn ($c) => "`$c` = :$c", array_keys($values)));
            $sql = "UPDATE family_fam SET $set,
                        fam_DateDeactivated = :deact, fam_DateLastEdited = NOW(), fam_EditedBy = :actor
                    WHERE fam_ID = :id";
            $params = [];
            foreach ($values as $k => $v) { $params[":$k"] = $v; }
            $params[':deact'] = $active ? null : date('Y-m-d');
            $params[':actor'] = $actorId;
            $params[':id'] = $id;
            $this->db->prepare($sql)->execute($params);
        } else {
            $cols = array_keys($values);
            $ph = array_map(static fn ($c) => ":$c", $cols);
            $sql = 'INSERT INTO family_fam (' . implode(', ', $cols)
                . ', fam_DateDeactivated, fam_DateEntered, fam_EnteredBy)'
                . ' VALUES (' . implode(', ', $ph) . ', :deact, NOW(), :actor)';
            $params = [];
            foreach ($values as $k => $v) { $params[":$k"] = $v; }
            $params[':deact'] = $active ? null : date('Y-m-d');
            $params[':actor'] = $actorId;
            $this->db->prepare($sql)->execute($params);
            $id = (int) $this->db->lastInsertId();
        }
        return $id;
    }

    /**
     * Groups of active families that share a normalized name (case/space
     * insensitive) — likely duplicates to review. Each family carries enough
     * context (city, address, email, members) for an admin to judge.
     *
     * @return list<array{name:string,families:list<array<string,mixed>>}>
     */
    public function duplicateGroups(): array
    {
        $rows = $this->db->query(
            'SELECT f.fam_ID AS id, f.fam_Name AS name, f.fam_City AS city, f.fam_Address1 AS addr,
                    f.fam_Email AS email, LOWER(TRIM(f.fam_Name)) AS k,
                    (SELECT COUNT(*) FROM person_per p WHERE p.per_fam_ID = f.fam_ID) AS members
               FROM family_fam f WHERE f.fam_DateDeactivated IS NULL
              ORDER BY k, members DESC, f.fam_ID'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['k']][] = [
                'id' => (int) $r['id'], 'name' => (string) $r['name'], 'city' => (string) $r['city'],
                'addr' => (string) $r['addr'], 'email' => (string) $r['email'], 'members' => (int) $r['members'],
            ];
        }
        $groups = [];
        foreach ($byKey as $fams) {
            if (count($fams) > 1) {
                $groups[] = $this->withEvidence($fams[0]['name'], $fams, 'surname');
            }
        }

        // Strongest first. A page that opens with 27 surname collisions and
        // buries the two real duplicates among them is not worth reading.
        usort($groups, static function (array $a, array $b): int {
            return [$b['rank'], strtolower($a['name'])] <=> [$a['rank'], strtolower($b['name'])];
        });

        return $groups;
    }

    /**
     * Attach the evidence for a group of families being one family.
     *
     * The judgement lives in FamilyDuplicateEvidence, which has no database and
     * can be tested directly; this only gathers what it needs.
     *
     * @param list<array<string,mixed>> $families
     * @param 'surname'|'address' $groupedBy
     * @return array{name:string,families:list<array<string,mixed>>,rank:int,evidence:list<string>,groupedBy:string}
     */
    private function withEvidence(string $name, array $families, string $groupedBy): array
    {
        $shared = $this->sharedMemberNames(array_map(static fn (array $f): int => (int) $f['id'], $families));
        $verdict = (new FamilyDuplicateEvidence())->assess($families, $groupedBy, $shared);

        return [
            'name' => $name,
            'families' => $families,
            'rank' => $verdict['rank'],
            'evidence' => $verdict['evidence'],
            'groupedBy' => $groupedBy,
        ];
    }

    /**
     * Names held by a person in every one of these families.
     *
     * The strongest ordinary signal that two family records describe one
     * family: the same person entered twice.
     *
     * @param list<int> $familyIds
     * @return list<string>
     */
    private function sharedMemberNames(array $familyIds): array
    {
        $familyIds = array_values(array_filter($familyIds));
        if (count($familyIds) < 2) {
            return [];
        }
        $in = implode(',', array_map('intval', $familyIds));
        $rows = $this->db->query(
            'SELECT LOWER(CONCAT(TRIM(COALESCE(per_FirstName, "")), " ", TRIM(COALESCE(per_LastName, "")))) AS who,
                    COUNT(DISTINCT per_fam_ID) AS families,
                    MIN(CONCAT(TRIM(COALESCE(per_FirstName, "")), " ", TRIM(COALESCE(per_LastName, "")))) AS label
               FROM person_per
              WHERE per_fam_ID IN (' . $in . ')
                AND TRIM(COALESCE(per_FirstName, "")) <> ""
              GROUP BY who
             HAVING families = ' . count($familyIds)
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $r): string => trim((string) $r['label']), $rows);
    }

    public function duplicateGroupCount(): int
    {
        return count($this->duplicateGroups());
    }

    /**
     * Groups of active families sharing a street address (unit numbers ignored
     * for grouping) — likely same-household candidates to review. A shared
     * address does NOT prove one family, so this is admin-reviewed, not automatic.
     *
     * @return list<array{name:string,families:list<array<string,mixed>>}>
     */
    public function addressGroups(): array
    {
        $rows = $this->db->query(
            'SELECT f.fam_ID AS id, f.fam_Name AS name, f.fam_City AS city, f.fam_Address1 AS addr,
                    f.fam_Email AS email,
                    (SELECT COUNT(*) FROM person_per p WHERE p.per_fam_ID = f.fam_ID) AS members
               FROM family_fam f
              WHERE f.fam_DateDeactivated IS NULL AND TRIM(f.fam_Address1) <> ""
              ORDER BY f.fam_Name'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byKey = [];
        foreach ($rows as $r) {
            // Normalize: lowercase, drop unit/apt tokens, collapse whitespace.
            $base = strtolower(trim((string) $r['addr']));
            $base = (string) preg_replace('/\s*(#|unit|apt\.?|suite|ste\.?)\s*\S+/i', '', $base);
            $base = trim((string) preg_replace('/\s+/', ' ', $base));
            if ($base === '') { continue; }
            $byKey[$base][] = [
                'id' => (int) $r['id'], 'name' => (string) $r['name'], 'city' => (string) $r['city'],
                'addr' => (string) $r['addr'], 'email' => (string) $r['email'], 'members' => (int) $r['members'],
            ];
        }
        $groups = [];
        foreach ($byKey as $fams) {
            if (count($fams) > 1) {
                // Label the group with the fullest address in it.
                $label = '';
                foreach ($fams as $f) { if (strlen($f['addr']) > strlen($label)) { $label = $f['addr']; } }
                $groups[] = $this->withEvidence($label, $fams, 'address');
            }
        }

        usort($groups, static function (array $a, array $b): int {
            return [$b['rank'], strtolower($a['name'])] <=> [$a['rank'], strtolower($b['name'])];
        });

        return $groups;
    }

    public function addressGroupCount(): int
    {
        return count($this->addressGroups());
    }

    /**
     * Merge $mergeId into $keepId: reassign all its people to the kept family,
     * then remove the now-empty family (and its family custom fields). Returns
     * the number of people moved. Transactional; refuses same/unknown families.
     */
    public function merge(int $keepId, int $mergeId, int $actorId): int
    {
        if ($keepId <= 0 || $mergeId <= 0 || $keepId === $mergeId) {
            throw new InvalidArgumentException('Choose two different families to merge.');
        }
        if ($this->find($keepId) === null || $this->find($mergeId) === null) {
            throw new InvalidArgumentException('Unknown family.');
        }
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('UPDATE person_per SET per_fam_ID = :keep, per_DateLastEdited = NOW(), per_EditedBy = :a WHERE per_fam_ID = :merge');
            $st->execute([':keep' => $keepId, ':a' => $actorId, ':merge' => $mergeId]);
            $moved = $st->rowCount();
            $this->db->prepare('DELETE FROM family_custom WHERE fam_ID = :m')->execute([':m' => $mergeId]);
            $this->db->prepare('DELETE FROM family_fam WHERE fam_ID = :m')->execute([':m' => $mergeId]);
            $this->db->commit();
            return $moved;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Hard-delete a family. Refuses when it still has members (reassign first). */
    public function delete(int $id): void
    {
        $fam = $this->find($id);
        if ($fam === null) {
            throw new InvalidArgumentException('Unknown family.');
        }
        $count = (int) (function () use ($id) {
            $st = $this->db->prepare('SELECT COUNT(*) FROM person_per WHERE per_fam_ID = :id');
            $st->execute([':id' => $id]);
            return $st->fetchColumn();
        })();
        if ($count > 0) {
            throw new RuntimeException("This family still has $count member(s). Reassign them first, or deactivate the family instead.");
        }
        $this->db->prepare('DELETE FROM family_fam WHERE fam_ID = :id')->execute([':id' => $id]);
    }
}
