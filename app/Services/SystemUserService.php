<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AuthRepository;
use App\Core\Security\PasswordHasher;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * System user administration — manage user_accounts and their role assignments.
 *
 * Direct-PDO against the member database (user_accounts / account_roles /
 * account_sessions). Passwords are hashed with the same PasswordHasher the
 * login uses, so accounts created/reset here authenticate normally.
 *
 * Every change is written to audit_log through the AuthRepository (when one is
 * given), so Activity history shows who changed which login. The audit write
 * goes through the repository rather than another INSERT here: this class is
 * SQL debt already and should not grow.
 */
final readonly class SystemUserService
{
    public const ROLES = ['admin', 'leader', 'scheduler', 'member'];
    private const MIN_PASSWORD = 12;

    public function __construct(
        private PDO $db,
        private PasswordHasher $hasher,
        private ?AuthRepository $auditLog = null,
    ) {
    }

    /** @param array<string,mixed>|null $details */
    private function audit(int $actorId, string $action, int $accountId, string $summary, ?array $details = null): void
    {
        if ($this->auditLog === null) {
            return;
        }
        try {
            $this->auditLog->recordAudit(
                accountId: $actorId > 0 ? $actorId : null,
                personId: null,
                action: $action,
                targetType: 'user_account',
                targetId: (string) $accountId,
                summary: mb_substr($summary, 0, 255),
                details: $details,
                ipAddress: isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
                userAgent: isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                at: new DateTimeImmutable(),
            );
        } catch (\Throwable) {
            // The change itself has been made; a failed history write must not
            // report it as failed and invite the administrator to repeat it.
        }
    }

    /** @return list<array<string,mixed>> Users with their roles attached. */
    public function list(): array
    {
        $users = [];
        // The person's name comes with the login so the list can say whose it
        // is without a lookup per row.
        $sql = 'SELECT u.id, u.person_id, u.email, u.display_name, u.is_active, u.must_change_password, u.last_login_at,
                       u.created_at, TRIM(CONCAT(COALESCE(p.first_name, ""), " ", COALESCE(p.last_name, ""))) AS person_name
                  FROM user_accounts u
                  LEFT JOIN people p ON p.id = u.person_id
                 ORDER BY u.is_active DESC, u.display_name IS NULL, u.display_name ASC, u.email ASC';
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int) $r['id'];
            $users[$id] = [
                'id' => $id,
                'person_id' => $r['person_id'] !== null ? (int) $r['person_id'] : null,
                'email' => (string) $r['email'],
                'display_name' => $r['display_name'] !== null ? (string) $r['display_name'] : '',
                'is_active' => (int) $r['is_active'] === 1,
                'must_change_password' => (int) $r['must_change_password'] === 1,
                'last_login_at' => $r['last_login_at'],
                'created_at' => $r['created_at'],
                'person_name' => trim((string) ($r['person_name'] ?? '')),
                'roles' => [],
            ];
        }
        if ($users === []) {
            return [];
        }
        $roles = $this->db->query(
            'SELECT id, account_id, role, campus_id, ministry_id
               FROM account_roles ORDER BY role ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($roles as $r) {
            $id = (int) $r['account_id'];
            if (!isset($users[$id])) {
                continue;
            }
            $users[$id]['roles'][] = [
                'id' => (int) $r['id'],
                'role' => (string) $r['role'],
                'campus_id' => $r['campus_id'] !== null ? (int) $r['campus_id'] : null,
                'ministry_id' => $r['ministry_id'] !== null ? (int) $r['ministry_id'] : null,
            ];
        }
        return array_values($users);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        foreach ($this->list() as $u) {
            if ($u['id'] === $id) {
                return $u;
            }
        }
        return null;
    }

    public function create(string $email, string $displayName, string $password, bool $active, bool $mustChange, int $actorId = 0): int
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email is required.');
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('Password must be at least ' . self::MIN_PASSWORD . ' characters.');
        }
        $exists = $this->db->prepare('SELECT COUNT(*) FROM user_accounts WHERE email = :e');
        $exists->execute([':e' => $email]);
        if ((int) $exists->fetchColumn() > 0) {
            throw new InvalidArgumentException('A user with this email already exists.');
        }
        $stmt = $this->db->prepare(
            'INSERT INTO user_accounts (email, password_hash, display_name, is_active, must_change_password, created_at, updated_at)
             VALUES (:e, :h, :d, :a, :m, NOW(), NOW())'
        );
        $stmt->execute([
            ':e' => $email,
            ':h' => $this->hasher->hash($password),
            ':d' => trim($displayName) !== '' ? trim($displayName) : null,
            ':a' => $active ? 1 : 0,
            ':m' => $mustChange ? 1 : 0,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->audit($actorId, 'user_account.create', $id, 'Created login ' . $email, ['active' => $active, 'mustChangePassword' => $mustChange]);

        return $id;
    }

    public function updateProfile(int $id, string $displayName, bool $active, bool $mustChange, int $currentUserId): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw new InvalidArgumentException('Unknown user.');
        }
        if (!$active) {
            if ($id === $currentUserId) {
                throw new RuntimeException('You cannot deactivate your own account.');
            }
            if ($this->isPortalWideAdmin($user) && $this->otherActiveAdmins($id) === 0) {
                throw new RuntimeException('This is the last active portal-wide admin — assign another before deactivating.');
            }
        }
        $stmt = $this->db->prepare(
            'UPDATE user_accounts SET display_name = :d, is_active = :a, must_change_password = :m, updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            ':d' => trim($displayName) !== '' ? trim($displayName) : null,
            ':a' => $active ? 1 : 0,
            ':m' => $mustChange ? 1 : 0,
            ':id' => $id,
        ]);
        $changes = [];
        if ($user['is_active'] !== $active) {
            $changes['active'] = $active;
        }
        if ($user['must_change_password'] !== $mustChange) {
            $changes['mustChangePassword'] = $mustChange;
        }
        $newName = trim($displayName);
        if ($user['display_name'] !== $newName) {
            $changes['displayName'] = ['previous' => $user['display_name'], 'next' => $newName];
        }
        if ($changes !== []) {
            $what = isset($changes['active']) ? ($active ? 'Reactivated ' : 'Deactivated ') : 'Updated ';
            $this->audit($currentUserId, 'user_account.update', $id, $what . 'login ' . $user['email'], $changes);
        }
    }

    public function setPassword(int $id, string $password, int $actorId = 0): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw new InvalidArgumentException('Unknown user.');
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('Password must be at least ' . self::MIN_PASSWORD . ' characters.');
        }
        $stmt = $this->db->prepare('UPDATE user_accounts SET password_hash = :h, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':h' => $this->hasher->hash($password), ':id' => $id]);
        $this->audit($actorId, 'user_account.password', $id, 'Set a new password for ' . $user['email']);
    }

    public function addRole(int $id, string $role, ?int $campusId, ?int $ministryId, int $actorId = 0): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw new InvalidArgumentException('Unknown user.');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Unknown role.');
        }
        $campusId = $campusId && $campusId > 0 ? $campusId : null;
        $ministryId = $ministryId && $ministryId > 0 ? $ministryId : null;

        // Skip exact duplicates.
        $dup = $this->db->prepare(
            'SELECT COUNT(*) FROM account_roles
              WHERE account_id = :u AND role = :r
                AND campus_id <=> :c AND ministry_id <=> :m'
        );
        $dup->execute([':u' => $id, ':r' => $role, ':c' => $campusId, ':m' => $ministryId]);
        if ((int) $dup->fetchColumn() > 0) {
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO account_roles (account_id, role, campus_id, ministry_id, created_at)
             VALUES (:u, :r, :c, :m, NOW())'
        );
        $stmt->execute([':u' => $id, ':r' => $role, ':c' => $campusId, ':m' => $ministryId]);
        $this->audit($actorId, 'user_account.role.add', $id, 'Gave ' . $user['email'] . ' the ' . $role . ' role',
            ['role' => $role, 'campusId' => $campusId, 'ministryId' => $ministryId]);
    }

    public function removeRole(int $roleId, int $actorId = 0): void
    {
        $stmt = $this->db->prepare('SELECT account_id, role, campus_id, ministry_id FROM account_roles WHERE id = :id');
        $stmt->execute([':id' => $roleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $isWideAdmin = $row['role'] === 'admin' && $row['campus_id'] === null && $row['ministry_id'] === null;
        if ($isWideAdmin && $this->otherActiveAdmins((int) $row['account_id']) === 0) {
            throw new RuntimeException('This is the last portal-wide admin role — assign another admin first.');
        }
        $this->db->prepare('DELETE FROM account_roles WHERE id = :id')->execute([':id' => $roleId]);
        $this->audit($actorId, 'user_account.role.remove', (int) $row['account_id'], 'Removed the ' . $row['role'] . ' role',
            ['role' => $row['role'], 'campusId' => $row['campus_id'] !== null ? (int) $row['campus_id'] : null, 'ministryId' => $row['ministry_id'] !== null ? (int) $row['ministry_id'] : null]);
    }

    public function delete(int $id, int $currentUserId): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw new InvalidArgumentException('Unknown user.');
        }
        if ($id === $currentUserId) {
            throw new RuntimeException('You cannot delete your own account.');
        }
        if ($this->isPortalWideAdmin($user) && $this->otherActiveAdmins($id) === 0) {
            throw new RuntimeException('This is the last active portal-wide admin — assign another before deleting.');
        }
        $this->db->beginTransaction();
        try {
            foreach (['account_roles', 'account_sessions', 'account_tokens'] as $t) {
                $this->db->prepare("DELETE FROM `$t` WHERE account_id = :id")->execute([':id' => $id]);
            }
            $this->db->prepare('DELETE FROM user_accounts WHERE id = :id')->execute([':id' => $id]);
            $this->db->commit();
            $this->audit($currentUserId, 'user_account.delete', $id, 'Deleted login ' . $user['email']);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $user */
    public function isPortalWideAdmin(array $user): bool
    {
        foreach ($user['roles'] as $r) {
            if ($r['role'] === 'admin' && $r['campus_id'] === null && $r['ministry_id'] === null) {
                return true;
            }
        }
        return false;
    }

    /** Active portal-wide admin users other than $exceptUserId. */
    private function otherActiveAdmins(int $exceptUserId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT r.account_id)
               FROM account_roles r
               JOIN user_accounts u ON u.id = r.account_id
              WHERE r.role = "admin" AND r.campus_id IS NULL AND r.ministry_id IS NULL
                AND u.is_active = 1 AND r.account_id <> :ex'
        );
        $stmt->execute([':ex' => $exceptUserId]);
        return (int) $stmt->fetchColumn();
    }
}
