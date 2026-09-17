<?php
/**
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var int $ministryId
 * @var array<int,array<string,mixed>> $availableMinistries
 * @var array<string,mixed> $campusSelector
 */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$permissions = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
$ministries = is_array($availableMinistries ?? null) ? $availableMinistries : [];
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$defaultCampusId = $campusSelector['defaultCampusId'] ?? null;
$campusesJson = htmlspecialchars(json_encode($campuses, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
$ministriesJson = htmlspecialchars(json_encode($ministries, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/_portal-shell.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Ministry Dashboard - Church Portal</title>
    <style>
                * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font:14px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color:var(--ink); background:var(--bg); }
        a { color:inherit; }
        .brand { display:flex; align-items:center; gap:12px; min-width:0; }
        .mark { width:40px; height:40px; border-radius:8px; display:grid; place-items:center; background:rgba(255,255,255,.13); border:1px solid rgba(255,255,255,.25); font-weight:900; }
        .brand-title { font-size:18px; font-weight:900; }
        .brand-sub { color:rgba(248,255,251,.72); font-size:12px; }
        .actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; justify-content:flex-end; }
        .campus-mini{display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.24);border-radius:8px;padding:4px 6px}
        .campus-mini select{width:auto;min-width:132px;max-width:180px;height:30px;padding:4px 24px 4px 8px;border:0;border-radius:6px;font-size:12px}
        .icon-btn{width:34px;height:34px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.24);border-radius:8px;background:rgba(255,255,255,.1);color:#fff;text-decoration:none;font-weight:900;cursor:pointer}
        .dropdown{position:relative}
        .dropdown-menu{display:none;position:absolute;right:0;top:40px;min-width:188px;background:#fff;color:var(--ink);border:1px solid var(--line);border-radius:8px;box-shadow:0 14px 34px rgba(28,48,39,.16);padding:6px;z-index:10}
        .dropdown.open .dropdown-menu{display:grid}
        .dropdown-menu a{padding:9px 10px;border-radius:6px;text-decoration:none}
        .dropdown-menu a:hover{background:var(--soft)}
        .button, button.button{display:inline-flex;justify-content:center;align-items:center;min-height:40px;border:0;border-radius:8px;padding:10px 14px;background:#fff;color:var(--deep);font:inherit;font-weight:900;text-decoration:none;cursor:pointer;box-shadow:0 12px 26px rgba(3,20,13,.16)}
        .button.secondary{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.24);box-shadow:none}
        .button[aria-disabled="true"]{opacity:.48;pointer-events:none}
        .hero{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);gap:16px;align-items:stretch;margin-bottom:18px}
        .hero-copy{color:#f8fffb;padding:10px 0 20px}
        .hero-kicker{display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;padding:6px 10px;border:1px solid rgba(255,255,255,.22);border-radius:999px;background:rgba(255,255,255,.1);font-size:12px;font-weight:900}
        .pulse-dot{width:7px;height:7px;border-radius:50%;background:#8ef0c6;box-shadow:0 0 0 6px rgba(142,240,198,.12)}
        h1{margin:0;font-size:clamp(34px,4.6vw,58px);line-height:.96;max-width:700px}
        .lead{margin:14px 0 0;color:rgba(248,255,251,.8);font-size:16px;line-height:1.45;max-width:670px}
        .hero-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
        .panel{background:var(--paper);border:1px solid var(--line);border-radius:8px;box-shadow:0 16px 42px rgba(27,50,40,.1);overflow:hidden}
        .summary{padding:14px;display:grid;gap:12px}
        .summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .metric{border-radius:8px;padding:14px;color:#fff;min-height:96px;display:grid;align-content:space-between}
        .metric strong{font-size:30px;line-height:1}
        .metric span{color:rgba(255,255,255,.82);font-weight:800;font-size:12px}
        .m1{background:var(--teal)} .m2{background:var(--blue)} .m3{background:var(--gold)} .m4{background:#1c5b49}
        .rail{margin-top:18px}
        .rail-title{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 10px}
        .rail-title h2{margin:0;font-size:18px}
        .rail-row{display:grid;grid-template-columns:repeat(4,minmax(220px,1fr));gap:14px;overflow-x:auto;padding-bottom:4px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch}
        .rail-row::-webkit-scrollbar{height:8px}
        .rail-row::-webkit-scrollbar-track{background:#edf2ef;border-radius:999px}
        .rail-row::-webkit-scrollbar-thumb{background:#c8d7d0;border-radius:999px}
        .rail-card{background:#fff;border:1px solid var(--line);border-radius:8px;min-height:160px;padding:14px;box-shadow:0 12px 30px rgba(27,50,40,.08);scroll-snap-align:start;display:grid;gap:10px}
        .rail-card h3{margin:0;font-size:15px}
        .rail-card p{margin:0;color:var(--muted);font-size:12px;line-height:1.45}
        .mini-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        .mini-stat{padding:10px 11px;border-radius:8px;background:var(--soft)}
        .mini-stat strong{display:block;font-size:22px;line-height:1}
        .mini-stat span{display:block;color:var(--muted);font-size:12px;text-transform:uppercase;font-weight:900}
        .content-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,.6fr) minmax(0,.4fr);gap:16px;margin-top:16px}
        .leaders-section{margin:18px 0}
        .leaders-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;padding:14px}
        .leader-card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px;display:grid;gap:8px;box-shadow:0 8px 20px rgba(27,50,40,.06)}
        .leader-card-header{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
        .leader-name{font-weight:900;font-size:15px}
        .leader-badges{display:flex;flex-wrap:wrap;gap:4px}
        .leader-badge{background:var(--teal);color:var(--on-teal);border-radius:999px;padding:2px 8px;font-size:12px;font-weight:900}
        .leader-meta{color:var(--muted);font-size:12px}
        .role-management-panel{max-height:600px;overflow-y:auto}
        .role-item{padding:12px 16px;border-bottom:1px solid var(--line);display:grid;gap:6px}
        .role-item:last-child{border-bottom:0}
        .role-item-header{display:flex;justify-content:space-between;align-items:center;gap:8px}
        .role-name{font-weight:900;font-size:13px}
        .role-actions{display:flex;gap:4px}
        .role-actions button{border:1px solid var(--line);background:#fff;color:var(--muted);border-radius:4px;padding:2px 6px;font-size:12px;cursor:pointer}
        .role-actions button:hover{background:var(--soft)}
        .role-members{font-size:12px;color:var(--muted)}
        .role-members-count{font-weight:900;color:var(--ink)}
        .leader-role{color:var(--teal)}
        .section-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line)}
        .section-head h2{margin:0;font-size:16px}
        .section-head a{color:var(--teal);font-weight:900;text-decoration:none;font-size:12px}
        .row{display:grid;grid-template-columns:64px minmax(0,1fr);gap:12px;padding:13px 16px;border-bottom:1px solid #edf2ef}
        .row:last-child{border-bottom:0}
        .date-chip{min-height:54px;border-radius:8px;display:grid;place-items:center;align-content:center;background:var(--soft);font-weight:950;text-align:center}
        .date-chip small{display:block;color:var(--muted);font-size:12px}
        .row-title{font-weight:900;overflow-wrap:anywhere}
        .row-meta{color:var(--muted);font-size:12px}
        .member-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;padding:14px}
        .member-card{border:1px solid #edf2ef;border-radius:8px;padding:12px;background:#fbfdfc;display:grid;gap:6px}
        .member-name{font-weight:900}
        .member-meta{font-size:12px;color:var(--muted)}
        .assignee-pager{display:flex;align-items:center;gap:6px}
        .assignee-pager button{min-height:30px;border:1px solid #dfcbb3;border-radius:7px;background:#fffaf2;color:var(--deep,#123b31);font-size:12px;font-weight:950;padding:4px 8px;cursor:pointer}
        .assignee-pager button:disabled{opacity:.45;cursor:not-allowed}
        .assignee-pager span{min-width:44px;text-align:center;color:#765f4b;font-size:12px;font-weight:900}
        .assignee-carousel{padding:14px}
        .assignee-card{width:100%;border:1px solid #ecdcc8;border-radius:8px;background:#fffaf2;padding:0;display:grid;grid-template-rows:auto 1fr;gap:0;min-height:260px;overflow:hidden;box-shadow:0 12px 28px rgba(55,43,31,.08)}
        .assignee-card[hidden]{display:none}
        .assignee-card.is-featured{border-color:#dfcbb3;box-shadow:0 18px 42px rgba(55,43,31,.14)}
        .assignee-card.is-featured .assignee-date strong{font-size:20px}
        .assignee-card.is-featured .assignee-pill{font-size:13px;padding:4px 10px}
        .assignee-date{display:grid;gap:5px;padding:14px 16px 12px;background:#fff4e6;border-bottom:1px solid #ecdcc8}
        .assignee-date strong{font-size:14px;line-height:1.25;color:#7b2445}
        .assignee-date span{font-size:12px;color:#765f4b;font-weight:900}
        .assignee-body{display:block}
        .assignee-role-list{display:grid;gap:0;background:rgba(255,255,255,.48)}
        .assignee-card.is-scrollable .assignee-role-list{max-height:560px;overflow-y:auto;overscroll-behavior:contain}
        .assignee-card.is-scrollable .assignee-role-list::-webkit-scrollbar{width:8px}
        .assignee-card.is-scrollable .assignee-role-list::-webkit-scrollbar-track{background:#f5eadb;border-radius:999px}
        .assignee-card.is-scrollable .assignee-role-list::-webkit-scrollbar-thumb{background:#dfcbb3;border-radius:999px}
        .assignee-role{display:grid;gap:6px;border-bottom:1px solid #ecdcc8;padding:11px 12px}
        .assignee-role:last-child{border-bottom:0}
        .assignee-role-name{font-size:13px;font-weight:950;color:var(--deep,#123b31)}
        .assignee-names{display:flex;flex-wrap:wrap;gap:5px}
        .assignee-pill{display:inline-flex;align-items:center;border:0;background:#e8eef6;color:#254d74;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:900}
        .empty{padding:18px 16px;color:var(--muted)}
        
        @media (max-width:1000px){.hero,.content-grid{grid-template-columns:1fr}.rail-row{grid-template-columns:repeat(2,minmax(220px,1fr))}.leaders-grid{grid-template-columns:repeat(auto-fit,minmax(250px,1fr))}}
        @media (max-width:640px){.actions{justify-content:flex-start}.summary-grid{grid-template-columns:1fr}.rail-row{grid-template-columns:1fr}.row{grid-template-columns:54px minmax(0,1fr)}}
        /* --- compact two-column ministry layout --- */
        .md-layout{display:grid;grid-template-columns:300px minmax(0,1fr);gap:16px;align-items:start}
        .md-side{display:grid;gap:12px;position:sticky;top:12px}
        .md-sec{background:var(--paper);border:1px solid var(--line);border-radius:10px;box-shadow:0 12px 30px rgba(28,48,39,.07);overflow:hidden}
        .md-sec>summary{list-style:none;cursor:pointer;padding:9px 12px;font-weight:800;font-size:13px;background:#fbfdfc;display:flex;align-items:center;gap:8px}
        .md-sec>summary::-webkit-details-marker{display:none}
        .md-sec>summary::after{content:"\25BE";color:var(--muted);font-size:12px;margin-left:auto}
        .md-sec:not([open])>summary::after{content:"\25B8"}
        .md-sec-body{padding:10px 12px;border-top:1px solid var(--line)}
        .md-kicker{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:900}
        .md-title{margin:2px 0 8px;font-size:18px;line-height:1.15;color:var(--deep)}
        .md-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:10px}
        .md-stat{border:1px solid var(--line);border-radius:8px;padding:7px 4px;text-align:center;background:#fbfdfc}
        .md-stat strong{display:block;font-size:16px;color:var(--deep)}
        .md-stat span{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
        .md-actions{display:grid;gap:6px}
        .md-actions .button{min-height:30px;padding:6px 10px;font-size:12px;width:100%;background:var(--teal);color:var(--on-teal);border:0}
        .md-actions .button.secondary{background:var(--paper);color:var(--deep);border:1px solid var(--line)}
        .md-actions .button:hover{filter:brightness(1.05)}
        .md-mini-btn{border:1px solid var(--line);background:var(--paper);color:var(--teal);border-radius:6px;font:inherit;font-size:12px;font-weight:800;padding:2px 8px;cursor:pointer;margin-left:auto}
        .md-list{display:grid;gap:2px}
        .md-list .empty{padding:8px 2px;color:var(--muted);font-size:12px}
        .md-list .leader-line{display:flex;align-items:center;gap:6px;padding:5px 2px;font-size:13px;font-weight:600;border-bottom:1px dashed var(--line)}
        .md-list .leader-line:last-child{border-bottom:0}
        .md-list .leader-line .lead-crown{color:var(--gold-ink,#92651c)}
        .lead-crown{color:var(--gold-ink,#92651c);margin-right:6px}
        .md-list .leader-card{background:transparent;border:0;border-bottom:1px dashed var(--line);border-radius:0;box-shadow:none;padding:6px 2px;margin:0}
        .md-list .leader-card:last-child{border-bottom:0}
        .md-list .leader-card-header{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
        .md-list .leader-name{font-weight:700;font-size:13px}
        .md-list .leader-badges{display:flex;gap:4px;flex-wrap:wrap}
        .md-list .leader-badge{font-size:12px;background:#fff4d4;color:#7a5400;border-radius:999px;padding:1px 6px;font-weight:800}
        .md-list .leader-meta,.md-list .role-members{font-size:12px;color:var(--muted)}
        .md-list .role-item{border-bottom:1px dashed var(--line);padding:6px 2px}
        .md-list .role-item:last-child{border-bottom:0}
        .md-list .role-item-header{display:flex;align-items:center;justify-content:space-between;gap:6px}
        .md-list .role-name{font-weight:700;font-size:13px}
        .md-list .role-name.leader-role{color:#7a5400}
        .md-list .role-actions{display:flex;gap:4px}
        .md-list .role-actions button{border:1px solid var(--line);background:var(--paper);border-radius:6px;font-size:12px;padding:1px 6px;cursor:pointer}
        .md-list .row{display:grid;grid-template-columns:36px 1fr;gap:8px;align-items:center;padding:5px 2px;border-bottom:1px dashed var(--line)}
        .md-list .row:last-child{border-bottom:0}
        .md-list .date-chip{background:var(--soft);border-radius:6px;text-align:center;padding:3px 2px;font-weight:800;font-size:13px;color:var(--deep);line-height:1}
        .md-list .date-chip small{display:block;font-size:12px;text-transform:uppercase;color:var(--muted)}
        .md-list .row-title{font-size:12px;font-weight:700}
        .md-list .row-meta{font-size:12px;color:var(--muted)}
        .md-main{display:grid;gap:14px}
        .md-soon{background:var(--soft,#eef4f0)}
        .md-soon-lead{margin:0 0 10px;font-size:13px;color:var(--muted)}
        .md-soon-list{list-style:none;margin:0;padding:0;display:grid;gap:8px;
          grid-template-columns:repeat(auto-fit,minmax(210px,1fr))}
        .md-soon-list li{display:grid;gap:2px;padding:10px 12px;background:var(--paper,#fff);
          border:1px solid var(--line);border-radius:var(--radius,8px)}
        .md-soon-name{font-size:13px;font-weight:700;color:var(--ink)}
        .md-soon-desc{font-size:12px;line-height:1.4;color:var(--muted)}
        @media(max-width:640px){.md-soon-list{grid-template-columns:1fr}}
        .md-main-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .panel-body{padding:14px 16px;font-size:13px}
        /* --- compact, low-emphasis (Facebook-like) spacing --- */
        .shell{padding:16px 0 30px}
        .panel{box-shadow:0 1px 2px rgba(27,50,40,.06)}
        .md-sec{box-shadow:0 1px 2px rgba(27,50,40,.06)}
        .section-head{padding:9px 12px}
        .section-head h2{font-size:14px;font-weight:800}
        .md-main{gap:10px}
        .md-main-grid{gap:10px}
        .md-side{gap:10px}
        .panel-body{padding:11px 12px;font-size:13px}
        .md-title{font-size:17px;margin:2px 0 6px}
        /* schedule assignees: lighter + tighter */
        .assignee-carousel{padding:10px}
        .assignee-card{min-height:auto;border:1px solid var(--line);border-radius:8px;background:var(--paper);box-shadow:none}
        .assignee-card.is-featured{box-shadow:none;border-color:var(--line)}
        .assignee-card.is-featured .assignee-date strong{font-size:13px}
        .assignee-card.is-featured .assignee-pill{font-size:12px;padding:2px 8px}
        .assignee-date{padding:8px 12px;gap:2px;background:#fbfdfc}
        .assignee-date strong{font-size:13px;font-weight:700;color:var(--deep)}
        .assignee-date span{font-size:12px;font-weight:500;color:var(--muted)}
        .assignee-role{padding:6px 12px;gap:3px}
        .assignee-role-name{font-size:12px;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.03em}
        .assignee-names{gap:4px}
        .assignee-pill{background:var(--soft);color:var(--ink);font-weight:500;font-size:12px;padding:2px 8px}
        .assignee-pager button{font-weight:700;min-height:26px;padding:3px 8px;background:var(--paper);border-color:var(--line);color:var(--deep)}
        .assignee-pager span{font-weight:600;color:var(--muted)}
        @media(max-width:900px){.md-layout{grid-template-columns:1fr}.md-side{position:static}.md-main-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>" data-ministry-id="<?= (int) $ministryId ?>" data-campuses="<?= $campusesJson ?>" data-ministries="<?= $ministriesJson ?>" data-default-campus="<?= $defaultCampusId !== null ? (int) $defaultCampusId : '' ?>">
    <?= portal_header(
        $basePath,
        '',
        '',
        $campuses,
        $defaultCampusId !== null ? (int) $defaultCampusId : null,
        $actor,
        [
            ['href' => $base . '/',            'label' => 'Dashboard',   'icon' => 'dashboard'],
            ['href' => $base . '/ministries',  'label' => 'Ministries',  'icon' => 'ministry'],
            ['href' => $base . '/calendar',    'label' => 'Calendar',    'icon' => 'calendar'],
            ['href' => $base . '/events',      'label' => 'Events',      'icon' => 'events'],
            ['href' => $base . '/people',      'label' => 'People',      'icon' => 'people'],
            ['href' => $base . '/availability','label' => 'Availability','icon' => 'availability'],
        ],
        [],
        [['href' => $base . '/ministries', 'label' => 'Back to list']],
        'Sign in',
        $base . '/login'
    ) ?>

    <main id="portal-main" tabindex="-1" class="md-layout">
        <!-- LEFT: compact, collapsible ministry panels -->
        <aside class="md-side">
            <details class="md-sec" open>
                <summary>Ministry</summary>
                <div class="md-sec-body">
                    <div class="md-kicker" id="heroKicker">Ministry dashboard</div>
                    <h1 class="md-title" id="heroTitle"><?= htmlspecialchars($ministryName !== '' ? $ministryName : 'Ministry dashboard', ENT_QUOTES, 'UTF-8') ?></h1>
                    <div class="md-stats">
                        <div class="md-stat"><strong id="miniMembers">-</strong><span>members</span></div>
                        <div class="md-stat"><strong id="miniRoles">-</strong><span>roles</span></div>
                        <div class="md-stat"><strong id="leaderCount">0</strong><span>leaders</span></div>
                    </div>
                    <div class="md-actions">
                        <a class="button" id="peopleButton" href="<?= $base ?>/people">People</a>
                        <!-- "Schedule editor" and "Roster schedules" were two
                             names for what a leader hears as one thing, and the
                             one that sounded more official was the one that did
                             not staff Sunday. Serving is the assignments on an
                             activity's date; a posted list is the standing rota
                             that has no activity behind it. -->
                        <a class="button secondary" id="scheduleButton" href="#">Serving grid</a>
                        <a class="button secondary" id="rosterButton" href="#">Posted lists</a>
                        <a class="button secondary" href="<?= $base ?>/calendar">Calendar</a>
                    </div>
                    <span id="heroLead" hidden></span><span id="memberCount" hidden></span><span id="upcomingCount" hidden></span><span id="pastCount" hidden></span><span id="snapshotCount" hidden></span><a id="peopleLink2" hidden href="#"></a><a id="rosterLink2" hidden href="#"></a>
                </div>
            </details>

            <details class="md-sec" open>
                <summary>Leaders</summary>
                <div class="md-sec-body"><div id="leadersList" class="md-list"><div class="empty">Loading…</div></div></div>
            </details>

            <details class="md-sec" open>
                <summary><span>Roles</span><button id="addRoleBtn" class="md-mini-btn" data-permission="manage_ministry_roles" style="display:none">+ Add</button></summary>
                <div class="md-sec-body"><div id="rolesList" class="md-list"><div class="empty">Loading…</div></div></div>
            </details>

            <details class="md-sec" open>
                <summary>Upcoming schedule</summary>
                <div class="md-sec-body"><div id="scheduleList" class="md-list"><div class="empty">Loading…</div></div></div>
            </details>
        </aside>

        <!-- MAIN: assignees + communications + links + documents -->
        <section class="md-main">
            <article class="panel">
                <div class="section-head">
                    <h2>Schedule assignees</h2>
                    <div class="assignee-pager" aria-label="Schedule assignee navigation">
                        <button id="assigneePrev" type="button" disabled>Previous</button>
                        <span id="assigneePage">0 / 0</span>
                        <button id="assigneeNext" type="button" disabled>Next</button>
                    </div>
                </div>
                <div id="assigneeCarousel"><div class="empty">Loading schedule assignees...</div></div>
            </article>

            <!-- Three separate "Coming soon" panels made most of the workspace
                 read as unavailable. Consolidated into one compact area so the
                 working tools dominate. No backend is implied or stubbed. -->
            <article class="panel md-soon">
                <div class="section-head">
                    <h2>More ministry tools</h2>
                    <?= pc_badge('Coming soon', 'neutral') ?>
                </div>
                <div class="panel-body">
                    <p class="md-soon-lead">These are planned for this workspace and are not available yet.</p>
                    <ul class="md-soon-list">
                        <li><span class="md-soon-name">Communications</span><span class="md-soon-desc">Announcements and messages for this ministry.</span></li>
                        <li><span class="md-soon-name">Managed links</span><span class="md-soon-desc">Curated forms, drives and chat groups.</span></li>
                        <li><span class="md-soon-name">Documents</span><span class="md-soon-desc">Guides, rosters and shared files.</span></li>
                    </ul>
                </div>
            </article>
        </section>
    </main>

    <footer class="portal-footer"><span>Church Portal</span><span>Campus-aware ministry dashboard</span></footer>
</div>
<script>
const shell = document.querySelector('.shell');
const basePath = shell.dataset.base || '';
const ministryId = Number(shell.dataset.ministryId || 0);
const seededMinistryName = <?= json_encode($ministryName ?? '') ?>;
const campusSelect = document.getElementById('campusSelect');
const menuButton = document.getElementById('menuButton');
const ministries = JSON.parse(shell.dataset.ministries || '[]');
const campusSelector = JSON.parse(shell.dataset.campuses || '[]');
let dashboardCard = null;
let roster = [];
let scheduleGrid = null;
let assigneeSlideIndex = 0;
let leaders = [];
let roles = [];
let userPermissions = <?= json_encode($permissions) ?>;

function escapeHtml(value){return String(value ?? '').replace(/[&<>"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));}
function selectedCampusId(){return campusSelect?.value || '';}
function campusQuery(name='current_campus_id'){const id=selectedCampusId();return id?`${name}=${encodeURIComponent(id)}`:'';}
function withCampus(url,name='current_campus_id'){const q=campusQuery(name);return q?url+(url.includes('?')?'&':'?')+q:url;}
function formatDateTime(value){if(!value)return 'No upcoming schedule'; const date=new Date(value); if(Number.isNaN(date.getTime())) return 'No upcoming schedule'; return date.toLocaleString([], {weekday:'short', month:'short', day:'numeric', hour:'numeric', minute:'2-digit'});}
function hasPermission(permission) { return Array.isArray(userPermissions) && userPermissions.includes(permission); }
function setButtons(){document.getElementById('peopleButton').href = withCampus(`${basePath}/people?ministry_id=${encodeURIComponent(ministryId)}`); document.getElementById('peopleLink2').href = withCampus(`${basePath}/people?ministry_id=${encodeURIComponent(ministryId)}`); document.getElementById('scheduleButton').href = withCampus(`${basePath}/schedules?ministry_id=${encodeURIComponent(ministryId)}`); document.getElementById('rosterButton').href = withCampus(`${basePath}/rosters?ministry_id=${encodeURIComponent(ministryId)}`); document.getElementById('rosterLink2').href = withCampus(`${basePath}/rosters?ministry_id=${encodeURIComponent(ministryId)}`);
    // Show/hide role management button based on permissions
    const addRoleBtn = document.getElementById('addRoleBtn');
    if (addRoleBtn && hasPermission('manage_ministry_roles')) {
        addRoleBtn.style.display = 'inline-flex';
    }
}

function renderLeaderCard(leader) {
    // Concise: just the leader's name (with a crown marker).
    return `<div class="leader-line"><span class="lead-crown" aria-hidden="true">\u2605</span>${escapeHtml(leader.displayName)}</div>`;
}

function renderRoleItem(role) {
    const isLeader = role.isLeaderRole ? ' leader-role' : '';
    const canManage = hasPermission('manage_ministry_roles');

    return `<div class="role-item">
        <div class="role-item-header">
            <span class="role-name${isLeader}">${escapeHtml(role.name)}</span>
            ${canManage ? `<div class="role-actions">
                <button onclick="editRole(${role.id})" title="Edit role">Edit</button>
                <button onclick="deleteRole(${role.id})" title="Delete role">Delete</button>
            </div>` : ''}
        </div>
        <div class="role-members">
            <span class="role-members-count">${role.assignedCount || 0}</span> assigned
            ${role.assignedMembers && role.assignedMembers.length > 0 ?
                `: ${role.assignedMembers.map(m => escapeHtml(m.displayName)).join(', ')}` : ''}
        </div>
    </div>`;
}

async function loadLeaders() {
    try {
        const response = await fetch(withCampus(`${basePath}/api/ministry/${encodeURIComponent(ministryId)}/leaders?since=-12months`), {
            credentials: 'same-origin'
        });
        const data = await response.json();
        leaders = Array.isArray(data.leaders) ? data.leaders : [];

        const target = document.getElementById('leadersList');
        const countEl = document.getElementById('leaderCount');

        if (leaders.length === 0) {
            target.innerHTML = '<div class="empty">No leaders identified for this ministry.</div>';
            if (countEl) countEl.textContent = '0 leaders';
            return;
        }

        target.className = 'leaders-grid';
        target.innerHTML = leaders.map(renderLeaderCard).join('');
        if (countEl) countEl.textContent = `${leaders.length} leader${leaders.length !== 1 ? 's' : ''}`;
    } catch (err) {
        console.error('Failed to load leaders:', err);
        document.getElementById('leadersList').innerHTML = '<div class="empty">Failed to load leaders.</div>';
    }
}

async function loadRoles() {
    try {
        const response = await fetch(`${basePath}/api/ministry/${encodeURIComponent(ministryId)}/roles`, {
            credentials: 'same-origin'
        });
        const data = await response.json();
        roles = Array.isArray(data.roles) ? data.roles : [];

        const target = document.getElementById('rolesList');

        if (roles.length === 0) {
            target.innerHTML = '<div class="empty">No roles defined for this ministry.</div>';
            return;
        }

        target.innerHTML = roles.map(renderRoleItem).join('');
    } catch (err) {
        console.error('Failed to load roles:', err);
        document.getElementById('rolesList').innerHTML = '<div class="empty">Failed to load roles.</div>';
    }
}

function addRole() {
    if (!hasPermission('manage_ministry_roles')) {
        alert('You do not have permission to manage roles.');
        return;
    }

    const name = prompt('Enter role name:');
    if (!name || name.trim() === '') return;

    const order = prompt('Enter role order (optional):', '0');

    fetch(`${basePath}/api/ministry/${encodeURIComponent(ministryId)}/roles`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
            name: name.trim(),
            order: parseInt(order) || 0,
            active: true
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.role) {
            loadRoles();
            loadLeaders();
        } else {
            alert('Failed to create role.');
        }
    })
    .catch(err => {
        console.error('Failed to create role:', err);
        alert('Failed to create role.');
    });
}

function editRole(roleId) {
    if (!hasPermission('manage_ministry_roles')) {
        alert('You do not have permission to manage roles.');
        return;
    }

    const role = roles.find(r => r.id === roleId);
    if (!role) return;

    const name = prompt('Edit role name:', role.name);
    if (name === null) return; // User cancelled

    const order = prompt('Edit role order:', role.order || 0);
    if (order === null) return;

    const updateData = {};
    if (name !== role.name) updateData.name = name.trim();
    if (parseInt(order) !== (role.order || 0)) updateData.order = parseInt(order) || 0;

    if (Object.keys(updateData).length === 0) return;

    fetch(`${basePath}/api/ministry/roles/${encodeURIComponent(roleId)}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(updateData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.role) {
            loadRoles();
            loadLeaders();
        } else {
            alert('Failed to update role.');
        }
    })
    .catch(err => {
        console.error('Failed to update role:', err);
        alert('Failed to update role.');
    });
}

function deleteRole(roleId) {
    if (!hasPermission('manage_ministry_roles')) {
        alert('You do not have permission to manage roles.');
        return;
    }

    const role = roles.find(r => r.id === roleId);
    if (!role) return;

    if (!confirm(`Are you sure you want to delete the role "${role.name}"? This will remove all assignments and cannot be undone.`)) {
        return;
    }

    fetch(`${basePath}/api/ministry/roles/${encodeURIComponent(roleId)}`, {
        method: 'DELETE',
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadRoles();
            loadLeaders();
        } else {
            alert('Failed to delete role.');
        }
    })
    .catch(err => {
        console.error('Failed to delete role:', err);
        alert('Failed to delete role.');
    });
}
function setText(id, value){const el=document.getElementById(id); if(el) el.textContent = String(value);}
function renderSchedule(card){const target=document.getElementById('scheduleList'); const snapshots=Array.isArray(card?.upcomingSchedule)?card.upcomingSchedule:[]; setText('snapshotCount', snapshots.length); if(snapshots.length===0){target.innerHTML='<div class=\"empty\">No upcoming scheduled assignments in the selected range.</div>'; return;} target.innerHTML=snapshots.slice(0,8).map(item=>`<div class=\"row\"><div class=\"date-chip\"><small>${escapeHtml(new Date(item.startsOn).toLocaleString([], {month:'short'}))}</small>${escapeHtml(new Date(item.startsOn).getDate())}</div><div><div class=\"row-title\">${escapeHtml(item.eventTitle || 'Scheduled item')}</div><div class=\"row-meta\">${escapeHtml(formatDateTime(item.startsOn))} · ${escapeHtml(item.assignmentCount ?? 0)} assignments</div></div></div>`).join('');}
function updateAssigneePager(){const cards=[...document.querySelectorAll('#assigneeCarousel .assignee-card')]; const prev=document.getElementById('assigneePrev'); const next=document.getElementById('assigneeNext'); const page=document.getElementById('assigneePage'); const total=cards.length; if(total===0){if(prev)prev.disabled=true; if(next)next.disabled=true; if(page)page.textContent='0 / 0'; return;} assigneeSlideIndex=Math.max(0,Math.min(assigneeSlideIndex,total-1)); cards.forEach((card,index)=>{card.hidden=index!==assigneeSlideIndex; card.classList.toggle('is-featured',index===assigneeSlideIndex);}); if(prev)prev.disabled=assigneeSlideIndex===0; if(next)next.disabled=assigneeSlideIndex>=total-1; if(page)page.textContent=`${assigneeSlideIndex+1} / ${total}`;}
function renderScheduleAssignees(){const target=document.getElementById('assigneeCarousel'); const grid=scheduleGrid || {}; const rolesById={}; (grid.roles||[]).forEach(role=>{rolesById[Number(role.id)] = role.name || 'Role';}); const occById={}; (grid.occurrences||[]).forEach(occ=>{occById[Number(occ.id)]={...occ, roles:{}};}); (grid.assignments||[]).forEach(assignment=>{const occId=Number(assignment.occurrenceId||assignment.occurrence_id||0); const roleId=Number(assignment.roleId||assignment.role_id||0); if(!occById[occId]) return; if(!occById[occId].roles[roleId]) occById[occId].roles[roleId]=[]; const name=assignment.displayName || assignment.label || 'Assigned'; occById[occId].roles[roleId].push(name);}); const cards=Object.values(occById).filter(occ=>Object.values(occ.roles).some(names=>names.length>0)).sort((a,b)=>new Date(a.startsOn)-new Date(b.startsOn)).slice(0,10); if(cards.length===0){target.className=''; target.innerHTML='<div class=\"empty\">No upcoming assignees in the selected range.</div>'; updateAssigneePager(); return;} target.className='assignee-carousel'; target.innerHTML=cards.map((occ,index)=>{const starts=new Date(occ.startsOn); const timeLabel=Number.isNaN(starts.getTime())?'':starts.toLocaleString([], {weekday:'long', month:'short', day:'numeric', hour:'numeric', minute:'2-digit'}); const roleEntries=Object.entries(occ.roles); const lineCount=roleEntries.reduce((sum,entry)=>sum+1+entry[1].length,0); const roleRows=roleEntries.map(([roleId,names])=>`<div class=\"assignee-role\"><div class=\"assignee-role-name\">${escapeHtml(rolesById[Number(roleId)] || 'Role')}</div><div class=\"assignee-names\">${names.map(name=>`<span class=\"assignee-pill\">${escapeHtml(name)}</span>`).join('')}</div></div>`).join(''); return `<article class=\"assignee-card${lineCount>15?' is-scrollable':''}\"${index===0?'':' hidden'}><div class=\"assignee-date\"><strong>${escapeHtml(occ.eventTitle || 'Scheduled item')}</strong><span>${escapeHtml(timeLabel || 'Upcoming schedule')}</span></div><div class=\"assignee-body\"><div class=\"assignee-role-list\">${roleRows}</div></div></article>`;}).join(''); assigneeSlideIndex=0; updateAssigneePager();}

async function loadFixed() {
    // Lightweight wrapper that prefers the auth-gated dashboard but
    // falls back to the public ministry-board for unauthenticated visitors.
    setButtons();
    const since = new Date(); since.setDate(since.getDate() - 90);
    const until = new Date(); until.setDate(until.getDate() + 90);
    const gridStart = new Date();

    let isAuthed = false;

    // Try auth-gated dashboard first
    try {
        const resp = await fetch(withCampus(basePath + '/api/ministry-dashboard?since=' + since.toISOString().slice(0,10) + '&until=' + until.toISOString().slice(0,10)), { credentials: 'same-origin' });
        const data = await resp.json();
        if (!(resp.status === 401 || (data && data.error && String(data.error).toLowerCase().includes('authentication')))) {
            isAuthed = true;
            const card = (Array.isArray(data.cards) ? data.cards : []).find(item => Number(item.ministryId) === ministryId);
            dashboardCard = card || null;
        }
    } catch (e) {
        // swallow — will try public board next
    }

    // If not authed, fall back to public ministry-board
    if (!isAuthed) {
        try {
            const boardResp = await fetch(withCampus(basePath + '/api/ministry-board?since=' + since.toISOString().slice(0,10) + '&until=' + until.toISOString().slice(0,10)), { credentials: 'same-origin' });
            const boardData = await boardResp.json();
            const card = (Array.isArray(boardData.cards) ? boardData.cards : []).find(item => Number(item.ministryId) === ministryId);
            dashboardCard = card || null;
        } catch (e) {
            dashboardCard = null;
        }
    }

    const current = dashboardCard || { name: seededMinistryName || ('Ministry #' + ministryId), campusId: null, upcomingAssignmentCount: 0, pastAssignmentCount: 0, upcomingSchedule: [], nextOccurrenceAt: null };
    document.getElementById('heroKicker').textContent = current.campusId ? ('Campus #' + current.campusId) : 'Ministry dashboard';
    document.getElementById('heroTitle').textContent = current.name || seededMinistryName || ('Ministry #' + ministryId);
    setText('memberCount', current.upcomingAssignmentCount ?? 0);
    setText('upcomingCount', current.upcomingAssignmentCount ?? 0);
    setText('pastCount', current.pastAssignmentCount ?? 0);

    // Ensure mini-stats show something sensible even for public visitors
    setText('miniMembers', current.upcomingAssignmentCount ?? 0);
    setText('miniRoles', current.roleCount ?? 0);

    renderSchedule(current);
    setButtons();

    // If authenticated, load the detailed panels; otherwise show a sign-in CTA
    if (isAuthed) {
        try {
            scheduleGrid = await fetch(withCampus(basePath + '/api/schedules/grid?ministry_id=' + encodeURIComponent(ministryId) + '&start=' + gridStart.toISOString().slice(0,10) + '&end=' + until.toISOString().slice(0,10)), { credentials: 'same-origin' }).then(r => r.json());
            renderScheduleAssignees();
        } catch (err) {
            scheduleGrid = null;
            document.getElementById('assigneeCarousel').innerHTML = '<div class="empty">Schedule assignees could not be loaded for this session.</div>';
            updateAssigneePager();
        }

        try {
            const rosterData = await fetch(withCampus(basePath + '/api/ministry-roster?ministry_id=' + encodeURIComponent(ministryId), 'campus_id'), { credentials: 'same-origin' }).then(r => r.json());
            const roster = Array.isArray(rosterData.members) ? rosterData.members : [];
            setText('memberCount', roster.length);
            setText('miniMembers', roster.length);
            setText('miniRoles', new Set(roster.flatMap(m => String(m.rolesServed || '').split(',').map(s => s.trim()).filter(Boolean))).size || 0);
        } catch (err) {
            // ignore roster failures
        }

        loadLeaders();
        loadRoles();
    } else {
        // Not authenticated: show CTA for assignees and roles
        document.getElementById('assigneeCarousel').innerHTML = '<div class="empty">Sign in to view schedule assignees.</div>';
        document.getElementById('rolesList').innerHTML = '<div class="empty">Sign in to view roles and members.</div>';
        // loadLeaders() only runs when authenticated, so without this the
        // leaders panel sat at "Loading…" forever for signed-out visitors —
        // indistinguishable from a hung request.
        document.getElementById('leadersList').innerHTML = '<div class="empty">Sign in to view ministry leaders.</div>';
        updateAssigneePager();
    }
}

document.getElementById('assigneePrev')?.addEventListener('click', ()=> { assigneeSlideIndex -= 1; updateAssigneePager(); });
document.getElementById('assigneeNext')?.addEventListener('click', ()=> { assigneeSlideIndex += 1; updateAssigneePager(); });
document.getElementById('addRoleBtn')?.addEventListener('click', addRole);
campusSelect?.addEventListener('change', loadFixed);
loadFixed();
</script>
</body>
</html>
