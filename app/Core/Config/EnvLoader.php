<?php

declare(strict_types=1);

namespace App\Core\Config;

/**
 * Minimal .env file loader for the standalone-PHP scaffold.
 *
 * Reads KEY=VALUE pairs from a .env file and populates them into
 * $_ENV / $_SERVER / putenv() unless already defined. Quoted values
 * (single or double) are unquoted; lines starting with '#' are ignored.
 *
 * In a full Laravel install this is a no-op — Laravel's vlucas/phpdotenv
 * runs earlier in the bootstrap. The two paths are compatible because
 * this loader never overwrites an existing environment variable.
 */
final class EnvLoader
{
    private static bool $loaded = false;

    public static function loadOnce(string $envPath): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_readable($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $eq = strpos($trimmed, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $eq));
            $value = trim(substr($trimmed, $eq + 1));
            if ($key === '') {
                continue;
            }
            // Strip wrapping quotes
            if (
                strlen($value) >= 2
                && (
                    ($value[0] === '"' && $value[-1] === '"')
                    || ($value[0] === "'" && $value[-1] === "'")
                )
            ) {
                $value = substr($value, 1, -1);
            }

            // Don't overwrite an already-set env var (Laravel/CI may have
            // populated it earlier with higher authority).
            if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
                continue;
            }
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }
        return $default;
    }
}
