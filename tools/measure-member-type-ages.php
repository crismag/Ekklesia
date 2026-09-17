<?php

declare(strict_types=1);

/**
 * Measure how member types actually distribute across ages, and rewrite
 * config/member-type-age-bands.json from what is found.
 *
 *   php tools/measure-member-type-ages.php            # report only
 *   php tools/measure-member-type-ages.php --write    # rewrite the config
 *
 * The bands exist to suggest a member type for somebody who has none. They are
 * only worth trusting while they still describe the roster, and a roster
 * changes — so this recomputes them rather than leaving a snapshot to go quietly
 * out of date.
 *
 * Read-only against the database unless --write is given, and --write only ever
 * touches the config file. It changes nobody's record.
 */

$root = dirname(__DIR__);
require_once $root . '/app/Services/MemberTypeSuggester.php';

const BANDS = [[0, 12], [13, 17], [18, 25], [26, 39], [40, 130]];
const MIN_CONFIDENCE = 0.7;

$write = in_array('--write', $argv, true);

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

$rows = $db->query(
    "SELECT mt.lst_OptionName AS type, YEAR(CURDATE()) - pp.per_BirthYear AS age
       FROM person_per pp
       JOIN person_custom pc ON pc.per_ID = pp.per_ID
       JOIN list_lst mt ON mt.lst_ID = 13 AND mt.lst_OptionID = pc.c1
      WHERE pp.per_BirthYear > 1900"
)->fetchAll(PDO::FETCH_ASSOC);

printf("%d people have both a birth year and a member type.\n\n", count($rows));

$bands = [];
printf("  %-9s %-14s %8s %8s   %s\n", 'age', 'suggests', 'matching', 'total', 'confidence');
foreach (BANDS as [$from, $to]) {
    $inBand = array_filter($rows, static fn (array $r): bool => (int) $r['age'] >= $from && (int) $r['age'] <= $to);
    $total = count($inBand);
    if ($total === 0) {
        printf("  %-9s %-14s %8s %8s   %s\n", $from . '-' . $to, '(nobody)', '-', 0, '-');
        continue;
    }
    $counts = array_count_values(array_map(static fn (array $r): string => (string) $r['type'], $inBand));
    arsort($counts);
    $type = (string) array_key_first($counts);
    $matching = $counts[$type];
    $confidence = $matching / $total;

    printf(
        "  %-9s %-14s %8d %8d   %5.1f%%%s\n",
        $from . '-' . $to,
        $type,
        $matching,
        $total,
        $confidence * 100,
        $confidence < MIN_CONFIDENCE ? '  (too weak — will suggest nothing)' : '',
    );

    $bands[] = [
        'from' => $from,
        'to' => $to,
        'type' => $type,
        'observed' => ['matching' => $matching, 'total' => $total],
    ];
}

if (!$write) {
    echo "\nReport only. Pass --write to update config/member-type-age-bands.json.\n";
    exit(0);
}

$path = $root . '/config/member-type-age-bands.json';
$existing = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
$payload = [
    '_comment' => $existing['_comment'] ?? 'Age bands used to SUGGEST a member type for a person who has none.',
    '_measured_on' => date('Y-m-d'),
    '_measured_from' => count($rows) . ' people with both a birth year and a member type',
    'bands' => $bands,
    '_minimum_confidence' => MIN_CONFIDENCE,
];
file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("\nWrote %s\n", $path);
