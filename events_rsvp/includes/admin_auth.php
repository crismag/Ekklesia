<?php
/**
 * Minimal token gate for the Events RSVP admin pages (standalone).
 * Include at the top of any admin page: either returns (authorized) or renders
 * a login form and exits. Token lives in rsvp.secure.php (env RSVP_ADMIN_TOKEN).
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('rv_admin_gate')) {
    function rv_admin_gate(): void
    {
        rv_session();
        if (!empty($_SESSION['rv_admin'])) { return; }

        try { rv_admin_ensure_seed(); } catch (Throwable $ex) { error_log('[events_rsvp seed] ' . $ex->getMessage()); }

        $err = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['admin_token'])) {
            $word = trim((string) $_POST['admin_token']);
            $ok = rv_admin_valid_code($word) || rv_admin_valid_code(rv_admin_prefix() . $word);
            if ($ok) {
                session_regenerate_id(true);
                $_SESSION['rv_admin'] = true;
                rv_csrf_rotate();
                header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? 'admin_attendance.php'), '?'));
                exit;
            }
            $err = 'Incorrect or expired access code.';
        }

        header('Content-Type: text/html; charset=utf-8');
        $csrf = rv_csrf();
        $prefix = rv_admin_prefix();
        ?><!doctype html><html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex"><title>Admin sign-in</title>
        <link rel="stylesheet" href="assets/rsvp.css"><?= theme_tokens_style_block() ?>
        <style>.pfx{display:flex;align-items:stretch}.pfx .p{display:flex;align-items:center;padding:0 10px;background:#e8f1fb;border:1.5px solid #c5d2de;border-right:0;border-radius:10px 0 0 10px;font-weight:800;color:#0f4e97;white-space:nowrap}.pfx input{border-radius:0 10px 10px 0!important}</style>
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

    function rv_admin_csrf_ok(): bool { return rv_csrf_ok($_POST['csrf'] ?? null); }
}
