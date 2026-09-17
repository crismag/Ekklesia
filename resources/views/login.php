<?php
/**
 * @var string $error
 * @var string $next
 * @var string $basePath
 */

$errorEsc = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
$nextEsc = htmlspecialchars($next, ENT_QUOTES, 'UTF-8');
$basePathEsc = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$loginUrl = $basePathEsc . '/api/login';
require_once __DIR__ . '/_portal-shell.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Church Portal - Sign in</title>
    <style>
                * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font: 14px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: var(--ink); background:var(--bg); }
        a { color: inherit; }
        .hero { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(300px, .8fr); gap: 16px; align-items: stretch; }
        .hero-copy { color: #f8fffb; padding: 20px 0 10px; }
        .hero-chip { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 14px; padding: 6px 10px; border: 1px solid rgba(255,255,255,.22); border-radius: 999px; background: rgba(255,255,255,.1); font-size: 12px; font-weight: 900; color: #f8fffb; }
        .pulse-dot { width: 7px; height: 7px; border-radius: 50%; background: #8ef0c6; box-shadow: 0 0 0 6px rgba(142,240,198,.12); }
        h1 { margin: 0; font-size: clamp(34px, 5vw, 58px); line-height: .98; max-width: 10ch; }
        .lead { margin: 12px 0 0; color: rgba(248,255,251,.82); font-size: 16px; max-width: 650px; }
        .panel { background: var(--paper); border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 14px 34px rgba(28,48,39,.08); overflow: hidden; }
        .panel-inner { padding: 20px; }
        .panel-inner h2 { margin: 0 0 8px; font-size: 20px; }
        .muted { color: var(--muted); }
        label { display: block; margin: 12px 0 4px; font-weight: 700; }
        input[type=email], input[type=password] { width: 100%; padding: 10px 11px; border: 1px solid var(--line); border-radius: 8px; font: inherit; background: #fff; color: var(--ink); }
        button { margin-top: 16px; width: 100%; min-height: 42px; padding: 10px 12px; background: var(--deep); color: #fff; border: 0; border-radius: 8px; font-weight: 900; cursor: pointer; font: inherit; }
        .err { margin-top: 12px; padding: 8px 10px; background: #ffebe9; border: 1px solid #ffc1ba; border-radius: 8px; color: #82071e; }
        .helper { margin-top: 14px; font-size: 12px; color: var(--muted); }
        .choice-list { display: grid; gap: 8px; margin: 12px 0 0; padding: 0; border: 0; }
        .choice { display: flex; align-items: center; gap: 10px; margin: 0; min-height: 48px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font-weight: 700; cursor: pointer; }
        .choice:has(input:checked) { border-color: var(--deep); background: var(--soft, #eef4f0); }
        .choice input { min-height: 0; width: 18px; height: 18px; accent-color: var(--deep); }
        .choice:focus-within { outline: 2px solid var(--deep); outline-offset: 2px; }
        .link-btn { background: transparent; color: var(--ink); border: 1px solid var(--line); margin-top: 8px; }
        
        @media (max-width: 760px) {
            .hero { grid-template-columns: 1fr; }
            
        }
    
        /* WCAG 2.5.8 (AA): 24px minimum. Auth inputs get the full 48px. */
        input,select,button{min-height:48px}
            .hero{position:relative;isolation:isolate}
        .hero::before{content:"";position:absolute;top:-18px;bottom:0;left:calc(-1 * var(--portal-gutter,1.5rem));right:calc(-1 * var(--portal-gutter,1.5rem));
          width:auto;background:linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 100%);z-index:-1}
        
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('wide') ?>>
    <?= portal_header(
        $basePath,
        'Sign in',
        'Access portal pages, schedules, ministries, and campus-aware tools.',
        [],
        null,
        null,
        [
            ['href' => $basePathEsc . '/', 'label' => 'Dashboard', 'icon' => 'dashboard'],
            ['href' => $basePathEsc . '/ministries', 'label' => 'Ministries', 'icon' => 'ministry'],
            ['href' => $basePathEsc . '/calendar', 'label' => 'Calendar', 'icon' => 'calendar'],
            ['href' => $basePathEsc . '/events', 'label' => 'Events', 'icon' => 'events'],
            ['href' => $basePathEsc . '/people', 'label' => 'People', 'icon' => 'people'],
        ],
        [],
        [],
        'Sign in',
        $basePathEsc . '/login'
    ) ?>
<main id="portal-main" tabindex="-1">


    <section class="hero">
        <div class="hero-copy">
            <div class="hero-chip"><span class="pulse-dot"></span>Welcome back</div>
            <h1>Sign in to Church Portal.</h1>
            <p class="lead">Open the ministry browser, people directory, calendar, and schedule tools once you're authenticated.</p>
        </div>
        <form class="panel panel-inner" method="post" action="<?= $loginUrl ?>" id="loginForm">
            <h2>Account access</h2>
            <?php if ($errorEsc !== ''): ?>
                <div class="err"><?= $errorEsc ?></div>
            <?php endif; ?>
            <label for="email">Email or mobile number</label>
            <input type="text" id="email" name="email" autocomplete="username" inputmode="email" required autofocus>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <input type="hidden" name="_next" value="<?= $nextEsc ?>">
            <button type="submit">Sign in</button>
        </form>

        <form class="panel panel-inner" id="choiceForm" hidden>
            <h2 id="choiceTitle">Choose your Church Portal account</h2>
            <p class="muted" id="choiceLead"></p>
            <div class="err" id="choiceError" role="alert" hidden></div>
            <fieldset class="choice-list" id="choiceList"></fieldset>
            <button type="submit" id="choiceSubmit">Continue</button>
            <button type="button" class="link-btn" id="choiceCancel">Use a different sign-in</button>
        </form>
    </section>

    </main>
<?= portal_footer('Church Portal', 'Sign-in gateway') ?>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const form = e.currentTarget;
    const body = new URLSearchParams({
        email: form.email.value,
        password: form.password.value,
    });
    const res = await fetch(<?= json_encode($loginUrl) ?>, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body,
    });
    const data = await res.json().catch(() => ({}));
    const basePath = <?= json_encode($basePath) ?>;
    if (res.ok && data && data.choiceRequired) {
        showChoice(data, form);
        return;
    }
    if (!res.ok) {
        const msg = (data && data.error) || ('Login failed (' + res.status + ').');
        const next = encodeURIComponent(form._next.value || (basePath + '/'));
        window.location = basePath + '/login?error=' + encodeURIComponent(msg) + '&next=' + next;
        return;
    }
    finishSignIn(data, form);
});

// An email or phone can be shared by a household, so the server may ask which
// person this sign-in is. It never picks one; the user confirms or chooses.
function showChoice(data, loginForm) {
    const choiceForm = document.getElementById('choiceForm');
    const list = document.getElementById('choiceList');
    const single = data.choices.length === 1;
    const claim = data.kind === 'claim';
    document.getElementById('choiceTitle').textContent = claim && single ? 'Is this you?' : 'Choose your Church Portal account';
    document.getElementById('choiceLead').textContent = claim
        ? (single
            ? 'This email or phone is on the record below. Confirm it is you to create your login.'
            : 'This email or phone is shared by more than one person. Choose who you are; this login will belong to them from now on.')
        : 'Your password opens more than one account that uses this email or phone. Choose which one to open.';
    document.getElementById('choiceSubmit').textContent = claim && single ? 'Yes, this is me' : 'Continue';
    list.replaceChildren();
    data.choices.forEach(function (choice, i) {
        const label = document.createElement('label');
        label.className = 'choice';
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'choice';
        input.value = String(choice.id);
        input.required = true;
        if (single) { input.checked = true; }
        label.append(input, document.createTextNode(choice.name));
        list.append(label);
    });
    list.setAttribute('aria-label', 'People');
    loginForm.hidden = true;
    choiceForm.hidden = false;
    choiceForm.dataset.next = loginForm._next.value;
    (list.querySelector('input:checked') || list.querySelector('input')).focus();
}

document.getElementById('choiceCancel').addEventListener('click', function () {
    document.getElementById('choiceForm').hidden = true;
    const loginForm = document.getElementById('loginForm');
    loginForm.hidden = false;
    loginForm.password.value = '';
    loginForm.email.focus();
});

document.getElementById('choiceForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const choiceForm = e.currentTarget;
    const picked = choiceForm.querySelector('input[name=choice]:checked');
    const errorBox = document.getElementById('choiceError');
    if (!picked) { return; }
    const res = await fetch(<?= json_encode($basePath . '/api/login/choose') ?>, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: new URLSearchParams({ id: picked.value }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        errorBox.textContent = (data && data.error) || ('Sign-in failed (' + res.status + ').');
        errorBox.hidden = false;
        return;
    }
    finishSignIn(data, { _next: { value: choiceForm.dataset.next || '' } });
});

function finishSignIn(data, form) {
    const basePath = <?= json_encode($basePath) ?>;
    // First-login flow: account flagged as must-change-password.
    // Send the user to the password change page, preserving the intended destination.
    if (data && data.mustChangePassword) {
        const target = form._next.value || (basePath + '/');
        window.location = basePath + '/password/change?next=' + encodeURIComponent(target);
        return;
    }
    window.location = form._next.value || (basePath + '/my-schedule');
}
</script>
</body>
</html>
