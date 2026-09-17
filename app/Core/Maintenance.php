<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A short, deliberate pause across the site while a release lands.
 *
 * Deployment here is an rsync that overwrites files in place: there is no
 * atomic release swap on shared hosting. So between the moment new code arrives
 * and the moment its migration has run, the application is executing against a
 * schema it does not match. That window has already cost this site twice —
 * migration 007 broke the member import, and 009 made the calendar render no
 * events at all with no error anyone could see. A gate is the only way to close
 * it without a second server.
 *
 * Two properties matter more than the feature itself:
 *
 * 1. It fails open. The flag carries an expiry, and once that passes it is
 *    ignored. A deploy that dies halfway — a dropped connection, a laptop lid
 *    closing — must not leave a church's website dark until somebody notices
 *    and knows where to look.
 *
 * 2. It costs one stat() per request. This sits in front of every route, so it
 *    reads a file and nothing else: no database, no config parsing, no session.
 */
final class Maintenance
{
    /**
     * Never longer than this, whatever the flag says.
     *
     * A deploy takes seconds. Fifteen minutes is generous enough that a slow
     * migration finishes inside it and short enough that a forgotten flag
     * clears itself before a Sunday morning.
     */
    public const MAX_SECONDS = 900;

    public static function flagPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/maintenance.flag';
    }

    /**
     * Whether requests should be held right now.
     *
     * @param string|null $path the flag file, for tests
     * @param int|null $now unix time, for tests
     */
    public static function isActive(?string $path = null, ?int $now = null): bool
    {
        $path ??= self::flagPath();
        if (!is_file($path)) {
            return false;
        }
        $now ??= time();
        $raw = @file_get_contents($path);
        if ($raw === false) {
            // Unreadable is not a reason to take the site down.
            return false;
        }

        $expires = self::expiryOf($raw, $path);

        return $expires !== null && $now < $expires;
    }

    /**
     * When the flag stops counting.
     *
     * The file holds a unix timestamp on its first line. Anything unparseable
     * falls back to the file's own mtime plus the ceiling, so a truncated or
     * half-written flag still expires rather than lasting forever.
     */
    private static function expiryOf(string $raw, string $path): ?int
    {
        $first = trim(strtok($raw, "\n") ?: '');
        $stamp = is_numeric($first) ? (int) $first : null;
        if ($stamp === null) {
            $mtime = @filemtime($path);
            $stamp = $mtime === false ? null : $mtime;
        }
        if ($stamp === null) {
            return null;
        }
        // The ceiling is applied to the stamp, not to whatever the file asked
        // for, so a flag cannot request an outage of arbitrary length.
        return $stamp + self::MAX_SECONDS;
    }

    /** The reason, if the flag carried one on its second line. */
    public static function reason(?string $path = null): string
    {
        $path ??= self::flagPath();
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return '';
        }
        $lines = explode("\n", $raw);

        return trim($lines[1] ?? '');
    }

    /**
     * Seconds a client should wait, for the Retry-After header.
     *
     * Bounded below by 5 so a client is never told to retry immediately, and
     * above by the ceiling so it is never told to wait longer than the flag
     * can possibly last.
     */
    public static function retryAfter(?string $path = null, ?int $now = null): int
    {
        $path ??= self::flagPath();
        $now ??= time();
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return 5;
        }
        $expires = self::expiryOf($raw, $path);
        if ($expires === null) {
            return 5;
        }

        return max(5, min(self::MAX_SECONDS, $expires - $now));
    }
}
