<?php

declare(strict_types=1);

/**
 * Printables hub — links to each printable/exportable view.
 *
 * @var string                $basePath
 * @var ?array<string,mixed>  $actor
 * @var array<string,mixed>   $campusSelector
 */

require_once __DIR__ . '/_printable-shell.php';

$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');

$body = static function () use ($base): string {
    $tile = static fn (string $href, string $title, string $desc, bool $soon = false): string =>
        '<a class="pr-tile' . ($soon ? ' soon' : '') . '" href="' . ($soon ? '#' : $href) . '">'
        . '<h3>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>'
        . '<p>' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<span class="cta">' . ($soon ? 'Coming soon' : 'Open &rsaquo;') . '</span></a>';

    return '<div class="pr-card"><div style="padding:16px">'
        . '<p style="margin:0 0 14px;font-size:13px;color:var(--muted,#627169)">For a wall calendar of the month you are looking at, open Calendar and choose <a href="' . $base . '/calendar/print-setup" style="color:var(--teal,#117b6d);font-weight:800">Print this view</a>.</p>'
        . '<div class="pr-tiles">'
        . $tile($base . '/printables/schedules', 'Schedule roster', 'Assigned members for selected dates & ministries — grouped by ministry.')
        . $tile($base . '/printables/events', 'Events', 'Upcoming events with dates — printable list.')
        . $tile($base . '/printables/birthdays', 'Birthdays', 'Members by birthday month — printable directory.')
        . '</div></div></div>';
};

echo printable_page([
    'basePath' => $basePath,
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'title' => 'Printables',
    'subtitle' => 'Printable and PDF-exportable listings — pick one to view, filter, and print.',
], $body);
