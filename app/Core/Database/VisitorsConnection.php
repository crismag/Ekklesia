<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config\EnvLoader;
use PDO;
use RuntimeException;

/**
 * PDO factory for the visitors database (database/visitors/001_schema.sql):
 * guest registrations, RSVPs and their promotion to members. SQLite, kept
 * apart from the member database because anyone can write to it.
 */
final class VisitorsConnection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $root = dirname(__DIR__, 3);
        $path = EnvLoader::get('VISITORS_DB_PATH', 'storage/private/database/visitors.sqlite');
        if (!str_starts_with((string) $path, '/')) {
            $path = $root . '/' . $path;
        }
        if (!is_file($path)) {
            throw new RuntimeException(
                "Visitors database not found at {$path}. Build it with "
              . 'php database/migrate/visitors_from_legacy.php, or create it from database/visitors/001_schema.sql.'
            );
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return self::$instance = $pdo;
    }

    public static function setInstance(?PDO $pdo): void
    {
        self::$instance = $pdo;
    }
}
