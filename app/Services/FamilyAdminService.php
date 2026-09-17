<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Family administration — CRUD over `households` (+ member lookups from
 * `people`) in the member database. Direct-PDO, self-contained.
 */
final readonly class FamilyAdminService
{
    private const STR_FIELDS = [
        'name', 'address_line1', 'address_line2', 'city', 'region',
        'postal_code', 'country', 'home_phone', 'email',
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
            $where = ' WHERE CONCAT(f.name, " ", COALESCE(f.city, ""), " ", COALESCE(f.email, "")) LIKE :s';
            $params[':s'] = '%' . $search . '%';
        }
        $total = (int) (function () use ($where, $params) {
            $st = $this->db->prepare('SELECT COUNT(*) FROM households f' . $where);
            $st->execute($params);
            return $st->fetchColumn();
        })();

        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT f.id, f.name, f.city, f.region,
                       f.email, f.home_phone, f.deactivated_on,
                       (SELECT COUNT(*) FROM people p WHERE p.household_id = f.id) AS members
                  FROM households f' . $where
             . ' ORDER BY f.name ASC LIMIT :limit OFFSET :offset';
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
        $total = (int) $this->db->query('SELECT COUNT(*) FROM households')->fetchColumn();
        $active = (int) $this->db->query('SELECT COUNT(*) FROM households WHERE deactivated_on IS NULL')->fetchColumn();
        return ['total' => $total, 'active' => $active];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $st = $this->db->prepare('SELECT * FROM households WHERE id = :id');
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function blank(): array
    {
        return [
            'id' => 0, 'name' => '', 'address_line1' => '', 'address_line2' => '',
            'city' => '', 'region' => 'Ontario', 'postal_code' => '', 'country' => 'CA',
            'home_phone' => '', 'email' => '', 'wedding_date' => null,
            'send_newsletter' => 0, 'deactivated_on' => null,
        ];
    }

    /** @return list<array<string,mixed>> members of a family with role labels. */
    public function members(int $famId): array
    {
        if ($famId <= 0) {
            return [];
        }
        $st = $this->db->prepare(
            'SELECT p.id, p.first_name, p.last_name,
                    p.email, p.mobile_phone,
                    hr.name AS family_role, p.household_role_id
               FROM people p
               LEFT JOIN household_roles hr ON hr.id = p.household_role_id
              WHERE p.household_id = :fam
              ORDER BY hr.sort_order IS NULL, hr.sort_order ASC, p.last_name ASC, p.first_name ASC'
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
        $addr = trim((string) ($fam['address_line1'] ?? ''));
        if ($fam === null || $addr === '') {
            return [];
        }
        $st = $this->db->prepare(
            'SELECT f.id, f.name,
                    (SELECT COUNT(*) FROM people p WHERE p.household_id = f.id) AS members
               FROM households f
              WHERE f.id <> :self AND f.deactivated_on IS NULL
                AND TRIM(f.address_line1) = :addr
              ORDER BY f.name'
        );
        $st->execute([':self' => $famId, ':addr' => $addr]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'members' => (int) $r['members'],
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Record that two households were linked or unlinked as related. Written on
     * both households, so either one's history shows it.
     *
     * @param 'household.linked'|'household.unlinked' $action
     */
    public function recordLinkChange(int $actorId, string $action, int $householdId, int $otherId, string $relationshipLabel = ''): void
    {
        if (!in_array($action, ['household.linked', 'household.unlinked'], true) || $householdId <= 0 || $otherId <= 0) {
            throw new InvalidArgumentException('Not a household link change.');
        }
        $names = $this->namesFor([$householdId, $otherId]);
        $nameOf = static fn (int $id): string => $names[$id] ?? ('Household #' . $id);
        $verb = $action === 'household.linked' ? 'Linked to' : 'Unlinked from';
        foreach ([[$householdId, $otherId], [$otherId, $householdId]] as [$self, $other]) {
            $this->audit($actorId, $action, $self, [
                'other_household_id' => $other,
                'other_household_name' => $nameOf($other),
                'relationship' => $relationshipLabel,
            ], $verb . ' ' . $nameOf($other) . ($relationshipLabel !== '' ? ' (' . $relationshipLabel . ')' : ''));
        }
    }

    /** Resolve family ids to {id,name}. @param list<int> $ids @return array<int,string> */
    public function namesFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($n) => $n > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("SELECT id, name FROM households WHERE id IN ($in)");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /** Active families for the "link a family" dropdown. @return list<array{id:int,name:string}> */
    public function pickList(int $excludeId = 0): array
    {
        $st = $this->db->prepare('SELECT id, name FROM households WHERE deactivated_on IS NULL AND id <> :ex ORDER BY name');
        $st->execute([':ex' => $excludeId]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Create or update a family. Name is required. Returns the family id.
     * @param array<string,mixed> $in
     */
    public function save(array $in, int $actorId): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Family name is required.');
        }
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email address looks invalid.');
        }

        $values = [];
        foreach (self::STR_FIELDS as $f) {
            $v = trim((string) ($in[$f] ?? ''));
            $values[$f] = $v === '' ? null : $v;
        }
        $values['name'] = $name;
        $wed = trim((string) ($in['wedding_date'] ?? ''));
        $values['wedding_date'] = $wed !== '' && strtotime($wed) ? date('Y-m-d', (int) strtotime($wed)) : null;
        $values['send_newsletter'] = (int) ($in['send_newsletter'] ?? 0) === 1 ? 1 : 0;
        // Active flag: deactivated when the checkbox is unchecked.
        $active = (int) ($in['is_active'] ?? 1) === 1;

        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) {
            $set = implode(', ', array_map(static fn ($c) => "`$c` = :$c", array_keys($values)));
            $sql = "UPDATE households SET $set,
                        deactivated_on = :deact, updated_at = NOW()
                    WHERE id = :id";
            $params = [];
            foreach ($values as $k => $v) { $params[":$k"] = $v; }
            $params[':deact'] = $active ? null : date('Y-m-d');
            $params[':id'] = $id;
            $this->db->prepare($sql)->execute($params);
            $this->audit($actorId, 'household.updated', $id);
        } else {
            $cols = array_keys($values);
            $ph = array_map(static fn ($c) => ":$c", $cols);
            $sql = 'INSERT INTO households (`' . implode('`, `', $cols) . '`, deactivated_on)'
                . ' VALUES (' . implode(', ', $ph) . ', :deact)';
            $params = [];
            foreach ($values as $k => $v) { $params[":$k"] = $v; }
            $params[':deact'] = $active ? null : date('Y-m-d');
            $this->db->prepare($sql)->execute($params);
            $id = (int) $this->db->lastInsertId();
            $this->audit($actorId, 'household.created', $id);
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
            'SELECT f.id, f.name, f.city, f.address_line1 AS addr,
                    f.email, LOWER(TRIM(f.name)) AS k,
                    (SELECT COUNT(*) FROM people p WHERE p.household_id = f.id) AS members
               FROM households f WHERE f.deactivated_on IS NULL
              ORDER BY k, members DESC, f.id'
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
            'SELECT LOWER(CONCAT(TRIM(COALESCE(first_name, "")), " ", TRIM(COALESCE(last_name, "")))) AS who,
                    COUNT(DISTINCT household_id) AS families,
                    MIN(CONCAT(TRIM(COALESCE(first_name, "")), " ", TRIM(COALESCE(last_name, "")))) AS label
               FROM people
              WHERE household_id IN (' . $in . ')
                AND TRIM(COALESCE(first_name, "")) <> ""
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
            'SELECT f.id, f.name, f.city, f.address_line1 AS addr,
                    f.email,
                    (SELECT COUNT(*) FROM people p WHERE p.household_id = f.id) AS members
               FROM households f
              WHERE f.deactivated_on IS NULL AND TRIM(f.address_line1) <> ""
              ORDER BY f.name'
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
     * then remove the now-empty family (its links go with it). Returns
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
            $st = $this->db->prepare('UPDATE people SET household_id = :keep, updated_at = NOW() WHERE household_id = :merge');
            $st->execute([':keep' => $keepId, ':merge' => $mergeId]);
            $moved = $st->rowCount();
            $this->db->prepare('DELETE FROM households WHERE id = :m')->execute([':m' => $mergeId]);
            $this->audit($actorId, 'household.merged', $keepId, ['merged_household_id' => $mergeId, 'people_moved' => $moved]);
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
            $st = $this->db->prepare('SELECT COUNT(*) FROM people WHERE household_id = :id');
            $st->execute([':id' => $id]);
            return $st->fetchColumn();
        })();
        if ($count > 0) {
            throw new RuntimeException("This family still has $count member(s). Reassign them first, or deactivate the family instead.");
        }
        $this->db->prepare('DELETE FROM households WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Record who changed a household. The actor id is the signed-in account; its person, if any, is recorded too.
     * An id that is not an account is kept as no one rather than breaking the write.
     *
     * @param array<string,mixed>|null $details
     */
    private function audit(int $actorId, string $action, int $householdId, ?array $details = null, ?string $summary = null): void
    {
        $this->db->prepare(
            'INSERT INTO audit_log (account_id, person_id, action, target_type, target_id, summary, details)
             VALUES ((SELECT id FROM user_accounts WHERE id = :actor), (SELECT person_id FROM user_accounts WHERE id = :actor2), :action, "household", :target, :summary, :details)'
        )->execute([
            ':actor' => $actorId, ':actor2' => $actorId, ':action' => $action, ':target' => (string) $householdId,
            ':summary' => $summary, ':details' => $details === null ? null : json_encode($details),
        ]);
    }
}
