#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Render a ministry schedule fixture to a standalone HTML file.
 *
 * Development and visual QA only. It never touches a database — it takes a
 * fixture, walks it through the same document model and the same template the
 * live route uses, and writes the result somewhere it can be looked at and
 * screenshotted.
 *
 * That is the point: the design is checked against the renderer that ships, not
 * against a mock-up of it.
 *
 *   php tools/render-ministry-schedule.php normal /tmp/normal.html
 *   php tools/render-ministry-schedule.php --all /tmp/out
 */

require __DIR__ . '/../app/Documents/MinistryScheduleDocument.php';
require __DIR__ . '/../app/Services/Ministry/ColumnBalancer.php';

use App\Documents\MinistryScheduleDocument;
use App\Services\Ministry\ColumnBalancer;

$fixtures = require __DIR__ . '/../tests/Fixtures/ministry-schedules.php';
$args = array_slice($argv, 1);

if ($args === [] || $args[0] === '--help') {
    fwrite(STDERR, "usage: render-ministry-schedule.php <fixture|--all> <out-file|out-dir>\n");
    fwrite(STDERR, "fixtures: " . implode(', ', array_keys($fixtures)) . "\n");
    exit(2);
}

/** Build the document a fixture describes, and write the sheet. */
function renderOne(array $case, string $name, string $path): void
{
    $stats = ['ministries' => count($case['sections']), 'assignments' => 0, 'volunteers' => 0, 'people' => 0];
    $distinct = [];
    foreach ($case['sections'] as $s) {
        foreach ($s['assignments'] as $a) {
            $stats['assignments']++;
            foreach ($a['people'] as $p) {
                $stats['people']++;
                $distinct[mb_strtolower($p['name'])] = true;
            }
        }
    }
    $stats['volunteers'] = count($distinct);

    $date = new DateTimeImmutable($case['date']);
    $document = new MinistryScheduleDocument(
        title: 'MINISTRY SCHEDULE',
        serviceDate: $date->format('Y-m-d'),
        serviceTitle: 'Sunday Worship Service',
        displayDate: strtoupper($date->format('F j, Y')),
        sections: $case['sections'],
        warnings: [],
        stats: $stats,
        sources: ['fixture'],
    );

    $columns = ColumnBalancer::distribute($document->sections, 3);
    $overflows = ColumnBalancer::overflows($columns);

    ob_start();
    require __DIR__ . '/../resources/views/print/ministry-schedule/classic.php';
    $sheet = (string) ob_get_clean();

    $css = (string) file_get_contents(__DIR__ . '/../resources/views/print/ministry-schedule/classic.css');
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&display=swap" rel="stylesheet">'
        . '<style>@page{size:Letter portrait;margin:0}'
        . 'html,body{margin:0;padding:0;background:#eef1f6}'
        . 'body{-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        . $css . '</style></head><body>' . $sheet . '</body></html>';

    file_put_contents($path, $html);
    printf("  %-16s -> %s  (%d sections, %d assignments%s)\n",
        $name, $path, count($case['sections']), $stats['assignments'],
        $overflows ? ', OVERFLOWS' : '');
}

if ($args[0] === '--all') {
    $dir = $args[1] ?? sys_get_temp_dir();
    @mkdir($dir, 0777, true);
    foreach ($fixtures as $name => $case) {
        renderOne($case, $name, rtrim($dir, '/') . '/' . $name . '.html');
    }
    exit(0);
}

$name = $args[0];
if (!isset($fixtures[$name])) {
    fwrite(STDERR, "unknown fixture: {$name}\n");
    exit(2);
}
renderOne($fixtures[$name], $name, $args[1] ?? (sys_get_temp_dir() . '/' . $name . '.html'));
