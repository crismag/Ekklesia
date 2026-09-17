<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Config\EnvLoader;
use PDO;
use RuntimeException;

/**
 * PDO factory for the member database (database/members/001_schema.sql):
 * people, households, ministries, the calendar and serving schedule, and
 * logins. The one MySQL database the portal uses.
 *
 * Connection options: throw on error, associative rows, real prepared
 * statements, utf8mb4.
 */
final class MembersConnection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host = EnvLoader::get('MEMBERS_DB_HOST', '127.0.0.1');
        $port = EnvLoader::get('MEMBERS_DB_PORT', '3306');
        $name = EnvLoader::get('MEMBERS_DB_DATABASE');
        $user = EnvLoader::get('MEMBERS_DB_USERNAME');
        $pass = EnvLoader::get('MEMBERS_DB_PASSWORD');

        if ($name === null || $name === '' || $user === null || $user === '') {
            throw new RuntimeException(
                'Member database not configured. Set MEMBERS_DB_DATABASE, MEMBERS_DB_USERNAME '
              . 'and MEMBERS_DB_PASSWORD in the portal .env file.'
            );
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        self::$instance = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$instance;
    }

    /** Tests and CLI tools may inject a PDO directly. */
    public static function setInstance(?PDO $pdo): void
    {
        self::$instance = $pdo;
    }
}
