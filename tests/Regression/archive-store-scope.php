<?php

declare(strict_types=1);

/**
 * The archive store lists and serves only the archives it wrote (YYYY/MM/<file>).
 * Its private folder also holds the live visitors database and other server-only
 * files, which must never appear as, or download as, an archive.
 */

require __DIR__ . '/../../app/Core/Config/EnvLoader.php';
require __DIR__ . '/../../app/Services/PrivateArchiveStore.php';

use App\Services\PrivateArchiveStore;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

$root = sys_get_temp_dir() . '/ekklesia-archive-scope-' . bin2hex(random_bytes(4));
mkdir($root . '/2026/09', 0700, true);
mkdir($root . '/database', 0700, true);
mkdir($root . '/demo', 0700, true);
file_put_contents($root . '/2026/09/mysql.members.09_17.1200.sql', '-- backup');
file_put_contents($root . '/database/visitors.sqlite', 'live');
file_put_contents($root . '/demo/secrets.txt', 'secret');
putenv('MAINTENANCE_PRIVATE_PATH=' . $root);

$store = new PrivateArchiveStore();
$listed = array_column($store->listRecent(), 'relative');
check('a backup is listed', in_array('2026/09/mysql.members.09_17.1200.sql', $listed, true));
check('the live visitors database is not listed', !in_array('database/visitors.sqlite', $listed, true));
check('other private files are not listed', !in_array('demo/secrets.txt', $listed, true));

$refused = static function (string $path) use ($store): bool {
    try {
        $store->absolute($path);
        return false;
    } catch (\InvalidArgumentException) {
        return true;
    }
};
check('a backup can be opened', !$refused('2026/09/mysql.members.09_17.1200.sql'));
check('the live visitors database cannot be downloaded', $refused('database/visitors.sqlite'));
check('other private files cannot be downloaded', $refused('demo/secrets.txt'));
check('path tricks are still refused', $refused('2026/09/../../demo/secrets.txt'));

foreach (['2026/09/mysql.members.09_17.1200.sql', 'database/visitors.sqlite', 'demo/secrets.txt'] as $f) {
    @unlink($root . '/' . $f);
}
@rmdir($root . '/2026/09'); @rmdir($root . '/2026'); @rmdir($root . '/database'); @rmdir($root . '/demo'); @rmdir($root);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
