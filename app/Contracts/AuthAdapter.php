<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

/**
 * AuthAdapter is the source-specific data-access boundary for portal auth.
 * Owns SQL against the portal-owned auth tables (portal_users,
 * portal_user_person_links, portal_user_roles, portal_sessions, portal_tokens).
 *
 * Returned arrays are intentionally raw associative arrays — repositories
 * compose them; services map them into ActorContext / domain DTOs.
 */
interface AuthAdapter
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
     *   display_name:?string
     * }|null
     */
    public function findUserById(int $portalUserId): ?array;

    /**
     * @return list<array{role:string,scope_campus_id:?int,scope_ministry_id:?int}>
     */
    public function listRolesForUser(int $portalUserId): array;

    /**
     * @return list<array{person_id:int,is_primary:bool}>
     */
    public function listPersonLinksForUser(int $portalUserId): array;

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
     * Revoke all currently-active sessions for a user except the one matching
     * $exceptToken (or all of them if $exceptToken is null). Returns the
     * number of sessions newly revoked.
     */
    public function revokeAllSessionsExcept(
        int $portalUserId,
        ?string $exceptToken,
        DateTimeImmutable $at,
    ): int;

    /**
     * Create a new portal user account. Returns the new portal_user_id.
     */
    public function createUser(string $email, string $passwordHash, ?string $displayName): int;

    /**
     * Provision a portal_users row keyed by ChurchCRM identity. Sets
     * must_change_password=1 and stores churchcrm_person_id. Returns the new
     * portal_user_id.
     */
    public function provisionUserFromChurchCrm(
        string $email,
        string $passwordHash,
        ?string $displayName,
        int $churchcrmPersonId,
    ): int;

    /**
     * Look up an existing portal_users row by its ChurchCRM linkage. Used so
     * a person who has already been provisioned (or who manually created an
     * account) is matched without going through email/phone resolution again.
     *
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

    /**
     * Replace every role row for the given user. Useful when re-syncing roles
     * from ChurchCRM (e.g. on each login the leader scope is recomputed).
     */
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
     * Append a row to portal_audit_log. The adapter is responsible for
     * JSON-encoding $payload.
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
