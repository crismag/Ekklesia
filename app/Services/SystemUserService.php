<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Security\PasswordHasher;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * System user administration — manage portal_users and their role assignments.
 *
 * Direct-PDO against the portal database (portal_users / portal_user_roles /
 * portal_user_person_links). Passwords are hashed with the same PasswordHasher
 * the login uses, so accounts created/reset here authenticate normally. No
 * ChurchCRM dependency.
 */
final readonly class SystemUserService
{
    public const ROLES = ['admin', 'leader', 'scheduler', 'member'];
    private const MIN_PASSWORD = 12;

    public function __construct(private PDO $db, private PasswordHasher $hasher)
    {
    }

    /** @return list<array<string,mixed>> Users with their roles attached. */
    public function list(): array
    {
        $users = [];
        $sql = 'SELECT portal_user_id, email, display_name, is_active, must_change_password, last_login_at
                  FROM portal_users ORDER BY is_active DESC, display_name IS NULL, display_name ASC, email ASC';
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $id = (int) $r['portal_user_id'];
            $users[$id] = [
                'portal_user_id' => $id,
                'email' => (string) $r['email'],
                'display_name' => $r['display_name'] !== null ? (string) $r['display_name'] : '',
                'is_active' => (int) $r['is_active'] === 1,
                'must_change_password' => (int) $r['must_change_password'] === 1,
                'last_login_at' => $r['last_login_at'],
                'roles' => [],
            ];
        }
        if ($users === []) {
            return [];
        }
        $roles = $this->db->query(
            'SELECT role_id, portal_user_id, role, scope_campus_id, scope_ministry_id
               FROM portal_user_roles ORDER BY role ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($roles as $r) {
            $id = (int) $r['portal_user_id'];
            if (!isset($users[$id])) {
                continue;
            }
            $users[$id]['roles'][] = [
                'role_id' => (int) $r['role_id'],
                'role' => (string) $r['role'],
                'scope_campus_id' => $r['scope_campus_id'] !== null ? (int) $r['scope_campus_id'] : null,
                'scope_ministry_id' => $r['scope_ministry_id'] !== null ? (int) $r['scope_ministry_id'] : null,
            ];
        }
        return array_values($users);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        foreach ($this->list() as $u) {
            if ($u['portal_user_id'] === $id) {
                return $u;
            }
        }
        return null;
    }

    public function create(string $email, string $displayName, string $password, bool $active, bool $mustChange): int
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email is required.');
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('Password must be at least ' . self::MIN_PASSWORD . ' characters.');
        }
        $exists = $this->db->prepare('SELECT COUNT(*) FROM portal_users WHERE email = :e');
        $exists->execute([':e' => $email]);
        if ((int) $exists->fetchColumn() > 0) {
            throw new InvalidArgumentException('A user with this email already exists.');
        }
        $stmt = $this->db->prepare(
            'INSERT INTO portal_users (email, password_hash, display_name, is_active, must_change_password, created_at, updated_at)
             VALUES (:e, :h, :d, :a, :m, NOW(), NOW())'
        );
        $stmt->execute([
            ':e' => $email,
            ':h' => $this->hasher->hash($password),
            ':d' => trim($displayName) !== '' ? trim($displayName) : null,
            ':a' => $active ? 1 : 0,
            ':m' => $mustChange ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
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
            'UPDATE portal_users SET display_name = :d, is_active = :a, must_change_password = :m, updated_at = NOW()
              WHERE portal_user_id = :id'
        );
        $stmt->execute([
            ':d' => trim($displayName) !== '' ? trim($displayName) : null,
            ':a' => $active ? 1 : 0,
            ':m' => $mustChange ? 1 : 0,
            ':id' => $id,
        ]);
    }

    public function setPassword(int $id, string $password): void
    {
        if ($this->find($id) === null) {
            throw new InvalidArgumentException('Unknown user.');
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('Password must be at least ' . self::MIN_PASSWORD . ' characters.');
        }
        $stmt = $this->db->prepare('UPDATE portal_users SET password_hash = :h, updated_at = NOW() WHERE portal_user_id = :id');
        $stmt->execute([':h' => $this->hasher->hash($password), ':id' => $id]);
    }

    public function addRole(int $id, string $role, ?int $campusId, ?int $ministryId): void
    {
        if ($this->find($id) === null) {
            throw new InvalidArgumentException('Unknown user.');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Unknown role.');
        }
        $campusId = $campusId && $campusId > 0 ? $campusId : null;
        $ministryId = $ministryId && $ministryId > 0 ? $ministryId : null;

        // Skip exact duplicates.
        $dup = $this->db->prepare(
            'SELECT COUNT(*) FROM portal_user_roles
              WHERE portal_user_id = :u AND role = :r
                AND scope_campus_id <=> :c AND scope_ministry_id <=> :m'
        );
        $dup->execute([':u' => $id, ':r' => $role, ':c' => $campusId, ':m' => $ministryId]);
        if ((int) $dup->fetchColumn() > 0) {
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO portal_user_roles (portal_user_id, role, scope_campus_id, scope_ministry_id, created_at)
             VALUES (:u, :r, :c, :m, NOW())'
        );
        $stmt->execute([':u' => $id, ':r' => $role, ':c' => $campusId, ':m' => $ministryId]);
    }

    public function removeRole(int $roleId): void
    {
        $stmt = $this->db->prepare('SELECT portal_user_id, role, scope_campus_id, scope_ministry_id FROM portal_user_roles WHERE role_id = :id');
        $stmt->execute([':id' => $roleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $isWideAdmin = $row['role'] === 'admin' && $row['scope_campus_id'] === null && $row['scope_ministry_id'] === null;
        if ($isWideAdmin && $this->otherActiveAdmins((int) $row['portal_user_id']) === 0) {
            throw new RuntimeException('This is the last portal-wide admin role — assign another admin first.');
        }
        $this->db->prepare('DELETE FROM portal_user_roles WHERE role_id = :id')->execute([':id' => $roleId]);
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
            foreach (['portal_user_roles', 'portal_user_person_links', 'portal_sessions'] as $t) {
                $this->db->prepare("DELETE FROM `$t` WHERE portal_user_id = :id")->execute([':id' => $id]);
            }
            $this->db->prepare('DELETE FROM portal_users WHERE portal_user_id = :id')->execute([':id' => $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $user */
    public function isPortalWideAdmin(array $user): bool
    {
        foreach ($user['roles'] as $r) {
            if ($r['role'] === 'admin' && $r['scope_campus_id'] === null && $r['scope_ministry_id'] === null) {
                return true;
            }
        }
        return false;
    }

    /** Active portal-wide admin users other than $exceptUserId. */
    private function otherActiveAdmins(int $exceptUserId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT r.portal_user_id)
               FROM portal_user_roles r
               JOIN portal_users u ON u.portal_user_id = r.portal_user_id
              WHERE r.role = "admin" AND r.scope_campus_id IS NULL AND r.scope_ministry_id IS NULL
                AND u.is_active = 1 AND r.portal_user_id <> :ex'
        );
        $stmt->execute([':ex' => $exceptUserId]);
        return (int) $stmt->fetchColumn();
    }
}
