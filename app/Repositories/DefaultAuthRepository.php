<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AuthAdapter;
use App\Contracts\AuthRepository;
use DateTimeImmutable;

/**
 * Source-agnostic auth repository.
 *
 * Composes a single AuthAdapter into the AuthRepository contract. Contains no
 * SQL. When the auth backend changes (e.g. swap SqlAuthAdapter for an
 * LDAP/OIDC adapter later), this class is unchanged.
 */
final class DefaultAuthRepository implements AuthRepository
{
    public function __construct(
        private readonly AuthAdapter $adapter,
    ) {
    }

    public function findUserByEmail(string $email): ?array
    {
        return $this->adapter->findUserByEmail($email);
    }

    public function loadUserProfile(int $accountId): ?array
    {
        $user = $this->adapter->findUserById($accountId);
        if ($user === null) {
            return null;
        }
        return [
            'id'             => $user['id'],
            'person_id'      => $user['person_id'],
            'email'          => $user['email'],
            'is_active'      => $user['is_active'],
            'display_name'   => $user['display_name'],
            'roles'          => $this->adapter->listRolesForUser($accountId),
        ];
    }

    public function recordLogin(int $accountId, DateTimeImmutable $at): void
    {
        $this->adapter->recordLogin($accountId, $at);
    }

    public function createSession(
        int $accountId,
        string $sessionToken,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->adapter->createSession($accountId, $sessionToken, $createdAt, $expiresAt, $ipAddress, $userAgent);
    }

    public function findActiveSession(string $sessionToken): ?array
    {
        return $this->adapter->findActiveSession($sessionToken);
    }

    public function touchSession(string $sessionToken, DateTimeImmutable $at): void
    {
        $this->adapter->touchSession($sessionToken, $at);
    }

    public function revokeSession(string $sessionToken, DateTimeImmutable $at): void
    {
        $this->adapter->revokeSession($sessionToken, $at);
    }

    public function revokeAllSessionsExcept(
        int $accountId,
        ?string $exceptToken,
        DateTimeImmutable $at,
    ): int {
        return $this->adapter->revokeAllSessionsExcept($accountId, $exceptToken, $at);
    }

    public function createUser(string $email, string $passwordHash, ?string $displayName): int
    {
        return $this->adapter->createUser($email, $passwordHash, $displayName);
    }

    public function provisionUserForPerson(
        string $email,
        string $passwordHash,
        ?string $displayName,
        int $personId,
    ): int {
        return $this->adapter->provisionUserForPerson($email, $passwordHash, $displayName, $personId);
    }

    public function listUsersForPerson(int $personId): array
    {
        return $this->adapter->listUsersForPerson($personId);
    }

    public function setMustChangePassword(int $accountId, bool $value): void
    {
        $this->adapter->setMustChangePassword($accountId, $value);
    }

    public function isMustChangePassword(int $accountId): bool
    {
        return $this->adapter->isMustChangePassword($accountId);
    }

    public function clearRolesForUser(int $accountId): void
    {
        $this->adapter->clearRolesForUser($accountId);
    }

    public function linkUserToPerson(int $accountId, int $personId): void
    {
        $this->adapter->linkUserToPerson($accountId, $personId);
    }

    public function assignRole(
        int $accountId,
        string $role,
        ?int $campusId,
        ?int $ministryId,
    ): void {
        $this->adapter->assignRole($accountId, $role, $campusId, $ministryId);
    }

    public function listUsersWithAccess(): array
    {
        return $this->adapter->listUsersWithAccess();
    }

    public function updateDisplayName(int $accountId, ?string $displayName): void
    {
        $this->adapter->updateDisplayName($accountId, $displayName);
    }

    public function updatePasswordHash(int $accountId, string $passwordHash): void
    {
        $this->adapter->updatePasswordHash($accountId, $passwordHash);
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
        $this->adapter->recordAudit(
            $accountId,
            $personId,
            $action,
            $targetType,
            $targetId,
            $summary,
            $details,
            $ipAddress,
            $userAgent,
            $at,
        );
    }
}
