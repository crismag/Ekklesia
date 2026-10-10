<?php

declare(strict_types=1);

/**
 * /admin/ministries — Manage ministries (Ministries workspace, administrators).
 *
 * Create a ministry, rename it or move it to a campus, deactivate or activate
 * it, delete it; and the public-listing settings the church website reads.
 * Writes go to the existing /api/admin/ministries* endpoints, which refuse
 * anyone but a portal-wide administrator. Who is in a ministry is managed on
 * the ministry's own Members & leaders tab, not here.
 *
 * Rendered once: this page used to call admin_render_page() twice and so drew
 * two complete pages, the second a leftover tile board.
 *
 * @var array<string,mixed> $req
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var array<string,mixed> $campusSelector
 */

require_once __DIR__ . '/_admin-shell.php';
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);

$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if (!$isAdmin) {
    echo admin_render_page([
        'basePath' => $basePath, 'activeId' => 'ministries',
        'pageTitle' => 'Manage ministries', 'pageSubtitle' => '',
        'sectionTitle' => 'Manage ministries',
        'sectionDescription' => 'Creating, renaming and retiring ministries is for administrators.',
        'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => false,
    ], static fn (): string => '<article class="ek-card"><div class="ek-empty">'
        . '<strong>Managing ministries is for administrators</strong>'
        . '<p>Leaders look after their ministry\'s members and serving roles from the ministry itself.</p>'
        . '<a class="ek-btn ek-btn-primary" href="' . $base . '/ministries">Go to Ministries</a>'
        . '</div></article>');
    return;
}

$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$campusNames = [];
foreach ($campuses as $c) {
    $campusNames[(int) ($c['id'] ?? 0)] = (string) ($c['name'] ?? '');
}

// Every ministry, active or not, counted across all campuses.
$rows = [];
$loadError = false;
try {
    $context = \App\Providers\PortalServiceProvider::makeRequestContext()->fromArray($req);
    $rows = \App\Providers\PortalServiceProvider::makeMinistryService()->listMinistriesAdmin($context, null);
} catch (\Throwable) {
    $loadError = true;
}
usort($rows, static fn (array $a, array $b): int => ((int) !$a['active'] <=> (int) !$b['active']) ?: strcasecmp((string) $a['name'], (string) $b['name']));
$activeCount = count(array_filter($rows, static fn (array $r): bool => (bool) $r['active']));

$campusOptions = static function (?int $selected) use ($campuses, $h): string {
    $out = '<option value="">All campuses</option>';
    foreach ($campuses as $c) {
        $id = (int) ($c['id'] ?? 0);
        if ($id > 0) {
            $out .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>' . $h($c['name'] ?? '') . '</option>';
        }
    }
    return $out;
};

$tableRows = '';
foreach ($rows as $m) {
    $id = (int) $m['ministry_id'];
    $campusId = $m['campus_id'] === null ? null : (int) $m['campus_id'];
    $campusLabel = $campusId === null ? 'All campuses' : ($campusNames[$campusId] ?? 'Campus #' . $campusId);
    $active = (bool) $m['active'];
    $tableRows .= '<tr id="ministry-' . $id . '" data-id="' . $id . '" data-name="' . $h($m['name']) . '" data-active="' . ($active ? '1' : '0') . '"'
        . ' data-members="' . (int) $m['member_count'] . '" data-roles="' . (int) $m['role_count'] . '" data-search="' . $h(strtolower((string) $m['name'])) . '">'
        . '<td><a href="' . $base . '/ministries/' . $id . '"><strong>' . $h($m['name']) . '</strong></a>'
        . '<form class="mm-edit" hidden data-edit-form>'
        . '<div class="ek-field"><label for="mmName' . $id . '">Name</label><input class="ek-input" id="mmName' . $id . '" type="text" maxlength="50" required value="' . $h($m['name']) . '"></div>'
        . '<div class="ek-field"><label for="mmCampus' . $id . '">Campus</label><select class="ek-select" id="mmCampus' . $id . '">' . $campusOptions($campusId) . '</select></div>'
        . '<button class="ek-btn ek-btn-primary" type="submit">Save</button>'
        . '<button class="ek-btn ek-btn-quiet" type="button" data-cancel>Cancel</button>'
        . '</form></td>'
        . '<td><span class="mm-lbl">Campus: </span>' . $h($campusLabel) . '</td>'
        . '<td class="is-num"><span class="mm-lbl">Members: </span>' . (int) $m['member_count'] . '</td>'
        . '<td class="is-num"><span class="mm-lbl">Leaders: </span>' . (int) $m['leader_count'] . '</td>'
        . '<td class="is-num"><span class="mm-lbl">Serving roles: </span>' . (int) $m['role_count'] . '</td>'
        . '<td>' . ($active ? '<span class="ek-badge is-ok">Active</span>' : '<span class="ek-badge is-warn">Inactive</span>') . '</td>'
        . '<td class="mm-do">'
        . '<button class="ek-btn" type="button" data-act="edit" aria-label="Rename or move ' . $h($m['name']) . '">Edit</button>'
        . '<button class="ek-btn" type="button" data-act="toggle" aria-label="' . ($active ? 'Deactivate ' : 'Activate ') . $h($m['name']) . '">' . ($active ? 'Deactivate' : 'Activate') . '</button>'
        . '<button class="ek-btn ek-btn-danger" type="button" data-act="delete" aria-label="Delete ' . $h($m['name']) . '">Delete</button>'
        . '</td></tr>';
}

$manageHtml = '<div id="mmFlash" role="status" aria-live="polite"></div>'
    . '<article class="ek-card" aria-labelledby="mmAddHead">'
    . '<div class="ek-card-head"><div><h2 id="mmAddHead">Add a ministry</h2><p>Then open it to add members, leaders and serving roles.</p></div></div>'
    . '<div class="ek-card-body"><form class="mm-add" id="mmAddForm" novalidate>'
    . '<div class="ek-field"><label for="mmNewName">Ministry name</label><input class="ek-input" id="mmNewName" type="text" maxlength="50" required autocomplete="off"><span class="ek-hint">Up to 50 characters.</span></div>'
    . '<div class="ek-field"><label for="mmNewCampus">Campus</label><select class="ek-select" id="mmNewCampus">' . $campusOptions(null) . '</select><span class="ek-hint">Leave as All campuses for a church-wide ministry.</span></div>'
    . '<button class="ek-btn ek-btn-primary" type="submit">Create ministry</button>'
    . '</form></div></article>'

    . '<article class="ek-card" aria-labelledby="mmListHead">'
    . '<div class="ek-card-head"><div><h2 id="mmListHead">Ministries <span class="mm-sub">(' . $activeCount . ' active of ' . count($rows) . ')</span></h2>'
    . '<p>Deactivating hides a ministry from the directory and the schedule editor and keeps its history. Deleting removes it with its memberships and serving roles.</p></div>'
    . ($rows !== [] ? '<div class="ek-field mm-filter"><label class="sr-only" for="mmFilter">Filter ministries</label><input class="ek-input" id="mmFilter" type="search" placeholder="Filter by name…" autocomplete="off"></div>' : '')
    . '</div>'
    . ($loadError
        ? '<div class="ek-card-body"><div class="ek-alert is-error"><div><strong>The ministry list could not be loaded.</strong> Reload the page to try again.</div></div></div>'
        : ($rows === []
            ? '<div class="ek-empty"><strong>No ministries yet</strong><p>Create the first one above.</p></div>'
            : '<div class="ek-table-wrap"><table class="ek-table mm-table" id="mmTable"><thead><tr>'
              . '<th scope="col">Ministry</th><th scope="col">Campus</th><th scope="col" class="is-num">Members</th><th scope="col" class="is-num">Leaders</th>'
              . '<th scope="col" class="is-num">Serving roles</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th>'
              . '</tr></thead><tbody>' . $tableRows . '</tbody></table></div>'
              . '<div id="mmNoMatch" hidden><div class="ek-empty"><strong>No ministry by that name</strong><p>Clear the filter to see them all.</p></div></div>'))
    . '</article>';

// The list an administrator picks from. Without it this page can only ask for
// identifiers, which is what it used to do.
$allMinistries = [];
try {
    $allMinistries = \App\Providers\PortalServiceProvider::makeMinistryService()->listMinistriesPublic(null);
} catch (\Throwable) {
    $allMinistries = [];
}

$checkboxes = '';
foreach ($allMinistries as $m) {
    $id = (int) ($m['ministry_id'] ?? 0);
    $name = (string) ($m['name'] ?? '');
    if ($id <= 0 || $name === '') {
        continue;
    }
    $checkboxes .= '<label class="mv-option" data-name="' . $h(strtolower($name)) . '">'
        . '<input type="checkbox" class="mv-check" value="' . $id . '" data-name="' . $h($name) . '">'
        . '<span>' . $h($name) . '</span></label>';
}

$visibilityHtml = '<article class="admin-card" id="public-listing">'
    . '<div class="admin-card-head"><div><h2>Public listing</h2>'
    . '<p>What the church website may show of ministries, and which ministries it should leave out. Nothing here changes the portal.</p></div></div>'
    . '<div class="admin-card-body">'

    . '<fieldset class="mv-fieldset"><legend>Public ministry pages</legend>'
    . '<div class="mv-inline">'
    . '<label class="mv-option"><input type="checkbox" id="admin_page_board" checked><span>Schedule board</span></label>'
    . '<label class="mv-option"><input type="checkbox" id="admin_page_directory" checked><span>Ministry directory</span></label>'
    . '</div></fieldset>'

    // Was: "Exclude ministries (comma-separated IDs or names)" with a
    // placeholder of "12,34,Children Ministry,Outreach". Church office staff do
    // not know ministry IDs, and nothing on the page told them. The stored
    // value is unchanged — still a list of IDs — it is now assembled by
    // ticking names.
    . '<fieldset class="mv-fieldset"><legend>Hide these ministries from the public directory</legend>'
    . '<p class="mv-help">Everything is listed publicly unless you tick it here. Ticking a ministry '
    . 'hides it from the public site only — it keeps working normally inside the portal.</p>'
    . ($allMinistries === []
        ? '<p class="mv-help">The ministry list could not be loaded, so this cannot be edited right now.</p>'
        : '<label class="sr-only" for="mv_search">Search ministries</label>'
          . '<input id="mv_search" type="search" class="mv-search" placeholder="Search ministries…" autocomplete="off">'
          . '<div class="mv-list" id="mv_list">' . $checkboxes . '</div>'
          . '<p class="mv-help" id="mv_summary" role="status"></p>')
    . '<input id="admin_excluded_ministries" type="hidden">'
    . '<p class="mv-help mv-unmatched" id="mv_unmatched" hidden></p>'
    . '</fieldset>'

    . '<fieldset class="mv-fieldset"><legend>Public page wording</legend>'
    . '<div class="mv-field"><label for="admin_title">Page title</label>'
    . '<input id="admin_title" type="text" placeholder="Ministry Schedule Board"></div>'
    . '<div class="mv-field"><label for="admin_subtitle">Short description</label>'
    . '<input id="admin_subtitle" type="text" placeholder="Upcoming ministry schedules by date and event."></div>'
    . '<div class="mv-field"><label for="admin_hero_lead">Introduction</label>'
    . '<textarea id="admin_hero_lead" rows="4" placeholder="A compact board for seeing ministry schedules across the church…"></textarea></div>'
    . '</fieldset>'

    . '<details class="mv-advanced"><summary>Advanced</summary><div class="mv-advanced-body">'
    . '<div class="mv-field"><label for="admin_excluded_groups">Hide these groups from public listings</label>'
    . '<p class="mv-help">Groups that are not ministries. Enter names separated by commas.</p>'
    . '<input id="admin_excluded_groups" type="text" placeholder="Small Group A, Prayer Chain"></div>'
    . '</div></details>'

    . '<div class="mv-actions">'
    . '<button id="admin_save_ministries" class="button primary">Save settings</button> '
    . '<button id="admin_preview_ministries" class="button">Preview schedule board</button>'
    . '</div>'
    . '</div></article>';

ob_start();
?>
<style>
  .mm-sub{color:var(--muted);font-size:13px;font-weight:500}
  .mm-add{display:grid;gap:var(--sp-3,12px);grid-template-columns:minmax(0,2fr) minmax(0,1fr) auto;align-items:start}
  .mm-add .ek-btn{margin-top:26px}
  .mm-filter{max-width:260px}
  .mm-table td.mm-do{white-space:nowrap;text-align:right}
  .mm-table .ek-btn{min-height:32px;padding:4px 10px;font-size:13px}
  .mm-table a{text-decoration:none}
  .mm-lbl{display:none}
  .mm-edit{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-top:8px}
  .mm-edit .ek-field{flex:1 1 160px}
  #public-listing{margin-top:0}
  [hidden]{display:none!important}
  @media (max-width:720px){
    .mm-add{grid-template-columns:1fr}.mm-add .ek-btn{margin-top:0}
    .mm-table thead{display:none}
    .mm-table tr{display:grid;gap:4px;padding:10px 12px;border-bottom:1px solid var(--line,#d9e4dd)}
    .mm-table td{padding:0;border:0;text-align:left!important}
    .mm-table .mm-lbl{display:inline;color:var(--muted)}
    .mm-table td.mm-do{white-space:normal}
  }
</style>
<script>
(function () {
  'use strict';
  var BASE = <?= json_encode($basePath) ?> || '';
  var flash = document.getElementById('mmFlash');
  function say(message, isError) {
    flash.innerHTML = '';
    var box = document.createElement('div'); box.className = 'ek-alert ' + (isError ? 'is-error' : 'is-ok');
    var text = document.createElement('div');
    if (isError) { var s = document.createElement('strong'); s.textContent = 'Not saved. '; text.appendChild(s); }
    text.appendChild(document.createTextNode(message)); box.appendChild(text); flash.appendChild(box);
    flash.scrollIntoView({ block: 'nearest' });
  }
  try { var kept = sessionStorage.getItem('mm-flash'); if (kept) { sessionStorage.removeItem('mm-flash'); say(kept, false); } } catch (e) {}
  function done(message) { try { sessionStorage.setItem('mm-flash', message); } catch (e) {} location.reload(); }
  function api(method, path, body) {
    return fetch(BASE + path, { method: method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) {
      if (!r.ok || d.success === false) throw new Error(d.error || 'The server refused the change (' + r.status + ').'); return d; }); });
  }
  function busy(b, on) { if (b) { b.disabled = on; b.setAttribute('aria-busy', on ? 'true' : 'false'); } }

  var add = document.getElementById('mmAddForm');
  add.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var input = document.getElementById('mmNewName'); var name = input.value.trim();
    if (!name) { input.setAttribute('aria-invalid', 'true'); say('Give the ministry a name.', true); input.focus(); return; }
    var btn = add.querySelector('button[type=submit]'); busy(btn, true);
    api('POST', '/api/admin/ministries', { name: name, campus_id: document.getElementById('mmNewCampus').value })
      .then(function (d) {
        var id = d.ministry && (d.ministry.ministry_id || d.ministry.id);
        if (id) { try { sessionStorage.setItem('mw-flash', 'Created ' + name + '. Add its members and serving roles here.'); } catch (e) {} location.href = BASE + '/ministries/' + id + '/members'; }
        else { done('Created ' + name + '.'); }
      })
      .catch(function (err) { busy(btn, false); say(err.message, true); });
  });

  var filter = document.getElementById('mmFilter');
  var table = document.getElementById('mmTable');
  if (filter && table) {
    var rows = [].slice.call(table.querySelectorAll('tbody tr')); var none = document.getElementById('mmNoMatch');
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase(), shown = 0;
      rows.forEach(function (tr) { var hit = !q || tr.getAttribute('data-search').indexOf(q) !== -1; tr.hidden = !hit; if (hit) shown++; });
      none.hidden = shown !== 0;
    });
  }
  if (table) {
    table.addEventListener('click', function (ev) {
      var cancel = ev.target.closest('[data-cancel]');
      if (cancel) { var f = cancel.closest('form'); f.hidden = true; f.closest('tr').querySelector('[data-act="edit"]').focus(); return; }
      var btn = ev.target.closest('button[data-act]'); if (!btn) return;
      var tr = btn.closest('tr'); var id = tr.getAttribute('data-id'); var name = tr.getAttribute('data-name');
      var act = btn.getAttribute('data-act');
      if (act === 'edit') { var form = tr.querySelector('[data-edit-form]'); form.hidden = !form.hidden; if (!form.hidden) form.querySelector('input').focus(); return; }
      if (act === 'toggle') {
        var on = tr.getAttribute('data-active') !== '1'; busy(btn, true);
        api('POST', '/api/admin/ministries/' + id + '/active', { active: on })
          .then(function () { done(name + (on ? ' is active again.' : ' is now inactive.')); })
          .catch(function (err) { busy(btn, false); say(err.message, true); });
        return;
      }
      if (act === 'delete') {
        var members = parseInt(tr.getAttribute('data-members'), 10) || 0, roles = parseInt(tr.getAttribute('data-roles'), 10) || 0;
        var msg = 'Permanently delete ' + name + '?\n\n' + (members || roles
          ? 'Its ' + members + (members === 1 ? ' membership' : ' memberships') + ' and ' + roles + (roles === 1 ? ' serving role' : ' serving roles') + ' are deleted with it. To keep its history, deactivate it instead.'
          : 'It has no members or serving roles.') + '\n\nThis cannot be undone.';
        if (!window.confirm(msg)) return;
        busy(btn, true);
        api('DELETE', '/api/admin/ministries/' + id)
          .then(function () { done('Deleted ' + name + '.'); })
          .catch(function (err) { busy(btn, false); say(err.message, true); });
      }
    });
    table.addEventListener('submit', function (ev) {
      var form = ev.target.closest('[data-edit-form]'); if (!form) return;
      ev.preventDefault();
      var tr = form.closest('tr'); var input = form.querySelector('input'); var name = input.value.trim();
      if (!name) { input.setAttribute('aria-invalid', 'true'); say('A ministry needs a name.', true); input.focus(); return; }
      var btn = form.querySelector('button[type=submit]'); busy(btn, true);
      api('PUT', '/api/admin/ministries/' + tr.getAttribute('data-id'), { name: name, campus_id: form.querySelector('select').value })
        .then(function () { done('Saved ' + name + '.'); })
        .catch(function (err) { busy(btn, false); say(err.message, true); });
    });
    if (location.hash && /^#ministry-\d+$/.test(location.hash)) {
      var target = document.getElementById(location.hash.slice(1));
      if (target) { var b = target.querySelector('[data-act="edit"]'); if (b) { b.click(); } }
    }
  }
})();
</script>
<style>
  .mv-fieldset{border:1px solid var(--line,#d9e4dd);border-radius:8px;padding:14px 16px;margin:0 0 16px}
  .mv-fieldset>legend{padding:0 6px;font-size:13px;font-weight:800;color:var(--ink)}
  .mv-help{font-size:13px;color:var(--muted,#5c6b63);margin:0 0 10px;line-height:1.45}
  .mv-inline{display:flex;flex-wrap:wrap;gap:16px}
  .mv-option{display:inline-flex;align-items:center;gap:8px;min-height:44px;cursor:pointer;font-size:14px}
  .mv-option input{width:18px;height:18px;flex:0 0 auto}
  .mv-search{width:100%;max-width:340px;font:inherit;padding:10px 12px;min-height:44px;
    border:1px solid var(--line,#c7d4cd);border-radius:8px;margin-bottom:10px}
  /* A scrolling list rather than a growing page: this is a picker, and the
     wording fields below it should stay reachable. */
  .mv-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:2px 14px;
    max-height:280px;overflow-y:auto;padding:4px 2px;border:1px solid var(--line,#e3eae6);border-radius:8px}
  .mv-option.is-hidden{display:none}
  .mv-unmatched{color:#7a5400}
  .mv-field{margin:0 0 12px}
  .mv-field label{display:block;font-size:13px;font-weight:700;margin-bottom:4px}
  .mv-field input,.mv-field textarea{width:100%;font:inherit;padding:10px 12px;min-height:44px;
    border:1px solid var(--line,#c7d4cd);border-radius:8px}
  .mv-field textarea{min-height:88px}
  .mv-advanced{border:1px solid var(--line,#e3eae6);border-radius:8px;background:var(--soft,#f7faf8);margin:0 0 16px}
  .mv-advanced>summary{cursor:pointer;padding:12px 14px;min-height:44px;display:flex;align-items:center;
    font-size:13px;font-weight:700;color:var(--muted,#5c6b63)}
  .mv-advanced-body{padding:0 14px 14px}
  .mv-actions{display:flex;flex-wrap:wrap;gap:8px}
</style>
<script>
(function(){
    // The stored shape is unchanged: excluded_ministries is still a list of
    // identifiers, and #admin_excluded_ministries still carries them as a
    // comma-separated string. Only the way an administrator builds that list
    // changed, from typing IDs to ticking names.
    const hidden = document.getElementById('admin_excluded_ministries');
    const list = document.getElementById('mv_list');
    const search = document.getElementById('mv_search');
    const summary = document.getElementById('mv_summary');
    const unmatchedEl = document.getElementById('mv_unmatched');
    // Values that match no current ministry are kept rather than dropped: a
    // ministry may have been renamed, and silently un-hiding it on the public
    // site would be a surprising thing for a save to do.
    let unmatched = [];

    function checks(){ return list ? Array.from(list.querySelectorAll('.mv-check')) : []; }


    function syncToHidden(){
        if(!hidden) return;
        const picked = checks().filter(c=>c.checked).map(c=>c.value);
        hidden.value = picked.concat(unmatched).join(', ');
        if(summary){
            summary.textContent = picked.length === 0
                ? 'Every ministry is listed publicly.'
                : picked.length + (picked.length === 1 ? ' ministry is hidden' : ' ministries are hidden')
                  + ': ' + checks().filter(c=>c.checked).map(c=>c.dataset.name).join(', ');
        }
    }

    function syncFromHidden(){
        if(!hidden) return;
        const stored = (hidden.value||'').split(',').map(s=>s.trim()).filter(Boolean);
        const wanted = stored.map(v=>v.toLowerCase());
        const matched = new Set();
        checks().forEach(c=>{
            const hit = wanted.includes(c.value.toLowerCase())
                     || wanted.includes((c.dataset.name||'').toLowerCase());
            c.checked = hit;
            if(hit){ matched.add(c.value.toLowerCase()); matched.add((c.dataset.name||'').toLowerCase()); }
        });
        unmatched = stored.filter(v=>!matched.has(v.toLowerCase()));
        if(unmatchedEl){
            unmatchedEl.hidden = unmatched.length === 0;
            unmatchedEl.textContent = unmatched.length
                ? 'Also hidden, but no longer matching a ministry on this list (kept as-is): ' + unmatched.join(', ')
                : '';
        }
        syncToHidden();
    }

    list?.addEventListener('change', e=>{ if(e.target.classList.contains('mv-check')) syncToHidden(); });

    search?.addEventListener('input', ()=>{
        const q = search.value.trim().toLowerCase();
        list.querySelectorAll('.mv-option').forEach(o=>{
            o.classList.toggle('is-hidden', q !== '' && !(o.dataset.name||'').includes(q));
        });
    });

    const saveBtn = document.getElementById('admin_save_ministries');
    const previewBtn = document.getElementById('admin_preview_ministries');
    const noticeEl = document.createElement('div');
    noticeEl.style.marginTop = '8px';
    saveBtn.parentNode.appendChild(noticeEl);

    function readValues(){
        const pages = { board: !!document.getElementById('admin_page_board').checked, directory: !!document.getElementById('admin_page_directory').checked };
        const excludedMin = (document.getElementById('admin_excluded_ministries').value||'').split(',').map(s=>s.trim()).filter(Boolean);
        const excludedGroups = (document.getElementById('admin_excluded_groups').value||'').split(',').map(s=>s.trim()).filter(Boolean);
        const title = (document.getElementById('admin_title').value || '').trim();
        const subtitle = (document.getElementById('admin_subtitle').value || '').trim();
        const heroLead = (document.getElementById('admin_hero_lead').value || '').trim();
        return { pages, excluded_ministries: excludedMin, excluded_groups: excludedGroups, title, subtitle, hero_lead: heroLead };
    }

    function apiPath(p){
        const SERVER_BASE = <?= json_encode($basePath) ?> || '';
        if (SERVER_BASE && SERVER_BASE !== '') return SERVER_BASE + p;
        const segs = location.pathname.split('/');
        if (segs.length > 1 && segs[1]) return '/' + segs[1] + p;
        return p;
    }

    async function fetchWithRetry(url, opts){
        const first = await fetch(url, opts);
        if (first.status !== 404) return first;
        // try deriving a base from the pathname (e.g. /church_portal/...)
        const segs = location.pathname.split('/');
        if (segs.length > 1 && segs[1]){
            const derived = '/' + segs[1] + url.replace(/^\//, '');
            if (derived === url) return first;
            try {
                const second = await fetch(derived, opts);
                return second;
            } catch (e) {
                return first;
            }
        }
        return first;
    }

    async function loadSaved(){
        try{
            const res = await fetchWithRetry(apiPath('/api/ministries-settings'), { credentials: 'same-origin' });
            const data = await res.json().catch(()=>null);
            if(data && data.pages){ document.getElementById('admin_page_board').checked = !!data.pages.board; document.getElementById('admin_page_directory').checked = !!data.pages.directory; }
            if(data && Array.isArray(data.excluded_ministries)) document.getElementById('admin_excluded_ministries').value = data.excluded_ministries.join(', ');
            if(data && Array.isArray(data.excluded_groups)) document.getElementById('admin_excluded_groups').value = data.excluded_groups.join(', ');
            if(data && typeof data.title === 'string') document.getElementById('admin_title').value = data.title;
            if(data && typeof data.subtitle === 'string') document.getElementById('admin_subtitle').value = data.subtitle;
            if(data && typeof data.hero_lead === 'string') document.getElementById('admin_hero_lead').value = data.hero_lead;
        }catch(e){}
    }

    saveBtn?.addEventListener('click', async ()=>{
        try{
            const body = readValues();
            const res = await fetchWithRetry(apiPath('/api/admin/ministries-settings'), { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body)});
            const data = await res.json().catch(()=>({}));
            if(!res.ok){ noticeEl.textContent = data.error || ('Save failed: '+res.status); return; }
            noticeEl.textContent = 'Saved server-side.';
        }catch(e){ noticeEl.textContent = 'Save failed.'; }
    });
    previewBtn?.addEventListener('click', ()=>{ window.open(new URL(apiPath('/schedule-board'), location.origin).toString(), '_blank'); });
    // loadSaved() writes the stored identifiers into the hidden field; tick the
    // boxes to match once it has, rather than watching the field for changes.
    loadSaved().then(syncFromHidden).catch(syncFromHidden);
})();
</script>
<?php
$pageAssets = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'ministries',
    'pageTitle' => 'Manage ministries', 'pageSubtitle' => '',
    'sectionTitle' => 'Manage ministries',
    'sectionDescription' => 'Create, rename, move, deactivate or delete ministries, and choose what the church website lists. Members and serving roles are managed inside each ministry.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $manageHtml . $visibilityHtml . $pageAssets);
