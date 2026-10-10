<?php
require_once __DIR__ . '/_admin-shell.php';
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);

$content = '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>External links &amp; references</h2><p>Curate URLs that should appear on the dashboard, footer, or member pages.</p></div>' . admin_section_status_badge('Planned') . '</div>'
    . '<div class="admin-card-body" style="color:var(--muted)">'
    . '<p>Today there is no centralised link manager. This section will manage:</p>'
    . '<ul style="margin:6px 0;padding-left:18px;line-height:1.7">'
    . '<li>Footer external links (privacy policy, ministry website, donate URL)</li>'
    . '<li>"Quick links" tile on the dashboard (Sermons, RSVP, Photos, etc.)</li>'
    . '<li>Per-ministry URLs (sub-site, photo album, sign-up form)</li>'
    . '<li>Social profiles (Facebook, Instagram, YouTube)</li>'
    . '</ul>'
    . '<p>State will live in <span class="code">config/links.json</span> on the same pattern as <span class="code">config/hero.json</span>, with a small UI here to add / edit / remove entries.</p>'
    . '</div></article>'

    . '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Reference docs</h2><p>Internal documentation, policies, and onboarding pages.</p></div>' . admin_section_status_badge('Planned') . '</div>'
    . '<div class="admin-card-body" style="color:var(--muted)">A future iteration may host markdown docs (<span class="code">docs/*.md</span>) and present them as searchable reference pages inside the portal — useful for new ministry leaders.</div>'
    . '</article>';

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'links',
    'pageTitle' => 'Site Links · Admin', 'pageSubtitle' => 'Manage external URLs, references.',
    'sectionTitle' => 'Site Links',
    'sectionDescription' => 'Curate external URLs, social references, and quick-link tiles. Placeholder section — implementation pending.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
