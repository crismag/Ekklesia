<?php
/**
 * The screen around the printable sheet.
 *
 * Everything outside .ms-sheet is scaffolding: a date to change, what the
 * builder found, what it is worried about, and a Print button. All of it
 * disappears under @media print, because what comes out of the printer must be
 * the publication and nothing else — no navigation, no buttons, no summary.
 *
 * The sheet is shown at its real physical size and scaled down to fit narrow
 * screens, rather than reflowed. A preview that rearranges itself is not a
 * preview of anything.
 *
 * @var \App\Documents\MinistryScheduleDocument $document
 * @var list<list<array<string,mixed>>> $columns
 * @var bool $overflows
 * @var string $base
 * @var string $date
 * @var array<string,mixed>|null $actor
 * @var list<array<string,mixed>> $campuses
 */
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$warn = $document->warningsOfLevel(\App\Documents\MinistryScheduleDocument::LEVEL_WARN);
$info = $document->warningsOfLevel(\App\Documents\MinistryScheduleDocument::LEVEL_INFO);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($document->title) ?> — <?= $e($document->displayDate) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
<?= file_get_contents(__DIR__ . '/classic.css') ?>

/* ---- preview scaffolding (screen only) --------------------------------- */
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
  background: #eef1f6;
  font: 14px/1.5 Archivo, Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
  color: #12224f;
  -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.pv-bar {
  display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
  padding: 14px 18px; background: #fff; border-bottom: 1px solid #d7e0ee;
  position: sticky; top: 0; z-index: 10;
}
.pv-bar h1 { margin: 0; font-size: 16px; font-weight: 800; flex: 1 1 auto; }
.pv-bar h1 span { display: block; font-size: 12px; font-weight: 600; color: #5d6f96; }
.pv-field { display: flex; flex-direction: column; gap: 3px; }
.pv-field label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #5d6f96; }
.pv-field input, .pv-field select {
  font: inherit; font-size: 13px; padding: 6px 9px; min-height: 36px;
  border: 1px solid #c3d1e6; border-radius: 6px; background: #fff; color: inherit;
}
.pv-btn {
  display: inline-flex; align-items: center; justify-content: center;
  min-height: 36px; padding: 7px 16px; border-radius: 6px; border: 1px solid transparent;
  font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none;
}
.pv-btn--primary { background: #13307c; color: #fff; }
.pv-btn--ghost { background: #fff; color: #13307c; border-color: #c3d1e6; }
.pv-btn:focus-visible, .pv-field :focus-visible { outline: 2px solid #1e4fd8; outline-offset: 1px; }

.pv-summary { padding: 12px 18px; background: #fff; border-bottom: 1px solid #d7e0ee; }
.pv-stats { display: flex; flex-wrap: wrap; gap: 18px; margin: 0 0 6px; padding: 0; list-style: none; font-size: 13px; }
.pv-stats b { font-size: 17px; font-weight: 800; display: block; line-height: 1.1; }
.pv-stats span { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #5d6f96; font-weight: 700; }
.pv-notes { margin: 0; padding: 0; list-style: none; display: grid; gap: 3px; font-size: 12.5px; }
.pv-notes li { display: flex; gap: 7px; align-items: baseline; }
.pv-notes .pv-tag { flex: 0 0 auto; font-size: 10.5px; font-weight: 800; text-transform: uppercase;
  letter-spacing: .05em; padding: 1px 7px; border-radius: 999px; min-height: 18px; }
.pv-warn .pv-tag { background: #fdeee0; color: #8a4b12; }
.pv-info .pv-tag { background: #e7effb; color: #13307c; }
.pv-ok { font-size: 12.5px; color: #17663f; font-weight: 700; }

/* The sheet keeps its physical proportions and is scaled, never reflowed. */
.pv-stage { padding: 22px 18px 40px; display: flex; justify-content: center; }
.pv-scaler { transform-origin: top center; }
.ms-sheet { box-shadow: 0 10px 34px rgba(18, 34, 79, .22); }

@media print {
  /* Letter portrait with no margin: the sheet supplies its own, so the
     decorative corners can sit against the paper edge. */
  @page { size: Letter portrait; margin: 0; }
  html, body { background: #fff; }
  .pv-bar, .pv-summary { display: none !important; }
  .pv-stage { padding: 0; display: block; }
  .pv-scaler { transform: none !important; width: auto !important; }
  .ms-sheet { box-shadow: none; margin: 0; width: 8.5in; min-height: 11in; }
}
</style>
</head>
<body>
<form class="pv-bar" method="get" action="<?= $e($base) ?>/schedules/ministry-print">
  <h1><?= $e($document->title) ?><span><?= $e($document->displayDate) ?></span></h1>
  <div class="pv-field">
    <label for="pvDate">Service date</label>
    <input type="date" id="pvDate" name="date" value="<?= $e($date) ?>">
  </div>
  <div class="pv-field">
    <label for="pvTemplate">Template</label>
    <select id="pvTemplate" name="template">
      <option value="classic">Classic Ministry</option>
    </select>
  </div>
  <button class="pv-btn pv-btn--ghost" type="submit">Update</button>
  <a class="pv-btn pv-btn--ghost" href="<?= $e($base) ?>/schedules">Back to schedules</a>
  <button class="pv-btn pv-btn--primary" type="button" id="pvPrint">Print</button>
</form>

<section class="pv-summary" aria-label="Schedule summary">
  <ul class="pv-stats">
    <li><b><?= (int) $document->stats['ministries'] ?></b><span>Ministries</span></li>
    <li><b><?= (int) $document->stats['assignments'] ?></b><span>Assignments</span></li>
    <li><b><?= (int) $document->stats['volunteers'] ?></b><span>Volunteers</span></li>
  </ul>
  <?php if ($warn === [] && $info === []): ?>
    <p class="pv-ok">✓ Nothing needs attention.</p>
  <?php else: ?>
    <ul class="pv-notes">
      <?php foreach ($warn as $w): ?>
        <li class="pv-warn"><span class="pv-tag">Check</span><span><?= $e($w['message']) ?></span></li>
      <?php endforeach; ?>
      <?php foreach ($info as $w): ?>
        <!-- Serving twice on a Sunday is normal. Said, never treated as an
             error, and never a reason to stop somebody printing. -->
        <li class="pv-info"><span class="pv-tag">Note</span><span><?= $e($w['message']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($overflows): ?>
    <p class="pv-notes pv-warn" style="margin-top:6px"><span class="pv-tag">Check</span>
      This schedule has more on it than the Classic sheet holds at a readable size.
      It continues onto a second page rather than being shrunk.</p>
  <?php endif; ?>
</section>

<div class="pv-stage">
  <div class="pv-scaler" id="pvScaler">
    <?php require __DIR__ . '/classic.php'; ?>
  </div>
</div>

<script>
(function () {
  'use strict';
  document.getElementById('pvPrint')?.addEventListener('click', function () { window.print(); });

  // Scale the sheet to the window without reflowing it. A preview that
  // rearranges itself to fit the screen is not a preview of the printed page.
  var scaler = document.getElementById('pvScaler');
  var stage = scaler?.parentElement;
  function fit() {
    if (!scaler || !stage) return;
    scaler.style.transform = 'none';
    scaler.style.width = '';
    var sheet = scaler.querySelector('.ms-sheet');
    if (!sheet) return;
    var natural = sheet.getBoundingClientRect().width;
    var room = stage.clientWidth - 36;
    var scale = Math.min(1, room / natural);
    scaler.style.transform = 'scale(' + scale + ')';
    // The transform does not change layout size, so the page would keep the
    // full-width scrollbar without this.
    scaler.style.width = natural + 'px';
    scaler.style.height = (sheet.getBoundingClientRect().height * scale) + 'px';
  }
  fit();
  window.addEventListener('resize', fit);
  // Undo the scale for the print snapshot, and put it back afterwards.
  window.addEventListener('beforeprint', function () { if (scaler) { scaler.style.transform = 'none'; scaler.style.height = ''; } });
  window.addEventListener('afterprint', fit);
})();
</script>
</body>
</html>
