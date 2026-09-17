<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PersonContactDirectory;
use PDO;

/**
 * Finds the people behind a sign-in identifier (email OR phone number) in the
 * `people` / `households` records, and reports what sign-in needs about a
 * person — so AuthService can:
 *   1. accept email or phone as the username
 *   2. offer a first-time login to the right person, chosen by the user
 *   3. derive the right role mix (member / leader / portal admin)
 *
 * An email or phone number is contact information, not identity: households
 * share them, so every lookup returns all matching people and never picks one.
 *
 * This service NEVER writes — read-only by design.
 */
final readonly class PersonIdentityResolver implements PersonContactDirectory
{
    /**
     * Default password formula for first-time logins:
     *   ChristLike#<FirstInitial><LastInitial>#2026!
     */
    public const DEFAULT_PASSWORD_PREFIX = 'ChristLike#';
    public const DEFAULT_PASSWORD_SUFFIX = '#2026!';

    public function __construct(
        private PDO $members,
    ) {
    }

    public function peopleWithContact(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return [];
        }
        $rows = self::looksLikeEmail($identifier)
            ? $this->findByEmail($identifier)
            : $this->findByPhone($identifier);

        return array_map(static fn (array $r): array => [
            'personId'  => (int) $r['id'],
            'firstName' => (string) $r['first_name'],
            'lastName'  => (string) $r['last_name'],
        ], $rows);
    }

    public function identityFor(int $personId): ?array
    {
        $stmt = $this->members->prepare(
            'SELECT id, first_name, last_name, email, mobile_phone, home_phone, campus_id
               FROM people WHERE id = :id'
        );
        $stmt->bindValue(':id', $personId, PDO::PARAM_INT);
        $stmt->execute();
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($person === false) {
            return null;
        }
        $firstName = (string) $person['first_name'];
        $lastName  = (string) $person['last_name'];

        return [
            'personId'         => $personId,
            'firstName'        => $firstName,
            'lastName'         => $lastName,
            'email'            => $this->trimOrNull($person['email'] ?? null),
            'phone'            => $this->preferredPhone($person),
            'defaultPassword'  => $this->defaultPasswordFor($firstName, $lastName),
            'isPortalAdmin'    => $this->holdsAccountRole($personId, 'admin', unscopedOnly: true),
            'canManageGroups'  => $this->holdsAccountRole($personId, 'scheduler', unscopedOnly: false),
            'leaderMinistryIds'=> $this->leaderMinistryIds($personId),
            'campusId'         => $person['campus_id'] !== null ? (int) $person['campus_id'] : null,
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
     * @return list<array<string,mixed>>
     */
    private function findByEmail(string $email): array
    {
        $stmt = $this->members->prepare(
            'SELECT id, first_name, last_name, email, mobile_phone, home_phone, campus_id
               FROM people
              WHERE LOWER(email) = LOWER(:e)
              ORDER BY id ASC'
        );
        $stmt->bindValue(':e', trim($email));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function findByPhone(string $phone): array
    {
        $digits = self::normalizePhone($phone);
        if ($digits === '' || strlen($digits) < 7) {
            return [];
        }
        // Match on the person's mobile and home phones (digits-only
        // comparison), falling through to households.home_phone so a
        // household phone number reaches every one of its members.
        $sql = "SELECT p.id, p.first_name, p.last_name, p.email,
                       p.mobile_phone, p.home_phone, p.campus_id
                  FROM people p
             LEFT JOIN households h ON h.id = p.household_id
                 WHERE REGEXP_REPLACE(COALESCE(p.mobile_phone,''), '[^0-9]', '') = :d
                    OR REGEXP_REPLACE(COALESCE(p.home_phone,''),   '[^0-9]', '') = :d2
                    OR REGEXP_REPLACE(COALESCE(h.home_phone,''),   '[^0-9]', '') = :d3
              ORDER BY p.id ASC";
        try {
            $stmt = $this->members->prepare($sql);
            $stmt->bindValue(':d',  $digits);
            $stmt->bindValue(':d2', $digits);
            $stmt->bindValue(':d3', $digits);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            // REGEXP_REPLACE requires MySQL 8.0+/MariaDB 10.0.5+. Fall back to
            // a LIKE comparison on the trailing 7 digits when it isn't available.
            $tail = substr($digits, -7);
            $sql = "SELECT p.id, p.first_name, p.last_name, p.email,
                           p.mobile_phone, p.home_phone, p.campus_id
                      FROM people p
                 LEFT JOIN households h ON h.id = p.household_id
                     WHERE p.mobile_phone LIKE :t1
                        OR p.home_phone LIKE :t2
                        OR h.home_phone LIKE :t3
                  ORDER BY p.id ASC";
            $like = '%' . $tail . '%';
            $stmt = $this->members->prepare($sql);
            $stmt->bindValue(':t1', $like);
            $stmt->bindValue(':t2', $like);
            $stmt->bindValue(':t3', $like);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    /**
     * Whether a login belonging to this person already holds the role. Admin
     * and scheduler access are granted on accounts (there is no separate
     * people-side flag), so re-syncing roles on login keeps them.
     */
    private function holdsAccountRole(int $personId, string $role, bool $unscopedOnly): bool
    {
        $sql = 'SELECT 1
                  FROM account_roles r
                  JOIN user_accounts u ON u.id = r.account_id
                 WHERE u.person_id = :pid AND r.role = :role';
        if ($unscopedOnly) {
            $sql .= ' AND r.campus_id IS NULL AND r.ministry_id IS NULL';
        }
        $stmt = $this->members->prepare($sql . ' LIMIT 1');
        $stmt->bindValue(':pid', $personId, PDO::PARAM_INT);
        $stmt->bindValue(':role', $role);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Ministries the person leads: confirmed leader memberships, plus leader
     * grants already made on one of their accounts.
     *
     * @return list<int>
     */
    private function leaderMinistryIds(int $personId): array
    {
        $sql = "SELECT mm.ministry_id
                  FROM ministry_members mm
                 WHERE mm.person_id = :pid
                   AND mm.role = 'leader'
                   AND mm.status = 'confirmed'
                 UNION
                SELECT r.ministry_id
                  FROM account_roles r
                  JOIN user_accounts u ON u.id = r.account_id
                 WHERE u.person_id = :pid2
                   AND r.role = 'leader'
                   AND r.ministry_id IS NOT NULL";
        $stmt = $this->members->prepare($sql);
        $stmt->bindValue(':pid', $personId, PDO::PARAM_INT);
        $stmt->bindValue(':pid2', $personId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_map('intval', $rows));
    }

    private function preferredPhone(array $person): ?string
    {
        foreach (['mobile_phone', 'home_phone'] as $col) {
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
