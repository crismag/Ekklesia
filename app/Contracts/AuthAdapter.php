<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

/**
 * AuthAdapter is the source-specific data-access boundary for portal auth.
 * Owns SQL against the account tables (user_accounts, account_roles,
 * account_sessions, account_tokens, audit_log). Session tokens are passed raw
 * and stored hashed.
 *
 * Returned arrays are intentionally raw associative arrays — repositories
 * compose them; services map them into ActorContext / domain DTOs.
 */
interface AuthAdapter
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
     *   person_id:?int,
     *   email:string,
     *   is_active:bool,
     *   display_name:?string
     * }|null
     */
    public function findUserById(int $accountId): ?array;

    /**
     * @return list<array{role:string,campus_id:?int,ministry_id:?int}>
     */
    public function listRolesForUser(int $accountId): array;

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
     * Revoke all currently-active sessions for a user except the one matching
     * $exceptToken (or all of them if $exceptToken is null). Returns the
     * number of sessions newly revoked.
     */
    public function revokeAllSessionsExcept(
        int $accountId,
        ?string $exceptToken,
        DateTimeImmutable $at,
    ): int;

    /**
     * Create a new user account. Returns the new account id.
     */
    public function createUser(string $email, string $passwordHash, ?string $displayName): int;

    /**
     * Provision a user_accounts row for a person. Sets
     * must_change_password=1 and stores person_id. Returns the new account id.
     */
    public function provisionUserForPerson(
        string $email,
        string $passwordHash,
        ?string $displayName,
        int $personId,
    ): int;

    /**
     * Every login that belongs to a person, oldest first. A person may have
     * several (one per sign-in identity).
     *
     * @return list<array{
     *   id:int,
     *   email:string,
     *   password_hash:string,
     *   is_active:bool,
     *   display_name:?string,
     *   must_change_password:bool
     * }>
     */
    public function listUsersForPerson(int $personId): array;

    public function setMustChangePassword(int $accountId, bool $value): void;

    public function isMustChangePassword(int $accountId): bool;

    /**
     * Replace every role row for the given user. Useful when re-syncing roles
     * from the person record (e.g. on each login the leader scope is recomputed).
     */
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
     * Append a row to audit_log. The adapter is responsible for
     * JSON-encoding $details.
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
