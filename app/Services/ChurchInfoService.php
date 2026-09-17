<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Read/write the church identity + contact + location info.
 *
 * Storage is a portal-owned JSON file (config/church-info.json) — deliberately
 * self-contained with NO dependency on ChurchCRM's config_cfg or any ChurchCRM
 * PHP, so it keeps working once ChurchCRM is decommissioned.
 */
final class ChurchInfoService
{
    /** Field => max length. */
    private const FIELDS = [
        'name' => 150, 'website' => 200, 'phone' => 50, 'email' => 120,
        'address' => 150, 'city' => 100, 'state' => 60, 'zip' => 20,
        'country' => 100, 'timeZone' => 100,
    ];

    public function __construct(private readonly string $configPath) {}

    /** @return array<string,string> */
    public function load(): array
    {
        $data = [];
        if (is_file($this->configPath)) {
            $raw = file_get_contents($this->configPath);
            $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        return $this->withDefaults($data);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function withDefaults(array $data): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $f) {
            $out[$f] = trim((string) ($data[$f] ?? ''));
        }
        if ($out['country'] === '') { $out['country'] = 'CA'; }
        if ($out['timeZone'] === '') { $out['timeZone'] = 'America/Toronto'; }
        return $out;
    }

    /**
     * Validate + persist. Returns the cleaned values.
     *
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    public function save(array $payload): array
    {
        $clean = [];
        foreach (self::FIELDS as $f => $max) {
            $v = trim((string) ($payload[$f] ?? ''));
            if (mb_strlen($v) > $max) {
                $v = mb_substr($v, 0, $max);
            }
            $clean[$f] = $v;
        }

        if ($clean['name'] === '') {
            throw new InvalidArgumentException('Church name is required.');
        }
        if ($clean['email'] !== '' && !filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email address looks invalid.');
        }

        $encoded = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode church info.');
        }
        $dir = dirname($this->configPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create config directory: $dir");
        }
        $tmp = tempnam($dir, 'church-info-');
        if ($tmp === false || file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
            if ($tmp !== false) { @unlink($tmp); }
            throw new RuntimeException('Failed to stage church info write.');
        }
        if (!@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to save church info.');
        }
        @chmod($this->configPath, 0664);
        return $clean;
    }
}
