<?php

declare(strict_types=1);

/**
 * Smoke check: leader-facing ministry roster.
 *
 * Path:
 *   PortalAuthAdapter (mdb)        →  AuthService.login          → AuthSession
 *   PortalAuthAdapter (mdb)        →  AuthService.resolveActor   → ActorContext
 *   ChurchCrmMinistryAdapter (cc)  ←  MinistryService.getRoster
 *
 * Usage:
 *   php tools/smoke-ministry-roster.php <email> <password> <ministryId> [since] [campusId]
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

use App\Http\Controllers\Api\MinistryRosterController;
use App\Providers\PortalServiceProvider;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php tools/smoke-ministry-roster.php <email> <password> <ministryId> [since] [campusId]\n");
    exit(2);
}

[$email, $password, $ministryId] = [$argv[1], $argv[2], (int) $argv[3]];
$since    = $argv[4] ?? '-180 days';
$campusId = isset($argv[5]) ? (int) $argv[5] : null;

$auth     = PortalServiceProvider::makeAuthService();
$ministry = PortalServiceProvider::makeMinistryService();
$reqctx   = PortalServiceProvider::makeRequestContext();

echo "=== Login ===\n";
$session = $auth->login($email, $password, ipAddress: '127.0.0.1', userAgent: 'smoke-ministry-roster');
printf("  user=%d  primary_person=%s\n",
    $session->portalUserId,
    $session->primaryPersonId === null ? '(none)' : (string) $session->primaryPersonId,
);

echo "\n=== GET /api/ministry-roster (controller) ===\n";
$controller = new MinistryRosterController($ministry, $reqctx);
$req = [
    'session_token' => $session->sessionToken,
    'ministry_id'   => $ministryId,
    'since'         => $since,
];
if ($campusId !== null) {
    $req['campus_id'] = $campusId;
}

$result = $controller->index($req);

printf("  ministry=%d (%s)  campus=%s  since=%s  members=%d\n",
    $result['ministryId'],
    $result['ministryName'],
    $result['campusFilter'] === null ? 'all' : (string) $result['campusFilter'],
    $result['since'],
    $result['memberCount'],
);

foreach (array_slice($result['members'], 0, 15) as $m) {
    printf("    person=%-4d  %-26s  campus=%-3s  served=%-2d  last=%s  roles=%s\n",
        $m['personId'],
        mb_strimwidth($m['displayName'], 0, 26, '…'),
        $m['primaryCampusId'] === null ? 'n/a' : (string) $m['primaryCampusId'],
        $m['assignmentCount'],
        $m['lastServedAt'] === null ? '(never)' : substr($m['lastServedAt'], 0, 10),
        $m['rolesServed'] === '' ? '(none)' : $m['rolesServed'],
    );
}
if (count($result['members']) > 15) {
    printf("    ...and %d more\n", count($result['members']) - 15);
}

echo "\n=== Cleanup ===\n";
$auth->logout($session->sessionToken);
echo "  revoked.\n";
echo "\nOK\n";
