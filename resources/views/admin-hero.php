<?php
/**
 * @var string                 $basePath
 * @var ?array<string, mixed>  $actor
 * @var array<string, mixed>   $campusSelector
 * @var array<string, mixed>   $heroConfig
 *
 * Hero rotator settings — slides editor + behaviour controls. Saves go
 * through POST /api/hero, which is gated to portal-wide admins.
 */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isPortalWideAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$defaultCampusId = $campusSelector['defaultCampusId'] ?? null;
$heroJson = htmlspecialchars(json_encode($heroConfig, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

require_once __DIR__ . '/_portal-shell.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Hero Settings - Church Portal</title>
    <style>
                *{box-sizing:border-box}
        body{margin:0;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 250px,var(--bg) 251px)}
        a{color:inherit}
        .crumbs{margin-bottom:6px;color:rgba(248,255,251,.78);font-size:12px}
        .crumbs a{color:#fff;text-decoration:none;font-weight:700}
        .admin-titleblock{margin:0 0 18px;color:#f8fffb}
        .admin-titleblock h1{margin:0;font-size:clamp(28px,3.6vw,40px);line-height:1.04}
        .admin-titleblock .sub{color:rgba(248,255,251,.78);font-size:13px}
        .layout{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,.6fr);gap:16px;align-items:start}
        .panel{background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 16px 42px rgba(27,50,40,.08);overflow:hidden}
        .panel-head{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line)}
        .panel-head h2{margin:0;font-size:15px}
        .panel-body{padding:14px 16px}
        .field{display:grid;gap:5px;margin-bottom:12px}
        .field label{font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        .field input,.field textarea,.field select{width:100%;border:1px solid var(--line);border-radius:8px;padding:10px 12px;font:inherit;background:#fff;color:var(--ink)}
        .field textarea{min-height:80px;resize:vertical}
        .field-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .switch{display:flex;align-items:center;gap:10px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:#fbfdfc;font-weight:600}
        .switch input{margin:0}
        .button,button.button{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 14px;border-radius:8px;background:var(--teal);color:var(--on-teal);border:0;font:inherit;font-weight:800;cursor:pointer;text-decoration:none}
        .button.secondary{background:#fff;color:var(--deep);border:1px solid var(--line)}
        .button.danger{background:#fff5f5;color:var(--danger);border:1px solid #f3c7c7}
        .button:disabled,.button[aria-disabled="true"]{opacity:.5;cursor:not-allowed;pointer-events:none}
        .slide-row{border:1px solid #edf2ef;border-radius:10px;padding:12px 14px;margin-bottom:10px;background:#fbfdfc;display:grid;gap:10px}
        .slide-row .slide-head{display:flex;justify-content:space-between;align-items:center;gap:8px}
        .slide-row .slide-head strong{font-size:13px;color:var(--muted)}
        .slide-row .slide-head .row-actions{display:flex;gap:6px}
        .icon-only{width:30px;height:30px;display:grid;place-items:center;border-radius:6px;border:1px solid var(--line);background:#fff;color:var(--ink);cursor:pointer;font-weight:900}
        .icon-only:hover{background:var(--soft)}
        .icon-only.danger{color:var(--danger);border-color:#f3c7c7}
        .actions-bar{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:14px 16px;border-top:1px solid var(--line);background:#fbfdfc}
        .actions-bar .left,.actions-bar .right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .toast{margin-left:auto;font-size:12px;color:var(--muted)}
        .toast.success{color:var(--teal)}
        .toast.error{color:var(--danger)}
        .preview{padding:18px;background:linear-gradient(180deg,var(--gradient-top,#0c2f28),var(--gradient-mid,#123b31));color:#f8fffb;border-radius:10px}
        .preview .pulse-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#8ef0c6;box-shadow:0 0 0 6px rgba(142,240,198,.12);margin-right:6px;vertical-align:middle}
        .preview-kicker{display:inline-flex;align-items:center;padding:4px 10px;border:1px solid rgba(255,255,255,.22);border-radius:999px;background:rgba(255,255,255,.1);font-size:12px;font-weight:800}
        .preview-title{margin:10px 0 6px;font-weight:900;line-height:1.04;letter-spacing:0}
        .preview-title.sm{font-size:18px}.preview-title.md{font-size:24px}.preview-title.lg{font-size:30px}.preview-title.xl{font-size:36px}
        .preview-lead{color:rgba(248,255,251,.78);font-size:13px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
        .preview-controls{margin-top:12px;display:flex;gap:6px;align-items:center}
        /* The dot stays 18x5; the button around it gets a real target. */
        .preview-dot{width:18px;height:5px;border-radius:999px;background:rgba(255,255,255,.26);border:0;
          box-sizing:content-box;padding:12px 6px;background-clip:content-box;cursor:pointer}
        .preview-dot.active{background:#fff;background-clip:content-box}
        .read-only-banner{padding:11px 14px;background:#fff8e6;border:1px solid #f3e0a8;color:#7c5b07;font-size:13px;border-radius:8px;margin-bottom:14px}
        
        @media(max-width:840px){.layout{grid-template-columns:1fr}.field-row{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>" data-hero='<?= $heroJson ?>' data-can-save="<?= $isPortalWideAdmin ? '1' : '0' ?>">
    <?= portal_header(
        $basePath,
        '',
        '',
        $campuses,
        $defaultCampusId !== null ? (int) $defaultCampusId : null,
        $actor,
        [
            ['href' => $base . '/',             'label' => 'Dashboard',    'icon' => 'dashboard'],
            ['href' => $base . '/ministries',   'label' => 'Ministries',   'icon' => 'ministry'],
            ['href' => $base . '/calendar',     'label' => 'Calendar',     'icon' => 'calendar'],
            ['href' => $base . '/events',       'label' => 'Events',       'icon' => 'events'],
            ['href' => $base . '/people',       'label' => 'People',       'icon' => 'people'],
        ],
        [],
        [],
        'Sign in',
        $base . '/login',
    ) ?>

    <div class="admin-titleblock">
        <div class="crumbs"><a href="<?= $base ?>/admin">Administration</a> &rsaquo; Hero rotator</div>
        <h1>Dashboard hero</h1>
        <div class="sub">Edit the rotating headline shown to signed-out visitors on Home. Signed-in members see a compact personal greeting instead. Stored as <code>config/hero.json</code>.</div>
    </div>

    <?php if (!$isPortalWideAdmin): ?>
        <div class="read-only-banner">
            You're viewing read-only — only portal-wide admins can save changes here.
            The form below is editable for previewing, but the <strong>Save</strong> action will be blocked by the server.
        </div>
    <?php endif; ?>

    <div class="layout">
        <article class="panel" aria-label="Hero editor">
            <div class="panel-head">
                <h2>Slides</h2>
                <button class="button secondary" type="button" id="addSlideBtn">+ Add slide</button>
            </div>
            <div class="panel-body" id="slidesContainer"></div>

            <div class="panel-head">
                <h2>Behaviour</h2>
            </div>
            <div class="panel-body">
                <div class="field-row">
                    <label class="switch">
                        <input type="checkbox" id="autoRotate"> Auto-rotate slides
                    </label>
                    <label class="switch">
                        <input type="checkbox" id="showControls"> Show prev / next + dot pager
                    </label>
                </div>
                <div class="field-row" style="margin-top:8px">
                    <div class="field">
                        <label for="rotationMs">Rotation interval (ms)</label>
                        <input type="number" id="rotationMs" min="1500" max="60000" step="500">
                        <small style="color:var(--muted);font-size:11px">Allowed range: 1500–60000 ms (1.5–60 seconds).</small>
                    </div>
                    <div class="field">
                        <label for="displayMode">Display mode</label>
                        <select id="displayMode">
                            <option value="rotator">Rotator (multi-slide)</option>
                            <option value="static">Static (first slide only)</option>
                        </select>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="titleScale">Title size</label>
                        <select id="titleScale">
                            <option value="sm">Small</option>
                            <option value="md">Medium</option>
                            <option value="lg">Large (default)</option>
                            <option value="xl">Extra-large</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="actions-bar">
                <div class="left">
                    <button class="button" id="saveBtn" type="button"<?= $isPortalWideAdmin ? '' : ' aria-disabled="true"' ?>>Save changes</button>
                    <button class="button secondary" id="resetBtn" type="button">Reset to last saved</button>
                </div>
                <div class="right">
                    <span class="toast" id="saveToast"></span>
                </div>
            </div>
        </article>

        <aside class="panel" aria-label="Live preview">
            <div class="panel-head"><h2>Preview</h2><span style="color:var(--muted);font-size:12px" id="previewMeta">Slide 1 of 1</span></div>
            <div class="panel-body">
                <div class="preview" id="previewBlock">
                    <div class="preview-kicker"><span class="pulse-dot"></span><span id="prevKicker">Loading…</span></div>
                    <h3 class="preview-title lg" id="prevTitle">…</h3>
                    <p class="preview-lead" id="prevLead">…</p>
                    <div class="preview-controls" id="prevControls"></div>
                </div>
                <div class="field" style="margin-top:14px">
                    <label>Source</label>
                    <code style="font-size:12px;color:var(--muted)">config/hero.json</code>
                </div>
            </div>
        </aside>
    </div>

    <?= portal_footer('Church Portal', 'Hero settings') ?>
</div>
<script>
(function(){
    const shell = document.querySelector('.shell');
    const basePath = shell.dataset.base || '';
    const canSave = shell.dataset.canSave === '1';
    let config = JSON.parse(shell.dataset.hero || '{}');
    let lastSaved = JSON.parse(JSON.stringify(config));

    const slidesContainer = document.getElementById('slidesContainer');
    const autoRotateEl = document.getElementById('autoRotate');
    const showControlsEl = document.getElementById('showControls');
    const rotationMsEl = document.getElementById('rotationMs');
    const displayModeEl = document.getElementById('displayMode');
    const titleScaleEl = document.getElementById('titleScale');
    const saveBtn = document.getElementById('saveBtn');
    const resetBtn = document.getElementById('resetBtn');
    const saveToast = document.getElementById('saveToast');
    const prevKicker = document.getElementById('prevKicker');
    const prevTitle = document.getElementById('prevTitle');
    const prevLead = document.getElementById('prevLead');
    const prevControls = document.getElementById('prevControls');
    const previewMeta = document.getElementById('previewMeta');

    let previewIdx = 0;
    let previewTimer = null;

    function escapeHtml(value){
        return String(value ?? '').replace(/[&<>"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));
    }

    function renderSlides(){
        slidesContainer.innerHTML = (config.slides || []).map((slide, i) => `
            <div class="slide-row" data-idx="${i}">
                <div class="slide-head">
                    <strong>Slide ${i + 1}</strong>
                    <div class="row-actions">
                        <button type="button" class="icon-only" data-action="up"  title="Move up"   ${i === 0 ? 'disabled' : ''}>↑</button>
                        <button type="button" class="icon-only" data-action="down" title="Move down" ${i === (config.slides.length - 1) ? 'disabled' : ''}>↓</button>
                        <button type="button" class="icon-only danger" data-action="delete" title="Delete slide">✕</button>
                    </div>
                </div>
                <div class="field">
                    <label for="slide-${i}-kicker">Kicker (optional small label)</label>
                    <input id="slide-${i}-kicker" type="text" data-field="kicker" maxlength="80" value="${escapeHtml(slide.kicker || '')}">
                </div>
                <div class="field">
                    <label for="slide-${i}-title">Title <span style="color:var(--danger)" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>
                    <input id="slide-${i}-title" type="text" data-field="title" maxlength="240" value="${escapeHtml(slide.title || '')}">
                </div>
                <div class="field">
                    <label for="slide-${i}-lead">Lead / body <span style="color:var(--danger)" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>
                    <textarea id="slide-${i}-lead" data-field="lead" maxlength="600">${escapeHtml(slide.lead || '')}</textarea>
                </div>
            </div>
        `).join('');
    }

    function renderBehaviour(){
        const b = config.behavior || {};
        autoRotateEl.checked   = !!b.autoRotate;
        showControlsEl.checked = !!b.showControls;
        rotationMsEl.value     = b.rotationMs ?? 7000;
        displayModeEl.value    = b.displayMode || 'rotator';
        titleScaleEl.value     = b.titleScale || 'lg';
    }

    function rerenderPreview(){
        const slides = config.slides || [];
        if (slides.length === 0){
            prevKicker.textContent = '';
            prevTitle.textContent = 'No slides yet — add one to get started.';
            prevLead.textContent = '';
            prevControls.innerHTML = '';
            previewMeta.textContent = '';
            return;
        }
        const isStatic = (config.behavior || {}).displayMode === 'static';
        if (isStatic) previewIdx = 0;
        if (previewIdx >= slides.length) previewIdx = 0;
        const slide = slides[previewIdx];
        prevKicker.textContent = slide.kicker || '';
        prevTitle.textContent  = slide.title || '';
        prevLead.textContent   = slide.lead  || '';
        prevTitle.className = 'preview-title ' + ((config.behavior || {}).titleScale || 'lg');
        previewMeta.textContent = `Slide ${previewIdx + 1} of ${slides.length}`;
        const showControls = (config.behavior || {}).showControls && !isStatic;
        prevControls.innerHTML = showControls
            ? slides.map((_, i) => `<button type="button" class="preview-dot ${i === previewIdx ? 'active' : ''}" data-i="${i}" aria-label="Show slide ${i + 1}"${i === previewIdx ? ' aria-current="true"' : ''}></button>`).join('')
            : '';
    }

    function readBehaviourFromUI(){
        config.behavior = {
            autoRotate:   autoRotateEl.checked,
            rotationMs:   Math.max(1500, Math.min(60000, parseInt(rotationMsEl.value, 10) || 7000)),
            showControls: showControlsEl.checked,
            displayMode:  displayModeEl.value,
            titleScale:   titleScaleEl.value,
        };
    }

    function readSlidesFromUI(){
        const rows = [...slidesContainer.querySelectorAll('.slide-row')];
        config.slides = rows.map(row => ({
            kicker: row.querySelector('[data-field=kicker]').value.trim(),
            title:  row.querySelector('[data-field=title]').value.trim(),
            lead:   row.querySelector('[data-field=lead]').value.trim(),
        }));
    }

    function startPreviewLoop(){
        clearInterval(previewTimer); previewTimer = null;
        const b = config.behavior || {};
        if (b.autoRotate && b.displayMode !== 'static' && (config.slides || []).length > 1){
            previewTimer = setInterval(() => {
                previewIdx = (previewIdx + 1) % config.slides.length;
                rerenderPreview();
            }, Math.max(1500, b.rotationMs || 7000));
        }
    }

    function refreshAll(){
        readBehaviourFromUI();
        readSlidesFromUI();
        rerenderPreview();
        startPreviewLoop();
    }

    // Slide row events
    slidesContainer.addEventListener('input', refreshAll);
    slidesContainer.addEventListener('click', (e) => {
        const action = e.target.closest('[data-action]')?.dataset.action;
        if (!action) return;
        const row = e.target.closest('.slide-row');
        const idx = Number(row?.dataset.idx);
        if (Number.isNaN(idx)) return;
        readBehaviourFromUI(); readSlidesFromUI();
        if (action === 'delete') {
            config.slides.splice(idx, 1);
        } else if (action === 'up' && idx > 0) {
            const [s] = config.slides.splice(idx, 1);
            config.slides.splice(idx - 1, 0, s);
        } else if (action === 'down' && idx < config.slides.length - 1) {
            const [s] = config.slides.splice(idx, 1);
            config.slides.splice(idx + 1, 0, s);
        }
        renderSlides(); rerenderPreview(); startPreviewLoop();
    });

    document.getElementById('addSlideBtn').addEventListener('click', () => {
        readBehaviourFromUI(); readSlidesFromUI();
        config.slides = config.slides || [];
        config.slides.push({ kicker: '', title: 'New slide title', lead: 'Describe what this slide should communicate to dashboard visitors.' });
        renderSlides(); rerenderPreview();
    });

    // Behaviour events
    [autoRotateEl, showControlsEl, rotationMsEl, displayModeEl, titleScaleEl].forEach(el => {
        el.addEventListener('change', refreshAll);
    });
    rotationMsEl.addEventListener('input', refreshAll);

    // Preview dot click
    prevControls.addEventListener('click', (e) => {
        const i = e.target.closest('.preview-dot')?.dataset.i;
        if (i === undefined) return;
        previewIdx = Number(i);
        rerenderPreview();
    });

    // Save
    saveBtn.addEventListener('click', async () => {
        if (!canSave) {
            saveToast.textContent = 'Saving requires portal-admin permission.';
            saveToast.className = 'toast error';
            return;
        }
        readBehaviourFromUI(); readSlidesFromUI();
        saveToast.textContent = 'Saving…';
        saveToast.className = 'toast';
        saveBtn.disabled = true;
        try {
            const res = await fetch(`${basePath}/api/hero`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(config),
            });
            const payload = await res.json().catch(() => ({}));
            if (!res.ok) {
                throw new Error(payload.error || `Save failed (${res.status})`);
            }
            // Use server-canonicalised payload as new lastSaved.
            config = payload;
            lastSaved = JSON.parse(JSON.stringify(payload));
            renderSlides(); renderBehaviour(); rerenderPreview(); startPreviewLoop();
            saveToast.textContent = 'Saved.';
            saveToast.className = 'toast success';
        } catch (err) {
            saveToast.textContent = err.message || 'Save failed.';
            saveToast.className = 'toast error';
        } finally {
            saveBtn.disabled = false;
        }
    });

    resetBtn.addEventListener('click', () => {
        config = JSON.parse(JSON.stringify(lastSaved));
        renderSlides(); renderBehaviour(); rerenderPreview(); startPreviewLoop();
        saveToast.textContent = 'Reverted to last saved version.';
        saveToast.className = 'toast';
    });

    renderSlides();
    renderBehaviour();
    rerenderPreview();
    startPreviewLoop();
})();
</script>
</body>
</html>
