<?php

declare(strict_types=1);

/**
 * Reset a portal user's password (admin CLI).
 *
 * Usage:
 *   php tools/reset-portal-password.php <portal_user_id> <new_password>
 *
 * This script hashes the supplied password with the application's PasswordHasher
 * and writes it into the portal_users row. It also sets the must_change_password
 * flag so the user is prompted to change the password on next sign-in.
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

use App\Core\Config\EnvLoader;
use App\Core\Database\MembersConnection;
use App\Adapters\Portal\PortalAuthAdapter;
use App\Repositories\DefaultAuthRepository;
use App\Core\Security\PasswordHasher;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/reset-portal-password.php <portal_user_id> <new_password>\n");
    exit(2);
}

$portalUserId = (int) $argv[1];
$newPassword = (string) $argv[2];

if ($portalUserId <= 0) {
    fwrite(STDERR, "Invalid portal_user_id.\n");
    exit(2);
}
if (strlen($newPassword) < 10) {
    fwrite(STDERR, "New password must be at least 10 characters.\n");
    exit(2);
}

EnvLoader::loadOnce(dirname(__DIR__) . '/.env');
$pdo = MembersConnection::get();
if ($pdo === null) {
    fwrite(STDERR, "Failed to obtain portal DB connection. Check .env and DB connectivity.\n");
    exit(3);
}

$adapter = new PortalAuthAdapter($pdo);
$repo = new DefaultAuthRepository($adapter);
$hasher = new PasswordHasher();

$hash = $hasher->hash($newPassword);
try {
    $repo->updatePasswordHash($portalUserId, $hash);
    // Force the user to change the password at next login.
    $repo->setMustChangePassword($portalUserId, true);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to update password: " . $e->getMessage() . "\n");
    exit(4);
}

echo "OK: password reset for portal_user_id={$portalUserId}\n";
echo "The account is flagged to require a password change on next login.\n";
