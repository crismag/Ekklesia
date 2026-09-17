<?php

declare(strict_types=1);

/**
 * Smoke check: scheduler grid edit (read → diff → save → re-read).
 *
 * Steps:
 *   1. Load grid (roles, members, occurrences, assignments).
 *   2. Fabricate a save batch:
 *        - Drop the first existing assignment.
 *        - Add a new assignment to the first empty (role × occurrence) cell.
 *   3. POST /api/schedules/assignments.
 *   4. Reload grid and verify the deltas applied.
 *   5. Restore the original state (best-effort cleanup).
 *
 * Usage:
 *   php tools/smoke-schedule-edit.php <email> <password> <ministryId> <start> <end>
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

use App\Http\Controllers\Api\ScheduleController;
use App\Providers\PortalServiceProvider;

if ($argc < 6) {
    fwrite(STDERR, "Usage: php tools/smoke-schedule-edit.php <email> <password> <ministryId> <start> <end>\n");
    exit(2);
}

[$email, $password, $ministryId, $startStr, $endStr] = [$argv[1], $argv[2], (int) $argv[3], $argv[4], $argv[5]];

$auth     = PortalServiceProvider::makeAuthService();
$schedule = PortalServiceProvider::makeScheduleService();
$reqctx   = PortalServiceProvider::makeRequestContext();
$controller = new ScheduleController($schedule, $reqctx);

$session = $auth->login($email, $password, ipAddress: '127.0.0.1', userAgent: 'smoke-schedule-edit');
$req = ['session_token' => $session->sessionToken];

echo "=== 1. Load grid ===\n";
$grid = $controller->grid($req + ['ministry_id' => $ministryId, 'start' => $startStr, 'end' => $endStr]);
printf("  ministry=%d  occurrences=%d  roles=%d  members=%d  assignments=%d\n",
    $grid['ministryId'], count($grid['occurrences']), count($grid['roles']),
    count($grid['people']), count($grid['assignments']));

if (count($grid['occurrences']) === 0 || count($grid['roles']) === 0) {
    fwrite(STDERR, "Need at least one occurrence and one role in the window for this smoke.\n");
    $auth->logout($session->sessionToken);
    exit(1);
}

// Build the desired-state batch by preserving ALL existing rows (id-keyed,
// not (occ,role)-keyed — the "extra assignees" pattern allows duplicates per
// cell, and we need to keep every row distinct).
$desired = [];
foreach ($grid['assignments'] as $a) {
    $desired[] = $a;
}

// Edit 1: drop the first existing assignment (if any).
$dropped = null;
if (!empty($desired)) {
    $first = $desired[0];
    $dropped = $first;
    array_shift($desired);
    printf("  edit: drop assignment id=%d (occ=%d, role=%d, person=%d)\n",
        $first['id'], $first['occurrenceId'], $first['roleId'], $first['personId']);
}

// Edit 2: add a fresh assignment to the first (occurrence × role) cell that
// has no row in $desired (id-less duplicate detection — count rows per key).
$keyCount = [];
foreach ($desired as $a) {
    $keyCount[$a['occurrenceId'] . ':' . $a['roleId']] = ($keyCount[$a['occurrenceId'] . ':' . $a['roleId']] ?? 0) + 1;
}
$added = null;
foreach ($grid['occurrences'] as $o) {
    foreach ($grid['roles'] as $r) {
        $key = $o['id'] . ':' . $r['id'];
        if (($keyCount[$key] ?? 0) > 0) continue;
        $person = $grid['people'][0] ?? null;
        if ($person === null) break 2;
        $desired[] = [
            'id' => null,
            'occurrenceId' => $o['id'],
            'roleId' => $r['id'],
            'personId' => $person['id'],
            'label' => '',
        ];
        $added = ['occurrenceId' => $o['id'], 'roleId' => $r['id'], 'personId' => $person['id'], 'who' => $person['displayName']];
        printf("  edit: add (occ=%d, role=%s) -> %s\n", $o['id'], $r['name'], $person['displayName']);
        break 2;
    }
}

echo "\n=== 2. POST save ===\n";
$saveResult = $controller->saveAssignments($req + [
    'ministryId' => $ministryId,
    'start' => $startStr,
    'end'   => $endStr,
    'assignments' => $desired,
]);
printf("  saved=%s  count=%d\n", $saveResult['saved'] ? 'yes' : 'no', $saveResult['assignmentCount']);

echo "\n=== 3. Re-load and verify ===\n";
$grid2 = $controller->grid($req + ['ministry_id' => $ministryId, 'start' => $startStr, 'end' => $endStr]);
printf("  assignments now=%d (was %d)\n", count($grid2['assignments']), count($grid['assignments']));

if ($dropped !== null) {
    $stillThere = false;
    foreach ($grid2['assignments'] as $a) {
        if ($a['id'] === $dropped['id']) { $stillThere = true; break; }
    }
    echo "  dropped row gone: " . ($stillThere ? 'NO (FAIL)' : 'yes') . "\n";
}
if ($added !== null) {
    $found = null;
    foreach ($grid2['assignments'] as $a) {
        if ($a['occurrenceId'] === $added['occurrenceId']
            && $a['roleId'] === $added['roleId']
            && $a['personId'] === $added['personId']
        ) { $found = $a; break; }
    }
    echo "  added row present: " . ($found ? 'yes (id=' . $found['id'] . ')' : 'NO (FAIL)') . "\n";
}

echo "\n=== 4. Restore original state ===\n";
// Send the original snapshot back, KEEPING ids where they still exist so
// the diff path updates rather than re-inserts. Rows we explicitly dropped
// are sent with id=null and will be re-created.
$restorePayload = [];
$existingIdsAfter = array_flip(array_map(fn ($a) => $a['id'], $grid2['assignments']));
foreach ($grid['assignments'] as $a) {
    $restorePayload[] = [
        'id' => isset($existingIdsAfter[$a['id']]) ? $a['id'] : null,
        'occurrenceId' => $a['occurrenceId'],
        'roleId' => $a['roleId'],
        'personId' => $a['personId'],
        'label' => $a['label'],
    ];
}
$restoreResult = $controller->saveAssignments($req + [
    'ministryId' => $ministryId,
    'start' => $startStr,
    'end'   => $endStr,
    'assignments' => $restorePayload,
]);
printf("  restored count=%d\n", $restoreResult['assignmentCount']);

$gridFinal = $controller->grid($req + ['ministry_id' => $ministryId, 'start' => $startStr, 'end' => $endStr]);
printf("  final db rows in window: %d (expected %d)\n",
    count($gridFinal['assignments']), count($grid['assignments']));

$auth->logout($session->sessionToken);
echo "\nOK\n";
