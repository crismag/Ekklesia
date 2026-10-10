<?php
/**
 * Events RSVP — confirmation page (standalone).
 * Reads the one-shot confirmation payload set by submit_rsvp.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

rv_session();
$last = $_SESSION['rv_last'] ?? null;
unset($_SESSION['rv_last']);

$church = (string) rv_cfg('app.church_name', '');
$sTitle = (string) rv_cfg('messages.success_title', "You're registered!");
$sBody  = (string) rv_cfg('messages.success_body', 'Thank you for your RSVP.');

$statusLabel = [
    'yes'   => "Yes — I'll be there",
    'maybe' => 'Maybe',
    'no'    => "Can't make it",
];

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

$headline = $last && ($last['status'] ?? '') === 'no'
    ? 'Thanks for letting us know'
    : $sTitle;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>RSVP received · <?= e($church !== '' ? $church : 'Event RSVP') ?></title>
<link rel="stylesheet" href="assets/rsvp.css"><?= theme_tokens_style_block() ?>
</head>
<body>
<div class="wrap">
  <div class="brand"><h1><?= e($church !== '' ? $church : 'Thank you') ?></h1></div>
  <div class="card">
    <div class="success">
      <div class="mark">&#10003;</div>
      <h2><?= e($headline) ?></h2>
      <?php if ($last): ?>
        <p><?= e(($last['name'] !== '' ? $last['name'] . ', ' : '') . $sBody) ?></p>
        <div class="summary">
          <div class="r"><span>Event</span><span><?= e($last['event_title']) ?></span></div>
          <?php if (!empty($last['event_date'])): ?><div class="r"><span>Date</span><span><?= e($fmtDate($last['event_date'])) ?></span></div><?php endif; ?>
          <?php if (!empty($last['event_time'])): ?><div class="r"><span>Time</span><span><?= e($fmtTime($last['event_time'])) ?></span></div><?php endif; ?>
          <?php if (!empty($last['location'])): ?><div class="r"><span>Where</span><span><?= e($last['location']) ?></span></div><?php endif; ?>
          <div class="r"><span>Your RSVP</span><span><?= e($statusLabel[$last['status']] ?? $last['status']) ?></span></div>
          <?php if ((int) ($last['party'] ?? 1) > 1): ?><div class="r"><span>Party size</span><span><?= (int) $last['party'] ?></span></div><?php endif; ?>
        </div>
      <?php else: ?>
        <p>Your RSVP has been received. Thank you!</p>
      <?php endif; ?>
      <p class="foot">You can close this page — we've saved your response.</p>
    </div>
  </div>
</div>
</body>
</html>
