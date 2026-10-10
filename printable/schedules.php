<?php

declare(strict_types=1);

/**
 * Schedule roster printable — formatted assignees for the filtered events,
 * grouped by ministry. Print/PDF ready.
 *
 * @var string                $basePath
 * @var ?array<string,mixed>  $actor
 * @var array<string,mixed>   $campusSelector
 */

require_once __DIR__ . '/_printable-shell.php';

$body = static function (): string {
    ob_start();
    ?>
    <div class="pr-card">
        <div class="pr-toolbar">
            <div class="pr-field"><label for="prStart">From</label><input id="prStart" type="date"></div>
            <div class="pr-field"><label for="prEnd">To</label><input id="prEnd" type="date"></div>
            <div class="pr-field">
                <label>Ministries</label>
                <div class="pr-checks-tools"><button type="button" id="prAll">All</button><button type="button" id="prNone">None</button></div>
                <div class="pr-checks" id="prMinistries"><div style="color:var(--muted);font-size:12px">Loading…</div></div>
            </div>
            <div class="spacer"></div>
            <button class="button" id="prApply" type="button">Generate</button>
            <button class="button secondary" id="prPrint" type="button">🖨 Print / Save as PDF</button>
        </div>
        <div class="pr-roster" id="prRoster"><div class="pr-empty">Pick a date range and ministries, then Generate.</div></div>
        <div class="pr-count" id="prCount"></div>
    </div>
    <?php
    return (string) ob_get_clean();
};

echo printable_page([
    'basePath' => $basePath,
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'title' => 'Schedule roster',
    'subtitle' => 'Assigned members for the selected dates & ministries — grouped by ministry, print or save as PDF.',
    'printTitle' => 'Schedule Roster',
], $body);
?>
<script>
(function () {
    "use strict";
    const BASE = <?= json_encode($basePath) ?> || '';

    function currentCampus() { const s = document.getElementById('campusSelect'); return s ? (s.value || '') : ''; }
    function currentCampusName() { const s = document.getElementById('campusSelect'); if (!s || !s.value) return 'All campuses'; const o = s.options[s.selectedIndex]; return o ? o.textContent.trim() : 'All campuses'; }
    function withCampus(path) { const c = currentCampus(); return c ? path + (path.includes('?') ? '&' : '?') + 'current_campus_id=' + encodeURIComponent(c) : path; }
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
    const ymd = (d) => d.toISOString().slice(0, 10);
    function fmtWhen(iso) { const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); }

    let ministries = [];

    // default range: today → +14 days
    (function initDates() {
        const now = new Date(); const end = new Date(); end.setDate(end.getDate() + 14);
        document.getElementById('prStart').value = ymd(now);
        document.getElementById('prEnd').value = ymd(end);
    })();

    async function loadMinistries() {
        try {
            const res = await fetch(BASE + withCampus('/api/ministries'), { credentials: 'same-origin' });
            if (res.status === 401) throw Object.assign(new Error('signed-out'), { signedOut: true });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.error || ('Failed (' + res.status + ')'));
            ministries = (Array.isArray(data.ministries) ? data.ministries : [])
                .map((m) => ({ id: m.ministryId, name: m.name }))
                .sort((a, b) => String(a.name).localeCompare(String(b.name)));
            document.getElementById('prMinistries').innerHTML = ministries.length
                ? ministries.map((m) => '<label><input type="checkbox" class="pr-mck" value="' + m.id + '" checked> ' + esc(m.name) + '</label>').join('')
                : '<div style="color:var(--muted);font-size:12px">No ministries available.</div>';
        } catch (e) {
            document.getElementById('prMinistries').innerHTML = e.signedOut
                ? '<div class="pr-empty">Sign in to choose ministries.<br><a href="' + esc(BASE) + '/login?next=' + encodeURIComponent(location.pathname) + '">Sign in</a></div>'
                : '<div class="pr-empty">Ministries could not be loaded. Try again.</div>';
        }
    }

    function selectedMinistryIds() {
        return Array.from(document.querySelectorAll('.pr-mck:checked')).map((c) => parseInt(c.value, 10));
    }

    async function generate() {
        const start = document.getElementById('prStart').value;
        const end = document.getElementById('prEnd').value;
        const ids = selectedMinistryIds();
        const roster = document.getElementById('prRoster');
        if (!start || !end) { roster.innerHTML = '<div class="pr-empty">Pick a From and To date.</div>'; return; }
        if (!ids.length) { roster.innerHTML = '<div class="pr-empty">Select at least one ministry.</div>'; return; }
        roster.innerHTML = '<div class="pr-empty">Loading…</div>';

        const nameById = {}; ministries.forEach((m) => { nameById[m.id] = m.name; });
        const grids = await Promise.all(ids.map((id) =>
            fetch(BASE + withCampus('/api/schedules/grid?ministry_id=' + id + '&start=' + start + '&end=' + end), { credentials: 'same-origin' })
                .then((r) => r.ok ? r.json() : null).catch(() => null).then((g) => ({ id, g }))
        ));

        let totalAssign = 0, sections = '';
        grids.forEach(({ id, g }) => {
            if (!g) return;
            const roleName = {}; (g.roles || []).forEach((r) => { roleName[r.id] = r.name; });
            const occ = {}; (g.occurrences || []).forEach((o) => { occ[o.id] = { title: o.eventTitle || 'Scheduled item', startsOn: o.startsOn, roles: {} }; });
            (g.assignments || []).forEach((a) => {
                if (!occ[a.occurrenceId]) return;
                const rn = roleName[a.roleId] || 'Assigned';
                (occ[a.occurrenceId].roles[rn] = occ[a.occurrenceId].roles[rn] || []).push(a.displayName || ('Person #' + a.personId));
                totalAssign++;
            });
            // only occurrences that have assignments, sorted by date
            const occs = Object.values(occ)
                .filter((o) => Object.keys(o.roles).length > 0)
                .sort((a, b) => new Date(a.startsOn) - new Date(b.startsOn));
            if (!occs.length) return;

            const count = occs.reduce((s, o) => s + Object.values(o.roles).reduce((n, arr) => n + arr.length, 0), 0);
            sections += '<section class="pr-min"><h2 class="pr-min-title">' + esc(nameById[id] || ('Ministry #' + id)) +
                ' <span class="count">' + count + ' assignment' + (count === 1 ? '' : 's') + '</span></h2>' +
                occs.map((o) =>
                    '<div class="pr-occ"><div class="pr-occ-head">' + esc(o.title) +
                        ' <span class="when">· ' + esc(fmtWhen(o.startsOn)) + '</span></div>' +
                        Object.keys(o.roles).sort().map((rn) =>
                            '<div class="pr-assign"><span class="pr-role">' + esc(rn) + '</span><span>' +
                            o.roles[rn].map(esc).join(', ') + '</span></div>').join('') +
                    '</div>').join('') +
                '</section>';
        });

        roster.innerHTML = sections || '<div class="pr-empty">No assignments in this date range for the selected ministries.</div>';
        const rangeLabel = new Date(start).toLocaleDateString() + ' – ' + new Date(end).toLocaleDateString();
        document.getElementById('prCount').textContent = totalAssign + ' assignment' + (totalAssign === 1 ? '' : 's') + ' · ' + ids.length + ' ministr' + (ids.length === 1 ? 'y' : 'ies');
        document.getElementById('printMeta').textContent = rangeLabel + ' · ' + currentCampusName() + ' · ' + totalAssign + ' assignments · Generated ' + new Date().toLocaleDateString();
    }

    document.getElementById('prApply').addEventListener('click', generate);
    document.getElementById('prPrint').addEventListener('click', () => window.print());
    document.getElementById('prAll').addEventListener('click', () => { document.querySelectorAll('.pr-mck').forEach((c) => c.checked = true); });
    document.getElementById('prNone').addEventListener('click', () => { document.querySelectorAll('.pr-mck').forEach((c) => c.checked = false); });
    const hc = document.getElementById('campusSelect');
    if (hc) hc.addEventListener('change', loadMinistries);

    loadMinistries();
})();
</script>
