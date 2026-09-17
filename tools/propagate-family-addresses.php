<?php

declare(strict_types=1);

/**
 * Fill an empty address on one side of a household from the other.
 *
 *   php tools/propagate-family-addresses.php            # report only
 *   php tools/propagate-family-addresses.php --apply    # write them
 *
 * Two gaps, both the same shape. A family record with no address whose members
 * have one — which is how the Nabua family, a couple with two children, ended
 * up invisible to every rule keyed on where a household lives. And a person
 * with no address whose family has one.
 *
 * Only ever fills a blank. Where the two sides disagree, the disagreement is
 * reported and nothing is written: a person really can live somewhere other
 * than their family's address, and a tool cannot tell that from a stale record.
 *
 * Members must agree unanimously before their address becomes the family's. One
 * member out of step means the household is not settled, and guessing which of
 * them is right is exactly the judgement a person should be making.
 *
 * Counts only are printed; addresses are member data.
 */

$root = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);

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

$key = static fn (string $v): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $v));
$blank = static fn (mixed $v): bool => trim((string) $v) === '';

// --- members → family --------------------------------------------------------

$rows = $db->query(
    "SELECT pp.per_fam_ID AS fam, pp.per_Address1 AS a1, pp.per_Address2 AS a2,
            pp.per_City AS city, pp.per_State AS state, pp.per_Zip AS zip, pp.per_Country AS country
       FROM person_per pp
       JOIN family_fam f ON f.fam_ID = pp.per_fam_ID
      WHERE TRIM(COALESCE(f.fam_Address1, '')) = ''
        AND TRIM(COALESCE(pp.per_Address1, '')) <> ''"
)->fetchAll(PDO::FETCH_ASSOC);

$byFamily = [];
foreach ($rows as $r) {
    $byFamily[(int) $r['fam']][] = $r;
}

$toFamily = [];
$disagreeing = 0;
foreach ($byFamily as $famId => $members) {
    $distinct = array_unique(array_map(static fn (array $m): string => $key((string) $m['a1']), $members));
    if (count($distinct) !== 1) {
        $disagreeing++;
        continue;
    }
    $toFamily[$famId] = $members[0];
}

printf("Families with no address whose members have one: %d\n", count($byFamily));
printf("  members agree, so the family can take it:      %d\n", count($toFamily));
printf("  members disagree, left for a person to settle: %d\n\n", $disagreeing);

// --- family → members --------------------------------------------------------

$people = $db->query(
    "SELECT pp.per_ID AS id, f.fam_Address1 AS a1, f.fam_Address2 AS a2,
            f.fam_City AS city, f.fam_State AS state, f.fam_Zip AS zip, f.fam_Country AS country
       FROM person_per pp
       JOIN family_fam f ON f.fam_ID = pp.per_fam_ID
      WHERE TRIM(COALESCE(f.fam_Address1, '')) <> ''
        AND TRIM(COALESCE(pp.per_Address1, '')) = ''"
)->fetchAll(PDO::FETCH_ASSOC);

printf("People with no address whose family has one:     %d\n\n", count($people));

if (!$apply) {
    echo "Dry run — nothing was written. Pass --apply to fill these in.\n";
    exit(0);
}

$setFamily = $db->prepare(
    "UPDATE family_fam
        SET fam_Address1 = :a1, fam_Address2 = :a2, fam_City = :city,
            fam_State = :state, fam_Zip = :zip, fam_Country = :country,
            fam_DateLastEdited = NOW()
      WHERE fam_ID = :id AND TRIM(COALESCE(fam_Address1, '')) = ''"
);
$famWritten = 0;
foreach ($toFamily as $famId => $a) {
    $setFamily->execute([
        ':a1' => $a['a1'], ':a2' => $a['a2'] ?? '', ':city' => $a['city'] ?? '',
        ':state' => $a['state'] ?? '', ':zip' => $a['zip'] ?? '', ':country' => $a['country'] ?? '',
        ':id' => $famId,
    ]);
    $famWritten += $setFamily->rowCount();
}

$setPerson = $db->prepare(
    "UPDATE person_per
        SET per_Address1 = :a1, per_Address2 = :a2, per_City = :city,
            per_State = :state, per_Zip = :zip, per_Country = :country,
            per_DateLastEdited = NOW()
      WHERE per_ID = :id AND TRIM(COALESCE(per_Address1, '')) = ''"
);
$personWritten = 0;
foreach ($people as $a) {
    $setPerson->execute([
        ':a1' => $a['a1'], ':a2' => $a['a2'] ?? '', ':city' => $a['city'] ?? '',
        ':state' => $a['state'] ?? '', ':zip' => $a['zip'] ?? '', ':country' => $a['country'] ?? '',
        ':id' => (int) $a['id'],
    ]);
    $personWritten += $setPerson->rowCount();
}

printf("Applied. %d family address(es) and %d person address(es) filled in.\n", $famWritten, $personWritten);
