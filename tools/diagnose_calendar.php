<?php

/**
 * CLI diagnostic for the portal calendar adapter.
 *
 * Calls ChurchCrmCalendarAdapter::listSystemItems with the same plumbing the
 * /api/calendar/sources route uses, and prints the resulting items. Lets us
 * verify that recurring event occurrences (and birthday/anniversary items)
 * are surfaced correctly, without requiring an authenticated browser session.
 *
 * Usage:
 *   php tools/diagnose_calendar.php [start] [end] [campusId]
 *   e.g. php tools/diagnose_calendar.php 2026-05-04 2026-06-04
 */

declare(strict_types=1);

// Mirror public/index.php's autoloader and env loader so the App\ namespace
// resolves and the ChurchCRM PDO can be built from the portal's .env.
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

use App\Adapters\ChurchCRM\ChurchCrmCalendarAdapter;
use App\Core\Config\EnvLoader;
use App\Core\Database\ChurchCrmConnection;

EnvLoader::loadOnce(__DIR__ . '/../.env');

$start    = $argv[1] ?? 'now';
$end      = $argv[2] ?? '+30 days';
$campusId = isset($argv[3]) && $argv[3] !== '' ? (int) $argv[3] : null;

$startDt = new DateTimeImmutable($start);
$endDt   = new DateTimeImmutable($end);

$pdo = ChurchCrmConnection::get();
if ($pdo === null) {
    fwrite(STDERR, "FAIL: ChurchCRM PDO not available.\n");
    exit(1);
}

$adapter = new ChurchCrmCalendarAdapter($pdo);
$items = $adapter->listSystemItems($startDt, $endDt, $campusId);

printf(
    "Window: %s → %s%s\n",
    $startDt->format('Y-m-d'),
    $endDt->format('Y-m-d'),
    $campusId !== null ? sprintf(' (campus #%d)', $campusId) : ''
);
printf("Items returned: %d\n", count($items));
printf("%s\n", str_repeat('-', 78));

$bySource = [];
foreach ($items as $item) {
    $bySource[$item['source']] = ($bySource[$item['source']] ?? 0) + 1;
}
foreach ($bySource as $source => $count) {
    printf("  %-16s %d\n", $source, $count);
}
echo "\n";

foreach ($items as $item) {
    printf(
        "[%s] %-12s %-30s %s\n",
        $item['date'] . (isset($item['starts_at']) ? ' ' . substr($item['starts_at'], 11, 5) : '     '),
        $item['source'],
        substr($item['title'], 0, 30),
        $item['meta'] ?? ''
    );
}
