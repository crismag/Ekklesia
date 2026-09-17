<?php

declare(strict_types=1);

/**
 * Align ChurchCRM group_grp rows with the Hub serving-ministry catalog.
 *
 * Default is a dry-run. Pass --apply to rename, create, and deactivate.
 *
 *   php tools/sync-ministry-catalog.php
 *   php tools/sync-ministry-catalog.php --apply
 *
 * Existing group ids are kept on rename so memberships, roles, and schedules
 * stay attached. "GS: Usher" is Guest Services + role Usher, not a second
 * ministry. G&A is Gifts and Arrows.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Core\Config\EnvLoader;
use App\Adapters\ChurchCRM\ChurchCrmMinistryAdapter;
use App\Core\Database\ChurchCrmConnection;
use App\Services\MinistryCatalog;

$opts = getopt('', ['apply', 'help']);
if ($opts === false || isset($opts['help'])) {
    fwrite(STDOUT, (string) preg_replace('/^<\?php.*?\*\//s', '', (string) file_get_contents(__FILE__)));
    exit(isset($opts['help']) ? 0 : 2);
}

$apply = isset($opts['apply']);
EnvLoader::loadOnce(__DIR__ . '/../.env');

$catalog = MinistryCatalog::fromFile(__DIR__ . '/../config/ministry-catalog.json');
$pdo = ChurchCrmConnection::get();
$adapter = new ChurchCrmMinistryAdapter($pdo);

$existing = $adapter->listMinistriesAdmin(null);
if ($existing === [] && $pdo === null) {
    fwrite(STDERR, "ChurchCRM database is not configured. Set CHURCHCRM_DB_* in .env.\n");
    exit(1);
}

/** @var array<string, array<string,mixed>> $byKey */
$byKey = [];
foreach ($existing as $row) {
    $byKey[$catalog->key((string) $row['name'])] = $row;
}

$actions = [];
$usedIds = [];

foreach ($catalog->serving() as $want) {
    $match = null;
    foreach (array_merge([$want['name']], $want['aliases']) as $alias) {
        $k = $catalog->key($alias);
        if (isset($byKey[$k])) {
            $match = $byKey[$k];
            break;
        }
    }
    if ($match === null) {
        $actions[] = ['op' => 'create', 'name' => $want['name'], 'description' => $want['description']];
        continue;
    }
    $usedIds[(int) $match['ministry_id']] = true;
    $sameName = (string) $match['name'] === $want['name'];
    $sameDesc = trim((string) ($match['description'] ?? '')) === $want['description'];
    $active = (bool) ($match['active'] ?? true);
    if (!$sameName || !$active) {
        $actions[] = [
            'op' => 'rename',
            'ministry_id' => (int) $match['ministry_id'],
            'from' => (string) $match['name'],
            'to' => $want['name'],
            'description' => $want['description'],
            'activate' => !$active,
        ];
    } elseif (!$sameDesc && $want['description'] !== '') {
        $actions[] = [
            'op' => 'describe',
            'ministry_id' => (int) $match['ministry_id'],
            'name' => $want['name'],
            'description' => $want['description'],
        ];
    } else {
        $actions[] = [
            'op' => 'keep',
            'ministry_id' => (int) $match['ministry_id'],
            'name' => $want['name'],
        ];
    }
}

foreach ($existing as $row) {
    $id = (int) $row['ministry_id'];
    if (isset($usedIds[$id]) || $catalog->shouldDeactivate((string) $row['name'])) {
        continue;
    }
    $serving = $catalog->matchServing((string) $row['name']);
    if ($serving === null || !(bool) ($row['active'] ?? true)) {
        continue;
    }
    $actions[] = [
        'op' => 'deactivate',
        'ministry_id' => $id,
        'name' => (string) $row['name'],
        'reason' => 'Duplicate of ' . $serving['name'],
        'member_count' => (int) ($row['member_count'] ?? 0),
    ];
}

foreach ($catalog->deactivate() as $drop) {
    $k = $catalog->key($drop['name']);
    if (!isset($byKey[$k])) {
        $actions[] = ['op' => 'missing', 'name' => $drop['name'], 'reason' => $drop['reason']];
        continue;
    }
    $row = $byKey[$k];
    if ($catalog->matchServing((string) $row['name']) !== null) {
        continue;
    }
    if (!(bool) ($row['active'] ?? true)) {
        $actions[] = [
            'op' => 'already-inactive',
            'ministry_id' => (int) $row['ministry_id'],
            'name' => (string) $row['name'],
            'reason' => $drop['reason'],
        ];
        continue;
    }
    $actions[] = [
        'op' => 'deactivate',
        'ministry_id' => (int) $row['ministry_id'],
        'name' => (string) $row['name'],
        'reason' => $drop['reason'],
        'member_count' => (int) ($row['member_count'] ?? 0),
    ];
}

$existingRoleNames = static function ($pdo, int $ministryId): array {
    if ($pdo === null || $ministryId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT role_name FROM roles WHERE ministry_group_id = :id');
    $stmt->execute([':id' => $ministryId]);
    $names = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $names[] = (string) $name;
    }
    return $names;
};

foreach ($catalog->serving() as $want) {
    if ($want['roles'] === []) {
        continue;
    }
    $match = null;
    foreach (array_merge([$want['name']], $want['aliases']) as $alias) {
        $k = $catalog->key($alias);
        if (isset($byKey[$k])) {
            $match = $byKey[$k];
            break;
        }
    }
    $have = [];
    foreach ($existingRoleNames($pdo, (int) ($match['ministry_id'] ?? 0)) as $haveName) {
        $have[$catalog->key($haveName)] = true;
    }
    foreach ($want['roles'] as $role) {
        if (isset($have[$catalog->key($role)])) {
            continue;
        }
        $actions[] = [
            'op' => 'role',
            'ministry_id' => $match !== null ? (int) $match['ministry_id'] : 0,
            'ministry' => $want['name'],
            'role' => $role,
        ];
    }
}

echo $apply ? "APPLY ministry catalog\n" : "DRY-RUN ministry catalog (pass --apply to write)\n";
echo str_repeat('-', 72) . "\n";
foreach ($actions as $a) {
    $op = (string) $a['op'];
    if ($op === 'keep') {
        printf("  keep        #%d  %s\n", $a['ministry_id'], $a['name']);
        continue;
    }
    if ($op === 'create') {
        printf("  create            %s\n", $a['name']);
        continue;
    }
    if ($op === 'rename') {
        printf(
            "  rename      #%d  %s → %s%s\n",
            $a['ministry_id'],
            $a['from'],
            $a['to'],
            !empty($a['activate']) ? ' (reactivate)' : ''
        );
        continue;
    }
    if ($op === 'describe') {
        printf("  describe    #%d  %s\n", $a['ministry_id'], $a['name']);
        continue;
    }
    if ($op === 'deactivate') {
        printf(
            "  deactivate  #%d  %s  (%d members) — %s\n",
            $a['ministry_id'],
            $a['name'],
            $a['member_count'],
            $a['reason']
        );
        continue;
    }
    if ($op === 'already-inactive') {
        printf("  inactive    #%d  %s\n", $a['ministry_id'], $a['name']);
        continue;
    }
    if ($op === 'missing') {
        printf("  not found        %s — %s\n", $a['name'], $a['reason']);
        continue;
    }
    if ($op === 'role') {
        printf(
            "  role        %s / %s%s\n",
            $a['ministry'],
            $a['role'],
            ((int) ($a['ministry_id'] ?? 0) > 0) ? '' : ' (after create)'
        );
    }
}

if (!$apply) {
    echo "\nNo database writes. Re-run with --apply on the people database host.\n";
    exit(0);
}

foreach ($actions as $a) {
    $op = (string) $a['op'];
    if ($op === 'create') {
        $adapter->createMinistry([
            'name' => $a['name'],
            'description' => $a['description'] ?? '',
        ]);
        continue;
    }
    if ($op === 'rename' || $op === 'describe') {
        $id = (int) $a['ministry_id'];
        $name = (string) ($a['to'] ?? $a['name']);
        $adapter->updateMinistry($id, [
            'name' => $name,
            'description' => (string) ($a['description'] ?? ''),
        ]);
        if ($op === 'rename' && !empty($a['activate'])) {
            $adapter->setMinistryActive($id, true);
        }
        continue;
    }
    if ($op === 'deactivate') {
        $adapter->setMinistryActive((int) $a['ministry_id'], false);
        continue;
    }
    if ($op === 'role' && (int) ($a['ministry_id'] ?? 0) > 0) {
        $adapter->createMinistryRole((int) $a['ministry_id'], [
            'name' => $a['role'],
            'order' => 0,
            'active' => true,
        ]);
    }
}

$existing = $adapter->listMinistriesAdmin(null);
$byKey = [];
foreach ($existing as $row) {
    $byKey[$catalog->key((string) $row['name'])] = $row;
}
foreach ($actions as $a) {
    if (($a['op'] ?? '') !== 'role' || (int) ($a['ministry_id'] ?? 0) > 0) {
        continue;
    }
    $k = $catalog->key((string) $a['ministry']);
    if (!isset($byKey[$k])) {
        continue;
    }
    $adapter->createMinistryRole((int) $byKey[$k]['ministry_id'], [
        'name' => $a['role'],
        'order' => 0,
        'active' => true,
    ]);
}

echo "\nDone.\n";
