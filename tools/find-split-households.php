<?php

declare(strict_types=1);

/**
 * Find households entered as two family records, and join them.
 *
 *   php tools/find-split-households.php                # report only
 *   php tools/find-split-households.php --apply        # merge the certain ones
 *   php tools/find-split-households.php --apply --likely
 *
 * The same mistake takes several shapes: a married couple as two one-person
 * families, a son split off from his parents and siblings, a surname typed two
 * ways. Thirteen households on this roster were split like that. One pair gave
 * street numbers a digit apart at the same unit, so no grouping on address text
 * would ever have put them together.
 *
 * Merging keeps the older family record and moves the other person into it, so
 * one of the two addresses is discarded — which is why an address that does not
 * match is reported rather than quietly resolved, and why only matching
 * addresses count as certain.
 *
 * Run tools/suggest-household-roles.php afterwards: a merged household often
 * becomes two adults of different sex sharing a surname, which is the plainest
 * rule there is.
 */

$root = dirname(__DIR__);
require_once $root . '/app/Services/HouseholdRoleSuggester.php';
require_once $root . '/app/Services/SplitHouseholdFinder.php';

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Services\SplitHouseholdFinder;

$apply = in_array('--apply', $argv, true);
$includeLikely = in_array('--likely', $argv, true);

$env = [];
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
        $env[$m[1]] = trim($m[2], "\"' \t\r\n");
    }
}
$db = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $env['CHURCHCRM_DB_HOST'] ?? '127.0.0.1', $env['CHURCHCRM_DB_DATABASE'] ?? ''),
    $env['CHURCHCRM_DB_USERNAME'] ?? '',
    $env['CHURCHCRM_DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$families = [];
foreach ($db->query(
    "SELECT f.fam_ID, f.fam_Name, COALESCE(f.fam_Address1, '') AS address,
            COALESCE(f.fam_Zip, '') AS postcode,
            (SELECT COUNT(*) FROM person_per q WHERE q.per_fam_ID = f.fam_ID) AS members,
            (SELECT GROUP_CONCAT(DISTINCT q.per_LastName) FROM person_per q WHERE q.per_fam_ID = f.fam_ID) AS surnames
       FROM family_fam f
      WHERE f.fam_DateDeactivated IS NULL
        AND (SELECT COUNT(*) FROM person_per q WHERE q.per_fam_ID = f.fam_ID) > 0"
) as $r) {
    $families[] = [
        'family_id' => (int) $r['fam_ID'],
        'label' => trim((string) $r['fam_Name']),
        'address' => (string) $r['address'],
        'postcode' => (string) $r['postcode'],
        'members' => (int) $r['members'],
        'surnames' => array_filter(array_map('trim', explode(',', (string) $r['surnames']))),
    ];
}

$pairs = (new SplitHouseholdFinder())->find($families);

printf("Families examined: %d\n", count($families));
printf("Households that look split in two: %d\n\n", count($pairs));

foreach ($pairs as $pair) {
    printf(
        "  [%s] %s (family %d, %d member%s) + %s (family %d, %d member%s)\n",
        strtoupper($pair['confidence']),
        $pair['a']['label'], $pair['a']['family_id'], $pair['a']['members'], $pair['a']['members'] === 1 ? '' : 's',
        $pair['b']['label'], $pair['b']['family_id'], $pair['b']['members'], $pair['b']['members'] === 1 ? '' : 's',
    );
    printf("      they share %s\n", implode(', ', $pair['agrees']));
    foreach ($pair['differs'] as $difference) {
        printf("      but %s\n", $difference);
    }
}

$actionable = array_values(array_filter($pairs, static fn (array $p): bool =>
    $p['confidence'] === SplitHouseholdFinder::CERTAIN
    || ($includeLikely && $p['confidence'] === SplitHouseholdFinder::LIKELY)));

if (!$apply) {
    printf(
        "\nDry run — nothing was merged. --apply joins the %d certain pair(s); add --likely for %d more.\n",
        count(array_filter($pairs, static fn (array $p): bool => $p['confidence'] === SplitHouseholdFinder::CERTAIN)),
        count(array_filter($pairs, static fn (array $p): bool => $p['confidence'] === SplitHouseholdFinder::LIKELY)),
    );
    exit(0);
}

$families = \App\Providers\PortalServiceProvider::makeFamilyAdminService();
$merged = 0;
foreach ($actionable as $pair) {
    // Keep the fuller record: it has more people pointing at it and, usually,
    // the address somebody checked. Ties go to the one entered first.
    [$keeper, $dropped] = $pair['a']['members'] === $pair['b']['members']
        ? ($pair['a']['family_id'] < $pair['b']['family_id'] ? [$pair['a'], $pair['b']] : [$pair['b'], $pair['a']])
        : ($pair['a']['members'] > $pair['b']['members'] ? [$pair['a'], $pair['b']] : [$pair['b'], $pair['a']]);
    $keep = $keeper['family_id'];
    $drop = $dropped['family_id'];
    $moved = $families->merge($keep, $drop, 0);
    printf("  merged family %d into %d — %d person moved\n", $drop, $keep, $moved);
    $merged++;
}
printf("\n%d pair(s) joined. Run tools/suggest-household-roles.php next to give them their roles.\n", $merged);
