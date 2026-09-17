<?php
/**
 * @var string                       $basePath
 * @var ?array<string, mixed>        $actor
 */

$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/_portal-shell.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Ekklesia - Availability</title>
    <style>
        .av-h1{margin:6px 0 10px;font-size:clamp(22px,3.2vw,28px);line-height:1.2;color:#f8fffb}
                * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font: 14px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: var(--ink); background: linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 300px,var(--bg) 301px); }
        a { color: inherit; }
        .panel { background: var(--paper); border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 14px 34px rgba(28,48,39,.08); overflow: hidden; }
        .hero { color: #f8fffb; padding: 10px 0 18px; }
        .hero h1 { margin: 0; font-size: 38px; line-height: 1.02; }
        .hero p { margin: 10px 0 0; max-width: 700px; color: rgba(248,255,251,.8); }
        .hero-row { display: flex; justify-content: space-between; gap: 14px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; }
        .hero-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 999px; border: 1px solid rgba(255,255,255,.22); background: rgba(255,255,255,.1); color: #f8fffb; font-size: 12px; font-weight: 900; }
        .grid { display: grid; gap: 14px; }
        .section { padding: 16px; border-bottom: 1px solid var(--line); }
        .section:last-child { border-bottom: 0; }
        .section h2 { margin: 0 0 10px; font-size: 16px; }
        label { display: block; margin: 10px 0 4px; font-weight: 700; }
        input[type=date], input[type=text] { width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px; font: inherit; background: #fff; color: var(--ink); }
        button, .button { display: inline-flex; justify-content: center; align-items: center; min-height: 40px; padding: 9px 13px; border-radius: 8px; background: var(--deep); color: #fff; border: 0; font: inherit; font-weight: 900; text-decoration: none; cursor: pointer; }
        .button.secondary { background: #fff; color: var(--deep); border: 1px solid var(--line); }
        .list { list-style: none; margin: 0; padding: 0; }
        .list li { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px; border-top: 1px solid #edf2ef; }
        .list li:first-child { border-top: 0; }
        .range { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
        .reason { color: var(--muted); }
        .empty { padding: 18px 16px; color: var(--muted); }
        .err { background: #ffebe9; border: 1px solid #ffc1ba; border-radius: 8px; padding: 8px 10px; color: #82071e; margin-top: 10px; }
        
        .muted { color: var(--muted); }
        @media (max-width: 720px) {
            .list li { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('readable') ?>>
    <?= portal_header(
        $basePath,
        'Availability',
        'Update your unavailability and keep serving windows current.',
        [],
        null,
        $actor,
        [
            ['href' => $base . '/', 'label' => 'Dashboard', 'icon' => 'dashboard'],
            ['href' => $base . '/ministries', 'label' => 'Ministries', 'icon' => 'ministry'],
            ['href' => $base . '/calendar', 'label' => 'Calendar', 'icon' => 'calendar'],
            ['href' => $base . '/events', 'label' => 'Events', 'icon' => 'events'],
            ['href' => $base . '/people', 'label' => 'People', 'icon' => 'people'],
        ],
        [
            ['href' => $base . '/my-schedule', 'label' => 'My schedule'],
        ],
        [],
        'Sign in',
        $base . '/login'
    ) ?>
<main id="portal-main" tabindex="-1">


    <?php if ($actor === null): ?>
        <h1 class="av-h1">Availability</h1>
        <section class="panel section">
            <h2>Sign in required</h2>
            <p class="muted">You're not signed in.</p>
            <p><a class="button" href="<?= $base ?>/login?next=<?= urlencode($base . '/availability') ?>">Sign in</a></p>
        </section>
    <?php elseif (($actor['personId'] ?? null) === null): ?>
        <h1 class="av-h1">Availability</h1>
        <section class="panel section">
            <h2>Person link required</h2>
            <p class="muted">Your portal account is not linked to a person record yet, so unavailability cannot be stored against your name.</p>
            <p><a class="button secondary" href="<?= $base ?>/">Back to dashboard</a></p>
        </section>
    <?php else: ?>
        <section class="hero">
            <div class="hero-row">
                <div>
                    <span class="hero-chip"><span class="pulse-dot"></span>Personal availability</span>
                    <h1>Manage your serving windows.</h1>
                    <p>Set your unavailability and keep the ministry calendar aware of when you are away.</p>
                </div>
            </div>
        </section>

        <section class="grid">
            <article class="panel section">
                <h2>Add unavailability</h2>
                <form id="addForm">
                    <label for="starts_on">Start date</label>
                    <input type="date" id="starts_on" name="starts_on" required>
                    <label for="ends_on">End date</label>
                    <input type="date" id="ends_on" name="ends_on" required>
                    <label for="reason">Reason (optional)</label>
                    <input type="text" id="reason" name="reason" maxlength="255" placeholder="e.g., Family vacation">
                    <div style="margin-top: 14px;">
                        <button type="submit">Save</button>
                    </div>
                    <div id="addErr" class="err" style="display:none;"></div>
                </form>
            </article>

            <article class="panel">
                <div class="section">
                    <h2>Upcoming entries</h2>
                </div>
                <ul id="entries" class="list">
                    <li class="empty">Loading…</li>
                </ul>
            </article>
        </section>
    <?php endif; ?>

    </main>
<?= portal_footer('Ekklesia', 'Campus-aware serving windows') ?>
</div>

<?php if ($actor !== null && ($actor['personId'] ?? null) !== null): ?>
<script>
(async function () {
    const base = <?= json_encode($basePath) ?>;
    const list = document.getElementById('entries');

    function fmt(d) { return d; }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    async function loadEntries() {
        const today = new Date().toISOString().slice(0, 10);
        const res = await fetch(base + '/api/availability?active_from=' + today, { credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        list.innerHTML = '';
        if (!res.ok) {
            const li = document.createElement('li');
            li.className = 'empty';
            li.textContent = 'Failed to load: ' + (data.error || res.status);
            list.appendChild(li);
            return;
        }
        if (!data.entries || data.entries.length === 0) {
            const li = document.createElement('li');
            li.className = 'empty';
            li.textContent = 'No upcoming unavailability on file.';
            list.appendChild(li);
            return;
        }
        for (const e of data.entries) {
            const li = document.createElement('li');
            const span = document.createElement('span');
            span.innerHTML = '<span class="range">' + fmt(e.startsOn) + ' → ' + fmt(e.endsOn) + '</span>'
                           + (e.reason ? ' &nbsp; <span class="reason">' + escapeHtml(e.reason) + '</span>' : '');
            const btn = document.createElement('button');
            btn.className = 'button secondary';
            btn.textContent = 'Remove';
            btn.addEventListener('click', async () => {
                if (!confirm('Remove ' + e.startsOn + ' → ' + e.endsOn + '?')) return;
                const r = await fetch(base + '/api/availability/' + encodeURIComponent(e.id), {
                    method: 'DELETE',
                    credentials: 'same-origin',
                });
                if (r.ok) loadEntries();
                else alert('Delete failed: ' + r.status);
            });
            li.appendChild(span);
            li.appendChild(btn);
            list.appendChild(li);
        }
    }

    document.getElementById('addForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const errBox = document.getElementById('addErr');
        errBox.style.display = 'none';
        const body = new URLSearchParams({
            starts_on: e.target.starts_on.value,
            ends_on:   e.target.ends_on.value,
            reason:    e.target.reason.value,
        });
        const res = await fetch(base + '/api/availability', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            errBox.textContent = data.error || ('Save failed: ' + res.status);
            errBox.style.display = 'block';
            return;
        }
        e.target.reset();
        loadEntries();
    });

    loadEntries();
})();
</script>
<?php endif; ?>
</body>
</html>
