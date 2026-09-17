<?php
/**
 * Portal home — the church's public visiting page.
 *
 * Layer 1 (everyone): hero, upcoming events, announcements, ministries, visit info.
 * Layer 2 (signed-in): personal assignments and leader jump-ins. Overlay only;
 * it never replaces the public church feed.
 *
 * @var string                       $basePath
 * @var ?array<string, mixed>        $actor
 * @var array<int, array<string,mixed>> $availableMinistries
 * @var array<string, mixed>         $campusSelector
 * @var array<string, mixed>         $home
 * @var string                       $churchName
 */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$permissions = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
$ministriesMine = is_array($availableMinistries ?? null) ? $availableMinistries : [];
$initialMinistryId = count($ministriesMine) === 1 ? (int) $ministriesMine[0]['ministryId'] : 0;
$canViewSelf = $actor !== null && in_array('view_own_assignments', $permissions, true) && ($actor['personId'] ?? null) !== null;
$isLeaderView = $actor !== null && (
    (bool) ($actor['isPortalWideAdmin'] ?? false)
    || in_array('view_ministry_schedule', $permissions, true)
    || in_array('manage_schedules', $permissions, true)
);
$canManageAvail = $actor !== null && in_array('manage_own_availability', $permissions, true) && ($actor['personId'] ?? null) !== null;
$canLeaderTools = $ministriesMine !== [];
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$disabledAttr = static fn (bool $enabled): string => $enabled ? '' : ' aria-disabled="true" tabindex="-1"';
$home = is_array($home ?? null) ? $home : \App\Services\HomePageService::empty();
$church = is_array($home['church'] ?? null) ? $home['church'] : [];
$heroCfg = is_array($home['hero'] ?? null) ? $home['hero'] : ['slides' => [], 'behavior' => []];
$heroSlides = is_array($heroCfg['slides'] ?? null) ? $heroCfg['slides'] : [];
$firstSlide = is_array($heroSlides[0] ?? null) ? $heroSlides[0] : [
    'kicker' => 'Welcome',
    'title' => 'Schedules, people, and what’s happening.',
    'lead' => 'This portal is where the church posts current events, ministry teams, and serving schedules.',
];
$publicEvents = is_array($home['events'] ?? null) ? $home['events'] : [];
$publicAnnouncements = is_array($home['announcements'] ?? null) ? $home['announcements'] : [];
$publicMinistries = is_array($home['ministries'] ?? null) ? $home['ministries'] : [];
$addressLine = (string) ($home['addressLine'] ?? '');
$churchTitle = htmlspecialchars((string) ($church['name'] ?? $churchName ?? 'Church Portal'), ENT_QUOTES, 'UTF-8');
$ministriesJson = htmlspecialchars(json_encode($ministriesMine, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$defaultCampusId = $campusSelector['defaultCampusId'] ?? null;
$campusesJson = htmlspecialchars(json_encode($campuses, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
$campusNames = [];
foreach ($campuses as $c) {
    if (isset($c['id'])) {
        $campusNames[(int) $c['id']] = (string) ($c['name'] ?? '');
    }
}
require_once __DIR__ . '/_portal-shell.php';
$actorFirstName = portal_first_name($actor['displayName'] ?? null);
$roleLabel = $isAdmin ? 'Portal admin' : ($canLeaderTools ? 'Ministry leader' : 'Member');

$renderEventRow = static function (array $item, string $basePathEscaped): string {
    $when = (string) ($item['next_occurrence_at'] ?? '');
    $dt = $when !== '' ? date_create($when) : false;
    $month = $dt instanceof DateTimeInterface ? $dt->format('M') : '—';
    $day = $dt instanceof DateTimeInterface ? $dt->format('j') : '';
    $title = htmlspecialchars((string) ($item['title'] ?? 'Event'), ENT_QUOTES, 'UTF-8');
    $id = (int) ($item['event_id'] ?? 0);
    $count = (int) ($item['occurrence_count'] ?? 0);
    $meta = $count > 0 ? $count . ' upcoming ' . ($count === 1 ? 'date' : 'dates') : 'On the church calendar';
    if ($dt instanceof DateTimeInterface) {
        $meta .= ' · ' . $dt->format('g:i A');
    }
    $href = $id > 0 ? $basePathEscaped . '/events/' . $id : $basePathEscaped . '/events';
    return '<a class="row row-link" href="' . $href . '"><div class="date-chip"><small>'
        . htmlspecialchars($month, ENT_QUOTES, 'UTF-8') . '</small>'
        . htmlspecialchars((string) $day, ENT_QUOTES, 'UTF-8')
        . '</div><div><div class="row-title">' . $title . '</div><div class="row-meta">'
        . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '</div></div></a>';
};

$renderNotice = static function (array $item): string {
    $title = htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $body = htmlspecialchars((string) ($item['body'] ?? ''), ENT_QUOTES, 'UTF-8');
    $tag = htmlspecialchars((string) ($item['tag'] ?? 'Church'), ENT_QUOTES, 'UTF-8');
    return '<div class="notice"><div class="notice-top"><div class="notice-title">' . $title
        . '</div><span class="tag">' . $tag . '</span></div><div class="notice-body">' . $body . '</div></div>';
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Home · <?= $churchTitle ?></title>
    <style>
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font:14px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color:var(--ink); background:var(--bg); }
        .hero{position:relative;isolation:isolate;display:block;margin:0 0 20px;padding:18px 0}
        .hero::before{content:"";position:absolute;top:-18px;bottom:0;left:0;right:0;
          width:auto;background:linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 100%);z-index:-1}
        a { color:inherit; }
        .hero-copy { color:#f8fffb; padding:28px 0 20px; }
        h1 { margin:0; font-size:clamp(28px, 4.4vw, 48px); line-height:1.05; letter-spacing:0; max-width:760px; }
        #heroTitle{max-height:none;overflow:hidden;display:block;line-height:1.05;font-size:clamp(28px,4.4vw,48px);white-space:pre-line}
        .lead { margin:12px 0 0; color:rgba(248,255,251,.82); font-size:16px; line-height:1.45; max-width:620px; white-space:pre-line; }
        .hero-kicker{display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;padding:6px 10px;border:1px solid rgba(255,255,255,.22);border-radius:999px;background:rgba(255,255,255,.1);font-size:12px;font-weight:900;color:#f8fffb}
        .pulse-dot{width:7px;height:7px;border-radius:50%;background:#8ef0c6;box-shadow:0 0 0 6px rgba(142,240,198,.12)}
        .hero-rotator{display:grid;gap:8px;align-content:start}
        .hero-controls{display:flex;align-items:center;gap:8px;margin-top:4px}
        .hero-dot{width:26px;height:6px;min-height:24px;padding:9px 0;background-clip:content-box;box-sizing:content-box;border:0;border-radius:999px;background:rgba(255,255,255,.26);cursor:pointer}
        .hero-dot.active{background:#fff}
        .hero-arrow{width:34px;height:34px;border-radius:8px;border:1px solid rgba(255,255,255,.24);background:rgba(255,255,255,.12);color:#fff;font-weight:900;cursor:pointer}
        .hero-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
        .home-layout { display:grid; grid-template-columns:minmax(0,1.4fr) minmax(260px,.72fr); gap:18px; align-items:start; }
        .home-main, .home-sidebar { display:grid; gap:18px; }
        .for-you { background:var(--paper); border:1px solid var(--line); border-radius:8px; box-shadow:0 16px 42px rgba(27,50,40,.1); padding:16px; margin:0 0 18px; display:grid; gap:12px; }
        .for-you-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap; }
        .for-you h2 { margin:0; font-size:16px; }
        .for-you-lead { margin:4px 0 0; color:var(--muted); font-size:13px; }
        .week-kicker{margin:0;padding:0 16px 8px;font-size:12px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        .for-you-role { display:inline-flex; align-items:center; min-height:28px; padding:4px 10px; border-radius:999px; background:var(--soft); color:var(--deep); font-size:12px; font-weight:900; }
        /* Shortcut boxes. The rail ends where somebody has finished
           reading their week and wants to go somewhere; a box is a target
           they can hit without scrolling back to the nav bar. Two columns
           keeps every label on one line at rail width. */
        .shortcut-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0}
        .shortcut-box{display:flex;align-items:center;gap:9px;min-height:52px;padding:12px 14px;
                      border-bottom:1px solid #edf2ef;border-right:1px solid #edf2ef;
                      text-decoration:none;color:var(--ink);font-weight:800;font-size:13px}
        .shortcut-box:hover{background:var(--soft);color:var(--teal)}
        .shortcut-box svg{width:16px;height:16px;flex:0 0 auto;opacity:.72}
        .shortcut-box span{overflow-wrap:anywhere}
        .shortcut-box[aria-disabled="true"]{opacity:.45;pointer-events:none}
        /* Odd counts leave a gap on the right; the last box closes the edge. */
        .shortcut-grid a:nth-child(2n),.shortcut-grid a:last-child{border-right:0}

        /* The site footer. The rail answers "where do I go next" while you are
           reading; the footer answers it once you have reached the bottom, and
           it is the only place a signed-out visitor is told that signing in
           shows them what they are scheduled to serve. */
        .home-footer{margin-top:26px;border-top:1px solid var(--line);padding:22px 0 6px}
        .foot-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:18px 24px}
        .foot-col h2{margin:0 0 8px;font-size:12px;font-weight:900;letter-spacing:.05em;
                     text-transform:uppercase;color:var(--muted)}
        .foot-col p{margin:0 0 4px;color:var(--muted);font-size:13px;line-height:1.45}
        .foot-col a{display:inline-flex;align-items:center;min-height:28px;
                    color:var(--teal);font-weight:800;text-decoration:none}
        .foot-col a:hover{text-decoration:underline}
        .foot-bar{display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;
                  margin-top:18px;padding-top:12px;border-top:1px solid var(--line);
                  color:var(--muted);font-size:12px}
        @media (max-width:820px){ .foot-col a{min-height:44px} }
        .panel { background:var(--paper); border:1px solid var(--line); border-radius:8px; box-shadow:0 16px 42px rgba(27,50,40,.1); overflow:hidden; }
        .panel-head { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:16px 16px 12px; border-bottom:1px solid var(--line); }
        .panel h2 { margin:0; font-size:16px; }
        .panel-head a { color:var(--teal); text-decoration:none; font-weight:900; font-size:12px; white-space:nowrap; }
        .row { display:grid; grid-template-columns:54px minmax(0,1fr); gap:10px; padding:12px 16px; border-bottom:1px solid #edf2ef; }
        .row:last-child { border-bottom:0; }
        a.row-link{text-decoration:none;color:inherit}
        a.row-link:hover .row-title{color:var(--teal)}
        .date-chip { min-height:48px; border-radius:8px; display:grid; place-items:center; align-content:center; background:var(--soft); font-weight:950; text-align:center; }
        .date-chip small { display:block; color:var(--muted); font-size:12px; }
        .row-title { font-weight:900; overflow-wrap:anywhere; font-size:13px; }
        .row-meta { color:var(--muted); font-size:12px; }
        .empty { padding:18px 16px; color:var(--muted); }
        .announcement-list{max-height:360px;overflow:auto;display:grid}
        .notice{display:grid;gap:5px;padding:11px 13px;border-bottom:1px solid #edf2ef;min-width:0}
        .notice:last-child{border-bottom:0}
        .notice-top{display:flex;justify-content:space-between;gap:10px;align-items:center}
        .notice-title{font-weight:900;font-size:13px;line-height:1.25;overflow-wrap:anywhere}
        .notice-body{color:var(--muted);font-size:12px;line-height:1.45;overflow-wrap:anywhere}
        .tag{display:inline-flex;border-radius:999px;padding:2px 7px;background:var(--soft);color:var(--deep);font-size:12px;font-weight:900;white-space:nowrap;line-height:1.4}
        .ministry-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:0}
        .ministry-tile{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:12px 16px;border-bottom:1px solid #edf2ef;border-right:1px solid #edf2ef;text-decoration:none;min-height:52px}
        .ministry-tile:hover .ministry-name{color:var(--teal)}
        .ministry-name{font-weight:800;font-size:13px;overflow-wrap:anywhere}
        .ministry-campus{color:var(--muted);font-size:12px}
        .visit{padding:16px;display:grid;gap:8px}
        .visit p{margin:0;color:var(--muted);font-size:13px;line-height:1.45}
        .visit a{color:var(--teal);font-weight:800;text-decoration:none}
        .leader-jump{padding:14px;display:grid;gap:8px}
        .leader-jump label{font-weight:900;font-size:11px;color:var(--muted);text-transform:uppercase}
        .leader-jump select{border:1px solid var(--line);border-radius:8px;padding:9px 12px;font:inherit;background:#fff;color:var(--ink);width:100%}
        .leader-links{display:flex;flex-wrap:wrap;gap:8px}
        .leader-links a{color:var(--teal);font-weight:800;font-size:13px;text-decoration:none;min-height:36px;display:inline-flex;align-items:center}
        .leader-links a[aria-disabled="true"]{opacity:.45;pointer-events:none;color:var(--muted)}
        /* The personal column.
           "This week" used to be a full-width panel: eight rows of about forty
           characters each, stretched across fourteen hundred pixels, taking
           three quarters of a screen to say what fits in a narrow list. It is a
           glance, not a document, so it lives in the rail beside the church
           feed and is set to be read at a glance. */
        .home-sidebar .for-you { padding:14px; margin:0; }
        .home-sidebar .for-you-head { flex-direction:column; gap:4px; }
        .home-sidebar .for-you-head h2 { font-size:15px; }
        .home-sidebar .for-you-lead { font-size:12.5px; margin:0; }
        .home-sidebar .panel-head h2 { font-size:13.5px; }
        /* Compact rows: the date chip shrinks, the title wraps instead of
           trailing off across empty space, and the meta line sits under it. */
        .home-sidebar .row { padding:7px 0; gap:9px; align-items:flex-start; }
        .home-sidebar .row + .row { border-top:1px solid var(--line,#e6ece8); }
        .home-sidebar .date-chip { min-width:36px; padding:3px 5px; font-size:14px; line-height:1.05; }
        .home-sidebar .date-chip small { font-size:9.5px; }
        .home-sidebar .row-title { font-size:13px; line-height:1.3; white-space:normal; }
        .home-sidebar .row-meta { font-size:11.5px; }
        .home-sidebar .week-kicker { margin:2px 0 4px; }
        /* Every row is a link; keep the whole row a comfortable target. */
        .home-sidebar .row-link { min-height:44px; }
        .week-more { display:flex; align-items:center; min-height:44px; padding:8px 0 2px;
                     font-size:12.5px; font-weight:800; color:var(--teal,#117b6d); text-decoration:none; }
        .week-more:hover { text-decoration:underline; }
        /* Everything in the rail is something somebody taps. The contact and
           shortcut links here sat at 18px — under half the 24px minimum, and
           they are the links a visitor uses to phone or email the church. */
        /* Not every link: a row is already a flex block and making it
           inline-flex laid the week out two abreast, which is not a list. This
           is for the inline contact and shortcut links only. */
        .home-sidebar a:not(.row-link):not(.shortcut-box) {
            display:inline-flex; align-items:center; min-height:24px; }
        .home-sidebar .row-link, .home-sidebar .shortcut-box { min-height:44px; }
        @media (max-width:1000px){
            .home-sidebar a:not(.row-link):not(.shortcut-box) { min-height:44px; } }

        @media (max-width:1000px){
          .home-layout{grid-template-columns:1fr}
          /* Stacked, the personal column comes first — it is the part that is
             about the reader, and burying it under the public feed would make
             a phone scroll past the church's notices to reach their own week. */
          .home-sidebar{order:-1}
        }
        @media (max-width:640px){
          .lead{font-size:15px}
          .hero{padding:8px 6px;margin-bottom:18px}
          .hero-copy{padding:12px 10px 12px}
          #heroTitle{font-size:clamp(22px,6.2vw,28px)}
          .hero-actions .pc-btn{flex:1 1 calc(50% - 4px);min-width:0}
          .row{grid-template-columns:48px minmax(0,1fr);padding:10px}
        }
        @media (max-width:820px){
          .hero-dot{min-height:44px;padding:19px 0;background-clip:content-box}
          .hero-arrow{width:44px;height:44px}
          .panel-head a{min-height:44px;display:inline-flex;align-items:center}
        }
        @media (min-width:821px){
          .panel-head a{min-height:24px;display:inline-flex;align-items:center}
        }
        @media (prefers-reduced-motion:reduce){
          .hero-dot,.hero-arrow{transition:none}
        }
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>" data-ministries="<?= $ministriesJson ?>" data-initial-ministry="<?= (int) $initialMinistryId ?>" data-campuses="<?= $campusesJson ?>" data-default-campus="<?= $defaultCampusId !== null ? (int) $defaultCampusId : '' ?>" data-can-view-self="<?= $canViewSelf ? '1' : '0' ?>" data-can-leader-tools="<?= $canLeaderTools ? '1' : '0' ?>" data-has-actor="<?= $actor !== null ? '1' : '0' ?>" data-leader-view="<?= $isLeaderView ? '1' : '0' ?>" data-signed-in="<?= $actor !== null ? '1' : '0' ?>">
    <?= portal_header(
        $basePath,
        '',
        '',
        $campuses,
        $defaultCampusId !== null ? (int) $defaultCampusId : null,
        $actor,
        [
            ['href' => $base . '/', 'label' => 'Home', 'icon' => 'dashboard'],
            ['href' => $base . '/ministries', 'label' => 'Ministries', 'icon' => 'ministry'],
            ['href' => $base . '/calendar', 'label' => 'Calendar', 'icon' => 'calendar'],
            ['href' => $base . '/events', 'label' => 'Events', 'icon' => 'events'],
            ['href' => $base . '/people', 'label' => 'People', 'icon' => 'people'],
        ],
        [],
        [],
        'Sign in',
        $base . '/login'
    ) ?>

    <section class="hero" aria-label="Church welcome">
        <div class="hero-copy">
            <div class="hero-rotator" aria-live="polite">
                <div class="hero-kicker"><span class="pulse-dot"></span><span id="heroKicker"><?= htmlspecialchars((string) ($firstSlide['kicker'] ?? 'Welcome'), ENT_QUOTES, 'UTF-8') ?></span></div>
                <h1 id="heroTitle"><?= htmlspecialchars((string) ($firstSlide['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="lead" id="heroLead"><?= htmlspecialchars((string) ($firstSlide['lead'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                <div class="hero-controls" aria-label="Banner messages">
                    <button class="hero-arrow" type="button" id="heroPrev" title="Previous banner">‹</button>
                    <button class="hero-dot active" type="button" data-slide="0" title="Banner 1"></button>
                    <button class="hero-arrow" type="button" id="heroNext" title="Next banner">›</button>
                </div>
            </div>
            <div class="hero-actions">
                <?php if ($actor === null): ?>
                <?= pc_button(['label' => 'Sign in', 'href' => $base . '/login', 'variant' => 'inverse']) ?>
                <?php else: ?>
                <?= pc_button(['label' => 'My schedule', 'href' => $base . '/my-schedule', 'variant' => 'inverse']) ?>
                <?php endif; ?>
                <?= pc_button(['label' => 'Calendar', 'href' => $base . '/calendar', 'variant' => 'onDark']) ?>
                <?= pc_button(['label' => 'Events', 'href' => $base . '/events', 'variant' => 'onDark']) ?>
                <?= pc_button(['label' => 'Ministries', 'href' => $base . '/ministries', 'variant' => 'onDark']) ?>
            </div>
        </div>
    </section>

    <main id="portal-main" tabindex="-1">


        <div class="home-layout">
            <!-- The wide column is the church, read in the order a visitor
                 needs it: who serves here, what the church is saying right now,
                 and what is coming up. The rail beside it is the reader. -->
            <div class="home-main">
                <article class="panel" aria-label="Ministries">
                    <div class="panel-head"><h2>Ministries</h2><a href="<?= $base ?>/ministries">All ministries &rsaquo;</a></div>
                    <div class="ministry-grid" id="ministryList">
                        <?php if ($publicMinistries === []): ?>
                            <div class="empty">Ministry teams will appear here when they are published.</div>
                        <?php else: ?>
                            <?php foreach ($publicMinistries as $m): ?>
                                <?php
                                $mid = (int) $m['ministry_id'];
                                $campusLabel = isset($m['campus_id']) && $m['campus_id'] !== null
                                    ? (string) ($campusNames[(int) $m['campus_id']] ?? '')
                                    : '';
                                ?>
                                <a class="ministry-tile" href="<?= $base ?>/ministries/<?= $mid ?>">
                                    <span class="ministry-name"><?= htmlspecialchars((string) $m['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($campusLabel !== ''): ?><span class="ministry-campus"><?= htmlspecialchars($campusLabel, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="panel" aria-label="Announcements">
                    <div class="panel-head">
                        <h2>Announcements</h2>
                        <?php if ($isAdmin): ?>
                        <a href="<?= $base ?>/admin/announcements">Manage &rsaquo;</a>
                        <?php endif; ?>
                    </div>
                    <div class="announcement-list" id="announcementList">
                        <?php if ($publicAnnouncements === []): ?>
                            <div class="empty">When the church publishes a note, it will show up here.</div>
                        <?php else: ?>
                            <?php foreach ($publicAnnouncements as $item): ?>
                                <?= $renderNotice($item) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="panel" aria-label="Upcoming events">
                    <div class="panel-head"><h2>Upcoming events</h2><a href="<?= $base ?>/events">All events &rsaquo;</a></div>
                    <div id="eventList">
                        <?php if ($publicEvents === []): ?>
                            <div class="empty">No upcoming events are posted yet.</div>
                        <?php else: ?>
                            <?php foreach ($publicEvents as $item): ?>
                                <?= $renderEventRow($item, $base) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>
            </div>

            <aside class="home-sidebar" aria-label="Your week and church contact">
                <?php if ($actor !== null): ?>
                <article class="panel" aria-label="This week">
                    <div class="panel-head"><h2>This week</h2><a href="<?= $base ?>/calendar">Calendar &rsaquo;</a></div>
                    <div id="thisWeekList">
                        <?php
                        $thisWeekEvents = is_array($thisWeekEvents ?? null) ? $thisWeekEvents : [];
                        if ($thisWeekEvents === []):
                        ?>
                        <div class="empty">Nothing posted for the next 7 days yet.</div>
                        <?php else: ?>
                        <p class="week-kicker">Activities</p>
                            <?php
                            // A glance, not the calendar. Eight services listed
                            // in a rail is a list somebody scrolls past; the
                            // next few and a count of the rest is something
                            // they read. The full week is one click away and
                            // the link says how much it holds.
                            $weekShown = array_slice($thisWeekEvents, 0, 5);
                            $weekMore = count($thisWeekEvents) - count($weekShown);
                            ?>
                            <?php foreach ($weekShown as $item): ?>
                            <?= $renderEventRow($item, $base) ?>
                            <?php endforeach; ?>
                            <?php if ($weekMore > 0): ?>
                            <a class="week-more" href="<?= $base ?>/calendar"><?= (int) $weekMore ?>
                                more <?= $weekMore === 1 ? 'activity' : 'activities' ?> this week &rsaquo;</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>

                <section class="for-you" aria-label="For you">
                    <div class="for-you-head">
                        <div>
                            <h2>For you, <?= htmlspecialchars($actorFirstName, ENT_QUOTES, 'UTF-8') ?></h2>
                            <p class="for-you-lead">This week at church, and the roles you are scheduled to serve.</p>
                        </div>
                        <span class="for-you-role"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </section>

                <article class="panel" aria-label="My upcoming assignments">
                    <div class="panel-head"><h2>My next assignments</h2><a href="<?= $base ?>/my-schedule">Full schedule &rsaquo;</a></div>
                    <div id="assignmentList"><div class="empty">Loading assignments…</div></div>
                </article>
                <?php endif; ?>

                <article class="panel" aria-label="Visit and contact">
                    <div class="panel-head"><h2>Visit <?= $churchTitle ?></h2></div>
                    <div class="visit">
                        <?php if ($addressLine !== ''): ?>
                        <p><?= htmlspecialchars($addressLine, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <?php if (($church['phone'] ?? '') !== ''): ?>
                        <p><a href="tel:<?= htmlspecialchars((string) $church['phone'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $church['phone'], ENT_QUOTES, 'UTF-8') ?></a></p>
                        <?php endif; ?>
                        <?php if (($church['email'] ?? '') !== ''): ?>
                        <p><a href="mailto:<?= htmlspecialchars((string) $church['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $church['email'], ENT_QUOTES, 'UTF-8') ?></a></p>
                        <?php endif; ?>
                        <?php if (($church['website'] ?? '') !== ''): ?>
                        <p><a href="<?= htmlspecialchars((string) $church['website'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $church['website'], ENT_QUOTES, 'UTF-8') ?></a></p>
                        <?php endif; ?>
                        <?php if ($addressLine === '' && ($church['phone'] ?? '') === '' && ($church['email'] ?? '') === '' && ($church['website'] ?? '') === ''): ?>
                        <p>Contact details will appear here once the church adds them.</p>
                        <?php endif; ?>
                    </div>
                </article>

                <?php
                // The nav bar carries these too, but the rail is where somebody
                // ends up after reading their week, and a box is a target you
                // can hit without going back to the top of the page.
                $shortcuts = [];
                if ($actor !== null) {
                    $shortcuts[] = ['my-schedule', 'calendar', 'My schedule', $canViewSelf];
                    if ($canManageAvail) {
                        $shortcuts[] = ['availability', 'availability', 'Availability', true];
                    }
                }
                $shortcuts[] = ['calendar', 'calendar', 'Calendar', true];
                $shortcuts[] = ['events', 'events', 'Events', true];
                $shortcuts[] = ['ministries', 'ministry', 'Ministries', true];
                $shortcuts[] = ['people', 'people', 'People', true];
                if ($isAdmin) {
                    $shortcuts[] = ['admin', 'admin', 'Admin', true];
                }
                ?>
                <article class="panel" aria-label="Shortcuts">
                    <div class="panel-head"><h2>Shortcuts</h2></div>
                    <nav class="shortcut-grid" aria-label="Portal shortcuts">
                        <?php foreach ($shortcuts as [$path, $icon, $label, $enabled]): ?>
                        <a class="shortcut-box" href="<?= $base ?>/<?= $path ?>"<?= $disabledAttr($enabled) ?>>
                            <?= portal_icon($icon) ?><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                        <?php endforeach; ?>
                    </nav>
                </article>

                <?php if ($canLeaderTools): ?>
                <article class="panel">
                    <div class="panel-head"><h2>Lead a ministry</h2></div>
                    <div class="leader-jump">
                        <label for="ministrySelect">Jump into a workspace</label>
                        <select id="ministrySelect">
                            <option value="">Select a ministry…</option>
                            <?php foreach ($ministriesMine as $ministry): ?>
                                <option value="<?= (int) $ministry['ministryId'] ?>" <?= (int) $ministry['ministryId'] === $initialMinistryId ? 'selected' : '' ?>><?= htmlspecialchars((string) $ministry['name'], ENT_QUOTES, 'UTF-8') ?><?= $ministry['campusId'] !== null ? ' · Campus #' . (int) $ministry['campusId'] : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="ministryHint" style="margin:0;color:var(--muted);font-size:12px;line-height:1.35">Pick a ministry, then open its workspace. People, printables, and schedules stay on that ministry’s pages.</p>
                        <div class="leader-links">
                            <a class="ministry-link" data-target="schedule" href="#"<?= $disabledAttr($canLeaderTools) ?>>Workspace</a>
                            <a class="ministry-link" data-target="people" href="#"<?= $disabledAttr($canLeaderTools) ?>>People</a>
                            <a class="ministry-link" data-target="print" href="#"<?= $disabledAttr($canLeaderTools) ?>>Printable roster</a>
                        </div>
                    </div>
                </article>
                <?php endif; ?>
            </aside>
        </div>
    </main>
    <?php
    // One footer landmark, not two: the shared portal_footer() strip is a
    // single thin line, so the home page renders the full footer itself and
    // carries the chrome-configured strings in its bottom bar rather than
    // stacking a second <footer> under this one.
    $chromeFooter = is_array(portal_chrome()['footer'] ?? null) ? portal_chrome()['footer'] : [];
    $footLeft  = (string) ($chromeFooter['leftText']  ?? 'Church Portal');
    $footRight = (string) ($chromeFooter['rightText'] ?? 'Campus-aware ministry operations');
    ?>
    <footer class="home-footer">
        <div class="foot-cols">
            <div class="foot-col">
                <h2><?= $churchTitle ?></h2>
                <?php if ($addressLine !== ''): ?>
                <p><?= htmlspecialchars($addressLine, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if (($church['phone'] ?? '') !== ''): ?>
                <p><a href="tel:<?= htmlspecialchars((string) $church['phone'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $church['phone'], ENT_QUOTES, 'UTF-8') ?></a></p>
                <?php endif; ?>
                <?php if (($church['email'] ?? '') !== ''): ?>
                <p><a href="mailto:<?= htmlspecialchars((string) $church['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $church['email'], ENT_QUOTES, 'UTF-8') ?></a></p>
                <?php endif; ?>
            </div>
            <div class="foot-col">
                <h2>Explore</h2>
                <p><a href="<?= $base ?>/calendar">Calendar</a></p>
                <p><a href="<?= $base ?>/events">Events</a></p>
                <p><a href="<?= $base ?>/ministries">Ministries</a></p>
                <p><a href="<?= $base ?>/people">People directory</a></p>
            </div>
            <div class="foot-col">
                <?php if ($actor !== null): ?>
                <h2>Your pages</h2>
                <p><a href="<?= $base ?>/my-schedule">My schedule</a></p>
                <?php if ($canManageAvail): ?>
                <p><a href="<?= $base ?>/availability">Availability</a></p>
                <?php endif; ?>
                <p><a href="<?= $base ?>/account">Account</a></p>
                <?php if ($isAdmin): ?>
                <p><a href="<?= $base ?>/admin">Admin</a></p>
                <?php endif; ?>
                <?php else: ?>
                <h2>Serving here</h2>
                <p>Sign in to see the roles you are scheduled to serve.</p>
                <p><a href="<?= $base ?>/login">Sign in</a></p>
                <?php endif; ?>
            </div>
            <div class="foot-col">
                <h2>Help</h2>
                <p><a href="<?= $base ?>/docs">Guides</a></p>
                <?php if (($church['website'] ?? '') !== ''): ?>
                <p><a href="<?= htmlspecialchars((string) $church['website'], ENT_QUOTES, 'UTF-8') ?>">Church website</a></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="foot-bar">
            <span><?= htmlspecialchars($footLeft, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($footRight !== ''): ?>
            <span><?= htmlspecialchars($footRight, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
    </footer>
</div>
<script type="application/json" id="heroBoot"><?= json_encode($heroCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
const shell = document.querySelector('.shell');
const basePath = shell.dataset.base || '';
const campusSelect = document.getElementById('campusSelect');
const hasActor = shell.dataset.hasActor === '1';
const canViewSelf = shell.dataset.canViewSelf === '1';
const ministrySelect = document.getElementById('ministrySelect');
let heroSlides = [
  { kicker: 'Welcome', title: 'Schedules, people, and what’s happening.', lead: 'This portal is where the church posts current events, ministry teams, and serving schedules.' }
];
let heroBehavior = { autoRotate: true, rotationMs: 7000, showControls: true, displayMode: 'rotator', titleScale: 'md' };
let heroRotateTimer = null;
let heroIndex = 0;
function escapeHtml(value){return String(value ?? '').replace(/[&<>"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));}
function monthDay(value){const d=new Date(value);return Number.isNaN(d.getTime())?['-','']:[d.toLocaleString([], {month:'short'}), String(d.getDate())];}
function formatTime(value){const d=new Date(value);return Number.isNaN(d.getTime())?'':d.toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});}
function selectedCampusId(){return campusSelect?.value || '';}
function campusQuery(name='current_campus_id'){const id=selectedCampusId(); return id ? `${name}=${encodeURIComponent(id)}` : '';}
function withCampus(url, name='current_campus_id'){const q=campusQuery(name); if(!q) return url; return url + (url.includes('?') ? '&' : '?') + q;}
function selectedMinistryId(){return ministrySelect?.value || '';}
function ministryUrl(kind){const id=selectedMinistryId(); if(!id) return '#'; if(kind==='schedule') return withCampus(`${basePath}/ministries/${encodeURIComponent(id)}`); if(kind==='print') return withCampus(`${basePath}/people?ministry_id=${encodeURIComponent(id)}&print=1`); return withCampus(`${basePath}/people?ministry_id=${encodeURIComponent(id)}`);}
function updateMinistryLinks(){
  const id=selectedMinistryId();
  const hint=document.getElementById('ministryHint');
  if(hint) hint.textContent = id ? 'Leader tools stay on the ministry workspace — not duplicated here.' : 'Pick a ministry before opening workspace tools.';
  document.querySelectorAll('.ministry-link').forEach(link=>{
    link.href=ministryUrl(link.dataset.target || 'people');
    if(!id){ link.setAttribute('aria-disabled','true'); } else { link.removeAttribute('aria-disabled'); }
  });
}
function empty(target,text){target.innerHTML=`<div class="empty" role="status">${escapeHtml(text)}</div>`;}
async function loadJson(url){const res=await fetch(url,{credentials:'same-origin'}); if(!res.ok) throw new Error(`Request failed ${res.status}`); return await res.json();}
async function loadThisWeek(){
  const target=document.getElementById('thisWeekList');
  if(!target || !hasActor) return;
  const start=new Date(); start.setHours(0,0,0,0);
  const end=new Date(start); end.setDate(end.getDate()+7);
  const ymd=d=>d.toISOString().slice(0,10);
  const inWeek=(iso)=>{
    if(!iso) return false;
    const t=new Date(iso);
    return !Number.isNaN(t.getTime()) && t>=start && t<end;
  };
  let events=[];
  try{
    const data=await loadJson(withCampus(`${basePath}/api/events?limit=40`));
    events=Array.isArray(data.events)?data.events:[];
  }catch(err){
    try{
      const data=await loadJson(withCampus(`${basePath}/api/public/events?limit=40`));
      events=Array.isArray(data.events)?data.events:[];
    }catch(e){ events=[]; }
  }
  // A glance, not the calendar. Eight services in a narrow rail is a list
  // somebody scrolls past; the next few plus a count of the rest is something
  // they read, and the full week is one click away.
  const WEEK_SHOWN=5;
  const weekAll=events.filter(e=>inWeek(e.next_occurrence_at));
  const weekEvents=weekAll.slice(0,WEEK_SHOWN);
  const weekMore=weekAll.length-weekEvents.length;
  let serving=[];
  if(canViewSelf){
    try{
      const data=await loadJson(withCampus(`${basePath}/api/my-schedule?start=${ymd(start)}&end=${ymd(end)}`));
      serving=Array.isArray(data.assignments)?data.assignments:[];
    }catch(err){ serving=[]; }
  }
  const eventRows=weekEvents.map(item=>{
    const [m,d]=monthDay(item.next_occurrence_at);
    const href=item.event_id?`${basePath}/events/${encodeURIComponent(item.event_id)}`:`${basePath}/events`;
    const meta=formatTime(item.next_occurrence_at)||'On the calendar';
    return `<a class="row row-link" href="${href}"><div class="date-chip"><small>${escapeHtml(m)}</small>${escapeHtml(d)}</div><div><div class="row-title">${escapeHtml(item.title)}</div><div class="row-meta">${escapeHtml(meta)}</div></div></a>`;
  }).join('');
  const SERVING_SHOWN=4;
  const servingMore=Math.max(0, serving.length-SERVING_SHOWN);
  const servingRows=serving.slice(0,SERVING_SHOWN).map(item=>{
    const [m,d]=monthDay(item.startsOn);
    const href=item.eventId?`${basePath}/events/${encodeURIComponent(item.eventId)}`:`${basePath}/my-schedule`;
    return `<a class="row row-link" href="${href}"><div class="date-chip"><small>${escapeHtml(m)}</small>${escapeHtml(d)}</div><div><div class="row-title">${escapeHtml(item.eventTitle || item.ministryName)}</div><div class="row-meta">${escapeHtml(item.roleName)} · ${escapeHtml(item.ministryName)} · ${escapeHtml(formatTime(item.startsOn))}</div></div></a>`;
  }).join('');
  if(!weekEvents.length && !serving.length){
    empty(target,'Nothing on the calendar this week, and you are not scheduled to serve.');
    return;
  }
  const more=(n,href,one,many)=>n>0
    ? `<a class="week-more" href="${href}">${n} more ${n===1?one:many} this week &rsaquo;</a>` : '';
  target.innerHTML=
    (weekEvents.length
      ? `<p class="week-kicker">Activities</p>${eventRows}${more(weekMore, `${basePath}/calendar`, 'activity', 'activities')}`
      : `<p class="week-kicker">Activities</p><div class="empty">No church activities in the next 7 days.</div>`)
    + (canViewSelf
      ? `<p class="week-kicker">Your serving</p>${servingRows || '<div class="empty">You are not scheduled this week.</div>'}${more(servingMore, `${basePath}/my-schedule`, 'duty', 'duties')}`
      : '');
}
async function loadAssignments(){
  const target=document.getElementById('assignmentList');
  if(!target) return;
  if(!canViewSelf){
    empty(target, hasActor
      ? 'Your account is not linked to a person record yet, so assignments cannot be shown. Ask an administrator to link it.'
      : 'Sign in to see your assignments.');
    return;
  }
  try{
    const start=new Date(); const end=new Date(); end.setDate(end.getDate()+28);
    const data=await loadJson(withCampus(`${basePath}/api/my-schedule?start=${start.toISOString().slice(0,10)}&end=${end.toISOString().slice(0,10)}`));
    const items=Array.isArray(data.assignments)?data.assignments:[];
    if(items.length===0){empty(target,'No assignments in the next 28 days.'); return;}
    target.innerHTML=items.slice(0,5).map(item=>{
      const [m,d]=monthDay(item.startsOn);
      return `<div class="row"><div class="date-chip"><small>${escapeHtml(m)}</small>${escapeHtml(d)}</div><div><div class="row-title">${escapeHtml(item.eventTitle || item.ministryName)}</div><div class="row-meta">${escapeHtml(item.roleName)} · ${escapeHtml(item.ministryName)} · ${escapeHtml(formatTime(item.startsOn))}</div></div></div>`;
    }).join('');
  }catch(err){
    empty(target,'Assignments could not be loaded for this session.');
  }
}
async function loadEvents(){
  const target=document.getElementById('eventList');
  try{
    const data=await loadJson(withCampus(`${basePath}/api/public/events?limit=8`));
    const items=Array.isArray(data.events)?data.events:[];
    if(items.length===0){empty(target,'No upcoming events are posted yet.'); return;}
    target.innerHTML=items.slice(0,8).map(item=>{
      const [m,d]=monthDay(item.next_occurrence_at);
      const count=Number(item.occurrence_count||0);
      const meta=(count>0?`${count} upcoming date${count===1?'':'s'}`:'On the church calendar')+(formatTime(item.next_occurrence_at)?' · '+formatTime(item.next_occurrence_at):'');
      const href=item.event_id?`${basePath}/events/${encodeURIComponent(item.event_id)}`:`${basePath}/events`;
      return `<a class="row row-link" href="${href}"><div class="date-chip"><small>${escapeHtml(m)}</small>${escapeHtml(d)}</div><div><div class="row-title">${escapeHtml(item.title)}</div><div class="row-meta">${escapeHtml(meta)}</div></div></a>`;
    }).join('');
  }catch(err){
    if(!target.querySelector('.row')) empty(target,'Events could not be refreshed right now.');
  }
}
async function loadMinistries(){
  const target=document.getElementById('ministryList');
  if(!target) return;
  try{
    const data=await loadJson(withCampus(`${basePath}/api/public/ministries`));
    const items=Array.isArray(data.ministries)?data.ministries:[];
    if(!items.length){empty(target,'Ministry teams will appear here when they are published.'); return;}
    const campuses=JSON.parse(shell.dataset.campuses||'[]');
    const names={};
    campuses.forEach(c=>{ if(c && c.id) names[String(c.id)]=c.name||''; });
    const sorted=[...items].sort((a,b)=>String(a.name||'').localeCompare(String(b.name||'')));
    target.innerHTML=sorted.slice(0,12).map(item=>{
      const id=item.ministry_id||item.ministryId;
      const campusId=item.campus_id||item.campusId;
      const campus=campusId? (names[String(campusId)]||'') : '';
      return `<a class="ministry-tile" href="${basePath}/ministries/${encodeURIComponent(id)}"><span class="ministry-name">${escapeHtml(item.name)}</span>${campus?`<span class="ministry-campus">${escapeHtml(campus)}</span>`:''}</a>`;
    }).join('');
  }catch(err){ /* keep server-rendered list */ }
}
const PC_EMPTY = (title, body) => `<div class="pc-empty" role="status"><p class="pc-empty-title">${escapeHtml(title)}</p><p class="pc-empty-body">${escapeHtml(body)}</p></div>`;
async function loadAnnouncements(){
  const target=document.getElementById('announcementList');
  if(!target) return;
  try{
    const data=await loadJson(`${basePath}/api/announcements`);
    const items=Array.isArray(data.items)?data.items:[];
    if(!items.length){target.innerHTML=PC_EMPTY('Nothing posted','When the church publishes a note, it will show up here.'); return;}
    target.innerHTML=items.map(item=>`<div class="notice"><div class="notice-top"><div class="notice-title">${escapeHtml(item.title)}</div><span class="tag">${escapeHtml(item.tag)}</span></div><div class="notice-body">${escapeHtml(item.body)}</div></div>`).join('');
  }catch(err){
    if(!target.querySelector('.notice')) target.innerHTML=PC_EMPTY('Could not load','Announcements are unavailable right now.');
  }
}
function renderHero(index){
  if(!heroSlides.length || !document.getElementById('heroTitle')) return;
  heroIndex=(index+heroSlides.length)%heroSlides.length;
  const slide=heroSlides[heroIndex];
  const kicker=document.getElementById('heroKicker');
  const title=document.getElementById('heroTitle');
  const lead=document.getElementById('heroLead');
  if(kicker) kicker.textContent=slide.kicker || '';
  if(title) title.textContent=slide.title || '';
  if(lead) lead.textContent=slide.lead || '';
  document.querySelectorAll('.hero-dot').forEach((dot,i)=>dot.classList.toggle('active',i===heroIndex));
}
function rebuildHeroDots(){
  const controls = document.querySelector('.hero-controls');
  if(!controls) return;
  controls.querySelectorAll('.hero-dot').forEach(d => d.remove());
  const next = document.getElementById('heroNext');
  heroSlides.forEach((_, i) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'hero-dot' + (i === 0 ? ' active' : '');
    btn.dataset.slide = String(i);
    btn.title = 'Banner ' + (i + 1);
    btn.addEventListener('click', () => renderHero(i));
    controls.insertBefore(btn, next);
  });
  controls.style.display = heroBehavior.showControls ? '' : 'none';
}
function startHeroRotation(){
  clearInterval(heroRotateTimer); heroRotateTimer = null;
  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce && heroBehavior.autoRotate && heroBehavior.displayMode !== 'static' && heroSlides.length > 1) {
    heroRotateTimer = setInterval(() => renderHero(heroIndex + 1), Math.max(1500, heroBehavior.rotationMs || 7000));
  }
}
function applyHeroConfig(data){
  if (Array.isArray(data.slides) && data.slides.length > 0) heroSlides = data.slides;
  if (data.behavior && typeof data.behavior === 'object') heroBehavior = Object.assign({}, heroBehavior, data.behavior);
  rebuildHeroDots();
  renderHero(0);
  startHeroRotation();
}
async function loadHeroFromApi(){
  try {
    const boot = document.getElementById('heroBoot');
    if (boot && boot.textContent) applyHeroConfig(JSON.parse(boot.textContent));
    const data = await loadJson(`${basePath}/api/hero`);
    applyHeroConfig(data);
  } catch (e) {
    rebuildHeroDots();
    renderHero(0);
    startHeroRotation();
  }
}
document.getElementById('heroPrev')?.addEventListener('click',()=>renderHero(heroIndex-1));
document.getElementById('heroNext')?.addEventListener('click',()=>renderHero(heroIndex+1));
ministrySelect?.addEventListener('change', updateMinistryLinks);
campusSelect?.addEventListener('change', () => { updateMinistryLinks(); loadThisWeek(); loadAssignments(); loadEvents(); loadMinistries(); });
document.getElementById('logoutForm')?.addEventListener('submit', async e=>{e.preventDefault(); await fetch(e.currentTarget.action,{method:'POST',credentials:'same-origin'}); location.reload();});
updateMinistryLinks(); loadThisWeek(); loadAssignments(); loadEvents(); loadMinistries(); loadAnnouncements(); loadHeroFromApi();
</script>
</body>
</html>
