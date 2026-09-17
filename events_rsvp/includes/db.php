<?php
/**
 * Standalone database helpers for the Events RSVP module. No dependency on portal
 * includes.
 *
 * Two databases:
 *  - visitors (SQLite, read/write): visitor_registrations, visitor_rsvps,
 *    visitor_promotions, visitor_admin_access_codes.
 *  - members (MySQL): people, events, event_occurrences, member_types. Read
 *    for matching and event lookup; written only when a registration is
 *    promoted to a member.
 * The two cannot share a transaction, so callers that write both order the
 * writes themselves.
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
    /** The visitors database (SQLite). */
    function rsvp_db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        $path = (string) (rsvp_secure()['visitors_db_path'] ?? 'storage/private/database/visitors.sqlite');
        if (!str_starts_with($path, '/')) {
            $path = dirname(__DIR__, 2) . '/' . $path;
        }
        if (!is_file($path)) {
            throw new RuntimeException('Visitors database not found at ' . $path);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }
}

if (!function_exists('rsvp_members_db')) {
    /** The member database (MySQL). */
    function rsvp_members_db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        $d = rsvp_secure()['members_db'] ?? [];
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
