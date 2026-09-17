<?php
/**
 * One-shot applier for migrations/portal/005-create-roster-schedules.sql.
 * Drops the obsolete ministry_duty table and creates the roster trio.
 * CLI:  php tools/apply_roster_migration.php
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require_once $path;
});
use App\Core\Config\EnvLoader;
EnvLoader::loadOnce(__DIR__ . '/../.env');

$mysqli = @new mysqli(
    (string) (EnvLoader::get('PORTAL_DB_HOST') ?? '127.0.0.1'),
    (string) (EnvLoader::get('PORTAL_DB_USERNAME') ?? ''),
    (string) (EnvLoader::get('PORTAL_DB_PASSWORD') ?? ''),
    (string) (EnvLoader::get('PORTAL_DB_DATABASE') ?? ''),
    (int)    (EnvLoader::get('PORTAL_DB_PORT') ?? 3306),
);
if ($mysqli->connect_errno) { echo 'FAIL: ' . $mysqli->connect_error . "\n"; exit(1); }

$sql = file_get_contents(__DIR__ . '/../migrations/portal/005-create-roster-schedules.sql');
echo "Applying 005-create-roster-schedules.sql ...\n";
if (!$mysqli->multi_query($sql)) { echo 'FAIL: ' . $mysqli->error . "\n"; exit(1); }
do { if ($r = $mysqli->store_result()) $r->free(); } while ($mysqli->more_results() && $mysqli->next_result());
if ($mysqli->errno !== 0) { echo 'FAIL: ' . $mysqli->error . "\n"; exit(1); }

foreach (['schedule_roster', 'schedule_roster_slot', 'schedule_roster_assignment'] as $t) {
    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $stmt->bind_result($n);
    $stmt->fetch();
    $stmt->close();
    if ((int) $n === 0) { echo "FAIL: $t missing\n"; exit(1); }
    echo "  ok: $t\n";
}
echo "Done.\n";
