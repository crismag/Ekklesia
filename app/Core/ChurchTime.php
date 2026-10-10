<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * The church's time zone, and the one rule built on it: every date and time
 * Ekklesia stores or shows is the church's local time.
 *
 * Event times are entered as wall-clock times ("Sunday 10:00") and stored
 * without a zone, so everything that reads or compares them must agree on
 * whose clock that is. It is the church's, set once under Administration →
 * Church information (`timeZone` in config/church-info.json):
 *
 *   - PHP: apply() makes it the default zone, so "now", date() and the
 *     offsets sent to browsers are the church's;
 *   - MySQL: applyTo() makes it the connection's zone, so NOW(), CURDATE()
 *     and DEFAULT CURRENT_TIMESTAMP are the church's;
 *   - browsers: script() hands it to the page, so a viewer anywhere sees the
 *     church's times and dates (shared/church-time.js).
 *
 * Nothing depends on the server's own time zone, so an installation works
 * anywhere once its zone is set.
 */
final class ChurchTime
{
    public const DEFAULT_ZONE = 'America/Toronto';

    private static ?string $zone = null;

    /** The church's IANA time zone, e.g. "America/Toronto". */
    public static function zone(): string
    {
        return self::$zone ??= self::readZone(dirname(__DIR__, 2) . '/config/church-info.json');
    }

    /** True when $zone names a time zone PHP knows. */
    public static function isValidZone(string $zone): bool
    {
        if ($zone === '' || !in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            return $zone === 'UTC';
        }

        return true;
    }

    /** Make the church's zone PHP's default for this process. */
    public static function apply(): void
    {
        date_default_timezone_set(self::zone());
    }

    /**
     * Make the church's zone the connection's zone. A named zone needs the
     * server's time-zone tables, which shared hosts often lack, so the
     * current UTC offset is the fallback; a connection lives for one request,
     * well inside one offset.
     */
    public static function applyTo(PDO $pdo): void
    {
        try {
            $pdo->exec('SET time_zone = ' . $pdo->quote(self::zone()));
            return;
        } catch (Throwable) {
            // Named zones unavailable on this server; use the offset.
        }
        $pdo->exec('SET time_zone = ' . $pdo->quote(self::offset()));
    }

    /** The church's current UTC offset, e.g. "-04:00". */
    public static function offset(?DateTimeImmutable $at = null): string
    {
        return ($at ?? new DateTimeImmutable('now', new DateTimeZone(self::zone())))
            ->setTimezone(new DateTimeZone(self::zone()))
            ->format('P');
    }

    /**
     * The inline script that gives a page the church's zone and the date
     * helpers in shared/church-time.js (window.EkklesiaTime).
     */
    public static function script(): string
    {
        static $source = null;
        $source ??= (string) file_get_contents(dirname(__DIR__, 2) . '/shared/church-time.js');
        $zone = json_encode(self::zone(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        return '<script>' . str_replace('__CHURCH_TIME_ZONE__', (string) $zone, $source) . '</script>';
    }

    /** For tests: use $zone instead of the configured one; null to re-read. */
    public static function override(?string $zone): void
    {
        self::$zone = $zone;
    }

    private static function readZone(string $path): string
    {
        $raw = is_file($path) ? (string) file_get_contents($path) : '';
        $data = $raw !== '' ? json_decode($raw, true) : null;
        $zone = is_array($data) ? trim((string) ($data['timeZone'] ?? '')) : '';

        return self::isValidZone($zone) ? $zone : self::DEFAULT_ZONE;
    }
}
