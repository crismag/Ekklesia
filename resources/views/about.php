<?php
/**
 * About Ekklesia: what the portal is, how it was built, and who built it.
 *
 * Open to everyone who can reach the portal. Static content only.
 *
 * @var string                $basePath
 * @var ?array<string,mixed>  $actor
 * @var array<string,mixed>   $campusSelector
 */

declare(strict_types=1);

require_once __DIR__ . '/_portal-shell.php';

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$base = $e($basePath);
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$church = (string) ($churchName ?? '');

$workspaces = [
    ['People & Records', 'The church’s member records and households, with Excel import and export and a history of every change.'],
    ['Ministries', 'Each ministry’s members, leaders, positions, serving roles and schedule.'],
    ['Events & Calendar', 'The church calendar: events, repeating services, campuses and printable calendars.'],
    ['Serving & Scheduling', 'Who serves when: the serving grid, schedule board, rosters and printables.'],
    ['Visitors & RSVPs', 'Guest sign-ups and event RSVPs, reviewed and welcomed into the member records.'],
    ['Administration', 'Logins and access, church and campus details, portal notices, backups and activity history.'],
];

$technology = [
    ['Server', 'PHP 8, server-rendered pages behind a small front controller of its own — no framework.'],
    ['Member database', 'MySQL / MariaDB, redesigned from the ground up for Ekklesia: people, households, ministries, calendar, schedules and logins.'],
    ['Visitors database', 'SQLite, kept apart from member records until a guest is reviewed and welcomed.'],
    ['Interface', 'HTML, CSS with theme tokens (the church’s colour presets) and plain JavaScript; works on phones.'],
    ['Security', 'Argon2id password hashing, session cookies, and access scoped by role, ministry and campus.'],
    ['Spreadsheets', 'A built-in Excel (.xlsx) reader and writer for the church’s member workbook.'],
    ['Maps and holidays', 'OpenStreetMap geocoding (Nominatim, Photon) and public holiday calendars from Nager.Date.'],
    ['Quality', 'PHP regression suites, architecture boundary checks, source contract checks and browser tests.'],
    ['Hosting', 'Hostinger (LiteSpeed web server).'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>About · Ekklesia</title>
    <style>
        body{margin:0;min-height:100vh;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
        .ab-grid{display:grid;gap:var(--sp-4,16px);grid-template-columns:minmax(0,2fr) minmax(260px,1fr);align-items:start}
        @media (max-width:960px){.ab-grid{grid-template-columns:minmax(0,1fr)}}
        .ab-prose{max-width:68ch}
        .ab-prose p{margin:0 0 var(--sp-3,12px);line-height:1.6}
        .ab-prose p:last-child{margin-bottom:0}
        .ab-list{list-style:none;margin:0;padding:0;display:grid;gap:var(--sp-3,12px)}
        .ab-list li{display:grid;gap:2px}
        .ab-list strong{font-size:14px}
        .ab-list span{color:var(--muted);line-height:1.5}
        .ab-tech{display:grid;grid-template-columns:minmax(120px,max-content) minmax(0,1fr);gap:var(--sp-2,8px) var(--sp-4,16px);margin:0}
        .ab-tech dt{font-weight:700}
        .ab-tech dd{margin:0;color:var(--ink);line-height:1.5}
        @media (max-width:560px){.ab-tech{grid-template-columns:minmax(0,1fr)}.ab-tech dd{margin-bottom:var(--sp-2,8px)}}
        .ab-credit{display:grid;gap:var(--sp-2,8px);text-align:left}
        .ab-credit-role{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}
        .ab-credit-name{font-size:22px;font-weight:800;line-height:1.2;color:var(--ink)}
        .ab-credit p{margin:0;color:var(--muted);line-height:1.5}
        .ab-mark{display:flex;align-items:center;gap:var(--sp-3,12px)}
        .ab-mark img{width:44px;height:44px;border-radius:10px;object-fit:cover}
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?>>
    <?= portal_header($basePath, '', '', $campuses, $campusSelector['defaultCampusId'] ?? null, $actor, [], [], [], 'Sign in', $basePath . '/login') ?>

    <main id="portal-main" tabindex="-1" class="ek-page">
        <?= ek_page_header('About Ekklesia', 'The church’s portal for its records, ministries, calendar and serving schedules.') ?>

        <div class="ab-grid">
            <div style="display:grid;gap:var(--sp-4,16px)">
                <section class="ek-card" aria-labelledby="ab-what">
                    <div class="ek-card-head"><h2 id="ab-what">What Ekklesia is</h2></div>
                    <div class="ek-card-body ab-prose">
                        <p><strong>Ekklesia</strong> (Greek for the gathered church) is the records and operations portal<?= $church !== '' ? ' of ' . $e($church) : '' ?>.
                            It is where the church keeps its people and households, organises its ministries, plans the calendar,
                            schedules who serves, and welcomes guests who sign up or RSVP.</p>
                        <p>It is not the church’s public website. The website presents the church to the world; Ekklesia is the working
                            tool behind it, for members, ministry leaders and administrators. A few portal functions are open to everyone —
                            the calendar, events, guest sign-up and event RSVPs — so the website can link to them.</p>
                        <p>Ekklesia works alongside <strong>Oikonomia</strong>, the leadership binder where church leaders keep their
                            reports, meetings and follow-up.</p>
                    </div>
                </section>

                <section class="ek-card" aria-labelledby="ab-workspaces">
                    <div class="ek-card-head"><h2 id="ab-workspaces">Workspaces</h2></div>
                    <div class="ek-card-body">
                        <ul class="ab-list">
                            <?php foreach ($workspaces as [$name, $purpose]): ?>
                                <li><strong><?= $e($name) ?></strong><span><?= $e($purpose) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>

                <section class="ek-card" aria-labelledby="ab-story">
                    <div class="ek-card-head"><h2 id="ab-story">How it was built</h2></div>
                    <div class="ek-card-body ab-prose">
                        <p>Ekklesia grew out of the church’s earlier Church Portal, which ran on tables inherited from ChurchCRM.
                            In 2026 the portal was rebuilt on a new, purpose-designed database: every member, household, ministry,
                            event and schedule was carried across and checked against the original, record by record.</p>
                        <p>The interface was then reorganised into the workspaces above, with a modern layout that works on
                            phones, while the calendar, the serving schedule and the printables the church relies on were kept as they were.
                            Guest sign-ups and RSVPs moved into their own database and review workflow.</p>
                    </div>
                </section>

                <section class="ek-card" aria-labelledby="ab-tech">
                    <div class="ek-card-head"><h2 id="ab-tech">Technology</h2></div>
                    <div class="ek-card-body">
                        <dl class="ab-tech">
                            <?php foreach ($technology as [$term, $detail]): ?>
                                <dt><?= $e($term) ?></dt><dd><?= $e($detail) ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </div>
                </section>
            </div>

            <aside style="display:grid;gap:var(--sp-4,16px)">
                <section class="ek-card" aria-labelledby="ab-credits">
                    <div class="ek-card-head"><h2 id="ab-credits">Credits</h2></div>
                    <div class="ek-card-body ab-credit">
                        <div class="ab-mark">
                            <img src="<?= $base ?>/images/christlikeness_colored.jpg" alt="">
                            <div>
                                <div class="ab-credit-role">Designed and developed by</div>
                                <div class="ab-credit-name">Cris Magalang</div>
                            </div>
                        </div>
                        <p>Ekklesia was designed and developed by Cris Magalang<?= $church !== '' ? ' for ' . $e($church) : '' ?>, from the database and
                            the portal’s workspaces to the migration of the church’s records.</p>
                    </div>
                </section>

                <section class="ek-card" aria-labelledby="ab-help">
                    <div class="ek-card-head"><h2 id="ab-help">Using the portal</h2></div>
                    <div class="ek-card-body ab-prose">
                        <p>The <a href="<?= $base ?>/docs">user guide</a> explains each workspace step by step.</p>
                    </div>
                </section>
            </aside>
        </div>
    </main>
    <?= portal_footer() ?>
</div>
</body>
</html>
