<?php

declare(strict_types=1);

/**
 * Every date and time is the church's local time, whatever the server's or
 * the viewer's time zone: one setting (Church information → Time zone) decides
 * PHP's clock, the database connection's clock and what every browser shows.
 *
 * Run: php tests/Regression/church-time.php
 */

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use App\Core\ChurchTime;
use App\Services\ChurchInfoService;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

$root = dirname(__DIR__, 2);

echo "The church's zone\n";
ChurchTime::override(null);
$configured = json_decode((string) file_get_contents($root . '/config/church-info.json'), true)['timeZone'] ?? '';
check('it is read from Church information', ChurchTime::zone() === ($configured !== '' ? $configured : ChurchTime::DEFAULT_ZONE));
check('real zone names are accepted', ChurchTime::isValidZone('Asia/Manila') && ChurchTime::isValidZone('UTC'));
check('anything else is refused', !ChurchTime::isValidZone('Toronto') && !ChurchTime::isValidZone('') && !ChurchTime::isValidZone('Mars/Base'));

echo "\nPHP's clock\n";
date_default_timezone_set('UTC');
ChurchTime::override('Asia/Manila');
ChurchTime::apply();
check('apply() makes the church zone the default, whatever the server set', date_default_timezone_get() === 'Asia/Manila');
check('so a stored wall-clock time is sent with the church offset',
    (new DateTimeImmutable('2026-10-11 10:00:00'))->format(DATE_ATOM) === '2026-10-11T10:00:00+08:00');

ChurchTime::override('America/Toronto');
check('the offset follows daylight saving (summer)', ChurchTime::offset(new DateTimeImmutable('2026-07-01 12:00:00')) === '-04:00');
check('the offset follows daylight saving (winter)', ChurchTime::offset(new DateTimeImmutable('2026-12-01 12:00:00')) === '-05:00');

echo "\nThe database's clock\n";
$recording = new class ('sqlite::memory:') extends PDO {
    /** @var list<string> */
    public array $sql = [];
    public bool $namedZones = true;
    public function exec(string $statement): int|false
    {
        $this->sql[] = $statement;
        if (!$this->namedZones && str_contains($statement, '/')) {
            throw new PDOException('Unknown or incorrect time zone');
        }

        return 0;
    }
};
ChurchTime::applyTo($recording);
check('the connection is set to the church zone by name', $recording->sql === ["SET time_zone = 'America/Toronto'"], implode(' | ', $recording->sql));
$recording->sql = [];
$recording->namedZones = false;
ChurchTime::applyTo($recording);
check('without time-zone tables it falls back to the current offset',
    count($recording->sql) === 2 && preg_match("/^SET time_zone = '-0[45]:00'$/", $recording->sql[1]) === 1, implode(' | ', $recording->sql));

echo "\nThe page script\n";
ChurchTime::override('Pacific/Auckland');
$script = ChurchTime::script();
check('it carries the church zone', str_contains($script, 'var zone = "Pacific/Auckland";'));
check('and defines window.EkklesiaTime', str_starts_with($script, '<script>') && str_contains($script, 'root.EkklesiaTime = api'));
check('the portal shell puts it on every page', str_contains((string) file_get_contents($root . '/resources/views/_portal-shell.php'), 'ChurchTime::script()'));

echo "\nIn the browser, from anywhere\n";
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "  skip  node is not installed; the browser checks did not run\n";
} else {
    $run = static function (string $viewer, string $church) use ($node): array {
        $cmd = 'TZ=' . escapeshellarg($viewer) . ' ' . escapeshellarg($node) . ' '
            . escapeshellarg(__DIR__ . '/church-time.mjs') . ' ' . escapeshellarg($church) . ' 2>&1';

        return (array) json_decode((string) shell_exec($cmd), true);
    };
    foreach (['America/Toronto', 'Asia/Shanghai', 'Australia/Sydney', 'Pacific/Honolulu', 'UTC'] as $viewer) {
        $r = $run($viewer, 'America/Toronto');
        check("a Toronto church seen from {$viewer}: the 10:00 service is 10:00 on Sunday the 11th",
            ($r['wallClock'] ?? null) === '2026-10-11 10:00' && ($r['withOffset'] ?? null) === '2026-10-11 10:00'
            && ($r['utcInstant'] ?? null) === '2026-10-11 10:00', json_encode($r));
        check("  and a date stays that date, a late event stays on its day, today is the church's",
            ($r['dateOnly'] ?? null) === '2026-10-12 00:00' && ($r['lateEvening'] ?? null) === '2026-10-11 23:30'
            && ($r['today'] ?? null) === ($r['expectedToday'] ?? '-'), json_encode($r));
    }
    $r = $run('America/Toronto', 'Asia/Manila');
    check('a Manila church seen from Toronto shows Manila times', ($r['utcInstant'] ?? null) === '2026-10-11 22:00'
        && ($r['wallClock'] ?? null) === '2026-10-11 10:00' && ($r['today'] ?? null) === ($r['expectedToday'] ?? '-'), json_encode($r));
    $r = $run('UTC', 'Not/AZone');
    check('an unknown zone falls back to the browser clock instead of failing', ($r['wallClock'] ?? null) === '2026-10-11 10:00'
        && array_key_exists('invalid', $r) && $r['invalid'] === null && array_key_exists('empty', $r) && $r['empty'] === null, json_encode($r));
}

echo "\nNo page decides a date in UTC or on the viewer's clock\n";
$offenders = [];
foreach (['resources/views', 'printable'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        if (preg_match('/toISOString\(\)\.slice\(0,\s*10\)|getTimezoneOffset\(|new Date\(\)/', $src) === 1) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
check('dates come from EkklesiaTime, not toISOString(), getTimezoneOffset() or new Date()', $offenders === [], implode(', ', $offenders));

echo "\nWhat administrators see\n";
$at = static fn (string $when): DateTimeImmutable => new DateTimeImmutable($when, new DateTimeZone('UTC'));
ChurchTime::override('America/Toronto');
check('the zone is described in words, with daylight saving',
    ChurchTime::describe($at('2026-07-01 12:00'))['label'] === 'Toronto (EDT, UTC−04:00)'
    && ChurchTime::describe($at('2026-12-01 12:00'))['label'] === 'Toronto (EST, UTC−05:00)');
ChurchTime::override('America/Argentina/Buenos_Aires');
check('a zone without a letter abbreviation shows its offset', ChurchTime::describe($at('2026-10-10 12:00'))['label'] === 'Buenos Aires (UTC−03:00)');
$read = new ReflectionMethod(ChurchTime::class, 'readZone');
$cfg = tempnam(sys_get_temp_dir(), 'tz-');
file_put_contents($cfg, '{"timeZone": "Asia/Manila"}');
ChurchTime::override(null);
check('a zone set in Church information counts as configured', $read->invoke(null, $cfg) === 'Asia/Manila' && ChurchTime::isConfigured());
file_put_contents($cfg, '{"timeZone": ""}');
check('an empty one falls back to the default and says so', $read->invoke(null, $cfg) === ChurchTime::DEFAULT_ZONE && !ChurchTime::isConfigured());
@unlink($cfg);
ChurchTime::override(null);
check("the server's own zone is remembered from before the church's was applied", ChurchTime::serverZone() === 'UTC');
$system = (string) file_get_contents($root . '/resources/views/admin-system.php');
check('Administration → System shows the church zone, the server zone and the database setting',
    str_contains($system, 'Church time zone') && str_contains($system, "Server's own PHP time zone") && str_contains($system, 'Database connection'));
check('and warns when no zone has been set', str_contains($system, "No time zone is set for the church"));
check('Church information says which zone is in use', str_contains((string) file_get_contents($root . '/resources/views/admin-church-info.php'), 'ChurchTime::describe()'));
check('the footer does not carry it: it is an administrative setting', !str_contains((string) file_get_contents($root . '/resources/views/_portal-shell.php'), 'portal-footer-tz'));
check('installing reports the zone the new installation uses', str_contains((string) file_get_contents($root . '/tools/install-database.php'), 'church time zone:'));
check('every deployment reports the zone of the installation it updates', str_contains((string) file_get_contents($root . '/tools/deploy.sh'), 'church time zone:'));

echo "\nChurch information\n";
$tmp = tempnam(sys_get_temp_dir(), 'church-info-');
$info = new ChurchInfoService($tmp);
try {
    $info->save(['name' => 'Sample Church', 'timeZone' => 'Toronto']);
    check('a misspelt time zone is refused, so it cannot move the whole calendar', false);
} catch (InvalidArgumentException) {
    check('a misspelt time zone is refused, so it cannot move the whole calendar', true);
}
check('a real one is saved', $info->save(['name' => 'Sample Church', 'timeZone' => 'Asia/Manila'])['timeZone'] === 'Asia/Manila');
@unlink($tmp);

ChurchTime::override(null);
printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
