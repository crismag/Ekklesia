<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ValidationFailed;
use App\Http\Requests\PortalRequestContext;
use App\Services\AuthService;

final readonly class AuthController
{
    public function __construct(
        private AuthService $authService,
        private PortalRequestContext $requestContext,
    ) {
    }

    /**
     * POST /api/login
     *
     * Body: { "email": "...", "password": "..." }
     * Sets a `portal_session` HttpOnly cookie carrying the session token,
     * and returns the same token + the bare user identity in the body.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function login(array $request): array
    {
        $email    = (string) ($request['email'] ?? '');
        $password = (string) ($request['password'] ?? '');
        if ($email === '' || $password === '') {
            throw new ValidationFailed('Email and password are required.');
        }

        $session = $this->authService->login(
            email: $email,
            plainPassword: $password,
            ipAddress: $request['_remote_addr'] ?? null,
            userAgent: $request['_user_agent'] ?? null,
        );

        $expiresUnix = $session->expiresAt->getTimestamp();
        $cookiePath  = ((string) ($request['_base_path'] ?? '')) . '/';
        if (PHP_SAPI !== 'cli') {
            setcookie('portal_session', $session->sessionToken, [
                'expires'  => $expiresUnix,
                'path'     => $cookiePath,
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => !empty($_SERVER['HTTPS']),
            ]);
        }

        return [
            'accountId'       => $session->accountId,
            'email'           => $session->email,
            'displayName'     => $session->displayName,
            'personId'        => $session->personId,
            'sessionToken'    => $session->sessionToken,
            'expiresAt'       => $session->expiresAt->format(DATE_ATOM),
            // Tells the login UI to redirect to /password/change before
            // serving anything else when true.
            'mustChangePassword' => $session->mustChangePassword,
        ];
    }

    /**
     * POST /api/auth/password
     *
     * Body: { "currentPassword": "...", "newPassword": "..." }
     * Requires a valid session. Clears must_change_password on success.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function changePassword(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $current = (string) ($request['currentPassword'] ?? '');
        $next    = (string) ($request['newPassword']     ?? '');
        $this->authService->changeOwnPassword($actor, $current, $next);
        return ['ok' => true];
    }

    /**
     * POST /api/logout — revokes the current session and clears the cookie.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function logout(array $request): array
    {
        $token = (string) ($request['cookies']['portal_session'] ?? $request['session_token'] ?? '');
        if ($token !== '') {
            $this->authService->logout($token);
        }
        $cookiePath = ((string) ($request['_base_path'] ?? '')) . '/';
        if (PHP_SAPI !== 'cli') {
            setcookie('portal_session', '', [
                'expires'  => time() - 3600,
                'path'     => $cookiePath,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return ['ok' => true];
    }

    /**
     * GET /api/me — returns the resolved actor for the current session.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function me(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        return [
            'actorId'           => $actor->actorId,
            'personId'          => $actor->personId,
            'isPortalWideAdmin' => $actor->isPortalWideAdmin,
            'permissions'       => array_map(fn ($p): string => $p->value, $actor->permissions),
            'ministryScopeIds'  => $actor->ministryScopeIds,
            'campusScopeIds'    => $actor->campusScopeIds,
            'currentCampusId'   => $actor->currentCampusId,
        ];
    }

    /**
     * GET /api/account
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function account(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        return [
            'account' => $this->authService->getAccountProfile($actor),
        ];
    }

    /**
     * POST /api/account/profile
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function updateProfile(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        return [
            'account' => $this->authService->updateOwnProfile(
                $actor,
                isset($request['displayName']) ? (string) $request['displayName'] : (string) ($request['display_name'] ?? ''),
                $this->auditMeta($request),
            ),
        ];
    }

    /**
     * POST /api/account/password
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function updatePassword(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $auditMeta = $this->auditMeta($request);
        $auditMeta['currentSessionToken'] = (string) ($request['cookies']['portal_session'] ?? $request['session_token'] ?? '') ?: null;

        $result = $this->authService->updateOwnPassword(
            $actor,
            (string) ($request['currentPassword'] ?? $request['current_password'] ?? ''),
            (string) ($request['newPassword'] ?? $request['new_password'] ?? ''),
            $auditMeta,
        );

        return ['ok' => true] + $result;
    }

    /**
     * GET /api/portal-access
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function portalAccess(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        return [
            'users' => $this->authService->listPortalAccess($actor),
        ];
    }

    /**
     * POST /api/portal-access
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function provisionPortalAccess(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        return [
            'user' => $this->authService->provisionPortalAccess(
                context: $actor,
                email: (string) ($request['email'] ?? ''),
                role: (string) ($request['role'] ?? ''),
                displayName: isset($request['displayName']) ? (string) $request['displayName'] : ((isset($request['display_name']) ? (string) $request['display_name'] : null)),
                temporaryPassword: isset($request['temporaryPassword']) ? (string) $request['temporaryPassword'] : ((isset($request['temporary_password']) ? (string) $request['temporary_password'] : null)),
                personId: isset($request['personId']) ? (int) $request['personId'] : (isset($request['person_id']) ? (int) $request['person_id'] : null),
                scopeMinistryId: isset($request['scopeMinistryId']) ? (int) $request['scopeMinistryId'] : (isset($request['ministry_id']) ? (int) $request['ministry_id'] : null),
                scopeCampusId: isset($request['scopeCampusId']) ? (int) $request['scopeCampusId'] : (isset($request['campus_id']) ? (int) $request['campus_id'] : null),
                auditMeta: $this->auditMeta($request),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array{ip:?string,userAgent:?string}
     */
    private function auditMeta(array $request): array
    {
        return [
            'ip'        => isset($request['_remote_addr']) ? (string) $request['_remote_addr'] : null,
            'userAgent' => isset($request['_user_agent'])  ? (string) $request['_user_agent']  : null,
        ];
    }
}
