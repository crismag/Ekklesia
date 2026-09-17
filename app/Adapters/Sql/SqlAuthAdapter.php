<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\AuthAdapter;
use DateTimeImmutable;
use PDO;

/**
 * Auth adapter — owns SQL against the account tables (user_accounts,
 * account_roles, account_sessions, audit_log) in the member database.
 *
 * Session tokens are stored as sha256 hashes in account_sessions.token_hash;
 * callers pass and receive the raw token, which only the browser keeps.
 */
final class SqlAuthAdapter implements AuthAdapter
{
    /**
     * Ministry leadership granted through accounts, as (ministry id => person ids).
     *
     * An account_roles row with role 'leader' scoped to a ministry is a grant
     * made in the portal rather than in any workbook. The person is the one the
     * account belongs to (user_accounts.person_id).
     *
     * The import uses this to keep a leader's membership when the sheet does
     * not mention them: someone who leads a ministry is in it.
     *
     * @return array<int,list<int>>
     */
    public function listMinistryLeaderPersonIds(): array
    {
        $sql = 'SELECT r.ministry_id AS ministry_id,
                       u.person_id   AS person_id
                  FROM account_roles r
                  JOIN user_accounts u ON u.id = r.account_id
                 WHERE r.role = \'leader\'
                   AND r.ministry_id IS NOT NULL
                   AND u.person_id IS NOT NULL';

        $out = [];
        foreach ($this->connection->query($sql) as $row) {
            $ministryId = (int) $row['ministry_id'];
            $personId = (int) ($row['person_id'] ?? 0);
            if ($ministryId > 0 && $personId > 0) {
                $out[$ministryId][$personId] = $personId;
            }
        }

        foreach ($out as $ministryId => $people) {
            $out[$ministryId] = array_values($people);
        }

        return $out;
    }

    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    /** The stored form of a session token: the browser keeps the raw value. */
    private static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array{
     *   id:int,
     *   email:string,
     *   password_hash:string,
     *   is_active:bool,
     *   display_name:?string
     * }|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, email, password_hash, is_active, display_name
               FROM user_accounts
              WHERE email = :email
              LIMIT 1'
        );
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'            => (int) $row['id'],
            'email'         => (string) $row['email'],
            'password_hash' => (string) $row['password_hash'],
            'is_active'     => (int) $row['is_active'] === 1,
            'display_name'  => $row['display_name'] !== null ? (string) $row['display_name'] : null,
        ];
    }

    /**
     * @return array{
     *   id:int,
     *   person_id:?int,
     *   email:string,
     *   is_active:bool,
     *   display_name:?string
     * }|null
     */
    public function findUserById(int $accountId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, person_id, email, is_active, display_name
               FROM user_accounts
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'           => (int) $row['id'],
            'person_id'    => $row['person_id'] !== null ? (int) $row['person_id'] : null,
            'email'        => (string) $row['email'],
            'is_active'    => (int) $row['is_active'] === 1,
            'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
        ];
    }

    /**
     * @return list<array{role:string,campus_id:?int,ministry_id:?int}>
     */
    public function listRolesForUser(int $accountId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT role, campus_id, ministry_id
               FROM account_roles
              WHERE account_id = :id'
        );
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'role'        => (string) $row['role'],
                'campus_id'   => $row['campus_id'] === null ? null : (int) $row['campus_id'],
                'ministry_id' => $row['ministry_id'] === null ? null : (int) $row['ministry_id'],
            ];
        }
        return $rows;
    }

    public function recordLogin(int $accountId, DateTimeImmutable $at): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE user_accounts SET last_login_at = :at WHERE id = :id'
        );
        $stmt->bindValue(':at', $at->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function createSession(
        int $accountId,
        string $sessionToken,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        // Native prepares (emulation off) require each named placeholder to
        // appear exactly once, so created_at and last_seen_at use distinct
        // placeholders bound to the same timestamp.
        $stmt = $this->connection->prepare(
            'INSERT INTO account_sessions
                (token_hash, account_id, created_at, expires_at, last_seen_at, ip_address, user_agent)
             VALUES
                (:token, :uid, :created, :expires, :last_seen, :ip, :ua)'
        );
        $createdStr = $createdAt->format('Y-m-d H:i:s');
        $stmt->bindValue(':token',     self::tokenHash($sessionToken), PDO::PARAM_STR);
        $stmt->bindValue(':uid',       $accountId,   PDO::PARAM_INT);
        $stmt->bindValue(':created',   $createdStr,  PDO::PARAM_STR);
        $stmt->bindValue(':expires',   $expiresAt->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':last_seen', $createdStr,  PDO::PARAM_STR);
        $stmt->bindValue(':ip',        $ipAddress,   PDO::PARAM_STR);
        $stmt->bindValue(':ua',        $userAgent !== null ? mb_substr($userAgent, 0, 255) : null, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @return array{account_id:int,expires_at:DateTimeImmutable,revoked:bool}|null
     */
    public function findActiveSession(string $sessionToken): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT account_id, expires_at, revoked_at
               FROM account_sessions
              WHERE token_hash = :token
              LIMIT 1'
        );
        $stmt->bindValue(':token', self::tokenHash($sessionToken), PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'account_id' => (int) $row['account_id'],
            'expires_at' => new DateTimeImmutable((string) $row['expires_at']),
            'revoked'    => $row['revoked_at'] !== null,
        ];
    }

    public function touchSession(string $sessionToken, DateTimeImmutable $at): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE account_sessions SET last_seen_at = :at WHERE token_hash = :token'
        );
        $stmt->bindValue(':at',    $at->format('Y-m-d H:i:s'),      PDO::PARAM_STR);
        $stmt->bindValue(':token', self::tokenHash($sessionToken), PDO::PARAM_STR);
        $stmt->execute();
    }

    public function revokeSession(string $sessionToken, DateTimeImmutable $at): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE account_sessions SET revoked_at = :at WHERE token_hash = :token AND revoked_at IS NULL'
        );
        $stmt->bindValue(':at',    $at->format('Y-m-d H:i:s'),      PDO::PARAM_STR);
        $stmt->bindValue(':token', self::tokenHash($sessionToken), PDO::PARAM_STR);
        $stmt->execute();
    }

    public function revokeAllSessionsExcept(
        int $accountId,
        ?string $exceptToken,
        DateTimeImmutable $at,
    ): int {
        if ($exceptToken === null) {
            $stmt = $this->connection->prepare(
                'UPDATE account_sessions
                    SET revoked_at = :at
                  WHERE account_id = :uid
                    AND revoked_at IS NULL'
            );
            $stmt->bindValue(':at',  $at->format('Y-m-d H:i:s'), PDO::PARAM_STR);
            $stmt->bindValue(':uid', $accountId,                 PDO::PARAM_INT);
        } else {
            $stmt = $this->connection->prepare(
                'UPDATE account_sessions
                    SET revoked_at = :at
                  WHERE account_id = :uid
                    AND revoked_at IS NULL
                    AND token_hash <> :keep'
            );
            $stmt->bindValue(':at',   $at->format('Y-m-d H:i:s'),     PDO::PARAM_STR);
            $stmt->bindValue(':uid',  $accountId,                     PDO::PARAM_INT);
            $stmt->bindValue(':keep', self::tokenHash($exceptToken), PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function createUser(string $email, string $passwordHash, ?string $displayName): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO user_accounts (email, password_hash, display_name)
             VALUES (:email, :hash, :name)'
        );
        $stmt->bindValue(':email', $email,        PDO::PARAM_STR);
        $stmt->bindValue(':hash',  $passwordHash, PDO::PARAM_STR);
        $stmt->bindValue(':name',  $displayName,  PDO::PARAM_STR);
        $stmt->execute();
        return (int) $this->connection->lastInsertId();
    }

    public function provisionUserForPerson(
        string $email,
        string $passwordHash,
        ?string $displayName,
        int $personId,
    ): int {
        $stmt = $this->connection->prepare(
            'INSERT INTO user_accounts
                (email, password_hash, display_name, must_change_password, person_id)
             VALUES (:email, :hash, :name, 1, :pid)'
        );
        $stmt->bindValue(':email', $email,        PDO::PARAM_STR);
        $stmt->bindValue(':hash',  $passwordHash, PDO::PARAM_STR);
        $stmt->bindValue(':name',  $displayName,  PDO::PARAM_STR);
        $stmt->bindValue(':pid',   $personId,     PDO::PARAM_INT);
        $stmt->execute();
        return (int) $this->connection->lastInsertId();
    }

    public function listUsersForPerson(int $personId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, email, password_hash, is_active, display_name, must_change_password
               FROM user_accounts
              WHERE person_id = :pid
              ORDER BY id ASC'
        );
        $stmt->bindValue(':pid', $personId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id'                   => (int) $row['id'],
            'email'                => (string) $row['email'],
            'password_hash'        => (string) $row['password_hash'],
            'is_active'            => (int) $row['is_active'] === 1,
            'display_name'         => $row['display_name'] !== null ? (string) $row['display_name'] : null,
            'must_change_password' => (int) $row['must_change_password'] === 1,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function setMustChangePassword(int $accountId, bool $value): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE user_accounts SET must_change_password = :v WHERE id = :id'
        );
        $stmt->bindValue(':v',  $value ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $accountId,     PDO::PARAM_INT);
        $stmt->execute();
    }

    public function isMustChangePassword(int $accountId): bool
    {
        $stmt = $this->connection->prepare(
            'SELECT COALESCE(must_change_password, 0) FROM user_accounts WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false && (int) $val === 1;
    }

    public function clearRolesForUser(int $accountId): void
    {
        $stmt = $this->connection->prepare(
            'DELETE FROM account_roles WHERE account_id = :id'
        );
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function linkUserToPerson(int $accountId, int $personId): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE user_accounts SET person_id = :pid WHERE id = :uid'
        );
        $stmt->bindValue(':uid', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $personId,  PDO::PARAM_INT);
        $stmt->execute();
    }

    public function assignRole(
        int $accountId,
        string $role,
        ?int $campusId,
        ?int $ministryId,
    ): void {
        // account_roles has no unique key over (account, role, scope), so an
        // exact duplicate is skipped here. <=> compares NULL scopes as equal.
        $stmt = $this->connection->prepare(
            'INSERT INTO account_roles (account_id, role, campus_id, ministry_id)
             SELECT :uid, :role, :campus, :ministry FROM DUAL
              WHERE NOT EXISTS (
                    SELECT 1 FROM account_roles
                     WHERE account_id = :uid2 AND role = :role2
                       AND campus_id <=> :campus2 AND ministry_id <=> :ministry2)'
        );
        foreach (['', '2'] as $n) {
            $stmt->bindValue(':uid' . $n,      $accountId,  PDO::PARAM_INT);
            $stmt->bindValue(':role' . $n,     $role,       PDO::PARAM_STR);
            $stmt->bindValue(':campus' . $n,   $campusId,   $campusId   === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':ministry' . $n, $ministryId, $ministryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    public function listUsersWithAccess(): array
    {
        $stmt = $this->connection->query(
            'SELECT id, person_id, email, is_active, display_name
               FROM user_accounts
              ORDER BY display_name IS NULL, display_name ASC, email ASC'
        );

        $users = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['id'];
            $users[$id] = [
                'id' => $id,
                'email' => (string) $row['email'],
                'is_active' => (int) $row['is_active'] === 1,
                'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
                'person_id' => $row['person_id'] !== null ? (int) $row['person_id'] : null,
                'roles' => [],
            ];
        }

        if ($users === []) {
            return [];
        }

        $roleStmt = $this->connection->query(
            'SELECT account_id, role, campus_id, ministry_id
               FROM account_roles
              ORDER BY account_id ASC, role ASC, ministry_id ASC, campus_id ASC'
        );
        foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['account_id'];
            if (!isset($users[$id])) {
                continue;
            }
            $users[$id]['roles'][] = [
                'role' => (string) $row['role'],
                'campus_id' => $row['campus_id'] === null ? null : (int) $row['campus_id'],
                'ministry_id' => $row['ministry_id'] === null ? null : (int) $row['ministry_id'],
            ];
        }

        return array_values($users);
    }

    public function updateDisplayName(int $accountId, ?string $displayName): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE user_accounts SET display_name = :name WHERE id = :id'
        );
        $stmt->bindValue(':name', $displayName, $displayName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function updatePasswordHash(int $accountId, string $passwordHash): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE user_accounts SET password_hash = :hash WHERE id = :id'
        );
        $stmt->bindValue(':hash', $passwordHash, PDO::PARAM_STR);
        $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function recordAudit(
        ?int $accountId,
        ?int $personId,
        string $action,
        ?string $targetType,
        ?string $targetId,
        ?string $summary,
        ?array $details,
        ?string $ipAddress,
        ?string $userAgent,
        DateTimeImmutable $at,
    ): void {
        $stmt = $this->connection->prepare(
            'INSERT INTO audit_log
                (occurred_at, account_id, person_id, action,
                 target_type, target_id, summary, details,
                 ip_address, user_agent)
             VALUES
                (:at, :account, :person, :action,
                 :target_type, :target_id, :summary, :details,
                 :ip, :ua)'
        );
        $stmt->bindValue(':at',          $at->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':account',     $accountId,  $accountId  === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':person',      $personId,   $personId   === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':action',      $action,     PDO::PARAM_STR);
        $stmt->bindValue(':target_type', $targetType, $targetType === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':target_id',   $targetId,   $targetId   === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':summary',     $summary,    $summary    === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(
            ':details',
            $details === null ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $details === null ? PDO::PARAM_NULL : PDO::PARAM_STR,
        );
        $stmt->bindValue(':ip', $ipAddress, $ipAddress === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':ua', $userAgent !== null ? mb_substr($userAgent, 0, 255) : null, $userAgent === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    }
}
