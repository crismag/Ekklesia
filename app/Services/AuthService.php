<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AuthRepository;
use App\Contracts\MinistryRepository;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Core\Security\PasswordHasher;
use App\DTO\Auth\AuthSession;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use DateTimeImmutable;

/**
 * Portal authentication service.
 *
 * Owns:
 *   - login (email + password → AuthSession)
 *   - session validation (token → ActorContext)
 *   - logout (revoke session)
 *   - admin-bootstrap (create user, link to person, assign role)
 *
 * Uses AuthRepository (contract) for storage; never executes SQL directly.
 * Password hashing goes through Security\PasswordHasher (Argon2id).
 */
final readonly class AuthService
{
    /**
     * Hard-coded administrator username. Lets a deployment ship with a known
     * way in even when no person rows exist yet (and acts as the
     * "always works" recovery account). Password comes from
     * PORTAL_HARDCODED_ADMIN_PASSWORD env var, defaulting to the well-known
     * fallback below — operators MUST override the env in production.
     */
    public const HARDCODED_ADMIN_USERNAME = 'church admin';
    private const HARDCODED_ADMIN_DEFAULT_PASSWORD = 'ChristLike#CA#2026!';
    private const HARDCODED_ADMIN_EMAIL = 'church.admin@portal.local';

    public function __construct(
        private AuthRepository $repository,
        private MinistryRepository $ministryRepository,
        private PasswordHasher $hasher,
        private int $sessionLifetimeSeconds = 60 * 60 * 12,   // 12 hours default
        private ?\App\Services\PersonIdentityResolver $identityResolver = null,
    ) {
    }

    /**
     * Authenticate a user. On success, creates a session row and returns an
     * AuthSession carrying the session token. Throws on bad credentials,
     * inactive account, or other failure modes.
     */
    public function login(
        string $email,
        string $plainPassword,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuthSession {
        $identifier = trim($email);
        if ($identifier === '' || $plainPassword === '') {
            throw new ValidationFailed('Username and password are required.');
        }

        // 1. Hard-coded "Church Admin" recovery account — always honored when
        //    the env-configured password matches. Provisions a user_accounts
        //    row keyed by HARDCODED_ADMIN_EMAIL so subsequent logins go down
        //    the normal path.
        if (strtolower($identifier) === self::HARDCODED_ADMIN_USERNAME
            || strtolower($identifier) === self::HARDCODED_ADMIN_EMAIL) {
            $envPwd = (string) (getenv('PORTAL_HARDCODED_ADMIN_PASSWORD')
                ?: ($_ENV['PORTAL_HARDCODED_ADMIN_PASSWORD'] ?? ''));
            $expected = $envPwd !== '' ? $envPwd : self::HARDCODED_ADMIN_DEFAULT_PASSWORD;
            if (hash_equals($expected, $plainPassword)) {
                return $this->loginHardcodedAdmin($ipAddress, $userAgent);
            }
        }

        // 2. Lookup the lowercased identifier in user_accounts. Existing flow.
        $user = $this->repository->findUserByEmail(strtolower($identifier));
        if ($user !== null) {
            if (!$user['is_active']) {
                throw new PermissionDenied('Account is inactive.');
            }
            if (!$this->hasher->verify($plainPassword, $user['password_hash'])) {
                // Existing portal account but wrong password — try the
                // person-record fallback (admin may have reset their default).
                $fallback = $this->tryPersonFallback($identifier, $plainPassword);
                if ($fallback !== null) {
                    return $this->finalizeLogin($fallback, $ipAddress, $userAgent);
                }
                throw new PermissionDenied('Invalid credentials.');
            }
            $mustChange = $this->repository->isMustChangePassword($user['id']);
            return $this->finalizeLogin([
                'id'                   => $user['id'],
                'email'                => $user['email'],
                'display_name'         => $user['display_name'],
                'must_change_password' => $mustChange,
            ], $ipAddress, $userAgent);
        }

        // 3. No user_accounts row — try the person identity resolver. If the
        //    submitted password matches the default ChristLike#<FNI><LNI>#2026!
        //    formula, auto-provision the account.
        $fallback = $this->tryPersonFallback($identifier, $plainPassword);
        if ($fallback !== null) {
            return $this->finalizeLogin($fallback, $ipAddress, $userAgent);
        }

        // Constant-time-ish dummy hash so we don't leak whether the username
        // existed via response-time differences.
        $this->hasher->verify($plainPassword, '$argon2id$v=19$m=65536,t=4,p=1$AAAAAAAAAAAAAAAAAAAAAA$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        throw new PermissionDenied('Invalid credentials.');
    }

    /**
     * Resolve the username against people records and (when the submitted password
     * matches the default formula) provision a user_accounts row + roles.
     *
     * Returns the same partial-user shape finalizeLogin() expects, or null
     * when no fallback path matched.
     *
     * @return array{
     *   id:int,
     *   email:string,
     *   display_name:?string,
     *   must_change_password:bool
     * }|null
     */
    private function tryPersonFallback(string $identifier, string $plainPassword): ?array
    {
        if ($this->identityResolver === null) {
            return null;
        }
        $identity = $this->identityResolver->resolveByIdentifier($identifier);
        if ($identity === null) {
            return null;
        }

        $existing = $this->repository->findUserByPersonId($identity['personId']);
        if ($existing !== null) {
            // Already provisioned. The default password is retired — the user
            // may only authenticate with the password stored on their row.
            // (Phone-login users reach this path because their user_accounts
            // email is synthetic and never matches the raw identifier typed.)
            if (!$existing['is_active']) {
                return null;
            }
            if (!$this->hasher->verify($plainPassword, $existing['password_hash'])) {
                return null;
            }
            $this->syncRolesFromPerson($existing['id'], $identity);
            return [
                'id'                   => $existing['id'],
                'email'                => $existing['email'],
                'display_name'         => $existing['display_name'],
                'must_change_password' => $existing['must_change_password'],
            ];
        }

        // No portal row yet — first-time access requires the default password.
        if (!hash_equals($identity['defaultPassword'], $plainPassword)) {
            return null;
        }

        // Pick a stable email-shaped key. Prefer real email, fall back to a
        // synthetic phone-keyed value so we never violate the unique index.
        $loginEmail = $identity['email'] !== null
            ? strtolower($identity['email'])
            : 'phone+' . \App\Services\PersonIdentityResolver::normalizePhone($identity['phone'] ?? '') . '@portal.local';
        $displayName = trim(($identity['firstName'] ?? '') . ' ' . ($identity['lastName'] ?? ''));
        if ($displayName === '') $displayName = null;

        $hash = $this->hasher->hash($plainPassword);
        $newId = $this->repository->provisionUserForPerson(
            $loginEmail,
            $hash,
            $displayName,
            $identity['personId'],
        );
        $this->syncRolesFromPerson($newId, $identity);

        return [
            'id'                   => $newId,
            'email'                => $loginEmail,
            'display_name'         => $displayName,
            'must_change_password' => true,
        ];
    }

    /**
     * Replace the role set for an account with the role mix derived from
     * the person (see PersonIdentityResolver):
     *   - "member"  always (everyone in people gets viewer privileges)
     *   - "leader"  one row per ministry the person leads (ministry_id)
     *   - "admin"   when the person already holds portal-wide admin
     *
     * @param array{
     *   personId:int,
     *   isPortalAdmin:bool,
     *   canManageGroups:bool,
     *   leaderMinistryIds:list<int>,
     *   campusId:?int
     * } $identity
     */
    private function syncRolesFromPerson(int $accountId, array $identity): void
    {
        $this->repository->clearRolesForUser($accountId);
        // Base member role — everyone in the people DB gets viewer privileges.
        $this->repository->assignRole($accountId, 'member', $identity['campusId'], null);

        foreach ($identity['leaderMinistryIds'] as $ministryId) {
            $this->repository->assignRole($accountId, 'leader', $identity['campusId'], $ministryId);
        }

        if ($identity['isPortalAdmin']) {
            // Portal-wide admin — no scope columns set.
            $this->repository->assignRole($accountId, 'admin', null, null);
        } elseif ($identity['canManageGroups']) {
            // Has manage-groups but isn't full admin — promote to scheduler so
            // they can edit ministry schedules but not flip portal settings.
            $this->repository->assignRole($accountId, 'scheduler', $identity['campusId'], null);
        }
    }

    /**
     * Provision (idempotently) the hard-coded recovery admin and return an
     * AuthSession for it. The first time this fires it inserts a user_accounts
     * row; subsequent calls just create a fresh session against that row.
     */
    private function loginHardcodedAdmin(?string $ipAddress, ?string $userAgent): AuthSession
    {
        $email = self::HARDCODED_ADMIN_EMAIL;
        $existing = $this->repository->findUserByEmail($email);
        if ($existing === null) {
            // Provision with a strong random hash; the env-configured password
            // is verified separately above and never persisted as a hash.
            $randomHash = $this->hasher->hash(bin2hex(random_bytes(32)));
            $newId = $this->repository->createUser($email, $randomHash, 'Church Admin');
            $this->repository->assignRole($newId, 'admin', null, null);
            $accountId = $newId;
            $displayName = 'Church Admin';
        } else {
            $accountId = $existing['id'];
            $displayName = $existing['display_name'] ?? 'Church Admin';
        }

        return $this->finalizeLogin([
            'id'                   => $accountId,
            'email'                => $email,
            'display_name'         => $displayName,
            'must_change_password' => false,
        ], $ipAddress, $userAgent);
    }

    /**
     * Common tail of the login flow: create a session row, mark last-login,
     * gather the person links, and assemble the AuthSession DTO.
     *
     * @param array{
     *   id:int,
     *   email:string,
     *   display_name:?string,
     *   must_change_password:bool
     * } $user
     */
    private function finalizeLogin(array $user, ?string $ipAddress, ?string $userAgent): AuthSession
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+' . $this->sessionLifetimeSeconds . ' seconds');
        $token = bin2hex(random_bytes(32));

        $this->repository->createSession(
            accountId: $user['id'],
            sessionToken: $token,
            createdAt: $now,
            expiresAt: $expiresAt,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
        $this->repository->recordLogin($user['id'], $now);

        $profile = $this->repository->loadUserProfile($user['id']);

        return new AuthSession(
            accountId: $user['id'],
            email: $user['email'],
            displayName: $user['display_name'],
            sessionToken: $token,
            expiresAt: $expiresAt,
            personId: $profile['person_id'] ?? null,
            mustChangePassword: (bool) $user['must_change_password'],
        );
    }

    /**
     * Resolve a session token into an ActorContext.
     * Throws PermissionDenied if the token is invalid, revoked, or expired.
     */
    public function resolveActor(string $sessionToken): ActorContext
    {
        if ($sessionToken === '') {
            throw new PermissionDenied('Missing session token.');
        }

        $session = $this->repository->findActiveSession($sessionToken);
        if ($session === null) {
            throw new PermissionDenied('Unknown session.');
        }
        if ($session['revoked']) {
            throw new PermissionDenied('Session revoked.');
        }
        $now = new DateTimeImmutable();
        if ($session['expires_at'] < $now) {
            throw new PermissionDenied('Session expired.');
        }

        $profile = $this->repository->loadUserProfile($session['account_id']);
        if ($profile === null || !$profile['is_active']) {
            throw new PermissionDenied('Account no longer available.');
        }

        $this->repository->touchSession($sessionToken, $now);

        // Fold roles → permissions, collect ministry + campus scope, detect
        // portal-wide-admin (admin role with no scope columns set).
        $permissions = [];
        $ministryScope = [];
        $campusScope = [];
        $isPortalWideAdmin = false;
        foreach ($profile['roles'] as $role) {
            foreach (PortalPermission::forRole($role['role']) as $perm) {
                if (!in_array($perm, $permissions, true)) {
                    $permissions[] = $perm;
                }
            }
            if ($role['role'] === 'admin'
                && $role['campus_id']   === null
                && $role['ministry_id'] === null
            ) {
                $isPortalWideAdmin = true;
            }
            if ($role['ministry_id'] !== null) {
                $ministryScope[$role['ministry_id']] = $role['ministry_id'];
            }
            if ($role['campus_id'] !== null) {
                $campusScope[$role['campus_id']] = $role['campus_id'];
            }
        }

        $primaryPersonId = $profile['person_id'];

        // Default current campus = first scoped campus, if any.
        // Real per-request override (top-bar selector equivalent) is applied
        // by the HTTP layer via ActorContext::withCurrentCampusId().
        $defaultCampusId = $campusScope === [] ? null : array_values($campusScope)[0];
        $displayName = $profile['display_name'];
        if ($primaryPersonId !== null) {
            $displayName = $this->ministryRepository->findDisplayNameForPerson($primaryPersonId) ?? $displayName;
        }

        return new ActorContext(
            actorId: $profile['id'],
            personId: $primaryPersonId,
            displayName: $displayName !== null ? (string) $displayName : null,
            permissions: array_values($permissions),
            ministryScopeIds: array_values($ministryScope),
            isPortalWideAdmin: $isPortalWideAdmin,
            campusScopeIds: array_values($campusScope),
            currentCampusId: $defaultCampusId,
        );
    }

    public function logout(string $sessionToken): void
    {
        if ($sessionToken === '') {
            return;
        }
        $this->repository->revokeSession($sessionToken, new DateTimeImmutable());
    }

    /**
     * Change the signed-in user's password. Verifies the current password
     * (the dummy default counts when must_change_password=1), enforces a
     * minimum strength, hashes with Argon2id, and clears the
     * must_change_password flag so the user can proceed normally.
     */
    public function changeOwnPassword(ActorContext $actor, string $currentPassword, string $newPassword): void
    {
        if ($currentPassword === '' || $newPassword === '') {
            throw new ValidationFailed('Current and new passwords are required.');
        }
        if (strlen($newPassword) < 10) {
            throw new ValidationFailed('New password must be at least 10 characters.');
        }
        if ($newPassword === $currentPassword) {
            throw new ValidationFailed('New password must differ from the current one.');
        }

        $profile = $this->repository->loadUserProfile($actor->actorId);
        if ($profile === null || !$profile['is_active']) {
            throw new PermissionDenied('Account no longer available.');
        }
        $userRow = $this->repository->findUserByEmail($profile['email']);
        if ($userRow === null) {
            throw new PermissionDenied('Account no longer available.');
        }
        if (!$this->hasher->verify($currentPassword, $userRow['password_hash'])) {
            throw new PermissionDenied('Current password does not match.');
        }

        $this->repository->updatePasswordHash($actor->actorId, $this->hasher->hash($newPassword));
        $this->repository->setMustChangePassword($actor->actorId, false);
    }

    /**
     * Bootstrap a new portal user. Used by the admin CLI, never by end-user UI.
     * Returns the new account id.
     */
    public function createUser(string $email, string $plainPassword, ?string $displayName = null): int
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationFailed('A valid email is required.');
        }
        if (strlen($plainPassword) < 12) {
            throw new ValidationFailed('Password must be at least 12 characters.');
        }
        if ($this->repository->findUserByEmail($email) !== null) {
            throw new ValidationFailed('A user with this email already exists.');
        }
        $hash = $this->hasher->hash($plainPassword);
        return $this->repository->createUser($email, $hash, $displayName);
    }

    /** Set the person this login belongs to (one person per login). */
    public function linkPerson(int $accountId, int $personId): void
    {
        if ($accountId <= 0 || $personId <= 0) {
            throw new ValidationFailed('Both account id and person id are required.');
        }
        $this->repository->linkUserToPerson($accountId, $personId);
    }

    public function assignRole(
        int $accountId,
        string $role,
        ?int $campusId = null,
        ?int $ministryId = null,
    ): void {
        if (!in_array($role, ['admin', 'leader', 'scheduler', 'member'], true)) {
            throw new ValidationFailed("Unknown role: $role");
        }
        $this->repository->assignRole($accountId, $role, $campusId, $ministryId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccountProfile(ActorContext $context): array
    {
        $profile = $this->repository->loadUserProfile($context->actorId);
        if ($profile === null || !$profile['is_active']) {
            throw new PermissionDenied('Account no longer available.');
        }

        return [
            'accountId' => (int) $profile['id'],
            'email' => (string) $profile['email'],
            'displayName' => $profile['display_name'],
            'personId' => $context->personId,
            'roles' => $profile['roles'],
            'permissions' => array_map(static fn (PortalPermission $permission): string => $permission->value, $context->permissions),
            'ministryScopeIds' => $context->ministryScopeIds,
            'campusScopeIds' => $context->campusScopeIds,
            'isPortalWideAdmin' => $context->isPortalWideAdmin,
        ];
    }

    /**
     * @param array{ip?:?string,userAgent?:?string} $auditMeta
     */
    public function updateOwnProfile(ActorContext $context, ?string $displayName, array $auditMeta = []): array
    {
        $displayName = trim((string) $displayName);
        if ($displayName !== '' && strlen($displayName) > 100) {
            throw new ValidationFailed('Display name must be 100 characters or fewer.');
        }

        $previous   = $this->repository->loadUserProfile($context->actorId);
        $normalized = $displayName === '' ? null : $displayName;
        $this->repository->updateDisplayName($context->actorId, $normalized);

        $this->audit(
            $context,
            action: 'auth.profile.update',
            targetType: 'user_account',
            targetId: (string) $context->actorId,
            summary: 'Updated own display name',
            payload: [
                'previous' => $previous['display_name'] ?? null,
                'next'     => $normalized,
            ],
            ip: $auditMeta['ip'] ?? null,
            ua: $auditMeta['userAgent'] ?? null,
        );

        return $this->getAccountProfile($context);
    }

    /**
     * @param array{ip?:?string,userAgent?:?string,currentSessionToken?:?string} $auditMeta
     * @return array{otherSessionsRevoked:int}
     */
    public function updateOwnPassword(
        ActorContext $context,
        string $currentPassword,
        string $newPassword,
        array $auditMeta = [],
    ): array {
        if ($currentPassword === '' || $newPassword === '') {
            throw new ValidationFailed('Current password and new password are required.');
        }
        if (strlen($newPassword) < 12) {
            throw new ValidationFailed('New password must be at least 12 characters.');
        }
        if ($currentPassword === $newPassword) {
            throw new ValidationFailed('New password must differ from the current one.');
        }

        $profile = $this->repository->loadUserProfile($context->actorId);
        if ($profile === null) {
            throw new PermissionDenied('Account no longer available.');
        }

        $user = $this->repository->findUserByEmail((string) $profile['email']);
        if ($user === null || (int) $user['id'] !== $context->actorId) {
            throw new PermissionDenied('Account no longer available.');
        }
        if (!$this->hasher->verify($currentPassword, (string) $user['password_hash'])) {
            throw new PermissionDenied('Current password is incorrect.');
        }

        $this->repository->updatePasswordHash($context->actorId, $this->hasher->hash($newPassword));

        // Kick every other browser/device for this user; keep the caller signed in.
        $revoked = $this->repository->revokeAllSessionsExcept(
            $context->actorId,
            $auditMeta['currentSessionToken'] ?? null,
            new DateTimeImmutable(),
        );

        $this->audit(
            $context,
            action: 'auth.password.change',
            targetType: 'user_account',
            targetId: (string) $context->actorId,
            summary: 'Changed own password',
            payload: ['otherSessionsRevoked' => $revoked],
            ip: $auditMeta['ip'] ?? null,
            ua: $auditMeta['userAgent'] ?? null,
        );

        return ['otherSessionsRevoked' => $revoked];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPortalAccess(ActorContext $context): array
    {
        $this->ensurePortalAdmin($context);
        return array_map(
            fn (array $user): array => $this->decorateAccessUser($user),
            $this->repository->listUsersWithAccess(),
        );
    }

    /**
     * @param array{ip?:?string,userAgent?:?string} $auditMeta
     * @return array<string, mixed>
     */
    public function provisionPortalAccess(
        ActorContext $context,
        string $email,
        string $role,
        ?string $displayName = null,
        ?string $temporaryPassword = null,
        ?int $personId = null,
        ?int $scopeMinistryId = null,
        ?int $scopeCampusId = null,
        array $auditMeta = [],
    ): array {
        $this->ensurePortalAdmin($context);

        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationFailed('A valid email is required.');
        }
        if (!in_array($role, ['admin', 'leader', 'scheduler', 'member'], true)) {
            throw new ValidationFailed("Unknown role: $role");
        }
        if (($role === 'leader' || $role === 'scheduler') && ($scopeMinistryId ?? 0) <= 0) {
            throw new ValidationFailed('Leadership and scheduler access requires a ministry scope.');
        }
        if ($role === 'member') {
            $scopeMinistryId = null;
            $scopeCampusId = null;
        }

        $user = $this->repository->findUserByEmail($email);
        if ($user === null) {
            $password = $temporaryPassword !== null && $temporaryPassword !== ''
                ? $temporaryPassword
                : bin2hex(random_bytes(9));
            $accountId = $this->createUser($email, $password, $displayName);
        } else {
            $accountId = (int) $user['id'];
            if ($displayName !== null && trim($displayName) !== '') {
                $this->repository->updateDisplayName($accountId, trim($displayName));
            }
        }

        if (($personId ?? 0) > 0) {
            $this->linkPerson($accountId, (int) $personId);
        }

        $this->assignRole(
            $accountId,
            $role,
            campusId: ($scopeCampusId ?? 0) > 0 ? (int) $scopeCampusId : null,
            ministryId: ($scopeMinistryId ?? 0) > 0 ? (int) $scopeMinistryId : null,
        );

        $profile = $this->repository->loadUserProfile($accountId);
        if ($profile === null) {
            throw new PermissionDenied('Provisioned account could not be loaded.');
        }

        $this->audit(
            $context,
            action: $user === null ? 'portal_access.create' : 'portal_access.update',
            targetType: 'user_account',
            targetId: (string) $accountId,
            summary: sprintf('%s %s as %s', $user === null ? 'Created' : 'Updated', $email, $role),
            payload: [
                'email'             => $email,
                'role'              => $role,
                'scopeMinistryId'   => $scopeMinistryId,
                'scopeCampusId'     => $scopeCampusId,
                'linkedPersonId'    => ($personId ?? 0) > 0 ? (int) $personId : null,
                'wasNewAccount'     => $user === null,
                'temporaryPasswordProvided' => $user === null && ($temporaryPassword !== null && $temporaryPassword !== ''),
            ],
            ip: $auditMeta['ip'] ?? null,
            ua: $auditMeta['userAgent'] ?? null,
        );

        return $this->decorateAccessUser($profile);
    }

    private function ensurePortalAdmin(ActorContext $context): void
    {
        if (!$context->isPortalWideAdmin) {
            throw new PermissionDenied('Portal account access requires portal-wide admin permission.');
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function audit(
        ActorContext $context,
        string $action,
        ?string $targetType,
        ?string $targetId,
        ?string $summary,
        ?array $payload,
        ?string $ip,
        ?string $ua,
    ): void {
        $this->repository->recordAudit(
            accountId:     $context->actorId,
            personId:      $context->personId,
            action:        $action,
            targetType:    $targetType,
            targetId:      $targetId,
            summary:       $summary,
            details:       $payload,
            ipAddress:     $ip,
            userAgent:     $ua,
            at:            new DateTimeImmutable(),
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function decorateAccessUser(array $user): array
    {
        $roles = $user['roles'] ?? [];
        $canManageSchedules = false;
        foreach ($roles as $role) {
            foreach (PortalPermission::forRole((string) $role['role']) as $permission) {
                if ($permission === PortalPermission::ManageSchedules) {
                    $canManageSchedules = true;
                    break 2;
                }
            }
        }

        return [
            'accountId' => (int) $user['id'],
            'email' => (string) $user['email'],
            'isActive' => (bool) $user['is_active'],
            'displayName' => $user['display_name'],
            'personId' => $user['person_id'] ?? null,
            'roles' => $roles,
            'canAssignPeopleToManagedMinistries' => $canManageSchedules,
        ];
    }
}
