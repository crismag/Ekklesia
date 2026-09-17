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
 * SQL. When the auth backend changes (e.g. swap PortalAuthAdapter for an
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

    public function loadUserProfile(int $portalUserId): ?array
    {
        $user = $this->adapter->findUserById($portalUserId);
        if ($user === null) {
            return null;
        }
        return [
            'portal_user_id' => $user['portal_user_id'],
            'email'          => $user['email'],
            'is_active'      => $user['is_active'],
            'display_name'   => $user['display_name'],
            'roles'          => $this->adapter->listRolesForUser($portalUserId),
            'person_links'   => $this->adapter->listPersonLinksForUser($portalUserId),
        ];
    }

    public function recordLogin(int $portalUserId, DateTimeImmutable $at): void
    {
        $this->adapter->recordLogin($portalUserId, $at);
    }

    public function createSession(
        int $portalUserId,
        string $sessionToken,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->adapter->createSession($portalUserId, $sessionToken, $createdAt, $expiresAt, $ipAddress, $userAgent);
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
        int $portalUserId,
        ?string $exceptToken,
        DateTimeImmutable $at,
    ): int {
        return $this->adapter->revokeAllSessionsExcept($portalUserId, $exceptToken, $at);
    }

    public function createUser(string $email, string $passwordHash, ?string $displayName): int
    {
        return $this->adapter->createUser($email, $passwordHash, $displayName);
    }

    public function provisionUserFromChurchCrm(
        string $email,
        string $passwordHash,
        ?string $displayName,
        int $churchcrmPersonId,
    ): int {
        return $this->adapter->provisionUserFromChurchCrm($email, $passwordHash, $displayName, $churchcrmPersonId);
    }

    public function findUserByChurchcrmPersonId(int $churchcrmPersonId): ?array
    {
        return $this->adapter->findUserByChurchcrmPersonId($churchcrmPersonId);
    }

    public function setMustChangePassword(int $portalUserId, bool $value): void
    {
        $this->adapter->setMustChangePassword($portalUserId, $value);
    }

    public function isMustChangePassword(int $portalUserId): bool
    {
        return $this->adapter->isMustChangePassword($portalUserId);
    }

    public function clearRolesForUser(int $portalUserId): void
    {
        $this->adapter->clearRolesForUser($portalUserId);
    }

    public function linkUserToPerson(int $portalUserId, int $personId, bool $isPrimary): void
    {
        $this->adapter->linkUserToPerson($portalUserId, $personId, $isPrimary);
    }

    public function assignRole(
        int $portalUserId,
        string $role,
        ?int $scopeCampusId,
        ?int $scopeMinistryId,
    ): void {
        $this->adapter->assignRole($portalUserId, $role, $scopeCampusId, $scopeMinistryId);
    }

    public function listUsersWithAccess(): array
    {
        return $this->adapter->listUsersWithAccess();
    }

    public function updateDisplayName(int $portalUserId, ?string $displayName): void
    {
        $this->adapter->updateDisplayName($portalUserId, $displayName);
    }

    public function updatePasswordHash(int $portalUserId, string $passwordHash): void
    {
        $this->adapter->updatePasswordHash($portalUserId, $passwordHash);
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
        $this->adapter->recordAudit(
            $actorUserId,
            $actorPersonId,
            $action,
            $targetType,
            $targetId,
            $summary,
            $payload,
            $ipAddress,
            $userAgent,
            $at,
        );
    }
}
