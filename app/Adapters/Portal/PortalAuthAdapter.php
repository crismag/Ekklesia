<?php

declare(strict_types=1);

namespace App\Adapters\Portal;

use App\Contracts\AuthAdapter;
use DateTimeImmutable;
use PDO;

/**
 * Portal auth adapter — owns SQL against portal-owned auth tables.
 *
 * All tables targeted by this adapter live in the portal-connection database
 * (default: u471078694_christlike_mdb). It never reads or writes ChurchCRM
 * core tables. Person/campus/ministry IDs stored here are foreign references
 * (informational), not enforced cross-DB FKs.
 */
final class PortalAuthAdapter implements AuthAdapter
{
    /**
     * Ministry leadership, as (ministry group id => person ids).
     *
     * Leadership is not a role on the membership row. It is an RBAC grant in
     * portal_user_roles scoped to a ministry, granted in the portal rather than
     * in any workbook — ministry_leaders exists but has never been populated.
     * A leader reaches a person either through portal_users.churchcrm_person_id
     * or through portal_user_person_links, so both are considered.
     *
     * The import uses this to keep a leader's membership when the sheet does
     * not mention them: someone who leads a ministry is in it.
     *
     * @return array<int,list<int>>
     */
    public function listMinistryLeaderPersonIds(): array
    {
        if ($this->connection === null) {
            return [];
        }

        $sql = 'SELECT r.scope_ministry_id AS ministry_id,
                       COALESCE(u.churchcrm_person_id, l.person_id) AS person_id
                  FROM portal_user_roles r
                  JOIN portal_users u ON u.portal_user_id = r.portal_user_id
             LEFT JOIN portal_user_person_links l
                    ON l.portal_user_id = r.portal_user_id AND l.is_primary = 1
                 WHERE r.role = \'leader\'
                   AND r.scope_ministry_id IS NOT NULL
                   AND r.scope_ministry_id > 0';

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

    /**
     * @return array{
     *   portal_user_id:int,
     *   email:string,
     *   password_hash:string,
     *   is_active:bool,
     *   display_name:?string
     * }|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT portal_user_id, email, password_hash, is_active, display_name
               FROM portal_users
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
            'portal_user_id' => (int) $row['portal_user_id'],
            'email'          => (string) $row['email'],
            'password_hash'  => (string) $row['password_hash'],
            'is_active'      => (int) $row['is_active'] === 1,
            'display_name'   => $row['display_name'] !== null ? (string) $row['display_name'] : null,
        ];
    }

    /**
     * @return array{
     *   portal_user_id:int,
     *   email:string,
     *   is_active:bool,
     *   display_name:?string
     * }|null
     */
    public function findUserById(int $portalUserId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT portal_user_id, email, is_active, display_name
               FROM portal_users
              WHERE portal_user_id = :id
              LIMIT 1'
        );
        $stmt->bindValue(':id', $portalUserId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'portal_user_id' => (int) $row['portal_user_id'],
            'email'          => (string) $row['email'],
            'is_active'      => (int) $row['is_active'] === 1,
            'display_name'   => $row['display_name'] !== null ? (string) $row['display_name'] : null,
        ];
    }

    /**
     * @return list<array{role:string,scope_campus_id:?int,scope_ministry_id:?int}>
     */
    public function listRolesForUser(int $portalUserId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT role, scope_campus_id, scope_ministry_id
               FROM portal_user_roles
              WHERE portal_user_id = :id'
        );
        $stmt->bindValue(':id', $portalUserId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'role'              => (string) $row['role'],
                'scope_campus_id'   => $row['scope_campus_id'] === null ? null : (int) $row['scope_campus_id'],
                'scope_ministry_id' => $row['scope_ministry_id'] === null ? null : (int) $row['scope_ministry_id'],
            ];
        }
        return $rows;
    }

    /**
     * @return list<array{person_id:int,is_primary:bool}>
     */
    public function listPersonLinksForUser(int $portalUserId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT person_id, is_primary
               FROM portal_user_person_links
              WHERE portal_user_id = :id
              ORDER BY is_primary DESC, link_id ASC'
        );
        $stmt->bindValue(':id', $portalUserId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'person_id'  => (int) $row['person_id'],
                'is_primary' => (int) $row['is_primary'] === 1,
            ];
        }
        return $rows;
    }

    public function recordLogin(int $portalUserId, DateTimeImmutable $at): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE portal_users SET last_login_at = :at WHERE portal_user_id = :id'
        );
        $stmt->bindValue(':at', $at->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':id', $portalUserId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function createSession(
        int $portalUserId,
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
            'INSERT INTO portal_sessions
                (session_token, portal_user_id, created_at, expires_at, last_seen_at, ip_address, user_agent)
             VALUES
                (:token, :uid, :created, :expires, :last_seen, :ip, :ua)'
        );
        $createdStr = $createdAt->format('Y-m-d H:i:s');
        $stmt->bindValue(':token',     $sessionToken, PDO::PARAM_STR);
        $stmt->bindValue(':uid',       $portalUserId, PDO::PARAM_INT);
        $stmt->bindValue(':created',   $createdStr,   PDO::PARAM_STR);
        $stmt->bindValue(':expires',   $expiresAt->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':last_seen', $createdStr,   PDO::PARAM_STR);
        $stmt->bindValue(':ip',        $ipAddress,    PDO::PARAM_STR);
        $stmt->bindValue(':ua',        $userAgent,    PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @return array{portal_user_id:int,expires_at:DateTimeImmutable,revoked:bool}|null
     */
    public function findActiveSession(string $sessionToken): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT portal_user_id, expires_at, revoked_at
               FROM portal_sessions
              WHERE session_token = :token
              LIMIT 1'
        );
        $stmt->bindValue(':token', $sessionToken, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'portal_user_id' => (int) $row['portal_user_id'],
            'expires_at'     => new DateTimeImmutable((string) $row['expires_at']),
            'revoked'        => $row['revoked_at'] !== null,
        ];
    }

    public function touchSession(string $sessionToken, DateTimeImmutable $at): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE portal_sessions SET last_seen_at = :at WHERE session_token = :token'
        );
        $stmt->bindValue(':at',    $at->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':token', $sessionToken,              PDO::PARAM_STR);
        $stmt->execute();
    }

    public function revokeSession(string $sessionToken, DateTimeImmutable $at): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE portal_sessions SET revoked_at = :at WHERE session_token = :token AND revoked_at IS NULL'
        );
        $stmt->bindValue(':at',    $at->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':token', $sessionToken,              PDO::PARAM_STR);
        $stmt->execute();
    }

    public function revokeAllSessionsExcept(
        int $portalUserId,
        ?string $exceptToken,
        DateTimeImmutable $at,
    ): int {
        if ($exceptToken === null) {
            $stmt = $this->connection->prepare(
                'UPDATE portal_sessions
                    SET revoked_at = :at
                  WHERE portal_user_id = :uid
                    AND revoked_at IS NULL'
            );
            $stmt->bindValue(':at',  $at->format('Y-m-d H:i:s'), PDO::PARAM_STR);
            $stmt->bindValue(':uid', $portalUserId,              PDO::PARAM_INT);
        } else {
            $stmt = $this->connection->prepare(
                'UPDATE portal_sessions
                    SET revoked_at = :at
                  WHERE portal_user_id = :uid
                    AND revoked_at IS NULL
                    AND session_token <> :keep'
            );
            $stmt->bindValue(':at',   $at->format('Y-m-d H:i:s'), PDO::PARAM_STR);
            $stmt->bindValue(':uid',  $portalUserId,              PDO::PARAM_INT);
            $stmt->bindValue(':keep', $exceptToken,               PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function createUser(string $email, string $passwordHash, ?string $displayName): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO portal_users (email, password_hash, display_name)
             VALUES (:email, :hash, :name)'
        );
        $stmt->bindValue(':email', $email,        PDO::PARAM_STR);
        $stmt->bindValue(':hash',  $passwordHash, PDO::PARAM_STR);
        $stmt->bindValue(':name',  $displayName,  PDO::PARAM_STR);
        $stmt->execute();
        return (int) $this->connection->lastInsertId();
    }

    public function provisionUserFromChurchCrm(
        string $email,
        string $passwordHash,
        ?string $displayName,
        int $churchcrmPersonId,
    ): int {
        $stmt = $this->connection->prepare(
            'INSERT INTO portal_users
                (email, password_hash, display_name, must_change_password, churchcrm_person_id)
             VALUES (:email, :hash, :name, 1, :pid)'
        );
        $stmt->bindValue(':email', $email,             PDO::PARAM_STR);
        $stmt->bindValue(':hash',  $passwordHash,      PDO::PARAM_STR);
        $stmt->bindValue(':name',  $displayName,       PDO::PARAM_STR);
        $stmt->bindValue(':pid',   $churchcrmPersonId, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $this->connection->lastInsertId();
    }

    public function findUserByChurchcrmPersonId(int $churchcrmPersonId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT portal_user_id, email, password_hash, is_active, display_name, must_change_password
               FROM portal_users
              WHERE churchcrm_person_id = :pid
              LIMIT 1'
        );
        $stmt->bindValue(':pid', $churchcrmPersonId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'portal_user_id'       => (int) $row['portal_user_id'],
            'email'                => (string) $row['email'],
            'password_hash'        => (string) $row['password_hash'],
            'is_active'            => (int) $row['is_active'] === 1,
            'display_name'         => $row['display_name'] !== null ? (string) $row['display_name'] : null,
            'must_change_password' => (int) $row['must_change_password'] === 1,
        ];
    }

    public function setMustChangePassword(int $portalUserId, bool $value): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE portal_users SET must_change_password = :v WHERE portal_user_id = :id'
        );
        $stmt->bindValue(':v',  $value ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $portalUserId,  PDO::PARAM_INT);
        $stmt->execute();
    }

    public function isMustChangePassword(int $portalUserId): bool
    {
        $stmt = $this->connection->prepare(
            'SELECT COALESCE(must_change_password, 0) FROM portal_users WHERE portal_user_id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $portalUserId, PDO::PARAM_INT);
        $stmt->execute();
        $val = $stmt->fetchColumn();
        return $val !== false && (int) $val === 1;
    }

    public function clearRolesForUser(int $portalUserId): void
    {
        $stmt = $this->connection->prepare(
            'DELETE FROM portal_user_roles WHERE portal_user_id = :id'
        );
        $stmt->bindValue(':id', $portalUserId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function linkUserToPerson(int $portalUserId, int $personId, bool $isPrimary): void
    {
        $stmt = $this->connection->prepare(
            'INSERT IGNORE INTO portal_user_person_links (portal_user_id, person_id, is_primary)
             VALUES (:uid, :pid, :primary)'
        );
        $stmt->bindValue(':uid',     $portalUserId,    PDO::PARAM_INT);
        $stmt->bindValue(':pid',     $personId,        PDO::PARAM_INT);
        $stmt->bindValue(':primary', $isPrimary ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function assignRole(
        int $portalUserId,
        string $role,
        ?int $scopeCampusId,
        ?int $scopeMinistryId,
    ): void {
        $stmt = $this->connection->prepare(
            'INSERT IGNORE INTO portal_user_roles (portal_user_id, role, scope_campus_id, scope_ministry_id)
             VALUES (:uid, :role, :campus, :ministry)'
        );
        $stmt->bindValue(':uid',      $portalUserId,    PDO::PARAM_INT);
        $stmt->bindValue(':role',     $role,            PDO::PARAM_STR);
        $stmt->bindValue(':campus',   $scopeCampusId,   $scopeCampusId   === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':ministry', $scopeMinistryId, $scopeMinistryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    }

    public function listUsersWithAccess(): array
    {
        $stmt = $this->connection->query(
            'SELECT portal_user_id, email, is_active, display_name
               FROM portal_users
              ORDER BY display_name IS NULL, display_name ASC, email ASC'
        );

        $users = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['portal_user_id'];
            $users[$id] = [
                'portal_user_id' => $id,
                'email' => (string) $row['email'],
                'is_active' => (int) $row['is_active'] === 1,
                'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
                'person_links' => [],
                'roles' => [],
            ];
        }

        if ($users === []) {
            return [];
        }

        $linkStmt = $this->connection->query(
            'SELECT portal_user_id, person_id, is_primary
               FROM portal_user_person_links
              ORDER BY portal_user_id ASC, is_primary DESC, link_id ASC'
        );
        foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['portal_user_id'];
            if (!isset($users[$id])) {
                continue;
            }
            $users[$id]['person_links'][] = [
                'person_id' => (int) $row['person_id'],
                'is_primary' => (int) $row['is_primary'] === 1,
            ];
        }

        $roleStmt = $this->connection->query(
            'SELECT portal_user_id, role, scope_campus_id, scope_ministry_id
               FROM portal_user_roles
              ORDER BY portal_user_id ASC, role ASC, scope_ministry_id ASC, scope_campus_id ASC'
        );
        foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['portal_user_id'];
            if (!isset($users[$id])) {
                continue;
            }
            $users[$id]['roles'][] = [
                'role' => (string) $row['role'],
                'scope_campus_id' => $row['scope_campus_id'] === null ? null : (int) $row['scope_campus_id'],
                'scope_ministry_id' => $row['scope_ministry_id'] === null ? null : (int) $row['scope_ministry_id'],
            ];
        }

        return array_values($users);
    }

    public function updateDisplayName(int $portalUserId, ?string $displayName): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE portal_users SET display_name = :name WHERE portal_user_id = :id'
        );
        $stmt->bindValue(':name', $displayName, $displayName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $portalUserId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function updatePasswordHash(int $portalUserId, string $passwordHash): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE portal_users SET password_hash = :hash WHERE portal_user_id = :id'
        );
        $stmt->bindValue(':hash', $passwordHash, PDO::PARAM_STR);
        $stmt->bindValue(':id', $portalUserId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function recordAudit(
        ?int $actorUserId,
        ?int $actorPersonId,
        string $action,
        ?string $targetType,
        ?string $targetId,
        ?string $summary,
        ?array $payload,
        ?string $ipAddress,
        ?string $userAgent,
        DateTimeImmutable $at,
    ): void {
        $stmt = $this->connection->prepare(
            'INSERT INTO portal_audit_log
                (at, actor_user_id, actor_person_id, action,
                 target_type, target_id, summary, payload_json,
                 ip_address, user_agent)
             VALUES
                (:at, :actor_user, :actor_person, :action,
                 :target_type, :target_id, :summary, :payload,
                 :ip, :ua)'
        );
        $stmt->bindValue(':at',           $at->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':actor_user',   $actorUserId,   $actorUserId   === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':actor_person', $actorPersonId, $actorPersonId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':action',       $action,        PDO::PARAM_STR);
        $stmt->bindValue(':target_type',  $targetType,    $targetType  === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':target_id',    $targetId,      $targetId    === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':summary',      $summary,       $summary     === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(
            ':payload',
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $payload === null ? PDO::PARAM_NULL : PDO::PARAM_STR,
        );
        $stmt->bindValue(':ip', $ipAddress, $ipAddress === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':ua', $userAgent !== null ? mb_substr($userAgent, 0, 255) : null, $userAgent === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    }
}
