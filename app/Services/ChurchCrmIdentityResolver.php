<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Resolves a login identifier (email OR mobile number) against the ChurchCRM
 * `person_per` / `family_fam` tables and reports the role(s) that person
 * carries — so AuthService can:
 *   1. accept email or phone as the username
 *   2. auto-provision a portal_users row keyed by ChurchCRM identity
 *   3. derive the right role mix (member / leader / portal admin)
 *
 * This service NEVER writes to ChurchCRM — read-only by design.
 */
final readonly class ChurchCrmIdentityResolver
{
    /**
     * Default password formula for first-time logins:
     *   ChristLike#<FirstInitial><LastInitial>#2026!
     */
    public const DEFAULT_PASSWORD_PREFIX = 'ChristLike#';
    public const DEFAULT_PASSWORD_SUFFIX = '#2026!';

    /**
     * Role names whose presence in person2group2role marks the holder as a
     * Ministry Leader. Mirrors the regex used elsewhere in the codebase.
     */
    private const LEADER_ROLE_REGEX = 'leader|head|coordinator|director|pastor';

    public function __construct(
        private PDO $churchCrm,
    ) {
    }

    /**
     * Look up a person by either email or normalized phone digits.
     * Returns null when no match is found.
     *
     * @return array{
     *   personId:int,
     *   firstName:string,
     *   lastName:string,
     *   email:?string,
     *   phone:?string,
     *   defaultPassword:string,
     *   isPortalAdmin:bool,
     *   canManageGroups:bool,
     *   leaderMinistryIds:list<int>,
     *   campusId:?int
     * }|null
     */
    public function resolveByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $person = str_contains($identifier, '@')
            ? $this->findByEmail($identifier)
            : $this->findByPhone($identifier);

        if ($person === null) {
            return null;
        }

        $personId  = (int) $person['per_ID'];
        $firstName = trim((string) ($person['per_FirstName'] ?? ''));
        $lastName  = trim((string) ($person['per_LastName']  ?? ''));

        return [
            'personId'         => $personId,
            'firstName'        => $firstName,
            'lastName'         => $lastName,
            'email'            => $this->trimOrNull($person['per_Email'] ?? null),
            'phone'            => $this->preferredPhone($person),
            'defaultPassword'  => $this->defaultPasswordFor($firstName, $lastName),
            'isPortalAdmin'    => $this->isPortalAdmin($personId),
            'canManageGroups'  => $this->canManageGroups($personId),
            'leaderMinistryIds'=> $this->leaderMinistryIds($personId),
            'campusId'         => $this->primaryCampusId($personId),
        ];
    }

    public function defaultPasswordFor(string $firstName, string $lastName): string
    {
        $first = strtoupper(substr(trim($firstName), 0, 1));
        $last  = strtoupper(substr(trim($lastName),  0, 1));
        // If a name happens to be missing, fall back to '?' so the password
        // still has a deterministic shape; admins should fix the person record.
        if ($first === '') $first = '?';
        if ($last === '')  $last  = '?';
        return self::DEFAULT_PASSWORD_PREFIX . $first . $last . self::DEFAULT_PASSWORD_SUFFIX;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByEmail(string $email): ?array
    {
        // person_per stores three email columns historically. We match on any.
        $sql = 'SELECT per_ID, per_FirstName, per_LastName,
                       per_Email, per_WorkEmail, per_Email2,
                       per_CellPhone, per_HomePhone, per_WorkPhone,
                       per_fam_ID
                  FROM person_per
                 WHERE LOWER(per_Email)     = LOWER(:e1)
                    OR LOWER(per_WorkEmail) = LOWER(:e2)
                    OR LOWER(per_Email2)    = LOWER(:e3)
                 LIMIT 1';
        try {
            $stmt = $this->churchCrm->prepare($sql);
        } catch (\PDOException $e) {
            // Older ChurchCRM may not have per_Email2; retry without it.
            $sql = 'SELECT per_ID, per_FirstName, per_LastName,
                           per_Email, per_WorkEmail,
                           per_CellPhone, per_HomePhone, per_WorkPhone,
                           per_fam_ID
                      FROM person_per
                     WHERE LOWER(per_Email)     = LOWER(:e1)
                        OR LOWER(per_WorkEmail) = LOWER(:e2)
                     LIMIT 1';
            $stmt = $this->churchCrm->prepare($sql);
            $stmt->bindValue(':e1', $email);
            $stmt->bindValue(':e2', $email);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        }
        $stmt->bindValue(':e1', $email);
        $stmt->bindValue(':e2', $email);
        $stmt->bindValue(':e3', $email);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByPhone(string $phone): ?array
    {
        $digits = self::normalizePhone($phone);
        if ($digits === '' || strlen($digits) < 7) {
            return null;
        }
        // Match on per_CellPhone, per_HomePhone, per_WorkPhone (digits-only
        // comparison). Falls through to family_fam.fam_HomePhone via a join
        // so a household phone number still resolves to one of its members
        // (the head-of-family if present, else the first match).
        $sql = "SELECT p.per_ID, p.per_FirstName, p.per_LastName,
                       p.per_Email, p.per_WorkEmail,
                       p.per_CellPhone, p.per_HomePhone, p.per_WorkPhone,
                       p.per_fam_ID
                  FROM person_per p
             LEFT JOIN family_fam f ON f.fam_ID = p.per_fam_ID
                 WHERE REGEXP_REPLACE(COALESCE(p.per_CellPhone,''), '[^0-9]', '') = :d
                    OR REGEXP_REPLACE(COALESCE(p.per_HomePhone,''), '[^0-9]', '') = :d2
                    OR REGEXP_REPLACE(COALESCE(p.per_WorkPhone,''), '[^0-9]', '') = :d3
                    OR REGEXP_REPLACE(COALESCE(f.fam_HomePhone,''), '[^0-9]', '') = :d4
              ORDER BY (p.per_CellPhone IS NOT NULL) DESC, p.per_ID ASC
                 LIMIT 1";
        try {
            $stmt = $this->churchCrm->prepare($sql);
            $stmt->bindValue(':d',  $digits);
            $stmt->bindValue(':d2', $digits);
            $stmt->bindValue(':d3', $digits);
            $stmt->bindValue(':d4', $digits);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        } catch (\PDOException $e) {
            // REGEXP_REPLACE requires MySQL 8.0+/MariaDB 10.0.5+. Fall back to
            // a LIKE comparison on the trailing 7 digits when the CTE-style
            // regex isn't available.
            $tail = substr($digits, -7);
            $sql = "SELECT p.per_ID, p.per_FirstName, p.per_LastName,
                           p.per_Email, p.per_WorkEmail,
                           p.per_CellPhone, p.per_HomePhone, p.per_WorkPhone,
                           p.per_fam_ID
                      FROM person_per p
                 LEFT JOIN family_fam f ON f.fam_ID = p.per_fam_ID
                     WHERE p.per_CellPhone LIKE :t1
                        OR p.per_HomePhone LIKE :t2
                        OR p.per_WorkPhone LIKE :t3
                        OR f.fam_HomePhone LIKE :t4
                     LIMIT 1";
            $like = '%' . $tail . '%';
            $stmt = $this->churchCrm->prepare($sql);
            $stmt->bindValue(':t1', $like);
            $stmt->bindValue(':t2', $like);
            $stmt->bindValue(':t3', $like);
            $stmt->bindValue(':t4', $like);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        }
    }

    private function isPortalAdmin(int $personId): bool
    {
        $stmt = $this->churchCrm->prepare(
            'SELECT COALESCE(usr_Admin, 0) FROM user_usr WHERE usr_per_ID = :pid LIMIT 1'
        );
        $stmt->bindValue(':pid', $personId, PDO::PARAM_INT);
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false && (int) $val === 1;
    }

    private function canManageGroups(int $personId): bool
    {
        $stmt = $this->churchCrm->prepare(
            'SELECT COALESCE(usr_ManageGroups, 0) FROM user_usr WHERE usr_per_ID = :pid LIMIT 1'
        );
        $stmt->bindValue(':pid', $personId, PDO::PARAM_INT);
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false && (int) $val === 1;
    }

    /**
     * @return list<int>
     */
    private function leaderMinistryIds(int $personId): array
    {
        $sql = "SELECT DISTINCT g.grp_ID
                  FROM person2group2role_p2g2r r
            INNER JOIN group_grp  g ON g.grp_ID    = r.p2g2r_grp_ID
            INNER JOIN list_lst   l ON l.lst_ID    = g.grp_RoleListID
                                   AND l.lst_OptionID = r.p2g2r_rle_ID
                 WHERE r.p2g2r_per_ID = :pid
                   AND l.lst_OptionName REGEXP '" . self::LEADER_ROLE_REGEX . "'";
        $stmt = $this->churchCrm->prepare($sql);
        $stmt->bindValue(':pid', $personId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_map('intval', $rows));
    }

    private function primaryCampusId(int $personId): ?int
    {
        try {
            $stmt = $this->churchCrm->prepare(
                'SELECT campus_id FROM person_campus_affiliation
                  WHERE person_id = :pid AND is_primary = 1 LIMIT 1'
            );
            $stmt->bindValue(':pid', $personId, PDO::PARAM_INT);
            $stmt->execute();
            $val = $stmt->fetchColumn();
            return $val === false ? null : (int) $val;
        } catch (\PDOException) {
            return null;
        }
    }

    private function preferredPhone(array $person): ?string
    {
        foreach (['per_CellPhone', 'per_HomePhone', 'per_WorkPhone'] as $col) {
            $val = $this->trimOrNull($person[$col] ?? null);
            if ($val !== null) return $val;
        }
        return null;
    }

    private function trimOrNull(mixed $value): ?string
    {
        $s = trim((string) ($value ?? ''));
        return $s === '' ? null : $s;
    }

    public static function normalizePhone(string $raw): string
    {
        return preg_replace('/[^0-9]/', '', $raw) ?? '';
    }

    public static function looksLikeEmail(string $identifier): bool
    {
        return str_contains($identifier, '@');
    }
}
