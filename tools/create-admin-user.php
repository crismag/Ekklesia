<?php

declare(strict_types=1);

/**
 * Bootstrap a portal admin user.
 *
 * Usage:
 *   PORTAL_DB_USERNAME=...  PORTAL_DB_PASSWORD=...   \
 *   php tools/create-admin-user.php <email> <password> [<displayName>] [<personId>]
 *
 * Creates the user, assigns the 'admin' role (no scope = portal-wide admin),
 * and optionally links to a ChurchCRM person id.
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

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/create-admin-user.php <email> <password> [<displayName>] [<personId>]\n");
    exit(2);
}

$email       = $argv[1];
$password    = $argv[2];
$displayName = $argv[3] ?? null;
$personId    = isset($argv[4]) ? (int) $argv[4] : null;

$auth = PortalServiceProvider::makeAuthService();

$portalUserId = $auth->createUser($email, $password, $displayName);
$auth->assignRole($portalUserId, 'admin');
if ($personId !== null && $personId > 0) {
    $auth->linkPerson($portalUserId, $personId, isPrimary: true);
}

echo "OK\n";
echo "  portal_user_id : $portalUserId\n";
echo "  email          : $email\n";
echo "  role           : admin (portal-wide)\n";
if ($personId !== null && $personId > 0) {
    echo "  linked person  : $personId (primary)\n";
}
