<?php

declare(strict_types=1);

/**
 * End-to-end smoke: portal auth → resolved ActorContext → schedule grid.
 *
 * Proves the full chain works:
 *   SqlAuthAdapter (mdb DB)     →  AuthService.login           → AuthSession
 *   SqlAuthAdapter (mdb DB)     →  AuthService.resolveActor    → ActorContext
 *   PortalRequestContext        →  optional currentCampusId override
 *   SqlScheduleAdapter (member DB) ← ScheduleService.getScheduleGrid
 *
 * Usage:
 *   MEMBERS_DB_USERNAME=... MEMBERS_DB_PASSWORD=... \
 *   php tools/smoke-end-to-end.php <email> <password> <ministryId> <start> <end>
 *
 * Example:
 *   php tools/smoke-end-to-end.php admin@christlike.local PortalAdmin#... 4 2026-04-01 2026-05-31
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

use App\Http\Requests\PortalRequestContext;
use App\Providers\PortalServiceProvider;

if ($argc < 6) {
    fwrite(STDERR, "Usage: php tools/smoke-end-to-end.php <email> <password> <ministryId> <start> <end>\n");
    exit(2);
}

[$email, $password, $ministryId, $startStr, $endStr] = [$argv[1], $argv[2], (int) $argv[3], $argv[4], $argv[5]];

$auth     = PortalServiceProvider::makeAuthService();
$schedule = PortalServiceProvider::makeScheduleService();
$reqctx   = new PortalRequestContext($auth);

echo "=== Login ===\n";
$session = $auth->login($email, $password, ipAddress: '127.0.0.1', userAgent: 'smoke-end-to-end');
printf("  user=%d  primary_person=%s  token=%s…\n",
    $session->accountId,
    $session->personId === null ? '(none)' : (string) $session->personId,
    substr($session->sessionToken, 0, 12),
);

echo "\n=== Resolve actor via PortalRequestContext (HTTP-style) ===\n";
$actor = $reqctx->fromArray([
    'session_token'     => $session->sessionToken,
    'current_campus_id' => null,   // top-bar selector unset
]);
printf("  actor_id=%d  is_portal_admin=%s  permissions=%d\n",
    $actor->actorId,
    $actor->isPortalWideAdmin ? 'YES' : 'no',
    count($actor->permissions),
);
printf("  ministry_scope=[%s]  campus_scope=[%s]  current_campus=%s\n",
    implode(',', array_map('strval', $actor->ministryScopeIds)),
    implode(',', array_map('strval', $actor->campusScopeIds)),
    $actor->currentCampusId === null ? '(none)' : (string) $actor->currentCampusId,
);

echo "\n=== Fetch schedule grid via the resolved actor ===\n";
$grid = $schedule->getScheduleGrid(
    context: $actor,
    ministryId: $ministryId,
    start: new DateTimeImmutable($startStr),
    end:   new DateTimeImmutable($endStr),
);
printf("  ministry=%d  range=%s..%s  view=%s\n",
    $grid->ministryId,
    $grid->start->format('Y-m-d'),
    $grid->end->format('Y-m-d'),
    $grid->viewMode,
);
printf("  roles=%d  members=%d  assignments=%d\n",
    count($grid->roles),
    count($grid->people),
    count($grid->assignments),
);
foreach (array_slice($grid->assignments, 0, 5) as $a) {
    printf("    #%-3s role=%-2d person=%-4s starts=%s\n",
        $a->id ?? '-', $a->roleId, $a->personId === 0 ? 'ext' : (string) $a->personId, $a->startsOn->format('Y-m-d H:i'));
}

echo "\n=== Cleanup: revoke session ===\n";
$auth->logout($session->sessionToken);
echo "  revoked.\n";

echo "\nOK\n";
