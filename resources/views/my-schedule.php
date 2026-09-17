<?php
/**
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var array<string,mixed> $campusSelector
 */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
require_once __DIR__ . '/_portal-shell.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>My Schedule - Church Portal</title>
    <style>
        .ms-h1{margin:6px 0 4px;font-size:clamp(22px,3.2vw,28px);line-height:1.2;color:#f8fffb}
                * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font: 14px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: var(--ink); background: linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 260px,var(--bg) 261px); }
        a { color: inherit; }
        .brand { display: grid; gap: 4px; }
        .brand a { color: #f8fffb; text-decoration: none; font-weight: 900; }
        h1 { margin: 0; font-size: 38px; line-height: 1.05; }
        .muted { color: rgba(248,255,251,.74); }
        .actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
        .campus-mini { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.24); border-radius: 8px; padding: 4px 6px; }
        .campus-mini select { width: auto; min-width: 132px; max-width: 180px; height: 30px; padding: 4px 24px 4px 8px; border: 0; border-radius: 6px; font-size: 12px; }
        .icon-btn { width: 34px; height: 34px; display: inline-grid; place-items: center; border: 1px solid rgba(255,255,255,.24); border-radius: 8px; background: rgba(255,255,255,.1); color: #fff; text-decoration: none; font-weight: 900; }
        .dropdown { position: relative; display: inline-block; }
        .dropdown-menu { display: none; position: absolute; right: 0; top: 40px; min-width: 180px; background: #fff; color: var(--ink); border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 14px 34px rgba(28,48,39,.16); padding: 6px; z-index: 10; }
        .dropdown.open .dropdown-menu { display: grid; }
        .dropdown-menu a { padding: 9px 10px; border-radius: 6px; text-decoration: none; }
        .dropdown-menu a:hover { background: var(--soft); }
        .button, button { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 9px 13px; border-radius: 8px; background: #fff; color: var(--deep); font: inherit; font-weight: 900; text-decoration: none; border: 0; cursor: pointer; }
        .button.secondary { background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.24); }
        .button[aria-disabled="true"] { opacity: .48; pointer-events: none; }
        .hero { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(280px, .6fr); gap: 16px; align-items: stretch; margin-bottom: 18px; }
        .hero-copy { color: #f8fffb; padding: 28px 0 8px; }
        .hero-kicker { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 12px; padding: 6px 10px; border: 1px solid rgba(255,255,255,.22); border-radius: 999px; background: rgba(255,255,255,.1); font-size: 12px; font-weight: 900; }
        .pulse-dot { width: 7px; height: 7px; border-radius: 50%; background: #8ef0c6; box-shadow: 0 0 0 6px rgba(142,240,198,.12); }
        .lead { margin: 12px 0 0; color: rgba(248,255,251,.82); font-size: 16px; max-width: 640px; }
        .hero-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
        .panel { background: var(--paper); border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 14px 34px rgba(28,48,39,.08); overflow: hidden; }
        .summary-panel { padding: 14px; display: grid; gap: 12px; align-self: end; }
        .summary-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .metric { border-radius: 8px; padding: 14px; color: #fff; min-height: 96px; display: grid; align-content: space-between; }
        .metric strong { font-size: 30px; line-height: 1; }
        .metric span { color: rgba(255,255,255,.82); font-weight: 800; font-size: 12px; }
        .m1 { background: var(--teal); }
        .m2 { background: var(--blue); }
        .m3 { background: var(--gold); }
        .m4 { background: #1c5b49; }
        .scope-note { color: var(--muted); font-size: 12px; }
        .toolbar { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: 10px; margin: 18px 0; }
        .toolbar label { display: block; font-size:12px; color: var(--muted); font-weight: 900; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
        .toolbar input { width: 100%; border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; font: inherit; background: #fff; color: var(--ink); }
        .toolbar .button { background: var(--deep); color: #fff; align-self: end; }
        .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 16px 16px 12px; border-bottom: 1px solid var(--line); }
        .panel h2 { margin: 0; font-size: 16px; }
        .panel-head .meta { color: var(--muted); font-size: 12px; }
        .assignment-list { display: grid; }
        .assignment-row { display: grid; grid-template-columns: 70px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 14px 16px; border-bottom: 1px solid #edf2ef; }
        .assignment-row:last-child { border-bottom: 0; }
        .date-chip { min-height: 58px; border-radius: 8px; display: grid; place-items: center; align-content: center; background: var(--soft); font-weight: 950; text-align: center; }
        .date-chip small { display: block; color: var(--muted); font-size:12px; }
        .row-title { font-weight: 900; font-size: 15px; }
        .row-meta { color: var(--muted); font-size: 12px; }
        .row-status { display: inline-flex; align-items: center; border-radius: 999px; padding: 4px 9px; background: var(--soft); color: var(--deep); font-size:12px; font-weight: 900; text-transform: uppercase; }
        .empty { padding: 22px 16px; color: var(--muted); }
        .helper-card { padding: 16px; display: grid; gap: 10px; }
        .helper-card p { margin: 0; color: var(--muted); }
        
        @media (max-width: 920px) {
            .hero { grid-template-columns: 1fr; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            .actions { justify-content: flex-start; }
            .campus-mini { width: 100%; }
            .campus-mini select { flex: 1; max-width: none; }
            .toolbar { grid-template-columns: 1fr; }
            .summary-grid { grid-template-columns: 1fr 1fr; }
            .assignment-row { grid-template-columns: 58px minmax(0, 1fr); }
            .assignment-row .row-status { grid-column: 2; justify-self: start; }
            
        }
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?>>
    <?= portal_header(
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
            ['href' => $base . '/availability','label' => 'Availability','icon' => 'availability'],
        ],
        [],
        [],
        'Sign in',
        $base . '/login'
    ) ?>
<main id="portal-main" tabindex="-1">


    <?php if ($actor === null): ?>
        <h1 class="ms-h1">My Schedule</h1>
        <section class="panel helper-card">
            <h2>Sign in required</h2>
            <p>Open your personal assignments after signing in with a portal account.</p>
            <div><a class="button" href="<?= $base ?>/login?next=<?= urlencode($base . '/my-schedule') ?>">Sign in</a></div>
        </section>
    <?php elseif (($actor['personId'] ?? null) === null): ?>
        <h1 class="ms-h1">My Schedule</h1>
        <section class="panel helper-card">
            <h2>Person link required</h2>
            <p>Your portal account is not yet linked to a person record, so personal assignments cannot be resolved.</p>
            <div><a class="button secondary" href="<?= $base ?>/">Back to dashboard</a></div>
        </section>
    <?php else: ?>
        <section class="hero">
            <div class="hero-copy">
                <div class="hero-kicker"><span class="pulse-dot"></span><span>Personal ministry plan</span></div>
            <h1 class="ms-h1">My Schedule</h1>
                <p class="lead">Use this page to review upcoming serving assignments, scan your next scheduled date, and adjust the viewing window without dropping into raw JSON.</p>
                <div class="hero-actions">
                    <a class="button" href="<?= $base ?>/availability">Manage availability</a>
                    <a class="button secondary" href="<?= $base ?>/calendar">Open calendar</a>
                </div>
            </div>
        </section>

        <section class="toolbar">
            <div>
                <label for="startDate">Start</label>
                <input id="startDate" type="date">
            </div>
            <div>
                <label for="endDate">End</label>
                <input id="endDate" type="date">
            </div>
            <div>
                <label for="statusFilter">Status</label>
                <input id="statusFilter" type="search" placeholder="Filter by ministry, role, event...">
            </div>
            <button id="loadButton" type="button" class="button">Load schedule</button>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Upcoming assignments</h2>
                <div class="meta" id="windowLabel">Loading schedule…</div>
            </div>
            <div id="scheduleList" class="assignment-list"><div class="empty">Loading assignments…</div></div>
        </section>
    <?php endif; ?>

    </main>
<footer class="portal-footer"><span>Church Portal</span><span>Personal schedule view</span></footer>
</div>

<?php if ($actor !== null && ($actor['personId'] ?? null) !== null): ?>
<script>
(function () {
    const basePath = <?= json_encode(rtrim($basePath, '/')) ?>;
    const campusSelect = document.getElementById('campusSelect');
    const menuButton = document.getElementById('menuButton');
    const startInput = document.getElementById('startDate');
    const endInput = document.getElementById('endDate');
    const filterInput = document.getElementById('statusFilter');
    const loadButton = document.getElementById('loadButton');
    const scheduleList = document.getElementById('scheduleList');
    const windowLabel = document.getElementById('windowLabel');
    // Summary metric tiles were removed (assignmentCount/ministryCount/daysUntilNext/windowSpan).
    // setText() is null-safe so the loaders below stay correct in case the
    // tiles return in a future iteration.
    const setText = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = String(value); };
    let assignments = [];

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"]/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));
    }

    function monthDay(value) {
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? ['-', ''] : [date.toLocaleString([], { month: 'short' }), String(date.getDate())];
    }

    function formatDateTime(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return 'Unknown';
        return date.toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function startOfToday() {
        const date = new Date();
        date.setHours(0, 0, 0, 0);
        return date;
    }

    function isoDate(date) {
        return date.toISOString().slice(0, 10);
    }

    function withCampus(url) {
        const id = campusSelect?.value || '';
        if (!id) return url;
        return url + (url.includes('?') ? '&' : '?') + 'current_campus_id=' + encodeURIComponent(id);
    }

    function renderAssignments() {
        const query = (filterInput?.value || '').trim().toLowerCase();
        const filtered = assignments.filter((assignment) => {
            if (!query) return true;
            return `${assignment.eventTitle || ''} ${assignment.ministryName || ''} ${assignment.roleName || ''} ${assignment.status || ''}`.toLowerCase().includes(query);
        });

        if (filtered.length === 0) {
            scheduleList.innerHTML = '<div class="empty">No assignments match the current window or filter.</div>';
            return;
        }

        scheduleList.innerHTML = filtered.map((assignment) => {
            const parts = monthDay(assignment.startsOn);
            return `<div class="assignment-row">
                <div class="date-chip"><small>${escapeHtml(parts[0])}</small>${escapeHtml(parts[1])}</div>
                <div>
                    <div class="row-title">${escapeHtml(assignment.eventTitle || assignment.ministryName)}</div>
                    <div class="row-meta">${escapeHtml(assignment.roleName)} · ${escapeHtml(assignment.ministryName)} · ${escapeHtml(formatDateTime(assignment.startsOn))}</div>
                </div>
                <div class="row-status">${escapeHtml(assignment.status || 'assigned')}</div>
            </div>`;
        }).join('');
    }

    function updateSummary(data) {
        setText('assignmentCount', assignments.length);
        setText('ministryCount', new Set(assignments.map((assignment) => assignment.ministryId)).size);
        setText('windowSpan', Math.max(1, Math.round((new Date(data.end) - new Date(data.start)) / 86400000)));

        if (assignments.length === 0) {
            setText('daysUntilNext', '-');
            return;
        }

        const today = startOfToday();
        const nextDate = new Date(assignments[0].startsOn);
        setText('daysUntilNext', Math.max(0, Math.floor((nextDate - today) / 86400000)));
    }

    async function loadSchedule() {
        const start = startInput.value;
        const end = endInput.value;
        windowLabel.textContent = 'Loading schedule…';
        scheduleList.innerHTML = '<div class="empty">Loading assignments…</div>';

        try {
            const data = await fetch(withCampus(`${basePath}/api/my-schedule?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`), {
                credentials: 'same-origin',
            }).then(async (res) => {
                const payload = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(payload.error || `Request failed ${res.status}`);
                return payload;
            });

            assignments = Array.isArray(data.assignments) ? data.assignments.slice().sort((a, b) => new Date(a.startsOn) - new Date(b.startsOn)) : [];
            windowLabel.textContent = `${escapeHtml(data.start)} to ${escapeHtml(data.end)}`;
            updateSummary(data);
            renderAssignments();
        } catch (error) {
            assignments = [];
            setText('assignmentCount', '0');
            setText('ministryCount', '0');
            setText('daysUntilNext', '-');
            scheduleList.innerHTML = `<div class="empty">${escapeHtml(error.message || 'Schedule could not be loaded.')}</div>`;
            windowLabel.textContent = 'Schedule unavailable';
        }
    }


    campusSelect?.addEventListener('change', loadSchedule);
    filterInput?.addEventListener('input', renderAssignments);
    loadButton?.addEventListener('click', loadSchedule);

    const today = startOfToday();
    const end = new Date(today);
    end.setDate(end.getDate() + 90);
    startInput.value = isoDate(today);
    endInput.value = isoDate(end);
    loadSchedule();
})();
</script>
<?php endif; ?>
</body>
</html>