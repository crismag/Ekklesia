<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTO\Auth\AuthSession;
use App\Exceptions\PermissionDenied;
use App\Exceptions\TooManyAttempts;
use App\Providers\PortalServiceProvider;

/**
 * The two sign-in methods that are round trips through a browser: Google, and
 * a link sent by mail.
 *
 * They are browser redirects rather than JSON calls — a person leaves for
 * Google and comes back, or arrives from their mail — so they live here rather
 * than beside the password API.
 *
 * Neither creates anything. Both end at AuthService::startVerifiedSession,
 * which signs in an account that already exists and nothing else.
 *
 * Every failure sends the person back to the sign-in page with the same
 * generic message. Which check failed — an unknown address, a shared one, a
 * deactivated account, a spent link — is exactly what somebody probing would
 * want to know.
 */
final class SignInMethodController
{
    /** The one thing a failed sign-in ever says. */
    private const REFUSED = 'That did not work. Try again, or sign in with your password.';

    /** Where the round trip's state is kept: server-side, never in the URL. */
    private const STATE_KEY = 'google_oauth_state';
    private const NEXT_KEY  = 'sign_in_next';

    /**
     * GET /auth/google/start
     *
     * @param array<string,mixed> $request
     */
    public function googleStart(array $request): string
    {
        $basePath = (string) ($request['_base_path'] ?? '');
        $service = PortalServiceProvider::makeGoogleSignInService();
        if ($service === null) {
            return self::backToLogin($basePath, self::REFUSED);
        }

        $begin = $service->begin();

        self::startSession();
        $_SESSION[self::STATE_KEY] = $begin['state'];
        $_SESSION[self::NEXT_KEY] = self::safeNext($request, $basePath);

        return self::redirect($begin['url']);
    }

    /**
     * GET /auth/google/callback
     *
     * @param array<string,mixed> $request
     */
    public function googleCallback(array $request): string
    {
        $basePath = (string) ($request['_base_path'] ?? '');
        $service = PortalServiceProvider::makeGoogleSignInService();

        self::startSession();
        $expected = isset($_SESSION[self::STATE_KEY]) ? (string) $_SESSION[self::STATE_KEY] : null;
        $next = isset($_SESSION[self::NEXT_KEY]) ? (string) $_SESSION[self::NEXT_KEY] : $basePath . '/';
        /* One round trip, one state: spent whether or not it works, so a
           callback cannot be replayed. */
        unset($_SESSION[self::STATE_KEY], $_SESSION[self::NEXT_KEY]);

        if ($service === null) {
            return self::backToLogin($basePath, self::REFUSED);
        }

        $code = (string) ($request['code'] ?? '');
        /* Checked before the code is spent: a state that does not match means
           this callback was not started by this browser. */
        if ($code === '' || !$service->stateMatches((string) ($request['state'] ?? ''), $expected)) {
            return self::backToLogin($basePath, self::REFUSED);
        }

        try {
            $session = $service->completeCallback(
                code: $code,
                ipAddress: $request['_remote_addr'] ?? null,
                userAgent: $request['_user_agent'] ?? null,
            );
        } catch (TooManyAttempts $tooMany) {
            return self::backToLogin($basePath, $tooMany->getMessage());
        } catch (PermissionDenied) {
            return self::backToLogin($basePath, self::REFUSED);
        }

        return self::signedIn($session, $request, $next);
    }

    /**
     * GET /login/link/{token} — following a sign-in link from an email.
     *
     * @param array<string,mixed> $request
     */
    public function magicLink(array $request): string
    {
        $basePath = (string) ($request['_base_path'] ?? '');
        $service = PortalServiceProvider::makeMagicLinkService();
        if ($service === null) {
            return self::backToLogin($basePath, self::REFUSED);
        }

        try {
            $session = $service->completeSignIn(
                token: (string) ($request['token'] ?? ''),
                ipAddress: $request['_remote_addr'] ?? null,
                userAgent: $request['_user_agent'] ?? null,
            );
        } catch (TooManyAttempts | PermissionDenied $refused) {
            /* A spent or expired link says so — it is the one failure a person
               can act on, and it reveals nothing about who exists. */
            return self::backToLogin($basePath, $refused->getMessage());
        }

        return self::signedIn($session, $request, $basePath . '/');
    }

    /**
     * Set the session cookie exactly as a password sign-in does, then go on.
     *
     * @param array<string,mixed> $request
     */
    private static function signedIn(AuthSession $session, array $request, string $next): string
    {
        $basePath = (string) ($request['_base_path'] ?? '');
        if (PHP_SAPI !== 'cli') {
            setcookie('portal_session', $session->sessionToken, [
                'expires'  => $session->expiresAt->getTimestamp(),
                'path'     => $basePath . '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => !empty($_SERVER['HTTPS']),
            ]);
        }

        /* An account still owing a password change owes it however it signed
           in; these methods do not excuse it. */
        if ($session->mustChangePassword) {
            return self::redirect($basePath . '/password/change?next=' . rawurlencode($next));
        }

        return self::redirect($next !== '' ? $next : $basePath . '/');
    }

    /** @param array<string,mixed> $request */
    private static function safeNext(array $request, string $basePath): string
    {
        $raw = (string) ($request['next'] ?? '');
        /* Same rule the password sign-in page uses: a path inside this portal,
           never somewhere else wearing our address. */
        $ok = $raw !== ''
            && str_starts_with($raw, $basePath . '/')
            && !str_contains($raw, "\n")
            && !str_contains($raw, "\r")
            && !str_starts_with($raw, '//')
            && preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw) !== 1;

        return $ok ? $raw : $basePath . '/my-schedule';
    }

    private static function backToLogin(string $basePath, string $message): string
    {
        return self::redirect($basePath . '/login?error=' . rawurlencode($message));
    }

    /** Redirect, and hand the router an empty body. */
    private static function redirect(string $location): string
    {
        if (PHP_SAPI !== 'cli') {
            header('Location: ' . $location, true, 302);
            header('Cache-Control: no-store');
        }

        return '';
    }

    private static function startSession(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}
