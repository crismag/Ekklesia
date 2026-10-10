<?php

declare(strict_types=1);

/**
 * Smoke check: end-to-end read of a ScheduleGrid from the member database.
 *
 * Wires the full chain by hand using PortalServiceProvider::makeScheduleService()
 * and prints the grid. No writes. No auth — uses a hardcoded ActorContext with
 * full permissions for the requested ministry.
 *
 * Usage:
 *   MEMBERS_DB_DATABASE=... MEMBERS_DB_USERNAME=... MEMBERS_DB_PASSWORD=... \
 *   php tools/smoke-grid.php [ministryId=4] [start=YYYY-MM-DD] [end=YYYY-MM-DD]
 *
 * Falls back to .env in the project root if env vars aren't already set.
 */

// Standalone autoload (no composer install required for the scaffold)
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

use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Providers\PortalServiceProvider;

$ministryId = (int) ($argv[1] ?? 4);                              // default = Victuals
$start      = new DateTimeImmutable($argv[2] ?? '2026-04-01');
$end        = new DateTimeImmutable($argv[3] ?? '2026-12-31');

// Hardcoded full-permission context (real auth lands in step B).
$context = new ActorContext(
    actorId: 0,
    personId: null,
    displayName: null,
    permissions: [
        PortalPermission::ManageSchedules,
        PortalPermission::ViewMinistrySchedule,
    ],
    ministryScopeIds: [$ministryId],
);

$service = PortalServiceProvider::makeScheduleService();
$grid = $service->getScheduleGrid($context, $ministryId, $start, $end);

echo "=== ScheduleGrid (ministry $ministryId, {$start->format('Y-m-d')} → {$end->format('Y-m-d')}) ===\n";
echo "viewMode: {$grid->viewMode}\n";
echo "\n-- Roles ({" . count($grid->roles) . "}) --\n";
foreach ($grid->roles as $role) {
    printf("  %4d  %s\n", $role->id, $role->name);
}
echo "\n-- Members ({" . count($grid->people) . "}) --\n";
foreach ($grid->people as $person) {
    printf("  %4d  %s\n", $person->id, $person->displayName);
}
echo "\n-- Assignments ({" . count($grid->assignments) . "}) --\n";
foreach ($grid->assignments as $a) {
    printf(
        "  #%-4s  role=%-3d  person=%-4s  starts=%s  %s\n",
        $a->id ?? '-',
        $a->roleId,
        $a->personId === 0 ? 'ext' : (string) $a->personId,
        $a->startsOn->format('Y-m-d H:i'),
        $a->label !== '' ? "[$a->label]" : ''
    );
}
if (!empty($grid->warnings)) {
    echo "\n-- Warnings --\n";
    foreach ($grid->warnings as $w) {
        echo "  ! $w\n";
    }
}
echo "\nOK\n";
