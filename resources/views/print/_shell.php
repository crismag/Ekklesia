<?php
/**
 * The page a printable calendar is drawn on.
 *
 * Built for paper rather than adapted from the screen: @page carries the real
 * size and margins, the type scale is in points, and nothing here is a portal
 * component. The screen and the print share their *data* and nothing else,
 * which is what lets a calendar look designed rather than exported.
 *
 * @var string $title
 * @var string $orientation
 * @var string $paper
 * @var array<string,mixed> $branding
 * @var string $body
 * @var string $styles
 */
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
// Inches, portrait. One table, used for both the @page rule and the sheet's own
// box — they were separate, and the sheet's was hardcoded to Letter, so asking
// for A4 produced a Letter-shaped sheet on an A4 page.
$paperInches = \App\Services\Calendar\PrintConfig::PAPERS;
[$paperW, $paperH] = $paperInches[$paper] ?? $paperInches['letter'];
if ($orientation === 'landscape') {
    [$paperW, $paperH] = [$paperH, $paperW];
}
$size = $paperW . 'in ' . $paperH . 'in';
// The sheet fills the page inside its margins.
$sheetW = round($paperW - 0.945, 3);
$sheetH = round($paperH - 0.945, 3);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?></title>
<style>
  @page { size: <?= $size ?>; margin: 12mm; }

  /* Point sizes, not pixels: this is going on paper, where a point is a real
     unit and a pixel is a guess about somebody's screen. */
  /* The accent as a custom property as well as a literal, so a theme's own
     stylesheet can tint with it instead of hardcoding a second green. */
  :root { --accent: <?= $e($branding['accent']) ?>; <?= $e(\App\Services\Calendar\MemberTypeStyle::cssVars()) ?>; }
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  /* One scale, applied once. Every size in a template is in points and
     relative to this, so "Large" enlarges the whole document consistently
     rather than one heading at a time. */
  html { font-size: calc(16px * <?= $e(number_format($typeScale ?? 1.0, 3)) ?>); }
  body {
    font: <?= $e(number_format(10 * ($typeScale ?? 1.0), 2)) ?>pt/1.42 <?= $fontStack ?? '"Iowan Old Style", Georgia, serif' ?>;
    color: #16211c;
    background: #eef1f0;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
  }
  .sheet {
    background: #fff;
    margin: 16px auto;
    padding: 14mm;
    width: <?= $sheetW ?>in;
    min-height: <?= $sheetH ?>in;
    box-shadow: 0 2px 18px rgba(0,0,0,.18);
  }

  /* Masthead. The calendar's title leads: a sheet pinned to a noticeboard has
     to say what it is before it says which month. The church is a small line
     above it and the month sits beside it, large but second. A long title
     wraps to a second line rather than shrinking. */
  .masthead { border-bottom: 1.5pt solid <?= $e($branding['accent']) ?>; padding-bottom: 8pt; margin-bottom: 12pt; }
  .masthead-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 18pt; }
  .mh-main { min-width: 0; flex: 1 1 auto; }
  .church { font-size: 9pt; letter-spacing: .16em; text-transform: uppercase; font-weight: 700; color: <?= $e($branding['accent']) ?>; }
  .church .sep { opacity: .55; padding: 0 .35em; }
  .doc-title { margin: 3pt 0 0; font-size: 30pt; line-height: 1.04; font-weight: 700; letter-spacing: -.012em;
    text-wrap: balance; overflow-wrap: anywhere; color: #16211c; }
  .subtitle { font-size: 10pt; color: #46534c; margin-top: 3pt; }
  .period { flex: 0 0 auto; font-size: 19pt; line-height: 1.05; font-weight: 400; letter-spacing: -.005em;
    text-align: right; color: #2b3832; white-space: nowrap; }

  /* Member types. Fixed colours (MemberTypeStyle), on the rule and the symbol
     only; the name stays in ink. The symbol is what survives greyscale. */
  .k-birth { border-left-color: var(--mt-none) !important; }
  .k-birth.mt-gna { border-left-color: var(--mt-gna) !important; }
  .k-birth.mt-trailblazer { border-left-color: var(--mt-trailblazer) !important; }
  .k-birth.mt-radical { border-left-color: var(--mt-radical) !important; }
  .mt-sym { width: .95em; height: .95em; vertical-align: -.14em; margin-right: .22em; flex: 0 0 auto; }
  .mt-gna .mt-sym { color: var(--mt-gna); }
  .mt-trailblazer .mt-sym { color: var(--mt-trailblazer); }
  .mt-radical .mt-sym { color: var(--mt-radical); }
  .legend { display: flex; flex-wrap: wrap; align-items: center; gap: 3pt 14pt; margin-top: 8pt;
    font-size: 8.5pt; color: #46534c; break-inside: avoid; }
  .legend-title { font-weight: 700; letter-spacing: .08em; text-transform: uppercase; font-size: 7.5pt; }
  .legend-item { display: inline-flex; align-items: center; gap: 4pt; }
  .legend-item .mt-sym { margin: 0; width: 10pt; height: 10pt; }
  .legend-rule { display: inline-block; width: 2.5pt; height: 10pt; background: var(--mt-none); }

  .colophon { margin-top: 14pt; padding-top: 6pt; border-top: .5pt solid #c9d4ce; display: flex; justify-content: space-between; gap: 10pt; font-size: 7.5pt; color: #6b7a72; }
  /* CSS counters are the only way to number a page from inside the document.
     Supported in print by the engines that matter; absent, the span is empty
     rather than wrong. */
  .folio::after { content: counter(page); }

  /* Optional editorial regions. These exist only when they have content — the
     shell emits no element at all otherwise — so there is no empty-state rule
     here to accidentally reserve height. */
  .doc-info { margin: 0 0 10pt; font-size: 9pt; line-height: 1.45; }
  .doc-info--bottom { margin: 12pt 0 0; padding-top: 7pt; border-top: .5pt solid #c9d4ce; }
  .doc-info > :first-child { margin-top: 0; }
  .doc-info > :last-child { margin-bottom: 0; }
  .doc-info p { margin: 0 0 5pt; }
  .doc-info h3, .doc-info h4 { margin: 0 0 4pt; font-size: 10pt; letter-spacing: .02em; }
  .doc-info ul, .doc-info ol { margin: 0 0 5pt; padding-left: 14pt; }
  .doc-info li { margin: 0 0 2pt; }

  /* Curated title treatments. Typography only — the title stays real text, so
     it can be read, copied and searched, and the same artwork works for any
     month. */
  .title-editorial .church { font-size: 8.5pt; letter-spacing: .22em; }
  .title-editorial .doc-title { font-size: 34pt; font-weight: 300; letter-spacing: -.02em; }
  .title-editorial .masthead { border-bottom-width: .75pt; }
  .title-banner .masthead {
    background: <?= $e($branding['accent']) ?>; border-bottom: 0;
    padding: 9pt 10pt; margin: -4mm -4mm 14pt; color: #fff;
  }
  .title-banner .church, .title-banner .subtitle { color: rgba(255,255,255,.86); }
  .title-banner .doc-title, .title-banner .period { color: #fff; }
  .title-banner .period { font-weight: 600; }

  .empty { padding: 28pt; text-align: center; color: #6b7a72; font-style: italic; }

  @media print {
    body { background: #fff; }
    .sheet { margin: 0; padding: 0; width: auto; min-height: 0; box-shadow: none; }
    .no-print { display: none !important; }
  }
<?= $styles ?>

  /* The decorative layer, behind everything and semantically absent.
     Positioned against the sheet rather than the page so it lands in the same
     place on screen and on paper, and clipped to the sheet so no theme can
     bleed art into a printer's unprintable margin. */
  .sheet { position: relative; }
  /* A band along the foot of the sheet, between the calendar and the colophon.
     In the flow, with a height of its own — not floated over the page. Two
     earlier attempts positioned it absolutely against the sheet, and both
     failed the same way: the sheet's height is decided by its contents, so
     reserving room inside the grid simply made the sheet shorter and the band
     followed it back down onto the calendar. A band that occupies real space
     cannot overlap anything. */
  .art { height: var(--art-h, 0.62in); margin: var(--art-t, 6pt) 0 var(--art-b, 4pt);
    overflow: hidden; opacity: var(--art-o, .8); pointer-events: none; }
  .art svg { display: block; width: 100%; height: 100%; }
  /* Minimal decoration is one dial, not a panel of switches: the artwork keeps
     its anchor pieces and drops everything its author marked as secondary. */
  .art--minimal [data-art="extra"] { display: none; }
  @media print { .art { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }

  /* The theme's own sheet, last so it can override the base without either
     side resorting to !important. Classic contributes nothing here: it *is*
     the base, which is what makes it impossible for a new theme to regress it. */
<?= $themeCss ?? '' ?>
</style>
</head>
<body>
<?php
  // The document's regions, in order. Header and footer are furniture and are
  // always present; the two information regions are not, and when they are
  // absent they emit nothing at all — no wrapper, no margin, no rule. A region
  // that reserves height for nothing is the fastest way to lose a row of a
  // wall calendar.
  $show = $headerShow ?? ['church' => true, 'location' => true, 'period' => true, 'docType' => true];
  $fShow = $footerShow ?? ['printed' => true, 'website' => true, 'church' => false, 'page' => false];
  $topHtml = trim((string) ($topInfo ?? ''));
  $bottomHtml = trim((string) ($bottomInfo ?? ''));
  $titleStyle = in_array($titleStyle ?? 'classic', ['classic', 'editorial', 'banner'], true)
      ? ($titleStyle ?? 'classic') : 'classic';

  // A custom title replaces the document-type label; a subtitle sits under the
  // church name where the campus already does.
  $customTitle = trim((string) ($headerTitle ?? ''));
  $customSubtitle = trim((string) ($headerSubtitle ?? ''));
  // The title: the reader's own, else the one worked out from what is on the
  // sheet (PrintComposer::docTitle).
  $periodNote = $customTitle !== '' ? $customTitle : (string) ($docTitle ?? ($branding['period_note'] ?? ''));
?>
<div class="sheet title-<?= $e($titleStyle) ?> theme-<?= $e($theme ?? 'classic') ?> ents-<?= $e($entryDisplay ?? 'auto') ?><?= !empty($inkFriendly) ? ' is-ink' : '' ?>" style="<?= $e(\App\Services\Calendar\CalendarTheme::tokenCss($theme ?? 'classic')) ?>">
  <header class="masthead">
    <div class="masthead-row">
      <div class="mh-main">
        <?php
          $kicker = [];
          if ($show['church']) { $kicker[] = (string) $branding['church']; }
          if ($show['location'] && ($branding['subtitle'] ?? '') !== '') { $kicker[] = (string) $branding['subtitle']; }
        ?>
        <?php if ($kicker !== []): ?>
          <div class="church"><?= implode('<span class="sep" aria-hidden="true">·</span>', array_map($e, $kicker)) ?></div>
        <?php endif; ?>
        <?php if ($show['docType'] && $periodNote !== ''): ?>
          <h1 class="doc-title"><?= $e($periodNote) ?></h1>
        <?php endif; ?>
        <?php if ($customSubtitle !== ''): ?>
          <div class="subtitle subtitle-custom"><?= $e($customSubtitle) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($show['period']): ?>
      <div class="period"><?= $e($branding['period']) ?></div>
      <?php endif; ?>
    </div>
  </header>

  <?php // Rendered only when it has something to say. ?>
  <?php if ($topHtml !== ''): ?>
    <section class="doc-info doc-info--top"><?= $topHtml ?></section>
  <?php endif; ?>

  <?= $body ?>

  <?php if (($legendRows ?? []) !== []): ?>
    <div class="legend" aria-label="Member types">
      <span class="legend-title">Member type</span>
      <?php foreach ($legendRows as $row): ?>
        <span class="legend-item mt-<?= $e($row['group']) ?>"><?= $row['symbol'] ?><?= $e($row['label']) ?></span>
      <?php endforeach; ?>
      <?php if (!empty($legendNeutral)): ?>
        <span class="legend-item"><span class="legend-rule" aria-hidden="true"></span>Not recorded</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($bottomHtml !== ''): ?>
    <section class="doc-info doc-info--bottom"><?= $bottomHtml ?></section>
  <?php endif; ?>

  <?php // Decoration, after the information and before the colophon. ?>
  <?= $artworkSvg ?? '' ?>

  <?php
    $left = [];
    if ($fShow['printed'] && ($branding['footer'] ?? '') !== '') { $left[] = (string) $branding['footer']; }
    if ($fShow['church']) { $left[] = (string) ($branding['church'] ?? ''); }
    if (trim((string) ($footerNote ?? '')) !== '') { $left[] = trim((string) $footerNote); }
    $right = [];
    if ($fShow['website'] && ($branding['website'] ?? '') !== '') { $right[] = (string) $branding['website']; }
  ?>
  <?php if ($left !== [] || $right !== [] || $fShow['page']): ?>
  <footer class="colophon">
    <span><?= $e(implode(' · ', array_filter($left))) ?></span>
    <?php if ($fShow['page']): ?><span class="folio"></span><?php endif; ?>
    <span><?= $e(implode(' · ', $right)) ?></span>
  </footer>
  <?php endif; ?>
</div>
</body>
</html>
