<?php

declare(strict_types=1);

/**
 * Smoke check: portal auth round-trip.
 *
 * Usage:
 *   PORTAL_DB_USERNAME=...  PORTAL_DB_PASSWORD=...   \
 *   php tools/smoke-auth.php <email> <password>
 *
 * Steps:
 *   1. Login (email + password) → AuthSession (carries token + expiry)
 *   2. Resolve session token → ActorContext (real permissions + scope)
 *   3. Logout (revoke token)
 *   4. Re-resolve same token → expect PermissionDenied
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

use App\Exceptions\PermissionDenied;
use App\Providers\PortalServiceProvider;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/smoke-auth.php <email> <password>\n");
    exit(2);
}

$email    = $argv[1];
$password = $argv[2];

$auth = PortalServiceProvider::makeAuthService();

echo "=== Step 1: login ===\n";
$session = $auth->login($email, $password, ipAddress: '127.0.0.1', userAgent: 'smoke-auth');
printf("  portal_user_id   : %d\n", $session->portalUserId);
printf("  email            : %s\n", $session->email);
printf("  display_name     : %s\n", $session->displayName ?? '(none)');
printf("  primary_person   : %s\n", $session->primaryPersonId === null ? '(unlinked)' : (string) $session->primaryPersonId);
printf("  person_links     : [%s]\n", implode(', ', array_map('strval', $session->personLinks)));
printf("  session_token    : %s…\n", substr($session->sessionToken, 0, 12));
printf("  expires_at       : %s\n", $session->expiresAt->format('Y-m-d H:i:s'));

echo "\n=== Step 2: resolveActor(token) ===\n";
$actor = $auth->resolveActor($session->sessionToken);
printf("  actor_id         : %d\n", $actor->actorId);
printf("  person_id        : %s\n", $actor->personId === null ? '(none)' : (string) $actor->personId);
printf("  is_portal_admin  : %s\n", $actor->isPortalWideAdmin ? 'YES (bypasses scope checks)' : 'no');
printf("  permissions      : [%s]\n", implode(', ', array_map(fn ($p) => $p->value, $actor->permissions)));
printf("  ministry_scope   : [%s]\n", implode(', ', array_map('strval', $actor->ministryScopeIds)));
printf("  campus_scope     : [%s]\n", implode(', ', array_map('strval', $actor->campusScopeIds)));
printf("  current_campus   : %s\n", $actor->currentCampusId === null ? '(none)' : (string) $actor->currentCampusId);

echo "\n=== Step 3: logout ===\n";
$auth->logout($session->sessionToken);
echo "  revoked.\n";

echo "\n=== Step 4: re-resolve revoked token (expect PermissionDenied) ===\n";
try {
    $auth->resolveActor($session->sessionToken);
    fwrite(STDERR, "FAIL: expected PermissionDenied but resolveActor succeeded.\n");
    exit(1);
} catch (PermissionDenied $e) {
    printf("  ✓ rejected: %s\n", $e->getMessage());
}

echo "\nOK\n";
