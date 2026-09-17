<?php
/**
 * One-shot applier for migrations/portal/003-portal-users-passwords.sql.
 * Idempotent (ADD COLUMN IF NOT EXISTS); safe to re-run.
 *
 * Web:  /church_portal/tools/apply_portal_auth_v3_migration.php
 * CLI:  php tools/apply_portal_auth_v3_migration.php
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

$host     = (string) (EnvLoader::get('PORTAL_DB_HOST')     ?? '127.0.0.1');
$port     = (int)    (EnvLoader::get('PORTAL_DB_PORT')     ?? 3306);
$database = (string) (EnvLoader::get('PORTAL_DB_DATABASE') ?? '');
$user     = (string) (EnvLoader::get('PORTAL_DB_USERNAME') ?? '');
$pass     = (string) (EnvLoader::get('PORTAL_DB_PASSWORD') ?? '');
if ($database === '' || $user === '') {
    echo "FAIL: portal DB env not configured.\n"; exit(1);
}

$mysqli = @new mysqli($host, $user, $pass, $database, $port);
if ($mysqli->connect_errno) {
    echo 'FAIL: ' . $mysqli->connect_error . "\n"; exit(1);
}

$sql = file_get_contents(__DIR__ . '/../migrations/portal/003-portal-users-passwords.sql');
if ($sql === false) { echo "FAIL: migration not found.\n"; exit(1); }

echo "Applying 003-portal-users-passwords.sql ...\n";

if (!$mysqli->multi_query($sql)) {
    echo 'FAIL: ' . $mysqli->error . "\n"; exit(1);
}
do {
    if ($r = $mysqli->store_result()) $r->free();
} while ($mysqli->more_results() && $mysqli->next_result());

if ($mysqli->errno !== 0) { echo 'FAIL: ' . $mysqli->error . "\n"; exit(1); }

foreach (['must_change_password', 'churchcrm_person_id'] as $col) {
    $stmt = $mysqli->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_users' AND COLUMN_NAME = ?"
    );
    $stmt->bind_param('s', $col);
    $stmt->execute();
    $stmt->bind_result($n);
    $stmt->fetch();
    $stmt->close();
    if ((int) $n === 0) { echo "FAIL: column $col missing.\n"; exit(1); }
}
echo "OK: portal_users.must_change_password and churchcrm_person_id are present.\n";
