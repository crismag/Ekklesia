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
  /* On screen the sheet is the paper itself, with the print margin as its
     padding, so its content is exactly as wide as on the printed page. That is
     what lets the page be measured here and fitted to one sheet (below). */
  .sheet {
    background: #fff;
    margin: 16px auto;
    padding: 12mm;
    width: <?= $paperW ?>in;
    min-height: <?= $paperH ?>in;
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

  /* Member types (MemberTypeStyle): colour on the name itself, never an extra
     mark, so a busy day loses no room. The rule beside the entry carries the
     hue at full strength; the name is highlighted with a light tint of it
     (text stays in ink) or, in "text" mode, set in a deeper shade of it. */
  .k-birth { border-left-color: var(--mt-none) !important; }
  .k-birth.mt-gna { border-left-color: var(--mt-gna) !important; }
  .k-birth.mt-trailblazer { border-left-color: var(--mt-trailblazer) !important; }
  .k-birth.mt-radical { border-left-color: var(--mt-radical) !important; }
  .mark-highlight .k-birth.mt-gna .t { background: var(--mt-gna-tint); }
  .mark-highlight .k-birth.mt-trailblazer .t { background: var(--mt-trailblazer-tint); }
  .mark-highlight .k-birth.mt-radical .t { background: var(--mt-radical-tint); }
  /* The tint hugs the name. The roomier tiers set the name as a block (for
     line clamping), which would otherwise stretch the tint across the cell. */
  .mark-highlight .k-birth[class*="mt-"] .t { border-radius: 2pt; padding: 0 2pt; margin: 0 -1pt;
    width: fit-content; max-width: 100%;
    -webkit-box-decoration-break: clone; box-decoration-break: clone; }
  .mark-highlight .t-showcase .k-birth[class*="mt-"] .t { margin-left: auto; margin-right: auto; }
  .mark-text .k-birth.mt-gna .t { color: var(--mt-gna-ink); }
  .mark-text .k-birth.mt-trailblazer .t { color: var(--mt-trailblazer-ink); }
  .mark-text .k-birth.mt-radical .t { color: var(--mt-radical-ink); }
  .legend { display: flex; flex-wrap: wrap; align-items: center; gap: 3pt 14pt; margin-top: 8pt;
    font-size: 8.5pt; color: #46534c; break-inside: avoid; }
  .legend-title { font-weight: 700; letter-spacing: .08em; text-transform: uppercase; font-size: 7.5pt; }
  .legend-item { display: inline-flex; align-items: center; gap: 4pt; }
  .legend-swatch { display: inline-block; min-width: 10pt; height: 10pt; border-radius: 2pt; border-left: 2.5pt solid var(--mt-none); }
  .mark-highlight .legend-swatch.mt-gna { background: var(--mt-gna-tint); }
  .mark-highlight .legend-swatch.mt-trailblazer { background: var(--mt-trailblazer-tint); }
  .mark-highlight .legend-swatch.mt-radical { background: var(--mt-radical-tint); }
  .legend-swatch.mt-gna { border-left-color: var(--mt-gna); }
  .legend-swatch.mt-trailblazer { border-left-color: var(--mt-trailblazer); }
  .legend-swatch.mt-radical { border-left-color: var(--mt-radical); }
  .mark-text .legend .mt-gna-label { color: var(--mt-gna-ink); font-weight: 700; }
  .mark-text .legend .mt-trailblazer-label { color: var(--mt-trailblazer-ink); font-weight: 700; }
  .mark-text .legend .mt-radical-label { color: var(--mt-radical-ink); font-weight: 700; }
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

  /* A background picture: its own layer behind everything, with a white wash
     over it (the "readability" setting) so text keeps its contrast. On paper
     it is fixed to the page, which in print means it repeats on every sheet
     rather than stretching across all of them. Cells that are normally tinted
     become translucent so the picture shows through evenly. */
  .bg-layer { position: absolute; inset: 0; z-index: 0; pointer-events: none;
    background-repeat: no-repeat; background-size: var(--bg-fit, cover);
    background-position: var(--bg-pos, center); opacity: var(--bg-o, .35);
    -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .bg-wash { position: absolute; inset: 0; z-index: 0; pointer-events: none;
    background: rgba(255,255,255,var(--bg-wash, .55)); }
  .has-bg > *:not(.bg-layer):not(.bg-wash) { position: relative; z-index: 1; }
  .has-bg .cal td.wknd, .has-bg .cal td.out, .has-bg .wk-day.wknd, .has-bg .wk-day.out { background: rgba(255,255,255,.4); }
  @media print { .bg-layer, .bg-wash { position: fixed; } }
  /* Editing on the sheet (the studio's "Edit text on the sheet"). Outlines and
     placeholders are for the screen; an empty editable region never prints. */
  [data-edit] { outline: 1px dashed rgba(12,90,69,.45); outline-offset: 2px; min-height: 1em; cursor: text; }
  [data-edit]:focus { outline: 2px solid #0c5a45; background: rgba(255,255,255,.9); }
  [data-edit]:empty::before { content: attr(data-placeholder); color: #8a948f; font-style: italic; font-weight: 400; }
  .doc-info .al-center { text-align: center; } .doc-info .al-right { text-align: right; }
  [data-href] { cursor: pointer; }
  @media print {
    [data-edit] { outline: 0 !important; background: transparent !important; }
    [data-edit]:empty, .doc-info:empty { display: none !important; }
  }
  .print-note { margin: 0 0 8pt; padding: 6pt 8pt; border: 1pt solid #c98a2b; background: #fff7e8;
    color: #5a3d00; font: 9pt/1.4 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; border-radius: 3pt; }

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
<div class="sheet title-<?= $e($titleStyle) ?> <?= $e(isset($themeClasses) ? $themeClasses($theme ?? 'classic') : 'theme-' . ($theme ?? 'classic')) ?> ents-<?= $e($entryDisplay ?? 'auto') ?><?= !empty($inkFriendly) ? ' is-ink' : '' ?><?= !empty($background) ? ' has-bg' : '' ?> mark-<?= $e($memberMark ?? 'highlight') ?>" style="<?= $e(\App\Services\Calendar\CalendarTheme::tokenCss($theme ?? 'classic')) ?>">
  <?php foreach (($printNotes ?? []) as $note): ?>
    <p class="print-note no-print" role="status"><?= $e($note) ?></p>
  <?php endforeach; ?>
  <?php if (!empty($background)):
    $bgPos = ['left' => '0%', 'center' => '50%', 'right' => '100%'][$background['x']] ?? '50%';
    $bgPos .= ' ' . (['top' => '0%', 'center' => '50%', 'bottom' => '100%'][$background['y']] ?? '50%'); ?>
    <div class="bg-layer" aria-hidden="true" style="background-image:url('<?= $e($background['url']) ?>');--bg-fit:<?= $background['fit'] === 'contain' ? 'contain' : 'cover' ?>;--bg-pos:<?= $e($bgPos) ?>;--bg-o:<?= $e(number_format((float) $background['opacity'], 2)) ?>"></div>
    <div class="bg-wash" aria-hidden="true" style="--bg-wash:<?= $e(number_format((float) $background['overlay'], 2)) ?>"></div>
  <?php endif; ?>
  <div class="sheet-body">
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
          <h1 class="doc-title"<?= !empty($editable) ? ' data-edit="title" data-placeholder="Calendar title"' : '' ?>><?= $e($periodNote) ?></h1>
        <?php endif; ?>
        <?php if ($customSubtitle !== '' || !empty($editable)): ?>
          <div class="subtitle subtitle-custom"<?= !empty($editable) ? ' data-edit="subtitle" data-placeholder="Line under the title (optional)"' : '' ?>><?= $e($customSubtitle) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($show['period']): ?>
      <div class="period"><?= $e($branding['period']) ?></div>
      <?php endif; ?>
    </div>
  </header>

  <?php // Rendered only when it has something to say. ?>
  <?php if ($topHtml !== '' || !empty($editable)): ?>
    <section class="doc-info doc-info--top"<?= !empty($editable) ? ' data-edit="top" data-rich="1" data-placeholder="Note above the calendar (optional)"' : '' ?>><?= $topHtml ?></section>
  <?php endif; ?>

  <?= $body ?>

  <?php if (($legendRows ?? []) !== []): ?>
    <div class="legend" aria-label="Member types">
      <span class="legend-title">Member type</span>
      <?php foreach ($legendRows as $row): ?>
        <span class="legend-item"><span class="legend-swatch mt-<?= $e($row['group']) ?>" aria-hidden="true"></span><span class="mt-<?= $e($row['group']) ?>-label"><?= $e($row['label']) ?></span></span>
      <?php endforeach; ?>
      <?php if (!empty($legendNeutral)): ?>
        <span class="legend-item"><span class="legend-rule" aria-hidden="true"></span>Not recorded</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($bottomHtml !== '' || !empty($editable)): ?>
    <section class="doc-info doc-info--bottom"<?= !empty($editable) ? ' data-edit="bottom" data-rich="1" data-placeholder="Note below the calendar (optional)"' : '' ?>><?= $bottomHtml ?></section>
  <?php endif; ?>

  <?php // Decoration, after the information and before the colophon. ?>
  <?= $artworkSvg ?? '' ?>

  <?php
    $left = [];
    if ($fShow['printed'] && ($branding['footer'] ?? '') !== '') { $left[] = (string) $branding['footer']; }
    if ($fShow['church']) { $left[] = (string) ($branding['church'] ?? ''); }
    $noteText = trim((string) ($footerNote ?? ''));
    $right = [];
    if ($fShow['website'] && ($branding['website'] ?? '') !== '') { $right[] = (string) $branding['website']; }
  ?>
  <?php if ($left !== [] || $right !== [] || $fShow['page'] || $noteText !== '' || !empty($editable)): ?>
  <footer class="colophon">
    <span><?= $e(implode(' · ', array_filter($left))) ?><?php if ($noteText !== '' || !empty($editable)): ?><?= $left !== [] ? ' · ' : '' ?><span class="foot-note"<?= !empty($editable) ? ' data-edit="footer" data-placeholder="Footer note (optional)"' : '' ?>><?= $e($noteText) ?></span><?php endif; ?></span>
    <?php if ($fShow['page']): ?><span class="folio"></span><?php endif; ?>
    <span><?= $e(implode(' · ', $right)) ?></span>
  </footer>
  <?php endif; ?>
  </div>
</div>
<?php if (!empty($fitOnePage)): ?>
<script>
/* One page per month, guaranteed.
 *
 * The grid is planned to fit, but a sheet can carry more than was planned for:
 * a long church or campus name, notes above or below, long holiday names. So
 * each printed page (the title and first month; then each further month) is
 * measured, and one that would run past the paper is scaled down just enough
 * to fit, as "fit to page" does. The scale is set on the page's own elements,
 * so printing and saving as PDF use it; nothing is ever pushed to a second
 * sheet. Measured on screen, where the sheet has the printed page's width. */
(function () {
  var avail = <?= json_encode(round(($paperH - 0.945) * 96 - 6, 1)) ?>;
  function fit() {
    var body = document.querySelector('.sheet-body');
    if (!body) return;
    var kids = Array.prototype.slice.call(body.children);
    kids.forEach(function (k) { k.style.zoom = ''; });
    var groups = [[]];
    var months = 0;
    kids.forEach(function (k) {
      if (k.matches('section.month') && months++ > 0) groups.push([]);
      groups[groups.length - 1].push(k);
    });
    groups.forEach(function (g) {
      var shown = g.filter(function (k) { return k.getClientRects().length > 0; });
      if (!shown.length) return;
      var top = shown[0].getBoundingClientRect().top;
      var last = shown[shown.length - 1];
      var bottom = last.getBoundingClientRect().bottom + parseFloat(getComputedStyle(last).marginBottom || 0);
      var height = bottom - top;
      if (height > avail) {
        var z = Math.max(0.5, Math.floor((avail / height) * 1000) / 1000);
        g.forEach(function (k) { k.style.zoom = String(z); });
        document.documentElement.setAttribute('data-fitted', String(z));
      }
    });
  }
  fit();
  window.addEventListener('load', fit);
})();
</script>
<?php endif; ?>
</body>
</html>
