<?php
/**
 * People Sign-Up — advanced form (standalone). Full ChurchCRM-style fields.
 * Posts to submit_signup.php with mode=advanced. Same review-table destination.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

sg_session();
$title   = (string) sg_cfg('app.title', 'Guest Sign-Up');
$church  = (string) sg_cfg('app.church_name', '');
$reasons = (array) sg_cfg('reason_options', []);
$reqReason = (bool) sg_cfg('features.require_reason', true);

$errors = $_SESSION['sg_errors'] ?? [];
$old    = $_SESSION['sg_old'] ?? [];
// Coming from a successful quick sign-up ("Add more details") — pre-fill the form.
if (!$old && !empty($_SESSION['sg_prefill'])) { $old = (array) $_SESSION['sg_prefill']; }
unset($_SESSION['sg_errors'], $_SESSION['sg_old'], $_SESSION['sg_prefill']);
$ov   = static function (string $k) use ($old): string { return e($old[$k] ?? ''); };
$osel = static function (string $k, string $v) use ($old): string { return (string) ($old[$k] ?? '') === $v ? ' selected' : ''; };

$csrf = sg_csrf();
$months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$thisYear = (int) date('Y');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Full sign-up · <?= e($church !== '' ? $church : $title) ?></title>
<link rel="stylesheet" href="assets/signup.css"><?= theme_tokens_style_block() ?>
</head><body>
<div class="wrap">
  <div class="brand">
    <h1><?= e($church !== '' ? $church : $title) ?></h1>
    <p>Tell us a bit more so we can welcome you well.</p>
  </div>

  <div class="card">
    <div class="card-head"><h2>Full sign-up</h2><p>Only name, town/city and birth month &amp; year are required.</p></div>
    <div class="card-body">
      <?php if ($errors): ?>
        <div class="alert error">Please check the following:
          <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form method="post" action="submit_signup.php" id="signupForm" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="mode" value="advanced">

        <div class="row2">
          <div class="field"><label for="first_name">First name <span class="req">*</span></label>
            <input type="text" id="first_name" name="first_name" autocomplete="given-name" maxlength="60" required value="<?= $ov('first_name') ?>"></div>
          <div class="field"><label for="last_name">Last name <span class="req">*</span></label>
            <input type="text" id="last_name" name="last_name" autocomplete="family-name" maxlength="60" required value="<?= $ov('last_name') ?>"></div>
        </div>
        <div class="row2">
          <div class="field"><label for="middle_name">Middle name <span class="hint">(optional)</span></label>
            <input type="text" id="middle_name" name="middle_name" maxlength="60" value="<?= $ov('middle_name') ?>"></div>
          <div class="field"><label for="nick_name">Preferred / nickname <span class="hint">(optional)</span></label>
            <input type="text" id="nick_name" name="nick_name" maxlength="60" value="<?= $ov('nick_name') ?>"></div>
        </div>

        <div class="field"><label for="gender">Gender <span class="hint">(optional)</span></label>
          <select id="gender" name="gender">
            <option value=""></option>
            <option value="1"<?= $osel('gender', '1') ?>>Male</option>
            <option value="2"<?= $osel('gender', '2') ?>>Female</option>
          </select></div>

        <div class="row2">
          <div class="field"><label for="email">Email <span class="hint">(optional)</span></label>
            <input type="email" id="email" name="email" autocomplete="email" maxlength="120" value="<?= $ov('email') ?>"></div>
          <div class="field"><label for="phone">Phone <span class="hint">(optional)</span></label>
            <input type="tel" id="phone" name="phone" autocomplete="tel" maxlength="40" value="<?= $ov('phone') ?>"></div>
        </div>

        <div class="field"><label for="address">Street address <span class="hint">(optional)</span></label>
          <input type="text" id="address" name="address" autocomplete="address-line1" maxlength="160" value="<?= $ov('address') ?>"></div>
        <div class="row2">
          <div class="field"><label for="city">Town / City <span class="req">*</span></label>
            <input type="text" id="city" name="city" autocomplete="address-level2" maxlength="80" required value="<?= $ov('city') ?>"></div>
          <div class="field"><label for="state">State / Province <span class="hint">(optional)</span></label>
            <input type="text" id="state" name="state" autocomplete="address-level1" maxlength="60" value="<?= $ov('state') ?>"></div>
        </div>
        <div class="row2">
          <div class="field"><label for="zip">Postal code <span class="hint">(optional)</span></label>
            <input type="text" id="zip" name="zip" autocomplete="postal-code" maxlength="20" value="<?= $ov('zip') ?>"></div>
          <div class="field"><label for="country">Country <span class="hint">(optional)</span></label>
            <input type="text" id="country" name="country" autocomplete="country-name" maxlength="60" value="<?= $ov('country') ?>"></div>
        </div>

        <div class="row3">
          <div class="field"><label for="birth_month">Birth month <span class="req">*</span></label>
            <select id="birth_month" name="birth_month" required><option value="">Month…</option>
              <?php foreach ($months as $i => $m): ?><option value="<?= $i + 1 ?>"<?= $osel('birth_month', (string) ($i + 1)) ?>><?= e($m) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label for="birth_year">Birth year <span class="req">*</span></label>
            <input type="number" id="birth_year" name="birth_year" inputmode="numeric" min="1900" max="<?= $thisYear ?>" required value="<?= $ov('birth_year') ?>"></div>
          <div class="field"><label for="birth_day">Day</label>
            <input type="number" id="birth_day" name="birth_day" inputmode="numeric" min="1" max="31" value="<?= $ov('birth_day') ?>"></div>
        </div>

        <div class="field"><label for="reason_for_visit">Reason for your visit <?= $reqReason ? '<span class="req">*</span>' : '' ?></label>
          <select id="reason_for_visit" name="reason_for_visit" <?= $reqReason ? 'required' : '' ?>>
            <option value="">Choose one…</option>
            <?php foreach ($reasons as $r): ?><option value="<?= e($r) ?>"<?= $osel('reason_for_visit', (string) $r) ?>><?= e($r) ?></option><?php endforeach; ?>
          </select></div>

        <div class="field"><label for="invited_by">Invited by <span class="hint">(optional)</span></label>
          <input type="text" id="invited_by" name="invited_by" maxlength="120" value="<?= $ov('invited_by') ?>"></div>
        <div class="field"><label for="church_background">Church background <span class="hint">(optional)</span></label>
          <input type="text" id="church_background" name="church_background" maxlength="255" placeholder="Current/previous church, if any" value="<?= $ov('church_background') ?>"></div>
        <div class="field"><label for="visit_notes">Anything else? <span class="hint">(optional)</span></label>
          <textarea id="visit_notes" name="visit_notes" maxlength="255"><?= $ov('visit_notes') ?></textarea></div>

        <button type="submit" class="btn" id="submitBtn">Submit</button>
      </form>
    </div>
  </div>
  <p class="foot"><a href="index.php">&larr; Back to the quick form</a></p>
</div>
<script src="assets/signup.js" defer></script>
</body></html>
