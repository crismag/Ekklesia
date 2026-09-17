<?php

declare(strict_types=1);

/**
 * Portal migration runner.
 *
 * Why this exists: migration 007 was in the repository but had never been
 * applied to the deployed database, because the import service used to create
 * its staging tables at runtime. When that runtime DDL was removed — schema
 * belongs to migrations, not to request handlers — the gap became visible as a
 * hard failure. Nothing recorded which migrations had been applied, so nothing
 * could have told us.
 *
 * Design:
 *  - migrations/portal/*.sql, applied in filename order.
 *  - Each file declares "-- @connection: portal|churchcrm" in its header.
 *    migrations/portal/ holds migrations for BOTH databases: portal_* tables in
 *    the portal database, member import staging and events alongside person_per
 *    in the ChurchCRM database. The directory name does not tell you which.
 *  - History lives in ONE place, portal_schema_migrations in the portal
 *    database, so there is a single answer to "what has been applied".
 *  - A migration is recorded only after it succeeds. A failure stops the run.
 *
 * Usage:
 *   php tools/migrate.php --status     what is applied, what is pending
 *   php tools/migrate.php --apply      apply everything pending, in order
 *   php tools/migrate.php --baseline   record pending as applied WITHOUT
 *                                      running them (adopting a database whose
 *                                      schema was created by hand)
 */

require __DIR__ . '/../app/Core/Config/EnvLoader.php';
require __DIR__ . '/../app/Core/Migrations/SqlStatements.php';
App\Core\Config\EnvLoader::loadOnce(__DIR__ . '/../.env');

const MIGRATION_DIR = __DIR__ . '/../migrations/portal';
const HISTORY_TABLE = 'portal_schema_migrations';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$mode = isset($opts['apply']) ? 'apply' : (isset($opts['baseline']) ? 'baseline' : 'status');

function env(string ...$keys): string
{
    foreach ($keys as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') {
            return $v;
        }
    }

    return '';
}

/** @return array{0:PDO,1:string} connection and its database name */
function connect(string $which): array
{
    if ($which === 'churchcrm') {
        $host = env('CHURCHCRM_DB_HOST', 'DB_HOST');
        $name = env('CHURCHCRM_DB_DATABASE', 'DB_DATABASE');
        $user = env('CHURCHCRM_DB_USERNAME', 'DB_USERNAME');
        $pass = env('CHURCHCRM_DB_PASSWORD', 'DB_PASSWORD');
    } else {
        $host = env('PORTAL_DB_HOST', 'DB_HOST');
        $name = env('PORTAL_DB_DATABASE', 'DB_DATABASE');
        $user = env('PORTAL_DB_USERNAME', 'DB_USERNAME');
        $pass = env('PORTAL_DB_PASSWORD', 'DB_PASSWORD');
    }
    if ($name === '') {
        throw new RuntimeException("No database configured for the '{$which}' connection.");
    }
    $port = env($which === 'churchcrm' ? 'CHURCHCRM_DB_PORT' : 'PORTAL_DB_PORT', 'DB_PORT') ?: '3306';
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    return [$pdo, $name];
}

/**
 * Split a file into executable statements.
 *
 * Comment lines are stripped per-line rather than per-chunk: a leading comment
 * block shares its semicolon-chunk with the first real statement, and skipping
 * the whole chunk silently discards that statement. That exact mistake is how
 * migration 008 half-applied on its first production run.
 *
 * @return list<string>
 */
/**
 * @return list<string>
 */
function statements(string $sql): array
{
    // The splitting itself lives in App\Core\Migrations\SqlStatements so it
    // can be tested. It got a bug that only surfaced when a migration put a
    // semicolon inside a column COMMENT, and nothing could have caught it while
    // it lived inside a script.
    return App\Core\Migrations\SqlStatements::parse($sql);
}


function targetOf(string $sql): string
{
    if (preg_match('/^\s*--\s*@connection:\s*([a-z]+)/mi', $sql, $m) === 1) {
        return strtolower(trim($m[1]));
    }

    return 'portal';
}

// ---------------------------------------------------------------- history

[$historyPdo, $historyDb] = connect('portal');
$historyPdo->exec(
    'CREATE TABLE IF NOT EXISTS `' . HISTORY_TABLE . '` (
        `filename`   VARCHAR(190) NOT NULL,
        `connection` VARCHAR(32)  NOT NULL,
        `checksum`   CHAR(64)     NOT NULL,
        `applied_at` DATETIME     NOT NULL,
        `adopted`    TINYINT(1)   NOT NULL DEFAULT 0,
        PRIMARY KEY (`filename`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = [];
foreach ($historyPdo->query('SELECT filename, checksum, applied_at, adopted FROM `' . HISTORY_TABLE . '`') as $row) {
    $applied[(string) $row['filename']] = $row;
}

$files = glob(MIGRATION_DIR . '/*.sql') ?: [];
sort($files);

echo "\n  history: {$historyDb}." . HISTORY_TABLE . "\n\n";

$pending = [];
foreach ($files as $path) {
    $name = basename($path);
    $sql = (string) file_get_contents($path);
    $target = targetOf($sql);
    $sum = hash('sha256', $sql);
    $state = 'pending';
    if (isset($applied[$name])) {
        $state = $applied[$name]['checksum'] === $sum
            ? ((int) $applied[$name]['adopted'] === 1 ? 'adopted' : 'applied')
            : 'CHANGED SINCE APPLIED';
    } else {
        $pending[] = ['path' => $path, 'name' => $name, 'sql' => $sql, 'target' => $target, 'sum' => $sum];
    }
    printf("  %-34s %-10s %s\n", $name, $target, $state);
}

if ($pending === []) {
    echo "\n  Nothing pending.\n\n";
    exit(0);
}

echo "\n  " . count($pending) . " pending.\n";

if ($mode === 'status') {
    echo "  Run with --apply to apply them, or --baseline to record them as already applied.\n\n";
    exit(0);
}

// ------------------------------------------------------------------ apply

$record = $historyPdo->prepare(
    'INSERT INTO `' . HISTORY_TABLE . '` (filename, connection, checksum, applied_at, adopted)
     VALUES (:f, :c, :s, NOW(), :a)
     ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = VALUES(applied_at), adopted = VALUES(adopted)'
);

$conns = [];
foreach ($pending as $m) {
    if ($mode === 'baseline') {
        $record->execute([':f' => $m['name'], ':c' => $m['target'], ':s' => $m['sum'], ':a' => 1]);
        echo "  adopted (not run): {$m['name']}\n";
        continue;
    }

    if (!isset($conns[$m['target']])) {
        $conns[$m['target']] = connect($m['target']);
    }
    [$pdo, $dbName] = $conns[$m['target']];

    echo "  applying {$m['name']} -> {$m['target']} ({$dbName})\n";
    try {
        foreach (statements($m['sql']) as $stmt) {
            $pdo->exec($stmt);
        }
    } catch (Throwable $e) {
        // Not recorded: a failed migration must never look applied.
        fwrite(STDERR, "\n  FAILED on {$m['name']}: " . $e->getMessage() . "\n");
        fwrite(STDERR, "  Stopping. Nothing after this was attempted, and this migration was not recorded.\n\n");
        exit(1);
    }
    $record->execute([':f' => $m['name'], ':c' => $m['target'], ':s' => $m['sum'], ':a' => 0]);
    echo "    ok\n";
}

echo "\n  Done.\n\n";
