<?php
/**
 * Events RSVP — RSVP form for a single event (standalone).
 * The event is always chosen by URL (?event_id=123) and loaded server-side —
 * never selected by the guest. Submits to submit_rsvp.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

rv_session();
$church = (string) rv_cfg('app.church_name', '');
$title  = (string) rv_cfg('app.title', 'Event RSVP');

$eventId = (int) ($_GET['event_id'] ?? 0);
$event   = null;
if ($eventId > 0) {
    try {
        $event = rv_load_event(rv_db(), $eventId);
    } catch (Throwable $ex) {
        error_log('[events_rsvp] event load failed: ' . $ex->getMessage());
        $event = null;
    }
}

$fields   = (array) rv_cfg('fields', []);
$allowMaybe = (bool) rv_cfg('features.allow_maybe', true);
$csrf = rv_csrf();

// Flash of validation errors + old input.
$errors = $_SESSION['rv_errors'] ?? [];
$old    = $_SESSION['rv_old'] ?? [];
unset($_SESSION['rv_errors'], $_SESSION['rv_old']);
$ov   = static function (string $k) use ($old): string { return e($old[$k] ?? ''); };
$osel = static function (string $k, string $v) use ($old): string { return (string) ($old[$k] ?? '') === $v ? ' selected' : ''; };
$ochk = static function (string $k, string $v, string $fallback) use ($old): string {
    $cur = (string) ($old[$k] ?? $fallback);
    return $cur === $v ? ' checked' : '';
};

$months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$thisYear = (int) date('Y');

$fmtDate = static function (?string $d): string {
    if (!$d) { return ''; }
    $ts = strtotime($d);
    return $ts ? date('l, F j, Y', $ts) : $d;
};
$fmtTime = static function (?string $t): string {
    if (!$t) { return ''; }
    $ts = strtotime($t);
    return $ts ? date('g:i A', $ts) : $t;
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= e($event['title'] ?? $title) ?><?= $church !== '' ? ' · ' . e($church) : '' ?></title>
<link rel="stylesheet" href="assets/rsvp.css"><?= theme_tokens_style_block() ?>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <h1><?= e($church !== '' ? $church : $title) ?></h1>
    <p><?= e($title) ?></p>
  </div>

  <div class="card">
  <?php if ($event === null): ?>
    <div class="notice">
      <h2>Event not available</h2>
      <p>We couldn't find this event, or it has no upcoming date. Please check the link from your invitation, or contact the church office.</p>
    </div>
  <?php else: ?>
    <div class="card-head">
      <h2><?= e($event['title']) ?></h2>
      <div class="event-meta">
        <?php if (!empty($event['date'])): ?><div class="mi">&#128197; <b><?= e($fmtDate($event['date'])) ?></b></div><?php endif; ?>
        <?php if (!empty($event['time'])): ?><div class="mi">&#128336; <?= e($fmtTime($event['time'])) ?></div><?php endif; ?>
        <?php if (!empty($event['location'])): ?><div class="mi">&#128205; <?= e($event['location']) ?></div><?php endif; ?>
      </div>
      <?php if (!empty($event['description'])): ?><p><?= e($event['description']) ?></p><?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($errors): ?>
        <div class="alert error">
          Please check the following:
          <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <form method="post" action="submit_rsvp.php" id="rsvpForm" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
        <input type="hidden" name="event_source" value="<?= e($event['source']) ?>">

        <div class="field">
          <label>Will you be joining us? <span class="req">*</span></label>
          <div class="seg<?= $allowMaybe ? '' : ' two' ?>">
            <input type="radio" id="rs_yes" name="rsvp_status" value="yes"<?= $ochk('rsvp_status', 'yes', 'yes') ?>>
            <label for="rs_yes">Yes, I'll be there</label>
            <?php if ($allowMaybe): ?>
              <input type="radio" id="rs_maybe" name="rsvp_status" value="maybe"<?= $ochk('rsvp_status', 'maybe', 'yes') ?>>
              <label for="rs_maybe">Maybe</label>
            <?php endif; ?>
            <input type="radio" id="rs_no" name="rsvp_status" value="no"<?= $ochk('rsvp_status', 'no', 'yes') ?>>
            <label for="rs_no">Can't make it</label>
          </div>
        </div>

        <div class="row2">
          <div class="field">
            <label for="first_name">First name <span class="req">*</span></label>
            <input type="text" id="first_name" name="first_name" autocomplete="given-name" maxlength="60" required value="<?= $ov('first_name') ?>">
          </div>
          <div class="field">
            <label for="last_name">Last name <span class="req">*</span></label>
            <input type="text" id="last_name" name="last_name" autocomplete="family-name" maxlength="60" required value="<?= $ov('last_name') ?>">
          </div>
        </div>

        <?php if (!empty($fields['email'])): ?>
        <div class="field">
          <label for="email">Email <span class="hint">(so we can confirm)</span></label>
          <input type="email" id="email" name="email" autocomplete="email" maxlength="120" value="<?= $ov('email') ?>">
        </div>
        <?php endif; ?>

        <?php if (!empty($fields['phone']) || !empty($fields['city'])): ?>
        <div class="row2">
          <?php if (!empty($fields['phone'])): ?>
          <div class="field">
            <label for="phone">Phone <span class="hint">(optional)</span></label>
            <input type="tel" id="phone" name="phone" autocomplete="tel" maxlength="40" value="<?= $ov('phone') ?>">
          </div>
          <?php endif; ?>
          <?php if (!empty($fields['city'])): ?>
          <div class="field">
            <label for="city">Town / City <span class="hint">(optional)</span></label>
            <input type="text" id="city" name="city" autocomplete="address-level2" maxlength="80" value="<?= $ov('city') ?>">
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($fields['birth_for_matching'])): ?>
        <div class="row2">
          <div class="field">
            <label for="birth_month">Birth month <span class="hint">(helps us find you)</span></label>
            <select id="birth_month" name="birth_month">
              <option value="">—</option>
              <?php foreach ($months as $i => $m): ?>
                <option value="<?= $i + 1 ?>"<?= $osel('birth_month', (string) ($i + 1)) ?>><?= e($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="birth_year">Birth year <span class="hint">(optional)</span></label>
            <input type="number" id="birth_year" name="birth_year" inputmode="numeric" min="1900" max="<?= $thisYear ?>" placeholder="e.g. 1990" value="<?= $ov('birth_year') ?>">
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($fields['party_count'])): ?>
        <div class="field">
          <label for="party_count">How many in your party?</label>
          <div class="stepper">
            <button type="button" data-step="-1" aria-label="Fewer">&minus;</button>
            <input type="number" id="party_count" name="party_count" inputmode="numeric" min="1" max="50" value="<?= e($old['party_count'] ?? '1') ?>">
            <button type="button" data-step="1" aria-label="More">+</button>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($fields['notes'])): ?>
        <div class="field">
          <label for="notes">Anything we should know? <span class="hint">(optional)</span></label>
          <textarea id="notes" name="notes" maxlength="255" placeholder="Accessibility needs, questions, etc."><?= $ov('notes') ?></textarea>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn" id="submitBtn">Confirm my RSVP</button>
      </form>
    </div>
  <?php endif; ?>
  </div>

  <p class="foot"><?= e($church) ?></p>
</div>
<script src="assets/rsvp.js" defer></script>
</body>
</html>
