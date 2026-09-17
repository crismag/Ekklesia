<?php
/**
 * @var string                $basePath
 * @var ?array<string, mixed> $actor
 * @var array<string, mixed>  $campusSelector
 *
 * Settings & Admin overview — the entry point for the central control panel.
 * Each linked section is a standalone /admin/{slug} page sharing the same
 * sidebar via _admin-shell.php.
 *
 * This page is records, site and access — not My Pages, not env dumps.
 * Diagnostics live under Advanced → System information.
 */
require_once __DIR__ . '/_admin-shell.php';

$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);

$tiles = [
    ['title' => 'Dashboard',          'href' => $base . '/admin/dashboard',  'desc' => 'Home layout, guest hero, church announcements.', 'badge' => 'Live'],
    ['title' => 'Maintenance',        'href' => $base . '/admin/maintenance', 'desc' => 'Backups, archives, member import and Excel export.', 'badge' => 'Live'],
    ['title' => 'Ministry list',      'href' => $base . '/admin/ministries', 'desc' => 'Which ministry pages appear on the public site.', 'badge' => 'Beta'],
    ['title' => 'Members & leaders',  'href' => $base . '/admin/groups-and-ministries', 'desc' => 'Create teams; manage members, roles, and leaders.', 'badge' => 'Live'],
    ['title' => 'Calendar',           'href' => $base . '/admin/calendar',   'desc' => 'Source list, custom rules, default views.', 'badge' => 'Live'],
    ['title' => 'Events',             'href' => $base . '/admin/events',     'desc' => 'Configured events. List columns and look-ahead remain on the roadmap.', 'badge' => 'Beta'],
    ['title' => 'Member records',     'href' => $base . '/admin/people',     'desc' => 'Add, find and edit person records. The top-bar People tab is look-up only.', 'badge' => 'Beta'],
    ['title' => 'Theme',              'href' => $base . '/admin/theme',      'desc' => 'Color palette, density, presets — Forest, Compact, Facebook, Minimalist, Warm.', 'badge' => 'Live'],
    ['title' => 'Header',             'href' => $base . '/admin/header',     'desc' => 'Brand text, primary navigation, uniformity.', 'badge' => 'Live'],
    ['title' => 'Footer',             'href' => $base . '/admin/footer',     'desc' => 'Footer text, minimal mode toggle.', 'badge' => 'Live'],
    ['title' => 'Documentation',      'href' => $base . '/docs',             'desc' => 'User guide for leaders and members — schedules, events, people, FAQ.', 'badge' => 'Live'],
];

$body = function () use ($actor, $isAdmin, $tiles, $base): string {
    if ($actor === null) {
        // Signed out: a prompt WITH an action, and no section map (spec §2.5).
        return '<article class="admin-card"><div class="admin-card-body">'
            . pc_signed_out($base, 'and manage portal settings')
            . '</div></article>';
    }
    $tilesHtml = '';
    foreach ($tiles as $t) {
        $tilesHtml .= sprintf(
            '<a class="tile" href="%s"><h3>%s %s</h3><p>%s</p><span class="tile-cta">Open &rsaquo;</span></a>',
            htmlspecialchars($t['href'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'),
            admin_section_status_badge($t['badge']),
            htmlspecialchars($t['desc'], ENT_QUOTES, 'UTF-8'),
        );
    }
    $adminBadge = $isAdmin ? '<span class="admin-status admin-status-live">portal admin</span>' : '<span class="admin-status admin-status-planned">standard user</span>';
    return <<<HTML
<article class="admin-card">
    <div class="admin-card-head">
        <div>
            <h2>Welcome to the control panel</h2>
            <p>Pick a section from the sidebar or one of the tiles below. Sections you don't have permission to save in stay browsable in read-only mode. Diagnostics live under Advanced → System information.</p>
        </div>
        $adminBadge
    </div>
    <div class="admin-card-body">
        <div class="tile-grid">$tilesHtml</div>
    </div>
</article>
HTML;
};

echo admin_render_page([
    'basePath' => $basePath,
    'activeId' => 'overview',
    'pageTitle' => 'Settings & Admin',
    'pageSubtitle' => 'Central control panel',
    'sectionTitle' => 'Settings & Admin',
    'sectionDescription' => 'Configure portal behaviour, people-facing content, and access — all in one place.',
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'isAdmin' => $isAdmin,
], $body);
