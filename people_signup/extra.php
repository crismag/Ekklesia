<?php
/**
 * People Sign-Up — page 3 (optional): social links, Member Type (auto-detected,
 * editable), marital toggle, and full address with a geo lookup button.
 * Continues the same staged row (single-record model). Posts to submit_signup.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

sg_session();

// Page 3 only makes sense as a continuation — need an in-progress staged row.
$rowId = (int) ($_SESSION['sg_row_id'] ?? 0);
if ($rowId <= 0) {
    header('Location: index.php');
    exit;
}

$title  = (string) sg_cfg('app.title', 'Guest Sign-Up');
$church = (string) sg_cfg('app.church_name', '');
$defCountry  = (string) sg_cfg('defaults.country', 'Canada');
$defProvince = (string) sg_cfg('defaults.province', 'Ontario');

$errors = $_SESSION['sg_errors'] ?? [];
$old    = $_SESSION['sg_old'] ?? [];
if (!$old && !empty($_SESSION['sg_prefill'])) { $old = (array) $_SESSION['sg_prefill']; }
unset($_SESSION['sg_errors'], $_SESSION['sg_old']);
$ov = static function (string $k) use ($old): string { return e($old[$k] ?? ''); };

// Member Type: use stored value, else auto-detect from birth year (+ married).
$birthYear = isset($old['birth_year']) ? (int) $old['birth_year'] : null;
$married   = (int) ($old['is_married'] ?? 0) === 1;
$mtStored  = isset($old['member_type']) && $old['member_type'] !== null ? (int) $old['member_type'] : null;
$mtDetected = sg_detect_member_type($birthYear, $married);
$mtCurrent  = $mtStored ?? $mtDetected;

$country  = ($old['country'] ?? '') !== '' ? (string) $old['country'] : $defCountry;
$province = ($old['state'] ?? '') !== '' ? (string) $old['state'] : $defProvince;

$csrf = sg_csrf();
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>A little more · <?= e($church !== '' ? $church : $title) ?></title>
<link rel="stylesheet" href="assets/signup.css"><?= theme_tokens_style_block() ?>
</head><body>
<div class="wrap">
  <div class="brand">
    <h1><?= e($church !== '' ? $church : $title) ?></h1>
    <p>Optional — helps us connect you and keep in touch.</p>
  </div>

  <div class="card">
    <div class="card-head"><h2>Address, groups &amp; socials</h2><p>Everything on this page is optional.</p></div>
    <div class="card-body">
      <?php if ($errors): ?>
        <div class="alert error">Please check the following:
          <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form method="post" action="submit_signup.php" id="signupForm" novalidate
            data-birth-year="<?= $birthYear ?: '' ?>" data-mt-detected="<?= $mtDetected ?: '' ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="mode" value="extra">

        <!-- Member Type -->
        <div class="field">
          <label for="member_type">Member type
            <span class="hint" id="mtHint"><?= $mtStored === null && $mtDetected ? '(auto-detected — adjust if needed)' : '(adjust if needed)' ?></span>
          </label>
          <select id="member_type" name="member_type">
            <option value="">—</option>
            <option value="1"<?= $mtCurrent === 1 ? ' selected' : '' ?>>Radical</option>
            <option value="2"<?= $mtCurrent === 2 ? ' selected' : '' ?>>Trailblazer</option>
            <option value="3"<?= $mtCurrent === 3 ? ' selected' : '' ?>>G&amp;A</option>
          </select>
        </div>

        <div class="field">
          <label style="display:flex;align-items:center;gap:10px;font-weight:700;cursor:pointer">
            <input type="hidden" name="is_married" value="0">
            <input type="checkbox" id="is_married" name="is_married" value="1" style="width:20px;height:20px"<?= $married ? ' checked' : '' ?>>
            I'm married
          </label>
          <div class="hint">Married adults are grouped as Trailblazer by default.</div>
        </div>

        <!-- Address -->
        <div class="field">
          <label for="zip">Postal code</label>
          <input type="text" id="zip" name="zip" autocomplete="postal-code" maxlength="20" value="<?= $ov('zip') ?>">
        </div>
        <div class="row2">
          <div class="field">
            <label for="address">Street address</label>
            <input type="text" id="address" name="address" autocomplete="address-line1" maxlength="160" value="<?= $ov('address') ?>">
          </div>
          <div class="field">
            <label for="address2">Unit / Apt <span class="hint">(optional)</span></label>
            <input type="text" id="address2" name="address2" autocomplete="address-line2" maxlength="160" value="<?= $ov('address2') ?>">
          </div>
        </div>

        <button type="button" class="btn secondary" id="geoBtn" style="margin-bottom:12px">📍 Find / verify my address</button>
        <div class="hint" id="geoStatus" style="margin:-6px 0 12px"></div>

        <div class="row2">
          <div class="field">
            <label for="city">Town / City</label>
            <input type="text" id="city" name="city" autocomplete="address-level2" maxlength="80" value="<?= $ov('city') ?>">
          </div>
          <div class="field">
            <label for="state">Province / State</label>
            <input type="text" id="state" name="state" autocomplete="address-level1" maxlength="60" value="<?= e($province) ?>">
          </div>
        </div>
        <div class="field">
          <label for="country">Country</label>
          <input type="text" id="country" name="country" autocomplete="country-name" maxlength="60" value="<?= e($country) ?>">
        </div>
        <input type="hidden" name="latitude" id="latitude" value="<?= $ov('latitude') ?>">
        <input type="hidden" name="longitude" id="longitude" value="<?= $ov('longitude') ?>">

        <!-- Socials -->
        <div class="field">
          <label for="facebook">Facebook <span class="hint">(optional)</span></label>
          <input type="text" id="facebook" name="facebook" maxlength="120" placeholder="Profile URL or handle" value="<?= $ov('facebook') ?>">
        </div>
        <div class="row2">
          <div class="field">
            <label for="linkedin">LinkedIn <span class="hint">(optional)</span></label>
            <input type="text" id="linkedin" name="linkedin" maxlength="120" value="<?= $ov('linkedin') ?>">
          </div>
          <div class="field">
            <label for="twitter">X <span class="hint">(Twitter, optional)</span></label>
            <input type="text" id="twitter" name="twitter" maxlength="120" value="<?= $ov('twitter') ?>">
          </div>
        </div>

        <button type="submit" class="btn" id="submitBtn">Save details</button>
      </form>
    </div>
  </div>
  <p class="foot"><a href="index.php?new=1">Done — start a new sign-up</a></p>
</div>
<script src="assets/signup.js" defer></script>
</body></html>
