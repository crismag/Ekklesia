<?php
/** @var array<string,mixed> $campusSelector */
/** @var string $basePath */
/** @var ?array<string,mixed> $actor */
/** @var int $personId */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
// Owner/admin self-service context (set by the route; default to none).
$canManage = $canManage ?? false;
$isOwner = $isOwner ?? false;
$ownerCampuses = is_array($ownerCampuses ?? null) ? $ownerCampuses : [];
$ownerPrimaryCampus = (int) ($ownerPrimaryCampus ?? 0);
require_once __DIR__ . '/_portal-shell.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>Person - Ekklesia</title>
<style>
        .pd-h1{margin:6px 0 12px;font-size:clamp(22px,3.2vw,28px);line-height:1.2;color:#f8fffb}
*{box-sizing:border-box}body{margin:0;min-height:100vh;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 245px,var(--bg) 246px)}.panel{background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 14px 34px rgba(28,48,39,.08);overflow:hidden}.profile-hero{display:grid;grid-template-columns:210px minmax(0,1fr);gap:20px;padding:20px;background:linear-gradient(180deg,#fff 0,#fbfdfc 100%);border-bottom:1px solid var(--line)}.photo-card{display:grid;gap:10px;justify-items:center}.photo-frame{width:180px;aspect-ratio:1;border-radius:8px;overflow:hidden;background:var(--soft);border:1px solid var(--line);display:grid;place-items:center;color:var(--deep);font-size:42px;font-weight:900}.photo-frame img{width:100%;height:100%;object-fit:cover;display:block}.photo-frame.missing img{display:none}.profile-name{margin:0;font-size:20px;font-weight:700;line-height:1.2;color:var(--ink)}
        .profile-title{display:grid;gap:10px;align-content:center}.profile-title h2{margin:0;font-size:34px;line-height:1.05}.profile-meta{display:flex;gap:8px;flex-wrap:wrap}.section{padding:18px;border-bottom:1px solid var(--line)}.section:last-child{border-bottom:0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.stat{background:var(--soft);border-radius:8px;padding:14px}.stat strong{display:block;font-size:28px}.muted{color:var(--muted)}.small{font-size:12px}.tags{display:flex;gap:8px;flex-wrap:wrap}.tag{display:inline-flex;border-radius:999px;padding:4px 10px;background:var(--soft);color:var(--deep);font-size:12px;font-weight:800}.tag.public{background:#e8eef6;color:#254d74}.tag.gold{background:#f8efe2;color:#7c5417}.button{display:inline-flex;align-items:center;min-height:40px;padding:9px 13px;border-radius:8px;background:var(--deep);color:#fff;font-weight:900;text-decoration:none}.button.secondary{background:#fff;color:var(--deep);border:1px solid var(--line)}.detail-list{display:grid;gap:8px}.detail-row{display:grid;grid-template-columns:150px minmax(0,1fr);gap:10px;padding:8px 0;border-bottom:1px solid #edf2ef}.detail-row:last-child{border-bottom:0}.two-col{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}@media(max-width:760px){.profile-hero,.grid,.two-col,.detail-row{grid-template-columns:1fr}.photo-frame{width:min(220px,100%)}}
</style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>" data-person-id="<?= (int) $personId ?>" data-is-own-profile="<?= $actor !== null && (int) ($actor['personId'] ?? 0) === (int) $personId ? '1' : '0' ?>">
<?= portal_header(
    $basePath,
    'Person Profile',
    'Profile and ministry activity.',
    $campuses,
    $campusSelector['defaultCampusId'] ?? null,
    $actor,
    [
        ['href' => $base . '/', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ['href' => $base . '/people', 'label' => 'People', 'icon' => 'people'],
        ['href' => $base . '/ministries', 'label' => 'Ministries', 'icon' => 'ministry'],
        ['href' => $base . '/calendar', 'label' => 'Calendar', 'icon' => 'calendar'],
    ],
    [],
    [],
    'Sign in',
    $base . '/login'
) ?>
<main id="portal-main" tabindex="-1">
    <h1 class="pd-h1" id="personHeading">Person</h1>

<section class="panel">
    <div id="profile"><div class="section muted">Loading person profile...</div></div>
    <div class="section">
        <a class="button secondary" id="peopleLink" href="<?= $base ?>/people">Back to people</a>
    </div>
</section>

<?php if ($canManage): ?>
<section class="panel" style="margin-top:16px">
  <div class="section">
    <h3 style="margin:0 0 4px"><?= $isOwner ? 'Manage my profile' : 'Manage this profile' ?></h3>
    <p class="muted small" style="margin:0 0 14px">Update your photo, address coordinates, and primary campus.</p>
    <div style="display:flex;flex-wrap:wrap;gap:26px">
      <div style="min-width:230px">
        <div class="muted small" style="font-weight:800;margin-bottom:6px">PROFILE PHOTO</div>
        <input type="file" id="ownerPhotoFile" accept="image/png,image/jpeg,image/webp">
        <div style="margin-top:8px;display:flex;gap:8px">
          <button class="button" id="ownerPhotoUpload" type="button">Upload photo</button>
          <button class="button secondary" id="ownerPhotoRemove" type="button">Remove</button>
        </div>
        <div class="small" id="ownerPhotoMsg" style="margin-top:6px"></div>
      </div>
      <div style="min-width:200px">
        <div class="muted small" style="font-weight:800;margin-bottom:6px">PRIMARY CAMPUS</div>
        <select id="ownerCampus" style="min-height:40px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;width:100%">
          <option value="0">— none —</option>
          <?php foreach ($ownerCampuses as $c): ?>
            <option value="<?= (int) $c['id'] ?>"<?= $ownerPrimaryCampus === (int) $c['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
        <div style="margin-top:8px"><button class="button" id="ownerCampusSave" type="button">Save campus</button></div>
        <div class="small" id="ownerCampusMsg" style="margin-top:6px"></div>
      </div>
      <div style="min-width:200px">
        <div class="muted small" style="font-weight:800;margin-bottom:6px">ADDRESS COORDINATES</div>
        <button class="button secondary" id="ownerGeo" type="button">&#10227; Refresh coordinates</button>
        <div class="small muted" style="margin-top:6px">Looks up your family's map location from its address.</div>
        <div class="small" id="ownerGeoMsg" style="margin-top:4px"></div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>
</main>
<?= portal_footer('Ekklesia', 'Person profile') ?>
</div>
<script>
(function(){
const shell=document.querySelector('.shell');const basePath=shell.dataset.base||'';const personId=shell.dataset.personId;const isOwnProfile=shell.dataset.isOwnProfile==='1';const campusSelect=document.getElementById('campusSelect');const profile=document.getElementById('profile');
function escapeHtml(v){return String(v??'').replace(/[&<>"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));}
function selectedCampusId(){return campusSelect?.value||'';}
function withCampus(url,name='campus_id'){const id=selectedCampusId();return id?url+(url.includes('?')?'&':'?')+`${name}=${encodeURIComponent(id)}`:url;}
function formatDate(v){if(!v)return'Never';const d=new Date(v);return Number.isNaN(d.getTime())?'Unknown':d.toLocaleDateString([],{month:'short',day:'numeric',year:'numeric'});}
function birthday(p){return p.birthMonth&&p.birthDay?new Date(2026,Number(p.birthMonth)-1,Number(p.birthDay)).toLocaleDateString([],{month:'long',day:'numeric'}):'Not listed';}
function age(p){if(!p.birthYear||Number(p.birthYear)<=0)return'Not listed';const today=new Date();let years=today.getFullYear()-Number(p.birthYear);if(p.birthMonth&&p.birthDay){const birthdayThisYear=new Date(today.getFullYear(),Number(p.birthMonth)-1,Number(p.birthDay));if(today<birthdayThisYear)years--;}return years>=0?`${years}`:'Not listed';}
function initials(p){const name=String(p.displayName||'').trim();return name.split(/\s+/).map(part=>part[0]||'').join('').slice(0,2).toUpperCase()||'?';}
function detail(label,value){return `<div class="detail-row"><strong>${escapeHtml(label)}</strong><span>${value}</span></div>`;}
function campusName(person){return person.primaryCampusName||Array.from(campusSelect?.options||[]).find(option=>String(option.value)===String(person.primaryCampusId))?.textContent||'Campus not set';}
function photoUrl(id){return `${basePath}/people/photo?id=${encodeURIComponent(id)}`;}
async function load(){
    document.getElementById('peopleLink').href=withCampus(`${basePath}/people`);
    profile.innerHTML='<div class="section muted">Loading person profile...</div>';
    try{
        const res=await fetch(withCampus(`${basePath}/api/public/people/${encodeURIComponent(personId)}`),{credentials:'same-origin'});
        const data=await res.json().catch(()=>({}));
        if(!res.ok)throw new Error(data.error||`Request failed ${res.status}`);
        const person=data.person;
        if(!person){profile.innerHTML='<div class="section muted">This person could not be found.</div>';return;}
        // Fill the server-rendered <h1> so the page has a real title.
        try{const _h=document.getElementById('personHeading');
            if(_h&&person.displayName)_h.textContent=person.displayName;}catch(e){}
        const ministries=String(person.ministries||'').trim();
        const ministryTags=ministries?ministries.split(', ').map(label=>`<span class="tag">${escapeHtml(label)}</span>`).join(''):'<span class="muted">No ministry memberships listed</span>';
        const links=Array.isArray(person.publicLinks)?person.publicLinks:[];
        const contact=person.contact||{};
        const address=person.address||{};
        const canViewContact=Boolean(data.canViewPrivileged);
        const campus=campusName(person);
        profile.innerHTML=`<div class="profile-hero">
            <div class="photo-card">
                <div class="photo-frame" id="photoFrame"><img id="profilePhoto" src="${escapeHtml(photoUrl(person.personId))}" alt="${escapeHtml(person.displayName||'Profile photo')}"><span id="photoInitials" style="display:none">${escapeHtml(initials(person))}</span></div>
                <span class="muted small">Photo</span>
            </div>
            <div class="profile-title">
                <p class="profile-name">${escapeHtml(person.displayName||`Person #${person.personId}`)}</p>
                <div class="profile-meta">
                    <span class="tag">${escapeHtml(campus)}</span>
                    <span class="tag public">Member Type: ${escapeHtml(person.memberTypeName||'Not listed')}</span>
                    <span class="tag ${person.isActive?'':'gold'}">${person.isActive?'Active':'Inactive'}</span>
                    <span class="tag public">Birthday ${escapeHtml(birthday(person))}</span>
                    <span class="tag public">Age ${escapeHtml(age(person))}</span>
                </div>
            </div>
        </div>
        <div class="section">
            <div class="grid">
                <div class="stat"><strong>${escapeHtml(person.assignmentCount||0)}</strong><span class="muted">recent assignments</span></div>
                <div class="stat"><strong>${escapeHtml(person.ministryCount||0)}</strong><span class="muted">ministries</span></div>
                <div class="stat"><strong>${escapeHtml(formatDate(person.lastServedAt))}</strong><span class="muted">last served</span></div>
            </div>
        </div>
        <div class="two-col section">
            <div>
                <h3>Ministries</h3>
                <div class="tags">${ministryTags}</div>
            </div>
            <div>
                <h3>Member type and roles</h3>
                <div class="tags"><span class="tag public">${escapeHtml(person.memberTypeName||'No member type listed')}</span><span class="tag">Age ${escapeHtml(age(person))}</span></div>
                <p>${escapeHtml(person.rolesServed||'No recent role history')}</p>
            </div>
        </div>
        <div class="two-col section">
            <div>
                <h3>Shared Links</h3>
                <div class="tags">${links.length?links.map(link=>`<a class="tag public" href="${escapeHtml(link.url)}" target="_blank" rel="noopener">${escapeHtml(link.label||'Link')}</a>`).join(''):'<span class="muted">No public links provided</span>'}</div>
            </div>
            <div>
                <h3>Contact</h3>
                <div class="detail-list">${
                    canViewContact
                        ? detail('Email',escapeHtml(contact.email||'Not listed'))+detail('Mobile',escapeHtml(contact.mobilePhone||'Not listed'))+detail('Home phone',escapeHtml(contact.homePhone||'Not listed'))+detail('Address',escapeHtml([address.line1,address.line2,address.city,address.state,address.zip,address.country].filter(Boolean).join(', ')||'Not listed'))
                        : '<div class="muted">Contact information and address require signed-in access.</div>'
                }</div>
            </div>
        </div>
        <div class="section">
            <h3>Notes and updates</h3>
            <div class="detail-list">
                ${detail('Created',escapeHtml(formatDate(person.dateEntered)))}
                ${detail('Last updated',escapeHtml(formatDate(person.dateLastEdited)))}
            </div>
        </div>`;
        const photo=document.getElementById('profilePhoto');
        photo?.addEventListener('error',()=>{
            const frame=document.getElementById('photoFrame');
            const initialsEl=document.getElementById('photoInitials');
            frame?.classList.add('missing');
            if(initialsEl) initialsEl.style.display='block';
        });
    }catch(error){
        profile.innerHTML=`<div class="section muted">${escapeHtml(error.message||'Person profile could not be loaded.')}</div>`;
    }
}
// Owner/admin self-service controls (photo, campus, coordinates).
(function(){
  function post(url,fd){return fetch(url,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});});}
  var up=document.getElementById('ownerPhotoUpload');
  if(up){up.addEventListener('click',function(){
    var input=document.getElementById('ownerPhotoFile');var m=document.getElementById('ownerPhotoMsg');var f=input&&input.files[0];
    if(!f){m.style.color='#b3261e';m.textContent='Choose an image first.';return;}
    var fd=new FormData();fd.append('photo',f);fd.append('id',personId);
    up.disabled=true;m.style.color='';m.textContent='Uploading…';
    post(basePath+'/people/photo',fd).then(function(res){up.disabled=false;
      if(res.ok&&res.j.success){m.style.color='#1a7a3a';m.textContent='Photo updated.';var img=document.getElementById('profilePhoto');if(img){img.src=basePath+'/people/photo?id='+personId+'&t='+Date.now();var fr=document.getElementById('photoFrame');if(fr)fr.classList.remove('missing');var ini=document.getElementById('photoInitials');if(ini)ini.style.display='none';}}
      else{m.style.color='#b3261e';m.textContent=(res.j&&res.j.error)||'Upload failed.';}
    }).catch(function(){up.disabled=false;m.style.color='#b3261e';m.textContent='Upload failed.';});
  });}
  var rm=document.getElementById('ownerPhotoRemove');
  if(rm){rm.addEventListener('click',function(){var m=document.getElementById('ownerPhotoMsg');var fd=new FormData();fd.append('id',personId);
    post(basePath+'/people/photo-delete',fd).then(function(res){if(res.ok&&res.j.success){m.style.color='#1a7a3a';m.textContent='Photo removed.';var img=document.getElementById('profilePhoto');if(img){img.dispatchEvent(new Event('error'));}}});
  });}
  var cs=document.getElementById('ownerCampusSave');
  if(cs){cs.addEventListener('click',function(){var v=document.getElementById('ownerCampus').value;var m=document.getElementById('ownerCampusMsg');var fd=new FormData();fd.append('id',personId);fd.append('campus_id',v);
    cs.disabled=true;m.style.color='';m.textContent='Saving…';
    post(basePath+'/people/campus',fd).then(function(res){cs.disabled=false;if(res.ok&&res.j.success){m.style.color='#1a7a3a';m.textContent='Campus updated.';setTimeout(load,300);}else{m.style.color='#b3261e';m.textContent=(res.j&&res.j.error)||'Failed.';}});
  });}
  var geo=document.getElementById('ownerGeo');
  if(geo){geo.addEventListener('click',function(){var m=document.getElementById('ownerGeoMsg');var fd=new FormData();fd.append('id',personId);
    geo.disabled=true;m.style.color='';m.textContent='Refreshing…';
    post(basePath+'/people/geocode',fd).then(function(res){geo.disabled=false;if(res.ok&&res.j.success){m.style.color='#1a7a3a';m.textContent='Coordinates updated ('+res.j.lat.toFixed(4)+', '+res.j.lng.toFixed(4)+').';}else{m.style.color='#b3261e';m.textContent=(res.j&&res.j.error)||'Could not geocode.';}});
  });}
})();

campusSelect?.addEventListener('change',load);load();
})();
</script>
</body>
</html>
