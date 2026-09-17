<?php
/**
 * Standalone DB helper for the People Sign-Up module.
 * Connects to the shared ChurchCRM database (holds person_per + the module's
 * own people_signup_temp table). No dependency on portal includes.
 */
declare(strict_types=1);

if (!function_exists('signup_secure')) {
    function signup_secure(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require __DIR__ . '/../config/signup.secure.php';
        }
        return is_array($cfg) ? $cfg : [];
    }
}

if (!function_exists('signup_db')) {
    function signup_db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        $d = signup_secure()['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $d['host'] ?? '127.0.0.1',
            $d['port'] ?? '3306',
            $d['name'] ?? '',
            $d['charset'] ?? 'utf8mb4',
        );
        $pdo = new PDO($dsn, $d['user'] ?? '', $d['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    }
}
