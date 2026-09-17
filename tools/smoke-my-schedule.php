<?php

declare(strict_types=1);

/**
 * Smoke check: member-facing "My Schedule" view.
 *
 * Proves the path:
 *   SqlAuthAdapter (mdb)           →  AuthService.login          → AuthSession
 *   SqlAuthAdapter (mdb)           →  AuthService.resolveActor   → ActorContext (with personId)
 *   ChurchCrmScheduleAdapter (cc)  ←  ScheduleService.getMySchedule
 *
 * Usage:
 *   PORTAL_DB_USERNAME=...    PORTAL_DB_PASSWORD=...    \
 *   CHURCHCRM_DB_USERNAME=... CHURCHCRM_DB_PASSWORD=... \
 *   php tools/smoke-my-schedule.php <email> <password> [start] [end]
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

use App\Http\Controllers\Api\MyScheduleController;
use App\Providers\PortalServiceProvider;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/smoke-my-schedule.php <email> <password> [start] [end]\n");
    exit(2);
}

[$email, $password] = [$argv[1], $argv[2]];
$startStr = $argv[3] ?? date('Y-m-d');
$endStr   = $argv[4] ?? date('Y-m-d', strtotime('+90 days'));

$auth     = PortalServiceProvider::makeAuthService();
$schedule = PortalServiceProvider::makeScheduleService();
$reqctx   = PortalServiceProvider::makeRequestContext();

echo "=== Login ===\n";
$session = $auth->login($email, $password, ipAddress: '127.0.0.1', userAgent: 'smoke-my-schedule');
printf("  user=%d  primary_person=%s  token=%s…\n",
    $session->accountId,
    $session->personId === null ? '(none)' : (string) $session->personId,
    substr($session->sessionToken, 0, 12),
);

if ($session->personId === null) {
    fwrite(STDERR, "FAIL: portal user has no linked person record — cannot resolve a personal schedule.\n");
    $auth->logout($session->sessionToken);
    exit(1);
}

echo "\n=== GET /api/my-schedule (controller) ===\n";
$controller = new MyScheduleController($schedule, $reqctx);
$result = $controller->index([
    'session_token' => $session->sessionToken,
    'start' => $startStr,
    'end'   => $endStr,
]);

printf("  person=%d  range=%s..%s  assignments=%d\n",
    $result['personId'],
    $result['start'],
    $result['end'],
    count($result['assignments']),
);

foreach (array_slice($result['assignments'], 0, 10) as $row) {
    printf("    [%s]  %s  ministry=%s  role=%s  event=%s  status=%s\n",
        substr($row['startsOn'], 0, 16),
        '#' . $row['id'],
        $row['ministryName'] !== '' ? $row['ministryName'] : ('grp' . $row['ministryId']),
        $row['roleName'],
        $row['eventTitle'] !== '' ? $row['eventTitle'] : ('event' . $row['eventId']),
        $row['status'],
    );
}

echo "\n=== Cleanup: revoke session ===\n";
$auth->logout($session->sessionToken);
echo "  revoked.\n";

echo "\nOK\n";
