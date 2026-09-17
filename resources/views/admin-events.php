<?php
require_once __DIR__ . '/_admin-shell.php';
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$roadmap = $base . '/admin/roadmap';

$content = '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Configured events</h2><p>The full events list — includes occurrence counts and recurrence rules.</p></div>' . admin_section_status_badge('Live') . '</div>'
    . '<div class="admin-card-body">'
    . '<div class="tile-grid">'
    . '<a class="tile" href="' . $base . '/events"><h3>Events board</h3><p>Browse upcoming events.</p><span class="tile-cta">Open &rsaquo;</span></a>'
    . '<a class="tile" href="' . $base . '/events/new"><h3>New event</h3><p>Create an event with one or many occurrences.</p><span class="tile-cta">Open &rsaquo;</span></a>'
    . '</div></div></article>'

    . '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>List columns &amp; display behaviour</h2><p>Which columns and meta-fields appear on /events.</p></div>' . admin_section_status_badge('Planned') . '</div>'
    . '<div class="admin-card-body" style="color:var(--muted)">A planned editor will let admins toggle: occurrence count, next-occurrence date, ministry tags, badges. Today these are hard-coded in <span class="code">resources/views/events-list.php</span>. Tracked on the <a href="' . $roadmap . '">development roadmap</a> — not a working setting on this page.</div>'
    . '</article>'

    . '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Default filter range</h2><p>How far ahead /events looks by default (e.g. 30 days, 90 days, all upcoming).</p></div>' . admin_section_status_badge('Planned') . '</div>'
    . '<div class="admin-card-body" style="color:var(--muted)">Currently the API caps at the next 8 events. A planned config option will let admins set a default look-ahead window. Tracked on the <a href="' . $roadmap . '">development roadmap</a> — not a working setting on this page.</div>'
    . '</article>';

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'events',
    'pageTitle' => 'Events · Admin', 'pageSubtitle' => 'Events list & display behaviour.',
    'sectionTitle' => 'Events',
    'sectionDescription' => 'Manage configured events. List columns and the default look-ahead window are still on the roadmap.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
