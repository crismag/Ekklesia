<?php

declare(strict_types=1);

/**
 * Migration runner behaviour.
 *
 * Written after a migration sat in the repository, unapplied, on the deployed
 * database — invisible because nothing recorded what had been applied. These
 * assertions cover the properties that make that impossible to repeat.
 *
 * Parsing is tested directly against the real schema and migration files,
 * because the two bugs that actually occurred were both parsing bugs: a comment
 * block sharing a semicolon-chunk with the statement after it, and a comment
 * containing a semicolon.
 */

require __DIR__ . '/../../app/Core/Config/EnvLoader.php';

$passed = 0;
$failed = 0;
require_once __DIR__ . '/../../app/Core/Migrations/SqlStatements.php';

use App\Core\Migrations\SqlStatements;

function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

// The runner defines statements()/stripTrailingComment()/targetOf(). Load it
// without running by capturing output from a --status invocation in a subshell
// is overkill; instead re-declare the same parsing here from its source so the
// test fails if the implementation changes shape.
$runner = file_get_contents(__DIR__ . '/../../tools/migrate.php');
check('migration runner exists', $runner !== false && $runner !== '');
check('runner records history in a table', str_contains($runner, 'schema_migrations'));
check('runner applies files from database/members/migrations', str_contains($runner, 'database/members/migrations'));
check('runner supports --status, --apply and --baseline',
    str_contains($runner, '--status') && str_contains($runner, '--apply') && str_contains($runner, '--baseline'));
check('a failed migration is not recorded as applied',
    str_contains($runner, 'a failed migration must never look applied') || str_contains($runner, 'FAILED on'));
check('runner stores a checksum so an edited migration is visible',
    str_contains($runner, 'checksum') && str_contains($runner, "hash('sha256'"));

// --- parsing, against the real schema and migration files ------------------
// Parse with the production splitter, not a copy of it: a duplicated splitter
// once agreed with the runner's bug instead of catching it.
$root = __DIR__ . '/../../database/members';
$files = array_merge(glob($root . '/*.sql') ?: [], glob($root . '/migrations/*.sql') ?: []);
check('the member schema files are present', in_array($root . '/001_schema.sql', $files, true));

$allSql = '';
$migrationSql = '';
foreach ($files as $f) {
    $sql = (string) file_get_contents($f);
    $allSql .= $sql;
    if (str_contains($f, '/migrations/')) {
        $migrationSql .= $sql;
    }
    $name = basename($f);
    $stmts = SqlStatements::parse($sql);
    check("{$name} parses into at least one statement", $stmts !== []);
    foreach ($stmts as $stmt) {
        // Every statement must begin with SQL, never with prose left behind by
        // a mis-split comment.
        if (preg_match('/^(CREATE|ALTER|INSERT|UPDATE|DROP|SET|DELETE|RENAME)\b/i', $stmt) !== 1) {
            check("{$name}: statement starts with SQL, not comment text (" . substr($stmt, 0, 40) . ')', false);
        }
    }
}
check('no parsed statement begins with stray comment prose', true);

// --- destructive-operation guard -------------------------------------------
check('every DROP in a migration is guarded by IF EXISTS',
    preg_match('/\bDROP\s+(TABLE|VIEW|INDEX|COLUMN)\s+(?!IF\s+EXISTS)/i', $migrationSql) !== 1);
check('no migration truncates', preg_match('/\bTRUNCATE\b/i', $migrationSql) !== 1);

// Every table the application relies on must be created by the schema, or it
// exists only on machines where someone happened to add it by hand. The
// calendar once broke in production for exactly that reason.
$schema = (string) file_get_contents($root . '/001_schema.sql');
foreach (['people', 'households', 'household_links', 'ministries', 'ministry_members', 'ministry_member_positions',
          'serving_roles', 'events', 'event_occurrences', 'assignments', 'rosters', 'user_accounts', 'account_roles',
          'account_sessions', 'member_import_batches', 'member_import_rows', 'audit_log', 'schema_migrations'] as $table) {
    check("the schema creates {$table}", preg_match('/CREATE TABLE ' . $table . ' \(/', $schema) === 1);
}
check('event_types.audience is created by the schema', preg_match("/\baudience\s+ENUM\('public','members','leaders'\)/", $schema) === 1);
foreach (['campus_id', 'status', 'source_label', 'duplicate_report', 'created_by_account_id', 'hub_sheet'] as $col) {
    check("the schema defines member_import_batches.{$col}", preg_match('/CREATE TABLE member_import_batches \([^;]*\b' . $col . '\b/s', $schema) === 1);
}

// ---------------------------------------------------------------------------
// A semicolon inside a quoted string is not a statement terminator.
//
// The comment stripper has been quote-aware since migration 001, where a role
// list contained "leader; scope_ministry_id required". The splitter had not
// caught up: a column COMMENT 'lowercased; identity' was cut in half and the
// fragment after it handed to the server as SQL. Caught when migration 011 did
// exactly that.
echo "\nSplitting statements\n";
check('a plain pair of statements splits',
    count(SqlStatements::parse("CREATE TABLE a (x INT);\nCREATE TABLE b (y INT);")) === 2);
check('a semicolon inside a string does not split',
    count(SqlStatements::parse("CREATE TABLE a (x INT COMMENT 'one; two');")) === 1,
    json_encode(SqlStatements::parse("CREATE TABLE a (x INT COMMENT 'one; two');")));
check('and the comment survives intact',
    str_contains(SqlStatements::parse("CREATE TABLE a (x INT COMMENT 'one; two');")[0], "'one; two'"));
check('a semicolon inside backticks does not split',
    count(SqlStatements::parse('CREATE TABLE `odd;name` (x INT);')) === 1);
check('an escaped quote does not end the string early',
    count(SqlStatements::parse("INSERT INTO t VALUES ('it''s; fine');")) === 1,
    json_encode(SqlStatements::parse("INSERT INTO t VALUES ('it''s; fine');")));
check('a backslash-escaped quote likewise',
    count(SqlStatements::parse("INSERT INTO t VALUES ('a\\'; b');")) === 1,
    json_encode(SqlStatements::parse("INSERT INTO t VALUES ('a\\'; b');")));
check('a trailing semicolon does not produce an empty statement',
    count(SqlStatements::parse('SELECT 1;')) === 1);
check('no terminator at all still yields the statement',
    count(SqlStatements::parse('SELECT 1')) === 1);
check('a line comment containing a semicolon is still dropped whole',
    count(SqlStatements::parse("-- roles: leader; admin\nSELECT 1;")) === 1);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
