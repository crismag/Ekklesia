<?php

declare(strict_types=1);

/**
 * Smoke check: member-facing availability CRUD.
 *
 * Path:
 *   SqlAuthAdapter (mdb)         →  AuthService.login          → AuthSession
 *   SqlAuthAdapter (mdb)         →  AuthService.resolveActor   → ActorContext
 *   SqlAvailabilityAdapter       ←  AvailabilityService.create / list / delete
 *
 * Usage:
 *   php tools/smoke-availability.php <email> <password>
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

use App\Http\Controllers\Api\AvailabilityController;
use App\Providers\PortalServiceProvider;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/smoke-availability.php <email> <password>\n");
    exit(2);
}

[$email, $password] = [$argv[1], $argv[2]];

$auth         = PortalServiceProvider::makeAuthService();
$availability = PortalServiceProvider::makeAvailabilityService();
$reqctx       = PortalServiceProvider::makeRequestContext();

$controller = new AvailabilityController($availability, $reqctx);

echo "=== Login ===\n";
$session = $auth->login($email, $password, ipAddress: '127.0.0.1', userAgent: 'smoke-availability');
printf("  user=%d  primary_person=%s\n",
    $session->accountId,
    $session->personId === null ? '(none)' : (string) $session->personId,
);

if ($session->personId === null) {
    fwrite(STDERR, "FAIL: portal user has no person link\n");
    $auth->logout($session->sessionToken);
    exit(1);
}

$reqBase = ['session_token' => $session->sessionToken];

echo "\n=== POST /api/availability (create) ===\n";
$createdA = $controller->create($reqBase + [
    'starts_on' => '2026-06-01',
    'ends_on'   => '2026-06-08',
    'reason'    => 'Family vacation',
]);
printf("  created id=%d  %s..%s  reason=%s\n",
    $createdA['id'], $createdA['startsOn'], $createdA['endsOn'], $createdA['reason']);

$createdB = $controller->create($reqBase + [
    'starts_on' => '2026-07-15',
    'ends_on'   => '2026-07-20',
]);
printf("  created id=%d  %s..%s  reason=%s\n",
    $createdB['id'], $createdB['startsOn'], $createdB['endsOn'], $createdB['reason'] ?? '(none)');

echo "\n=== GET /api/availability (list mine, active forward) ===\n";
$today = (new DateTimeImmutable())->format('Y-m-d');
$list = $controller->index($reqBase + ['active_from' => $today]);
printf("  person=%d  active_from=%s  entries=%d\n",
    $list['personId'], $list['activeFrom'], count($list['entries']));
foreach ($list['entries'] as $e) {
    printf("    #%-3d  %s..%s  %s\n",
        $e['id'], $e['startsOn'], $e['endsOn'], $e['reason'] ?? '(no reason)');
}

echo "\n=== DELETE /api/availability (remove first) ===\n";
$delResult = $controller->delete($reqBase + ['id' => $createdA['id']]);
printf("  deleted id=%d  ok=%s\n", $delResult['id'], $delResult['ok'] ? 'yes' : 'no');

echo "\n=== GET /api/availability (after delete) ===\n";
$listAfter = $controller->index($reqBase + ['active_from' => $today]);
printf("  remaining entries=%d\n", count($listAfter['entries']));

echo "\n=== Cleanup remaining test rows ===\n";
$controller->delete($reqBase + ['id' => $createdB['id']]);
echo "  cleaned.\n";

$auth->logout($session->sessionToken);
echo "\nOK\n";
