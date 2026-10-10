<?php
/** @var string $basePath */
/** @var ?array<string,mixed> $actor */
/** @var array<string,mixed> $campusSelector */
require_once __DIR__ . '/_portal-shell.php';
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$permissions = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
$canManageCalendarSettings = $actor !== null && (
    in_array('manage_events', $permissions, true)
    || in_array('manage_schedules', $permissions, true)
    || (bool) ($actor['isPortalWideAdmin'] ?? false)
);
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$campusesJson = htmlspecialchars(json_encode($campuses, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Calendar Settings - Ekklesia</title>
    <style>
                *{box-sizing:border-box}
        body{margin:0;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 250px,var(--bg) 251px)}
        a{color:inherit}
        .page-titlebar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;color:#f8fffb;margin-bottom:18px}
        .brand{display:grid;gap:4px}
        .brand a{color:#f8fffb;text-decoration:none;font-weight:900}
        h1{margin:0;font-size:40px;line-height:1.02}
        .sub{color:rgba(248,255,251,.75)}
        .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
        .campus-mini{display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.24);border-radius:8px;padding:4px 6px}
        .campus-mini select{width:auto;min-width:132px;max-width:180px;height:30px;padding:4px 24px 4px 8px;border:0;border-radius:6px;font-size:12px}
        .icon-btn,.button,button.button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 13px;border-radius:8px;font:inherit;font-weight:900;text-decoration:none;border:0;cursor:pointer}
        .icon-btn{width:34px;height:34px;padding:0;border:1px solid rgba(255,255,255,.24);background:rgba(255,255,255,.1);color:#fff}
        .button{background:#fff;color:var(--deep);box-shadow:0 12px 26px rgba(3,20,13,.16)}
        .button.secondary{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.24);box-shadow:none}
        .button.danger{background:#fff0f0;color:var(--danger)}
        .toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin:18px 0}
        .layout{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(320px,.7fr);gap:16px;align-items:start}
        .panel{background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 16px 42px rgba(27,50,40,.1);overflow:hidden}
        .panel-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line)}
        .panel-head h2{margin:0;font-size:16px}
        .panel-head span{color:var(--muted);font-size:12px}
        .panel-body{padding:16px}
        .grid{display:grid;gap:12px}
        .grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}
        .field{display:grid;gap:6px}
        .field label{font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        .field input,.field select,.field textarea{width:100%;border:1px solid var(--line);border-radius:8px;padding:10px 12px;font:inherit;background:#fff;color:var(--ink)}
        .field textarea{min-height:92px;resize:vertical}
        .help{font-size:12px;color:var(--muted)}
        .help code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;
          background:var(--soft,#eef4f0);padding:1px 4px;border-radius:3px}
        .color-row{display:flex;gap:8px;align-items:center}
        /* 44px keeps the swatch a comfortable target; the native control is
           tiny by default on desktop browsers. */
        .color-row input[type=color]{inline-size:44px;block-size:44px;padding:2px;
          border:1px solid var(--line,#d9e4dd);border-radius:8px;background:var(--paper,#fff);cursor:pointer;flex:0 0 auto}
        .color-row input[type=text]{flex:1;min-width:0}
        .switches{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        .switch{display:flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:8px;padding:10px 11px;background:#fbfdfc}
        .switch input{margin:0}
        .list{display:grid;gap:10px}
        .rule{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;border:1px solid #edf2ef;border-radius:8px;padding:12px;background:#fbfdfc}
        .rule strong{display:block}
        .rule small{display:block;color:var(--muted)}
        .badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:900;background:var(--soft);color:var(--deep)}
        .preview{display:grid;gap:8px}
        .swatch{display:flex;gap:8px;flex-wrap:wrap}
        .swatch button{width:28px;height:28px;border-radius:8px;border:1px solid rgba(0,0,0,.08);cursor:pointer}
        .empty{padding:14px;border:1px dashed var(--line);border-radius:8px;color:var(--muted);background:#fcfdfc}
        @media(max-width:1080px){.layout{grid-template-columns:1fr}.switches,.grid.two{grid-template-columns:1fr}}
        @media(max-width:760px){.page-titlebar{flex-direction:column;align-items:stretch}.actions{justify-content:flex-start}.toolbar{flex-direction:column;align-items:stretch}}
    
        /* WCAG 2.5.8 (AA): source checkboxes were 13x13 and one link 21px.
           Desktop floor only — touch widths already get 44px elsewhere. */
        input[type=checkbox],input[type=radio]{width:24px;height:24px}
        .rule a,.card a,label a,.brand a{min-height:24px;display:inline-flex;align-items:center}
        label{min-height:24px;display:flex;align-items:center;gap:8px}
        @media(max-width:820px){
          input[type=checkbox],input[type=radio]{width:24px;height:24px}
          label{min-height:44px}
          .rule a,.card a,label a,.brand a{min-height:44px}
        }
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>" data-can-manage-settings="<?= $canManageCalendarSettings ? '1' : '0' ?>" data-campuses="<?= $campusesJson ?>">
<?= portal_header($basePath, '', '', $campuses, null, $actor) ?>
<main id="portal-main" tabindex="-1">

    <header class="page-titlebar">
        <div class="brand">
            <a href="<?= $base ?>/">Ekklesia</a>
            <h1>Calendar Settings</h1>
            <div class="sub">Shape what the calendar shows, and define local custom calendars for the dashboard view.</div>
        </div>
        <div class="actions">
            
            <a class="icon-btn" href="<?= $base ?>/calendar" title="Back to calendar">←</a>
            <a class="button secondary" href="<?= $base ?>/calendar">Open calendar</a>
        </div>
    </header>

    <div class="toolbar">
        <div class="badge">Local browser storage</div>
        <div class="help">These settings are stored in the current browser until a shared backend is added.</div>
    </div>

    <section class="layout">
        <article class="panel">
            <div class="panel-head">
                <h2>Visible sources</h2>
                <span>Month / week / day filters</span>
            </div>
            <div class="panel-body grid">
                <div class="switches" id="sourceSwitches">
                    <label class="switch"><input type="checkbox" value="events" checked>Upcoming events</label>
                    <label class="switch"><input type="checkbox" value="assignments" checked>Role assignments</label>
                    <label class="switch"><input type="checkbox" value="schedules" checked>Ministry schedules</label>
                    <label class="switch"><input type="checkbox" value="birthdays" checked>Birthdays</label>
                    <label class="switch"><input type="checkbox" value="anniversaries" checked>Anniversaries</label>
                    <label class="switch"><input type="checkbox" value="custom" checked>Others</label>
                </div>
                <div class="help">Turn sources on or off for the main calendar view. You can also keep only one or two visible when leading a team.<br><strong>Saved in this browser only</strong> — these preferences do not follow you to another device or a private window.</div>
                <div class="grid two">
                    <div class="panel" style="box-shadow:none">
                        <div class="panel-head"><h2>Quick reset</h2><span>Restore the defaults</span></div>
                        <div class="panel-body">
                            <p class="help">This restores all built-in source toggles and clears any local custom rules in this browser.</p>
                            <button class="button danger" id="resetBtn" type="button">Reset local calendar settings</button>
                        </div>
                    </div>
                    <div class="panel" style="box-shadow:none">
                        <div class="panel-head"><h2>Preview</h2><span>What the page can read</span></div>
                        <div class="panel-body preview">
                            <div class="empty">Built-in calendar sources are immediate. Custom rules appear below once saved.</div>
                            <div class="swatch" id="colorSwatches"></div>
                        </div>
                    </div>
                </div>
            </div>
        </article>

        <aside class="panel">
            <div class="panel-head">
                <h2>Custom calendars</h2>
                <span>Query logic stored locally</span>
            </div>
            <div class="panel-body grid">
                <form class="grid" id="ruleForm">
                    <input type="hidden" id="ruleId">
                    <div class="field">
                        <label for="ruleName">Calendar name</label>
                        <input id="ruleName" name="name" type="text" placeholder="Youth team coverage">
                    </div>
                    <div class="grid two">
                        <div class="field">
                            <label for="ruleKind">Kind</label>
                            <select id="ruleKind" name="kind">
                                <option value="custom">Custom</option>
                                <option value="birthday">Birthday</option>
                                <option value="anniversary">Anniversary</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="ruleColor">Color</label>
                            <div class="color-row">
                                <input id="ruleColorPicker" type="color" value="#5b6d8a" aria-label="Pick a colour">
                                <input id="ruleColor" name="color" type="text" value="#5b6d8a" placeholder="#5b6d8a"
                                    inputmode="text" spellcheck="false" aria-describedby="ruleColorHelp">
                            </div>
                            <div class="help" id="ruleColorHelp">Pick a colour, choose a swatch, or type a hex value such as #5b6d8a.</div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="ruleQuery">Query</label>
                        <textarea id="ruleQuery" name="query" aria-describedby="ruleQueryHelp"
                            placeholder="Search text to match items shown on the calendar"></textarea>
                        <div class="help" id="ruleQueryHelp">Matches text in an item&rsquo;s title or details &mdash; for example
                            <code>youth</code> to group everything with &ldquo;youth&rdquo; in its name. Leave blank to match every item of the chosen kind.</div>
                    </div>
                    <label class="switch"><input id="ruleEnabled" type="checkbox" checked>Enabled</label>
                    <div class="toolbar" style="margin:0">
                        <button class="button" id="saveRuleBtn" type="submit">Save calendar rule</button>
                        <button class="button secondary" id="clearRuleBtn" type="button">Clear form</button>
                    </div>
                    <div class="help">Custom calendars are matched against the items already loaded into the view. That keeps the page fast while backend rules are being built.</div>
                </form>
            </div>
        </aside>
    </section>

    <section class="panel" style="margin-top:16px">
        <div class="panel-head">
            <h2>Saved custom calendars</h2>
            <span id="ruleCount">0 rules</span>
        </div>
        <div class="panel-body">
            <div class="list" id="ruleList"></div>
        </div>
    </section>

    </main>
<footer class="portal-footer"><span>Ekklesia</span><span>Calendar settings</span></footer>
</div>
<script>
const shell = document.querySelector('.shell');
const basePath = shell.dataset.base || '';
const canManageSettings = shell.dataset.canManageSettings === '1';
const campusSelect = document.getElementById('campusSelect'); // shell-owned
const sourceSwitches = document.getElementById('sourceSwitches');
const ruleList = document.getElementById('ruleList');
const ruleCount = document.getElementById('ruleCount');
const ruleForm = document.getElementById('ruleForm');
const ruleId = document.getElementById('ruleId');
const ruleName = document.getElementById('ruleName');
const ruleKind = document.getElementById('ruleKind');
const ruleQuery = document.getElementById('ruleQuery');
const ruleColor = document.getElementById('ruleColor');
const ruleColorPicker = document.getElementById('ruleColorPicker');
/* Keep the picker and the hex field in step, in both directions. The text
   field stays authoritative — a half-typed value must not be rewritten under
   the user — so the picker only follows once the text is a complete hex. */
const HEX_RE = /^#[0-9a-f]{6}$/i;
function syncColor(value){
  if(ruleColorPicker && HEX_RE.test(value)) ruleColorPicker.value = value.toLowerCase();
}
ruleColor?.addEventListener('input', () => syncColor(ruleColor.value.trim()));
ruleColorPicker?.addEventListener('input', () => { ruleColor.value = ruleColorPicker.value; });
const ruleEnabled = document.getElementById('ruleEnabled');
const resetBtn = document.getElementById('resetBtn');
const clearRuleBtn = document.getElementById('clearRuleBtn');
const saveRuleBtn = document.getElementById('saveRuleBtn');
const swatches = document.getElementById('colorSwatches');
/* v2, matching calendar.php. The two screens share this key, so leaving this
   on v1 would have made the settings page describe a different set of switches
   than the calendar actually honours. In v2 a source is shown unless it is
   explicitly false. */
const SOURCES_KEY = 'church_portal_calendar_sources_v2';
const RULES_KEY = 'church_portal_calendar_rules_v1';
const COLORS = ['#5b6d8a','#117b6d','#276a9f','#c48725','#8a5d9d','#8b6a35','#b64d4d'];

function esc(value){return String(value ?? '').replace(/[&<>"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));}
function loadSources(){try{const raw = JSON.parse(localStorage.getItem(SOURCES_KEY) || 'null'); return raw && typeof raw === 'object' ? raw : { events:true, assignments:true, schedules:true, birthdays:true, anniversaries:true, custom:true };}catch{return { events:true, assignments:true, schedules:true, birthdays:true, anniversaries:true, custom:true };}}
function saveSources(next){localStorage.setItem(SOURCES_KEY, JSON.stringify(next));}
function loadRules(){try{const raw = JSON.parse(localStorage.getItem(RULES_KEY) || '[]'); return Array.isArray(raw) ? raw : [];}catch{return [];}}
function saveRules(next){localStorage.setItem(RULES_KEY, JSON.stringify(next));}
function ruleIdValue(){return (globalThis.crypto?.randomUUID?.() || `rule_${Date.now()}_${Math.random().toString(16).slice(2)}`);}

function renderSwatches(){swatches.innerHTML = COLORS.map(color => `<button type="button" title="${esc(color)}" style="background:${esc(color)}" data-color="${esc(color)}"></button>`).join('');}

function setForm(rule = null){
  ruleId.value = rule?.id || '';
  ruleName.value = rule?.name || '';
  ruleKind.value = rule?.kind || 'custom';
  ruleQuery.value = rule?.query || '';
  ruleColor.value = rule?.color || '#5b6d8a';
  syncColor(ruleColor.value);
  ruleEnabled.checked = rule?.enabled !== false;
}

function renderRules(){
  const rules = loadRules();
  ruleCount.textContent = `${rules.length} rule${rules.length === 1 ? '' : 's'}`;
  if (!rules.length) {
    ruleList.innerHTML = '<div class="empty">No custom calendars have been saved yet.</div>';
    return;
  }
  ruleList.innerHTML = rules.map(rule => `
    <div class="rule">
      <div>
        <strong>${esc(rule.name || 'Custom calendar')}</strong>
        <small>${esc(rule.kind || 'custom')} · ${esc(rule.query || 'No query')}${rule.color ? ` · ${esc(rule.color)}` : ''}</small>
        <small>${rule.enabled === false ? 'Disabled' : 'Enabled'}</small>
      </div>
      <div class="toolbar" style="margin:0;justify-content:flex-end">
        <button class="button secondary" type="button" data-edit="${esc(rule.id)}">Edit</button>
        <button class="button danger" type="button" data-delete="${esc(rule.id)}">Delete</button>
      </div>
    </div>
  `).join('');
}

function readSourceState(){
  const state = loadSources();
  sourceSwitches.querySelectorAll('input[type="checkbox"]').forEach(input => {
    input.checked = state[input.value] !== false;
  });
}

if (!canManageSettings) {
  saveRuleBtn.disabled = true;
  resetBtn.disabled = true;
  clearRuleBtn.disabled = true;
  ruleForm.querySelectorAll('input,select,textarea,button').forEach(el => el.disabled = true);
}

renderSwatches();
readSourceState();
renderRules();
setForm();

sourceSwitches.addEventListener('change', event => {
  const input = event.target;
  if (!input.matches('input[type="checkbox"]')) return;
  const next = loadSources();
  next[input.value] = input.checked;
  saveSources(next);
});

swatches.addEventListener('click', event => {
  const button = event.target.closest('button[data-color]');
  if (!button) return;
  ruleColor.value = button.dataset.color || ruleColor.value;
  syncColor(ruleColor.value);
});

ruleForm.addEventListener('submit', event => {
  event.preventDefault();
  if (!canManageSettings) return;
  const rules = loadRules();
  const payload = {
    id: ruleId.value || ruleIdValue(),
    name: ruleName.value.trim(),
    kind: ruleKind.value,
    query: ruleQuery.value.trim(),
    color: ruleColor.value.trim() || '#5b6d8a',
    enabled: ruleEnabled.checked,
  };
  const index = rules.findIndex(rule => rule.id === payload.id);
  if (index >= 0) rules.splice(index, 1, payload); else rules.unshift(payload);
  saveRules(rules);
  setForm();
  renderRules();
});

ruleList.addEventListener('click', event => {
  const editId = event.target.closest('[data-edit]')?.dataset.edit;
  const deleteId = event.target.closest('[data-delete]')?.dataset.delete;
  if (editId) {
    const rule = loadRules().find(item => item.id === editId);
    if (rule) setForm(rule);
  }
  if (deleteId) {
    const rules = loadRules().filter(item => item.id !== deleteId);
    saveRules(rules);
    if (ruleId.value === deleteId) setForm();
    renderRules();
  }
});

clearRuleBtn.addEventListener('click', () => setForm());
resetBtn.addEventListener('click', () => {
  localStorage.removeItem(SOURCES_KEY);
  localStorage.removeItem(RULES_KEY);
  readSourceState();
  setForm();
  renderRules();
});

// Campus is now owned by the portal shell (cookie-based, same semantics).
// The page-level control was removed to avoid a duplicate id="campusSelect".
</script>
</body>
</html>
