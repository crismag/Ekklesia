#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Campus member import from a Drive / Excel workbook.
 *
 * Reads .xlsx (Zip + mergeCell fill). CSV is refused unless --allow-csv because
 * CSV drops household merge ranges.
 *
 * The Hub / primary worksheet is enough for North York and Scarborough.
 * Secondary is optional fill-in only.
 *
 * Default is a dry parse or a DB match preview. person_per is not written
 * unless you ingest a staging batch and then --apply-batch with --confirm=APPLY.
 *
 * Usage:
 *   php tools/member-import.php --source=/tmp/members.xlsx --preset=ny --dump-json
 *   php tools/member-import.php --source=/tmp/scarborough.xlsx --preset=sc --dump-json
 *   php tools/member-import.php --source=file.xlsx --preset=ny --campus-id=3 --dry-run
 *   php tools/member-import.php --source=file.xlsx --preset=ny --campus-id=3 --ingest
 *   php tools/member-import.php --apply-batch=12 --confirm=APPLY
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

use App\Providers\PortalServiceProvider;
use App\Services\MemberSheetMerger;
use App\Services\MemberWorkbookParser;

$opts = getopt('', [
    'source:', 'google:', 'preset:', 'primary:', 'secondary:',
    'campus-id:', 'instructions:', 'dump-json', 'dry-run', 'ingest',
    'apply-batch:', 'confirm:', 'allow-csv', 'help',
]);

if ($opts === false || isset($opts['help'])) {
    fwrite(STDOUT, (string) preg_replace('/^<\?php.*?\*\//s', '', (string) file_get_contents(__FILE__)));
    exit(isset($opts['help']) ? 0 : 2);
}

$parser = new MemberWorkbookParser();
$merger = new MemberSheetMerger($parser);

$preset = strtolower(trim((string) ($opts['preset'] ?? '')));
$primary = trim((string) ($opts['primary'] ?? ''));
$secondary = array_key_exists('secondary', $opts) ? trim((string) $opts['secondary']) : '';
if ($preset !== '' && isset(MemberWorkbookParser::PRESETS[$preset])) {
    $primary = $primary !== '' ? $primary : MemberWorkbookParser::PRESETS[$preset]['primary'];
    if (!array_key_exists('secondary', $opts)) {
        $secondary = '';
    }
}
if ($primary === '') {
    $primary = MemberWorkbookParser::HUB_SHEET;
}

$source = trim((string) ($opts['source'] ?? ''));
$google = trim((string) ($opts['google'] ?? ''));
$cleanup = null;
if ($google !== '') {
    $imp = PortalServiceProvider::makeMemberCampusImportService();
    $source = $imp->downloadGoogleSheet($google);
    $cleanup = $source;
}

$dumpJson = isset($opts['dump-json']);
$dryRun = isset($opts['dry-run']);
$doIngest = isset($opts['ingest']);
$applyBatch = isset($opts['apply-batch']) ? (int) $opts['apply-batch'] : 0;
$allowCsv = isset($opts['allow-csv']);
$campusId = isset($opts['campus-id']) ? (int) $opts['campus-id'] : 0;
$confirm = (string) ($opts['confirm'] ?? '');

try {
    if ($applyBatch > 0) {
        if ($confirm !== 'APPLY') {
            fwrite(STDERR, "Refusing to write person_per. Re-run with --confirm=APPLY to apply staging batch #{$applyBatch}.\n");
            exit(2);
        }
        $imp = PortalServiceProvider::makeMemberCampusImportService();
        $result = $imp->applyBatch($applyBatch, 0);
        echo json_encode(['applied' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    if ($source === '') {
        fwrite(STDERR, "Provide --source=file.xlsx (or --google=SHEET_URL).\n");
        exit(2);
    }
    if ($parser->isCsvPath($source) && !$allowCsv) {
        fwrite(STDERR, MemberWorkbookParser::CSV_MERGE_WARNING . "\n");
        exit(2);
    }

    if ($dumpJson) {
        $parsed = $parser->parseWorkbook($source, $primary, $secondary);
        $merged = $merger->merge($parsed['hub']['rows'] ?? [], $parsed['ny']['rows'] ?? []);
        $deduper = new \App\Services\MemberImportDeduper($parser);
        $deduped = $deduper->dedupe($merged);
        $merged = $deduped['rows'];
        $out = [
            'sheets' => $parsed['sheets'],
            'primary' => $parsed['hub']['sheet'] ?? null,
            'secondary' => $parsed['ny']['sheet'] ?? null,
            'warnings' => array_merge($parsed['warnings'], $deduped['warnings']),
            'duplicates_removed' => $deduped['removed'],
            'row_count' => count($merged),
            'rows' => array_map(static function (array $r): array {
                return [
                    'last_name' => $r['last_name'],
                    'first_name' => $r['first_name'],
                    'email' => $r['email'],
                    'phone' => $r['phone'],
                    'address_raw' => $r['address_raw'],
                    'member_type' => $r['member_type'],
                    'status' => $r['status'],
                    'source' => $r['source'],
                ];
            }, $merged),
        ];
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    if ($campusId <= 0) {
        fwrite(STDERR, "--campus-id is required for --dry-run or --ingest.\n");
        exit(2);
    }
    $imp = PortalServiceProvider::makeMemberCampusImportService();
    if ($doIngest) {
        $result = $imp->ingest($source, $campusId, 0, $primary, $secondary, basename($source), $allowCsv);
        echo json_encode([
            'ingested' => true,
            'batch_id' => $result['batch']['id'] ?? null,
            'counts' => $result['counts'],
            'warnings' => $result['warnings'],
            'next' => 'Review staging in /admin/maintenance/import then apply, or: php tools/member-import.php --apply-batch=ID --confirm=APPLY',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    $preview = $imp->preview($source, $campusId, $primary, $secondary);
    echo json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    fwrite(STDERR, sprintf(
        "Dry run only. Would create %d, update %d, unlink %d campus members. No person_per writes.\n",
        $preview['plan']['create'],
        $preview['plan']['update'],
        $preview['plan']['remove']
    ));
} finally {
    if (is_string($cleanup) && $cleanup !== '' && is_file($cleanup)) {
        @unlink($cleanup);
    }
}
