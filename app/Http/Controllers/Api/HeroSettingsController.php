<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\PermissionDenied;
use App\Http\Requests\PortalRequestContext;
use App\Services\HeroSettingsService;

/**
 * Read/write the dashboard hero rotator config.
 *
 * GET  /api/hero  — returns the current config (public; the dashboard JS
 *                   fetches this on every page load).
 * POST /api/hero  — replaces the config; portal-admin only.
 */
final readonly class HeroSettingsController
{
    public function __construct(
        private HeroSettingsService $service,
        private PortalRequestContext $requestContext,
    ) {}

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function show(array $request): array
    {
        return $this->service->load();
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function update(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        if (!$actor->isPortalWideAdmin) {
            throw new PermissionDenied('Hero settings can only be edited by a portal-wide admin.');
        }
        return $this->service->save($request);
    }
}
