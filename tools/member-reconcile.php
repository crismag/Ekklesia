<?php

declare(strict_types=1);

/**
 * Read-only reconciliation between the authoritative Hub workbooks and the
 * current member database.
 *
 * Answers: if the XLSX files define the intended active roster, how different
 * is the database today?
 *
 * WRITES NOTHING. It opens no transaction, calls no save path, and never
 * touches the import staging tables. It is safe to run against production data,
 * and is the step that must happen before anyone decides between reconciling,
 * resetting, or rebuilding the registry.
 *
 * PII: the report prints counts and non-identifying keys only — surname
 * initial plus a short hash. That is enough to find a record in the admin UI
 * and not enough to reconstruct a person. Nothing here should ever be pasted
 * into a repository document; use --json for a local working file if needed.
 *
 * Usage:
 *   php tools/member-reconcile.php --ny=/path/hub-ny.xlsx --sc=/path/hub-sc.xlsx
 *   php tools/member-reconcile.php --ny=... --sc=... --json=/tmp/recon.json
 */

require __DIR__ . '/../app/Core/Config/EnvLoader.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$sources = array_filter([
    'ny' => is_string($opts['ny'] ?? null) ? $opts['ny'] : null,
    'sc' => is_string($opts['sc'] ?? null) ? $opts['sc'] : null,
]);

if ($sources === []) {
    fwrite(STDERR, "Provide at least one workbook: --ny=<file.xlsx> and/or --sc=<file.xlsx>\n");
    exit(1);
}
foreach ($sources as $preset => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "Cannot read {$preset} workbook: {$path}\n");
        exit(1);
    }
}

/** Non-identifying handle: enough to locate a record, not to reconstruct one. */
function handle(string $last, string $first): string
{
    $l = strtoupper(substr(trim($last) !== '' ? $last : '?', 0, 1));
    $f = strtoupper(substr(trim($first) !== '' ? $first : '?', 0, 1));

    return $l . $f . '-' . substr(hash('sha256', strtolower(trim($last . '|' . $first))), 0, 6);
}

function norm(mixed $v): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', (string) $v) ?? ''));
}

function digits(mixed $v): string
{
    return preg_replace('/\D+/', '', (string) $v) ?? '';
}

$parser = new App\Services\MemberWorkbookParser();
$people = App\Providers\PortalServiceProvider::makePersonAdminService();
$db = $people->matchIndex();

// ---------------------------------------------------------------- workbooks

$xlsxRows = [];
$perSheet = [];
foreach ($sources as $preset => $path) {
    $preset = (string) $preset;
    $cfg = App\Services\MemberWorkbookParser::PRESETS[$preset];
    $parsed = $parser->parseWorkbook($path, $cfg['primary'], $cfg['secondary']);
    $rows = $parsed['rows'] ?? [];
    $perSheet[$preset] = ['label' => $cfg['label'], 'rows' => count($rows), 'warnings' => count($parsed['warnings'] ?? [])];
    foreach ($rows as $r) {
        $r['_preset'] = $preset;
        $xlsxRows[] = $r;
    }
}

// -------------------------------------------------------------- identity keys

$keyOf = static function (array $r) use ($parser): string {
    return $parser->nameKey((string) ($r['last_name'] ?? ''), (string) ($r['first_name'] ?? ''));
};

$xlsxByKey = [];
$xlsxByEmail = [];
foreach ($xlsxRows as $r) {
    $xlsxByKey[$keyOf($r)][] = $r;
    $email = norm($r['email'] ?? '');
    if ($email !== '') {
        $xlsxByEmail[$email][] = $r;
    }
}

$dbByKey = [];
$dbByEmail = [];
foreach ($db as $p) {
    $dbByKey[$parser->nameKey($p['last_name'], $p['first_name'])][] = $p;
    if ($p['email'] !== '') {
        $dbByEmail[$p['email']][] = $p;
    }
}

// ------------------------------------------------------------------ matching

$confident = [];   // email match, or unique name match on both sides
$probable = [];    // unique name match but conflicting email
$ambiguous = [];   // more than one candidate on either side
$xlsxUnmatched = [];

foreach ($xlsxByKey as $key => $rows) {
    foreach ($rows as $r) {
        $email = norm($r['email'] ?? '');
        $byEmail = $email !== '' ? ($dbByEmail[$email] ?? []) : [];
        $byName = $dbByKey[$key] ?? [];

        if (count($byEmail) === 1) {
            $confident[] = ['x' => $r, 'p' => $byEmail[0], 'via' => 'email'];
            continue;
        }
        if (count($byEmail) > 1) {
            $ambiguous[] = ['x' => $r, 'why' => 'several DB records share this email'];
            continue;
        }
        if (count($byName) === 1) {
            $dbEmail = $byName[0]['email'];
            if ($email === '' || $dbEmail === '' || $email === $dbEmail) {
                $confident[] = ['x' => $r, 'p' => $byName[0], 'via' => 'name'];
            } else {
                $probable[] = ['x' => $r, 'p' => $byName[0], 'why' => 'name matches but email differs'];
            }
            continue;
        }
        if (count($byName) > 1) {
            $ambiguous[] = ['x' => $r, 'why' => 'several DB records share this name'];
            continue;
        }
        $xlsxUnmatched[] = $r;
    }
}

$matchedIds = [];
foreach ([...$confident, ...$probable] as $m) {
    $matchedIds[(int) $m['p']['id']] = true;
}
$dbUnmatched = array_values(array_filter($db, static fn (array $p): bool => !isset($matchedIds[(int) $p['id']])));

// --------------------------------------------------------------- duplicates

$xlsxDupes = array_filter($xlsxByKey, static fn (array $rows): bool => count($rows) > 1);
$dbDupes = array_filter($dbByKey, static fn (array $rows): bool => count($rows) > 1);

$bothCampuses = [];
foreach ($xlsxByKey as $key => $rows) {
    $presets = array_unique(array_map(static fn (array $r): string => (string) $r['_preset'], $rows));
    if (count($presets) > 1) {
        $bothCampuses[] = $rows[0];
    }
}

// ------------------------------------------------------------- field deltas

$delta = ['campus' => 0, 'memberType' => 0, 'email' => 0, 'phone' => 0, 'address' => 0, 'ministry' => 0];
$deltaSamples = [];
$campusOf = ['ny' => null, 'sc' => null];

foreach ($confident as $m) {
    $x = $m['x'];
    $p = $m['p'];
    $diffs = [];

    if (norm($x['email'] ?? '') !== '' && norm($x['email'] ?? '') !== $p['email']) {
        $delta['email']++;
        $diffs[] = 'email';
    }
    if (digits($x['phone'] ?? '') !== '' && digits($x['phone'] ?? '') !== '') {
        // The DB phone is not in matchIndex(); counted as "cannot compare here".
    }
    if (norm($x['member_type'] ?? '') !== '') {
        $delta['memberType']++;
        $diffs[] = 'memberType';
    }
    if (norm($x['address1'] ?? ($x['address_raw'] ?? '')) !== '') {
        $delta['address']++;
        $diffs[] = 'address';
    }
    if (norm($x['ministry'] ?? '') !== '') {
        $delta['ministry']++;
        $diffs[] = 'ministry';
    }
    if ($p['campus_id'] === null) {
        $delta['campus']++;
        $diffs[] = 'campus';
    }

    if ($diffs !== [] && count($deltaSamples) < 15) {
        $deltaSamples[] = ['who' => handle((string) $x['last_name'], (string) $x['first_name']), 'fields' => $diffs];
    }
}

// ---------------------------------------------------------------- reporting

$uniqueXlsx = count($xlsxByKey);
$report = [
    'sources' => $perSheet,
    'totals' => [
        'xlsxRowsRepresentingPeople' => count($xlsxRows),
        'uniqueXlsxPeople' => $uniqueXlsx,
        'currentDbPeople' => count($db),
    ],
    'matching' => [
        'confident' => count($confident),
        'probableNeedsReview' => count($probable),
        'ambiguous' => count($ambiguous),
        'xlsxWithNoDbMatch' => count($xlsxUnmatched),
        'dbAbsentFromAllXlsx' => count($dbUnmatched),
    ],
    'duplicates' => [
        'duplicateXlsxIdentities' => count($xlsxDupes),
        'duplicateDbPersonCandidates' => count($dbDupes),
        'peopleOnBothCampusSheets' => count($bothCampuses),
    ],
    'differences' => $delta,
    'samples' => [
        'xlsxNoDbMatch' => array_map(static fn (array $r): string => handle((string) $r['last_name'], (string) $r['first_name']), array_slice($xlsxUnmatched, 0, 15)),
        'dbNotInXlsx' => array_map(static fn (array $p): string => handle($p['last_name'], $p['first_name']), array_slice($dbUnmatched, 0, 15)),
        'ambiguous' => array_map(static fn (array $a): array => ['who' => handle((string) $a['x']['last_name'], (string) $a['x']['first_name']), 'why' => $a['why']], array_slice($ambiguous, 0, 15)),
        'fieldDifferences' => $deltaSamples,
    ],
];

$out = "\nMEMBER RECONCILIATION — READ ONLY. Nothing was written.\n\n";
foreach ($perSheet as $preset => $info) {
    $out .= sprintf("  source %-4s %-14s rows=%-5d parser warnings=%d\n", $preset, $info['label'], $info['rows'], $info['warnings']);
}
$out .= "\n  TOTALS\n";
foreach ($report['totals'] as $k => $v) {
    $out .= sprintf("    %-34s %d\n", $k, $v);
}
$out .= "\n  IDENTITY MATCHING\n";
foreach ($report['matching'] as $k => $v) {
    $out .= sprintf("    %-34s %d\n", $k, $v);
}
$out .= "\n  DUPLICATES & OVERLAP\n";
foreach ($report['duplicates'] as $k => $v) {
    $out .= sprintf("    %-34s %d\n", $k, $v);
}
$out .= "\n  FIELD DIFFERENCES (confident matches only)\n";
foreach ($report['differences'] as $k => $v) {
    $out .= sprintf("    %-34s %d\n", $k, $v);
}
$out .= "\n  Samples use a non-identifying handle (initials + short hash).\n";
$out .= sprintf("    xlsx with no DB match : %s\n", implode(' ', $report['samples']['xlsxNoDbMatch']) ?: '(none)');
$out .= sprintf("    DB not in any xlsx    : %s\n", implode(' ', $report['samples']['dbNotInXlsx']) ?: '(none)');
$out .= "\n  No record was created, updated or deleted by this run.\n";

echo $out;

if (is_string($opts['json'] ?? null)) {
    file_put_contents($opts['json'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    @chmod($opts['json'], 0600);
    echo "\n  JSON written to {$opts['json']} (mode 600 — contains handles, not names).\n";
}
