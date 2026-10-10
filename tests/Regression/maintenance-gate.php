<?php

declare(strict_types=1);

/**
 * The gate that closes the window between new code and its migration.
 *
 * Deployment overwrites files in place, so for a few seconds the site runs code
 * whose schema has not arrived. That has broken this site twice — the member
 * import once, and the calendar once, silently, showing no events at all.
 *
 * The tests that matter most here are the ones about *not* holding: a church
 * website must never be left dark because a deploy died on somebody's laptop.
 * Every path that cannot positively establish "a release is in progress right
 * now" has to let the request through.
 */

require_once __DIR__ . '/../../app/Core/Maintenance.php';

use App\Core\Maintenance;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

$dir = sys_get_temp_dir() . '/portal-maint-' . bin2hex(random_bytes(4));
mkdir($dir);
$flag = $dir . '/maintenance.flag';
$now = 1_800_000_000;

echo "Holding requests while a release lands\n";
check('no flag means the site is serving', Maintenance::isActive($flag, $now) === false);

file_put_contents($flag, $now . "\ndeploying\n");
check('a fresh flag holds requests', Maintenance::isActive($flag, $now) === true);
check('and carries its reason', Maintenance::reason($flag) === 'deploying');
check('a flag one second old still holds', Maintenance::isActive($flag, $now + 1) === true);
check('and just inside the ceiling still holds',
    Maintenance::isActive($flag, $now + Maintenance::MAX_SECONDS - 1) === true);

echo "\nFailing open — a dead deploy must not keep the site dark\n";
check('past the ceiling the flag is ignored',
    Maintenance::isActive($flag, $now + Maintenance::MAX_SECONDS) === false);
check('and long past it, certainly',
    Maintenance::isActive($flag, $now + 86_400) === false);

// A flag cannot ask for an outage longer than the ceiling allows.
file_put_contents($flag, ($now + 86_400) . "\nforever\n");
check('a flag stamped in the future cannot outlast the ceiling',
    Maintenance::isActive($flag, $now + 86_400 + Maintenance::MAX_SECONDS) === false);

// Half-written, truncated or corrupt flags fall back to the file's own mtime,
// which still expires. The one thing they must never do is hold forever.
file_put_contents($flag, "not-a-timestamp\n");
touch($flag, $now);
check('an unparseable flag falls back to its mtime and still holds briefly',
    Maintenance::isActive($flag, $now + 5) === true);
check('and still expires', Maintenance::isActive($flag, $now + Maintenance::MAX_SECONDS + 1) === false);

file_put_contents($flag, '');
touch($flag, $now);
check('an empty flag behaves the same way, not forever',
    Maintenance::isActive($flag, $now + Maintenance::MAX_SECONDS + 1) === false);

unlink($flag);
check('removing the flag releases immediately', Maintenance::isActive($flag, $now) === false);
check('and a missing flag has no reason to report', Maintenance::reason($flag) === '');

echo "\nWhat clients are told to do\n";
file_put_contents($flag, $now . "\ndeploying\n");
$retry = Maintenance::retryAfter($flag, $now);
check('Retry-After is positive', $retry > 0, (string) $retry);
check('and never longer than the gate can last', $retry <= Maintenance::MAX_SECONDS, (string) $retry);
check('and never zero, which would mean hammering the server',
    Maintenance::retryAfter($flag, $now + Maintenance::MAX_SECONDS - 1) >= 5);
unlink($flag);
check('with no flag the advice is still a sane number', Maintenance::retryAfter($flag, $now) >= 5);

rmdir($dir);

echo "\nThe deploy script runs the stages in the only safe order\n";
$sh = file_get_contents(__DIR__ . '/../../tools/deploy.sh');
$order = [];
foreach (['maintenance gate', 'syncing files', 'applying migrations', 'releasing'] as $stage) {
    $order[$stage] = strpos($sh, $stage);
}
check('the gate goes up before files are sent',
    $order['maintenance gate'] < $order['syncing files']);
check('files are sent before migrations run',
    $order['syncing files'] < $order['applying migrations']);
check('migrations run before the gate comes down',
    $order['applying migrations'] < $order['releasing']);
check('the script stops on any failing command', str_contains($sh, 'set -Eeuo pipefail'));
check('a failed migration refuses to release',
    str_contains($sh, 'the gate stays up and the release is NOT live'));
check('pending migrations after --apply also refuse to release',
    str_contains($sh, 'refusing to release'));
// Match invocations, not the comment that explains why there are none.
$rsyncLines = array_values(array_filter(
    explode("\n", $sh),
    static fn (string $l): bool => preg_match('/^\s*(if\s+)?!?\s*rsync\s/', $l) === 1,
));
check('the script does invoke rsync', $rsyncLines !== []);
check('the sync never deletes — the server owns config the repository does not',
    !array_filter($rsyncLines, static fn (string $l): bool => str_contains($l, '--delete')),
    implode(' | ', $rsyncLines));
check('the flag is server-owned and never synced from a laptop',
    str_contains(file_get_contents(__DIR__ . '/../../.rsync-deploy-exclude'), 'storage/maintenance.flag'));

echo "\nThe gate is wired into the front controller\n";
$front = file_get_contents(__DIR__ . '/../../public/index.php');
check('index.php consults it', str_contains($front, 'Maintenance::isActive()'));
check('before any route is dispatched',
    strpos($front, 'Maintenance::isActive()') < strpos($front, '$handler = $routes[$key]'));
check('and answers 503, not 200 with an error page', str_contains($front, 'http_response_code(503)'));
check('with Retry-After so clients back off', str_contains($front, 'Retry-After'));
check('and no-store, so nothing caches the outage', str_contains($front, "header('Cache-Control: no-store')"));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
