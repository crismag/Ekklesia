<?php
require_once __DIR__ . '/_admin-shell.php';

$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
/** @var array<string,mixed> $themeConfig */
// Safe to embed directly in a <script> block: JSON_HEX_* flags escape <, >, &, ', "
// to \u00xx so the literal can never close the script tag or break JS parsing.
$themeJson = json_encode(
    $themeConfig,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$content = '';
if (!$isAdmin) {
    $content .= '<div class="read-only-banner">Read-only — only portal admins can switch themes. You can still preview each preset locally.</div>';
}
$content .= '<article class="admin-card" id="themePicker">'
    . '<div class="admin-card-head"><div><h2>Theme presets</h2><p>Pick a preset — the change applies portal-wide on save.</p></div>' . admin_section_status_badge('Live') . '</div>'
    . '<div class="theme-grid" id="themeGrid"></div>'
    . '<div class="actions-bar"><div><button class="button" id="saveThemeBtn"' . ($isAdmin ? '' : ' aria-disabled="true"') . '>Apply selected theme</button></div><span class="toast" id="themeToast"></span></div>'
    . '</article>'

    . '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>How themes work</h2><p>Each preset is a CSS-variable map applied at the document root.</p></div></div>'
    . '<div class="admin-card-body" style="color:var(--muted);font-size:13px;line-height:1.5">'
    . '<p>Presets live in <span class="code">config/theme.json</span>. Each one defines:</p>'
    . '<ul style="margin:6px 0;padding-left:18px">'
    . '<li><b>Colors</b> — ink, muted, line, paper, deep, teal, blue, gold, rose, soft, bg</li>'
    . '<li><b>Gradient</b> — gradient-top, gradient-mid (the deep header band)</li>'
    . '<li><b>Density</b> — font-scale (page-wide font-size multiplier), radius, spacing</li>'
    . '</ul>'
    . '<p>To add a new preset, copy an existing entry in <span class="code">config/theme.json</span> and rename the key. The picker below picks them up automatically.</p>'
    . '</div></article>';

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'theme',
    'pageTitle' => 'Theme · Admin', 'pageSubtitle' => 'Color palette + density presets.',
    'sectionTitle' => 'Theme',
    'sectionDescription' => 'Pick the active preset — Forest, Facebook, Minimalist, Metallic Chic, Cool &amp; Collected, Earthy &amp; Serene, Vibrant but Calm, plus Compact and Warm — or add your own in config/theme.json.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
?>
<script>
(function(){
    const config = <?= $themeJson ?>;
    const canSave = <?= $isAdmin ? 'true' : 'false' ?>;
    const basePath = <?= json_encode($basePath, JSON_THROW_ON_ERROR) ?>;
    const grid  = document.getElementById('themeGrid');
    const toast = document.getElementById('themeToast');
    const saveBtn = document.getElementById('saveThemeBtn');
    let selected = config.active;

    function escapeHtml(v){ return String(v ?? '').replace(/[&<>"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch])); }
    function swatchHtml(vars){
        const slots = ['--gradient-top','--gradient-mid','--teal','--gold','--soft','--paper'];
        return slots.map(k => `<span style="background:${escapeHtml(vars[k] || '#fff')}"></span>`).join('');
    }

    function render(){
        grid.innerHTML = Object.entries(config.presets).map(([id, preset]) => `
            <button type="button" class="theme-card${id === selected ? ' is-active' : ''}" data-id="${escapeHtml(id)}">
                <div class="theme-swatch">${swatchHtml(preset.vars || {})}</div>
                <div class="theme-info"><b>${escapeHtml(preset.name || id)}</b><small>${escapeHtml(preset.description || '')}</small></div>
            </button>
        `).join('');
    }

    grid.addEventListener('click', (e) => {
        const card = e.target.closest('.theme-card');
        if (!card) return;
        selected = card.dataset.id;
        render();
    });

    saveBtn.addEventListener('click', async () => {
        if (!canSave) {
            toast.textContent = 'Saving requires portal-admin permission.';
            toast.className = 'toast error';
            return;
        }
        toast.textContent = 'Applying…'; toast.className = 'toast'; saveBtn.disabled = true;
        try {
            const res = await fetch(`${basePath}/api/theme/active`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ active: selected }),
            });
            const payload = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(payload.error || `Save failed (${res.status})`);
            toast.textContent = 'Applied. Reloading to show the change…';
            toast.className = 'toast success';
            setTimeout(() => location.reload(), 700);
        } catch (err) {
            toast.textContent = err.message || 'Save failed.';
            toast.className = 'toast error';
            saveBtn.disabled = false;
        }
    });

    render();
})();
</script>
