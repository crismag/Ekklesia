<?php

declare(strict_types=1);

/**
 * Initialize the databases of a NEW, EMPTY Ekklesia installation.
 *
 *   php tools/install-database.php            show what would be done
 *   php tools/install-database.php --install  do it
 *
 * Member database (MEMBERS_DB_* in .env), which must contain no tables:
 *   1. loads database/members/001_schema.sql and 002_sheet_views.sql;
 *   2. for each migration in database/members/migrations/, checks the tables
 *      and columns it creates against the database just built:
 *        - all present  → recorded as included in the base schema (adopted);
 *        - none present → applied, then recorded, as tools/migrate.php would;
 *        - some present, or not checkable → stops and says which.
 *      A migration is never recorded as applied on the strength of its name.
 *
 * Visitors database (VISITORS_DB_PATH, default
 * storage/private/database/visitors.sqlite): created from
 * database/visitors/001_schema.sql when the file does not exist. An existing
 * file is left exactly as it is.
 *
 * It refuses a member database that already has tables: an existing
 * installation is upgraded with tools/migrate.php, and Church Portal data is
 * moved with database/migrate/run.sh in an isolated target. Nothing here
 * drops, truncates or overwrites.
 */

require __DIR__ . '/../app/Core/Config/EnvLoader.php';
require __DIR__ . '/../app/Core/Migrations/SqlStatements.php';
require __DIR__ . '/../app/Core/Migrations/SchemaExpectations.php';
require __DIR__ . '/../app/Core/ChurchTime.php';

use App\Core\Config\EnvLoader;
use App\Core\Migrations\SchemaExpectations;
use App\Core\Migrations\SqlStatements;

$root = dirname(__DIR__);
EnvLoader::loadOnce($root . '/.env');
$install = in_array('--install', array_slice($argv, 1), true);

function out(string $line = ''): void
{
    echo $line, "\n";
}

function stop(string $message): never
{
    fwrite(STDERR, "\n  STOPPED: {$message}\n\n");
    exit(1);
}

function setting(string $key, string $default = ''): string
{
    $value = EnvLoader::get($key);

    return $value !== null && $value !== '' ? $value : $default;
}

// ------------------------------------------------------------ member database

$name = setting('MEMBERS_DB_DATABASE');
if ($name === '') {
    stop('MEMBERS_DB_DATABASE is not set in .env.');
}
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            setting('MEMBERS_DB_HOST', '127.0.0.1'), setting('MEMBERS_DB_PORT', '3306'), $name),
        setting('MEMBERS_DB_USERNAME'),
        setting('MEMBERS_DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (PDOException $e) {
    stop("cannot connect to the member database {$name}: " . $e->getMessage());
}

$tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
out();
out("  member database: {$name}");
if ($tables > 0) {
    stop("{$name} already has {$tables} table(s). This tool only initializes an empty database.\n"
        . "  To upgrade an existing installation: php tools/migrate.php --status, then --apply.");
}

$migrations = glob($root . '/database/members/migrations/*.sql') ?: [];
sort($migrations);
$plans = [];
foreach ($migrations as $path) {
    $expect = SchemaExpectations::of((string) file_get_contents($path));
    if ($expect['unverifiable'] !== []) {
        stop(basename($path) . ' contains statements this tool cannot check ('
            . implode('; ', $expect['unverifiable']) . '). Initialize by hand, following docs/deploy/installation.md.');
    }
    $plans[] = ['path' => $path, 'name' => basename($path), 'checks' => $expect['checks']];
}

if (!$install) {
    out('  would load database/members/001_schema.sql and 002_sheet_views.sql');
    foreach ($plans as $p) {
        out("  would check {$p['name']} (" . count($p['checks']) . ' expectation(s)) and record or apply it');
    }
}

// ----------------------------------------------------------- visitors database

$visitorsPath = setting('VISITORS_DB_PATH', 'storage/private/database/visitors.sqlite');
if (!str_starts_with($visitorsPath, '/')) {
    $visitorsPath = $root . '/' . $visitorsPath;
}
$visitorsExists = is_file($visitorsPath);
out("  visitors database: {$visitorsPath}" . ($visitorsExists ? ' (exists; left as it is)' : ''));

if (!$install) {
    if (!$visitorsExists) {
        out('  would create it from database/visitors/001_schema.sql');
    }
    out();
    out('  Nothing was changed. Run again with --install to initialize.');
    out();
    exit(0);
}

// --------------------------------------------------------------------- install

foreach (['001_schema.sql', '002_sheet_views.sql'] as $file) {
    out("  loading database/members/{$file}");
    foreach (SqlStatements::parse((string) file_get_contents($root . '/database/members/' . $file)) as $statement) {
        $pdo->exec($statement);
    }
}

// Same history table as tools/migrate.php, which reads it afterwards.
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `filename`   VARCHAR(190) NOT NULL,
        `checksum`   CHAR(64)     NOT NULL,
        `applied_at` DATETIME     NOT NULL,
        `adopted`    TINYINT(1)   NOT NULL DEFAULT 0,
        PRIMARY KEY (`filename`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$record = $pdo->prepare(
    'INSERT INTO `schema_migrations` (filename, checksum, applied_at, adopted) VALUES (:f, :s, NOW(), :a)'
);
$tableExists = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
);
$columnType = $pdo->prepare(
    'SELECT column_type FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
);

foreach ($plans as $p) {
    $present = 0;
    foreach ($p['checks'] as $check) {
        if (!isset($check['column'])) {
            $tableExists->execute([':t' => $check['table']]);
            $present += (int) $tableExists->fetchColumn() > 0 ? 1 : 0;
            continue;
        }
        $columnType->execute([':t' => $check['table'], ':c' => $check['column']]);
        $type = $columnType->fetchColumn();
        if ($type === false) {
            continue;
        }
        if (isset($check['enum'])) {
            $wanted = "enum('" . implode("','", $check['enum']) . "')";
            $present += strtolower((string) $type) === strtolower($wanted) ? 1 : 0;
            continue;
        }
        $present++;
    }

    $sum = hash('sha256', (string) file_get_contents($p['path']));
    if ($present === count($p['checks']) && $present > 0) {
        $record->execute([':f' => $p['name'], ':s' => $sum, ':a' => 1]);
        out("  {$p['name']}: already in the base schema ({$present}/{$present} checks), recorded");
        continue;
    }
    if ($present === 0) {
        out("  {$p['name']}: not in the base schema, applying");
        foreach (SqlStatements::parse((string) file_get_contents($p['path'])) as $statement) {
            $pdo->exec($statement);
        }
        $record->execute([':f' => $p['name'], ':s' => $sum, ':a' => 0]);
        continue;
    }
    stop("{$p['name']} is only partly in the base schema ({$present}/" . count($p['checks'])
        . ' checks). The base schema and this migration disagree; fix the repository, then empty the'
        . " database (it now holds the base schema) before installing again.");
}

if (!$visitorsExists) {
    $dir = dirname($visitorsPath);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        stop("cannot create {$dir}");
    }
    $sqlite = new PDO('sqlite:' . $visitorsPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sqlite->exec((string) file_get_contents($root . '/database/visitors/001_schema.sql'));
    chmod($visitorsPath, 0640);
    out("  created the visitors database");
}

$tz = \App\Core\ChurchTime::describe();
out();
out('  church time zone: ' . $tz['zone'] . ' · ' . $tz['label'] . ($tz['configured'] ? '' : ' (default: not set yet)'));
out('    Every date and time follows it. Set it first, in Administration → Church information.');
out();
out('  Done. Next:');
out('    php tools/migrate.php --status        should report nothing pending');
out('    php tools/create-admin-user.php       creates the first administrator');
out();
