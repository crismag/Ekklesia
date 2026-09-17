<?php

declare(strict_types=1);

/**
 * Admin control board.
 *
 * Replaces a page whose entire unique contribution was one link (/admin/hero) —
 * everything else on it already sat in the permanently visible sidebar, and the
 * Overview page repeated 12 of its 13 tiles again. A dashboard that lists the
 * sections you are already looking at is a menu for a menu.
 *
 * This board answers a different question: what is the state of the site, what
 * needs attention today, and where is every configurable thing. The Overview
 * page's environment snapshot is folded in here too, so there is one landing
 * screen rather than two overlapping ones.
 *
 * @var string                    $basePath
 * @var array<string,mixed>|null  $actor
 * @var array<string,mixed>       $dashboard
 */

require_once __DIR__ . '/_admin-shell.php';

$base = $basePath;
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ago = static function (?int $ts) use ($e): string {
    if (!$ts) {
        return '—';
    }
    $d = max(0, time() - $ts);
    if ($d < 3600) {
        return (int) ($d / 60) . ' min ago';
    }
    if ($d < 86400) {
        return (int) ($d / 3600) . ' h ago';
    }

    return (int) ($d / 86400) . ' d ago';
};

/** Every manageable area, grouped so that small single-purpose editors stop
 *  competing with major sections for a top-level slot. */
$groups = [
    [
        'title' => 'Front page',
        'desc' => 'What a member sees first.',
        'items' => [
            ['label' => 'Hero rotator', 'desc' => 'Slides shown to signed-out visitors — kicker, title, rotation.', 'href' => $base . '/admin/hero', 'badge' => 'live'],
            ['label' => 'Announcements', 'desc' => 'Church-wide notes on the home sidebar.', 'href' => $base . '/admin/announcements', 'badge' => 'live'],
        ],
    ],
    [
        'title' => 'Site chrome & appearance',
        'desc' => 'Applies to every page.',
        'items' => [
            ['label' => 'Theme', 'desc' => 'Colour preset, radius and spacing.', 'href' => $base . '/admin/theme', 'badge' => 'live'],
            ['label' => 'Header', 'desc' => 'Brand text and primary navigation.', 'href' => $base . '/admin/header', 'badge' => 'live'],
            ['label' => 'Footer', 'desc' => 'Left and right footer text.', 'href' => $base . '/admin/footer', 'badge' => 'live'],
        ],
    ],
    [
        'title' => 'People & data',
        'desc' => 'The directory and everything that feeds it.',
        'items' => [
            ['label' => 'People', 'desc' => 'Directory, search, edit, photos.', 'href' => $base . '/admin/people', 'badge' => 'live'],
            ['label' => 'Families', 'desc' => 'Households and addresses.', 'href' => $base . '/admin/families', 'badge' => 'live'],
            ['label' => 'Duplicate families', 'desc' => 'Review and merge duplicate households.', 'href' => $base . '/admin/families/duplicates', 'badge' => 'live'],
            ['label' => 'Option lists', 'desc' => 'Classifications, member types, family roles.', 'href' => $base . '/admin/options', 'badge' => 'live'],
            ['label' => 'Import members', 'desc' => 'Bring members in from a spreadsheet.', 'href' => $base . '/admin/maintenance/import', 'badge' => 'live'],
        ],
    ],
    [
        'title' => 'Church setup',
        'desc' => 'Structure that rarely changes.',
        'items' => [
            ['label' => 'Church info', 'desc' => 'Name, contact details, service times.', 'href' => $base . '/admin/church-info', 'badge' => 'live'],
            ['label' => 'Campuses', 'desc' => 'Locations and the main campus.', 'href' => $base . '/admin/campuses', 'badge' => 'live'],
            ['label' => 'Ministries', 'desc' => 'Ministry catalog and visibility.', 'href' => $base . '/admin/ministries', 'badge' => 'live'],
            ['label' => 'Groups & ministries', 'desc' => 'Ministry members, leaders and serving roles.', 'href' => $base . '/admin/groups-and-ministries', 'badge' => 'beta'],
            ['label' => 'Calendar settings', 'desc' => 'Calendar sources and custom rules.', 'href' => $base . '/admin/calendar', 'badge' => 'live'],
            ['label' => 'Events', 'desc' => 'Configured events, occurrences and recurrence.', 'href' => $base . '/admin/events', 'badge' => 'live'],
        ],
    ],
    [
        'title' => 'Access & operations',
        'desc' => 'Who can get in, and how the data is protected.',
        'items' => [
            ['label' => 'Users & access', 'desc' => 'Who can sign in, and what they may do.', 'href' => $base . '/admin/users', 'badge' => 'live'],
            ['label' => 'Import, export & backups', 'desc' => 'Spreadsheets in, records out, safe copies.', 'href' => $base . '/admin/maintenance', 'badge' => 'live'],
            ['label' => 'Sign-ups & RSVP', 'desc' => 'Guest registration and event RSVP.', 'href' => $base . '/admin/outreach', 'badge' => 'live'],
            ['label' => 'User guide', 'desc' => 'Documentation for leaders and members.', 'href' => $base . '/docs', 'badge' => 'live'],
        ],
    ],
];

ob_start();
?>
<style>
    .dash-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px}
    /* Common tasks. Deliberately worded as jobs rather than page names, and
       given room to breathe: this is the row an administrator who is not sure
       where to start will read first. */
    .dash-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(232px,1fr));gap:10px}
    .dash-quickaction{display:flex;flex-direction:column;gap:2px;padding:12px 14px;
      min-height:44px;border:1px solid var(--line);border-radius:var(--radius,8px);
      background:var(--surface);color:var(--ink);text-decoration:none}
    .dash-quickaction:hover,.dash-quickaction:focus-visible{background:var(--soft);border-color:var(--deep)}
    .dash-quickaction b{font-size:14px}
    .dash-quickaction span{font-size:12px;color:var(--muted)}
    .dash-metric{display:block;padding:12px 14px;border:1px solid var(--line);border-radius:var(--radius,10px);
        background:var(--paper);text-decoration:none;color:var(--ink)}
    .dash-metric:hover{background:var(--soft)}
    .dash-metric b{display:block;font-size:26px;line-height:1.1;font-variant-numeric:tabular-nums}
    .dash-metric span{display:block;font-size:12px;color:var(--muted);margin-top:2px}
    /* Attention list — the reason to open this page at all. */
    .dash-alert{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:center;
        padding:11px 14px;border:1px solid var(--line);border-radius:var(--radius,10px);background:var(--paper);margin-bottom:8px}
    .dash-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
    .dash-dot.high{background:var(--rose)}
    .dash-dot.medium{background:var(--gold)}
    .dash-dot.low{background:var(--muted)}
    .dash-alert h3{margin:0;font-size:14px}
    .dash-alert p{margin:2px 0 0;font-size:12px;color:var(--muted)}
    .dash-alert .button{white-space:nowrap;min-height:36px}
    .dash-ok{padding:14px;border:1px dashed var(--line);border-radius:var(--radius,10px);color:var(--muted);font-size:13px}
    /* Simple horizontal bars — no chart library, and readable without colour. */
    .dash-bars{display:grid;gap:6px}
    .dash-bar{display:grid;grid-template-columns:minmax(90px,32%) minmax(0,1fr) auto;gap:10px;align-items:center;font-size:12px}
    .dash-bar .track{background:var(--soft);border-radius:999px;height:10px;overflow:hidden}
    .dash-bar .fill{background:var(--teal-ink,var(--teal));height:100%;border-radius:999px}
    .dash-bar .num{font-variant-numeric:tabular-nums;color:var(--muted);min-width:3ch;text-align:right}
    .dash-group{margin-top:6px}
    .dash-group h3{margin:0 0 2px;font-size:14px}
    .dash-group p.sub{margin:0 0 8px;font-size:12px;color:var(--muted)}
    .dash-items{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px}
    .dash-item{display:grid;gap:2px;padding:10px 12px;border:1px solid var(--line);border-radius:var(--radius,10px);
        background:var(--paper);text-decoration:none;color:var(--ink);min-height:44px}
    .dash-item:hover{background:var(--soft)}
    .dash-item strong{font-size:13px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .dash-item span{font-size:12px;color:var(--muted)}
    .dash-files{width:100%;border-collapse:collapse;font-size:12px}
    .dash-files th,.dash-files td{border-bottom:1px solid var(--line);padding:7px 8px;text-align:left}
    .dash-files th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
    .dash-files td.num{text-align:right;font-variant-numeric:tabular-nums}
    /* Table links are targets too — 24px floor (WCAG 2.5.8). */
    .dash-files td a{display:inline-flex;align-items:center;min-height:24px}
    @media(max-width:640px){
        .dash-alert{grid-template-columns:auto minmax(0,1fr);row-gap:8px}
        .dash-alert .button{grid-column:2}
    }
</style>
<?php if (!$isAdmin): ?>
    <div class="admin-card"><div class="admin-card-body">
        <h2>Sign in required</h2>
        <p>The control board is available to portal administrators.</p>
        <p><a class="button" href="<?= $e($base) ?>/login">Sign in</a></p>
    </div></div>
<?php else: ?>

    <div class="admin-card">
        <div class="admin-card-head"><div><h2>Needs attention</h2><p>Ranked by how much it affects the data.</p></div></div>
        <div class="admin-card-body">
            <?php $alerts = $dashboard['attention'] ?? []; ?>
            <?php if ($alerts === []): ?>
                <div class="dash-ok">Nothing needs attention. Counts look consistent and a recent backup exists.</div>
            <?php else: ?>
                <?php foreach ($alerts as $a): ?>
                    <div class="dash-alert">
                        <span class="dash-dot <?= $e($a['level']) ?>" aria-hidden="true"></span>
                        <div>
                            <h3><?= $e($a['title']) ?></h3>
                            <p><?= $e($a['body']) ?></p>
                        </div>
                        <a class="button secondary" href="<?= $e($a['href']) ?>"><?= $e($a['action']) ?></a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>


    <div class="admin-card">
        <div class="admin-card-head"><div><h2>Common tasks</h2><p>The things most often done from here.</p></div></div>
        <div class="admin-card-body">
            <div class="dash-quick">
                <a class="dash-quickaction" href="<?= $e($base) ?>/admin/people">
                    <b>Add or edit a person</b><span>Find a member and update their record</span></a>
                <a class="dash-quickaction" href="<?= $e($base) ?>/admin/events">
                    <b>Add an event</b><span>Put something on the church calendar</span></a>
                <a class="dash-quickaction" href="<?= $e($base) ?>/admin/groups-and-ministries">
                    <b>Manage a ministry</b><span>Members, leaders and roles</span></a>
                <a class="dash-quickaction" href="<?= $e($base) ?>/admin/maintenance/import">
                    <b>Import from a spreadsheet</b><span>Bring members in from Excel</span></a>
                <a class="dash-quickaction" href="<?= $e($base) ?>/admin/maintenance">
                    <b>Back up church data</b><span>Make a safe copy of current records</span></a>
                <a class="dash-quickaction" href="<?= $e($base) ?>/calendar">
                    <b>View the calendar</b><span>See what is coming up</span></a>
            </div>
        </div>
    </div>


    <div class="admin-card">
        <div class="admin-card-head"><div><h2>At a glance</h2><p>Live counts across the portal.</p></div></div>
        <div class="admin-card-body">
            <div class="dash-metrics">
                <?php foreach (($dashboard['metrics'] ?? []) as $m): ?>
                    <a class="dash-metric" href="<?= $e($m['href']) ?>">
                        <b><?= $e(number_format((int) $m['value'])) ?></b>
                        <span><?= $e($m['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-head"><div><h2>Directory breakdown</h2><p>Where the people records actually sit.</p></div></div>
        <div class="admin-card-body">
            <?php foreach (['classifications' => 'Classification', 'memberTypes' => 'Member type'] as $key => $label): ?>
                <?php $rows = $dashboard['breakdown'][$key] ?? []; ?>
                <?php if ($rows !== []): ?>
                    <p class="sub" style="margin:0 0 6px;font-size:12px;color:var(--muted)"><?= $e($label) ?></p>
                    <div class="dash-bars" style="margin-bottom:14px">
                        <?php foreach ($rows as $r): ?>
                            <div class="dash-bar">
                                <span><?= $e($r['name']) ?></span>
                                <span class="track"><span class="fill" style="width:<?= (int) $r['pct'] ?>%"></span></span>
                                <span class="num"><?= $e(number_format((int) $r['count'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-head"><div><h2>Manage</h2><p>Everything configurable, grouped by what it affects.</p></div></div>
        <div class="admin-card-body" style="display:grid;gap:16px">
            <?php foreach ($groups as $g): ?>
                <div class="dash-group">
                    <h3><?= $e($g['title']) ?></h3>
                    <p class="sub"><?= $e($g['desc']) ?></p>
                    <div class="dash-items">
                        <?php foreach ($g['items'] as $it): ?>
                            <a class="dash-item" href="<?= $e($it['href']) ?>">
                                <strong><?= $e($it['label']) ?><?= admin_section_status_badge($it['badge']) ?></strong>
                                <span><?= $e($it['desc']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-head"><div>
            <h2>Advanced</h2>
            <p>Troubleshooting information, for when something is wrong or someone
               is helping you remotely.</p>
        </div></div>
        <div class="admin-card-body">
            <a class="button secondary" href="<?= $e($basePath) ?>/admin/system">System information</a>
        </div>
    </div>

<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath,
    'activeId' => 'dashboard',
    'pageTitle' => 'Administration',
    'pageSubtitle' => 'What needs attention, and where to go next',
    'sectionTitle' => 'Administration',
    'sectionDescription' => 'Start here: what needs looking at, the things you do most often, '
        . 'and how the church records stand today.',
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'isAdmin' => $isAdmin,
], static fn (): string => $content);
