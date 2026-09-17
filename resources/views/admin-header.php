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

$content = '';
if (!$isAdmin) {
    $content .= '<div class="ek-alert" role="note">Only a portal-wide admin can change the brand.</div>';
}
$content .= '<section class="ek-card" aria-labelledby="brandHeading">'
    . '<div class="ek-card-head"><div><h2 id="brandHeading">Brand</h2><p>The product name at the top of the sidebar on every page. The church name from Church information is shown beneath it.</p></div></div>'
    . '<div class="ek-card-body"><div class="ek-form">'
    . '<div class="ek-field"><label for="brandTitle">Brand name</label><input class="ek-input" type="text" id="brandTitle" maxlength="60"></div>'
    . '<div class="ek-field"><label for="brandSubtitle">Brand subtitle</label><input class="ek-input" type="text" id="brandSubtitle" maxlength="120" aria-describedby="brandSubtitleHint"><span class="ek-hint" id="brandSubtitleHint">Saved, but not shown at the moment: the sidebar shows the church name there instead.</span></div>'
    . '</div></div>'
    . '<div class="actions-bar">'
    . '<button class="ek-btn ek-btn-primary" type="button" id="saveBtn"' . ($isAdmin ? '' : ' aria-disabled="true"') . '>Save brand</button>'
    . '<span class="toast" id="hdrToast" role="status"></span>'
    . '</div>'
    . '</section>'

    // Navigation used to be edited here as a row of top-bar links. Since the
    // workspace sidebar it comes from the workspace map, which follows each
    // person's access; a hand-edited list could not. Saying so is better than
    // an editor whose changes appear nowhere.
    . '<section class="ek-card" aria-labelledby="navHeading">'
    . '<div class="ek-card-head"><div><h2 id="navHeading">Navigation</h2><p>The sidebar lists the portal\'s workspaces and the pages in each.</p></div></div>'
    . '<div class="ek-card-body" style="color:var(--muted);font-size:13px;line-height:1.5">'
    . '<p style="margin:0">Navigation is not edited here. Every page shows the same workspace sidebar (the menu button opens it on phones), and each person sees only the pages their account can use.</p>'
    . '</div></section>';

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'header',
    'pageTitle' => 'Header', 'pageSubtitle' => 'Brand text.',
    'sectionTitle' => 'Header',
    'sectionDescription' => 'The product name shown at the top of the sidebar on every page.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
?>
<script>
(function(){
    const cfg = <?= $chromeJson ?>;
    const basePath = <?= json_encode($basePath, JSON_THROW_ON_ERROR) ?>;
    const canSave = <?= $isAdmin ? 'true' : 'false' ?>;

    const brandTitleEl = document.getElementById('brandTitle');
    const brandSubEl   = document.getElementById('brandSubtitle');
    const saveBtn      = document.getElementById('saveBtn');
    const toast        = document.getElementById('hdrToast');
    const header = (cfg.header || {});
    // Carried through unchanged so saving the brand never rewrites the stored
    // list; it no longer drives navigation.
    const nav = Array.isArray(header.primaryNav) ? header.primaryNav.slice() : [];

    brandTitleEl.value = header.brandTitle || '';
    brandSubEl.value   = header.brandSubtitle || '';

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
})();
</script>
