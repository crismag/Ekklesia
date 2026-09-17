<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationFailed;
use RuntimeException;

/**
 * Persist simple ministries page settings to config/ministries.json.
 * Schema:
 *  pages: { board: bool, directory: bool }
 *  excluded_ministries: list<int>
 *  excluded_groups: list<int>
 */
final class MinistriesSettingsService
{
    public function __construct(private readonly string $configPath) {}

    /** @return array{pages:array{board:bool,directory:bool},excluded_ministries:list<string>,excluded_groups:list<string>,title:string,subtitle:string,hero_lead:string} */
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
        if (!is_array($decoded)) return $this->defaults();
        try { return $this->validate($decoded); } catch (ValidationFailed) { return $this->defaults(); }
    }

    /** @param array<string,mixed> $payload */
    public function save(array $payload): array
    {
        $clean = $this->validate($payload);
        $encoded = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) throw new RuntimeException('Failed to encode ministries settings.');
        $dir = dirname($this->configPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create config directory: $dir");
        }
        $tmp = tempnam($dir, 'ministries-');
        if ($tmp === false) throw new RuntimeException('Cannot create temp file.');
        if (file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) { @unlink($tmp); throw new RuntimeException('Failed to write temp file.'); }
        if (!@rename($tmp, $this->configPath)) { @unlink($tmp); throw new RuntimeException('Failed to replace config file.'); }
        @chmod($this->configPath, 0664);
        return $clean;
    }

    /** @param array<string,mixed> $payload */
    private function validate(array $payload): array
    {
        $pagesIn = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
        $excludedMin = is_array($payload['excluded_ministries'] ?? null) ? $payload['excluded_ministries'] : [];
        $excludedGroups = is_array($payload['excluded_groups'] ?? null) ? $payload['excluded_groups'] : [];

        $pages = [ 'board' => $this->coerceBool($pagesIn['board'] ?? true), 'directory' => $this->coerceBool($pagesIn['directory'] ?? true) ];

        $normalize = static fn($v) => trim((string) $v);
        $excludedMin = array_values(array_map($normalize, array_filter($excludedMin, fn($v) => trim((string) $v) !== '')));
        $excludedGroups = array_values(array_map($normalize, array_filter($excludedGroups, fn($v) => trim((string) $v) !== '')));

        $title = is_string($payload['title'] ?? null) ? trim($payload['title']) : '';
        $subtitle = is_string($payload['subtitle'] ?? null) ? trim($payload['subtitle']) : '';
        $hero = is_string($payload['hero_lead'] ?? null) ? trim($payload['hero_lead']) : '';

        return ['pages' => $pages, 'excluded_ministries' => $excludedMin, 'excluded_groups' => $excludedGroups, 'title' => $title, 'subtitle' => $subtitle, 'hero_lead' => $hero];
    }

    private function defaults(): array
    {
        return [
            'pages' => ['board' => true, 'directory' => true],
            'excluded_ministries' => [],
            'excluded_groups' => [],
            'title' => 'Ministry Schedule Board',
            'subtitle' => 'Public board view for upcoming ministry schedules by date range and event.',
            'hero_lead' => 'A compact board for seeing ministry schedules across the church. Use the date range and event selector to view what can be posted, printed, or checked at a glance.',
        ];
    }

    private function coerceBool(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v !== 0;
        if (is_string($v)) return in_array(strtolower(trim($v)), ['1','true','yes','on'], true);
        return false;
    }
}
