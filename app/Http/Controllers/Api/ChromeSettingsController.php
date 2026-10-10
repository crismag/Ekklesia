<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\PermissionDenied;
use App\Http\Requests\PortalRequestContext;
use App\Services\ChromeSettingsService;
use App\Services\ThemeSettingsService;

/**
 * Site-chrome admin endpoints — header text, primary nav, footer text,
 * minimal footer toggle, theme picker. All write operations require a
 * portal-wide admin; reads are public so signed-out visitors still get
 * the correct branding.
 */
final readonly class ChromeSettingsController
{
    public function __construct(
        private ChromeSettingsService $chrome,
        private ThemeSettingsService $theme,
        private PortalRequestContext $requestContext,
    ) {}

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function showChrome(array $request): array
    {
        return $this->chrome->load();
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function updateChrome(array $request): array
    {
        $this->requireAdmin($request);
        return $this->chrome->save($request);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function showTheme(array $request): array
    {
        return $this->theme->load();
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function setActiveTheme(array $request): array
    {
        $this->requireAdmin($request);
        $presetId = (string) ($request['active'] ?? '');
        return $this->theme->setActive($presetId);
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function updateThemePreset(array $request): array
    {
        $this->requireAdmin($request);
        $presetId = (string) ($request['preset'] ?? '');
        $vars = is_array($request['vars'] ?? null) ? $request['vars'] : [];
        return $this->theme->updatePresetVars($presetId, $vars);
    }

    /** @param array<string,mixed> $request */
    private function requireAdmin(array $request): void
    {
        $actor = $this->requestContext->fromArray($request);
        if (!$actor->isPortalWideAdmin) {
            throw new PermissionDenied('Site chrome can only be edited by a portal-wide admin.');
        }
    }
}
