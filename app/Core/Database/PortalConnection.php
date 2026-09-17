<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config\EnvLoader;
use PDO;
use RuntimeException;

/**
 * PDO factory for the portal-owned database connection.
 *
 * Hosts portal-owned tables only: portal_users, portal_user_person_links,
 * portal_user_roles, portal_sessions, portal_audit_log, portal_notifications,
 * portal_availability, portal_preferences, etc.
 *
 * Default database name is u471078694_christlike_mdb (per project decision
 * 2026-05-01). Environment variables can override.
 *
 * In a full Laravel install this class is replaced by
 *   DB::connection('portal')->getPdo()
 * via the service container; nothing above the adapter layer changes.
 */
final class PortalConnection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host = EnvLoader::get('PORTAL_DB_HOST',     EnvLoader::get('DB_HOST', '127.0.0.1'));
        $port = EnvLoader::get('PORTAL_DB_PORT',     EnvLoader::get('DB_PORT', '3306'));
        $name = EnvLoader::get('PORTAL_DB_DATABASE', 'u471078694_christlike_mdb');
        $user = EnvLoader::get('PORTAL_DB_USERNAME', EnvLoader::get('DB_USERNAME'));
        $pass = EnvLoader::get('PORTAL_DB_PASSWORD', EnvLoader::get('DB_PASSWORD'));

        if ($user === null || $user === '') {
            throw new RuntimeException(
                'Portal DB connection not configured. Set PORTAL_DB_USERNAME and '
              . 'PORTAL_DB_PASSWORD (or DB_* fallbacks) in the portal .env file. '
              . 'PORTAL_DB_DATABASE defaults to u471078694_christlike_mdb.'
            );
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        self::$instance = $pdo;
        return $pdo;
    }

    public static function setInstance(?PDO $pdo): void
    {
        self::$instance = $pdo;
    }
}
