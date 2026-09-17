<?php
/**
 * Standalone DB helper for the Events RSVP module.
 * Connects to the shared ChurchCRM database (person_per + events_event and the
 * module's own rsvp_* tables). No dependency on portal includes.
 */
declare(strict_types=1);

if (!function_exists('rsvp_secure')) {
    function rsvp_secure(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require __DIR__ . '/../config/rsvp.secure.php';
        }
        return is_array($cfg) ? $cfg : [];
    }
}

if (!function_exists('rsvp_db')) {
    function rsvp_db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        $d = rsvp_secure()['db'] ?? [];
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
