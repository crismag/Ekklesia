<?php

declare(strict_types=1);

/**
 * Member database migration runner.
 *
 * The schema starts from database/members/001_schema.sql (a fresh install, or
 * database/migrate/run.sh when moving legacy data). Every later change is a file
 * in database/members/migrations/, applied in filename order by this runner.
 *
 * Why it exists: a migration once sat in the repository, unapplied, on the
 * deployed database, because nothing recorded what had been applied. So:
 *  - History lives in schema_migrations in the member database.
 *  - A migration is recorded only after it succeeds. A failure stops the run.
 *  - A checksum is kept, so a migration edited after it ran is visible.
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

const MIGRATION_DIR = __DIR__ . '/../database/members/migrations';
const HISTORY_TABLE = 'schema_migrations';

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

/** @return array{0:PDO,1:string} the member database connection and its name */
function connect(): array
{
    $host = env('MEMBERS_DB_HOST') ?: '127.0.0.1';
    $port = env('MEMBERS_DB_PORT') ?: '3306';
    $name = env('MEMBERS_DB_DATABASE');
    if ($name === '') {
        throw new RuntimeException('MEMBERS_DB_DATABASE is not configured.');
    }
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        env('MEMBERS_DB_USERNAME'),
        env('MEMBERS_DB_PASSWORD'),
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


// ---------------------------------------------------------------- history

[$pdo, $dbName] = connect();
$historyPdo = $pdo;
$historyDb = $dbName;
$historyPdo->exec(
    'CREATE TABLE IF NOT EXISTS `' . HISTORY_TABLE . '` (
        `filename`   VARCHAR(190) NOT NULL,
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
    $sum = hash('sha256', $sql);
    $state = 'pending';
    if (isset($applied[$name])) {
        $state = $applied[$name]['checksum'] === $sum
            ? ((int) $applied[$name]['adopted'] === 1 ? 'adopted' : 'applied')
            : 'CHANGED SINCE APPLIED';
    } else {
        $pending[] = ['path' => $path, 'name' => $name, 'sql' => $sql, 'sum' => $sum];
    }
    printf("  %-40s %s\n", $name, $state);
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
    'INSERT INTO `' . HISTORY_TABLE . '` (filename, checksum, applied_at, adopted)
     VALUES (:f, :s, NOW(), :a)
     ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = VALUES(applied_at), adopted = VALUES(adopted)'
);

foreach ($pending as $m) {
    if ($mode === 'baseline') {
        $record->execute([':f' => $m['name'], ':s' => $m['sum'], ':a' => 1]);
        echo "  adopted (not run): {$m['name']}\n";
        continue;
    }

    echo "  applying {$m['name']} ({$dbName})\n";
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
    $record->execute([':f' => $m['name'], ':s' => $m['sum'], ':a' => 0]);
    echo "    ok\n";
}

echo "\n  Done.\n\n";
