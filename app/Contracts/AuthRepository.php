<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

/**
 * Service-facing contract for portal authentication and session storage.
 * Implementations compose AuthAdapter (one per backend) — the service layer
 * depends only on this contract.
 */
interface AuthRepository
{
    /**
     * @return array{
     *   portal_user_id:int,
     *   email:string,
     *   password_hash:string,
     *   is_active:bool,
     *   display_name:?string
     * }|null
     */
    public function findUserByEmail(string $email): ?array;

    /**
     * @return array{
     *   portal_user_id:int,
     *   email:string,
     *   is_active:bool,
     *   display_name:?string,
     *   roles:list<array{role:string,scope_campus_id:?int,scope_ministry_id:?int}>,
     *   person_links:list<array{person_id:int,is_primary:bool}>
     * }|null
     */
    public function loadUserProfile(int $portalUserId): ?array;

    public function recordLogin(int $portalUserId, DateTimeImmutable $at): void;

    public function createSession(
        int $portalUserId,
        string $sessionToken,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent,
    ): void;

    /**
     * @return array{portal_user_id:int,expires_at:DateTimeImmutable,revoked:bool}|null
     */
    public function findActiveSession(string $sessionToken): ?array;

    public function touchSession(string $sessionToken, DateTimeImmutable $at): void;

    public function revokeSession(string $sessionToken, DateTimeImmutable $at): void;

    /**
     * Revoke every active session belonging to a user EXCEPT the one whose
     * token is passed in (used after a self-service password change so the
     * caller's own browser stays signed in while everything else is kicked).
     * Pass $exceptToken = null to revoke ALL sessions.
     */
    public function revokeAllSessionsExcept(
        int $portalUserId,
        ?string $exceptToken,
        DateTimeImmutable $at,
    ): int;

    public function createUser(string $email, string $passwordHash, ?string $displayName): int;

    public function provisionUserFromChurchCrm(
        string $email,
        string $passwordHash,
        ?string $displayName,
        int $churchcrmPersonId,
    ): int;

    /**
     * @return array{
     *   portal_user_id:int,
     *   email:string,
     *   password_hash:string,
     *   is_active:bool,
     *   display_name:?string,
     *   must_change_password:bool
     * }|null
     */
    public function findUserByChurchcrmPersonId(int $churchcrmPersonId): ?array;

    public function setMustChangePassword(int $portalUserId, bool $value): void;

    public function isMustChangePassword(int $portalUserId): bool;

    public function clearRolesForUser(int $portalUserId): void;

    public function linkUserToPerson(int $portalUserId, int $personId, bool $isPrimary): void;

    public function assignRole(
        int $portalUserId,
        string $role,
        ?int $scopeCampusId,
        ?int $scopeMinistryId,
    ): void;

    /**
     * @return list<array{
     *   portal_user_id:int,
     *   email:string,
     *   is_active:bool,
     *   display_name:?string,
     *   person_links:list<array{person_id:int,is_primary:bool}>,
     *   roles:list<array{role:string,scope_campus_id:?int,scope_ministry_id:?int}>
     * }>
     */
    public function listUsersWithAccess(): array;

    public function updateDisplayName(int $portalUserId, ?string $displayName): void;

    public function updatePasswordHash(int $portalUserId, string $passwordHash): void;

    /**
     * Append an entry to portal_audit_log. Every portal-originated write
     * routes through here. $payload is JSON-encoded by the adapter.
     *
     * @param array<string, mixed>|null $payload
     */
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
    ): void;
}
