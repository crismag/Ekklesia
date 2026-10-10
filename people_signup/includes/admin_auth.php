<?php
/**
 * Minimal token gate for the People Sign-Up admin pages (standalone).
 * Include this at the very top of any admin page. It either returns (authorized)
 * or renders a login form and exits. The token lives in signup.secure.php
 * (env SIGNUP_ADMIN_TOKEN), never in JSON or the URL history once logged in.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('sg_admin_gate')) {
    function sg_admin_gate(): void
    {
        sg_session();
        if (!empty($_SESSION['sg_admin'])) { return; }

        // Make sure there's always a usable code (auto-seeds a default the first time).
        try { sg_admin_ensure_seed(); } catch (Throwable $ex) { error_log('[people_signup seed] ' . $ex->getMessage()); }

        $err = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['admin_token'])) {
            // The login field takes the WORD; we prepend the fixed prefix. The full
            // code (or the master recovery key) is also accepted as-is.
            $word = trim((string) $_POST['admin_token']);
            $candidates = [$word, sg_admin_prefix() . $word];
            $ok = false;
            foreach ($candidates as $c) { if (sg_admin_valid_code($c)) { $ok = true; break; } }
            if ($ok) {
                session_regenerate_id(true);
                $_SESSION['sg_admin'] = true;
                sg_csrf_rotate();
                header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? 'admin_review.php'), '?'));
                exit;
            }
            $err = 'Incorrect or expired access code.';
        }

        header('Content-Type: text/html; charset=utf-8');
        $csrf = sg_csrf();
        $prefix = sg_admin_prefix();
        ?><!doctype html><html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex"><title>Admin sign-in</title>
        <link rel="stylesheet" href="assets/signup.css"><?= theme_tokens_style_block() ?>
        <style>.pfx{display:flex;align-items:stretch}.pfx .p{display:flex;align-items:center;padding:0 10px;background:var(--soft,#eef4f0);border:1.5px solid #c7d4cd;border-right:0;border-radius:10px 0 0 10px;font-weight:800;color:#0c5a45;white-space:nowrap}.pfx input{border-radius:0 10px 10px 0!important}</style>
        </head><body>
        <div class="wrap"><div class="brand"><h1>Admin sign-in</h1></div>
        <div class="card"><div class="card-body">
        <?php if ($err !== ''): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <div class="field"><label for="admin_token">Access code</label>
          <div class="pfx"><span class="p"><?= e($prefix) ?></span>
          <input type="password" id="admin_token" name="admin_token" autocomplete="off" autofocus placeholder="WORD ID"></div>
          <div class="hint">Enter the current WORD ID. It changes periodically.</div></div>
          <button class="btn" type="submit">Enter</button>
        </form>
        </div></div></div></body></html><?php
        exit;
    }

    /** True when the posted CSRF token is valid — use before any admin mutation. */
    function sg_admin_csrf_ok(): bool { return sg_csrf_ok($_POST['csrf'] ?? null); }
}
