<?php

declare(strict_types=1);

/**
 * Fill in household roles — husband, wife, child — where the household says so
 * plainly.
 *
 *   php tools/suggest-household-roles.php                    # report only
 *   php tools/suggest-household-roles.php --apply            # write the certain ones
 *   php tools/suggest-household-roles.php --apply --likely   # and the likely ones too
 *
 * Kinship cannot be derived in general (docs/design/27-family-model.md), which
 * is why 292 of 306 people have no family role. But a subset is unambiguous —
 * one address, one surname, exactly two adults of different sex — and clearing
 * that subset is what makes entering the rest bearable.
 *
 * Writes per_fmr_ID, the family role ChurchCRM already has, and only where it
 * is empty. A role somebody entered is never contradicted.
 *
 * Counts and reasons are printed; names are member data and stay out of the
 * terminal.
 */

$root = dirname(__DIR__);
require_once $root . '/app/Services/HouseholdRoleSuggester.php';

use App\Services\HouseholdRoleSuggester;

$apply = in_array('--apply', $argv, true);
// "Likely" means the likeliest reading of a household that could be read
// another way — a couple whose surnames differ, or the oldest two of three
// adults. Separated from "certain" so the safe pass can run on its own.
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

// Role name to id, read rather than assumed: the list is administrator-edited.
$roleId = [];
foreach ($db->query('SELECT lst_OptionID id, lst_OptionName n FROM list_lst WHERE lst_ID = 2') as $r) {
    $roleId[strtolower(trim((string) $r['n']))] = (int) $r['id'];
}
foreach ([HouseholdRoleSuggester::HUSBAND, HouseholdRoleSuggester::WIFE, HouseholdRoleSuggester::CHILD] as $needed) {
    if (!isset($roleId[strtolower($needed)])) {
        fwrite(STDERR, "There is no family role called \"{$needed}\". Add it before running this.\n");
        exit(1);
    }
}

// The family record is the household.
//
// This grouped by address at first, which excluded every family with no
// address recorded — one of them being a couple with two children, exactly the
// case these rules exist for, sitting there with nothing filled in. It also
// merged separate families at one address into a single "household", which is
// how two unrelated adults get read as a couple.
//
// A family_fam row already asserts a household. Use it, and leave families
// split across two records to the relationship graph, where they belong.
$rows = $db->query(
    "SELECT pp.per_ID, pp.per_LastName, COALESCE(pp.per_Gender, 0) AS gender,
            COALESCE(mt.lst_OptionName, '') AS member_type,
            COALESCE(fr.lst_OptionName, '') AS role,
            COALESCE(pp.per_BirthYear, 0) AS birth_year,
            pp.per_fam_ID AS household
       FROM person_per pp
       JOIN family_fam f ON f.fam_ID = pp.per_fam_ID
       LEFT JOIN person_custom pc ON pc.per_ID = pp.per_ID
       LEFT JOIN list_lst mt ON mt.lst_ID = 13 AND mt.lst_OptionID = pc.c1
       LEFT JOIN list_lst fr ON fr.lst_ID = 2  AND fr.lst_OptionID = pp.per_fmr_ID
      WHERE COALESCE(pp.per_fam_ID, 0) > 0"
)->fetchAll(PDO::FETCH_ASSOC);

$households = [];
foreach ($rows as $r) {
    $households[(int) $r['household']][] = [
        'person_id' => (int) $r['per_ID'],
        'last_name' => (string) $r['per_LastName'],
        'gender' => (int) $r['gender'],
        'member_type' => (string) $r['member_type'],
        'role' => (string) $r['role'],
        'birth_year' => (int) $r['birth_year'],
    ];
}

$suggester = new HouseholdRoleSuggester();
$reasons = [];
$byRule = [];
$all = [];

foreach ($households as $members) {
    $result = $suggester->forHousehold($members);
    if ($result['skipped'] !== null) {
        $reasons[$result['skipped']] = ($reasons[$result['skipped']] ?? 0) + 1;
        continue;
    }
    foreach ($result['suggestions'] as $suggestion) {
        $key = $suggestion['confidence'] . '/' . $suggestion['rule'];
        $byRule[$key][$suggestion['role']] = ($byRule[$key][$suggestion['role']] ?? 0) + 1;
        $all[] = $suggestion;
    }
}

$rules = [
    'certain/A' => 'one surname, two adults of different sex',
    'certain/C' => 'recorded as a child, living with adults',
    'likely/B'  => 'two adults of different sex with children, surnames differ',
    'likely/D'  => 'oldest two of three or more adults, a generation above the rest',
];

printf("Households (family records with at least one person): %d\n\n", count($households));
foreach ($rules as $key => $description) {
    if (!isset($byRule[$key])) {
        continue;
    }
    [$confidence, $rule] = explode('/', $key);
    printf("  %-8s rule %s — %s\n", strtoupper($confidence), $rule, $description);
    foreach ($byRule[$key] as $role => $n) {
        printf("      %-8s %d\n", $role, $n);
    }
}

$certain = array_values(array_filter($all, static fn (array $x): bool => $x['confidence'] === HouseholdRoleSuggester::CERTAIN));
$likely = array_values(array_filter($all, static fn (array $x): bool => $x['confidence'] === HouseholdRoleSuggester::LIKELY));
printf("\n  certain: %d role(s)    likely: %d role(s)\n\n", count($certain), count($likely));

echo "  nothing suggested, because:\n";
arsort($reasons);
foreach ($reasons as $why => $n) {
    printf("      %-62s %d\n", $why, $n);
}

$writes = $includeLikely ? $all : $certain;

if (!$apply) {
    printf(
        "\nDry run — nothing was written. --apply writes the %d certain role(s); add --likely for %d more.\n",
        count($certain),
        count($likely),
    );
    exit(0);
}

$set = $db->prepare('UPDATE person_per SET per_fmr_ID = :r, per_DateLastEdited = NOW() WHERE per_ID = :id AND COALESCE(per_fmr_ID, 0) = 0');
$written = 0;
foreach ($writes as $s) {
    $set->execute([':r' => $roleId[strtolower($s['role'])], ':id' => $s['person_id']]);
    $written += $set->rowCount();
}
printf(
    "\nApplied %s. %d role(s) written; %d already had one and were left alone.\n",
    $includeLikely ? 'certain and likely' : 'the certain ones only',
    $written,
    count($writes) - $written,
);
