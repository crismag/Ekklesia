<?php
/**
 * People Sign-Up — simple guest sign-up page (standalone).
 * Renders the short, mobile-first form. Submits to submit_signup.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

sg_session();
// index.php is always the start of a NEW guest — drop any in-progress record so
// pages 2/3 build on a fresh row, not the previous person's.
unset($_SESSION['sg_row_id'], $_SESSION['sg_prefill']);

$title   = (string) sg_cfg('app.title', 'Guest Sign-Up');
$church  = (string) sg_cfg('app.church_name', '');
$tagline = (string) sg_cfg('app.tagline', '');
$reasons = (array) sg_cfg('reason_options', []);
$reqReason = (bool) sg_cfg('features.require_reason', true);
$advLink   = (bool) sg_cfg('features.advanced_link', true);

// One-shot flash of validation errors + old input (set by submit_signup.php on failure).
$errors = $_SESSION['sg_errors'] ?? [];
$old    = $_SESSION['sg_old'] ?? [];
unset($_SESSION['sg_errors'], $_SESSION['sg_old']);
$ov = static function (string $k) use ($old): string { return e($old[$k] ?? ''); };
$osel = static function (string $k, string $v) use ($old): string { return (string) ($old[$k] ?? '') === $v ? ' selected' : ''; };

$csrf = sg_csrf();
$months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$thisYear = (int) date('Y');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= e($title) ?><?= $church !== '' ? ' · ' . e($church) : '' ?></title>
<link rel="stylesheet" href="assets/signup.css"><?= theme_tokens_style_block() ?>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <h1><?= e($church !== '' ? $church : $title) ?></h1>
    <?php if ($tagline !== ''): ?><p><?= e($tagline) ?></p><?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head">
      <h2><?= e($title) ?></h2>
      <p>Just a few quick details — takes under a minute.</p>
    </div>
    <div class="card-body">
      <?php if ($errors): ?>
        <div class="alert error">
          Please check the following:
          <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <form method="post" action="submit_signup.php" id="signupForm" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

        <div class="row2">
          <div class="field">
            <label for="first_name">First name <span class="req">*</span></label>
            <input type="text" id="first_name" name="first_name" autocomplete="given-name"
                   maxlength="60" required value="<?= $ov('first_name') ?>">
          </div>
          <div class="field">
            <label for="last_name">Last name <span class="req">*</span></label>
            <input type="text" id="last_name" name="last_name" autocomplete="family-name"
                   maxlength="60" required value="<?= $ov('last_name') ?>">
          </div>
        </div>

        <div class="field">
          <label for="city">Town / City <span class="req">*</span></label>
          <input type="text" id="city" name="city" autocomplete="address-level2"
                 maxlength="80" required value="<?= $ov('city') ?>">
        </div>

        <div class="field">
          <label for="reason_for_visit">Reason for your visit <?= $reqReason ? '<span class="req">*</span>' : '' ?></label>
          <select id="reason_for_visit" name="reason_for_visit" <?= $reqReason ? 'required' : '' ?>>
            <option value="">Choose one…</option>
            <?php foreach ($reasons as $r): ?>
              <option value="<?= e($r) ?>"<?= $osel('reason_for_visit', (string) $r) ?>><?= e($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="row3">
          <div class="field">
            <label for="birth_month">Birth month <span class="req">*</span></label>
            <select id="birth_month" name="birth_month" required>
              <option value="">Month…</option>
              <?php foreach ($months as $i => $m): ?>
                <option value="<?= $i + 1 ?>"<?= $osel('birth_month', (string) ($i + 1)) ?>><?= e($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="birth_year">Birth year <span class="req">*</span></label>
            <input type="number" id="birth_year" name="birth_year" inputmode="numeric"
                   min="1900" max="<?= $thisYear ?>" placeholder="e.g. 1990"
                   required value="<?= $ov('birth_year') ?>">
          </div>
          <div class="field">
            <label for="birth_day">Day</label>
            <input type="number" id="birth_day" name="birth_day" inputmode="numeric"
                   min="1" max="31" placeholder="—" value="<?= $ov('birth_day') ?>">
          </div>
        </div>

        <div class="field">
          <label for="invited_by">Invited by <span class="hint">(optional)</span></label>
          <input type="text" id="invited_by" name="invited_by" maxlength="120"
                 placeholder="Who invited you?" value="<?= $ov('invited_by') ?>">
        </div>

        <button type="submit" class="btn" id="submitBtn">Sign me in</button>
      </form>
    </div>
  </div>

  <?php if ($advLink): ?>
    <p class="foot">Have more time? <a href="advanced.php">Fill out the full form &rarr;</a></p>
  <?php endif; ?>
</div>
<script src="assets/signup.js" defer></script>
</body>
</html>
