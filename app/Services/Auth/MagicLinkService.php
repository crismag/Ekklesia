<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\AuthRepository;
use App\Contracts\Mailer;
use App\DTO\Auth\AuthSession;
use App\DTO\Mail\MailMessage;
use App\Exceptions\PermissionDenied;
use App\Services\AuthService;
use DateTimeImmutable;
use Throwable;

/**
 * Signing in with a link sent to the account's own address.
 *
 * ## What this may and may not do
 *
 * Like Google sign-in, it authenticates an account that **already exists** and
 * creates nothing: no person, no account, no role, no assignment. A request
 * for an address nobody has an account for sends no mail and says so to
 * nobody.
 *
 * ## The link itself
 *
 * 32 random bytes, sent once, and stored only as a SHA-256 hash — so the
 * database never holds anything that could be used to sign in, exactly as it
 * never holds a password. It lasts fifteen minutes and works once: spending it
 * is a single statement that both marks it used and refuses to mark it twice
 * (see AuthRepository::consumeMagicLoginToken), so a link opened twice, or
 * opened by a mail scanner first, cannot sign anybody in twice.
 *
 * ## What the screen says
 *
 * The same sentence either way. "If that address has an account here, a link
 * is on its way" is the whole answer for an address with an account, without
 * one, or belonging to somebody who has been deactivated — otherwise the form
 * answers the question "does this person attend this church?".
 */
final readonly class MagicLinkService
{
    /** Short enough that a forwarded or logged link is stale, long enough to walk to. */
    public const LIFETIME_SECONDS = 900;

    public function __construct(
        private AuthRepository $repository,
        private AuthService $authService,
        private Mailer $mailer,
        private LoginRateLimiter $rateLimiter,
        private string $signInUrlTemplate,
        private string $churchName = 'Ekklesia',
    ) {
    }

    /**
     * Send a sign-in link, if there is anywhere to send it.
     *
     * Returns nothing: the caller's answer to the browser is the same
     * sentence whatever happened here.
     */
    public function requestLink(
        string $email,
        ?string $ipAddress = null,
        ?DateTimeImmutable $now = null,
    ): void {
        $now ??= new DateTimeImmutable();
        $identifier = AuthService::loginKeyFor($email);

        /* Both buckets, before anything is looked up: without this, the form
           is a way to send somebody a great deal of mail, and a slow way to
           ask which addresses exist. */
        $this->rateLimiter->ensureWithinLimit('magic_link', $identifier, $ipAddress, $now);
        $this->rateLimiter->record('magic_link', $identifier, $ipAddress, $now);

        $accounts = $this->repository->findActiveAccountsByEmail($identifier);
        /* One account, or nothing is sent. Several active accounts on one
           address would mean sending a link that signs somebody in as a
           person nobody chose. */
        if (count($accounts) !== 1) {
            return;
        }
        $account = $accounts[0];

        $token = bin2hex(random_bytes(32));
        $this->repository->createMagicLoginToken(
            accountId: $account['id'],
            tokenHash: self::hash($token),
            createdAt: $now,
            expiresAt: $now->modify('+' . self::LIFETIME_SECONDS . ' seconds'),
        );

        $link = str_replace('{token}', rawurlencode($token), $this->signInUrlTemplate);

        try {
            $this->mailer->send(new MailMessage(
                toAddress: $account['email'],
                subject: 'Your ' . $this->churchName . ' sign-in link',
                text: $this->body($link),
                html: null,
                toName: $account['display_name'],
            ));
        } catch (Throwable) {
            /* A relay that is down must not become a way to learn that this
               address has an account. The operator finds this in the mail
               server's own logs; the browser is told what everyone is told.
               Nothing about the link is written anywhere. */
            error_log('[ekklesia] a sign-in link could not be delivered');
        }
    }

    /**
     * Spend a link and start a session.
     *
     * The session is new and ordinary: the same table, lifetime and cookie a
     * password sign-in produces.
     */
    public function completeSignIn(
        string $token,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?DateTimeImmutable $now = null,
    ): AuthSession {
        $now ??= new DateTimeImmutable();

        /* A link is a secret somebody may try to guess; counted per source. */
        $this->rateLimiter->ensureWithinLimit('magic_link', null, $ipAddress, $now);
        $this->rateLimiter->record('magic_link', null, $ipAddress, $now);

        $accountId = $token === ''
            ? null
            : $this->repository->consumeMagicLoginToken(self::hash($token), $now);

        if ($accountId === null) {
            /* Unknown, expired, already used: one sentence for all three. */
            throw new PermissionDenied('That sign-in link is no longer valid. Please ask for a new one.');
        }

        return $this->authService->startVerifiedSession(
            accountId: $accountId,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    private function body(string $link): string
    {
        return implode("\n", [
            'Use this link to sign in to ' . $this->churchName . ':',
            '',
            $link,
            '',
            'It works once, and only for the next 15 minutes.',
            'If you did not ask to sign in, you can ignore this message — nobody',
            'has been given access to your account.',
        ]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
