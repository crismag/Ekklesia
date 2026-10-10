<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Exceptions\PermissionDenied;
use App\Services\AuthService;

/**
 * Resolves an incoming HTTP request into an ActorContext.
 *
 * Resolution order (first match wins):
 *   1. session_token in the request (from cookie or Authorization header)
 *      → AuthService::resolveActor() — produces a real ActorContext from
 *        the user_accounts / account_roles rows.
 *   2. Direct ActorContext fields in the request payload — used by tests and
 *      service-internal callers that already hold a context. Permissions
 *      default to ViewOwnAssignments only when nothing else is supplied.
 *
 * After the actor is resolved, the per-request campus override is applied via
 * ActorContext::withCurrentCampusIds() or withCurrentCampusId().
 */
final class PortalRequestContext
{
    public function __construct(
        private readonly ?AuthService $authService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     */
    public function fromArray(array $request): ActorContext
    {
        $context = $this->resolveBaseContext($request);

        if (array_key_exists('current_campus_ids', $request)) {
            $requestedCampusIds = $this->campusIdsFromRequest($request['current_campus_ids']);
            foreach ($requestedCampusIds as $requestedCampusId) {
                if (!$context->canAccessCampus($requestedCampusId)) {
                    throw new PermissionDenied(
                        "Actor is outside the requested campus scope (campus {$requestedCampusId})."
                    );
                }
            }

            return $context->withCurrentCampusIds($requestedCampusIds);
        }

        // Per-request campus override: the UI's top-bar selector posts a
        // current_campus_id alongside the request. Validate it falls within
        // the actor's campus scope (or that they're a portal-wide admin).
        if (array_key_exists('current_campus_id', $request) && $request['current_campus_id'] !== null) {
            $requestedCampusId = (int) $request['current_campus_id'];
            if ($requestedCampusId > 0 && !$context->canAccessCampus($requestedCampusId)) {
                throw new PermissionDenied(
                    "Actor is outside the requested campus scope (campus {$requestedCampusId})."
                );
            }
            $context = $context->withCurrentCampusId($requestedCampusId > 0 ? $requestedCampusId : null);
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function resolveBaseContext(array $request): ActorContext
    {
        $sessionToken = $this->extractSessionToken($request);
        if ($sessionToken !== null && $this->authService !== null) {
            return $this->authService->resolveActor($sessionToken);
        }

        // When AuthService is wired (HTTP path) but no session token was found,
        // refuse to fall back to request-supplied actor fields — that would let
        // an unauthenticated caller forge actor_id/permissions via query/body.
        if ($this->authService !== null) {
            throw new PermissionDenied('Authentication required.');
        }

        // Fallback: accept already-resolved context fields directly. Used by
        // tests and CLI callers that construct PortalRequestContext without
        // an AuthService; never reachable from HTTP.
        return new ActorContext(
            actorId: (int) ($request['actor_id'] ?? 0),
            personId: isset($request['person_id']) ? (int) $request['person_id'] : null,
            displayName: isset($request['display_name']) ? (string) $request['display_name'] : null,
            permissions: $this->permissionsFromRequest($request),
            ministryScopeIds: array_map('intval', $request['ministry_scope_ids'] ?? []),
            isPortalWideAdmin: (bool) ($request['is_portal_wide_admin'] ?? false),
            campusScopeIds: array_map('intval', $request['campus_scope_ids'] ?? []),
            // 0 means "All campuses": the selector sends the empty option as 0
            // rather than omitting the parameter. Passing that through as a
            // real id made every campus-aware read filter on campus 0, which
            // matches nothing — the calendar showed no events and no birthdays
            // as soon as All was selected. currentCampusIds below already
            // treated 0 as "no filter"; this now agrees with it.
            currentCampusId: isset($request['current_campus_id']) && (int) $request['current_campus_id'] > 0
                ? (int) $request['current_campus_id']
                : null,
            currentCampusIds: array_key_exists('current_campus_ids', $request)
                ? $this->campusIdsFromRequest($request['current_campus_ids'])
                : (isset($request['current_campus_id']) && (int) $request['current_campus_id'] > 0
                    ? [(int) $request['current_campus_id']]
                    : []),
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function extractSessionToken(array $request): ?string
    {
        // Direct field
        if (isset($request['session_token']) && is_string($request['session_token']) && $request['session_token'] !== '') {
            return $request['session_token'];
        }
        // Cookie payload
        if (isset($request['cookies']) && is_array($request['cookies'])) {
            $cookieToken = $request['cookies']['portal_session'] ?? null;
            if (is_string($cookieToken) && $cookieToken !== '') {
                return $cookieToken;
            }
        }
        // Authorization: Bearer <token>
        if (isset($request['authorization']) && is_string($request['authorization'])) {
            if (preg_match('/^Bearer\s+(\S+)$/i', $request['authorization'], $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $request
     * @return list<PortalPermission>
     */
    private function permissionsFromRequest(array $request): array
    {
        $permissions = $request['permissions'] ?? [PortalPermission::ViewOwnAssignments];

        return array_map(
            fn (PortalPermission|string $permission): PortalPermission => $permission instanceof PortalPermission
                ? $permission
                : PortalPermission::from($permission),
            $permissions,
        );
    }

    /**
     * @param mixed $rawCampusIds
     * @return list<int>
     */
    private function campusIdsFromRequest(mixed $rawCampusIds): array
    {
        if (is_string($rawCampusIds)) {
            $rawCampusIds = $rawCampusIds === ''
                ? []
                : preg_split('/\s*,\s*/', $rawCampusIds, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (!is_array($rawCampusIds)) {
            return [];
        }

        $campusIds = [];
        foreach ($rawCampusIds as $campusId) {
            $campusId = (int) $campusId;
            if ($campusId <= 0) {
                continue;
            }
            $campusIds[$campusId] = $campusId;
        }

        return array_values($campusIds);
    }
}
