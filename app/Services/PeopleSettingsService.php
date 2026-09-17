<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationFailed;
use RuntimeException;

/**
 * Read/write simple people-directory settings persisted to config/people.json
 * Schema:
 *   columns: { name:bool, ministries:bool, roles:bool, memberType:bool, assignments:bool, birthday:bool, status:bool }
 *   header: { title:string, subtitle:string }
 */
final class PeopleSettingsService
{
    private const DEFAULT_COLS = [
        'name' => true, 'ministries' => true, 'roles' => true,
        'memberType' => true, 'assignments' => true, 'birthday' => true, 'status' => true,
    ];

    public function __construct(private readonly string $configPath) {}

    /**
     * Load merged settings. If $campusId is provided and campus-specific
     * overrides exist, they will be merged on top of global settings.
     *
     * @param string|null $campusId
     * @return array{columns:array<string,bool>,header:array{title:string,subtitle:string}}
     */
    public function load(?string $campusId = null): array
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

        $global = is_array($decoded['global'] ?? null) ? $decoded['global'] : $this->defaults();
        $campusMap = is_array($decoded['campus'] ?? null) ? $decoded['campus'] : [];
        if ($campusId !== null && isset($campusMap[(string) $campusId]) && is_array($campusMap[(string) $campusId])) {
            $override = $campusMap[(string) $campusId];
            return $this->merge($global, $override);
        }
        return $this->normalize($global);
    }

    /**
     * Save settings either globally (null $campusId) or for a specific campus.
     * @param array<string,mixed> $payload
     * @param string|null $campusId
     * @return array{columns:array<string,bool>,header:array{title:string,subtitle:string}}
     */
    public function save(array $payload, ?string $campusId = null): array
    {
        $clean = $this->validate($payload);

        $decoded = [];
        if (is_file($this->configPath)) {
            $raw = file_get_contents($this->configPath);
            $decoded = is_string($raw) ? json_decode($raw, true) ?? [] : [];
        }
        if (!is_array($decoded)) $decoded = [];

        // ensure structure
        $decoded['global'] = is_array($decoded['global'] ?? null) ? $decoded['global'] : $this->defaults();
        $decoded['campus'] = is_array($decoded['campus'] ?? null) ? $decoded['campus'] : [];

        if ($campusId === null || (string) $campusId === '') {
            $decoded['global'] = $clean;
        } else {
            $decoded['campus'][(string) $campusId] = $clean;
        }

        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode people settings as JSON.');
        }
        $dir = dirname($this->configPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create config directory: $dir");
        }
        $tmp = tempnam($dir, 'people-');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create temporary file for people config.');
        }
        if (file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to write people config tempfile.');
        }
        if (!@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to atomically replace people config.');
        }
        @chmod($this->configPath, 0664);
        return $clean;
    }

    /** @param array<string,mixed> $payload */
    private function validate(array $payload): array
    {
        $colsIn = is_array($payload['columns'] ?? null) ? $payload['columns'] : [];
        $headerIn = is_array($payload['header'] ?? null) ? $payload['header'] : [];

        $cols = [];
        foreach (self::DEFAULT_COLS as $k => $v) {
            $cols[$k] = $this->coerceBool($colsIn[$k] ?? $v);
        }

        $title = mb_substr(trim((string) ($headerIn['title'] ?? 'People Portal')), 0, 120);
        $subtitle = mb_substr(trim((string) ($headerIn['subtitle'] ?? '')), 0, 240);

        return ['columns' => $cols, 'header' => ['title' => $title, 'subtitle' => $subtitle]];
    }

    private function normalize(array $decoded): array
    {
        try {
            return $this->validate($decoded);
        } catch (ValidationFailed) {
            return $this->defaults();
        }
    }

    /** @return array{columns:array<string,bool>,header:array{title:string,subtitle:string}} */
    private function defaults(): array
    {
        return ['columns' => self::DEFAULT_COLS, 'header' => ['title' => 'People Portal', 'subtitle' => 'Campus-aware people browsing with public-safe profile visibility.']];
    }

    private function coerceBool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }
}
