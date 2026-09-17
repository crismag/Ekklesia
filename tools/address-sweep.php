<?php

declare(strict_types=1);

/**
 * Sweep stored addresses: fill blanks, refresh coordinates, report the rest.
 *
 * Dry run by default. Writing requires --apply, because every write here
 * changes a member's stored record — unlike the import, these rows have
 * already been applied and there is no staging copy to fall back on.
 *
 *   php tools/address-sweep.php --plan
 *   php tools/address-sweep.php --apply --delay=1500
 *   php tools/address-sweep.php --apply --all --limit=50
 *
 *   --plan            report what would change; touch nothing (default)
 *   --apply           write coordinates and fill blank address fields
 *   --all             include families that already have coordinates
 *   --limit=N         stop after N families
 *   --offset=N        skip the first N (resume a dry run the host cut short)
 *   --delay=MS        minimum gap per provider (default 1200, minimum 1000)
 *   --provider=NAME   nominatim | photon | both (default both)
 *   --report=PATH     write the unresolved list to a file
 *
 * The report names members, because its purpose is to tell an administrator
 * whose address needs attention. It is written where you ask and nowhere else:
 * it is roster data and does not belong in the repository.
 */

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Adapters\ChurchCRM\ChurchCrmAddressAdapter;
use App\Services\AddressNormalizer;
use App\Services\AddressSweepService;
use App\Services\PostalAreaIndex;
use App\Services\Geocoding\NominatimProvider;
use App\Services\Geocoding\PhotonProvider;
use App\Services\Geocoding\ProviderPool;
use App\Services\Geocoding\StreamHttpGet;

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$apply = isset($opts['apply']);
$onlyMissing = !isset($opts['all']);
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;
$offset = isset($opts['offset']) ? max(0, (int) $opts['offset']) : 0;

// A floor, not just a default. Nominatim's usage policy permits one request a
// second; letting a flag go below that would make abuse a typo away.
$delay = max(1000, (int) ($opts['delay'] ?? 1200));
$which = strtolower((string) ($opts['provider'] ?? 'both'));

$env = [];
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
        $env[$m[1]] = trim($m[2], "\"' \t\r\n");
    }
}

$db = new PDO(
    sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $env['CHURCHCRM_DB_HOST'] ?? '127.0.0.1',
        $env['CHURCHCRM_DB_DATABASE'] ?? '',
    ),
    $env['CHURCHCRM_DB_USERNAME'] ?? '',
    $env['CHURCHCRM_DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

// Nominatim's policy requires an identifying User-Agent with a way to reach
// whoever is responsible. Sending a generic one is how a caller gets blocked.
$appUrl = $env['APP_URL'] ?? 'https://christlikeness.crishub.com';
$contact = $env['GEOCODER_CONTACT'] ?? ($env['MAIL_FROM_ADDRESS'] ?? '');
$agent = 'ChristlikenessChurchPortal/1.0 (+' . $appUrl . ($contact !== '' ? '; ' . $contact : '') . ')';

$http = new StreamHttpGet();
$providers = [];
if ($which === 'both' || $which === 'nominatim') {
    $providers[] = new NominatimProvider($http, $agent, $delay);
}
if ($which === 'both' || $which === 'photon') {
    $providers[] = new PhotonProvider($http, $agent, $delay);
}
if ($providers === []) {
    fwrite(STDERR, "Unknown --provider. Use nominatim, photon, or both.\n");
    exit(1);
}

$service = new AddressSweepService(
    new ChurchCrmAddressAdapter($db),
    new ProviderPool($providers),
    AddressNormalizer::fromFile(
        $root . '/config/address-cities.json',
        $root . '/config/ca-postal-areas.json',
    ),
    PostalAreaIndex::fromFile($root . '/config/ca-postal-areas.json'),
);

printf(
    "Address sweep — %s\n  providers: %s\n  pace:      %dms per provider\n  scope:     %s%s\n\n",
    $apply ? 'APPLYING CHANGES' : 'dry run (nothing will be written)',
    implode(' + ', array_map(static fn ($p): string => $p->name(), $providers)),
    $delay,
    $onlyMissing ? 'families missing coordinates' : 'all families with an address',
    $limit !== null ? ", first {$limit}" : '',
);

$started = microtime(true);
$result = $service->sweep([
    'onlyMissingCoordinates' => $onlyMissing,
    'limit' => $limit,
    'offset' => $offset,
    'apply' => $apply,
    'progress' => static function (int $done, int $total): void {
        if ($done === $total || $done % 10 === 0) {
            printf("\r  %d/%d", $done, $total);
        }
    },
]);
$elapsed = microtime(true) - $started;

echo "\n\nStatistics\n";
foreach ($result['stats'] as $key => $value) {
    printf("  %-22s %d\n", $key, $value);
}

echo "\nProvider usage\n";
foreach ($result['providers'] as $key => $value) {
    printf("  %-22s %s\n", $key, $key === 'waitedMs' ? round($value / 1000, 1) . 's' : (string) $value);
}
printf("  %-22s %s\n", 'elapsed', round($elapsed, 1) . 's');

$unresolved = $result['unresolved'];
printf("\nUnresolvable addresses: %d\n", count($unresolved));

if ($result['stopped'] !== null) {
    printf("\nStopped early: %s\n", $result['stopped']);
    echo "Re-run to continue; work already written is kept.\n";
}

if ($unresolved !== []) {
    $path = is_string($opts['report'] ?? null) ? $opts['report'] : null;
    if ($path !== null) {
        $fh = fopen($path, 'wb');
        fputcsv($fh, ['family_id', 'family', 'reason', 'parse_issues']);
        foreach ($unresolved as $row) {
            fputcsv($fh, [$row['id'], $row['label'], $row['reason'], implode(' ', $row['issues'])]);
        }
        fclose($fh);
        @chmod($path, 0600);
        printf("Written to %s (member data — keep it off the repository).\n", $path);
    } else {
        echo "Reasons:\n";
        $byReason = [];
        foreach ($unresolved as $row) {
            $byReason[$row['reason']] = ($byReason[$row['reason']] ?? 0) + 1;
        }
        arsort($byReason);
        foreach ($byReason as $reason => $count) {
            printf("  %-58s %d\n", $reason, $count);
        }
        echo "\nRe-run with --report=<path> for the list of who they are.\n";
    }
}

if (!$apply) {
    echo "\nNothing was written. Re-run with --apply to save these changes.\n";
}
