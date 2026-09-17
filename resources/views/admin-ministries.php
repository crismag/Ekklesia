<?php
require_once __DIR__ . '/_admin-shell.php';
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);

$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

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

$content = '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>What the public can see</h2>'
    . '<p>Choose which ministry pages appear on the public site, and hide any ministry that should not be listed.</p></div></div>'
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
    . '<button id="admin_preview_ministries" class="button">Preview public board</button>'
    . '</div>'
    . '</div></article>';

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'ministries',
    'pageTitle' => 'Ministry list · Admin', 'pageSubtitle' => 'Public visibility',
    'sectionTitle' => 'Ministry list',
    'sectionDescription' => 'Choose which ministry pages appear on the public site. Create a team, and who is on it, under Members & leaders.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn(): string => $content);
?>
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
    previewBtn?.addEventListener('click', ()=>{ window.open(new URL(apiPath('/ministries'), location.origin).toString(), '_blank'); });
    // loadSaved() writes the stored identifiers into the hidden field; tick the
    // boxes to match once it has, rather than watching the field for changes.
    loadSaved().then(syncFromHidden).catch(syncFromHidden);
})();
</script>
<?php
require_once __DIR__ . '/_admin-shell.php';
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);

$content = '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Roles &amp; members</h2><p>Configure roles per ministry and manage who serves on each.</p></div>' . admin_section_status_badge('Beta') . '</div>'
    . '<div class="admin-card-body">'
    . '<div class="tile-grid">'
    . '<a class="tile" href="' . $base . '/ministries"><h3>Ministries</h3><p>Open a ministry workspace — members, serving grid, posted lists.</p><span class="tile-cta">Open &rsaquo;</span></a>'
    . '<a class="tile" href="' . $base . '/people"><h3>People</h3><p>Look up members. Adding a record is Member records, not this directory.</p><span class="tile-cta">Open &rsaquo;</span></a>'
    . '<a class="tile" href="' . $base . '/schedules"><h3>Serving grid</h3><p>Fill roles on an activity\'s dates.</p><span class="tile-cta">Open &rsaquo;</span></a>'
    . '</div></div></article>'

    . '';

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'ministries',
    'pageTitle' => 'Ministry list · Admin', 'pageSubtitle' => 'Public visibility.',
    'sectionTitle' => 'Ministry list',
    'sectionDescription' => 'Public ministry visibility. Create teams and assign members under Members & leaders.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
