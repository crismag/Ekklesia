<?php

declare(strict_types=1);

/**
 * Birthdays printable — members by birthday month, filterable, print/PDF ready.
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
            <div class="pr-field">
                <label for="prMonth">Month</label>
                <select id="prMonth">
                    <option value="0">All months</option>
                    <?php foreach (['January','February','March','April','May','June','July','August','September','October','November','December'] as $i => $m): ?>
                        <option value="<?= $i + 1 ?>"><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pr-field" style="min-width:200px">
                <label for="prSearch">Search</label>
                <input id="prSearch" type="search" placeholder="Name or campus…" autocomplete="off">
            </div>
            <div class="spacer"></div>
            <button class="button" id="prPrint" type="button">🖨 Print / Save as PDF</button>
        </div>
        <table class="pr-tbl" id="prTable">
            <thead><tr>
                <th data-sort="name">Name <span class="arrow"></span></th>
                <th data-sort="date">Birthday <span class="arrow"></span></th>
                <th data-sort="age">Age <span class="arrow"></span></th>
                <th data-sort="campus">Campus <span class="arrow"></span></th>
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
    'title' => 'Birthdays',
    'subtitle' => 'Members by birthday month — filter, then print or save as PDF.',
    'printTitle' => 'Church Birthdays',
], $body);
?>
<script>
(function () {
    "use strict";
    const BASE = <?= json_encode($basePath) ?> || '';
    const MONTHS = ['', 'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const MONTHS_FULL = ['', 'January','February','March','April','May','June','July','August','September','October','November','December'];

    function currentCampus() { const s = document.getElementById('campusSelect'); return s ? (s.value || '') : ''; }
    function currentCampusName() { const s = document.getElementById('campusSelect'); if (!s || !s.value) return 'All campuses'; const o = s.options[s.selectedIndex]; return o ? o.textContent.trim() : 'All campuses'; }
    function withCampus(path) { const c = currentCampus(); return c ? path + (path.includes('?') ? '&' : '?') + 'current_campus_id=' + encodeURIComponent(c) : path; }
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));

    let people = [];
    let sortKey = 'date', sortDir = 1;

    async function load() {
        document.getElementById('prCount').textContent = 'Loading…';
        try {
            const res = await fetch(BASE + withCampus('/api/people-directory'), { credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.error || ('Failed to load (' + res.status + ')'));
            const nowYear = new Date().getFullYear();
            people = (Array.isArray(data.people) ? data.people : [])
                .filter((p) => Number(p.birthMonth) > 0)
                .map((p) => ({
                    name: p.displayName || ('Person #' + p.personId),
                    month: Number(p.birthMonth) || 0,
                    day: Number(p.birthDay) || 0,
                    year: Number(p.birthYear) || 0,
                    age: Number(p.birthYear) > 0 ? (nowYear - Number(p.birthYear)) : null,
                    campus: p.primaryCampusName || '',
                }));
            render();
        } catch (e) {
            people = [];
            document.getElementById('prBody').innerHTML = '';
            document.getElementById('prCount').textContent = e.message;
        }
    }

    function filtered() {
        const m = parseInt(document.getElementById('prMonth').value, 10) || 0;
        const q = (document.getElementById('prSearch').value || '').trim().toLowerCase();
        let rows = people.filter((p) => (m === 0 || p.month === m) &&
            (q === '' || p.name.toLowerCase().includes(q) || p.campus.toLowerCase().includes(q)));
        const cmp = {
            name: (a, b) => a.name.localeCompare(b.name),
            date: (a, b) => (a.month - b.month) || (a.day - b.day) || a.name.localeCompare(b.name),
            age: (a, b) => ((a.age ?? 999) - (b.age ?? 999)) || a.name.localeCompare(b.name),
            campus: (a, b) => a.campus.localeCompare(b.campus) || (a.month - b.month) || (a.day - b.day),
        }[sortKey];
        rows.sort((a, b) => cmp(a, b) * sortDir);
        return rows;
    }

    function render() {
        const rows = filtered();
        document.getElementById('prBody').innerHTML = rows.length ? rows.map((p) =>
            '<tr><td>' + esc(p.name) + '</td>' +
            '<td>' + (p.month ? MONTHS[p.month] + ' ' + (p.day || '') : '—') + '</td>' +
            '<td>' + (p.age != null ? p.age : '—') + '</td>' +
            '<td>' + (esc(p.campus) || '—') + '</td></tr>').join('')
            : '<tr><td colspan="4"><div class="pr-empty">No birthdays match.</div></td></tr>';
        const m = parseInt(document.getElementById('prMonth').value, 10) || 0;
        document.getElementById('prCount').textContent = rows.length + ' ' + (rows.length === 1 ? 'person' : 'people');
        // print header meta
        const monthLabel = m === 0 ? 'All months' : MONTHS_FULL[m];
        document.getElementById('printMeta').textContent =
            monthLabel + ' · ' + currentCampusName() + ' · ' + rows.length + ' people · Generated ' + new Date().toLocaleDateString();
        // sort arrows
        document.querySelectorAll('#prTable th[data-sort]').forEach((th) => {
            th.querySelector('.arrow').textContent = th.dataset.sort === sortKey ? (sortDir > 0 ? '▲' : '▼') : '';
        });
    }

    document.getElementById('prMonth').addEventListener('change', render);
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
