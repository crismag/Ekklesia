<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\PermissionDenied;
use App\Http\Requests\PortalRequestContext;
use App\Services\AnnouncementSettingsService;

/**
 * GET  /api/announcements — portal notices the caller may see today: public
 *                            ones for anyone, plus signed-in and administrator
 *                            notices for those readers.
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
        // Not signed in (or a stale session) reads as a visitor, never as an error:
        // the portal entry shows public notices to everyone.
        try {
            $actor = $this->requestContext->fromArray($request);
        } catch (PermissionDenied) {
            $actor = null;
        }

        return ['items' => $this->service->activeNotices($actor)];
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
