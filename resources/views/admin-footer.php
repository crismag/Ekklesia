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
    $content .= '<div class="read-only-banner">Read-only — only portal admins can save footer changes.</div>';
}
$content .= '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Footer text</h2><p>Two slots — a left phrase and a right phrase. Either may be empty.</p></div>' . admin_section_status_badge('Live') . '</div>'
    . '<div class="admin-card-body">'
    . '<div class="field-row">'
    . '<div class="field"><label for="leftText">Left text</label><input type="text" id="leftText" maxlength="80"></div>'
    . '<div class="field"><label for="rightText">Right text</label><input type="text" id="rightText" maxlength="120"></div>'
    . '</div>'
    . '<label class="switch" style="margin-top:6px"><input type="checkbox" id="minimalCheckbox"> Minimal footer (single thin row, no border, no padding)</label>'
    . '</div>'
    . '<div class="actions-bar"><div></div><div style="display:flex;gap:8px;align-items:center"><span class="toast" id="ftrToast"></span><button class="button" id="saveBtn"' . ($isAdmin ? '' : ' aria-disabled="true"') . '>Save changes</button></div></div>'
    . '</article>'

    . '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Live preview</h2></div></div>'
    . '<div class="admin-card-body" style="padding:0">'
    . '<div id="footerPreview" style="background:linear-gradient(180deg,var(--gradient-top,#0c2f28),var(--gradient-mid,#123b31));padding:24px"><div id="prevFooterMount"></div></div>'
    . '</div>'
    . '</article>';

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'footer',
    'pageTitle' => 'Footer · Admin', 'pageSubtitle' => 'Footer text + minimal toggle.',
    'sectionTitle' => 'Footer',
    'sectionDescription' => 'Manage the global footer text. The minimal mode collapses it to a single thin row across every page.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
?>
<script>
(function(){
    const cfg = <?= $chromeJson ?>;
    const basePath = <?= json_encode($basePath, JSON_THROW_ON_ERROR) ?>;
    const canSave = <?= $isAdmin ? 'true' : 'false' ?>;
    const footer = cfg.footer || {};

    const leftEl    = document.getElementById('leftText');
    const rightEl   = document.getElementById('rightText');
    const minimalEl = document.getElementById('minimalCheckbox');
    const saveBtn   = document.getElementById('saveBtn');
    const toast     = document.getElementById('ftrToast');
    const mount     = document.getElementById('prevFooterMount');

    leftEl.value = footer.leftText || '';
    rightEl.value = footer.rightText || '';
    minimalEl.checked = !!footer.minimal;

    function esc(v){ return String(v ?? '').replace(/[&<>"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch])); }

    function refreshPreview(){
        const cls = minimalEl.checked
            ? 'border:0;padding:6px 0;margin-top:14px;font-size:11px;opacity:.7'
            : 'border-top:1px solid rgba(255,255,255,.18);padding:16px 0;margin-top:28px;font-size:12px';
        mount.innerHTML = `
            <footer style="display:flex;justify-content:space-between;gap:12px;color:rgba(248,255,251,.72);${cls}">
                <span>${esc(leftEl.value)}</span>
                <span>${esc(rightEl.value)}</span>
            </footer>
        `;
    }
    [leftEl, rightEl, minimalEl].forEach(el => el.addEventListener('input', refreshPreview));
    refreshPreview();

    saveBtn.addEventListener('click', async () => {
        if (!canSave) { toast.textContent = 'Saving requires portal-admin.'; toast.className = 'toast error'; return; }
        const payload = {
            header: cfg.header || {},
            footer: {
                leftText:  leftEl.value.trim(),
                rightText: rightEl.value.trim(),
                minimal:   minimalEl.checked,
            },
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
