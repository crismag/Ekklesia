<?php
/**
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var int $ministryId
 * @var array<int,array<string,mixed>> $availableMinistries
 * @var array<string,mixed> $campusSelector
 */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$ministries = is_array($availableMinistries ?? null) ? $availableMinistries : [];
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$printMode = isset($_GET['print']) && (string) $_GET['print'] === '1';
require_once __DIR__ . '/_portal-shell.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>People Portal - Ekklesia</title>
    <style>
                *{box-sizing:border-box}
        body{margin:0;min-height:100vh;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
        a{color:inherit}
        .button,button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 13px;border-radius:8px;background:var(--deep);color:#fff;font:inherit;font-weight:900;text-decoration:none;border:0;cursor:pointer}
        .button.secondary{background:#fff;color:var(--deep);border:1px solid var(--line)}
        /* People & Records: the directory sits on the kit (ek-page, tabs, page
           header, cards). The table, cards and filters below are the working
           directory; only their surfaces follow the kit's tokens. */
        .panel{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-lg,12px);box-shadow:0 1px 2px rgba(16,32,24,.04),0 8px 24px rgba(16,32,24,.05);overflow:hidden}
        .summary{padding:14px;display:grid;grid-template-columns:repeat(2,1fr);gap:10px;align-self:end}
        .metric{border-radius:8px;padding:14px;color:#fff;min-height:86px;display:grid;align-content:space-between}
        .metric strong{font-size:28px;line-height:1}
        .metric span{font-size:12px;font-weight:800;color:rgba(255,255,255,.84)}
        .m1{background:var(--teal)}.m2{background:var(--blue)}.m3{background:var(--gold)}.m4{background:#6f2b47}
        /* Was a fixed 8-column grid that could not fit its own content: the Refresh
           button overflowed the viewport by 21px at 1440 and 101px at 1280
           (audit H3). auto-fit lets the row wrap instead of overflowing. */
        .toolbar{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:0;align-items:end}
        .filters-wrap{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-lg,12px);padding:var(--sp-4,16px) var(--sp-5,20px)}
        .toolbar>*{min-width:0}
        .toolbar select,.toolbar input{width:100%}
        label{display:block;margin-bottom:6px;color:var(--ink);font-size:13px;font-weight:650}
        select,input{width:100%;min-height:40px;border:1px solid var(--line);border-radius:var(--radius,8px);padding:8px 12px;font:inherit;background:var(--paper);color:var(--ink)}
        select:focus,input:focus{outline:none;border-color:var(--teal);box-shadow:var(--focus-shadow,0 0 0 3px rgba(17,123,109,.3))}
        .toolbar button{align-self:end}
        .context-note{margin:0;color:var(--muted);font-size:13px}
        /* The dark band was a fixed 250px gradient on <body>, so the intro paragraph
           was clipped mid-line where the band ended (audit C4 seam class). The band
           now belongs to .hero and always matches its real height. */
        .colvis-wrap{display:grid;gap:6px}
        /* The label wraps rather than running off the page. Held to one line it was
           202px wide in a track that could be narrower, and since .toolbar>* allows
           its wrapper to shrink, the text simply spilled past the right edge and
           gave the whole page a nine-pixel horizontal scroll at 1280. The box
           still can't shrink below the 44px touch target. */
        .colvis{display:inline-flex;align-items:center;gap:8px;min-height:44px;font-size:13px;font-weight:500;letter-spacing:0;text-transform:none;color:var(--ink);cursor:pointer;overflow-wrap:break-word;min-width:0}
        .colvis input{width:24px;height:24px;flex:0 0 auto}
        /* Roles, Assignments, and Campus are optional. They stay in the markup
           so the toggles can restore them; the choice persists per browser. */
        .directory-table:not(.show-extra) .col-roles,
        .directory-table:not(.show-extra) .col-assignments,
        .directory-table:not(.show-campus) .col-campus{display:none}
        td.is-hidden-col,.is-hidden-col{display:none}
        .directory-table th.col-last{width:16%}
        .directory-table th.col-first{width:14%}
        .directory-table th.col-campus{width:12%}
        .directory-table th.col-ministries{width:auto}
        .directory-table th.col-member,
        .directory-table th.col-birthday,
        .directory-table th.col-status{width:1%;white-space:nowrap}
        .directory-table{width:100%;table-layout:auto;border-collapse:collapse;background:var(--paper)}
        .directory-table th,.directory-table td{text-align:left;padding:6px 12px;border-bottom:1px solid var(--line,#edf2ef);vertical-align:middle;line-height:1.4}
        .directory-table th{background:var(--soft,#eef4f0);color:var(--muted);font-size:12px;font-weight:700;white-space:nowrap}
        .sort-button{display:inline-flex;align-items:center;gap:5px;min-height:28px;padding:0;border:0;background:transparent;color:inherit;font:inherit;font-weight:700;cursor:pointer}
        .sort-button:focus-visible,.person-name:focus-visible,.pager button:focus-visible{outline:2px solid var(--teal);outline-offset:2px}
        .sort-button::after{content:"↕";font-size:12px;color:#9aa9a1}
        .sort-button.active::after{content:"↑";color:var(--teal)}
        .sort-button.active.desc::after{content:"↓"}
        .directory-table tr:last-child td{border-bottom:0}
        .name-cell{display:flex;align-items:center;gap:8px;min-width:150px}
        .name-inline{display:flex;align-items:baseline;gap:8px;min-width:0;flex-wrap:wrap}
        .person-sub{color:var(--muted);font-size:12px;white-space:nowrap}
        .table-wrap{overflow:auto}
        .pager{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-top:1px solid var(--line);background:var(--paper);flex-wrap:wrap}
        .pager-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .pager button{min-height:32px;padding:6px 10px}
        .pager button:disabled{opacity:.45;cursor:not-allowed}
        .page-size{display:flex;align-items:center;gap:6px;color:var(--muted);font-size:12px;font-weight:800}
        .page-size select{width:auto;min-width:78px;padding:6px 8px}
        .nowrap{white-space:nowrap}
        .cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        .person-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-lg,12px);box-shadow:0 1px 2px rgba(16,32,24,.04),0 8px 24px rgba(16,32,24,.05);padding:14px;display:grid;gap:10px;min-height:220px}
        .person-head{display:flex;gap:10px;align-items:flex-start;justify-content:space-between}
        .avatar{width:28px;height:28px;border-radius:6px;background:var(--soft);color:var(--deep);display:grid;place-items:center;font-size:11px;font-weight:700;flex:0 0 auto}
        .name-block{min-width:0;flex:1}
        .person-name{min-height:24px;display:inline-flex;align-items:center;font-size:14px;font-weight:500;color:var(--teal-ink,#117b6d);text-decoration:none;line-height:1.3}
        .muted{color:var(--muted)}
        .small{font-size:12px}
        .tags{display:flex;gap:6px;flex-wrap:wrap}
        .tag{display:inline-flex;border-radius:999px;padding:2px 8px;background:var(--soft);color:var(--deep);font-size:12px;font-weight:650}
        .tag.public{background:#e8eef6;color:#254d74}
        .tag.inactive{background:#f8e8e8;color:#8a2a2a}
        /* The overflow chip on cards. Outlined rather than filled so it reads
           as a count of what is not shown, not as another ministry. */
        .tag.more{background:transparent;color:var(--deep);border:1px dashed var(--line, currentColor);font-weight:700}
        .info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
        .info{padding:9px;border-radius:8px;background:var(--soft,#fbfdfc);border:1px solid var(--line,#edf2ef)}
        .info strong{display:block;font-size:18px;line-height:1}
        .actions-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:auto}
        .text-link{color:var(--teal);font-weight:900;text-decoration:none}
        .empty{padding:22px;color:var(--muted)}
        @media(max-width:1120px){.toolbar{grid-template-columns:repeat(3,1fr)}.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:760px){.summary,.toolbar,.cards{grid-template-columns:1fr}}

        /* Below 1024px a 7-column directory is not a table any more. Each row
           becomes a card with "Label: value" pairs, driven by the data-label
           attributes on the cells. Previously columns 4 and 5 were simply
           hidden, which removed information rather than presenting it.
           No data, sorting or pagination behaviour changes. */
        /* Filters ahead of content is the desktop-first inversion the audit
           flagged (H2): on a 390px screen the intro plus seven stacked selects
           filled the entire first screen and zero people were visible. */
        .filters-summary{display:none}
        .display-note{margin:4px 0 0;font-size:11.5px;line-height:1.4;color:var(--muted,#5c6b63);max-width:24ch}
        .display-note[hidden]{display:none}
        @media(max-width:1023px){
          .filters-wrap{padding:var(--sp-3,12px)}
          .filters-summary{display:flex;align-items:center;gap:8px;min-height:48px;padding:0 14px;
            background:var(--soft,#eef4f0);border:1px solid var(--line,#d9e4dd);
            border-radius:var(--radius,8px);font-size:14px;font-weight:700;color:var(--ink,#17211b);cursor:pointer}
          .filters-wrap[open] .filters-summary{margin-bottom:10px}
          .toolbar{margin:0}
          .toolbar select,.toolbar input{min-height:48px;font-size:16px}
          .directory-table thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
          .directory-table,.directory-table tbody,.directory-table tr,.directory-table td{display:block;width:auto}
          .directory-table tr{margin:0 0 var(--sp-2,8px);padding:var(--sp-3,12px);background:var(--paper,#fff);
            border:1px solid var(--line,#d9e4dd);border-radius:var(--radius,8px)}
          .directory-table td{border:0;padding:4px 0;display:flex;gap:var(--sp-3,12px);align-items:flex-start}
          .directory-table td.is-hidden-col{display:none}
          .directory-table td::before{content:attr(data-label);flex:0 0 108px;font-size:12px;font-weight:800;
            letter-spacing:.04em;text-transform:uppercase;color:var(--muted,#627169);padding-top:2px}
          /* Last name is the card title — no label, full width, larger. */
          .directory-table td[data-label="Last name"]{display:block;margin-bottom:6px}
          .directory-table td[data-label="Last name"]::before{display:none}
          .directory-table td[data-label="Last name"] .person-name{font-size:16px}
          .directory-table:not(.show-campus) td.col-campus,
          .directory-table:not(.show-extra) td.col-roles,
          .directory-table:not(.show-extra) td.col-assignments{display:none}
          .directory-table td:empty,.directory-table td.is-blank{display:none}
          .directory-table tr:last-child td{border-bottom:0}
          .name-cell{min-width:0}
          .person-name{min-height:44px;display:inline-flex;align-items:center}
        }
        @media print{body{background:#fff}.shell{width:100%;padding:0}.topbar,.portal-titleblock,.ek-tabs,.ek-page-header,.filters-wrap,.context-note,.portal-footer{display:none!important}.panel,.person-card{box-shadow:none}.cards{grid-template-columns:repeat(2,1fr)}.person-card{break-inside:avoid}}
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>" data-ministry-id="<?= (int) $ministryId ?>">
    <?= portal_header(
        $basePath,
        'People Portal',
        'Campus-aware people browsing with public-safe profile visibility.',
        $campuses,
        $campusSelector['defaultCampusId'] ?? null,
        $actor,
        [
            ['href' => $base . '/', 'label' => 'Dashboard', 'icon' => 'dashboard'],
            ['href' => $base . '/ministries', 'label' => 'Ministries', 'icon' => 'ministry'],
            ['href' => $base . '/calendar', 'label' => 'Calendar', 'icon' => 'calendar'],
            ['href' => $base . '/events', 'label' => 'Events', 'icon' => 'events'],
            ['href' => $base . '/availability', 'label' => 'Availability', 'icon' => 'availability'],
        ],
        [],
        [],
        'Sign in',
        $base . '/login'
    ) ?>

<main id="portal-main" class="ek-page" tabindex="-1">
    <?= ek_workspace_tabs($basePath, 'people', 'directory', $actor) ?>
    <?= ek_page_header(
        'Directory',
        'Look up who is on this campus — by ministry, family, birthday month, or search. Visitors who are not signed in see a first name and last initial; contact details and addresses stay private.'
    ) ?>
    <?php if ($actor !== null && !empty($actor['isPortalWideAdmin'])): ?>
    <p class="context-note">Adding or editing a person happens in <a class="text-link" href="<?= $base ?>/admin/people">Member records</a>, not this page.</p>
    <?php endif; ?>

    <details class="filters-wrap" id="peopleFilters" open><summary class="filters-summary">Filters &amp; search</summary>
    <section class="toolbar" aria-label="People filters">
        <div>
            <label for="ministrySelect">Ministry</label>
            <select id="ministrySelect">
                <option value="">All ministries</option>
                <?php foreach ($ministries as $ministry): ?>
                    <option value="<?= (int) $ministry['ministryId'] ?>" <?= (int) $ministry['ministryId'] === (int) $ministryId ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $ministry['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="monthSelect">Birthday</label>
            <select id="monthSelect">
                <option value="">Any month</option>
                <option value="current">Current month</option>
                <option value="1">January</option><option value="2">February</option><option value="3">March</option><option value="4">April</option>
                <option value="5">May</option><option value="6">June</option><option value="7">July</option><option value="8">August</option>
                <option value="9">September</option><option value="10">October</option><option value="11">November</option><option value="12">December</option>
            </select>
        </div>
        <div>
            <label for="statusSelect">Status</label>
            <select id="statusSelect">
                <option value="">Active and inactive</option>
                <option value="active">Active members</option>
                <option value="inactive">Inactive members</option>
            </select>
        </div>
        <div>
            <label for="activitySelect">Activity</label>
            <select id="activitySelect">
                <option value="">Any activity</option>
                <option value="assigned">Has assignments</option>
                <option value="unassigned">No assignments</option>
                <option value="ministry">Has ministry</option>
                <option value="none">No ministry</option>
            </select>
        </div>
        <div>
            <label for="memberTypeSelect">Member Type</label>
            <select id="memberTypeSelect"><option value="">All member types</option></select>
        </div>
        <div>
            <label for="displayMode">Display</label>
            <select id="displayMode">
                <option value="table">Table list</option>
                <option value="cards">Cards</option>
            </select>
            <p class="display-note" id="displayNote" hidden>Showing cards — the table
                is too wide for this screen. Your choice is remembered for larger ones.</p>
        </div>
        <div class="colvis-wrap">
            <label for="extraCols">Columns</label>
            <label class="colvis"><input type="checkbox" id="extraCols"> Show roles &amp; assignments</label>
        </div>
        <div class="colvis-wrap">
            <label for="campusCol">Campus</label>
            <label class="colvis"><input type="checkbox" id="campusCol" checked> Show campus</label>
        </div>
        <div>
            <label for="searchInput">People search</label>
            <input id="searchInput" type="search" placeholder="Search name, ministry, family, role">
        </div>
        <button type="button" id="refreshButton">Refresh</button>
    </section>
    </details>
    <p id="contextNote" class="context-note">Loading people...</p>

    <section id="peopleDirectory"><div class="panel empty">Loading people...</div></section>
    </main>
<?= portal_footer('Ekklesia', 'People portal') ?>
</div>
<script>
(function(){
    const shell=document.querySelector('.shell');
    const basePath=shell.dataset.base||'';
    const campusSelect=document.getElementById('campusSelect');
    const ministrySelect=document.getElementById('ministrySelect');
    const monthSelect=document.getElementById('monthSelect');
    const statusSelect=document.getElementById('statusSelect');
    const activitySelect=document.getElementById('activitySelect');
    const memberTypeSelect=document.getElementById('memberTypeSelect');
    const displayMode=document.getElementById('displayMode');
    const searchInput=document.getElementById('searchInput');
    const peopleDirectory=document.getElementById('peopleDirectory');
    const contextNote=document.getElementById('contextNote');
    let people=[];
    let canViewPrivileged=false;
    let sortKey='lastName';
    let sortDirection='asc';
    let page=1;
    let pageSize=20;
    // Public page: load server-side people settings for preview (fallback to localStorage)
    const DEFAULT_COLS = { name:true, ministries:true, roles:true, memberType:true, assignments:true, birthday:true, status:true };
    let cols = Object.assign({}, DEFAULT_COLS);
    async function loadServerPrefs(){
        // try server first
        try{
            const res = await fetch(withCampus(`${basePath}/api/people-settings`), { credentials: 'same-origin' });
            if(res.ok){
                const data = await res.json().catch(()=>null);
                if(data && data.columns){ cols = Object.assign({}, DEFAULT_COLS, data.columns); }
                if(data && data.header){ try{ const titleEl = document.querySelector('.portal-titleblock h1'); const subEl = document.querySelector('.portal-titleblock p'); if(data.header.title && titleEl) titleEl.textContent = data.header.title; if(data.header.subtitle && subEl) subEl.textContent = data.header.subtitle; }catch(e){}
                }
                return;
            }
        }catch(e){}
        // fallback: localStorage
        try{const raw=localStorage.getItem('people_columns_v1'); if(raw){const parsed=JSON.parse(raw); cols=Object.assign({}, DEFAULT_COLS, parsed);} }catch(e){}
        try{ const hdrRaw = localStorage.getItem('people_header_v1'); if(hdrRaw){ const hdr = JSON.parse(hdrRaw); if(hdr.title && document.querySelector('.portal-titleblock h1')) document.querySelector('.portal-titleblock h1').textContent = hdr.title; if(hdr.subtitle && document.querySelector('.portal-titleblock p')) document.querySelector('.portal-titleblock p').textContent = hdr.subtitle; } }catch(e){}
    }

    function escapeHtml(value){return String(value??'').replace(/[&<>"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));}
    function selectedCampusId(){return campusSelect?.value||'';}
    function withCampus(url,name='campus_id'){const id=selectedCampusId();return id?url+(url.includes('?')?'&':'?')+`${name}=${encodeURIComponent(id)}`:url;}
    function formatDate(value){if(!value)return'Never';const d=new Date(value);return Number.isNaN(d.getTime())?'Unknown':d.toLocaleDateString([],{month:'short',day:'numeric',year:'numeric'});}
    function birthday(person){return person.birthMonth&&person.birthDay?new Date(2026,Number(person.birthMonth)-1,Number(person.birthDay)).toLocaleDateString([],{month:'short',day:'numeric'}):'Not listed';}
    function firstNameOf(person){return String(person.firstName||'').trim();}
    function lastNameOf(person){return String(person.lastName||person.lastInitial||'').trim();}
    function initials(person){
        const first=firstNameOf(person);
        const last=lastNameOf(person);
        const letters=((first[0]||'')+(last[0]||'')).toUpperCase();
        if(letters) return letters;
        const name=String(person.displayName||'').trim();
        return name.split(/\s+/).map(part=>part[0]||'').join('').slice(0,2).toUpperCase()||'?';
    }
    function normalizeText(value){return String(value??'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9]+/g,' ').trim();}
    function selectedCampusName(){return Array.from(campusSelect?.options||[]).find(option=>option.value===selectedCampusId())?.textContent?.trim()||'';}
    function campusLabel(person){return person.primaryCampusName || selectedCampusName() || (person.primaryCampusId ? `Campus ${person.primaryCampusId}` : '');}
    function searchableText(person){
        return normalizeText([
            person.displayName,
            person.firstName,
            person.lastName,
            person.lastInitial,
            person.familyName,
            person.memberTypeName,
            person.ministries,
            person.rolesServed,
            campusLabel(person),
            person.isActive ? 'active' : 'inactive',
            birthday(person),
            person.assignmentCount,
        ].join(' '));
    }
    // Subsequence matching (characters in order but NOT adjacent) was far too
    // loose for a directory: "cris" matched C-r-eat-i-ve-s and C-la-ris-se,
    // so a four-letter query returned mostly unrelated people. Typo tolerance
    // is now bounded — the matched characters must sit in a tight window
    // (at most two extra characters), which still forgives a dropped letter
    // without matching across a whole word.
    function isNearMatch(needle,word){
        if(needle.length<4) return false;              // too short to fuzz safely
        if(word.length>needle.length+2) return false;  // must be a similar-length word
        let i=0,skips=0;
        for(const ch of word){
            if(ch===needle[i]) i++;
            else if(++skips>2) return false;
            if(i===needle.length) return true;
        }
        return i===needle.length;
    }
    function tokenMatches(token,searchable){
        if(searchable.includes(token)) return true;
        return searchable.split(/\s+/).some(word=>word.startsWith(token)||word.includes(token)||isNearMatch(token,word));
    }

    function updateMemberTypeOptions(){
        const current=memberTypeSelect.value;
        const memberTypes=[...new Set(people.map(person=>String(person.memberTypeName||'').trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,undefined,{sensitivity:'base'}));
        memberTypeSelect.innerHTML='<option value="">All member types</option>'+memberTypes.map(name=>`<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('');
        if(memberTypes.includes(current)) memberTypeSelect.value=current;
    }

    // Column preference persistence removed from public view (admin-only)

    function filteredPeople(){
        const ministry=ministrySelect.value;
        const monthRaw=monthSelect.value;
        const month=monthRaw==='current'?String(new Date().getMonth()+1):monthRaw;
        const status=statusSelect.value;
        const activity=activitySelect.value;
        const memberType=memberTypeSelect.value;
        const queryTokens=normalizeText(searchInput.value).split(/\s+/).filter(Boolean);
        return people.filter(person=>{
            const ministries=String(person.ministries||'');
            const matchesMinistry=!ministry || ministries.split(',').map(v=>v.trim()).some(label=>label && ministrySelect.options[ministrySelect.selectedIndex]?.textContent.trim().startsWith(label));
            const matchesMonth=!month || String(person.birthMonth||'')===month;
            const matchesStatus=!status || (status==='active'?person.isActive:!person.isActive);
            const matchesActivity=!activity
                || (activity==='assigned' && Number(person.assignmentCount||0)>0)
                || (activity==='unassigned' && Number(person.assignmentCount||0)===0)
                || (activity==='ministry' && Number(person.ministryCount||0)>0)
                || (activity==='none' && Number(person.ministryCount||0)===0);
            const matchesMemberType=!memberType || String(person.memberTypeName||'')===memberType;
            const searchable=searchableText(person);
            const matchesQuery=queryTokens.length===0 || queryTokens.every(token=>tokenMatches(token,searchable));
            return matchesMinistry&&matchesMonth&&matchesStatus&&matchesActivity&&matchesMemberType&&matchesQuery;
        }).sort(comparePeople);
    }

    function sortValue(person,key){
        if(key==='lastName') return lastNameOf(person);
        if(key==='firstName') return firstNameOf(person);
        if(key==='campus') return campusLabel(person);
        if(key==='name') return String(person.displayName||'');
        if(key==='ministries') return String(person.ministries||'');
        if(key==='roles') return String(person.rolesServed||'');
        if(key==='memberType') return String(person.memberTypeName||'');
        if(key==='assignments') return Number(person.assignmentCount||0);
        if(key==='birthday') return Number(person.birthMonth||0)*100+Number(person.birthDay||0);
        if(key==='status') return person.isActive?1:0;
        return String(person.displayName||'');
    }

    function comparePeople(a,b){
        const av=sortValue(a,sortKey);
        const bv=sortValue(b,sortKey);
        let result=0;
        if(typeof av==='number'&&typeof bv==='number') result=av-bv;
        else result=String(av).localeCompare(String(bv),undefined,{sensitivity:'base'});
        if(result===0) result=lastNameOf(a).localeCompare(lastNameOf(b),undefined,{sensitivity:'base'});
        if(result===0) result=firstNameOf(a).localeCompare(firstNameOf(b),undefined,{sensitivity:'base'});
        if(result===0) result=String(a.displayName||'').localeCompare(String(b.displayName||''),undefined,{sensitivity:'base'});
        return sortDirection==='desc'?-result:result;
    }

    function setSort(key){
        if(sortKey===key){
            sortDirection=sortDirection==='asc'?'desc':'asc';
        }else{
            sortKey=key;
            sortDirection='asc';
        }
        page=1;
        render();
    }

    function resetPageAndRender(){
        page=1;
        render();
    }

    function setText(id, value){const el=document.getElementById(id); if(el) el.textContent = String(value);}

    function render(){
        const rows=filteredPeople();
        setText('memberCount', rows.length);
        setText('ministryCount', rows.filter(p=>Number(p.ministryCount||0)>0).length);
        setText('servedCount', rows.filter(p=>Number(p.assignmentCount||0)>0).length);
        setText('privacyLabel', canViewPrivileged?'Privileged':'Public');
        contextNote.textContent=`${rows.length} people shown from ${selectedCampusName()||'all campuses'}. ${canViewPrivileged?'Contact and address may be visible in details.':'Public view masks last names and hides contact/address.'}`;
        if(rows.length===0){peopleDirectory.className='';peopleDirectory.innerHTML='<div class="panel empty">No people match the selected filters.</div>';return;}
        if(effectiveDisplay()==='cards'){
            renderCards(rows);
            return;
        }
        renderTable(rows);
    }

    function renderTable(rows){
        const totalPages = Math.max(1, Math.ceil(rows.length/pageSize));
        if(page>totalPages) page = totalPages;
        const start = (page-1)*pageSize;
        const pageRows = rows.slice(start, start+pageSize);
        const sortClass = (key) => `sort-button ${sortKey===key ? 'active '+sortDirection : ''}`.trim();
        peopleDirectory.className = 'panel table-wrap';
        peopleDirectory.innerHTML = `<table class="directory-table">
            <thead><tr>
                <th class="col-last"><button class="${sortClass('lastName')}" type="button" data-sort="lastName">Last Name</button></th>
                <th class="col-first"><button class="${sortClass('firstName')}" type="button" data-sort="firstName">First Name</button></th>
                <th class="col-campus"><button class="${sortClass('campus')}" type="button" data-sort="campus">Campus</button></th>
                <th class="col-ministries"><button class="${sortClass('ministries')}" type="button" data-sort="ministries">Ministries</button></th>
                <th class="col-roles"><button class="${sortClass('roles')}" type="button" data-sort="roles">Roles</button></th>
                <th class="col-member"><button class="${sortClass('memberType')}" type="button" data-sort="memberType">Member Type</button></th>
                <th class="col-assignments"><button class="${sortClass('assignments')}" type="button" data-sort="assignments">Assignments</button></th>
                <th class="col-birthday"><button class="${sortClass('birthday')}" type="button" data-sort="birthday">Birthday</button></th>
                <th class="col-status"><button class="${sortClass('status')}" type="button" data-sort="status">Status</button></th>
            </tr></thead>
            <tbody>${pageRows.map(person=>{
                const ministries = String(person.ministries||'').trim();
                const roles = String(person.rolesServed||'').trim();
                const links = Array.isArray(person.publicLinks) ? person.publicLinks : [];
                const lastLabel = lastNameOf(person) || (person.displayName||`Person #${person.personId}`);
                const firstLabel = firstNameOf(person);
                const campus = campusLabel(person);
                const profileHref = withCampus(`${basePath}/people/${encodeURIComponent(person.personId)}`);
                return `<tr>
                    <td class="col-last" data-label="Last name"><div class="name-cell"><span class="avatar">${escapeHtml(initials(person))}</span><div class="name-inline"><a class="person-name" href="${profileHref}">${escapeHtml(lastLabel)}</a></div></div></td>
                    <td class="col-first" data-label="First name">${firstLabel ? `<a class="person-name" href="${profileHref}">${escapeHtml(firstLabel)}</a>` : '<span class="muted small">—</span>'}</td>
                    <td class="col-campus" data-label="Campus">${escapeHtml(campus||'No campus set')}</td>
                    <td class="col-ministries ${(ministries||links.length)?'':'is-blank'}" data-label="Ministries">${ministryTags(ministries, 0)} ${links.map(link=>`<a class="tag public" href="${escapeHtml(link.url)}" target="_blank" rel="noopener">${escapeHtml(link.label||'Link')}</a>`).join(' ')}</td>
                    <td class="col-roles ${roles?'':'is-blank'}" data-label="Roles">${escapeHtml(roles||'')}</td>
                    <td class="col-member" data-label="Member type">${person.memberTypeName ? `<span class="tag">${escapeHtml(person.memberTypeName)}</span>` : '<span class="muted small">No member type</span>'}</td>
                    <td class="col-assignments nowrap" data-label="Assignments">${escapeHtml(person.assignmentCount||0)} · ${escapeHtml(formatDate(person.lastServedAt))}</td>
                    <td class="col-birthday nowrap" data-label="Birthday">${escapeHtml(birthday(person))}</td>
                    <td class="col-status" data-label="Status"><span class="tag ${person.isActive ? '' : 'inactive'}">${person.isActive ? 'Active' : 'Inactive'}</span></td>
                </tr>`;
            }).join('')}</tbody>
        </table>
        <div class="pager">
            <div class="muted small">Showing ${escapeHtml(start+1)}-${escapeHtml(Math.min(start+pageSize,rows.length))} of ${escapeHtml(rows.length)}</div>
            <div class="pager-controls">
                <label class="page-size">Rows <select id="pageSizeSelect"><option value="20"${pageSize===20?' selected':''}>20</option><option value="50"${pageSize===50?' selected':''}>50</option><option value="100"${pageSize===100?' selected':''}>100</option></select></label>
                <button type="button" id="prevPage" ${page<=1?'disabled':''}>Previous</button>
                <span class="muted small">Page ${escapeHtml(page)} of ${escapeHtml(totalPages)}</span>
                <button type="button" id="nextPage" ${page>=totalPages?'disabled':''}>Next</button>
            </div>
        </div>`;
        peopleDirectory.querySelectorAll('[data-sort]').forEach(button=>button.addEventListener('click',()=>setSort(button.dataset.sort||'lastName')));
        peopleDirectory.querySelector('#prevPage')?.addEventListener('click',()=>{if(page>1){page--;render();}});
        peopleDirectory.querySelector('#nextPage')?.addEventListener('click',()=>{if(page<totalPages){page++;render();}});
        peopleDirectory.querySelector('#pageSizeSelect')?.addEventListener('change',(event)=>{pageSize=Number(event.target.value||20);page=1;render();});
    }

    /**
     * Ministry chips for one person.
     *
     * Both views used to cut the list at three and say nothing, so somebody
     * serving in four ministries appeared to serve in three — the profile
     * disagreed with the directory and the directory looked wrong. Fourteen
     * people were affected.
     *
     * The table shows all of them: it is the view people read against a
     * roster, and a column that quietly drops entries is worse than a wide
     * one. Cards stay compact and carry a chip counting the rest, which names
     * them rather than merely hinting that more exist.
     */
    function ministryTags(ministries, limit){
        const labels = String(ministries||'').split(',').map(part=>part.trim()).filter(Boolean);
        if(!labels.length) return '';
        if(!limit || labels.length <= limit){
            return labels.map(label=>`<span class="tag">${escapeHtml(label)}</span>`).join(' ');
        }
        const hidden = labels.slice(limit);
        const rest = hidden.join(', ');
        return labels.slice(0, limit).map(label=>`<span class="tag">${escapeHtml(label)}</span>`).join(' ')
            + ` <span class="tag more" title="${escapeHtml(rest)}" aria-label="${escapeHtml(hidden.length + ' more: ' + rest)}">+${hidden.length}</span>`;
    }

    function renderCards(rows){
        peopleDirectory.className='cards';
        peopleDirectory.innerHTML = rows.map(person=>{
            const ministries = String(person.ministries||'').trim();
            const roles = String(person.rolesServed||'').trim();
            const links = Array.isArray(person.publicLinks) ? person.publicLinks : [];
            const lastLabel = lastNameOf(person);
            const firstLabel = firstNameOf(person);
            const heading = lastLabel && firstLabel ? `${lastLabel}, ${firstLabel}` : (lastLabel || firstLabel || person.displayName || `Person #${person.personId}`);
            return `<article class="person-card">
                <div class="person-head">
                    <div class="avatar">${escapeHtml(initials(person))}</div>
                    <div class="name-block">
                        <a class="person-name" href="${withCampus(`${basePath}/people/${encodeURIComponent(person.personId)}`)}">${escapeHtml(heading)}</a>
                        <div class="muted small campus-line">${escapeHtml(campusLabel(person)||'No campus set')}</div>
                        <div class="muted small">${escapeHtml(person.familyName||'No family listed')}</div>
                    </div>
                    <span class="tag ${person.isActive ? '' : 'inactive'}">${person.isActive ? 'Active' : 'Inactive'}</span>
                </div>
                <div class="tags">
                    ${person.memberTypeName ? `<span class="tag public">${escapeHtml(person.memberTypeName)}</span>` : ''}
                    ${ministryTags(ministries, 3)}
                    ${links.map(link=>`<a class="tag public" href="${escapeHtml(link.url)}" target="_blank" rel="noopener">${escapeHtml(link.label||'Link')}</a>`).join('')}
                </div>
                <div class="info-grid">
                    <div class="info"><strong>${escapeHtml(person.assignmentCount||0)}</strong><span class="muted small">assignments</span></div>
                    <div class="info"><strong>${escapeHtml(birthday(person))}</strong><span class="muted small">birthday</span></div>
                </div>
                <div class="muted small">${escapeHtml(roles||'')}${roles ? ' · ' : ''}Last served ${escapeHtml(formatDate(person.lastServedAt))}</div>
            </article>`;
        }).join('');
    }

    async function loadDirectory(){
        peopleDirectory.className='';
        peopleDirectory.innerHTML='<div class="panel empty">Loading people...</div>';
        try{
            const res=await fetch(withCampus(`${basePath}/api/public/people-directory`),{credentials:'same-origin'});
            const data=await res.json().catch(()=>({}));
            if(!res.ok) throw new Error(data.error||`Request failed ${res.status}`);
            people=(Array.isArray(data.people)?data.people:[]).sort(comparePeople);
            canViewPrivileged=Boolean(data.canViewPrivileged);
            updateMemberTypeOptions();
            page=1;
            render();
        }catch(error){
            people=[];
            canViewPrivileged=false;
            updateMemberTypeOptions();
            setText('memberCount', '0');
            setText('ministryCount', '0');
            setText('servedCount', '0');
            setText('privacyLabel', 'Public');
            contextNote.textContent='Directory could not be loaded.';
            peopleDirectory.className='';
            peopleDirectory.innerHTML=`<div class="panel empty">${escapeHtml(error.message||'Directory could not be loaded.')}</div>`;
        }
    }

    campusSelect?.addEventListener('change',loadDirectory);
    ministrySelect.addEventListener('change',resetPageAndRender);
    monthSelect.addEventListener('change',resetPageAndRender);
    statusSelect.addEventListener('change',resetPageAndRender);
    activitySelect.addEventListener('change',resetPageAndRender);
    memberTypeSelect.addEventListener('change',resetPageAndRender);
    /* Preferred view, and what is actually shown.
     *
     * These are two different things. A directory table is thirteen columns
     * wide; on a phone it is a horizontal scroll with the useful part off the
     * side. Someone who picked Table at their desk on Monday should not be
     * handed that on Sunday morning — but their choice must survive, because
     * they will be back at the desk.
     *
     * So the preference is stored and never overwritten by the override. The
     * narrow layout presents cards instead, says so, and steps aside the moment
     * the reader chooses something while they are actually on that screen. */
    var DISPLAY_KEY='people_display_v1';
    var narrowMq=window.matchMedia('(max-width:760px)');
    var chosenHere=false;

    function preferredDisplay(){
        try{ return localStorage.getItem(DISPLAY_KEY) || 'table'; }catch(e){ return 'table'; }
    }
    function overriding(){
        return narrowMq.matches && !chosenHere && preferredDisplay()==='table';
    }
    function effectiveDisplay(){
        return overriding() ? 'cards' : displayMode.value;
    }
    function syncDisplayNote(){
        var note=document.getElementById('displayNote');
        if(note){
            note.hidden = !overriding();
        }
        // The control keeps showing the stored preference, because that is what
        // it controls. Marking it rather than silently disagreeing.
        if(overriding()){ displayMode.setAttribute('aria-describedby','displayNote'); }
        else { displayMode.removeAttribute('aria-describedby'); }
    }

    try{ displayMode.value = preferredDisplay(); }catch(e){}
    syncDisplayNote();

    displayMode.addEventListener('change',function(){
        chosenHere = narrowMq.matches;
        try{ localStorage.setItem(DISPLAY_KEY, displayMode.value); }catch(e){}
        syncDisplayNote();
        resetPageAndRender();
    });
    narrowMq.addEventListener
        ? narrowMq.addEventListener('change',function(){ chosenHere=false; syncDisplayNote(); resetPageAndRender(); })
        : narrowMq.addListener(function(){ chosenHere=false; syncDisplayNote(); resetPageAndRender(); });
    searchInput.addEventListener('input',resetPageAndRender);
    document.getElementById('refreshButton').addEventListener('click',loadDirectory);
    // Load server prefs (if any), then load the directory
    loadServerPrefs().then(()=>loadDirectory());
    if(<?= $printMode ? 'true' : 'false' ?>) setTimeout(()=>window.print(),500);
})();
</script>
<script>
(function(){
  // Filters expanded on desktop, collapsed on small screens so the directory is
  // the first thing on the page. Presentation only — no filter state changes.
  var d=document.getElementById('peopleFilters'); if(!d) return;
  var mq=window.matchMedia('(max-width:1023px)');
  function apply(){ d.open = !mq.matches; }
  apply();
  mq.addEventListener ? mq.addEventListener('change',apply) : mq.addListener(apply);
})();
</script>
<script>
(function(){
  // Column visibility: Roles and Assignments start hidden so the directory
  // scans faster; Campus starts shown. Controls restore/hide and persist.
  var EXTRA_KEY='church_portal_people_extra_cols_v1';
  var CAMPUS_KEY='church_portal_people_campus_col_v1';
  var extraBox=document.getElementById('extraCols');
  var campusBox=document.getElementById('campusCol');
  function apply(){
    var extraOn=!!(extraBox&&extraBox.checked);
    var campusOn=campusBox?campusBox.checked:true;
    document.querySelectorAll('.directory-table').forEach(function(t){
      t.classList.toggle('show-extra',extraOn);
      t.classList.toggle('show-campus',campusOn);
    });
    document.querySelectorAll('td[data-label="Roles"],td[data-label="Assignments"]').forEach(function(td){
      td.classList.toggle('is-hidden-col',!extraOn);
    });
    document.querySelectorAll('td[data-label="Campus"],.campus-line').forEach(function(el){
      el.classList.toggle('is-hidden-col',!campusOn);
    });
  }
  try{ if(extraBox) extraBox.checked = localStorage.getItem(EXTRA_KEY)==='1'; }catch(e){}
  try{
    if(campusBox){
      var stored=localStorage.getItem(CAMPUS_KEY);
      campusBox.checked = stored===null ? true : stored==='1';
    }
  }catch(e){}
  apply();
  extraBox&&extraBox.addEventListener('change',function(){
    try{ localStorage.setItem(EXTRA_KEY, extraBox.checked?'1':'0'); }catch(e){}
    apply();
  });
  campusBox&&campusBox.addEventListener('change',function(){
    try{ localStorage.setItem(CAMPUS_KEY, campusBox.checked?'1':'0'); }catch(e){}
    apply();
  });
  var target=document.getElementById('peopleDirectory');
  if(target && window.MutationObserver){
    new MutationObserver(function(){ apply(); }).observe(target,{childList:true,subtree:false});
  }
})();
</script>
</body>
</html>
