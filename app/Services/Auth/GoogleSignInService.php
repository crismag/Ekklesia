<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\AuthRepository;
use App\DTO\Auth\AuthSession;
use App\Exceptions\PermissionDenied;
use App\Services\AuthService;
use DateTimeImmutable;
use RuntimeException;

/**
 * Signing in with Google.
 *
 * ## What this may and may not do
 *
 * It **authenticates an account that already exists**. It never creates a
 * person, an account, a role, a ministry assignment or any other permission:
 * an account exists here because an administrator provisioned it, and Google
 * only establishes that the person at the browser is the one that account
 * belongs to. Everything anybody may then do still comes from account_roles,
 * untouched by this file.
 *
 * ## How an identity finds its account
 *
 * Once — and only once — on the first Google sign-in, an identity is matched
 * to an account by its **verified** email, and only when **exactly one active
 * account** has that address. Anything else is refused: no account, several
 * accounts, or an address Google has not verified. Shared and ambiguous
 * addresses are the ordinary case in a church (a household on one address),
 * and guessing which account was meant is how somebody signs in as their
 * relative.
 *
 * After that the association is durable and Google's `sub` is what is matched,
 * so the account survives the person renaming their Google address — and an
 * address that later moves to somebody else opens nothing.
 *
 * ## What a failure says
 *
 * "That did not work." Never whether the address is known here, whether an
 * account is inactive, or whether it was ambiguous: a sign-in screen that
 * distinguishes them is a way to ask whether somebody attends this church.
 */
final readonly class GoogleSignInService
{
    private const PROVIDER = 'google';

    /** What every refusal says, whatever the reason. */
    private const REFUSAL = 'That Google account cannot sign in here.';

    public function __construct(
        private AuthRepository $repository,
        private AuthService $authService,
        private GoogleOidcClient $client,
        private LoginRateLimiter $rateLimiter,
    ) {
    }

    /** A random value to carry through the round trip, and the URL to send them to. */
    public function begin(?string $loginHint = null): array
    {
        $state = bin2hex(random_bytes(32));

        return ['state' => $state, 'url' => $this->client->authorizationUrl($state, $loginHint)];
    }

    /** Whether the state that came back is the one this browser was given. */
    public function stateMatches(string $returned, ?string $expected): bool
    {
        return $returned !== ''
            && $expected !== null
            && $expected !== ''
            && hash_equals($expected, $returned);
    }

    /**
     * Finish the round trip: verify what Google says, find the account it
     * belongs to, and start a session.
     *
     * The session is a new one, created the same way a password sign-in
     * creates it — same table, same lifetime, same cookie.
     */
    public function completeCallback(
        string $code,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?DateTimeImmutable $now = null,
    ): AuthSession {
        $now ??= new DateTimeImmutable();

        /* Counted before anything is verified: a callback is reachable by
           anybody, and the exchange itself costs a request to Google. */
        $this->rateLimiter->ensureWithinLimit(self::PROVIDER, null, $ipAddress, $now);
        $this->rateLimiter->record(self::PROVIDER, null, $ipAddress, $now);

        try {
            $identity = $this->client->identityFromCode($code);
        } catch (RuntimeException) {
            /* Google's reason — a spent code, a wrong audience, a bad
               signature — is for the server log, not for the browser. */
            throw new PermissionDenied(self::REFUSAL);
        }

        $account = $this->accountFor($identity, $now, $ipAddress);

        return $this->authService->startVerifiedSession(
            accountId: $account['id'],
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    /**
     * The account this identity signs in as.
     *
     * @param array{subject:string,email:string,emailVerified:bool,name:?string} $identity
     * @return array{id:int,email:string,is_active:bool,display_name:?string}
     */
    private function accountFor(array $identity, DateTimeImmutable $now, ?string $ipAddress): array
    {
        /* Counted per identity as well, so one Google account cannot be used
           to hunt for which addresses exist here. */
        $this->rateLimiter->ensureWithinLimit(self::PROVIDER, $identity['subject'], null, $now);
        $this->rateLimiter->record(self::PROVIDER, $identity['subject'], null, $now);

        $linked = $this->repository->findAccountByCredential(self::PROVIDER, $identity['subject']);
        if ($linked !== null) {
            if (!$linked['is_active']) {
                throw new PermissionDenied(self::REFUSAL);
            }
            $this->repository->touchCredential(self::PROVIDER, $identity['subject'], $now);

            return $linked;
        }

        /* Not linked yet. Linking needs proof of the address, and Google
           saying it is verified is the proof this portal accepts. */
        if (!$identity['emailVerified'] || $identity['email'] === '') {
            throw new PermissionDenied(self::REFUSAL);
        }

        $candidates = $this->repository->findActiveAccountsByEmail(
            AuthService::loginKeyFor($identity['email']),
        );
        /* Exactly one, or nothing happens: no account is not an invitation to
           create one, and several is not a choice this may make on somebody's
           behalf. */
        if (count($candidates) !== 1) {
            throw new PermissionDenied(self::REFUSAL);
        }

        $account = $candidates[0];
        $this->repository->linkCredential(
            accountId: $account['id'],
            provider: self::PROVIDER,
            subject: $identity['subject'],
            linkedEmail: $identity['email'],
            at: $now,
        );
        $this->repository->recordAudit(
            accountId: $account['id'],
            personId: null,
            action: 'auth.google.linked',
            targetType: 'account',
            targetId: (string) $account['id'],
            summary: 'Google sign-in associated with this account',
            /* The address Google verified, and nothing that could be replayed:
               no token, no code, no subject in the clear. */
            details: ['email' => $identity['email']],
            ipAddress: $ipAddress,
            userAgent: null,
            at: $now,
        );

        return $account;
    }
}
