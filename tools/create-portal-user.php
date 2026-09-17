<?php

declare(strict_types=1);

/**
 * Bootstrap a portal user with an arbitrary role.
 *
 * Usage:
 *   php tools/create-portal-user.php <email> <password> <role> [displayName] [personId] [scopeMinistryId] [scopeCampusId]
 *
 * Roles: admin | leader | scheduler | member
 *   - admin (no scope) is portal-wide.
 *   - leader/scheduler may be scoped to a ministry, a campus, or both.
 *   - member is unscoped (only sees own assignments).
 *
 * Pass `0` for any optional positional argument you want to skip while still
 * setting one further to the right.
 *
 * Reads DB credentials from .env (PORTAL_DB_*).
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Providers\PortalServiceProvider;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php tools/create-portal-user.php <email> <password> <role> [displayName] [personId] [scopeMinistryId] [scopeCampusId]\n");
    fwrite(STDERR, "  role: admin | leader | scheduler | member\n");
    exit(2);
}

[$email, $password, $role] = [$argv[1], $argv[2], $argv[3]];
$displayName     = ($argv[4] ?? '') !== '' ? $argv[4] : null;
$personId        = isset($argv[5]) ? (int) $argv[5] : 0;
$scopeMinistryId = isset($argv[6]) ? (int) $argv[6] : 0;
$scopeCampusId   = isset($argv[7]) ? (int) $argv[7] : 0;

if (!in_array($role, ['admin', 'leader', 'scheduler', 'member'], true)) {
    fwrite(STDERR, "Unknown role '$role'. Use admin | leader | scheduler | member.\n");
    exit(2);
}

$auth = PortalServiceProvider::makeAuthService();

$portalUserId = $auth->createUser($email, $password, $displayName);
$auth->assignRole(
    $portalUserId,
    $role,
    scopeCampusId:   $scopeCampusId   > 0 ? $scopeCampusId   : null,
    scopeMinistryId: $scopeMinistryId > 0 ? $scopeMinistryId : null,
);
if ($personId > 0) {
    $auth->linkPerson($portalUserId, $personId, isPrimary: true);
}

echo "OK\n";
echo "  portal_user_id : $portalUserId\n";
echo "  email          : $email\n";
echo "  role           : $role" . ($role === 'admin' && $scopeMinistryId === 0 && $scopeCampusId === 0 ? ' (portal-wide)' : '') . "\n";
if ($scopeMinistryId > 0) {
    echo "  scope ministry : $scopeMinistryId\n";
}
if ($scopeCampusId > 0) {
    echo "  scope campus   : $scopeCampusId\n";
}
if ($personId > 0) {
    echo "  linked person  : $personId (primary)\n";
}
