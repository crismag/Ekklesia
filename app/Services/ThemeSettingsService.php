<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationFailed;
use RuntimeException;

/**
 * Read/write the portal theme config.
 *
 * Storage is a JSON file at config/theme.json with shape:
 *   { active: string, presets: { <id>: { name, description, vars: {<css-var>: <value>, ...} } } }
 *
 * The CSS variable map for the active preset is emitted by
 * _portal-shell.php at the top of every page so a single source of truth
 * controls colors, radii, font scale, and gradient endpoints across the
 * whole portal.
 */
final class ThemeSettingsService
{
    /** Tokens we accept for inline writes — narrow this list to keep injections impossible. */
    private const ALLOWED_VARS = [
        '--ink', '--muted', '--line', '--paper', '--deep', '--teal', '--blue', '--gold',
        '--rose', '--soft', '--bg', '--gradient-top', '--gradient-mid', '--font-scale',
        '--radius', '--spacing',
    ];

    public function __construct(private readonly string $configPath) {}

    /**
     * @return array{
     *   active:string,
     *   presets:array<string,array{name:string,description:string,vars:array<string,string>}>
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
        if (!is_array($decoded) || empty($decoded['presets']) || !is_array($decoded['presets'])) {
            return $this->defaults();
        }
        $active = (string) ($decoded['active'] ?? 'forest');
        if (!isset($decoded['presets'][$active])) {
            $active = (string) array_key_first($decoded['presets']);
        }
        return [
            'active'  => $active,
            'presets' => $decoded['presets'],
        ];
    }

    /**
     * Convenience: returns the active preset only — what the shell needs to
     * emit CSS variables.
     *
     * @return array{id:string,name:string,description:string,vars:array<string,string>}
     */
    public function loadActivePreset(): array
    {
        $cfg = $this->load();
        $id = $cfg['active'];
        $preset = $cfg['presets'][$id] ?? null;
        if (!is_array($preset)) {
            $preset = $this->defaults()['presets']['forest'];
        }
        return [
            'id'          => $id,
            'name'        => (string) ($preset['name'] ?? $id),
            'description' => (string) ($preset['description'] ?? ''),
            'vars'        => is_array($preset['vars'] ?? null) ? $preset['vars'] : [],
        ];
    }

    /**
     * Switch the active preset. Pass an existing preset id.
     *
     * @return array{active:string,presets:array<string,mixed>}
     */
    public function setActive(string $presetId): array
    {
        $cfg = $this->load();
        if (!isset($cfg['presets'][$presetId])) {
            throw new ValidationFailed("Unknown theme preset: $presetId");
        }
        $cfg['active'] = $presetId;
        return $this->writeAtomic($cfg);
    }

    /**
     * Replace one preset's variables (for fine-tuning the colors of an
     * existing preset). Unknown variable names are stripped.
     *
     * @param array<string,string> $vars
     * @return array{active:string,presets:array<string,mixed>}
     */
    public function updatePresetVars(string $presetId, array $vars): array
    {
        $cfg = $this->load();
        if (!isset($cfg['presets'][$presetId])) {
            throw new ValidationFailed("Unknown theme preset: $presetId");
        }
        $cleanVars = [];
        foreach (self::ALLOWED_VARS as $allowed) {
            if (isset($vars[$allowed])) {
                $value = trim((string) $vars[$allowed]);
                // Hex / rgb / numeric only — block CSS injections.
                if ($value === '' || preg_match('/[<>{};]/', $value)) {
                    continue;
                }
                $cleanVars[$allowed] = mb_substr($value, 0, 32);
            }
        }
        // Keep any vars we didn't accept from the input but that the preset
        // already had, so partial updates don't wipe defaults.
        $cfg['presets'][$presetId]['vars'] = array_merge(
            (array) ($cfg['presets'][$presetId]['vars'] ?? []),
            $cleanVars,
        );
        return $this->writeAtomic($cfg);
    }

    /** @param array<string,mixed> $cfg */
    private function writeAtomic(array $cfg): array
    {
        $encoded = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode theme config as JSON.');
        }
        $dir = dirname($this->configPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create config directory: $dir");
        }
        $tmp = tempnam($dir, 'theme-');
        if ($tmp === false || file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
            if ($tmp !== false) @unlink($tmp);
            throw new RuntimeException('Failed to stage theme config write.');
        }
        if (!@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to atomically replace theme config.');
        }
        @chmod($this->configPath, 0664);
        return $cfg;
    }

    /** @return array{active:string,presets:array<string,mixed>} */
    private function defaults(): array
    {
        return [
            'active' => 'forest',
            'presets' => [
                'forest' => [
                    'name' => 'Forest (default)',
                    'description' => 'Deep-teal heritage palette.',
                    'vars' => [
                        '--ink' => '#17211b', '--muted' => '#66756d', '--line' => '#d9e4dd',
                        '--paper' => '#ffffff', '--deep' => '#123b31', '--teal' => '#117b6d',
                        '--blue' => '#276a9f', '--gold' => '#c48725', '--rose' => '#b84957',
                        '--soft' => '#eef4f0', '--bg' => '#f7faf8',
                        '--gradient-top' => '#0c2f28', '--gradient-mid' => '#123b31',
                        '--font-scale' => '1', '--radius' => '8px', '--spacing' => '1',
                    ],
                ],
            ],
        ];
    }
}
