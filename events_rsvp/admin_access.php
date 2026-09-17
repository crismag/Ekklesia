<?php
/**
 * Events RSVP — admin access code manager (token-gated).
 * Set or generate the WORD ID and its validity window (default 1 week).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
rv_admin_gate();

$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!rv_admin_csrf_ok()) {
        $flash = 'Session expired — please retry.';
    } else {
        $days = (int) ($_POST['days'] ?? 7);
        $note = rv_str($_POST['note'] ?? '');
        $word = ($_POST['action'] ?? '') === 'generate' ? rv_admin_gen_word() : rv_str($_POST['word'] ?? '');
        try {
            rv_admin_set_word($word, $days, $note !== '' ? $note : null);
            $flash = 'New access code saved.';
        } catch (Throwable $ex) {
            error_log('[events_rsvp access] ' . $ex->getMessage());
            $flash = 'Could not save the code.';
        }
    }
    $_SESSION['rv_access_flash'] = $flash;
    header('Location: admin_access.php');
    exit;
}
if (!empty($_SESSION['rv_access_flash'])) { $flash = (string) $_SESSION['rv_access_flash']; unset($_SESSION['rv_access_flash']); }

$active = rv_admin_active();
$prefix = rv_admin_prefix();
$csrf = rv_csrf();
$church = (string) rv_cfg('app.church_name', '');
$defDays = (int) rv_cfg('admin_access.default_days', 7);
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Access code · RSVP admin</title>
<link rel="stylesheet" href="assets/rsvp.css"><?= theme_tokens_style_block() ?>
<style>
  .code{font-size:22px;font-weight:900;letter-spacing:.5px;background:#e8f1fb;border:1px dashed #86a9d6;border-radius:10px;padding:12px 14px;color:#0f4e97;word-break:break-all;text-align:center}
  .meta{display:flex;flex-wrap:wrap;gap:8px 18px;margin:12px 0;font-size:13px;color:var(--muted)}
  .pill{display:inline-block;padding:2px 10px;border-radius:999px;font-weight:800;font-size:12px}
  .ok{background:#e6f7ec;color:#1a7a3a}.exp{background:#fdecea;color:#b3261e}
  .links a{display:inline-block;margin-right:12px}
</style></head><body>
<div class="wrap">
  <div class="brand"><h1>RSVP admin access</h1><p><?= e($church) ?></p></div>

  <?php if ($flash !== ''): ?><div class="alert" style="background:#e8f1fb;border:1px solid #bcd6f5;color:#0f4e97"><?= e($flash) ?></div><?php endif; ?>

  <div class="card"><div class="card-body">
    <label style="font-weight:800;font-size:13px">Current access code</label>
    <?php if ($active): ?>
      <div class="code"><?= e($active['code']) ?></div>
      <div class="meta">
        <span><?= $active['active'] ? '<span class="pill ok">Active</span>' : '<span class="pill exp">Expired</span>' ?></span>
        <span>WORD: <b><?= e($active['word']) ?></b></span>
        <span>Issued: <?= e(substr($active['issued_at'], 0, 16)) ?></span>
        <span>Expires: <?= e(substr($active['expires_at'], 0, 16)) ?></span>
        <?php if ($active['active']): ?><span><b><?= (int) $active['remaining_days'] ?></b> day(s) left</span><?php endif; ?>
      </div>
      <p class="hint">Share only the WORD ID with staff — the login already shows the “<?= e($prefix) ?>” prefix.</p>
    <?php else: ?>
      <div class="code">— none set —</div>
    <?php endif; ?>
  </div></div>

  <div class="card" style="margin-top:14px"><div class="card-head"><h2>Issue a new code</h2>
    <p>Set your own WORD, or generate one. Choosing a new code immediately replaces the old one.</p></div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="field">
          <label for="word">WORD ID</label>
          <input type="text" id="word" name="word" maxlength="64" placeholder="e.g. Harvest482" autocomplete="off">
          <div class="hint">Letters and numbers only. Full code becomes <b><?= e($prefix) ?>&lt;WORD&gt;</b>.</div>
        </div>
        <div class="field">
          <label for="days">Valid for (days)</label>
          <input type="number" id="days" name="days" min="1" max="365" value="<?= $defDays ?>">
        </div>
        <div class="field">
          <label for="note">Note <span class="hint">(optional)</span></label>
          <input type="text" id="note" name="note" maxlength="120" placeholder="e.g. for weekend team">
        </div>
        <div class="actions">
          <button class="btn" type="submit" name="action" value="set">Save this code</button>
          <button class="btn secondary" type="submit" name="action" value="generate">Generate a random WORD</button>
        </div>
      </form>
    </div>
  </div>

  <p class="foot links">
    <a href="admin_attendance.php">← RSVP attendance</a>
  </p>
</div></body></html>
