<?php

declare(strict_types=1);

/**
 * A fresh installation records a migration as included in the base schema only
 * after checking the database for what that migration creates. These checks
 * cover reading those expectations from SQL, and the repository rule they
 * depend on: every migration is folded into 001_schema.sql.
 *
 * The database side of tools/install-database.php is exercised against a real
 * empty database when installation is verified; see docs/deploy/installation.md.
 *
 * Run: php tests/Regression/install-database.php
 */

require_once __DIR__ . '/../../app/Core/Migrations/SqlStatements.php';
require_once __DIR__ . '/../../app/Core/Migrations/SchemaExpectations.php';

use App\Core\Migrations\SchemaExpectations;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

echo "Reading what a migration creates\n";
$e = SchemaExpectations::of("-- a comment; with a semicolon\nCREATE TABLE IF NOT EXISTS `widgets` (id INT);\n");
check('a created table is expected', $e['checks'] === [['table' => 'widgets']] && $e['unverifiable'] === [], json_encode($e));

$e = SchemaExpectations::of("ALTER TABLE people\n  ADD COLUMN nickname VARCHAR(40) NULL AFTER first_name,\n  ADD KEY ix_nick (nickname);");
check('an added column is expected, an added key needs no check', $e['checks'] === [['table' => 'people', 'column' => 'nickname']], json_encode($e));

$e = SchemaExpectations::of("ALTER TABLE account_tokens MODIFY purpose ENUM('a','b, c','d') NOT NULL;");
check('a changed ENUM is expected with its values, commas inside quotes kept',
    $e['checks'] === [['table' => 'account_tokens', 'column' => 'purpose', 'enum' => ['a', 'b, c', 'd']]], json_encode($e));

$e = SchemaExpectations::of('ALTER TABLE people CHANGE COLUMN nick nickname VARCHAR(40) NULL;');
check('a renamed column is expected under its new name', $e['checks'] === [['table' => 'people', 'column' => 'nickname']], json_encode($e));

foreach ([
    'a data change' => "UPDATE people SET notes = NULL;",
    'an insert' => "INSERT INTO member_types (name) VALUES ('Guest');",
    'a dropped column' => 'ALTER TABLE people DROP COLUMN notes;',
    'a dropped table' => 'DROP TABLE IF EXISTS widgets;',
    'a view' => 'CREATE OR REPLACE VIEW v AS SELECT 1;',
] as $what => $sql) {
    check("{$what} cannot be checked, so the installer stops", SchemaExpectations::of($sql)['unverifiable'] !== []);
}

echo "\nEvery migration is in the base schema\n";
$root = dirname(__DIR__, 2);
$schema = (string) file_get_contents($root . '/database/members/001_schema.sql');
$tableBody = static function (string $table) use ($schema): ?string {
    if (preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?' . preg_quote($table, '/') . '`?\s*\((.*?)\)\s*ENGINE/is', $schema, $m) !== 1) {
        return null;
    }

    return $m[1];
};
$migrations = glob($root . '/database/members/migrations/*.sql') ?: [];
check('there are migrations to check', $migrations !== []);
foreach ($migrations as $path) {
    $name = basename($path);
    $e = SchemaExpectations::of((string) file_get_contents($path));
    check("{$name} can be checked by the installer", $e['unverifiable'] === [], implode('; ', $e['unverifiable']));
    $missing = [];
    foreach ($e['checks'] as $c) {
        $body = $tableBody($c['table']);
        if ($body === null) {
            $missing[] = $c['table'];
        } elseif (isset($c['column']) && preg_match('/^\s*`?' . preg_quote($c['column'], '/') . '`?\s/mi', $body) !== 1) {
            $missing[] = $c['table'] . '.' . $c['column'];
        } elseif (isset($c['enum'])) {
            foreach ($c['enum'] as $value) {
                if (!str_contains($body, "'" . $value . "'")) {
                    $missing[] = $c['table'] . '.' . $c['column'] . " value '{$value}'";
                }
            }
        }
    }
    check("{$name} is folded into 001_schema.sql", $missing === [], 'missing: ' . implode(', ', $missing));
}

echo "\nThe installer's safeguards\n";
$tool = (string) file_get_contents($root . '/tools/install-database.php');
check('it refuses a database that already has tables', str_contains($tool, 'This tool only initializes an empty database'));
check('it changes nothing without --install', str_contains($tool, "in_array('--install'") && str_contains($tool, 'Nothing was changed'));
check('it never drops or truncates', preg_match('/\b(DROP|TRUNCATE)\s+(TABLE|DATABASE)\b/i', $tool) !== 1);
check('it leaves an existing visitors database alone', str_contains($tool, 'left as it is'));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
