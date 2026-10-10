<?php

declare(strict_types=1);

/**
 * Bootstrap a portal admin user.
 *
 * Usage:
 *   MEMBERS_DB_USERNAME=... MEMBERS_DB_PASSWORD=...   \
 *   php tools/create-admin-user.php <email> <password> [<displayName>] [<personId>]
 *
 * Creates the user, assigns the 'admin' role (no scope = portal-wide admin),
 * and optionally links to a person id.
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

$accountId = $auth->createUser($email, $password, $displayName);
$auth->assignRole($accountId, 'admin');
if ($personId !== null && $personId > 0) {
    $auth->linkPerson($accountId, $personId);
}

echo "OK\n";
echo "  account_id     : $accountId\n";
echo "  email          : $email\n";
echo "  role           : admin (portal-wide)\n";
if ($personId !== null && $personId > 0) {
    echo "  linked person  : $personId\n";
}
