<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\PortalRequestContext;
use App\Services\MinistryService;
use App\Exceptions\ValidationFailed;
use DateTimeImmutable;

final readonly class MinistryController
{
    public function __construct(
        private MinistryService $ministryService,
        private PortalRequestContext $requestContext,
    ) {
    }

    /**
     * GET /api/ministries
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function list(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        return [
            'ministries' => $this->ministryService->listAccessibleMinistries($actor),
            'campusSelector' => $this->ministryService->getCampusSelector($actor),
        ];
    }

    /**
     * GET /api/campuses
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function campuses(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        return $this->ministryService->getCampusSelector($actor);
    }

    /**
     * GET /api/ministry-dashboard
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function dashboard(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $since = new DateTimeImmutable((string) ($request['since'] ?? '-90 days'));
        $until = new DateTimeImmutable((string) ($request['until'] ?? '+90 days'));

        return [
            'cards' => array_map(
                static fn ($card): array => $card->toArray(),
                $this->ministryService->listDashboardCards($actor, $since, $until),
            ),
        ];
    }

    /**
     * GET /api/ministry/{id}/roles
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function getRoles(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);

        $roles = $this->ministryService->getMinistryRoles($actor, $ministryId);

        return [
            'roles' => array_map(
                static fn ($role): array => $role->toArray(),
                $roles,
            ),
        ];
    }

    /**
     * POST /api/ministry/{id}/roles
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function createRole(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);

        $data = [
            'name' => (string) ($request['name'] ?? ''),
            'order' => (int) ($request['order'] ?? 0),
            'active' => (bool) ($request['active'] ?? true),
        ];

        $role = $this->ministryService->createRole($actor, $ministryId, $data);

        return ['role' => $role->toArray()];
    }

    /**
     * PUT /api/ministry/roles/{roleId}
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function updateRole(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $roleId = (int) ($request['roleId'] ?? 0);

        $data = [];
        if (isset($request['name'])) {
            $data['name'] = (string) $request['name'];
        }
        if (isset($request['order'])) {
            $data['order'] = (int) $request['order'];
        }
        if (isset($request['active'])) {
            $data['active'] = (bool) $request['active'];
        }

        $role = $this->ministryService->updateRole($actor, $roleId, $data);

        return ['role' => $role->toArray()];
    }

    /**
     * DELETE /api/ministry/roles/{roleId}
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function deleteRole(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $roleId = (int) ($request['roleId'] ?? 0);

        $success = $this->ministryService->deleteRole($actor, $roleId);

        return ['success' => $success];
    }

    /**
     * POST /api/ministry/roles/{roleId}/assign/{personId}
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function assignToRole(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $roleId = (int) ($request['roleId'] ?? 0);
        $personId = (int) ($request['personId'] ?? 0);

        $success = $this->ministryService->assignPersonToRole($actor, $personId, $roleId);

        return ['success' => $success];
    }

    /**
     * DELETE /api/ministry/roles/{roleId}/assign/{personId}
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function removeFromRole(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $roleId = (int) ($request['roleId'] ?? 0);
        $personId = (int) ($request['personId'] ?? 0);

        $success = $this->ministryService->removePersonFromRole($actor, $personId, $roleId);

        return ['success' => $success];
    }

    /**
     * GET /api/ministry/{id}/leaders
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function getLeaders(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);
        $since = new DateTimeImmutable((string) ($request['since'] ?? '-12 months'));
        $campusId = $this->campusFromRequest($request);

        $leaders = $this->ministryService->getMinistryLeaders($actor, $ministryId, $since, $campusId);

        return [
            'leaders' => array_map(
                static fn ($leader): array => $leader->toArray(),
                $leaders,
            ),
        ];
    }

    /**
     * GET /api/ministry/{id}/members — full roster (all members, role, positions).
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function getMembers(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);
        $campusId = $this->campusFromRequest($request);

        return ['members' => $this->ministryService->getMinistryMembers($actor, $ministryId, $campusId)];
    }

    /**
     * GET /api/ministry/{id}/group-roles — membership roles (Member / Leader).
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function getGroupRoles(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);

        return ['groupRoles' => $this->ministryService->getGroupRoles($actor, $ministryId)];
    }

    /**
     * POST /api/ministry/{id}/members/{personId}/role  { role: 'member'|'leader' }
     * Set a member's role (adds the membership when absent).
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function setMemberRole(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);
        $personId = (int) ($request['personId'] ?? 0);
        $role = (string) ($request['role'] ?? 'member');

        return ['success' => $this->ministryService->setMemberRole($actor, $ministryId, $personId, $role)];
    }

    /**
     * DELETE /api/ministry/{id}/members/{personId} — remove from the ministry.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function removeMember(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);
        $personId = (int) ($request['personId'] ?? 0);

        return ['success' => $this->ministryService->removeMemberFromMinistry($actor, $ministryId, $personId)];
    }

    /**
     * POST /api/ministry/{id}/leaders/{personId} — make a member a leader.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function tagLeader(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);
        $personId = (int) ($request['personId'] ?? 0);

        return ['success' => $this->ministryService->tagLeader($actor, $ministryId, $personId)];
    }

    /**
     * DELETE /api/ministry/{id}/leaders/{personId} — make a leader a member.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function untagLeader(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);
        $personId = (int) ($request['personId'] ?? 0);

        return ['success' => $this->ministryService->untagLeader($actor, $ministryId, $personId)];
    }

    /**
     * Parse the global campus filter (current_campus_id). Empty / "all" / 0 → null
     * meaning "all campuses".
     *
     * @param array<string, mixed> $request
     */
    private function campusFromRequest(array $request): ?int
    {
        $raw = $request['current_campus_id'] ?? null;
        if ($raw === null || $raw === '' || $raw === 'all') {
            return null;
        }
        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    // ---------------------------------------------------------------------
    // Ministry CRUD (admin) — GET/POST/PUT/DELETE /api/admin/ministries
    // ---------------------------------------------------------------------

    /**
     * GET /api/admin/ministries
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function listAdminMinistries(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $campusId = $this->campusFromRequest($request);

        return ['ministries' => $this->ministryService->listMinistriesAdmin($actor, $campusId)];
    }

    /**
     * GET /api/admin/leaders — all leaders grouped by ministry (overview).
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function listLeaders(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $campusId = $this->campusFromRequest($request);

        return ['leaders' => $this->ministryService->listLeadersByMinistry($actor, $campusId)];
    }

    /**
     * POST /api/admin/ministries
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function createMinistry(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);

        $data = [
            'name' => (string) ($request['name'] ?? ''),
            'description' => (string) ($request['description'] ?? ''),
        ];
        if (isset($request['campus_id']) && $request['campus_id'] !== '') {
            $data['campus_id'] = (int) $request['campus_id'];
        }

        return ['ministry' => $this->ministryService->createMinistry($actor, $data)];
    }

    /**
     * PUT /api/admin/ministries/{id}
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function updateMinistry(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);

        $data = [
            'name' => (string) ($request['name'] ?? ''),
            'description' => (string) ($request['description'] ?? ''),
        ];
        if (array_key_exists('campus_id', $request) && $request['campus_id'] !== '') {
            $data['campus_id'] = (int) $request['campus_id'];
        }

        return ['ministry' => $this->ministryService->updateMinistry($actor, $ministryId, $data)];
    }

    /**
     * POST /api/admin/ministries/{id}/active
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function setMinistryActive(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);
        $active = filter_var($request['active'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $success = $this->ministryService->setMinistryActive($actor, $ministryId, $active);

        return ['success' => $success, 'active' => $active];
    }

    /**
     * DELETE /api/admin/ministries/{id}
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function deleteMinistry(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['id'] ?? 0);

        return ['success' => $this->ministryService->deleteMinistry($actor, $ministryId)];
    }
}
