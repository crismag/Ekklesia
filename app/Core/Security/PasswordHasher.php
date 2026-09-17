<?php

declare(strict_types=1);

namespace App\Core\Security;

use RuntimeException;

/**
 * Argon2id password hasher.
 *
 * Strategy:
 *   - hash(): Argon2id (PHP_PASSWORD_ARGON2ID) with conservative defaults.
 *   - verify(): constant-time compare via password_verify().
 *   - needsRehash(): true when Argon2id parameters have changed since the
 *     hash was created (caller should re-hash transparently on next login).
 *
 * The exact memory_cost / time_cost / threads numbers can be tuned per
 * deployment via env vars without changing call sites.
 */
final class PasswordHasher
{
    /** @var array<string, int> */
    private array $options;

    public function __construct(
        int $memoryCost = 65536,    // KB; 64 MiB
        int $timeCost   = 4,
        int $threads    = 1,
    ) {
        $this->options = [
            'memory_cost' => $memoryCost,
            'time_cost'   => $timeCost,
            'threads'     => $threads,
        ];
    }

    public function hash(string $plain): string
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new RuntimeException('Argon2id is required but not available in this PHP build.');
        }
        $hash = password_hash($plain, PASSWORD_ARGON2ID, $this->options);
        if (!is_string($hash)) {
            throw new RuntimeException('password_hash() failed.');
        }
        return $hash;
    }

    public function verify(string $plain, string $storedHash): bool
    {
        if ($storedHash === '') {
            return false;
        }
        return password_verify($plain, $storedHash);
    }

    public function needsRehash(string $storedHash): bool
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            return false;
        }
        return password_needs_rehash($storedHash, PASSWORD_ARGON2ID, $this->options);
    }
}
