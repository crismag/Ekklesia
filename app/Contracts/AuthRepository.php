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
     *   id:int,
     *   email:string,
     *   password_hash:string,
     *   is_active:bool,
     *   display_name:?string
     * }|null
     */
    public function findUserByEmail(string $email): ?array;

    /**
     * @return array{
     *   id:int,
     *   email:string,
     *   is_active:bool,
     *   display_name:?string,
     *   roles:list<array{role:string,campus_id:?int,ministry_id:?int}>,
     *   person_id:?int
     * }|null
     */
    public function loadUserProfile(int $accountId): ?array;

    public function recordLogin(int $accountId, DateTimeImmutable $at): void;

    public function createSession(
        int $accountId,
        string $sessionToken,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent,
    ): void;

    /**
     * @return array{account_id:int,expires_at:DateTimeImmutable,revoked:bool}|null
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
        int $accountId,
        ?string $exceptToken,
        DateTimeImmutable $at,
    ): int;

    public function createUser(string $email, string $passwordHash, ?string $displayName): int;

    public function provisionUserForPerson(
        string $email,
        string $passwordHash,
        ?string $displayName,
        int $personId,
    ): int;

    /**
     * @return array{
     *   id:int,
     *   email:string,
     *   password_hash:string,
     *   is_active:bool,
     *   display_name:?string,
     *   must_change_password:bool
     * }|null
     */
    public function findUserByPersonId(int $personId): ?array;

    public function setMustChangePassword(int $accountId, bool $value): void;

    public function isMustChangePassword(int $accountId): bool;

    public function clearRolesForUser(int $accountId): void;

    public function linkUserToPerson(int $accountId, int $personId): void;

    public function assignRole(
        int $accountId,
        string $role,
        ?int $campusId,
        ?int $ministryId,
    ): void;

    /**
     * @return list<array{
     *   id:int,
     *   email:string,
     *   is_active:bool,
     *   display_name:?string,
     *   person_id:?int,
     *   roles:list<array{role:string,campus_id:?int,ministry_id:?int}>
     * }>
     */
    public function listUsersWithAccess(): array;

    public function updateDisplayName(int $accountId, ?string $displayName): void;

    public function updatePasswordHash(int $accountId, string $passwordHash): void;

    /**
     * Append an entry to audit_log. Every portal-originated write
     * routes through here. $details is JSON-encoded by the adapter.
     *
     * @param array<string, mixed>|null $details
     */
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
    ): void;
}
