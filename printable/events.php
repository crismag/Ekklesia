<?php

declare(strict_types=1);

/**
 * Events printable — upcoming events, filterable, print/PDF ready.
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
            <div class="pr-field" style="min-width:220px">
                <label for="prSearch">Search</label>
                <input id="prSearch" type="search" placeholder="Event title…" autocomplete="off">
            </div>
            <div class="spacer"></div>
            <button class="button" id="prPrint" type="button">🖨 Print / Save as PDF</button>
        </div>
        <table class="pr-tbl" id="prTable">
            <thead><tr>
                <th data-sort="date">Next date <span class="arrow"></span></th>
                <th data-sort="title">Event <span class="arrow"></span></th>
                <th data-sort="count">Occurrences <span class="arrow"></span></th>
            </tr></thead>
            <tbody id="prBody"></tbody>
        </table>
        <div class="pr-count" id="prCount">Loading…</div>
    </div>
    <?php
    return (string) ob_get_clean();
};

echo printable_page([
    'basePath' => $basePath,
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'title' => 'Events',
    'subtitle' => 'Upcoming events — print or save as PDF.',
    'printTitle' => 'Upcoming Events',
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

    // next_occurrence_at may be null, an ISO string, or a serialized DateTime object.
    function parseDate(v) {
        if (!v) return null;
        if (typeof v === 'string') { const d = new Date(v); return isNaN(d) ? null : d; }
        if (typeof v === 'object' && v.date) { const d = new Date(v.date.replace(' ', 'T')); return isNaN(d) ? null : d; }
        return null;
    }
    function fmt(d) { return d ? d.toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'No upcoming date'; }

    let events = [];
    let sortKey = 'date', sortDir = 1;

    async function load() {
        document.getElementById('prCount').textContent = 'Loading…';
        try {
            const res = await fetch(BASE + withCampus('/api/events?limit=200'), { credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.error || ('Failed to load (' + res.status + ')'));
            events = (Array.isArray(data.events) ? data.events : []).map((e) => ({
                title: e.title || 'Untitled event',
                date: parseDate(e.next_occurrence_at),
                count: Number(e.occurrence_count) || 0,
            }));
            render();
        } catch (e) {
            events = [];
            document.getElementById('prBody').innerHTML = '';
            document.getElementById('prCount').textContent = e.message;
        }
    }

    function filtered() {
        const q = (document.getElementById('prSearch').value || '').trim().toLowerCase();
        let rows = events.filter((e) => q === '' || e.title.toLowerCase().includes(q));
        const cmp = {
            date: (a, b) => ((a.date ? a.date.getTime() : Infinity) - (b.date ? b.date.getTime() : Infinity)) || a.title.localeCompare(b.title),
            title: (a, b) => a.title.localeCompare(b.title),
            count: (a, b) => (a.count - b.count) || a.title.localeCompare(b.title),
        }[sortKey];
        rows.sort((a, b) => cmp(a, b) * sortDir);
        return rows;
    }

    function render() {
        const rows = filtered();
        document.getElementById('prBody').innerHTML = rows.length ? rows.map((e) =>
            '<tr><td>' + esc(fmt(e.date)) + '</td>' +
            '<td>' + esc(e.title) + '</td>' +
            '<td>' + e.count + '</td></tr>').join('')
            : '<tr><td colspan="3"><div class="pr-empty">No events match.</div></td></tr>';
        document.getElementById('prCount').textContent = rows.length + ' ' + (rows.length === 1 ? 'event' : 'events');
        document.getElementById('printMeta').textContent =
            currentCampusName() + ' · ' + rows.length + ' events · Generated ' + new Date().toLocaleDateString();
        document.querySelectorAll('#prTable th[data-sort]').forEach((th) => {
            th.querySelector('.arrow').textContent = th.dataset.sort === sortKey ? (sortDir > 0 ? '▲' : '▼') : '';
        });
    }

    document.getElementById('prSearch').addEventListener('input', render);
    document.querySelectorAll('#prTable th[data-sort]').forEach((th) => {
        th.addEventListener('click', () => {
            const k = th.dataset.sort;
            if (sortKey === k) sortDir = -sortDir; else { sortKey = k; sortDir = 1; }
            render();
        });
    });
    document.getElementById('prPrint').addEventListener('click', () => { render(); window.print(); });
    const hc = document.getElementById('campusSelect');
    if (hc) hc.addEventListener('change', load);

    load();
})();
</script>
