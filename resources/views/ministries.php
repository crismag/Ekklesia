<?php
/**
 * Ministry schedule board.
 *
 * Left pane: collapsible Filters + Ministries. Main body: assignments grouped
 * by Date → Ministry (each event/role/date mentioned once via hierarchy), in
 * two compact view modes — Card and Table.
 *
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/_portal-shell.php';
$minSettings = \App\Providers\PortalServiceProvider::makeMinistriesSettingsService()->load();
$title = $minSettings['title'] ?? 'Ministry Schedule Board';
$subtitle = $minSettings['subtitle'] ?? 'Who is assigned across ministries — grouped by date and ministry.';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Ministry Schedule Board · Church Portal</title>
    <style>
        :root{--ink:#17211b;--muted:#66756d;--line:#d9e4dd;--paper:#fff;--deep:#123b31;--teal:#117b6d;--soft:#eef4f0;--bg:#f7faf8;--gold:#c48725;--gradient-top:#0c2f28;--gradient-mid:#123b31}
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:linear-gradient(180deg,var(--gradient-top) 0,var(--gradient-mid) 150px,var(--bg) 151px)}
        a{color:inherit}
        .mb-titleblock{color:#f8fffb;margin:0 0 16px}
        .mb-titleblock h1{margin:0;font-size:clamp(22px,3vw,30px)}
        .mb-titleblock .sub{color:rgba(248,255,251,.8);font-size:13px;margin-top:4px}
        .mb-layout{display:grid;grid-template-columns:280px minmax(0,1fr);gap:16px;align-items:start}
        /* left pane */
        .mb-side{display:grid;gap:12px;position:sticky;top:12px}
        .mb-sec{background:var(--paper);border:1px solid var(--line);border-radius:10px;box-shadow:0 12px 30px rgba(28,48,39,.07);overflow:hidden}
        .mb-sec>summary{list-style:none;cursor:pointer;padding:11px 14px;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:space-between;background:#fbfdfc}
        .mb-sec>summary::-webkit-details-marker{display:none}
        .mb-sec>summary::after{content:"▾";color:var(--muted);font-size:12px;transition:transform .15s}
        .mb-sec:not([open])>summary::after{transform:rotate(-90deg)}
        .mb-sec-body{padding:12px 14px;border-top:1px solid var(--line);display:grid;gap:10px}
        .mb-field{display:grid;gap:4px}
        .mb-field label{font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        .mb-field input,.mb-field select{width:100%;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:inherit;background:var(--paper);color:var(--ink)}
        .mb-row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        .mb-modes{display:flex;gap:6px}
        .mb-modes button{flex:1;border:1px solid var(--line);background:var(--paper);color:var(--deep);border-radius:8px;padding:8px;font:inherit;font-weight:800;cursor:pointer}
        .mb-modes button.on{background:var(--teal);color:var(--on-teal);border-color:var(--teal)}
        .button{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:36px;padding:8px 12px;border-radius:8px;background:var(--teal);color:var(--on-teal);border:0;font:inherit;font-weight:800;cursor:pointer;text-decoration:none}
        .button.secondary{background:var(--paper);color:var(--deep);border:1px solid var(--line)}
        .mb-checks{border:1px solid var(--line);border-radius:8px;max-height:260px;overflow:auto;padding:4px 8px}
        .mb-checks label{display:flex;align-items:center;gap:7px;font-size:13px;padding:3px 2px;cursor:pointer}
        .mb-checks-tools{display:flex;gap:12px;margin-bottom:4px}
        .mb-checks-tools a{font-size:12px;color:var(--teal);font-weight:800;cursor:pointer}
        /* main */
        .mb-main{display:grid;gap:12px}
        .mb-note{color:rgba(248,255,251,.9);font-size:12px}
        .mb-empty{background:var(--paper);border:1px solid var(--line);border-radius:10px;padding:26px;text-align:center;color:var(--muted)}
        .day{background:var(--paper);border:1px solid var(--line);border-radius:10px;box-shadow:0 12px 30px rgba(28,48,39,.07);overflow:hidden}
        .day-head{background:var(--deep);color:#f4fffb;padding:9px 16px;font-weight:800;font-size:14px;display:flex;align-items:center;gap:8px}
        .day-head .dm{color:rgba(244,255,251,.7);font-weight:600;font-size:12px}
        .day-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;padding:14px}
        .mcard{border:1px solid var(--line);border-radius:9px;overflow:hidden;background:#fbfdfc}
        .mcard>h3{margin:0;font-size:14px;padding:9px 12px;background:var(--soft);color:var(--deep);border-bottom:1px solid var(--line)}
        .occ{padding:9px 12px;border-bottom:1px dashed var(--line)}
        .occ:last-child{border-bottom:0}
        .occ-head{font-weight:800;font-size:12px;margin-bottom:5px}
        .occ-head .time{color:var(--muted);font-weight:600}
        .rline{display:grid;grid-template-columns:120px minmax(0,1fr);gap:8px;font-size:13px;padding:2px 0}
        .rline .rname{color:var(--teal);font-weight:800}
        /* table mode */
        .mb-tblwrap{background:var(--paper);border:1px solid var(--line);border-radius:10px;box-shadow:0 12px 30px rgba(28,48,39,.07);overflow:auto}
        .mb-tbl{width:100%;border-collapse:collapse}
        .mb-tbl th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding:8px 12px;border-bottom:2px solid var(--line);position:sticky;top:0;background:var(--paper)}
        .mb-tbl td{padding:7px 12px;border-bottom:1px solid var(--line);font-size:13px;vertical-align:top}
        .mb-tbl tr.d-row td{background:var(--deep);color:#f4fffb;font-weight:800;font-size:12px}
        .mb-tbl .mname{font-weight:800}
        .mb-tbl .rname{color:var(--teal);font-weight:800}
        /* compact left pane so controls never overflow the 280px card */
        .mb-side{gap:10px}
        .mb-sec>summary{padding:8px 11px;font-size:12px}
        .mb-sec-body{padding:9px 11px;gap:8px}
        .mb-field label{font-size:12px}
        .mb-field input,.mb-field select{padding:6px 8px;font-size:12px;min-width:0}
        .mb-row2{gap:7px}
        .mb-modes button{padding:6px 4px;font-size:12px}
        .button{min-height:32px;padding:6px 10px;font-size:12px}
        .mb-checks{max-height:220px;padding:3px 7px}
        .mb-checks label{font-size:12px;padding:2px 2px}
        .mb-mrow{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:8px;padding:2px 1px}
        .mb-mrow input{margin:0}
        .mb-mlink{font-size:12px;text-decoration:none;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .mb-mlink:hover,.mb-mlink:focus{color:var(--teal);text-decoration:underline}
        .mb-mlink .cnt{color:var(--muted);font-size:12px}
        .mb-opt{display:flex;align-items:center;gap:7px;font-size:12px;cursor:pointer}
        @media(max-width:900px){.mb-layout{grid-template-columns:1fr}.mb-side{position:static}}
        /* WCAG 2.5.8 (AA), desktop floor: board controls were 13x13 checkboxes
           and 18px links. These sit BEFORE the <=900px block below so the 44px
           touch rules there still win on small screens — the reverse order
           silently reverted mobile from 25 to 38 sub-44px targets. */
        .mck{width:24px;height:24px}
        .mb-mlink{min-height:24px;display:inline-flex;align-items:center}
        .mb-opt input[type=checkbox]{width:24px;height:24px}
        .mb-opt{min-height:24px}
        .mb-sec-body a,.mb-sec-body button{min-height:24px;display:inline-flex;align-items:center}
        .mb-sec-body input[type=checkbox]{width:24px;height:24px}
        .mb-checks-tools a{min-height:24px;min-width:24px;display:inline-flex;align-items:center;justify-content:center}

        /* NOTE: .mb-sec-body a is (0,1,1) and beats a bare .mb-mlink (0,1,0)
           whatever the source order, so the touch rules must match that
           specificity or they silently lose. */
        @media(max-width:900px){
          .mck,.mb-opt input[type=checkbox],.mb-sec-body input[type=checkbox]{width:24px;height:24px}
          .mb-sec-body a.mb-mlink,.mb-mlink{min-height:44px}
          .mb-sec-body a,.mb-sec-body button{min-height:44px}
          .mb-mrow{min-height:44px}
          .mb-sec-body label.mb-opt,.mb-opt{min-height:44px}
        }

        /* Board is now a secondary destination reached from the chooser. On
           small screens the three filter panels stacked to ~800px ABOVE the
           results (audit H2), and the option rows were ~20px tall (H5). */
        @media(max-width:900px){
          .mb-side{position:static;gap:8px}
          .mb-sec>summary{min-height:48px;display:flex;align-items:center;gap:8px;
            padding:0 12px;font-size:14px;font-weight:700;cursor:pointer;
            background:var(--soft);border:1px solid var(--line);border-radius:var(--radius,8px)}
          .mb-sec[open]>summary{border-bottom-left-radius:0;border-bottom-right-radius:0}
          .mb-opt{min-height:44px;font-size:14px;gap:10px;padding:2px 0}
          .mb-opt input[type=checkbox]{width:24px;height:24px;flex:0 0 auto}
          .mb-field label{font-size:13px}
          .mb-field input,.mb-field select{min-height:44px;font-size:16px}
          /* Ministry selector rows: 15 checkbox+link pairs sat at ~20px. */
          .mb-mrow{min-height:44px;display:flex;align-items:center;gap:10px}
          .mb-mlink{font-size:14px;display:flex;align-items:center;min-height:44px;flex:1;min-width:0}
          .mck{width:20px;height:20px;flex:0 0 auto}
        }
        @media print{
            @page{margin:12mm}
            body{background:#fff;color:#000}
            .shell{width:100%;padding:0}
            .topbar,.portal-header,.portal-footer,.mb-side,.mb-titleblock,.mb-note{display:none!important}
            .mb-layout{grid-template-columns:1fr}
            .day,.mb-tblwrap{border:0;box-shadow:none}
            .day-head,.mb-tbl tr.d-row td{background:#eee!important;color:#000!important;border-bottom:1px solid #000}
            .mcard{break-inside:avoid;border:1px solid #ccc}
            .day{break-inside:avoid}
        }
    

    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>">
    <?= portal_header($basePath, '', '', [], null, $actor, [
        ['href' => $base . '/', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['href' => $base . '/ministries', 'label' => 'Ministries', 'icon' => 'ministry'],
        ['href' => $base . '/calendar', 'label' => 'Calendar', 'icon' => 'calendar'],
        ['href' => $base . '/events', 'label' => 'Events', 'icon' => 'events'],
        ['href' => $base . '/printables', 'label' => 'Printables', 'icon' => 'search'],
    ], [], [], 'Sign in', $base . '/login') ?>
<main id="portal-main" tabindex="-1">


    <div class="mb-titleblock">
        <h1><?= htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="sub"><?= htmlspecialchars((string) $subtitle, ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="mb-layout">
        <aside class="mb-side">
            <details class="mb-sec" open>
                <summary>Filters</summary>
                <div class="mb-sec-body">
                    <div class="mb-row2">
                        <div class="mb-field"><label for="sinceDate">From</label><input id="sinceDate" type="date"></div>
                        <div class="mb-field"><label for="untilDate">To</label><input id="untilDate" type="date"></div>
                    </div>
                    <div class="mb-field"><label for="searchInput">Search</label><input id="searchInput" type="search" placeholder="Ministry, event, or person…" autocomplete="off"></div>
                    <div class="mb-field"><label for="eventSelect">Event</label><select id="eventSelect"><option value="">All events</option></select></div>
                    <div class="mb-field"><label>View</label>
                        <div class="mb-modes"><button type="button" id="modeCard" class="on">Cards</button><button type="button" id="modeTable">Table</button></div>
                    </div>
                    <button class="button secondary" id="printButton" type="button">🖨 Print / Save as PDF</button>
                </div>
            </details>
            <details class="mb-sec" open>
                <summary>Display</summary>
                <div class="mb-sec-body">
                    <label class="mb-opt"><input type="checkbox" id="optHideEvent"> Hide event name &amp; time</label>
                    <label class="mb-opt"><input type="checkbox" id="optCompact"> Compact names (last initial)</label>
                </div>
            </details>
            <details class="mb-sec" open>
                <summary>Ministries</summary>
                <div class="mb-sec-body">
                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--muted);min-height:24px"><input type="checkbox" id="schedOnly" style="width:24px;height:24px"> With upcoming schedules only</label>
                    <div class="mb-checks-tools"><a id="minAll">All</a><a id="minNone">None</a></div>
                    <div class="mb-checks" id="ministryChecks"><div style="color:var(--muted);font-size:12px">Loading…</div></div>
                </div>
            </details>
        </aside>

        <section class="mb-main">
            <div class="mb-note" id="rangeNote">Loading…</div>
            <div id="board"><div class="mb-empty">Loading schedule board…</div></div>
        </section>
    </div>

    </main>
<?= portal_footer('Church Portal', 'Ministry schedule board') ?>
</div>
<script>
(function () {
    "use strict";
    const shell = document.querySelector('.shell');
    const BASE = shell.dataset.base || '';
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
    const isoDate = (d) => d.toISOString().slice(0, 10);
    // "Gift and Arrows" → "gift_and_arrows" for friendly /ministry/<slug> links
    const slugify = (name) => String(name || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

    function currentCampus() { const s = document.getElementById('campusSelect'); return s ? (s.value || '') : ''; }
    function withCampus(path) { const c = currentCampus(); return c ? path + (path.includes('?') ? '&' : '?') + 'current_campus_id=' + encodeURIComponent(c) : path; }

    const $ = (id) => document.getElementById(id);
    const sinceInput = $('sinceDate'), untilInput = $('untilDate'), searchInput = $('searchInput'),
        eventSelect = $('eventSelect'), board = $('board'), rangeNote = $('rangeNote');

    let assignments = [];       // flat rows from the board endpoint
    let allMinistries = [];     // full ministry list (incl. no-schedule ones)
    let schedSet = {};          // ministry_id -> true when it has assignments in range
    let schedCount = {};        // ministry_id -> number of upcoming scheduled occurrences
    let hiddenMinistries = {};  // id -> true when unchecked
    let schedulesOnly = false;  // list only ministries with upcoming schedules
    let viewMode = localStorage.getItem('mbView') || 'card';
    let hideEvent = localStorage.getItem('mbHideEvent') === '1';   // hide event name/time
    let compactNames = localStorage.getItem('mbCompact') === '1';  // last name → initial

    // "Ralph Cantimbulan" → "Ralph C." when compact mode is on
    function fmtName(n) {
        if (!compactNames) return n;
        const t = String(n).trim().split(/\s+/);
        if (t.length < 2) return n;
        const last = t.pop();
        return t.join(' ') + ' ' + last.charAt(0) + '.';
    }
    function peopleStr(list, dash) {
        return list.length ? list.map((p) => esc(fmtName(p))).join(', ') : dash;
    }

    // default range: today → +30 days
    (function () { const t = new Date(), u = new Date(); u.setDate(u.getDate() + 30); sinceInput.value = isoDate(t); untilInput.value = isoDate(u); })();

    function fmtDayHead(iso) {
        const d = new Date(iso);
        return { wk: d.toLocaleDateString([], { weekday: 'long' }), md: d.toLocaleDateString([], { month: 'long', day: 'numeric', year: 'numeric' }) };
    }
    function fmtTime(iso) { const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }

    // Full ministry list (independent of the date range / schedules).
    async function loadMinistryList() {
        try {
            const res = await fetch(BASE + withCampus('/api/public/ministries'), { credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            allMinistries = (Array.isArray(data.ministries) ? data.ministries : [])
                .map((m) => ({ id: m.ministry_id, name: m.name }))
                .sort((a, b) => a.name.localeCompare(b.name));
        } catch (e) { allMinistries = []; }
    }

    async function loadBoard() {
        board.innerHTML = '<div class="mb-empty">Loading schedule board…</div>';
        try {
            const url = BASE + withCampus('/api/public/schedule-board?since=' + encodeURIComponent(sinceInput.value) + '&until=' + encodeURIComponent(untilInput.value));
            const res = await fetch(url, { credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.error || ('Request failed ' + res.status));
            assignments = Array.isArray(data.assignments) ? data.assignments : [];
        } catch (e) {
            assignments = [];
            rangeNote.textContent = '';
            board.innerHTML = '<div class="mb-empty">' + esc(e.message || 'The schedule board could not be loaded.') + '</div>';
        }
        // ministries with any assignment in the current range + how many
        // distinct scheduled occurrences each has (upcoming schedule count)
        schedSet = {};
        const occByMin = {};
        assignments.forEach((a) => {
            schedSet[a.ministry_id] = true;
            (occByMin[a.ministry_id] = occByMin[a.ministry_id] || new Set()).add(a.occurrence_id);
        });
        schedCount = {};
        Object.keys(occByMin).forEach((k) => { schedCount[k] = occByMin[k].size; });
        // fall back to ministries seen in the board if the full list is unavailable
        if (allMinistries.length === 0) {
            const seen = {};
            assignments.forEach((a) => { if (!seen[a.ministry_id]) seen[a.ministry_id] = a.ministry_name || ('Ministry #' + a.ministry_id); });
            allMinistries = Object.keys(seen).map((id) => ({ id: Number(id), name: seen[id] })).sort((a, b) => a.name.localeCompare(b.name));
        }
        rebuildEvents();
        renderChecklist();
        render();
    }

    function rebuildEvents() {
        const evs = [...new Set(assignments.map((a) => (a.event_title || '').trim()).filter(Boolean))].sort();
        const cur = eventSelect.value;
        eventSelect.innerHTML = '<option value="">All events</option>' + evs.map((e) => '<option value="' + esc(e) + '">' + esc(e) + '</option>').join('');
        if (evs.includes(cur)) eventSelect.value = cur;
    }

    function renderChecklist() {
        let list = allMinistries;
        if (schedulesOnly) list = list.filter((m) => schedSet[m.id]);
        $('ministryChecks').innerHTML = list.length
            ? list.map((m) => {
                const n = schedCount[m.id] || 0;
                return '<div class="mb-mrow">' +
                    '<input type="checkbox" class="mck" value="' + m.id + '"' + (hiddenMinistries[m.id] ? '' : ' checked') + ' aria-label="Show ' + esc(m.name) + ' in board">' +
                    '<a class="mb-mlink" href="' + BASE + '/ministry/' + slugify(m.name) + '" title="Open ' + esc(m.name) + '">' + esc(m.name) + ' <span class="cnt">(' + n + ')</span></a>' +
                '</div>';
            }).join('')
            : '<div style="color:var(--muted);font-size:12px">' + (schedulesOnly ? 'No ministries with upcoming schedules in range.' : 'No ministries available.') + '</div>';
    }

    function filteredRows() {
        const q = (searchInput.value || '').trim().toLowerCase();
        const ev = eventSelect.value;
        return assignments.filter((a) =>
            !hiddenMinistries[a.ministry_id] &&
            (!ev || (a.event_title || '') === ev) &&
            (!q || (a.ministry_name + ' ' + a.event_title + ' ' + a.person_name).toLowerCase().includes(q)));
    }

    // group rows → date → ministry → occurrence → role → people
    function group(rows) {
        const days = {};
        rows.forEach((a) => {
            const dk = a.starts_on.slice(0, 10);
            const day = days[dk] || (days[dk] = { key: dk, startsOn: a.starts_on, ministries: {} });
            const m = day.ministries[a.ministry_id] || (day.ministries[a.ministry_id] = { id: a.ministry_id, name: a.ministry_name, occ: {} });
            const o = m.occ[a.occurrence_id] || (m.occ[a.occurrence_id] = { id: a.occurrence_id, title: a.event_title || 'Scheduled item', startsOn: a.starts_on, roles: {} });
            const r = o.roles[a.serving_role_id] || (o.roles[a.serving_role_id] = { id: a.serving_role_id, name: a.role_name || 'Assigned', people: [] });
            if (a.person_name) r.people.push(a.person_name);
        });
        return Object.values(days).sort((x, y) => new Date(x.startsOn) - new Date(y.startsOn))
            .map((d) => ({
                ...d,
                ministries: Object.values(d.ministries).sort((a, b) => a.name.localeCompare(b.name))
                    .map((m) => ({ ...m, occ: Object.values(m.occ).sort((a, b) => new Date(a.startsOn) - new Date(b.startsOn)) })),
            }));
    }

    function renderCards(days) {
        return days.map((d) => {
            const h = fmtDayHead(d.startsOn);
            return '<section class="day"><div class="day-head">' + esc(h.wk) + ' <span class="dm">· ' + esc(h.md) + '</span></div>' +
                '<div class="day-grid">' + d.ministries.map((m) =>
                    '<article class="mcard"><h3>' + esc(m.name) + '</h3>' + m.occ.map((o) => {
                        const head = hideEvent ? '' : '<div class="occ-head">' + esc(o.title) + ' <span class="time">· ' + esc(fmtTime(o.startsOn)) + '</span></div>';
                        return '<div class="occ">' + head +
                            Object.values(o.roles).map((r) =>
                                '<div class="rline"><span class="rname">' + esc(r.name) + '</span><span>' + peopleStr(r.people, '<em style="color:var(--muted)">—</em>') + '</span></div>').join('') +
                            '</div>';
                    }).join('') +
                    '</article>').join('') +
                '</div></section>';
        }).join('');
    }

    function renderTable(days) {
        const cols = hideEvent ? 3 : 4;
        let rows = '';
        days.forEach((d) => {
            const h = fmtDayHead(d.startsOn);
            rows += '<tr class="d-row"><td colspan="' + cols + '">' + esc(h.wk) + ' · ' + esc(h.md) + '</td></tr>';
            d.ministries.forEach((m) => {
                let firstMin = true;
                m.occ.forEach((o) => {
                    let firstOcc = true;
                    Object.values(o.roles).forEach((r) => {
                        const eventCell = hideEvent ? '' :
                            '<td>' + (firstOcc ? esc(o.title) + ' <span style="color:var(--muted)">· ' + esc(fmtTime(o.startsOn)) + '</span>' : '') + '</td>';
                        rows += '<tr>' +
                            '<td class="mname">' + (firstMin ? esc(m.name) : '') + '</td>' +
                            eventCell +
                            '<td class="rname">' + esc(r.name) + '</td>' +
                            '<td>' + peopleStr(r.people, '—') + '</td></tr>';
                        firstMin = false; firstOcc = false;
                    });
                });
            });
        });
        const eventTh = hideEvent ? '' : '<th>Event</th>';
        return '<div class="mb-tblwrap"><table class="mb-tbl"><thead><tr><th>Ministry</th>' + eventTh + '<th>Role</th><th>Assignees</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }

    function render() {
        const rows = filteredRows();
        const days = group(rows);
        const people = rows.filter((r) => r.person_name).length;
        rangeNote.textContent = rows.length
            ? (people + ' assignment' + (people === 1 ? '' : 's') + ' · ' + days.length + ' date' + (days.length === 1 ? '' : 's'))
            : '';
        board.innerHTML = days.length
            ? (viewMode === 'table' ? renderTable(days) : renderCards(days))
            : '<div class="mb-empty">No assignments match the current filters.</div>';
    }

    function setMode(m) {
        viewMode = m; localStorage.setItem('mbView', m);
        $('modeCard').classList.toggle('on', m === 'card');
        $('modeTable').classList.toggle('on', m === 'table');
        render();
    }

    // events
    sinceInput.addEventListener('change', loadBoard);
    untilInput.addEventListener('change', loadBoard);
    searchInput.addEventListener('input', render);
    eventSelect.addEventListener('change', render);
    $('modeCard').addEventListener('click', () => setMode('card'));
    $('modeTable').addEventListener('click', () => setMode('table'));
    $('printButton').addEventListener('click', () => window.print());
    $('ministryChecks').addEventListener('change', (e) => {
        if (!e.target.classList.contains('mck')) return;
        hiddenMinistries[e.target.value] = !e.target.checked;
        render();
    });
    $('minAll').addEventListener('click', () => { hiddenMinistries = {}; document.querySelectorAll('.mck').forEach((c) => c.checked = true); render(); });
    $('minNone').addEventListener('click', () => { document.querySelectorAll('.mck').forEach((c) => { c.checked = false; hiddenMinistries[c.value] = true; }); render(); });
    $('schedOnly').addEventListener('change', (e) => { schedulesOnly = e.target.checked; renderChecklist(); });
    $('optHideEvent').checked = hideEvent;
    $('optCompact').checked = compactNames;
    $('optHideEvent').addEventListener('change', (e) => { hideEvent = e.target.checked; localStorage.setItem('mbHideEvent', hideEvent ? '1' : '0'); render(); });
    $('optCompact').addEventListener('change', (e) => { compactNames = e.target.checked; localStorage.setItem('mbCompact', compactNames ? '1' : '0'); render(); });
    const hc = document.getElementById('campusSelect');
    if (hc) hc.addEventListener('change', async () => { await loadMinistryList(); await loadBoard(); });

    setMode(viewMode);
    (async () => { await loadMinistryList(); await loadBoard(); })();
})();
</script>
<script>
(function(){
  // Filters open on desktop, collapsed on small screens so the results are the
  // first thing on the page. Purely presentational — no filter state changes.
  var mq=window.matchMedia('(max-width:900px)');
  var secs=[].slice.call(document.querySelectorAll('details.mb-sec'));
  function apply(){ secs.forEach(function(d,i){ d.open = mq.matches ? false : true; }); }
  if(secs.length){ apply(); mq.addEventListener ? mq.addEventListener('change',apply) : mq.addListener(apply); }
})();
</script>
</body>
</html>
