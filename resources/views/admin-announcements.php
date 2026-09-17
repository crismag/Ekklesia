<?php
require_once __DIR__ . '/_admin-shell.php';
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
/** @var array<string, mixed> $announcementConfig */
$configJson = htmlspecialchars(json_encode($announcementConfig ?? ['items' => []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

$content = ''
    . ($isAdmin ? '' : '<div class="read-only-banner">You can preview announcements, but only a portal-wide admin can save them.</div>')
    . '<article class="admin-card" data-announcements="' . $configJson . '" data-can-save="' . ($isAdmin ? '1' : '0') . '" id="annEditor">'
    . '<div class="admin-card-head"><div><h2>Published notes</h2><p>These appear on the portal home page. Drafts stay hidden until you check Published. Optional start/end dates keep seasonal notes from lingering.</p></div>' . admin_section_status_badge('Live') . '</div>'
    . '<div class="admin-card-body"><div id="annList"></div>'
    . '<p style="margin:8px 0 0"><button class="button secondary" type="button" id="addAnn">+ Add announcement</button></p></div>'
    . '<div class="actions-bar"><div><button class="button" type="button" id="saveAnn"' . ($isAdmin ? '' : ' aria-disabled="true"') . '>Save</button> '
    . '<button class="button secondary" type="button" id="resetAnn">Reset</button></div><span class="toast" id="annToast"></span></div>'
    . '</article>'
    . <<<'JS'
<script>
(function(){
  const root = document.getElementById('annEditor');
  if (!root) return;
  const basePath = document.querySelector('.shell')?.dataset.base || '';
  const canSave = root.dataset.canSave === '1';
  let items = (JSON.parse(root.dataset.announcements || '{"items":[]}').items) || [];
  let lastSaved = JSON.parse(JSON.stringify(items));
  const list = document.getElementById('annList');
  const toast = document.getElementById('annToast');
  const tags = ['Church','Campus','Ministry','Prayer','Serve'];
  function esc(v){return String(v??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
  function newId(){return 'a'+Math.random().toString(16).slice(2,10);}
  function render(){
    if (!items.length) {
      list.innerHTML = '<p style="color:var(--muted);margin:0">No announcements yet. Add one to post it on Home.</p>';
      return;
    }
    list.innerHTML = items.map((item,i)=>`
      <div class="slide-row" data-i="${i}" style="border:1px solid #edf2ef;border-radius:10px;padding:12px 14px;margin-bottom:10px;background:#fbfdfc;display:grid;gap:10px">
        <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
          <strong style="font-size:13px;color:var(--muted)">#${i+1}</strong>
          <button class="button danger" type="button" data-remove="${i}">Remove</button>
        </div>
        <div class="field"><label>Title</label><input data-f="title" data-i="${i}" value="${esc(item.title||'')}"></div>
        <div class="field"><label>Body</label><textarea data-f="body" data-i="${i}" rows="3">${esc(item.body||'')}</textarea></div>
        <div class="field-row">
          <div class="field"><label>Tag</label><select data-f="tag" data-i="${i}">${tags.map(t=>`<option${item.tag===t?' selected':''}>${t}</option>`).join('')}</select></div>
          <label class="switch"><input type="checkbox" data-f="published" data-i="${i}" ${item.published?'checked':''}> Published</label>
        </div>
        <div class="field-row">
          <div class="field"><label>Starts (optional)</label><input type="date" data-f="startsOn" data-i="${i}" value="${esc(item.startsOn||'')}"></div>
          <div class="field"><label>Ends (optional)</label><input type="date" data-f="endsOn" data-i="${i}" value="${esc(item.endsOn||'')}"></div>
        </div>
      </div>`).join('');
  }
  function readForm(){
    items.forEach((item,i)=>{
      const q = (f)=>list.querySelector(`[data-f="${f}"][data-i="${i}"]`);
      if (!q('title')) return;
      item.title = q('title').value;
      item.body = q('body').value;
      item.tag = q('tag').value;
      item.published = q('published').checked;
      item.startsOn = q('startsOn').value;
      item.endsOn = q('endsOn').value;
      if (!item.id) item.id = newId();
    });
  }
  list.addEventListener('click', e=>{
    const btn = e.target.closest('[data-remove]');
    if (!btn) return;
    readForm();
    items.splice(Number(btn.dataset.remove),1);
    render();
  });
  list.addEventListener('input', ()=>readForm());
  list.addEventListener('change', ()=>readForm());
  document.getElementById('addAnn').addEventListener('click', ()=>{
    readForm();
    items.push({id:newId(),title:'',body:'',tag:'Church',published:true,startsOn:'',endsOn:''});
    render();
  });
  document.getElementById('resetAnn').addEventListener('click', ()=>{
    items = JSON.parse(JSON.stringify(lastSaved));
    render();
    toast.textContent = 'Reverted to last save.';
  });
  document.getElementById('saveAnn').addEventListener('click', async ()=>{
    if (!canSave) { toast.textContent = 'Only a portal-wide admin can save.'; toast.className='toast error'; return; }
    readForm();
    toast.textContent = 'Saving…'; toast.className='toast';
    try {
      const res = await fetch(basePath + '/api/announcements', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({items})
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || data.message || ('HTTP '+res.status));
      items = data.items || [];
      lastSaved = JSON.parse(JSON.stringify(items));
      render();
      toast.textContent = 'Saved.'; toast.className='toast success';
    } catch (err) {
      toast.textContent = err.message || 'Save failed.'; toast.className='toast error';
    }
  });
  render();
})();
</script>
JS;

echo admin_render_page([
    'basePath' => $basePath,
    'activeId' => 'dashboard',
    'pageTitle' => 'Announcements',
    'pageSubtitle' => 'Church-wide notes on the home page',
    'sectionTitle' => 'Announcements',
    'sectionDescription' => 'Write and schedule notes members see on Home. Shout-outs and a public message board are not part of this page — those need moderation and stay off until they have a real workflow.',
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'isAdmin' => $isAdmin,
], static fn (): string => $content);
