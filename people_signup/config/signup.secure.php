<?php
/**
 * Sensitive config (database settings, admin token). NOT stored in JSON.
 *
 * Standalone-friendly: prefers explicit environment variables, then falls back
 * to parsing the portal .env (../../.env) so it works in-place, else the
 * placeholder defaults below. For a truly standalone install, set the env vars
 * or replace the fallbacks — do NOT commit real credentials.
 */
declare(strict_types=1);

return (static function (): array {
    $envFile = __DIR__ . '/../../.env';
    static $parsed = null;
    $get = static function (string $key, ?string $default = null) use ($envFile, &$parsed): ?string {
        $v = getenv($key);
        if ($v !== false && $v !== '') { return $v; }
        if ($parsed === null) {
            $parsed = [];
            if (is_readable($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
                    [$n, $val] = explode('=', $line, 2);
                    $val = trim($val);
                    if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
                        $val = substr($val, 1, -1);
                    }
                    $parsed[trim($n)] = $val;
                }
            }
        }
        return $parsed[$key] ?? $default;
    };

    return [
        // Member database (MySQL): people, events, member_types. Read for
        // matching and event lookup; written only when a registration is promoted.
        'members_db' => [
            'host'    => $get('MEMBERS_DB_HOST', $get('DB_HOST', '127.0.0.1')),
            'port'    => $get('MEMBERS_DB_PORT', $get('DB_PORT', '3306')),
            'name'    => $get('MEMBERS_DB_DATABASE', $get('DB_DATABASE', '')),
            'user'    => $get('MEMBERS_DB_USERNAME', $get('DB_USERNAME', '')),
            'pass'    => $get('MEMBERS_DB_PASSWORD', $get('DB_PASSWORD', '')),
            'charset' => 'utf8mb4',
        ],
        // Visitors database (SQLite): registrations, RSVPs, promotions, access
        // codes. Relative paths are resolved from the Ekklesia root.
        'visitors_db_path' => $get('VISITORS_DB_PATH', 'storage/private/database/visitors.sqlite'),
        // Gate for the admin review pages (?token=...). Override via SIGNUP_ADMIN_TOKEN.
        'admin_token' => $get('SIGNUP_ADMIN_TOKEN', 'change-me-people_signup-admin'),
    ];
})();
