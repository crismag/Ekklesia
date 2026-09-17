<?php

declare(strict_types=1);

/**
 * Re-parse addresses that an earlier parser read wrongly, from the original
 * text the import kept.
 *
 *   php tools/repair-parsed-addresses.php            # report only
 *   php tools/repair-parsed-addresses.php --apply    # write the corrections
 *
 * Why this can exist at all: the import staging row keeps address_raw, the
 * line exactly as the spreadsheet wrote it, next to the fields parsed from it.
 * So when the parser is corrected, the original is still there to read again —
 * no guessing at what a mangled street line used to be.
 *
 * It only touches records whose city is plainly not a city: a country or a
 * province sitting in the field. A record that merely looks unusual is left
 * alone, because re-parsing everything would overwrite corrections people made
 * by hand.
 *
 * Dry run unless --apply. Counts only are printed; addresses are member data
 * and do not belong in a terminal log.
 */

$root = dirname(__DIR__);
require_once $root . '/app/Services/PostalAreaIndex.php';
require_once $root . '/app/Services/AddressNormalizer.php';

use App\Services\AddressNormalizer;

/** Values that are never a city. */
const NOT_A_CITY = [
    'CANADA', 'CA', 'CAN', 'USA', 'US', 'UNITED STATES',
    'ON', 'ONTARIO', 'BC', 'AB', 'QC', 'QUEBEC', 'MB', 'SK', 'NS', 'NB', 'NL', 'PE',
];

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

$normalizer = AddressNormalizer::fromFile(
    $root . '/config/address-cities.json',
    $root . '/config/ca-postal-areas.json',
);

$placeholders = implode(',', array_fill(0, count(NOT_A_CITY), '?'));

// --- staged rows: the original text lives here, so fix these first ----------

$staged = $db->prepare(
    "SELECT id, address_raw, address1, city, state, zip, country
       FROM member_import_row
      WHERE TRIM(COALESCE(address_raw,'')) <> ''
        AND UPPER(TRIM(COALESCE(city,''))) IN ($placeholders)"
);
$staged->execute(NOT_A_CITY);
$stagedRows = $staged->fetchAll(PDO::FETCH_ASSOC);

$stagedFixed = 0;
$stagedSkipped = 0;
foreach ($stagedRows as $row) {
    $parsed = $normalizer->parse((string) $row['address_raw']);
    if (trim($parsed['city']) === '' || in_array(strtoupper(trim($parsed['city'])), NOT_A_CITY, true)) {
        $stagedSkipped++;
        continue;
    }
    if ($apply) {
        $db->prepare('UPDATE member_import_row SET address1 = :a, city = :c, state = :s, zip = :z, country = :k WHERE id = :id')
           ->execute([
               ':a' => $parsed['address1'], ':c' => $parsed['city'], ':s' => $parsed['state'],
               ':z' => $parsed['zip'], ':k' => $parsed['country'], ':id' => (int) $row['id'],
           ]);
    }
    $stagedFixed++;
}

printf("Staged import rows with a country or province as the city: %d\n", count($stagedRows));
printf("  correctable from the original line: %d\n", $stagedFixed);
printf("  still not resolvable:               %d\n\n", $stagedSkipped);

// --- applied person records -------------------------------------------------

$people = $db->prepare(
    "SELECT per_ID, per_Address1, per_City, per_State, per_Zip, per_Country
       FROM person_per
      WHERE UPPER(TRIM(COALESCE(per_City,''))) IN ($placeholders)"
);
$people->execute(NOT_A_CITY);
$personRows = $people->fetchAll(PDO::FETCH_ASSOC);

$matchStaged = $db->prepare(
    "SELECT address_raw FROM member_import_row
      WHERE matched_person_id = :id AND TRIM(COALESCE(address_raw,'')) <> ''
      ORDER BY id DESC LIMIT 1"
);

$peopleFixed = 0;
$peopleSkipped = 0;
foreach ($personRows as $row) {
    $matchStaged->execute([':id' => (int) $row['per_ID']]);
    $raw = (string) ($matchStaged->fetchColumn() ?: '');

    if ($raw === '') {
        // No original to re-read. Rebuild the line from the pieces, dropping
        // the city that is known to be wrong, and let the parser try again.
        $raw = trim(implode(', ', array_filter([
            trim((string) $row['per_Address1']),
            trim((string) $row['per_State']) . ' ' . trim((string) $row['per_Zip']),
        ])));
    }

    $parsed = $normalizer->parse($raw);
    if (trim($parsed['city']) === '' || in_array(strtoupper(trim($parsed['city'])), NOT_A_CITY, true)) {
        $peopleSkipped++;
        continue;
    }
    if ($apply) {
        $db->prepare(
            'UPDATE person_per SET per_Address1 = :a, per_City = :c, per_State = :s, per_Zip = :z,
                    per_Country = :k, per_DateLastEdited = NOW()
              WHERE per_ID = :id'
        )->execute([
            ':a' => $parsed['address1'], ':c' => $parsed['city'], ':s' => $parsed['state'],
            ':z' => $parsed['zip'], ':k' => $parsed['country'], ':id' => (int) $row['per_ID'],
        ]);
    }
    $peopleFixed++;
}

printf("People with a country or province as the city: %d\n", count($personRows));
printf("  corrected: %d\n", $peopleFixed);
printf("  left alone, could not be resolved: %d\n\n", $peopleSkipped);

echo $apply
    ? "Applied.\n"
    : "Dry run — nothing was written. Pass --apply to save these corrections.\n";
