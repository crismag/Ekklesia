<?php

declare(strict_types=1);

/**
 * Planned capabilities, collected.
 *
 * Previously each unbuilt feature carried its own card inside a working
 * section, and one — Site Links — held a permanent sidebar slot for a page
 * whose only content was a description of future work. Counted across the admin
 * area that was 13 PLANNED and 5 BETA against 20 LIVE, so roughly a third of
 * what primary navigation advertised did not exist.
 *
 * Gathering them here keeps them discoverable and honest without letting them
 * compete with tools that work. Nothing here is implemented to fill a gap.
 *
 * @var string                   $basePath
 * @var array<string,mixed>|null $actor
 */

require_once __DIR__ . '/_admin-shell.php';

$base = $basePath;
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$planned = [
    [
        'area' => 'Front page & content',
        'items' => [
            ['title' => 'Continue Working tiles', 'desc' => 'Reorder, rename and toggle the shortcut cards. Home now shows a personal hub, so this is only worth building if the shortcuts need to differ per church.'],
            ['title' => 'Personal notes', 'desc' => 'A private scratch area on a member’s own page. Needs storage and a retention decision before it is worth starting.'],
            ['title' => 'Profile photo upload', 'desc' => 'Members uploading their own portrait. Needs storage, size limits and a moderation position.'],
        ],
    ],
    [
        'area' => 'Site links & references',
        'items' => [
            ['title' => 'External links manager', 'desc' => 'Curated URLs for the footer, dashboard quick links, and member pages — privacy policy, donate link, ministry websites.'],
            ['title' => 'Reference documents', 'desc' => 'A place to publish policies and forms without editing views.'],
        ],
    ],
    [
        'area' => 'Calendar & events',
        'items' => [
            ['title' => 'Default calendar view', 'desc' => 'Which view and campus filter a first-time visitor lands on. Today the portal remembers each person’s own last choice, which covers most of the need.'],
            ['title' => 'Events list columns', 'desc' => 'Which columns and meta-fields appear on the events list.'],
            ['title' => 'Default filter range', 'desc' => 'How far forward the events list looks by default.'],
        ],
    ],
    [
        'area' => 'Ministries',
        'items' => [
            ['title' => 'Default ministry visibility', 'desc' => 'Whether a newly created ministry is public by default.'],
            ['title' => 'Ministry dashboard content', 'desc' => 'Which panels appear on a ministry workspace, per ministry.'],
        ],
    ],
];

ob_start();
?>
<style>
    .rm-note{padding:12px 14px;border:1px solid var(--line);border-radius:var(--radius,10px);
        background:var(--soft);font-size:13px;color:var(--ink);margin-bottom:14px}
    .rm-area{margin-bottom:16px}
    .rm-area h3{margin:0 0 8px;font-size:14px}
    .rm-item{padding:10px 12px;border:1px solid var(--line);border-radius:var(--radius,10px);
        background:var(--paper);margin-bottom:6px}
    .rm-item strong{display:flex;align-items:center;gap:8px;font-size:13px;flex-wrap:wrap}
    .rm-item p{margin:3px 0 0;font-size:12px;color:var(--muted)}
</style>
<?php if (!$isAdmin): ?>
    <div class="ek-card"><div class="ek-card-body">The development roadmap is for portal-wide administrators.</div></div>
<?php else: ?>
    <section class="ek-card">
        <div class="ek-card-head"><div><h2>Not built yet</h2><p>Everything the admin area used to advertise as planned, in one place.</p></div></div>
        <div class="ek-card-body">
            <div class="rm-note">
                These are not in progress and none of them blocks anything today. They are listed so
                the working parts of the admin area are not diluted by descriptions of future work.
                Each needs a product decision before it is worth building.
            </div>
            <?php foreach ($planned as $group): ?>
                <div class="rm-area">
                    <h3><?= $e($group['area']) ?></h3>
                    <?php foreach ($group['items'] as $item): ?>
                        <div class="rm-item">
                            <strong><?= $e($item['title']) ?><?= admin_section_status_badge('planned') ?></strong>
                            <p><?= $e($item['desc']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath,
    'activeId' => 'roadmap',
    'pageTitle' => 'Development roadmap',
    'pageSubtitle' => 'Planned capabilities, gathered out of the working sections',
    'sectionTitle' => 'Development roadmap',
    'sectionDescription' => 'What the admin area does not do yet, stated once rather than scattered.',
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'isAdmin' => $isAdmin,
], static fn (): string => $content);
