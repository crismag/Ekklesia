<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\PermissionDenied;
use App\Http\Requests\PortalRequestContext;
use App\Services\AnnouncementSettingsService;

/**
 * GET  /api/announcements — published, in-window items (public).
 * POST /api/announcements — replace the full list; portal-admin only.
 */
final readonly class AnnouncementSettingsController
{
    public function __construct(
        private AnnouncementSettingsService $service,
        private PortalRequestContext $requestContext,
    ) {}

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function show(array $request): array
    {
        return ['items' => $this->service->publishedNow()];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function update(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        if (!$actor->isPortalWideAdmin) {
            throw new PermissionDenied('Announcements can only be edited by a portal-wide admin.');
        }
        return $this->service->save($request);
    }
}
