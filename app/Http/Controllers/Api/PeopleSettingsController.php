<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\PermissionDenied;
use App\Http\Requests\PortalRequestContext;
use App\Services\PeopleSettingsService;

final readonly class PeopleSettingsController
{
    public function __construct(
        private PeopleSettingsService $people,
        private PortalRequestContext $requestContext,
    ) {}

    /** @param array<string,mixed> $request */
    public function show(array $request): array
    {
        // Accept optional campus_id query param for campus-scoped preview
        $campusId = isset($request['campus_id']) ? (string) $request['campus_id'] : null;
        return $this->people->load($campusId);
    }

    /** @param array<string,mixed> $request */
    public function update(array $request): array
    {
        $this->requireAdmin($request);
        // allow optional campus_id to scope the save
        $campusId = isset($request['campus_id']) ? (string) $request['campus_id'] : null;
        return $this->people->save($request, $campusId);
    }

    private function requireAdmin(array $request): void
    {
        $actor = $this->requestContext->fromArray($request);
        if (!$actor->isPortalWideAdmin) {
            throw new PermissionDenied('People directory settings can only be edited by a portal-wide admin.');
        }
    }
}
