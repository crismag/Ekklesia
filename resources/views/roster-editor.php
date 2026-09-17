<?php
/**
 * @var string                           $basePath
 * @var ?array<string, mixed>            $actor
 * @var array<int, array<string,mixed>>  $availableMinistries
 * @var array<string, mixed>             $campusSelector
 * @var string                           $rosterMode      'list' | 'new' | 'edit'
 * @var int                              $rosterId        edit mode
 * @var ?int                             $ministryFilterId list mode
 * @var ?int                             $prefilledMinistryId new mode
 *
 * Roster Schedule editor — simplified linear flow:
 *   1. Roster setup    — title · active range · attached ministry
 *   2. People-pool     — classification chips + all-members toggle
 *   3. Add task        — Weekly DOW OR One-time date · Person/Family · Task
 *   4. Snapshot        — cards grouped by "when":
 *                        * weekly cards  ("Every Monday")  — one per DOW
 *                        * one-time cards (a specific date) — one per date
 *
 * The roster's active range bounds weekly task expansion (and the calendar
 * feed). One-time tasks carry their own date.
 */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$availableMinistries = is_array($availableMinistries ?? null) ? $availableMinistries : [];
$currentCampusId = $actor['currentCampusId'] ?? ($campusSelector['defaultCampusId'] ?? null);
$rosterMode = $rosterMode ?? 'list';
$rosterId   = $rosterId   ?? 0;
require_once __DIR__ . '/_portal-shell.php';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Roster Schedules</title>
    <style>
                *, *::before, *::after { box-sizing: border-box; }
        body { margin:0; min-height:100vh; font: 13px/1.5 Inter, ui-sans-serif, system-ui, sans-serif; color: var(--ink);
               background: linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 110px,var(--bg) 111px); }
        h1 { font-size: 24px; margin: 0; line-height: 1.15; color: var(--ink); }
        .page-kicker { font-size:12px; color: var(--teal); font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 4px; }
        .titleblock { display:flex; align-items:flex-end; justify-content:space-between; gap:14px; flex-wrap:wrap; margin: 4px 0 10px; }
        /* The links, not the line, are the targets. Laying them out as
           inline-flex boxes with a 24px minimum satisfies WCAG 2.5.8 while the
           row itself grows by seven pixels — the separators stay put and the
           text keeps its size. */
        .titleblock .title-meta { color: var(--muted); font-size: 12px;
            display: flex; flex-wrap: wrap; align-items: center; gap: 2px; }
        .titleblock .title-meta a { color: var(--teal); text-decoration: none; font-weight: 800;
            display: inline-flex; align-items: center; min-height: 24px; padding: 0 6px;
            border-radius: var(--radius-sm, 5px); }
        .titleblock .title-meta a:hover { background: var(--soft); }
        .titleblock .title-meta a:focus-visible { outline: 2px solid var(--focus-ring, var(--teal));
            outline-offset: 1px; }

        .panel { background: var(--paper); border: 1px solid var(--line); border-radius: 8px;
                 box-shadow: 0 12px 30px rgba(27,50,40,.08); margin: 12px 0; overflow: hidden; }
        .panel-head { padding: 10px 14px; border-bottom: 1px solid var(--line); display:flex; justify-content:space-between; align-items:center; gap:8px; }
        .panel-head h2 { margin: 0; font-size: 13px; font-weight: 800; }
        .panel-head .meta { color: var(--muted); font-size: 12px; }
        .panel-body { padding: 12px 14px; }

        /* Header bar — title / dates / ministry */
        .row-form { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 10px; align-items: end; }
        .field { display: grid; gap: 4px; }
        .field label { font-size:12px; color: var(--muted); font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
        .field input, .field select, .field textarea {
            padding: 7px 10px; border: 1px solid var(--line); border-radius: 6px; font: inherit; background: #fff; color: var(--ink); width: 100%; }
        .field-hint { font-size:12px; color: var(--muted); }
        @media (max-width: 800px) { .row-form { grid-template-columns: 1fr 1fr; } }

        /* Roster editor is a high-risk surface: presentation only. Touch sizing
           and semantics, no behavioural change to entry handling. */
        @media (max-width: 800px) {
          .row-form input,
          .row-form select,
          .add-task input:not([type=radio]):not([type=checkbox]),
          .add-task select,
          .add-task button,
          .form-actions button,
          .panel-body input:not([type=radio]):not([type=checkbox]),
          .panel-body select,
          input[type=text], select, button.button { min-height: 44px; }
          .kind-row input[type=radio] { width: 20px; height: 20px; }
          .kind-row label { min-height: 44px; display: inline-flex; align-items: center; gap: 8px; }
          .sc-x { min-width: 44px; min-height: 44px; }
          input[type=text] { font-size: 16px; }
        }

        /* People-pool filter strip */
        .filter-strip { display: flex; gap: 14px; padding: 10px 14px; border-top: 1px solid var(--line); flex-wrap: wrap; align-items: center; background: #fbfdfc; }
        .chip-row { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 9px; border-radius: 999px; border: 1px solid var(--line); cursor: pointer; user-select: none; background: #fff; font-size: 12px; font-weight: 700; }
        .filter-chip input { display: none; }
        .filter-chip.is-active { background: var(--teal); color: var(--on-teal); border-color: var(--teal); }

        /* Add-task strip */
        .add-task { padding: 12px 14px; display: grid; gap: 10px; }
        .kind-row { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
        /* The wrapping label is the target — clicking it selects the radio — so
           it is the thing that has to clear 24px (WCAG 2.5.8). It was 20px at
           desktop widths; the 44px rule below applies only on mobile. */
        .kind-row label { min-height: 24px; display: inline-flex; align-items: center; gap: 8px; }
        .kind-row label { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; font-size: 13px; color: var(--ink); cursor: pointer; }
        .kind-row input[type=radio] { width: 16px; height: 16px; accent-color: var(--teal); }
        .when-row { display: grid; grid-template-columns: 200px 1fr 1fr 100px; gap: 10px; align-items: end; }
        @media (max-width: 800px) { .when-row { grid-template-columns: 1fr 1fr; } }
        .add-task button { padding: 8px 12px; border: 0; background: var(--deep); color: #fff; border-radius: 6px; font-weight: 800; cursor: pointer; font-size: 13px; height: 36px; }
        .add-task button:hover { background: #0e3528; }
        .add-warning { padding: 0 14px 10px; font-size: 12px; color: #82071e; min-height: 14px; }

        /* Snapshot — gallery of cards (mirrors schedule editor) */
        .snap-gallery { display: flex; gap: 12px; overflow-x: auto; padding: 12px 14px;
                        scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; }
        .snap-gallery::-webkit-scrollbar { height: 5px; }
        .snap-gallery::-webkit-scrollbar-track { background: #f1f3f5; border-radius: 3px; }
        .snap-gallery::-webkit-scrollbar-thumb { background: #c8d0da; border-radius: 3px; }
        .snap-card { flex: 0 0 clamp(220px, 24vw, 300px); min-width: 220px; max-width: 320px;
                     border: 1px solid #d0d7de; border-radius: 8px; padding: 12px;
                     scroll-snap-align: start; background: #fff; font-size: 12px;
                     display: flex; flex-direction: column; gap: 6px; }
        .snap-card.weekly  { border-left: 3px solid var(--gold); background: #fffaf2; }
        .snap-card.once    { border-left: 3px solid var(--blue); }
        .snap-card .sc-title { font-weight: 800; font-size: 14px; color: var(--ink); }
        .snap-card .sc-when  { font-size:12px; color: var(--muted); margin-bottom: 4px; }
        .snap-card .sc-row   { padding: 6px 0; border-top: 1px solid #f1f3f5; display: grid; gap: 2px; }
        .snap-card .sc-role-name { font-size:12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
        .snap-card .sc-role-name.empty { color: #c8c8c8; font-style: italic; }
        .snap-card .sc-name-line { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
        .snap-card .sc-name { color: var(--ink); font-weight: 700; }
        .snap-card .sc-name.guest { color: #7a4d00; font-style: italic; }
        .snap-card .sc-x { background: transparent; border: 0; color: #b64d4d; font-weight: 800; cursor: pointer; padding: 0 4px; font-size: 14px; line-height: 1; }
        .snap-card .sc-x:hover { color: #821212; }
        .empty { color: var(--muted); padding: 20px; text-align: center; font-style: italic; }

        /* Status + actions */
        .status { padding: 8px 12px; border-radius: 8px; font-size: 13px; margin-top: 10px; display: none; }
        .status.ok  { background: #dff5e8; border: 1px solid #8dd2ad; color: #166534; }
        .status.err { background: #ffebe9; border: 1px solid #ffc1ba; color: #82071e; }
        .form-actions { display: flex; justify-content: space-between; gap: 8px; margin-top: 10px; padding: 10px 14px; border-top: 1px solid var(--line); background: #fafdfb; }
        .form-actions .left { display: flex; gap: 8px; }
        .form-actions button, .form-actions a.btn { padding: 8px 14px; border: 0; background: var(--deep); color: #fff; border-radius: 6px; font-weight: 800; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; }
        .form-actions button.secondary, .form-actions a.btn.secondary { background: #fff; color: var(--deep); border: 1px solid var(--line); }
        .form-actions button.danger { background: #fff; color: #b64d4d; border: 1px solid #f0c4c4; }

        /* List mode */
        .roster-list { display: grid; gap: 8px; }
        .roster-card { display: grid; grid-template-columns: 1fr auto; gap: 10px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 8px; background: #fff; align-items: center; }
        .roster-card .title { font-weight: 800; font-size: 14px; }
        .roster-card .meta  { color: var(--muted); font-size: 12px; }
        .roster-card a.btn  { padding: 6px 10px; min-height: 0; font-size: 12px; background: var(--deep); color: #fff; border-radius: 6px; text-decoration: none; font-weight: 800; }

        
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>"
     data-mode="<?= htmlspecialchars($rosterMode, ENT_QUOTES, 'UTF-8') ?>"
     data-roster-id="<?= (int) $rosterId ?>"
     data-prefill-ministry="<?= (int) ($prefilledMinistryId ?? 0) ?>"
     data-ministry-filter="<?= (int) ($ministryFilterId ?? 0) ?>">
    <?= portal_header(
        $basePath, '', '', $campuses, $currentCampusId, $actor,
        [
            ['href' => $base . '/', 'label' => 'Dashboard', 'icon' => 'dashboard'],
            ['href' => $base . '/ministries', 'label' => 'Ministries', 'icon' => 'ministry'],
            ['href' => $base . '/calendar', 'label' => 'Calendar', 'icon' => 'calendar'],
            ['href' => $base . '/events', 'label' => 'Events', 'icon' => 'events'],
            ['href' => $base . '/people', 'label' => 'People', 'icon' => 'people'],
        ], [], [], 'Sign in', $base . '/login'
    ) ?>
<main id="portal-main" tabindex="-1">


    <div class="titleblock">
        <div>
            <div class="page-kicker">Roster schedules</div>
            <h1 id="pageTitle">
                <?php if ($rosterMode === 'list'): ?>All roster schedules
                <?php elseif ($rosterMode === 'new'): ?>New roster
                <?php else: ?>Edit roster<?php endif; ?>
            </h1>
        </div>
        <?php if ($actor !== null): ?>
            <div class="title-meta">
                <a href="<?= $base ?>/rosters">All rosters</a>
                · <a href="<?= $base ?>/rosters/new">New</a>
                <?php if ($rosterMode !== 'list'): ?> · <a href="<?= $base ?>/calendar">Open calendar</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="status" class="status"></div>

    <!-- LIST MODE -->
    <?php if ($rosterMode === 'list'): ?>
    <div class="panel">
        <div class="panel-head">
            <h2>Rosters</h2>
            <a class="btn" href="<?= $base ?>/rosters/new" style="background:var(--deep);color:#fff;text-decoration:none;font-weight:800;padding:6px 12px;border-radius:6px;font-size:12px;">+ New roster</a>
        </div>
        <div class="panel-body">
            <div id="rosterList" class="roster-list"><div class="empty">Loading…</div></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- NEW / EDIT MODE -->
    <?php if ($rosterMode !== 'list'): ?>

    <!-- 1. Roster setup -->
    <div class="panel">
        <div class="panel-body">
            <div class="row-form">
                <div class="field">
                    <label for="title">Title <span style="color:#b64d4d">*</span></label>
                    <input type="text" id="title" placeholder="e.g. MTE Pick-Up Schedule, Sunday Potbless Volunteers">
                </div>
                <div class="field">
                    <label for="startsOn">Active from</label>
                    <input type="date" id="startsOn">
                    <span class="field-hint">Bounds weekly tasks; one-time tasks carry their own date.</span>
                </div>
                <div class="field">
                    <label for="endsOn">Active until</label>
                    <input type="date" id="endsOn">
                </div>
                <div class="field">
                    <label for="ministryId">Attached ministry</label>
                    <select id="ministryId">
                        <option value="">— None —</option>
                        <?php foreach ($availableMinistries as $m): ?>
                            <option value="<?= (int) $m['ministryId'] ?>"
                                <?= ((int) ($prefilledMinistryId ?? 0) === (int) $m['ministryId']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $m['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- 2. People-pool filter strip -->
        <div class="filter-strip">
            <strong style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;font-weight:800;">People pool</strong>
            <label class="filter-chip">
                <input type="checkbox" id="allMembers"> Include all members
            </label>
            <div class="chip-row" id="memberTypeChips"></div>
            <span style="color:var(--muted);font-size:12px" id="poolStatus"></span>
        </div>

        <!-- 3. Add-task strip -->
        <div class="add-task">
            <div class="kind-row">
                <label><input type="radio" name="taskKind" value="weekly" checked> Every week (recurring)</label>
                <label><input type="radio" name="taskKind" value="once"> Just one date</label>
            </div>
            <div class="when-row">
                <div class="field" id="dowField">
                    <label for="entryDow">Day of week</label>
                    <select id="entryDow">
                        <option value="0">Sunday</option>
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                    </select>
                </div>
                <div class="field" id="dateField" style="display:none">
                    <label for="entryDate">Date</label>
                    <input type="date" id="entryDate">
                </div>
                <div class="field">
                    <label for="entryPerson">Person or Family</label>
                    <input type="text" id="entryPerson" list="rosterPeopleList" autocomplete="off" placeholder="Search a member or type a family name…">
                </div>
                <div class="field">
                    <label for="entryRole">Task / Role</label>
                    <input type="text" id="entryRole" list="rosterRolesList" autocomplete="off" placeholder="e.g. Shoppers Markville, Setup, Greeter">
                </div>
                <div class="field">
                    <label>&nbsp;</label>
                    <button type="button" id="addEntryBtn">+ Add task</button>
                </div>
            </div>
        </div>
        <div class="add-warning" id="addWarning"></div>
    </div>

    <!-- 4. Snapshot -->
    <div class="panel">
        <div class="panel-head">
            <h2>Roster snapshot</h2>
            <span class="meta" id="snapMeta"></span>
        </div>
        <div id="snapGallery" class="snap-gallery">
            <div class="empty">No tasks yet — set the recurrence above and click <strong>+ Add task</strong>.</div>
        </div>
        <div class="form-actions">
            <div class="left">
                <button id="saveBtn" type="button">Save roster</button>
                <a class="btn secondary" href="<?= $base ?>/rosters">Cancel</a>
            </div>
            <?php if ($rosterMode === 'edit'): ?>
            <button id="deleteBtn" type="button" class="danger">Delete roster</button>
            <?php endif; ?>
        </div>
    </div>

    <datalist id="rosterPeopleList"></datalist>
    <datalist id="rosterRolesList"></datalist>
    <?php endif; ?>

    </main>
<?= portal_footer('Church Portal', 'Roster schedules') ?>
</div>

<script>
(function () {
    const ROOT = <?= json_encode($base) ?>;
    const shell = document.querySelector('.shell');
    const mode = shell.dataset.mode;
    const initialRosterId = parseInt(shell.dataset.rosterId || '0', 10);
    const ministryFilterId = parseInt(shell.dataset.ministryFilter || '0', 10);
    const statusEl = document.getElementById('status');
    const DOW_NAMES = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
    function showStatus(kind, msg) {
        statusEl.className = 'status ' + (kind === 'err' ? 'err' : 'ok');
        statusEl.textContent = msg;
        statusEl.style.display = 'block';
        if (kind !== 'err') setTimeout(() => statusEl.style.display = 'none', 2200);
    }

    // ===== LIST MODE =====
    if (mode === 'list') {
        const listEl = document.getElementById('rosterList');
        async function load() {
            try {
                const url = ROOT + '/api/rosters' + (ministryFilterId ? '?ministry_id=' + ministryFilterId : '');
                const res = await fetch(url, { credentials: 'same-origin' });
                const data = await res.json().catch(() => ({}));
                const rs = Array.isArray(data.rosters) ? data.rosters : [];
                if (rs.length === 0) {
                    listEl.innerHTML = '<div class="empty">No rosters yet — click <strong>+ New roster</strong> to start.</div>';
                    return;
                }
                listEl.innerHTML = rs.map(r => {
                    const dates = r.startsOn === r.endsOn ? r.startsOn : (r.startsOn + ' → ' + r.endsOn);
                    return '<div class="roster-card">' +
                        '<div><div class="title">' + esc(r.title) + '</div>' +
                        (r.subtitle ? '<div class="meta">' + esc(r.subtitle) + '</div>' : '') +
                        '<div class="meta">' + esc(dates) + (r.ministryId ? ' · ministry #' + r.ministryId : '') + '</div></div>' +
                        '<div><a class="btn" href="' + ROOT + '/rosters/' + r.rosterId + '">Open</a></div></div>';
                }).join('');
            } catch (_) { listEl.innerHTML = '<div class="empty">Could not load rosters.</div>'; }
        }
        load();
        return;
    }

    // ===== NEW / EDIT MODE =====
    let memberTypes = [];
    let people      = [];
    let nameToId    = {};
    let roles       = [];
    // Each task: { kind: 'weekly'|'once', dow:int|null, date:string|null,
    //              role:string|null, personId:int|null, displayName:string|null }
    let tasks       = [];

    // -------- people pool --------
    function isExcludedByDefault(name) { return /g&a|gift.?and.?arrows/i.test(String(name || '')); }
    function selectedMemberTypeIds() {
        return Array.from(document.querySelectorAll('#memberTypeChips input:checked')).map(c => parseInt(c.value, 10));
    }
    function renderMemberTypeChips() {
        const wrap = document.getElementById('memberTypeChips');
        wrap.innerHTML = memberTypes.map(t => {
            const checked = !isExcludedByDefault(t.name);
            return '<label class="filter-chip' + (checked ? ' is-active' : '') + '" data-id="' + t.id + '">' +
                '<input type="checkbox" value="' + t.id + '"' + (checked ? ' checked' : '') + '> ' + esc(t.name) +
            '</label>';
        }).join('');
        wrap.querySelectorAll('input').forEach(cb => cb.addEventListener('change', () => {
            cb.parentElement.classList.toggle('is-active', cb.checked);
            loadPeople();
        }));
    }

    async function loadPeople() {
        const params = new URLSearchParams();
        const ministryId = document.getElementById('ministryId').value;
        const allMembers = document.getElementById('allMembers').checked;
        if (ministryId && !allMembers) params.set('ministry_id', ministryId);
        if (allMembers) params.set('all_members', '1');
        const types = selectedMemberTypeIds();
        if (types.length) params.set('member_type_ids', types.join(','));
        const status = document.getElementById('poolStatus');
        status.textContent = 'Loading…';
        try {
            const res = await fetch(ROOT + '/api/rosters/people-pool?' + params.toString(), { credentials: 'same-origin' });
            if (res.status === 401) { window.location.href = ROOT + '/login?next=' + encodeURIComponent(location.pathname + location.search); return; }
            const data = await res.json().catch(() => ({}));
            if (memberTypes.length === 0 && Array.isArray(data.memberTypes)) {
                memberTypes = data.memberTypes;
                renderMemberTypeChips();
            }
            people = Array.isArray(data.people) ? data.people : [];
            people.sort((a, b) => a.displayName.localeCompare(b.displayName));
            const dl = document.getElementById('rosterPeopleList');
            dl.innerHTML = people.map(p => '<option value="' + esc(p.displayName) + '">').join('');
            nameToId = {};
            for (const p of people) nameToId[p.displayName.toLowerCase()] = p.personId;
            status.textContent = people.length + ' people available';
        } catch (_) {
            people = [];
            status.textContent = 'Could not load people pool.';
        }
    }

    async function loadRoles() {
        const ministryId = document.getElementById('ministryId').value;
        roles = [];
        if (ministryId) {
            try {
                const res = await fetch(ROOT + '/api/ministry-dashboard?since=2000-01-01&until=2099-01-01', { credentials: 'same-origin' });
                const data = await res.json().catch(() => ({}));
                const card = (data.cards || []).find(c => Number(c.ministryId) === Number(ministryId));
                roles = Array.isArray(card && card.roles) ? card.roles : [];
            } catch (_) { roles = []; }
        }
        const dl = document.getElementById('rosterRolesList');
        dl.innerHTML = roles.map(r => '<option value="' + esc(r.name) + '">').join('');
    }

    // -------- task add / kind toggle --------
    function setAddWarning(msg) { document.getElementById('addWarning').textContent = msg || ''; }

    function currentKind() {
        const r = document.querySelector('input[name=taskKind]:checked');
        return r ? r.value : 'weekly';
    }
    function syncKindFields() {
        const kind = currentKind();
        document.getElementById('dowField').style.display  = (kind === 'weekly') ? '' : 'none';
        document.getElementById('dateField').style.display = (kind === 'once')   ? '' : 'none';
    }
    document.querySelectorAll('input[name=taskKind]').forEach(r => r.addEventListener('change', syncKindFields));

    function addTask() {
        const kind = currentKind();
        const personInp = document.getElementById('entryPerson');
        const roleInp   = document.getElementById('entryRole');
        const pname = (personInp.value || '').trim();
        const role  = (roleInp.value   || '').trim() || null;

        if (!pname) { setAddWarning('Pick a person or type a family/guest label.'); personInp.focus(); return; }

        const pid = nameToId[pname.toLowerCase()] || null;
        const task = {
            kind,
            dow:  kind === 'weekly' ? parseInt(document.getElementById('entryDow').value, 10) : null,
            date: kind === 'once'   ? document.getElementById('entryDate').value || ''       : null,
            role,
            personId:    pid,
            displayName: pid ? null : pname,
        };
        if (task.kind === 'once' && !task.date) {
            setAddWarning('Pick a date for a one-time task.');
            document.getElementById('entryDate').focus();
            return;
        }
        tasks.push(task);
        // Clear person + role; keep kind/dow/date so leaders can keep adding
        // people to the same recurrence pattern.
        personInp.value = '';
        roleInp.value = '';
        setAddWarning('');
        renderSnapshot();
        personInp.focus();
    }
    document.getElementById('addEntryBtn')?.addEventListener('click', addTask);
    ['entryDow','entryDate','entryPerson','entryRole'].forEach(id => {
        document.getElementById(id)?.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); addTask(); }
        });
    });

    // -------- snapshot --------
    function fmtDateLong(d) {
        try { return new Date(d + 'T00:00:00').toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }); }
        catch (_) { return d; }
    }
    function fmtDateRel(d) {
        try { return new Date(d + 'T00:00:00').toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' }); }
        catch (_) { return d; }
    }
    function findPersonName(pid) {
        const p = people.find(p => Number(p.personId) === Number(pid));
        return p ? p.displayName : ('Person #' + pid);
    }

    function renderSnapshot() {
        const gallery = document.getElementById('snapGallery');
        if (tasks.length === 0) {
            gallery.innerHTML = '<div class="empty">No tasks yet — set the recurrence above and click <strong>+ Add task</strong>.</div>';
            document.getElementById('snapMeta').textContent = '';
            return;
        }

        // Group tasks into cards. Weekly tasks group by DOW; one-time tasks
        // group by date. One card per group.
        const weeklyByDow = new Map();   // dow → list of {task, _idx}
        const onceByDate  = new Map();   // YYYY-MM-DD → list of {task, _idx}
        tasks.forEach((t, i) => {
            const item = { ...t, _idx: i };
            if (t.kind === 'weekly') {
                const arr = weeklyByDow.get(t.dow) || [];
                arr.push(item);
                weeklyByDow.set(t.dow, arr);
            } else {
                const arr = onceByDate.get(t.date) || [];
                arr.push(item);
                onceByDate.set(t.date, arr);
            }
        });

        const cards = [];

        // Weekly cards — sorted by DOW (Sun → Sat).
        [...weeklyByDow.keys()].sort((a, b) => a - b).forEach(dow => {
            cards.push(buildCard('weekly', 'Every ' + DOW_NAMES[dow], weeklyDescriptionFor(dow), weeklyByDow.get(dow)));
        });
        // One-time cards — sorted chronologically.
        [...onceByDate.keys()].sort().forEach(date => {
            cards.push(buildCard('once', fmtDateLong(date), fmtDateRel(date), onceByDate.get(date)));
        });

        gallery.innerHTML = cards.join('');
        const weeklyCount = tasks.filter(t => t.kind === 'weekly').length;
        const onceCount   = tasks.length - weeklyCount;
        document.getElementById('snapMeta').textContent =
            weeklyCount + ' weekly · ' + onceCount + ' one-time';
    }

    function weeklyDescriptionFor(dow) {
        // Short helper text under the title — e.g. "recurs while active".
        const start = document.getElementById('startsOn').value;
        const end   = document.getElementById('endsOn').value;
        if (start && end) return 'every ' + DOW_NAMES[dow] + ' from ' + start + ' to ' + end;
        return 'every ' + DOW_NAMES[dow];
    }

    function buildCard(kindCls, title, subtitle, items) {
        const rows = items.map(it => {
            const name = it.displayName || (it.personId ? findPersonName(it.personId) : 'Unknown');
            const isGuest = !it.personId;
            const roleHtml = it.role
                ? '<div class="sc-role-name">' + esc(it.role) + '</div>'
                : '<div class="sc-role-name empty">No task / role</div>';
            return '<div class="sc-row">' +
                roleHtml +
                '<div class="sc-name-line">' +
                    '<span class="sc-name' + (isGuest ? ' guest' : '') + '">' + esc(name) + '</span>' +
                    '<button type="button" class="sc-x" data-action="del-entry" data-idx="' + it._idx + '" title="Remove" aria-label="Remove entry"><span aria-hidden="true">&times;</span></button>' +
                '</div>' +
            '</div>';
        }).join('');
        return '<div class="snap-card ' + kindCls + '">' +
            '<div class="sc-title">' + esc(title) + '</div>' +
            '<div class="sc-when">' + esc(subtitle) + '</div>' +
            rows +
        '</div>';
    }

    document.getElementById('snapGallery')?.addEventListener('click', e => {
        const del = e.target.closest('[data-action=del-entry]');
        if (!del) return;
        const idx = parseInt(del.dataset.idx, 10);
        tasks.splice(idx, 1);
        renderSnapshot();
    });

    // -------- save / delete --------
    function tasksToSlots() {
        // Each task → one slot (slot_date for once, slot_dow for weekly) +
        // one assignment row. Display order preserves the order the leader
        // typed entries.
        return tasks.map((t, i) => ({
            slotDate: t.kind === 'once'   ? (t.date || null) : null,
            slotDow:  t.kind === 'weekly' ? t.dow            : null,
            label:    t.role || null,
            location: null,
            roleId:   null,
            displayOrder: i,
            assignees: [{
                personId:    t.personId,
                displayName: t.displayName,
                displayOrder: 0,
            }],
        }));
    }

    document.getElementById('saveBtn')?.addEventListener('click', async () => {
        const payload = {
            title: document.getElementById('title').value.trim(),
            ministryId: document.getElementById('ministryId').value || null,
            startsOn: document.getElementById('startsOn').value,
            endsOn:   document.getElementById('endsOn').value,
            isPublished: true,
            slots: tasksToSlots(),
        };
        if (!payload.title) { showStatus('err', 'Title is required.'); return; }
        if (!payload.startsOn || !payload.endsOn) { showStatus('err', 'Active range (start and end) is required.'); return; }

        const url = (mode === 'new') ? ROOT + '/api/rosters' : ROOT + '/api/rosters/' + initialRosterId;
        try {
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { showStatus('err', (data && data.error) || ('Save failed (' + res.status + ').')); return; }
            if (mode === 'new' && data.rosterId) { window.location = ROOT + '/rosters/' + data.rosterId; return; }
            showStatus('ok', 'Roster saved.');
        } catch (_) { showStatus('err', 'Network error — please retry.'); }
    });

    document.getElementById('deleteBtn')?.addEventListener('click', async () => {
        if (!confirm('Delete this roster? This is permanent.')) return;
        try {
            const res = await fetch(ROOT + '/api/rosters/' + initialRosterId, { method: 'DELETE', credentials: 'same-origin' });
            if (!res.ok) { showStatus('err', 'Delete failed.'); return; }
            window.location = ROOT + '/rosters';
        } catch (_) { showStatus('err', 'Network error — please retry.'); }
    });

    document.getElementById('ministryId')?.addEventListener('change', async () => {
        await Promise.all([loadRoles(), loadPeople()]);
    });
    document.getElementById('allMembers')?.addEventListener('change', loadPeople);

    // -------- bootstrap --------
    async function boot() {
        if (mode === 'edit' && initialRosterId > 0) {
            const res = await fetch(ROOT + '/api/rosters/' + initialRosterId, { credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.roster) { showStatus('err', 'Could not load roster.'); return; }
            const r = data.roster;
            document.getElementById('title').value      = r.title;
            document.getElementById('ministryId').value = r.ministryId || '';
            document.getElementById('startsOn').value   = r.startsOn;
            document.getElementById('endsOn').value     = r.endsOn;
            // Each saved slot may carry many assignees; flatten back into the
            // editor's per-(task, person) shape so each name is removable
            // independently.
            tasks = [];
            for (const s of (r.slots || [])) {
                const kind = s.slotDate ? 'once' : (s.slotDow !== null ? 'weekly' : 'once');
                for (const a of (s.assignees || [])) {
                    tasks.push({
                        kind,
                        dow:  kind === 'weekly' ? s.slotDow : null,
                        date: kind === 'once'   ? (s.slotDate || '') : null,
                        role: s.label || null,
                        personId: a.personId,
                        displayName: a.displayName,
                    });
                }
            }
            memberTypes = Array.isArray(data.memberTypes) ? data.memberTypes : memberTypes;
            renderMemberTypeChips();
        } else if (mode === 'new') {
            const today = new Date().toISOString().slice(0, 10);
            document.getElementById('startsOn').value = today;
            document.getElementById('endsOn').value   = today;
            document.getElementById('entryDate').value = today;
        }
        await Promise.all([loadRoles(), loadPeople()]);
        syncKindFields();
        renderSnapshot();
    }
    boot();
})();
</script>
</body>
</html>
