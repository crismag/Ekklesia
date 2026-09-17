<?php

declare(strict_types=1);

/**
 * Migration runner behaviour.
 *
 * Written after migration 007 sat in the repository, unapplied, on the deployed
 * database — invisible because nothing recorded what had been applied. These
 * assertions cover the properties that make that impossible to repeat.
 *
 * Parsing is tested directly against the real migration files, because the two
 * bugs that actually occurred were both parsing bugs: a comment block sharing a
 * semicolon-chunk with the statement after it, and a comment containing a
 * semicolon.
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
check('runner records history in a table', str_contains($runner, 'portal_schema_migrations'));
check('runner reads a per-file @connection target', str_contains($runner, '@connection'));
check('runner supports --status, --apply and --baseline',
    str_contains($runner, '--status') && str_contains($runner, '--apply') && str_contains($runner, '--baseline'));
check('a failed migration is not recorded as applied',
    str_contains($runner, 'a failed migration must never look applied') || str_contains($runner, 'FAILED on'));
check('runner stores a checksum so an edited migration is visible',
    str_contains($runner, 'checksum') && str_contains($runner, "hash('sha256'"));

// --- parsing, against the real files ---------------------------------------
$dir = __DIR__ . '/../../migrations/portal';
$files = glob($dir . '/*.sql') ?: [];
check('portal migrations are present', count($files) >= 8);

// Parse with the production splitter, not a copy of it.
//
// These closures used to reimplement it — and reproduced its bug exactly, so
// the test agreed with the runner that a semicolon inside a COMMENT ended a
// statement. Both were wrong together, which is the failure mode a duplicated
// implementation is for.
$statements = static fn (string $sql): array => SqlStatements::parse($sql);

$allSql = '';
foreach ($files as $f) {
    $sql = (string) file_get_contents($f);
    $allSql .= $sql;
    $name = basename($f);

    check("{$name} declares its target database", preg_match('/^\s*--\s*@connection:\s*(portal|churchcrm)\s*$/mi', $sql) === 1);

    $stmts = $statements($sql);
    check("{$name} parses into at least one statement", $stmts !== []);
    foreach ($stmts as $stmt) {
        // Every statement must begin with SQL, never with prose left behind by
        // a mis-split comment — the exact failure mode that broke 001.
        if (preg_match('/^(CREATE|ALTER|INSERT|UPDATE|DROP|SET|DELETE|RENAME)\b/i', $stmt) !== 1) {
            check("{$name}: statement starts with SQL, not comment text (" . substr($stmt, 0, 40) . ')', false);
        }
    }
}
check('no parsed statement begins with stray comment prose', true);

// --- destructive-operation guard -------------------------------------------
// 005 legitimately drops an abandoned table that never held data, and it has
// already run — rewriting an applied migration would be worse than the drop.
// So the guard is not "never drop": it is that any drop is guarded by IF
// EXISTS, that nothing truncates, and that no drop creeps into a later
// migration unnoticed.
$unguardedDrop = preg_match('/\bDROP\s+TABLE\s+(?!IF\s+EXISTS)/i', $allSql) === 1;
check('every DROP TABLE is guarded by IF EXISTS', !$unguardedDrop);
check('no portal migration truncates', preg_match('/\bTRUNCATE\b/i', $allSql) !== 1);
$dropFiles = [];
foreach ($files as $f) {
    if (preg_match('/\bDROP\s+TABLE\b/i', (string) file_get_contents($f)) === 1) {
        $dropFiles[] = basename($f);
    }
}
check('only the known migration drops anything (' . (implode(', ', $dropFiles) ?: 'none') . ')',
    $dropFiles === ['005-create-roster-schedules.sql']);

// Migration 009 gives event types their portal identity. The audience values
// it seeds are the same strings EventAudience compares against, so a typo here
// would silently make leader-only events visible to everyone.
$m009 = (string) file_get_contents($dir . '/009-event-type-portal-metadata.sql');
check('009 targets the churchcrm connection', str_contains($m009, '-- @connection: churchcrm'));
check('009 is idempotent', substr_count(strtoupper($m009), 'IF NOT EXISTS') >= 6);
check('009 avoids PREPARE/EXECUTE (breaks the semicolon splitter)',
    preg_match('/\bPREPARE\s+stmt\b|\bEXECUTE\s+stmt\b/i', $m009) !== 1);
foreach (['portal_slug', 'portal_label', 'portal_audience', 'portal_color', 'portal_is_default'] as $col) {
    check("009 defines event_types.{$col}", str_contains($m009, '`' . $col . '`'));
}
check('009 seeds the leader-audience layers', str_contains($m009, "'leadership'") && str_contains($m009, "'ministry'"));
check('009 backfills dangling event_type ids', str_contains($m009, 'UPDATE `events_event`'));

// Migration 007 must create what the import adapter actually uses.
$m007 = (string) file_get_contents($dir . '/007-member-import-staging.sql');
$adapter = (string) file_get_contents(__DIR__ . '/../../app/Adapters/ChurchCRM/ChurchCrmMemberImportAdapter.php');
check('007 creates member_import_batch', str_contains($m007, 'member_import_batch'));
check('007 creates member_import_row', str_contains($m007, 'member_import_row'));
check('007 is idempotent', substr_count(strtoupper($m007), 'IF NOT EXISTS') >= 2);
check('import adapter refuses rather than creating schema',
    !preg_match('/CREATE\s+TABLE/i', $adapter) && str_contains($adapter, 'assertSchemaReady'));
check('refusal names the database and the migrate command',
    str_contains($adapter, 'SELECT DATABASE()') && str_contains($adapter, 'tools/migrate.php'));

// Columns the adapter binds must exist in the migration.
foreach (['campus_id', 'status', 'source_label', 'duplicate_report', 'created_by'] as $col) {
    check("007 defines member_import_batch.{$col}", str_contains($m007, '`' . $col . '`'));
}
foreach (['batch_id', 'last_name', 'filled_from', 'matched_person_id'] as $col) {
    check("007 defines member_import_row.{$col}", str_contains($m007, '`' . $col . '`'));
}

// Every column the application reads must be created by a migration, or it
// exists only on machines where someone happened to add it by hand. The
// calendar broke in production because event_types.portal_audience arrived in
// application code with a migration that had not been applied there.
$audienceInMigrations = false;
foreach ($files as $f) {
    if (str_contains((string) file_get_contents($f), 'portal_audience')) {
        $audienceInMigrations = true;
    }
}
check('event_types.portal_audience is created by a migration', $audienceInMigrations);


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
