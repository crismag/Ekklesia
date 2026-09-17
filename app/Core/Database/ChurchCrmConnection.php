<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config\EnvLoader;
use PDO;
use RuntimeException;

/**
 * PDO factory for the ChurchCRM-source database connection.
 *
 * Single source of connection truth for the scaffold. Reads from environment
 * (which `EnvLoader` populates from .env when not running under Laravel).
 * In a full Laravel install this class is replaced by `DB::connection(...)`
 * via the service container; nothing above the adapter layer changes.
 *
 * Connection options are deliberately conservative:
 *   - PDO::ATTR_ERRMODE          → throw on error (forces explicit handling)
 *   - PDO::ATTR_DEFAULT_FETCH_MODE → ASSOC (no numeric duplicates)
 *   - PDO::ATTR_EMULATE_PREPARES  → false (real prepared statements)
 *   - charset utf8mb4
 */
final class ChurchCrmConnection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host = EnvLoader::get('CHURCHCRM_DB_HOST',     EnvLoader::get('DB_HOST', '127.0.0.1'));
        $port = EnvLoader::get('CHURCHCRM_DB_PORT',     EnvLoader::get('DB_PORT', '3306'));
        $name = EnvLoader::get('CHURCHCRM_DB_DATABASE', EnvLoader::get('DB_DATABASE'));
        $user = EnvLoader::get('CHURCHCRM_DB_USERNAME', EnvLoader::get('DB_USERNAME'));
        $pass = EnvLoader::get('CHURCHCRM_DB_PASSWORD', EnvLoader::get('DB_PASSWORD'));

        if ($name === null || $name === '' || $user === null || $user === '') {
            throw new RuntimeException(
                'ChurchCRM DB connection not configured. Set CHURCHCRM_DB_DATABASE / '
              . 'CHURCHCRM_DB_USERNAME / CHURCHCRM_DB_PASSWORD (or DB_* fallbacks) '
              . 'in the portal .env file.'
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

    /**
     * Tests/CLI may inject a PDO directly (e.g. an in-memory driver or an
     * already-built connection from Laravel's container).
     */
    public static function setInstance(?PDO $pdo): void
    {
        self::$instance = $pdo;
    }
}
