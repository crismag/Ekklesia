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

    /* ---------------------------------------------------------- credentials */

    /**
     * The account a provider identity belongs to, or null when this identity
     * has never been linked here.
     *
     * The identity is the provider's own stable subject. Nothing about an
     * address is consulted: an address can be renamed or change hands, and an
     * account that follows one is an account somebody else can inherit.
     *
     * @param 'google' $provider
     * @return array{
     *   id:int,
     *   email:string,
     *   is_active:bool,
     *   display_name:?string
     * }|null
     */
    public function findAccountByCredential(string $provider, string $subject): ?array;

    /**
     * Every active account holding this exact sign-in address.
     *
     * A list, deliberately: what a caller does about more than one is a
     * decision it must make, not something a repository quietly resolves.
     *
     * @return list<array{id:int,email:string,is_active:bool,display_name:?string}>
     */
    public function findActiveAccountsByEmail(string $email): array;

    /**
     * Associate a provider identity with an account.
     *
     * Refuses rather than overwrites: one identity belongs to one account, and
     * one account has one identity per provider.
     *
     * @param 'google' $provider
     */
    public function linkCredential(
        int $accountId,
        string $provider,
        string $subject,
        ?string $linkedEmail,
        DateTimeImmutable $at,
    ): void;

    /** @param 'google' $provider */
    public function touchCredential(string $provider, string $subject, DateTimeImmutable $at): void;

    /* ------------------------------------------------------- sign-in links */

    /**
     * Store a sign-in link's token, hashed, with the moment it stops working.
     *
     * The hash only: a stolen database must not yield a working link, exactly
     * as it must not yield a working password.
     */
    public function createMagicLoginToken(
        int $accountId,
        string $tokenHash,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): void;

    /**
     * Spend a sign-in link, and say whose it was.
     *
     * One statement marks it used and refuses to mark it twice, so two
     * requests arriving together cannot both succeed: a link is one use, and
     * "check then use" would be exactly the race that makes it two.
     *
     * Returns the account id when this call is the one that spent it, and null
     * for a link that is unknown, already spent, or past its expiry.
     */
    public function consumeMagicLoginToken(string $tokenHash, DateTimeImmutable $now): ?int;

    /* --------------------------------------------------------- rate limits */

    /**
     * Note that something was attempted, against an opaque bucket.
     *
     * @param 'password'|'magic_link'|'google' $kind
     */
    public function recordAuthAttempt(string $kind, string $bucket, DateTimeImmutable $at): void;

    /** @param 'password'|'magic_link'|'google' $kind */
    public function countAuthAttempts(string $kind, string $bucket, DateTimeImmutable $since): int;

    /** Forget attempts older than $before, so the ledger does not grow for ever. */
    public function purgeAuthAttempts(DateTimeImmutable $before): void;

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
