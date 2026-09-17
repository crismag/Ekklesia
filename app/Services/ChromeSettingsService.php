<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationFailed;
use RuntimeException;

/**
 * Read/write the site-wide header + footer "chrome" config.
 *
 * Storage is config/chrome.json — used by _portal-shell.php so every page
 * that calls portal_header() / portal_footer() picks up brand-text /
 * primary-nav / footer-text overrides without having to redeploy.
 */
final class ChromeSettingsService
{
    private const ALLOWED_NAV_ICONS = [
        'dashboard', 'ministry', 'calendar', 'events', 'people',
        'availability', 'settings', 'admin', 'profile', 'search', 'menu',
    ];
    private const MAX_NAV_ITEMS = 10;

    public function __construct(private readonly string $configPath) {}

    /**
     * @return array{
     *   header:array{brandTitle:string,brandSubtitle:string,primaryNav:list<array{label:string,href:string,icon:string}>},
     *   footer:array{leftText:string,rightText:string,minimal:bool}
     * }
     */
    public function load(): array
    {
        if (!is_file($this->configPath)) {
            return $this->defaults();
        }
        $raw = file_get_contents($this->configPath);
        if ($raw === false || $raw === '') {
            return $this->defaults();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->defaults();
        }
        try {
            return $this->validate($decoded);
        } catch (ValidationFailed) {
            return $this->defaults();
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   header:array{brandTitle:string,brandSubtitle:string,primaryNav:list<array{label:string,href:string,icon:string}>},
     *   footer:array{leftText:string,rightText:string,minimal:bool}
     * }
     */
    public function save(array $payload): array
    {
        $clean = $this->validate($payload);
        $encoded = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode chrome config.');
        }
        $dir = dirname($this->configPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create config directory: $dir");
        }
        $tmp = tempnam($dir, 'chrome-');
        if ($tmp === false || file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
            if ($tmp !== false) @unlink($tmp);
            throw new RuntimeException('Failed to stage chrome config write.');
        }
        if (!@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to atomically replace chrome config.');
        }
        @chmod($this->configPath, 0664);
        return $clean;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   header:array{brandTitle:string,brandSubtitle:string,primaryNav:list<array{label:string,href:string,icon:string}>},
     *   footer:array{leftText:string,rightText:string,minimal:bool}
     * }
     */
    private function validate(array $payload): array
    {
        $headerIn = is_array($payload['header'] ?? null) ? $payload['header'] : [];
        $footerIn = is_array($payload['footer'] ?? null) ? $payload['footer'] : [];
        $navIn    = is_array($headerIn['primaryNav'] ?? null) ? $headerIn['primaryNav'] : [];

        if (count($navIn) > self::MAX_NAV_ITEMS) {
            throw new ValidationFailed(sprintf('At most %d primary-nav items are allowed.', self::MAX_NAV_ITEMS));
        }

        $cleanNav = [];
        foreach ($navIn as $i => $item) {
            if (!is_array($item)) {
                throw new ValidationFailed("Nav item #" . ($i + 1) . ' must be an object.');
            }
            $label = trim((string) ($item['label'] ?? ''));
            $href  = trim((string) ($item['href']  ?? ''));
            $icon  = (string) ($item['icon'] ?? 'dashboard');
            if ($label === '' || $href === '') {
                throw new ValidationFailed("Nav item #" . ($i + 1) . ' needs a label and href.');
            }
            if (!in_array($icon, self::ALLOWED_NAV_ICONS, true)) {
                $icon = 'dashboard';
            }
            // Don't accept arbitrary URLs — only same-origin paths starting with "/".
            if (!str_starts_with($href, '/')) {
                throw new ValidationFailed("Nav item #" . ($i + 1) . ' href must be a same-origin path starting with /.');
            }
            $cleanNav[] = [
                'label' => mb_substr($label, 0, 40),
                'href'  => mb_substr($href,  0, 200),
                'icon'  => $icon,
            ];
        }

        return [
            'header' => [
                'brandTitle'    => mb_substr(trim((string) ($headerIn['brandTitle']    ?? 'Scheduler')), 0, 60),
                'brandSubtitle' => mb_substr(trim((string) ($headerIn['brandSubtitle'] ?? '')), 0, 120),
                'primaryNav'    => $cleanNav !== [] ? $cleanNav : $this->defaults()['header']['primaryNav'],
            ],
            'footer' => [
                'leftText'  => mb_substr(trim((string) ($footerIn['leftText']  ?? 'Church Portal')), 0, 80),
                'rightText' => mb_substr(trim((string) ($footerIn['rightText'] ?? '')), 0, 120),
                'minimal'   => $this->coerceBool($footerIn['minimal'] ?? true),
            ],
        ];
    }

    private function coerceBool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value))  return $value !== 0;
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function defaults(): array
    {
        return [
            'header' => [
                'brandTitle'    => 'Scheduler',
                'brandSubtitle' => 'Campus-aware ministry operations',
                'primaryNav'    => [
                    ['label' => 'Dashboard',    'href' => '/',             'icon' => 'dashboard'],
                    ['label' => 'Ministries',   'href' => '/ministries',   'icon' => 'ministry'],
                    ['label' => 'Calendar',     'href' => '/calendar',     'icon' => 'calendar'],
                    ['label' => 'Events',       'href' => '/events',       'icon' => 'events'],
                    ['label' => 'People',       'href' => '/people',       'icon' => 'people'],
                    ['label' => 'Availability', 'href' => '/availability', 'icon' => 'availability'],
                ],
            ],
            'footer' => [
                'leftText'  => 'Church Portal',
                'rightText' => 'Campus-aware ministry operations',
                'minimal'   => true,
            ],
        ];
    }
}
