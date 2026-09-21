<?php
/**
 * Template A — the wall calendar.
 *
 * One grid per month, sized so a whole month fits a page with room for real
 * titles rather than coloured dots. A day is a cell of text; colour marks the
 * source and never carries meaning on its own, because this gets photocopied.
 *
 * The cell used to have one type size — 7.4pt, always — which is right for a
 * Sunday carrying four services and wrong for a birthday sheet, where a single
 * celebrant sat as a tiny line in the corner of an empty square. Each cell now
 * picks a *named tier* from CellPlan: the loosest one its content genuinely
 * fits in. A quiet day breathes, a busy day stays exactly as dense as it is
 * today, and nothing is ever shrunk to fit — the answer below the last tier is
 * "+n more", not six point.
 *
 * @var array<string,mixed> $model
 * @var array<string,string> $colors
 */

use App\Services\Calendar\CellPlan;
use App\Services\Calendar\EntryPresentation;
use App\Services\Calendar\PrintDensity;

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// The reader's type-size choice. Everything the grid measures itself against
// scales with it, because a larger type that still budgets for the small one
// would clip.
$ts = (float) ($typeScale ?? 1.0);
$shortNames = ($nameStyle ?? 'full') === 'short';
$entryMode = (string) ($entryDisplay ?? 'auto');

$styles = <<<'CSS'
  .cal { width: 100%; border-collapse: collapse; table-layout: fixed; }
  .cal th { font-size: calc(7.5pt * var(--ts, 1)); letter-spacing: .14em; text-transform: uppercase; font-weight: 700;
    color: #5c6b63; padding: 0 0 5pt; text-align: left; border-bottom: .75pt solid #16211c; }
  /* Rows share the grid's height rather than growing with their contents. A
     wall calendar that runs onto a second sheet is not a wall calendar, and
     that is what a content-sized row produced: a busy week pushed the month to
     12.24in on an 8.5in page. */
  .cal td { border: .5pt solid #d3ddd7; vertical-align: top; padding: 0; }
  /* A table cell cannot be held to a height — it grows with its contents, which
     is exactly how a busy week pushed this grid onto a second sheet. The cell's
     contents live in a box that *can* be held, and anything past it is reported
     as "+n more" rather than clipped silently. */
  .cell { height: calc(var(--grid-h, 5.4in) / var(--rows, 6)); overflow: hidden;
    padding: var(--pad-t, 3pt) 4pt var(--pad-b, 2pt);
    display: flex; flex-direction: column; }
  .cal td.out { background: #f6f8f7; }
  .cal td.out .num { color: #b3bfb8; }
  .cal td.wknd { background: #fcfbf7; }
  .num { font-size: calc(11pt * var(--ts, 1)); font-weight: 700; line-height: 1; margin-bottom: 2pt; flex: 0 0 auto; }
  .num .mon { font-size: 6.5pt; letter-spacing: .1em; text-transform: uppercase; color: #6b7a72; margin-left: 3pt; font-weight: 700; }
  /* The entry list does not claim the cell's spare height by default: doing so
     pushed "+n more" to the very bottom of a busy cell instead of leaving it
     under the last entry, which is a visible change to a sheet that is meant
     to be unchanged. Only a sparse cell grows, because only a sparse cell has
     a composition to centre. */
  .ents { flex: 0 1 auto; min-height: 0; display: flex; flex-direction: column; }
  .s-sparse .ents { flex: 1 1 auto; }

  /* One tier, one class. The renderer decides *which* class a cell gets; the
     stylesheet decides what that class looks like. No cell is ever handed an
     arbitrary font size, so print output stays predictable and two cells at the
     same tier are identical. */
  .t-compact  { --e-fs: 7.4pt; --e-lh: 1.26; --e-gap: 1.4pt; --e-lines: 1; }
  .t-normal   { --e-fs: 9pt;   --e-lh: 1.22; --e-gap: 1.9pt; --e-lines: 2; }
  .t-readable { --e-fs: 11pt;  --e-lh: 1.18; --e-gap: 2.7pt; --e-lines: 2; }
  .t-showcase { --e-fs: 15pt;  --e-lh: 1.14; --e-gap: 3.6pt; --e-lines: 3; }

  .ent { font-size: calc(var(--e-fs) * var(--ts, 1)); line-height: var(--e-lh);
    margin-bottom: var(--e-gap); padding-left: 5pt;
    border-left: 2pt solid #9aa7a0; break-inside: avoid; }
  .ent .t { font-weight: 700; }
  .ent .w { color: #5c6b63; }

  /* Compact keeps today's behaviour exactly: one line, clipped with an
     ellipsis. A wrapped title there makes the cell's height unpredictable,
     which is what made the grid unfittable in the first place. */
  .t-compact .ent { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

  /* The roomy tiers may wrap, because that is the point of them — a name is
     better on two lines than shortened to an initial. The line clamp is the
     guarantee that "may wrap" never becomes "may grow without limit". */
  .t-normal .ent .t, .t-readable .ent .t, .t-showcase .ent .t {
    -webkit-box-orient: vertical;
    -webkit-line-clamp: var(--e-lines); line-clamp: var(--e-lines);
    overflow: hidden; overflow-wrap: break-word; }
  .t-readable .ent .t, .t-showcase .ent .t { display: -webkit-box; }
  /* Inline-box, not box. A -webkit-box is block-level, so at `normal` — where
     the secondary is meant to sit inline after the title — it pushed "· 26"
     onto a line of its own and the cell ran past the height it was planned
     for. The clamp is the same; only the box level differs. */
  .t-normal .ent .t { display: -webkit-inline-box; vertical-align: top; max-width: 100%; }
  /* Only the two roomy tiers give the secondary a line of its own. At 9pt
     that line costs more height than the tier gains, so normal keeps it
     inline exactly as compact does. */
  .t-readable .ent .w, .t-showcase .ent .w {
    display: block; font-size: .82em; font-weight: 600; }

  /* A cell that could not afford to wrap at its tier holds every entry to one
     clamped line. Set as a class rather than a measurement, so the stylesheet
     stays the thing that decides how text is set. */
  .w-tight .ent { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  /* The clamp box has to come off entirely here, not merely be set to one
     line: a -webkit-box does its own clipping, so the ellipsis belonging to
     the row never appeared and two long names were cut mid-word with nothing
     to show for it. */
  .w-tight .ent .t { display: inline; overflow: visible;
    -webkit-line-clamp: none; line-clamp: none; }

  /* Sparse cells are a small composition rather than a list: the entries take
     the room the day is not using, instead of huddling under the date. */
  .s-sparse .ents { justify-content: center; }

  .t-showcase .ent { border-left: 0; padding-left: 0; text-align: center; }
  .t-showcase .ent .t { color: #16211c; }

  .more { font-size: calc(6.8pt * var(--ts, 1)); color: #6b7a72; font-style: italic; flex: 0 0 auto; }
  .month + .month { margin-top: 16pt; page-break-before: always; }

  /* Grow to fit. The planned row height becomes a minimum, nothing is hidden
     and nothing is clamped: names wrap instead of ending in an ellipsis, and a
     busy week is simply taller. When a month outgrows the sheet it breaks
     between weeks, never through one, and the weekday row repeats. */
  .cal.is-grow thead { display: table-header-group; }
  .cal.is-grow tr { break-inside: avoid; page-break-inside: avoid; }
  /* The table is given the page's grid height as its *height*, which for a
     table is only a minimum: spare height is shared out among the weeks, a busy
     week takes more of it than a quiet one, and only when the month genuinely
     needs more than the sheet does the table grow past it. So a month fits one
     page whenever it can and continues on another only when it must. */
  /* 93%: the grid budget is arithmetic, and asking for all of it left the
     footer a few points over the edge on some themes. The difference is shared
     among the weeks and is not visible; a spilled footer is. */
  .cal.is-grow { height: calc((var(--grid-h, 5.4in) + 0.222in) * .93); }
  .cal.is-grow .cell { height: auto; min-height: 0.62in; overflow: visible; }
  .cal.is-grow .ent, .cal.is-grow .w-tight .ent { white-space: normal; overflow: visible; text-overflow: clip; }
  .cal.is-grow .ent .t, .cal.is-grow .w-tight .ent .t {
    display: inline; -webkit-line-clamp: none; line-clamp: none; overflow: visible; overflow-wrap: break-word; }
  .cal.is-grow .t-readable .ent .t, .cal.is-grow .t-showcase .ent .t { display: block; }
  .month-name { font-size: 13pt; margin: 0 0 7pt; font-weight: 700; letter-spacing: .01em; }
CSS;

/**
 * How much of a page the grid may occupy.
 *
 * Measured: page margin (12mm ×2 = 0.945in), sheet padding (14mm ×2 = 1.102in),
 * masthead ($mastheadIn, from the composer), weekday header row 0.222in, and a little slack so a rounding
 * error does not spill the grid onto a second sheet. $paperHeightIn already
 * accounts for orientation, and for the paper actually chosen.
 */
$paperHeightIn = (float) ($paperHeightIn ?? 8.5);
$paperWidthIn = (float) ($paperWidthIn ?? 11.0);
$gridHeightIn = max(3.0, $paperHeightIn - 0.945 - 1.102 - (float) ($mastheadIn ?? 0.66) - 0.222 - 0.05);
// The member-type key under the grid is a line of its own.
if (($legendRows ?? []) !== []) {
    $gridHeightIn -= 0.24;
}
// Growing, the row height above is the *least* a week gets, not the most: a
// busy week takes what it needs and the month may continue on another sheet.
$grow = ($pageHeight ?? 'fit') === 'grow';
// A theme that spends more of the page on its masthead has that much less to
// give the grid. Measured per theme and expressed as a difference from
// Classic, so Classic's arithmetic above is untouched.
$gridHeightIn = max(3.0, $gridHeightIn - (float) ($themeChromeIn ?? 0.0));
// A theme with decoration around the edges keeps that much of the page clear.
$safe = is_array($safeZone ?? null) ? $safeZone : ['top' => 0.0, 'bottom' => 0.0, 'left' => 0.0, 'right' => 0.0];
$gridHeightIn = max(3.0, $gridHeightIn - (float) $safe['top'] - (float) $safe['bottom']);

$rowCount = 0;
foreach ($model['months'] as $m) {
    $rowCount = max($rowCount, count($m['weeks']));
}
$rowCount = max(1, $rowCount);
$rowHeightIn = $gridHeightIn / $rowCount;

// The text column inside one day, which is what decides whether a name wraps.
// Seven columns across whatever is left of the page, less the cell's own
// padding (4pt each side) and the entry's rule and indent (7pt).
$gridWidthIn = max(3.0, $paperWidthIn - 0.945 - 1.102 - (float) $safe['left'] - (float) $safe['right']);
// A PowerPoint theme gives the grid its region instead: that area is where
// the design left room. The key goes inside it unless the design has its own.
if (is_array($gridBox ?? null)) {
    $inRegionKey = ($legendRows ?? []) !== [] && !isset($pptx['model']['regions']['LEGEND']) ? 0.24 : 0.0;
    $gridHeightIn = max(2.0, (float) $gridBox['h'] - 0.222 - $inRegionKey);
    $gridWidthIn = max(3.0, (float) $gridBox['w']);
    $rowCount = 0;
    foreach ($model['months'] as $m) {
        $rowCount = max($rowCount, count($m['weeks']));
    }
    $rowHeightIn = $gridHeightIn / max(1, $rowCount);
}
$cellTextWidthIn = max(0.5, ($gridWidthIn / 7) - (11.0 / 72.0));

// How tightly the grid is set — a separate axis from type size, and from the
// per-cell tier. Density owns the chrome (padding, leading, the gap between
// entries); the tier owns the type. Auto still resolves against the content.
$busiest = [];
foreach ($model['months'] as $m) {
    foreach ($m['weeks'] as $week) {
        foreach ($week as $day) {
            $busiest[] = count($day['entries']);
        }
    }
}
$resolved = PrintDensity::resolve((string) ($density ?? 'standard'), $busiest, $rowHeightIn, $ts);
$densityMode = $resolved['mode'];
$dm = PrintDensity::metrics($densityMode);

// What is left of a cell once the date number and the cell's own padding are
// paid for. This is the budget every tier is measured against.
$usableIn = max(0.1, $rowHeightIn - (($dm['cellPad'] + (float) ($themeCellPadIn ?? 0.0)) * $ts) - ($dm['numHeight'] * $ts));

$hiddenCount = 0;

ob_start();
foreach ($model['months'] as $mi => $month): ?>
  <?php
    // Printed in "auto", each month wears its own monthly theme: its tokens
    // on the section restyle everything inside it.
    $monthTheme = ($monthThemes ?? [])[$month['year'] . '-' . $month['month']] ?? null;
  ?>
  <section class="month<?= $monthTheme !== null && $mi > 0 ? ' ' . $e($themeClasses($monthTheme)) : '' ?>"<?= $monthTheme !== null && $mi > 0 ? ' style="' . $e(\App\Services\Calendar\CalendarTheme::tokenCss($monthTheme)) . '"' : '' ?>>
    <?php if (count($model['months']) > 1): ?>
      <h2 class="month-name"><?= $e($month['title']) ?></h2>
    <?php endif; ?>
    <table class="cal<?= $grow ? ' is-grow' : '' ?>" style="<?= $e(PrintDensity::cssVars($densityMode)) ?>;--ts:<?= $e(number_format($ts, 3)) ?>;--grid-h:<?= $e(number_format($gridHeightIn, 2)) ?>in;--rows:<?= (int) count($month['weeks']) ?>">
      <thead><tr><?php foreach ($weekdays as $w): ?><th scope="col"><?= $e($w) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($month['weeks'] as $week): ?>
        <tr>
          <?php foreach ($week as $day): ?>
            <?php
              $out = !($day['in_range'] ?? true);

              // Project each entry into primary/secondary before planning, so
              // the plan measures the text that will actually be set rather
              // than the raw title. Wrapping is offered where a tier can
              // afford it, which is what lets a full name survive.
              // Compact is the promise that the old sheet is still available:
              // no splitting, no wrapping, and the same number of entries a
              // day that PrintDensity has always allowed.
              $legacy = $entryMode === 'compact';
              $items = [];
              foreach ($day['entries'] as $entry) {
                  $items[] = EntryPresentation::of($entry, $shortNames, !$legacy, !$legacy)
                      + ['source' => $entry['source'], 'href' => (string) ($entry['href'] ?? '')];
              }

              // Growing, a day may take a quarter as much height again before the
              // type tightens, so a busy day is not set in the smallest tier
              // inside a row that has grown to hold it anyway.
              $plan = CellPlan::plan(
                  $items, $entryMode, $grow ? $usableIn * 1.25 : $usableIn, $cellTextWidthIn, $ts, $dm['moreHeight'],
                  $legacy && !$grow ? PrintDensity::perDay($densityMode, $rowHeightIn, $ts) : null,
              );
              if ($grow) {
                  // The plan still chooses the type tier, so a busy day is set
                  // as densely as before; it just no longer hides anything.
                  $plan['shown'] = count($items);
                  $plan['hidden'] = 0;
                  $plan['wrap'] = true;
                  if ($plan['state'] === 'overflow') {
                      $plan['state'] = 'dense';
                  }
              }
              $hiddenCount += $plan['hidden'];
            ?>
            <td class="<?= $out ? 'out' : ($day['is_weekend'] ? 'wknd' : '') ?>">
              <div class="cell t-<?= $e($plan['tier']) ?> s-<?= $e($plan['state']) ?><?= $plan['wrap'] ? '' : ' w-tight' ?>">
              <div class="num"><?= (int) $day['day'] ?><?php if ((int) $day['day'] === 1): ?><span class="mon"><?= $e($day['month_short']) ?></span><?php endif; ?></div>
              <?php if ($plan['shown'] > 0): ?>
              <div class="ents">
                <?php foreach (array_slice($items, 0, $plan['shown']) as $item): ?>
                  <div class="ent k-<?= $e($item['category']) ?><?= ($item['group'] ?? null) !== null ? ' mt-' . $e($item['group']) : '' ?>"<?= !empty($editable) && str_starts_with($item['href'], '/events/') ? ' data-href="' . $e($item['href']) . '"' : '' ?> style="border-left-color:<?= $e($colors[$item['source']] ?? '#9aa7a0') ?>">
                    <span class="t"><?= $e($item['primary']) ?></span>
                    <?php if ($item['secondary'] !== ''): ?><span class="w"><?php
                      // Inline, a person's age reads as "(29)" and an event's
                      // time as "· 7:00pm". A bare "·" is what appeared when a
                      // long name wrapped and left the separator stranded on a
                      // line of its own.
                      if (\App\Services\Calendar\CellPlan::secondaryIsBlock($plan['tier'])) {
                          echo $e($item['secondary']);
                      } elseif ($item['person']) {
                          echo ' (' . $e($item['secondary']) . ')';
                      } else {
                          echo ' · ' . $e($item['secondary']);
                      }
                    ?></span><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <?php if ($plan['hidden'] > 0): ?>
                <div class="more">+<?= (int) $plan['hidden'] ?> more</div>
              <?php endif; ?>
              </div>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endforeach;
$body = (string) ob_get_clean();
