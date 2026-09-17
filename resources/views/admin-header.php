<?php
require_once __DIR__ . '/_admin-shell.php';

$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
/** @var array<string,mixed> $chromeConfig */
// Embedded inside a <script> block, not an HTML attribute. Script content
// is raw text and is never HTML-decoded, so htmlspecialchars() here put
// literal &quot; into the source and the parser died on the first one —
// taking the whole editor's JavaScript with it. The JSON_HEX_* flags are
// the correct protection in this context: they escape <, >, &, ' and " as
// \uXXXX, which is valid JS and cannot break out of the script element.
$chromeJson = json_encode(
    $chromeConfig,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$content = '<style>'
    // The nav editor's inputs rendered about 21px tall and the move/remove
    // buttons smaller still — below the 24px minimum, and hard to hit on a
    // touch screen where this page is perfectly usable otherwise.
    . '#navList .nav-in{min-height:36px;padding:6px 10px;font:inherit;'
    . 'border:1px solid var(--line,#c7d4cd);border-radius:6px;width:100%;box-sizing:border-box}'
    . '#navList .nav-mv{min-width:36px;min-height:36px;padding:6px 10px;line-height:1}'
    . '</style>';
if (!$isAdmin) {
    $content .= '<div class="read-only-banner">Read-only — only portal admins can save header changes.</div>';
}
$content .= '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Brand</h2><p>Text shown next to the logo mark and as the home aria-label.</p></div>' . admin_section_status_badge('Live') . '</div>'
    . '<div class="admin-card-body">'
    . '<div class="field-row">'
    . '<div class="field"><label for="brandTitle">Brand title</label><input type="text" id="brandTitle" maxlength="60"></div>'
    . '<div class="field"><label for="brandSubtitle">Brand subtitle (used as tooltip)</label><input type="text" id="brandSubtitle" maxlength="120"></div>'
    . '</div></div></article>'

    . '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Primary navigation</h2><p>Links shown across every page topbar (and inside the mobile drawer).</p></div></div>'
    . '<div class="admin-card-body" id="navList"></div>'
    . '<div class="actions-bar">'
    . '<div><button class="button secondary" id="addNavBtn" type="button">+ Add link</button></div>'
    . '<div style="display:flex;gap:8px;align-items:center"><span class="toast" id="hdrToast"></span><button class="button" id="saveBtn"' . ($isAdmin ? '' : ' aria-disabled="true"') . '>Save changes</button></div>'
    . '</div>'
    . '</article>'

    . '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Header uniformity</h2><p>Pages that already share <span class="code">portal_header()</span>.</p></div>' . admin_section_status_badge('Beta') . '</div>'
    . '<div class="admin-card-body" style="color:var(--muted);font-size:13px;line-height:1.5">'
    . '<p>Pages on the new shell pick up brand + nav changes automatically:</p>'
    . '<p><span class="code">/</span>, <span class="code">/calendar</span>, <span class="code">/admin</span>, <span class="code">/admin/hero</span>, <span class="code">/admin/theme</span>, <span class="code">/admin/header</span>, <span class="code">/admin/footer</span> + every other <span class="code">/admin/*</span> page.</p>'
    . '<p>Pages with custom topbars to migrate next: <span class="code">/events</span>, <span class="code">/events/{id}</span>, <span class="code">/people</span>, <span class="code">/people/{id}</span>, <span class="code">/ministries</span>, <span class="code">/ministries/{id}</span>, <span class="code">/my-schedule</span>, <span class="code">/availability</span>, <span class="code">/schedules</span>, <span class="code">/account</span>. Each migration is a small swap to <span class="code">portal_header()</span> + drop of duplicate CSS.</p>'
    . '</div></article>';

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'header',
    'pageTitle' => 'Header · Admin', 'pageSubtitle' => 'Brand text + primary nav.',
    'sectionTitle' => 'Header',
    'sectionDescription' => 'Edit the brand text shown in the topbar and the primary navigation links — applied to every page using the shared shell.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
?>
<script>
(function(){
    const cfg = <?= $chromeJson ?>;
    const basePath = <?= json_encode($basePath, JSON_THROW_ON_ERROR) ?>;
    const canSave = <?= $isAdmin ? 'true' : 'false' ?>;

    const ICONS = ['dashboard','ministry','calendar','events','people','availability','settings','admin','profile','search','menu'];
    const brandTitleEl = document.getElementById('brandTitle');
    const brandSubEl   = document.getElementById('brandSubtitle');
    const navList      = document.getElementById('navList');
    const saveBtn      = document.getElementById('saveBtn');
    const toast        = document.getElementById('hdrToast');
    const header = (cfg.header || {});
    let nav = Array.isArray(header.primaryNav) ? header.primaryNav.slice() : [];

    function esc(v){ return String(v ?? '').replace(/[&<>"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch])); }

    brandTitleEl.value = header.brandTitle || '';
    brandSubEl.value   = header.brandSubtitle || '';

    function renderNav(){
        if (nav.length === 0) {
            navList.innerHTML = '<div style="color:var(--muted);font-size:13px">No items — click "+ Add link" to add one.</div>';
            return;
        }
        navList.innerHTML = nav.map((item, i) => `
            <div style="display:grid;grid-template-columns:auto minmax(0,2fr) minmax(0,2fr) minmax(0,1fr) auto;gap:8px;align-items:center;padding:8px;border:1px solid var(--line);border-radius:8px;margin-bottom:8px;background:#fbfdfc" data-i="${i}">
                <span style="font-weight:800;color:var(--muted);width:24px;text-align:center" aria-hidden="true">${i+1}</span>
                <input class="nav-in" type="text" data-field="label" maxlength="40" value="${esc(item.label || '')}" placeholder="Label"
                       aria-label="Menu item ${i+1} label">
                <input class="nav-in" type="text" data-field="href"  maxlength="200" value="${esc(item.href || '')}" placeholder="/path"
                       aria-label="Menu item ${i+1} link address">
                <select class="nav-in" data-field="icon" aria-label="Menu item ${i+1} icon">
                    ${ICONS.map(ic => `<option value="${ic}"${item.icon === ic ? ' selected' : ''}>${ic}</option>`).join('')}
                </select>
                <span style="display:flex;gap:4px">
                    <!-- The arrows were their own accessible name: a screen
                         reader announced "up arrow, button" with no idea which
                         row it belonged to. -->
                    <button type="button" class="button secondary nav-mv" data-action="up"   ${i === 0 ? 'disabled' : ''}
                            aria-label="Move ${esc(item.label || 'menu item ' + (i+1))} up">↑</button>
                    <button type="button" class="button secondary nav-mv" data-action="down" ${i === nav.length - 1 ? 'disabled' : ''}
                            aria-label="Move ${esc(item.label || 'menu item ' + (i+1))} down">↓</button>
                    <button type="button" class="button danger nav-mv"    data-action="del"
                            aria-label="Remove ${esc(item.label || 'menu item ' + (i+1))} from the menu">✕</button>
                </span>
            </div>
        `).join('');
    }

    navList.addEventListener('input', (e) => {
        const row = e.target.closest('[data-i]'); if (!row) return;
        const i = Number(row.dataset.i);
        const f = e.target.dataset.field;
        if (!f || Number.isNaN(i)) return;
        nav[i][f] = e.target.value;
    });
    navList.addEventListener('click', (e) => {
        const action = e.target.closest('[data-action]')?.dataset.action;
        const row = e.target.closest('[data-i]');
        if (!action || !row) return;
        const i = Number(row.dataset.i);
        if (action === 'del')  nav.splice(i, 1);
        if (action === 'up'   && i > 0)            { const [s] = nav.splice(i, 1); nav.splice(i - 1, 0, s); }
        if (action === 'down' && i < nav.length - 1) { const [s] = nav.splice(i, 1); nav.splice(i + 1, 0, s); }
        renderNav();
    });

    document.getElementById('addNavBtn').addEventListener('click', () => {
        nav.push({ label: 'New link', href: '/', icon: 'dashboard' });
        renderNav();
    });

    saveBtn.addEventListener('click', async () => {
        if (!canSave) { toast.textContent = 'Saving requires portal-admin.'; toast.className = 'toast error'; return; }
        const payload = {
            header: {
                brandTitle:    brandTitleEl.value.trim(),
                brandSubtitle: brandSubEl.value.trim(),
                primaryNav:    nav,
            },
            footer: cfg.footer || {},
        };
        toast.textContent = 'Saving…'; toast.className = 'toast'; saveBtn.disabled = true;
        try {
            const res = await fetch(`${basePath}/api/chrome`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const out = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(out.error || `Save failed (${res.status})`);
            toast.textContent = 'Saved.'; toast.className = 'toast success';
        } catch (err) {
            toast.textContent = err.message || 'Save failed.'; toast.className = 'toast error';
        } finally { saveBtn.disabled = false; }
    });

    renderNav();
})();
</script>
