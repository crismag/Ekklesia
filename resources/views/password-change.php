<?php
/**
 * @var string                       $basePath
 * @var ?array<string, mixed>        $actor
 *
 * Mandatory password change page. Hit by the login flow when the API
 * returns mustChangePassword=true (i.e. the user just signed in with the
 * default ChristLike#<FNI><LNI>#2026! password). Once they pick a new
 * password and the API clears the flag, they're redirected to ?next=.
 */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$next = isset($_GET['next']) ? (string) $_GET['next'] : ($basePath . '/');
$nextEsc = htmlspecialchars($next, ENT_QUOTES, 'UTF-8');
$displayName = htmlspecialchars((string) ($actor['displayName'] ?? 'Friend'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Set a new password — Church Portal</title>
    <style>
        /* Self-contained theme tokens so this auth-flow page always renders
           correctly even when the DB-backed theme service is unavailable.
           Previously these were undefined here, which made every input border
           and the submit button invisible (undefined var -> invalid rule). */
        :root{--ink:#17211b;--muted:#66756d;--line:#d9e4dd;--teal:#117b6d;
              --soft:#eef4f0;--bg:#f7faf8;--gradient-top:#0c2f28;--gradient-mid:#123b31}
                *{box-sizing:border-box}
        body{margin:0;font:14px/1.5 Inter,ui-sans-serif,system-ui,sans-serif;color:var(--ink);background:linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 220px,var(--bg) 221px)}
        .shell{max-width:520px;margin:60px auto;padding:0 16px}
        .panel{background:#fff;border:1px solid var(--line);border-radius:10px;box-shadow:0 16px 42px rgba(27,50,40,.12);padding:24px}
        h1{margin:0 0 6px;color:#fff;font-size:24px}
        .sub{color:rgba(255,255,255,.78);margin:0 0 18px}
        h2{margin:0 0 12px;font-size:18px}
        label{display:block;margin:14px 0 4px;font-weight:700;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        input[type=password]{width:100%;padding:11px 12px;border:1.5px solid #9fb3a8;border-radius:8px;font:inherit;background:#fbfdfc;color:var(--ink);transition:border-color .15s,box-shadow .15s}
        input[type=password]:hover{border-color:#7d9689}
        input[type=password]:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(17,123,109,.18)}
        button{margin-top:18px;width:100%;background:var(--teal);color:var(--on-teal);border:0;border-radius:8px;padding:13px 14px;font:inherit;font-size:15px;font-weight:800;cursor:pointer;box-shadow:0 2px 6px rgba(17,123,109,.28)}
        button:hover{background:#0e6a5e}
        button:disabled{opacity:.6;cursor:wait}
        .err{background:#fff5f5;border:1px solid #f0c4c4;color:#7a2222;padding:10px 12px;border-radius:8px;margin-top:8px;font-size:13px}
        .ok{background:#f1f9f3;border:1px solid #bee3c8;color:#1f6135;padding:10px 12px;border-radius:8px;margin-top:8px;font-size:13px}
        .helper{color:var(--muted);font-size:12px;margin:14px 0 0}
        code{background:var(--soft);padding:2px 6px;border-radius:4px;font-size:12px}
    </style>
</head>
<body>
<main>

<div class="shell">
    <h1>Set a new password</h1>
    <p class="sub">Hi, <?= $displayName ?>. Pick a password before you continue — your first-time default expires after this step.</p>

    <form class="panel" id="pwForm">
        <h2>New password</h2>
        <div id="msg"></div>

        <label for="currentPassword">Current password</label>
        <input type="password" id="currentPassword" name="currentPassword" autocomplete="current-password" required>

        <label for="newPassword">New password</label>
        <input type="password" id="newPassword" name="newPassword" autocomplete="new-password" minlength="10" required>

        <label for="confirmPassword">Confirm new password</label>
        <input type="password" id="confirmPassword" name="confirmPassword" autocomplete="new-password" minlength="10" required>

        <button type="submit" id="submitBtn">Update password</button>
        <p class="helper">Use at least 10 characters. Mix letters, numbers, and a symbol or two — avoid reusing other site passwords.</p>
    </form>
</div>

<script>
const basePath = <?= json_encode($basePath) ?>;
const next     = <?= json_encode($next) ?>;
const form = document.getElementById('pwForm');
const msg  = document.getElementById('msg');
const btn  = document.getElementById('submitBtn');

function setMsg(text, kind) {
    msg.innerHTML = text ? '<div class="' + (kind || 'err') + '">' + text + '</div>' : '';
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const cur = form.currentPassword.value;
    const nxt = form.newPassword.value;
    const cnf = form.confirmPassword.value;
    if (nxt !== cnf) {
        setMsg('New password and confirmation do not match.');
        return;
    }
    btn.disabled = true;
    setMsg('');
    try {
        const res = await fetch(basePath + '/api/auth/password', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ currentPassword: cur, newPassword: nxt }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            setMsg((data && data.error) || ('Password update failed (' + res.status + ').'));
            btn.disabled = false;
            return;
        }
        setMsg('Password updated. Continuing…', 'ok');
        setTimeout(() => { window.location = next || (basePath + '/'); }, 700);
    } catch (err) {
        setMsg('Network error. Please try again.');
        btn.disabled = false;
    }
});
</script>
</main>
</body>
</html>
