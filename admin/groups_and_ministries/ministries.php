<?php

declare(strict_types=1);

/**
 * Members & leaders — ministry membership (admin).
 *
 * Master-detail workspace: pick a ministry on the left to manage its members,
 * roles, and leaders on the right; create/edit/deactivate/delete ministries.
 *
 * Self-contained within church_portal — no dependency on the ChurchCRM app.
 * Reuses the portal admin shell (_admin-shell.php) and the existing
 * /api/ministry* + /api/admin/ministries endpoints.
 *
 * @var string                $basePath        provided by the route closure
 * @var ?array<string,mixed>  $actor
 * @var array<string,mixed>   $campusSelector
 */

require_once __DIR__ . '/../../resources/views/_admin-shell.php';

$isAdmin  = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];

ob_start();
?>
<style>
  .gm{display:grid;grid-template-columns:300px minmax(0,1fr);gap:14px;align-items:start}
  .gm-side{display:grid;gap:10px}
  .gm-searchrow{display:flex;gap:8px}
  .gm-searchrow input{flex:1;border:1px solid var(--line);border-radius:8px;padding:9px 11px;font:inherit;background:var(--paper);color:var(--ink)}
  .gm-list{display:grid;gap:8px;max-height:70vh;overflow:auto;padding:2px}
  .gm-item{display:grid;gap:2px;padding:10px 12px;border:1px solid var(--line);border-left:3px solid transparent;border-radius:8px;background:var(--paper);cursor:pointer;transition:background .12s,border-color .12s}
  .gm-item:hover{background:var(--soft)}
  .gm-item.is-active{border-left-color:var(--teal);background:var(--soft)}
  .gm-item.is-inactive{opacity:.62}
  .gm-item .gm-name{font-weight:800;font-size:13px;display:flex;align-items:center;gap:6px}
  .gm-item .gm-meta{color:var(--muted);font-size:11px}
  .gm-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;background:var(--teal)}
  .gm-dot.off{background:#c2ccc7}
  .gm-badge{font-size:10px;font-weight:800;padding:1px 7px;border-radius:999px;background:var(--soft);color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
  .gm-badge.inactive{background:#eef0f2;color:#5a6976}
  .gm-badge.leader{background:#fff4d4;color:#7a5400}
  .gm-empty{padding:26px 16px;text-align:center;color:var(--muted);border:1px dashed var(--line);border-radius:10px}
  .gm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px}
  .gm-stat{border:1px solid var(--line);border-radius:10px;padding:12px 14px;background:#fbfdfc}
  .gm-stat .v{font-size:22px;font-weight:800;line-height:1;color:var(--deep)}
  .gm-stat .l{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-top:5px}
  .gm-open{cursor:pointer}
  .gm-open:hover{background:var(--soft)}
  .gm-num{text-align:right;font-variant-numeric:tabular-nums}
  .gm-warn{padding:10px 12px;background:#fff8e6;border:1px solid #f3e0a8;color:#7c5b07;border-radius:8px;margin-top:12px;font-size:13px}
  .gm-lead-row{padding:10px 14px;border-bottom:1px solid var(--line);cursor:pointer}
  .gm-lead-row:last-child{border-bottom:0}
  .gm-lead-row:hover{background:var(--soft)}
  .gm-lead-head{font-weight:700;font-size:13px;margin-bottom:6px;display:flex;align-items:center;gap:6px}
  .gm-lead-head .sub{color:var(--muted);font-weight:500;font-size:12px}
  .gm-lead-names{display:flex;flex-wrap:wrap;gap:5px}
  .gm-lead-names .none{color:var(--muted);font-size:12px}
  .gm-tbl{width:100%;border-collapse:collapse}
  .gm-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);padding:6px 10px;border-bottom:1px solid var(--line)}
  .gm-tbl td{padding:8px 10px;border-bottom:1px solid var(--line);font-size:13px;vertical-align:middle}
  .gm-tbl tr:last-child td{border-bottom:0}
  .gm-x{border:1px solid var(--line);background:var(--paper);color:var(--muted);border-radius:6px;width:26px;height:26px;line-height:1;cursor:pointer;font-weight:800}
  .gm-x:hover{border-color:#f3c7c7;color:var(--danger);background:#fff5f5}
  .gm-crown{color:#b8860b;margin-right:2px}
  .gm-col-lead{width:64px;text-align:center}
  .gm-leadbtn{border:1px solid var(--line);background:var(--paper);color:#b3bbb6;border-radius:6px;width:30px;height:26px;line-height:1;cursor:pointer;font-size:13px}
  .gm-leadbtn.on{background:#fff4d4;border-color:#e5c76b;color:#7a5400}
  .gm-leadbtn:hover{border-color:#e5c76b}
  .gm-leadbtn:disabled{opacity:.5;cursor:wait}
  .gm-inline{display:flex;gap:8px;flex-wrap:wrap;align-items:end}
  .gm-inline .field{margin-bottom:0}
  .gm-role{display:flex;align-items:center;gap:8px;padding:7px 10px;border-bottom:1px dashed var(--line)}
  .gm-role:last-child{border-bottom:0}
  .gm-role .gm-role-name{flex:1;font-size:13px;font-weight:600}
  /* let the members card show the typeahead results outside its rounded box */
  .gm-allow-overflow{overflow:visible}
  .gm-typeahead{position:relative}
  .gm-ta-results{position:absolute;top:100%;left:0;right:0;z-index:20;background:var(--paper);border:1px solid var(--line);border-radius:8px;box-shadow:0 12px 30px rgba(27,50,40,.16);max-height:260px;overflow:auto;margin-top:4px}
  .gm-ta-opt{padding:8px 11px;font-size:13px;cursor:pointer;border-bottom:1px solid var(--soft)}
  .gm-ta-opt:last-child{border-bottom:0}
  .gm-ta-opt:hover,.gm-ta-opt.is-active{background:var(--soft)}
  .gm-ta-opt small{color:var(--muted)}
  .gm-ta-empty{padding:9px 11px;font-size:12px;color:var(--muted)}
  .gm-hidden{display:none!important}
  .gm-toast{position:fixed;right:18px;bottom:18px;background:var(--deep);color:#fff;padding:10px 14px;border-radius:8px;font-size:13px;box-shadow:0 12px 30px rgba(12,47,40,.3);opacity:0;transform:translateY(8px);transition:opacity .15s,transform .15s;z-index:50}
  .gm-toast.show{opacity:1;transform:none}
  .gm-toast.error{background:#7a2222}
  @media(max-width:820px){.gm{grid-template-columns:1fr}}
</style>

<?php if (!$isAdmin): ?>
<div class="read-only-banner">You are viewing this page in read-only mode — ministry management requires portal-wide admin access.</div>
<?php endif; ?>

<div class="gm" id="gmRoot" data-can-write="<?= $isAdmin ? '1' : '0' ?>">

  <!-- LEFT: ministry list + create -->
  <aside class="gm-side">
    <article class="admin-card">
      <div class="admin-card-head"><div><h2>Ministries</h2><p>Pick one to manage its members &amp; roles.</p></div>
        <button class="button" id="gmNewBtn" type="button" <?= $isAdmin ? '' : 'aria-disabled="true"' ?>>+ New</button>
      </div>
      <div class="admin-card-body">
        <div class="gm-searchrow"><label class="sr-only" for="gmSearch">Search ministries</label><input type="search" id="gmSearch" placeholder="Search ministries…" autocomplete="off"></div>

        <form id="gmCreateForm" class="gm-hidden" style="margin-top:10px">
          <div class="field"><label for="gmNewName">Ministry name</label>
            <input id="gmNewName" type="text" maxlength="50" placeholder="e.g. Worship Team" required></div>
          <div class="field"><label for="gmNewCampus">Campus (optional)</label>
            <select id="gmNewCampus"><option value="">— None —</option></select></div>
          <div class="gm-inline">
            <button class="button" type="submit">Create ministry</button>
            <button class="button secondary" type="button" id="gmCreateCancel">Cancel</button>
          </div>
        </form>

        <div class="gm-list" id="gmList" style="margin-top:10px"></div>
      </div>
    </article>
  </aside>

  <!-- RIGHT: selected ministry detail -->
  <section class="admin-content" style="gap:14px">

    <div id="gmOverview" style="display:grid;gap:14px"></div>

    <div id="gmDetail" class="gm-hidden" style="display:grid;gap:14px">

      <!-- Header / actions -->
      <article class="admin-card">
        <div class="admin-card-head">
          <div><h2 id="gmDetailName">—</h2><p id="gmDetailMeta"></p></div>
          <span id="gmDetailStatus" class="admin-status admin-status-live">Active</span>
        </div>
        <div class="actions-bar" id="gmActions">
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="button secondary" id="gmEditBtn" type="button">Edit</button>
            <button class="button secondary" id="gmToggleBtn" type="button">Deactivate</button>
          </div>
          <button class="button danger" id="gmDeleteBtn" type="button">Delete permanently</button>
        </div>
        <div class="admin-card-body gm-hidden" id="gmEditBody">
          <form id="gmEditForm">
            <div class="field"><label for="gmEditName">Ministry name</label>
              <input id="gmEditName" type="text" maxlength="50" required></div>
            <div class="field"><label for="gmEditCampus">Campus</label>
              <select id="gmEditCampus"><option value="">— None —</option></select></div>
            <div class="gm-inline">
              <button class="button" type="submit">Save changes</button>
              <button class="button secondary" type="button" id="gmEditCancel">Cancel</button>
            </div>
          </form>
        </div>
      </article>

      <!-- Members -->
      <article class="admin-card gm-allow-overflow">
        <div class="admin-card-head"><div><h2>Members &amp; roles</h2><p>Everyone serving in this ministry and the role they hold.</p></div>
          <span class="gm-badge" id="gmMemberCount">0</span>
        </div>
        <div class="admin-card-body">
          <div id="gmMembers"></div>
        </div>
        <div class="actions-bar" style="justify-content:flex-start;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <div class="field gm-typeahead" style="margin-bottom:0;min-width:230px">
            <label for="gmPersonSearch">Add member</label>
            <input id="gmPersonSearch" type="text" autocomplete="off" placeholder="Type a name…">
            <input type="hidden" id="gmAddPerson">
            <div class="gm-ta-results gm-hidden" id="gmPersonResults"></div>
          </div>
          <div class="field" style="margin-bottom:0;min-width:150px"><label for="gmAddRole">As (group role)</label>
            <select id="gmAddRole"></select></div>
          <button class="button" id="gmAddMemberBtn" type="button">Add to ministry</button>
        </div>
      </article>

      <!-- Roles -->
      <article class="admin-card">
        <div class="admin-card-head"><div><h2>Ministry Assignments</h2><p>Assignment roles the scheduler uses for per-event assignments (e.g. Porter, Server). Separate from member group roles.</p></div></div>
        <div class="admin-card-body"><div id="gmRoles"></div></div>
        <div class="actions-bar" style="justify-content:flex-start;gap:8px">
          <input id="gmNewRole" type="text" placeholder="e.g. Leader, Vocalist, Usher" maxlength="128"
                 style="flex:1;border:1px solid var(--line);border-radius:8px;padding:9px 11px;font:inherit;background:var(--paper);color:var(--ink)">
          <button class="button" id="gmAddRoleBtn" type="button">Add role</button>
        </div>
      </article>

    </div>
  </section>
</div>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath'           => $basePath,
    'activeId'           => 'groups',
    'pageTitle'          => 'Members & leaders',
    'pageSubtitle'       => 'Create teams; manage members, roles, and leaders.',
    'sectionTitle'       => 'Members & leaders',
    'sectionDescription' => 'Create and manage ministry teams — members, serving roles, and leaders. Public visibility is Administration → Ministry list.',
    'actor'              => $actor,
    'campusSelector'     => $campusSelector,
    'isAdmin'            => $isAdmin,
], static fn (): string => $content);
?>
<script>
(function () {
    "use strict";
    const BASE = <?= json_encode($basePath) ?> || '';
    const CAMPUSES = <?= json_encode(array_map(static fn ($c): array => [
        'id' => (int) ($c['id'] ?? $c['campus_id'] ?? 0),
        'name' => (string) ($c['name'] ?? $c['campus_name'] ?? ''),
    ], $campuses)) ?>;
    const root = document.getElementById('gmRoot');
    const CAN_WRITE = root.getAttribute('data-can-write') === '1';
    const INITIAL_SLUG = <?= json_encode($slug ?? '') ?> || '';

    // The global campus is the header selector (#campusSelect); '' = all campuses.
    function currentCampus() {
        const sel = document.getElementById('campusSelect');
        return sel ? (sel.value || '') : '';
    }
    function withCampus(path) {
        const c = currentCampus();
        return c ? path + (path.includes('?') ? '&' : '?') + 'current_campus_id=' + encodeURIComponent(c) : path;
    }

    const api = (method, path, body) => fetch(BASE + path, {
        method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    }).then(async (r) => {
        const data = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(data.error || (method + ' ' + path + ' failed (' + r.status + ')'));
        return data;
    });

    let toastTimer = null;
    function toast(msg, isError) {
        let el = document.getElementById('gmToast');
        if (!el) { el = document.createElement('div'); el.id = 'gmToast'; el.className = 'gm-toast'; document.body.appendChild(el); }
        el.textContent = msg;
        el.className = 'gm-toast show' + (isError ? ' error' : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { el.className = 'gm-toast'; }, 2600);
    }
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
    const isLeaderName = (n) => /leader|head|coordinator|director|pastor/i.test(String(n || ''));
    // "Field Ministry" -> "field_ministry" (URL slug for deep links)
    const slugify = (name) => String(name || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    // loose key for matching a URL slug to a name, tolerant of -/_/space
    const slugKey = (s) => String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
    function ministryUrl(name) { return BASE + '/admin/groups-and-ministries/' + slugify(name); }

    // ---- state ----
    let ministries = [];
    let people = [];
    let selectedId = 0;
    let currentRoles = [];
    let currentMembers = [];
    let groupRoles = [];       // member-type roles (Member/Leader/Teacher) for the picker
    let leadersByMinistry = {};

    // ---- campus <select> options ----
    function fillCampusSelect(sel, selectedCampusId) {
        sel.innerHTML = '<option value="">— None —</option>' +
            CAMPUSES.filter((c) => c.id > 0).map((c) =>
                '<option value="' + c.id + '"' + (c.id === selectedCampusId ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('');
    }

    // ---- left list ----
    function renderList() {
        const q = (document.getElementById('gmSearch').value || '').trim().toLowerCase();
        const rows = ministries.filter((m) => q === '' || m.name.toLowerCase().includes(q));
        const el = document.getElementById('gmList');
        if (!rows.length) { el.innerHTML = '<div class="gm-empty">No ministries match.</div>'; return; }
        el.innerHTML = rows.map((m) =>
            '<div class="gm-item' + (m.ministry_id === selectedId ? ' is-active' : '') + (m.active ? '' : ' is-inactive') + '" data-id="' + m.ministry_id + '">' +
              '<div class="gm-name"><span class="gm-dot' + (m.active ? '' : ' off') + '"></span>' + esc(m.name) +
                (m.active ? '' : ' <span class="gm-badge inactive">inactive</span>') + '</div>' +
              '<div class="gm-meta">' + m.member_count + ' member' + (m.member_count === 1 ? '' : 's') +
                ' · ' + (m.leader_count || 0) + ' leader' + ((m.leader_count || 0) === 1 ? '' : 's') + '</div>' +
            '</div>').join('');
    }

    async function loadMinistries(keepSelection) {
        try {
            const [mData, lData] = await Promise.all([
                api('GET', withCampus('/api/admin/ministries')),
                api('GET', withCampus('/api/admin/leaders')),
            ]);
            ministries = Array.isArray(mData.ministries) ? mData.ministries : [];
            leadersByMinistry = {};
            (Array.isArray(lData.leaders) ? lData.leaders : []).forEach((l) => {
                (leadersByMinistry[l.ministry_id] = leadersByMinistry[l.ministry_id] || []).push(l);
            });
        } catch (e) { toast(e.message, true); ministries = []; leadersByMinistry = {}; }
        if (!keepSelection) selectedId = 0;
        renderList();
        renderOverview();
    }

    function findMinistry(id) { return ministries.find((m) => m.ministry_id === id) || null; }

    // ---- overview dashboard (shown until a ministry is selected) ----
    function currentCampusName() {
        const c = currentCampus();
        if (!c) return 'All campuses';
        const hit = CAMPUSES.find((x) => String(x.id) === String(c));
        return hit ? hit.name : 'Selected campus';
    }
    function renderOverview() {
        const el = document.getElementById('gmOverview');
        if (!el) return;
        const total = ministries.length;
        const active = ministries.filter((m) => m.active).length;
        const members = ministries.reduce((s, m) => s + (m.member_count || 0), 0);
        const leaders = ministries.reduce((s, m) => s + (m.leader_count || 0), 0);
        const roles = ministries.reduce((s, m) => s + (m.role_count || 0), 0);
        const noLeader = ministries.filter((m) => m.active && (m.leader_count || 0) === 0);
        const ranked = ministries.slice().sort((a, b) =>
            (b.leader_count || 0) - (a.leader_count || 0) ||
            (b.member_count || 0) - (a.member_count || 0) ||
            String(a.name).localeCompare(String(b.name)));

        const stat = (v, l) => '<div class="gm-stat"><div class="v">' + v + '</div><div class="l">' + l + '</div></div>';

        let html = '<article class="admin-card">' +
            '<div class="admin-card-head"><div><h2>Ministries overview</h2><p>' + esc(currentCampusName()) + '</p></div></div>' +
            '<div class="admin-card-body">' +
              '<div class="gm-stats">' +
                stat(active + ' / ' + total, 'Active ministries') +
                stat(members, 'Members') +
                stat(leaders, 'Leaders') +
                stat(roles, 'Roles') +
              '</div>' +
              (noLeader.length
                ? '<div class="gm-warn">♛ ' + noLeader.length + ' active ministr' + (noLeader.length === 1 ? 'y has' : 'ies have') + ' no leader tagged yet.</div>'
                : '') +
            '</div></article>';

        if (total) {
            html += '<article class="admin-card"><div class="admin-card-head"><div><h2>Leaders by ministry</h2>' +
                '<p>Click a ministry to manage its members, roles &amp; leaders.</p></div></div>' +
                '<div class="admin-card-body" style="padding:0">' +
                ranked.map((m) => {
                    const leaders = leadersByMinistry[m.ministry_id] || [];
                    const names = leaders.length
                        ? leaders.map((l) => '<span class="gm-badge leader">♛ ' + esc(l.display_name) + '</span>').join(' ')
                        : '<span class="none">No leaders tagged yet</span>';
                    return '<div class="gm-lead-row gm-open" data-open-ministry="' + m.ministry_id + '">' +
                        '<div class="gm-lead-head"><span class="gm-dot' + (m.active ? '' : ' off') + '"></span>' + esc(m.name) +
                            (m.active ? '' : ' <span class="gm-badge inactive">inactive</span>') +
                            ' <span class="sub">· ' + (m.member_count || 0) + ' members</span></div>' +
                        '<div class="gm-lead-names">' + names + '</div>' +
                    '</div>';
                }).join('') +
                '</div></article>';
        } else {
            html += '<div class="admin-card"><div class="admin-card-body"><div class="gm-empty">No ministries in this campus yet. Use “+ New” to create one.</div></div></div>';
        }
        el.innerHTML = html;
    }

    // open a ministry from the overview table
    document.getElementById('gmOverview').addEventListener('click', (e) => {
        const row = e.target.closest('[data-open-ministry]');
        if (row) selectMinistry(parseInt(row.dataset.openMinistry, 10));
    });

    // ---- detail ----
    async function selectMinistry(id) {
        selectedId = id;
        renderList();
        document.getElementById('gmOverview').classList.add('gm-hidden');
        document.getElementById('gmDetail').classList.remove('gm-hidden');
        document.getElementById('gmEditBody').classList.add('gm-hidden');
        const m = findMinistry(id);
        if (!m) return;
        // Keep the address bar in sync so the ministry is shareable/bookmarkable.
        try { history.replaceState({ ministryId: id }, '', ministryUrl(m.name)); } catch (e) {}
        document.getElementById('gmDetailName').textContent = m.name;
        const campusName = (CAMPUSES.find((c) => c.id === m.campus_id) || {}).name || '';
        document.getElementById('gmDetailMeta').textContent = 'Ministry #' + m.ministry_id + (campusName ? ' · ' + campusName : '');
        const status = document.getElementById('gmDetailStatus');
        status.textContent = m.active ? 'Active' : 'Inactive';
        status.className = 'admin-status ' + (m.active ? 'admin-status-live' : 'admin-status-planned');
        document.getElementById('gmToggleBtn').textContent = m.active ? 'Deactivate' : 'Activate';
        // Add-member pool follows the global (header) campus.
        await loadPeople();
        await loadRoles(id);
    }

    async function loadRoles(id) {
        try {
            const [rolesData, membersData, grData] = await Promise.all([
                api('GET', '/api/ministry/' + id + '/roles'),
                api('GET', withCampus('/api/ministry/' + id + '/members')),
                api('GET', '/api/ministry/' + id + '/group-roles'),
            ]);
            currentRoles = Array.isArray(rolesData.roles) ? rolesData.roles : [];
            currentMembers = Array.isArray(membersData.members) ? membersData.members : [];
            groupRoles = Array.isArray(grData.groupRoles) ? grData.groupRoles : [];
            renderMembers();
            renderRoles();
            fillRoleSelect();
        } catch (e) { toast(e.message, true); }
    }

    // Collapse the raw person2group2role rows into one entry per person, so a
    // member with several roles prints once and each role name shows once.
    function membersByPerson() {
        const map = new Map();
        currentMembers.forEach((m) => {
            if (!map.has(m.person_id)) {
                map.set(m.person_id, { personId: m.person_id, name: m.display_name, roles: [] });
            }
            map.get(m.person_id).roles.push({ id: m.role_id, name: m.role_name || '', isLeader: !!m.is_leader });
        });
        const rows = Array.from(map.values());
        rows.forEach((r) => { r.isLeader = r.roles.some((x) => x.isLeader); });
        rows.sort((a, b) => String(a.name).localeCompare(String(b.name)));
        return rows;
    }

    function renderMembers() {
        const rows = membersByPerson();
        document.getElementById('gmMemberCount').textContent = rows.length;
        const el = document.getElementById('gmMembers');
        if (!rows.length) { el.innerHTML = '<div class="gm-empty">No members yet. Add one below.</div>'; return; }
        el.innerHTML = '<table class="gm-tbl"><thead><tr><th>Member</th><th>Role</th>' +
            (CAN_WRITE ? '<th class="gm-col-lead">Leader</th><th></th>' : '') + '</tr></thead><tbody>' +
            rows.map((x) => {
                const roleNames = Array.from(new Set(x.roles.map((r) => r.name).filter(Boolean)));
                const roleLabel = roleNames.length ? roleNames.map(esc).join(', ') : '—';
                const crown = x.isLeader ? '<span class="gm-crown" title="Leader">♛</span>' : '';
                return '<tr>' +
                    '<td>' + crown + esc(x.name) + '</td>' +
                    '<td>' + roleLabel + '</td>' +
                    (CAN_WRITE
                        ? '<td class="gm-col-lead"><button class="gm-leadbtn' + (x.isLeader ? ' on' : '') + '"' +
                              ' data-lead-person="' + x.personId + '" data-lead-state="' + (x.isLeader ? '1' : '0') + '"' +
                              ' title="' + (x.isLeader ? 'Remove leader tag' : 'Tag as leader') + '">♛</button></td>' +
                          '<td style="text-align:right"><button class="gm-x" data-remove-person="' + x.personId + '" title="Remove from ministry">&times;</button></td>'
                        : '') +
                '</tr>';
            }).join('') + '</tbody></table>';
    }

    function renderRoles() {
        const el = document.getElementById('gmRoles');
        if (!currentRoles.length) { el.innerHTML = '<div class="gm-empty">No roles yet. Add one below.</div>'; return; }
        el.innerHTML = currentRoles.map((r) =>
            '<div class="gm-role"><span class="gm-role-name">' + esc(r.name) +
                (r.isLeaderRole ? ' <span class="gm-badge leader">leader</span>' : '') + '</span>' +
                '<span class="gm-badge">' + (r.assignedCount || 0) + '</span>' +
                (CAN_WRITE ? '<button class="gm-x" data-delete-role="' + r.id + '" title="Delete role">&times;</button>' : '') +
            '</div>').join('');
    }

    // Role <select> (the person picker is now a typeahead, below).
    function fillRoleSelect() {
        const rSel = document.getElementById('gmAddRole');
        // Member "group role" (member type): Member / Leader / Teacher — NOT the
        // ministry assignment roles. Default to the group's default (Member).
        let list = groupRoles.slice();
        if (!list.length) list = [{ id: 0, name: 'Member', is_default: true }];
        const def = list.find((r) => r.is_default) || list[0];
        rSel.innerHTML = list.map((r) =>
            '<option value="' + r.id + '"' + (def && r.id === def.id ? ' selected' : '') + '>' + esc(r.name) + '</option>').join('');
    }

    // Load the candidate people pool for the current (header) campus.
    async function loadPeople() {
        const c = currentCampus();
        const q = '?campus_id=' + encodeURIComponent(c ? c : 'all');
        try {
            const data = await api('GET', '/api/people-directory' + q);
            people = (Array.isArray(data.people) ? data.people : [])
                .map((p) => ({ personId: p.personId, displayName: p.displayName }))
                .sort((a, b) => String(a.displayName).localeCompare(String(b.displayName)));
        } catch (e) { people = []; }
        clearPersonPick();
    }

    // ---- person typeahead ----
    let taMatches = [], taIndex = -1;
    function clearPersonPick() {
        const hid = document.getElementById('gmAddPerson');
        const inp = document.getElementById('gmPersonSearch');
        if (hid) hid.value = '';
        if (inp) inp.value = '';
        hidePersonResults();
    }
    function hidePersonResults() {
        document.getElementById('gmPersonResults').classList.add('gm-hidden');
        taMatches = []; taIndex = -1;
    }
    function renderPersonResults(q) {
        const box = document.getElementById('gmPersonResults');
        const query = String(q || '').trim().toLowerCase();
        taMatches = (query === '' ? people : people.filter((p) => p.displayName.toLowerCase().includes(query))).slice(0, 40);
        if (taIndex >= taMatches.length) taIndex = -1;
        if (!taMatches.length) {
            box.innerHTML = '<div class="gm-ta-empty">' + (people.length ? 'No matching person.' : 'No people in this campus.') + '</div>';
            box.classList.remove('gm-hidden');
            return;
        }
        box.innerHTML = taMatches.map((p, i) =>
            '<div class="gm-ta-opt' + (i === taIndex ? ' is-active' : '') + '" data-idx="' + i + '">' + esc(p.displayName) + '</div>').join('');
        box.classList.remove('gm-hidden');
    }
    function pickPerson(i) {
        const p = taMatches[i]; if (!p) return;
        document.getElementById('gmAddPerson').value = p.personId;
        document.getElementById('gmPersonSearch').value = p.displayName;
        hidePersonResults();
    }

    // ---- write handlers (guarded) ----
    function guard() { if (!CAN_WRITE) { toast('Admin access required.', true); return false; } return true; }

    // create
    document.getElementById('gmNewBtn').addEventListener('click', () => {
        if (!guard()) return;
        document.getElementById('gmCreateForm').classList.toggle('gm-hidden');
        document.getElementById('gmNewName').focus();
    });
    document.getElementById('gmCreateCancel').addEventListener('click', () => document.getElementById('gmCreateForm').classList.add('gm-hidden'));
    document.getElementById('gmCreateForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!guard()) return;
        const name = document.getElementById('gmNewName').value.trim();
        const campus_id = document.getElementById('gmNewCampus').value;
        if (!name) return;
        try {
            const data = await api('POST', '/api/admin/ministries', { name, campus_id });
            document.getElementById('gmNewName').value = '';
            document.getElementById('gmCreateForm').classList.add('gm-hidden');
            await loadMinistries(true);
            const newId = data.ministry && data.ministry.ministry_id;
            if (newId) selectMinistry(newId);
            toast('Ministry created.');
        } catch (err) { toast(err.message, true); }
    });

    // edit
    document.getElementById('gmEditBtn').addEventListener('click', () => {
        if (!guard()) return;
        const m = findMinistry(selectedId); if (!m) return;
        document.getElementById('gmEditName').value = m.name;
        fillCampusSelect(document.getElementById('gmEditCampus'), m.campus_id);
        document.getElementById('gmEditBody').classList.toggle('gm-hidden');
    });
    document.getElementById('gmEditCancel').addEventListener('click', () => document.getElementById('gmEditBody').classList.add('gm-hidden'));
    document.getElementById('gmEditForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!guard()) return;
        const name = document.getElementById('gmEditName').value.trim();
        const campus_id = document.getElementById('gmEditCampus').value;
        if (!name) return;
        try {
            await api('PUT', '/api/admin/ministries/' + selectedId, { name, campus_id });
            await loadMinistries(true);
            await selectMinistry(selectedId);
            toast('Saved.');
        } catch (err) { toast(err.message, true); }
    });

    // activate / deactivate
    document.getElementById('gmToggleBtn').addEventListener('click', async () => {
        if (!guard()) return;
        const m = findMinistry(selectedId); if (!m) return;
        try {
            await api('POST', '/api/admin/ministries/' + selectedId + '/active', { active: !m.active });
            await loadMinistries(true);
            await selectMinistry(selectedId);
            toast(m.active ? 'Ministry deactivated.' : 'Ministry activated.');
        } catch (err) { toast(err.message, true); }
    });

    // delete (hard)
    document.getElementById('gmDeleteBtn').addEventListener('click', async () => {
        if (!guard()) return;
        const m = findMinistry(selectedId); if (!m) return;
        if (!window.confirm('Permanently delete “' + m.name + '”?\n\nThis removes the ministry and all its memberships and roles. This cannot be undone.')) return;
        try {
            await api('DELETE', '/api/admin/ministries/' + selectedId);
            selectedId = 0;
            resetUrl();
            document.getElementById('gmDetail').classList.add('gm-hidden');
            document.getElementById('gmOverview').classList.remove('gm-hidden');
            await loadMinistries(false);
            toast('Ministry deleted.');
        } catch (err) { toast(err.message, true); }
    });

    // add member
    document.getElementById('gmAddMemberBtn').addEventListener('click', async () => {
        if (!guard()) return;
        const personId = parseInt(document.getElementById('gmAddPerson').value, 10);
        const roleId = parseInt(document.getElementById('gmAddRole').value, 10);
        if (!personId) { toast('Pick a person.', true); return; }
        if (!roleId) { toast('Add a role first, then pick one.', true); return; }
        try {
            await setMemberRole(personId, roleId);
            clearPersonPick();
            await loadRoles(selectedId);
            await loadMinistries(true);
            toast('Member added.');
        } catch (err) { toast(err.message, true); }
    });

    // person typeahead events
    const psInput = document.getElementById('gmPersonSearch');
    psInput.addEventListener('input', function () {
        document.getElementById('gmAddPerson').value = ''; // typing invalidates a prior pick
        taIndex = -1;
        renderPersonResults(this.value);
    });
    psInput.addEventListener('focus', function () { renderPersonResults(this.value); });
    psInput.addEventListener('keydown', function (e) {
        if (document.getElementById('gmPersonResults').classList.contains('gm-hidden')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); taIndex = Math.min(taIndex + 1, taMatches.length - 1); renderPersonResults(this.value); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); taIndex = Math.max(taIndex - 1, 0); renderPersonResults(this.value); }
        else if (e.key === 'Enter') { e.preventDefault(); if (taIndex >= 0) pickPerson(taIndex); else if (taMatches.length === 1) pickPerson(0); }
        else if (e.key === 'Escape') { hidePersonResults(); }
    });
    document.getElementById('gmPersonResults').addEventListener('mousedown', function (e) {
        const opt = e.target.closest('.gm-ta-opt'); if (!opt) return;
        e.preventDefault();
        pickPerson(parseInt(opt.dataset.idx, 10));
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.gm-typeahead')) hidePersonResults();
    });

    function setMemberRole(personId, roleId) {
        return api('POST', '/api/ministry/' + selectedId + '/members/' + personId + '/role', { role_id: roleId });
    }
    // Leader is a SEPARATE tag — it never changes the member's work/assignment role.
    async function tagLeader(personId) {
        await api('POST', '/api/ministry/' + selectedId + '/leaders/' + personId);
    }
    async function untagLeader(personId) {
        await api('DELETE', '/api/ministry/' + selectedId + '/leaders/' + personId);
    }
    async function removeMemberEntirely(personId) {
        await api('DELETE', '/api/ministry/' + selectedId + '/members/' + personId);
    }

    // member row actions: leader toggle + remove-from-ministry (event delegation)
    document.getElementById('gmMembers').addEventListener('click', async (e) => {
        const leadBtn = e.target.closest('[data-lead-person]');
        if (leadBtn) {
            if (!guard()) return;
            const pid = parseInt(leadBtn.dataset.leadPerson, 10);
            const isOn = leadBtn.dataset.leadState === '1';
            leadBtn.disabled = true;
            try {
                if (isOn) { await untagLeader(pid); toast('Leader tag removed.'); }
                else { await tagLeader(pid); toast('Tagged as leader.'); }
                await loadRoles(selectedId);
                await loadMinistries(true);
            } catch (err) { toast(err.message, true); leadBtn.disabled = false; }
            return;
        }
        const rmBtn = e.target.closest('[data-remove-person]');
        if (rmBtn) {
            if (!guard()) return;
            const pid = parseInt(rmBtn.dataset.removePerson, 10);
            try {
                await removeMemberEntirely(pid);
                await loadRoles(selectedId);
                await loadMinistries(true);
                toast('Member removed.');
            } catch (err) { toast(err.message, true); }
        }
    });

    // add role
    document.getElementById('gmAddRoleBtn').addEventListener('click', async () => {
        if (!guard()) return;
        const input = document.getElementById('gmNewRole');
        const name = input.value.trim();
        if (!name) return;
        try {
            await api('POST', '/api/ministry/' + selectedId + '/roles', { name });
            input.value = '';
            await loadRoles(selectedId);
            await loadMinistries(true);
            toast('Role added.');
        } catch (err) { toast(err.message, true); }
    });

    // delete role
    document.getElementById('gmRoles').addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-delete-role]'); if (!btn || !guard()) return;
        if (!window.confirm('Delete this role? Members holding it will be unassigned.')) return;
        try {
            await api('DELETE', '/api/ministry/roles/' + btn.dataset.deleteRole);
            await loadRoles(selectedId);
            await loadMinistries(true);
            toast('Role deleted.');
        } catch (err) { toast(err.message, true); }
    });

    // list click + search
    document.getElementById('gmList').addEventListener('click', (e) => {
        const item = e.target.closest('.gm-item'); if (!item) return;
        selectMinistry(parseInt(item.dataset.id, 10));
    });
    document.getElementById('gmSearch').addEventListener('input', renderList);

    // Global campus (header selector) changes → re-filter the whole page.
    const headerCampus = document.getElementById('campusSelect');
    if (headerCampus) {
        headerCampus.addEventListener('change', async () => {
            const prev = selectedId;
            await loadMinistries(true);
            if (prev && findMinistry(prev)) {
                await selectMinistry(prev);
            } else {
                selectedId = 0;
                resetUrl();
                document.getElementById('gmDetail').classList.add('gm-hidden');
                document.getElementById('gmOverview').classList.remove('gm-hidden');
                renderList();
            }
        });
    }

    function resetUrl() { try { history.replaceState({}, '', BASE + '/admin/groups-and-ministries'); } catch (e) {} }

    // ---- boot ----
    fillCampusSelect(document.getElementById('gmNewCampus'), null);
    (async () => {
        await loadMinistries(false);
        if (INITIAL_SLUG) {
            const want = slugKey(INITIAL_SLUG);
            const match = ministries.find((mm) => slugKey(mm.name) === want);
            if (match) {
                selectMinistry(match.ministry_id);
            } else {
                toast('No ministry matches “' + INITIAL_SLUG + '” in this campus.', true);
            }
        }
    })();
})();
</script>
