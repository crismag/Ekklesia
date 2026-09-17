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
    $content .= '<div class="ek-alert" role="note">Only a portal-wide admin can change the theme. You can look at each preset here.</div>';
}
$content .= '<section class="ek-card" id="themePicker" aria-labelledby="themeHeading">'
    . '<div class="ek-card-head"><div><h2 id="themeHeading">Theme</h2><p>Choose a preset, then apply it. It changes the colours and density of every page, for everyone.</p></div></div>'
    . '<div class="theme-grid" id="themeGrid" role="radiogroup" aria-label="Theme presets"></div>'
    . '<div class="actions-bar"><div><button class="ek-btn ek-btn-primary" type="button" id="saveThemeBtn"' . ($isAdmin ? '' : ' aria-disabled="true"') . '>Apply selected theme</button></div><span class="toast" id="themeToast" role="status"></span></div>'
    . '</section>'

    . '<details class="ek-card" style="padding:0">'
    . '<summary class="ek-card-head" style="cursor:pointer;min-height:44px"><strong>Adding a preset</strong></summary>'
    . '<div class="ek-card-body" style="color:var(--muted);font-size:13px;line-height:1.5">'
    . '<p style="margin-top:0">Presets live in <span class="code">config/theme.json</span> on the server. Each one defines:</p>'
    . '<ul style="margin:6px 0;padding-left:18px">'
    . '<li><b>Colours</b>: ink, muted, line, paper, deep, teal, blue, gold, rose, soft, bg</li>'
    . '<li><b>Gradient</b>: gradient-top, gradient-mid (the sidebar and deep bands)</li>'
    . '<li><b>Density</b>: font-scale, radius, spacing</li>'
    . '</ul>'
    . '<p style="margin-bottom:0">To add one, copy an existing entry, rename its key and change the values. It appears here on the next page load.</p>'
    . '</div></details>';

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'theme',
    'pageTitle' => 'Theme', 'pageSubtitle' => 'Colour and density presets.',
    'sectionTitle' => 'Theme',
    'sectionDescription' => 'The portal’s colours and density, chosen from the presets installed on this server.',
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
            <button type="button" role="radio" aria-checked="${id === selected ? 'true' : 'false'}" class="theme-card${id === selected ? ' is-active' : ''}" data-id="${escapeHtml(id)}">
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
