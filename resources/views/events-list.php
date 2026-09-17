<?php

declare(strict_types=1);

/** @var array<string,mixed> $campusSelector */
/** @var string $basePath */
/** @var ?array<string,mixed> $actor */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$canManageEvents = isset($actor) && in_array('manage_events', $actor['permissions'] ?? [], true);
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
require_once __DIR__ . '/_portal-shell.php';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>Events - Church Portal</title><style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
.brand{display:flex;align-items:center;gap:12px;min-width:0}
.mark{width:40px;height:40px;border-radius:8px;display:grid;place-items:center;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.25);font-weight:900}
.brand-title{font-size:18px;font-weight:900}
.brand-sub{color:rgba(248,255,251,.72);font-size:12px}
.top-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.icon-btn{width:34px;height:34px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.24);border-radius:8px;background:rgba(255,255,255,.1);color:#fff;text-decoration:none;font-weight:900;cursor:pointer}
.dropdown{position:relative}
.dropdown-menu{display:none;position:absolute;right:0;top:40px;min-width:188px;background:#fff;color:var(--ink);border:1px solid var(--line);border-radius:8px;box-shadow:0 14px 34px rgba(28,48,39,.16);padding:6px;z-index:10}
.dropdown.open .dropdown-menu{display:grid}.dropdown-menu a{padding:9px 10px;border-radius:6px;text-decoration:none}.dropdown-menu a:hover{background:var(--soft)}
.button,button.button{display:inline-flex;justify-content:center;align-items:center;min-height:40px;border:0;border-radius:8px;padding:10px 14px;background:#fff;color:var(--deep);font:inherit;font-weight:900;text-decoration:none;cursor:pointer;box-shadow:0 12px 26px rgba(3,20,13,.16)}
.button.secondary{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.24);box-shadow:none}
/* Event list styles (kept from original) */
.panel{background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 14px 34px rgba(28,48,39,.08);overflow:hidden}
.event{display:grid;grid-template-columns:90px minmax(0,1fr);gap:14px;align-items:center;padding:15px 16px;border-bottom:1px solid #edf2ef}
.event:last-child{border-bottom:0}
.date{min-height:60px;border-radius:8px;background:var(--soft);display:grid;place-items:center;align-content:center;font-weight:950}
.date small{display:block;color:var(--muted);font-size:12px}
.title{font-weight:950;font-size:16px}
.title a{color:inherit;text-decoration:none}
.title a:hover,.title a:focus{color:var(--teal);text-decoration:underline}
.open{color:var(--teal);font-weight:900;text-decoration:none}
.empty{padding:22px;color:var(--muted)}

@media(max-width:720px){.actions{display:flex;gap:8px;flex-wrap:wrap}.event{grid-template-columns:64px minmax(0,1fr);}.event .open{grid-column:2}}

        .event a{min-height:24px;display:inline-flex;align-items:center}
        /* A schedule is a table. Cards made a dozen events feel like a hundred. */
        .sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
        .ev-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:12px 14px;border-bottom:1px solid var(--line)}
        .ev-search{flex:1 1 240px;min-width:0}
        .ev-search input{width:100%;font:inherit;padding:9px 11px;border:1px solid var(--line);
            border-radius:8px;background:var(--paper);color:var(--ink);min-height:44px;box-sizing:border-box}
        .ev-count{margin:0;font-size:12px;color:var(--muted)}
        .ev-wrap{overflow-x:auto}
        .ev-periods{display:flex;flex-wrap:wrap;gap:4px;align-items:center;padding:10px 12px 0}
        .ev-period{display:inline-flex;align-items:center;min-height:32px;padding:4px 10px;border:1px solid var(--line);
            border-radius:999px;text-decoration:none;color:var(--ink);font-size:12px;font-weight:700;background:var(--paper)}
        .ev-period:hover{background:var(--soft)}
        .ev-period.is-on{background:var(--teal);color:var(--on-teal,#fff);border-color:var(--teal)}
        .ev-nav{display:inline-flex;align-items:center;gap:6px;margin-left:6px;font-size:12px}
        .ev-nav b{min-width:9ch;text-align:center}
        .ev-step{display:inline-flex;align-items:center;justify-content:center;min-width:32px;min-height:32px;
            border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--ink);background:var(--paper)}
        .ev-step:hover{background:var(--soft)}
        /* Date-led agenda: a soft list/card hybrid. Compact enough for a busy
           month, but not a spreadsheet — the date carries the hierarchy and the
           event title comes second. */
        .ev-agenda{padding:6px 0 4px}
        .ev-morewrap{display:flex;justify-content:center;padding:4px 14px 14px}
        .ev-morewrap [hidden]{display:none}
        .ev-month{margin:14px 14px 6px;font-size:12px;font-weight:800;letter-spacing:.08em;
            text-transform:uppercase;color:var(--muted)}
        .ev-month:first-child{margin-top:6px}
        /* display:grid outranks the UA's [hidden]{display:none}, so setting
           the attribute did nothing: the search filter has never actually
           hidden a day, and the page rendered every one of them regardless. */
        .ev-day[hidden]{display:none}
        .ev-day{display:grid;grid-template-columns:56px minmax(0,1fr);gap:12px;
            padding:8px 14px;border-top:1px solid var(--line)}
        .ev-daydate{text-align:center;padding-top:2px}
        .ev-num{display:block;font-size:22px;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
        .ev-dow{display:block;font-size:10px;font-weight:700;letter-spacing:.06em;color:var(--muted);margin-top:2px}
        .ev-items{list-style:none;margin:0;padding:0;display:grid;gap:2px;min-width:0}
        .ev-item + .ev-item{border-top:1px dashed var(--line)}
        .ev-link{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:2px 10px;
            padding:7px 8px;border-radius:8px;text-decoration:none;color:var(--ink);min-height:44px;align-content:center}
        .ev-link:hover{background:var(--soft)}
        .ev-title{font-size:14px;font-weight:700;overflow-wrap:anywhere}
        .ev-time{grid-column:2;grid-row:1;font-size:12px;color:var(--muted);white-space:nowrap;text-align:right}
        .ev-meta{grid-column:1;font-size:12px;color:var(--muted);overflow-wrap:anywhere}
        .ev-rep{grid-column:2;font-size:11px;color:var(--muted);white-space:nowrap;text-align:right}
        .is-cancelled .ev-title{text-decoration:line-through;color:var(--muted)}
        .ev-off{display:inline-block;text-decoration:none;font-size:10.5px;font-weight:800;
            letter-spacing:.05em;text-transform:uppercase;color:var(--rose-ink,var(--rose));
            border:1px solid currentColor;border-radius:var(--radius-full,999px);padding:0 6px;
            margin-right:5px;vertical-align:1px}
        @media(max-width:560px){
            .ev-day{grid-template-columns:44px minmax(0,1fr);gap:10px;padding:8px 10px}
            .ev-num{font-size:19px}
            .ev-link{grid-template-columns:minmax(0,1fr)}
            .ev-time,.ev-rep{grid-column:1;grid-row:auto;text-align:left}
        }
        .ev-none{padding:18px;color:var(--muted)}
        @media(max-width:760px){
            /* Repeats and Campus fold away; the row still says when and what. */
            .ev-table .ev-rep,.ev-table th:nth-child(4){display:none}
            .ev-table th,.ev-table td{padding:2px 6px}
        }
        @media(max-width:520px){
            .ev-table .ev-campus,.ev-table th:nth-child(5){display:none}
        }
    .ev-notice{padding:12px 16px;margin:0 0 14px;background:#e6f7ec;border:1px solid #b7e3c6;
          border-radius:10px;color:#14663d;font-size:14px;font-weight:700}
</style></head><body><div class="shell" <?= portal_shell_mods('workspace') ?>><?= portal_header(
    $basePath,
    '',
    '',
    $campuses,
    $campusSelector['defaultCampusId'] ?? null,
    $actor,
    [
        ['href' => $base . '/',            'label' => 'Dashboard',   'icon' => 'dashboard'],
        ['href' => $base . '/ministries',  'label' => 'Ministries',  'icon' => 'ministry'],
        ['href' => $base . '/calendar',    'label' => 'Calendar',    'icon' => 'calendar'],
        ['href' => $base . '/events',      'label' => 'Events',      'icon' => 'events'],
        ['href' => $base . '/people',      'label' => 'People',      'icon' => 'people'],
    ],
    [],
    [],
    'Sign in',
    $base . '/login'
) ?>
<main id="portal-main" tabindex="-1">
<?= pc_page_header([
    'kicker'      => 'Events',
    'title'       => 'Events',
    'description' => 'Upcoming events for the selected campus.',
    'actions'     => $canManageEvents
        ? '<a href="' . htmlspecialchars($base . '/events/new', ENT_QUOTES, 'UTF-8') . '" class="pc-btn pc-btn--primary"><span>New event</span></a>'
        : '',
]) ?>
<?php if (isset($_GET['removed'])): ?>
    <!-- Landing back here with no word about it looks like the delete failed. -->
    <div class="ev-notice" role="status">Event deleted.</div>
<?php endif; ?>
<section class="panel">
<?php if (isset($events) && is_array($events) && $events !== []): ?>
    <?php
    $range = $eventRange ?? 'upcoming';
    $anchor = $eventAnchor ?? null;
    $anchorDate = $anchor !== null ? new DateTimeImmutable($anchor) : new DateTimeImmutable('today');
    $qs = static function (array $extra) use ($base): string {
        return $base . '/events?' . http_build_query(array_filter($extra, static fn ($v): bool => $v !== null && $v !== ''));
    };
    $periods = ['upcoming' => 'Upcoming', 'past' => 'Past', 'month' => 'Month', 'quarter' => 'Quarter', 'all' => 'All'];
    $step = $range === 'quarter' ? '3 months' : '1 month';
    $label = $range === 'quarter'
        ? 'Q' . (int) ceil((int) $anchorDate->format('n') / 3) . ' ' . $anchorDate->format('Y')
        : $anchorDate->format('F Y');
    ?>
    <nav class="ev-periods" aria-label="Browse events by period">
        <?php foreach ($periods as $key => $text): ?>
            <a class="ev-period<?= $range === $key ? ' is-on' : '' ?>"
               <?= $range === $key ? 'aria-current="page"' : '' ?>
               href="<?= htmlspecialchars($qs(['range' => $key, 'on' => in_array($key, ['month', 'quarter'], true) ? $anchorDate->format('Y-m-d') : null]), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
        <?php if ($range === 'month' || $range === 'quarter'): ?>
            <span class="ev-nav">
                <a class="ev-step" aria-label="Previous <?= $range === 'quarter' ? 'quarter' : 'month' ?>"
                   href="<?= htmlspecialchars($qs(['range' => $range, 'on' => $anchorDate->modify('-' . $step)->format('Y-m-d')]), ENT_QUOTES, 'UTF-8') ?>">&lsaquo;</a>
                <b><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></b>
                <a class="ev-step" aria-label="Next <?= $range === 'quarter' ? 'quarter' : 'month' ?>"
                   href="<?= htmlspecialchars($qs(['range' => $range, 'on' => $anchorDate->modify('+' . $step)->format('Y-m-d')]), ENT_QUOTES, 'UTF-8') ?>">&rsaquo;</a>
            </span>
        <?php endif; ?>
    </nav>
    <div class="ev-tools">
        <label class="ev-search">
            <span class="sr">Search events</span>
            <input type="search" id="evSearch" placeholder="Search by name, campus or place" autocomplete="off">
        </label>
        <p class="ev-count" id="evCount" aria-live="polite"></p>
    </div>
    <div class="ev-agenda" id="evAgenda">
    <?php
    $currentMonth = null;
    foreach (($agenda ?? []) as $ymd => $items):
        $day = new DateTimeImmutable($ymd);
        $month = $day->format('F Y');
        if ($month !== $currentMonth): $currentMonth = $month; ?>
            <h2 class="ev-month"><?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php endif; ?>
        <?php
        $hay = strtolower($ymd . ' ' . implode(' ', array_map(
            static fn (array $i): string => ($i['title'] ?? '') . ' ' . ($i['campus_names'] ?? '') . ' '
                . ($i['location_name'] ?? '') . ' ' . ($i['ministry_name'] ?? ''), $items)));
        ?>
        <section class="ev-day" data-search="<?= htmlspecialchars($hay, ENT_QUOTES, 'UTF-8') ?>">
            <div class="ev-daydate">
                <span class="ev-num"><?= htmlspecialchars($day->format('d'), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="ev-dow"><?= htmlspecialchars(strtoupper($day->format('D')), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <ul class="ev-items">
                <?php foreach ($items as $i):
                    $st = new DateTimeImmutable((string) $i['starts_at']);
                    $en = !empty($i['ends_at']) ? new DateTimeImmutable((string) $i['ends_at']) : null;
                    // Midnight start with a midnight (or absent) end is all-day.
                    // end == start means "no stated finish"; midnight means all-day.
                    $hasEnd = $en !== null && $en > $st;
                    $allDay = $st->format('H:i') === '00:00' && !$hasEnd;
                    $time = $allDay
                        ? 'All day'
                        : $st->format('g:i A') . ($hasEnd ? ' – ' . $en->format('g:i A') : '');
                    $campus = (string) ($i['campus_names'] ?? '');
                    $host = (string) ($i['host_campus_name'] ?? '');
                    $loc = (string) ($i['location_name'] ?? '');
                    $ministry = (string) ($i['ministry_name'] ?? '');
                    $repeats = (int) ($i['occurrence_count'] ?? 1) > 1;

                    // Campus and location are different things, but repeating
                    // one as the other reads as noise: "NorthYork · NorthYork".
                    // Show a place only when it actually adds information — a
                    // custom venue, or a host campus that is not simply the
                    // campus the event belongs to.
                    $campusList = array_values(array_filter(array_map('trim', explode(',', $campus))));
                    // A host campus is stored alongside the campuses the event is
                    // for, so a Scarborough event held at North York would
                    // otherwise read "All campuses". Whom it is for is the
                    // campus list minus a host that is only there to host.
                    $relevant = $host !== '' && count($campusList) > 1
                        ? array_values(array_diff($campusList, [$host]))
                        : $campusList;
                    $campusLabel = count($relevant) > 1 ? 'All campuses' : ($relevant[0] ?? '');
                    $where = '';
                    if ($loc !== '') {
                        $where = $loc;
                    } elseif ($host !== '' && (count($campusList) > 1 || $host !== ($campusList[0] ?? ''))) {
                        $where = 'at ' . $host;
                    }
                    $meta = array_values(array_filter([
                        $where !== '' ? $where : null,
                        $campusLabel !== '' ? $campusLabel : null,
                        $ministry !== '' ? $ministry : null,
                    ]));
                    ?>
                    <li class="ev-item<?= !empty($i['is_cancelled']) ? ' is-cancelled' : '' ?>">
                        <a class="ev-link" href="<?= $base ?>/events/<?= (int) ($i['event_id'] ?? 0) ?>">
                            <span class="ev-title">
                                <?php if (!empty($i['is_cancelled'])): ?>
                                    <!-- The word, not only the strike-through. A
                                         line through the title is decoration: a
                                         screen reader does not announce it and a
                                         printed list loses it. The calendar feed
                                         says it the same way. -->
                                    <span class="ev-off">Cancelled</span>
                                <?php endif; ?>
                                <?= htmlspecialchars((string) ($i['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="ev-time"><?= htmlspecialchars($time, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($meta !== []): ?>
                                <span class="ev-meta"><?= htmlspecialchars(implode(' · ', $meta), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <?php if ($repeats): ?>
                                <span class="ev-rep" title="Repeating event">&#8635; repeats</span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
    </div>
    <div class="ev-morewrap">
        <button type="button" class="pc-btn pc-btn--secondary pc-btn--md" id="evMore" hidden></button>
    </div>
    <p class="ev-none" id="evNone" hidden>No events match that filter.</p>
<?php elseif (($agenda ?? []) === []): ?><?= pc_empty_state([
    'icon'  => 'events',
    'title' => 'No events scheduled for this period',
    'body'  => $canManageEvents
        ? 'Add one, or switch period above to see other months.'
        : 'Try another period above.',
    'action' => $canManageEvents
        ? '<a class="pc-btn pc-btn--primary" href="' . htmlspecialchars($base . '/events/new', ENT_QUOTES, 'UTF-8') . '"><span>Add event</span></a>'
        : '',
]) ?>
<?php else: ?><?= pc_empty_state([
    'icon'  => 'events',
    'title' => 'No upcoming events',
    'body'  => $actor === null
        ? 'Sign in to see events for your campus.'
        : 'There are no events scheduled for the selected campus yet.',
    'action' => $actor === null
        ? pc_button(['label' => 'Sign in', 'href' => $base . '/login', 'variant' => 'primary'])
        : '',
]) ?><?php endif; ?>
</section></main><footer class="portal-footer"><span>Church Portal</span><span>Campus-aware event planning</span></footer></div><script>

// Event creation lives on /events/new — a real form. This used to be a
// prompt() posting a bare title to an endpoint that could not succeed.
(function () {
    const search = document.getElementById('evSearch');
    const rows = [...document.querySelectorAll('.ev-day')];
    const count = document.getElementById('evCount');
    const none = document.getElementById('evNone');
    const more = document.getElementById('evMore');
    if (!rows.length) return;

    // A year of weekly services across every event is 157 days, and this page
    // rendered all of them: 22,000px, near enough twenty-five screens. The rest
    // stays in the document so searching still reaches it and Ctrl+F still
    // works — it is simply not all drawn at once.
    const FIRST = 30;
    let expanded = rows.length <= FIRST;

    function apply() {
        const q = (search?.value || '').trim().toLowerCase();
        let matched = 0;
        let shown = 0;
        rows.forEach(r => {
            const hit = !q || r.dataset.search.includes(q);
            if (hit) matched++;
            // Searching looks through everything: a capped list that hid
            // matches would be worse than a long one.
            const on = hit && (expanded || q !== '' || matched <= FIRST);
            r.hidden = !on;
            if (on) shown++;
        });
        const items = document.querySelectorAll('.ev-day:not([hidden]) .ev-item').length;
        count.textContent = `${items} event${items === 1 ? '' : 's'} across ${shown} day${shown === 1 ? '' : 's'}`;
        if (none) none.hidden = matched !== 0;
        if (more) {
            const hiddenDays = matched - shown;
            more.hidden = hiddenDays <= 0;
            more.textContent = `Show ${hiddenDays} more day${hiddenDays === 1 ? '' : 's'}`;
        }
    }
    more?.addEventListener('click', function () {
        expanded = true;
        apply();
        // Focus the first newly revealed day so a keyboard user is not left at
        // a button that has just vanished.
        document.querySelectorAll('.ev-day:not([hidden])')[FIRST]?.scrollIntoView({ block: 'nearest' });
        search?.focus();
    });
    search?.addEventListener('input', apply);
    apply();
})();
</script><script>
// Restored from the browser's back/forward cache, this page is a snapshot: an
// event deleted since would still be listed here. Server-rendered pages cannot
// be fixed with a cache header, so the restore is what triggers the reload.
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
</script>
</body></html>
