<?php
/**
 * Template F — the week.
 *
 * The same wall calendar at a week's scale. A month grid gives a day about an
 * inch and then says "+3 more"; a week gives it four or five, which is enough
 * for the day a church actually has. Somebody printing this wants this Sunday
 * legible from across the foyer, not the whole month in miniature.
 *
 * Seven columns, one row per week, as many weeks as the range holds — so a
 * fortnight is two rows on one sheet and a month is four or five, each still
 * roomier than the month grid.
 *
 * A week's cell is three to five times the area of a month's, which is exactly
 * the situation the adaptive tier was built for: a Tuesday with one entry on it
 * had four inches of paper and used a quarter of an inch of it. Each day now
 * picks a tier from CellPlan, on the same terms as the month grid — the loosest
 * one its content genuinely fits in, and never a size chosen to make something
 * fit.
 *
 * @var array<string,mixed> $model
 * @var array<string,string> $colors
 */

use App\Services\Calendar\CellPlan;
use App\Services\Calendar\EntryPresentation;

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$ts = (float) ($typeScale ?? 1.0);

$shortNames = ($nameStyle ?? 'full') === 'short';
$entryMode = (string) ($entryDisplay ?? 'auto');
// Compact is the promise that the previous sheet is still available: no
// splitting, no wrapping, and the same entries a day it has always shown.
$legacy = $entryMode === 'compact';

$weeks = $model['weeks'] ?? [];
$weekCount = max(1, count($weeks));

// The page's usable height, shared between the weeks on it. Page margins
// (0.945in), sheet padding (1.102in) and the masthead ($mastheadIn) come off first.
$paperHeightIn = (float) ($paperHeightIn ?? 8.5);
$paperWidthIn = (float) ($paperWidthIn ?? 11.0);
$gridHeightIn = max(2.5, $paperHeightIn - 0.945 - 1.102 - (float) ($mastheadIn ?? 0.66) - 0.05);
if (($legendRows ?? []) !== []) {
    $gridHeightIn -= 0.24;
}
// As in the monthly grid: growing, the planned row height is a minimum.
$grow = ($pageHeight ?? 'fit') === 'grow';
// A theme that spends more of the page on its masthead has that much less to
// give the grid. Measured per theme, as a difference from Classic.
$gridHeightIn = max(2.5, $gridHeightIn - (float) ($themeChromeIn ?? 0.0));

// Past four weeks a row is no roomier than a month cell, so the sheet stops
// trying to hold them all and lets them run on.
$perPage = min($weekCount, 4);

// Each week costs more than its day row: a date range label (0.187in), the
// weekday header (0.242in) and the gap to the next block (0.125in). Measured,
// because budgeting only for the row is what pushed a two-week sheet to 9.6in
// on an 8.5in page.
$blockChromeIn = (0.187 + 0.242) * $ts + 0.125;
$rowHeightIn = max(0.7, ($gridHeightIn / $perPage) - $blockChromeIn);

// Measured off a rendered cell, margins and padding included, at 96dpi:
//
//   cell padding  0.097   (5.33px + 4px)
//   day number    0.208   (16px + 4px margin)
//   entry         0.156   (12.8px + 2.13px margin)
//   "+n more"     0.134
//
// The "+n more" line is reserved in advance for the same reason the month grid
// reserves it: it appears exactly when the day is full. Estimating instead of
// measuring is what clipped eight cells on the first attempt, and a clipped
// cell loses an entry its own count never mentioned.
$cellContentIn = $rowHeightIn - (0.097 * $ts);
$perDay = max(2, (int) floor(($cellContentIn - (0.208 * $ts) - (0.134 * $ts)) / (0.156 * $ts)));

// What is left of a cell once the date number and the cell's own padding are
// paid for. This is the budget every tier is measured against.
$usableIn = max(0.1, $cellContentIn - (0.208 * $ts));
// The text column inside one day, which is what decides whether a name wraps:
// seven columns across the page, less the cell's padding (5pt each side) and
// the entry's rule and indent (7pt).
$gridWidthIn = max(3.0, $paperWidthIn - 0.945 - 1.102);
$cellTextWidthIn = max(0.5, ($gridWidthIn / 7) - (17.0 / 72.0));

$styles = <<<'CSS'
  .wk { display: grid; grid-template-columns: repeat(7, 1fr); gap: 0;
    border: .5pt solid #d3ddd7; border-right: 0; border-bottom: 0; }
  .wk + .wk { margin-top: 9pt; }
  .wk-head { font-size: calc(7.5pt * var(--ts, 1)); letter-spacing: .14em; text-transform: uppercase;
    font-weight: 700; color: #5c6b63; padding: 3pt 5pt; border-right: .5pt solid #d3ddd7;
    border-bottom: .75pt solid #16211c; }
  .wk-day { border-right: .5pt solid #d3ddd7; border-bottom: .5pt solid #d3ddd7;
    padding: 4pt 5pt 3pt; overflow: hidden; height: var(--row-h, 1.6in);
    display: flex; flex-direction: column; }
  .wk-day.out { background: #f6f8f7; }
  .wk-day.out .wk-num { color: #b3bfb8; }
  .wk-day.wknd { background: #fcfbf7; }
  .wk-num { font-size: calc(12pt * var(--ts, 1)); font-weight: 700; line-height: 1;
    margin-bottom: 3pt; }
  .wk-num .mon { font-size: calc(6.5pt * var(--ts, 1)); letter-spacing: .1em;
    text-transform: uppercase; color: #6b7a72; margin-left: 3pt; font-weight: 700; }
  .wk-num { flex: 0 0 auto; }
  /* The list does not claim the cell's spare height by default: doing so would
     push "+n more" to the very bottom of a busy cell rather than leaving it
     under the last entry. Only a sparse cell grows, because only a sparse cell
     has a composition to centre. */
  .wk-ents { flex: 0 1 auto; min-height: 0; display: flex; flex-direction: column; }
  .s-sparse .wk-ents { flex: 1 1 auto; justify-content: center; }

  /* One tier, one class — the same vocabulary the month grid uses. The renderer
     decides which class a cell gets; the stylesheet decides what that class
     looks like, so no cell is ever handed an arbitrary font size. */
  .t-compact  { --e-fs: 7.4pt; --e-lh: 1.26; --e-gap: 1.4pt; --e-lines: 1; }
  .t-normal   { --e-fs: 9pt;   --e-lh: 1.22; --e-gap: 1.9pt; --e-lines: 2; }
  .t-readable { --e-fs: 11pt;  --e-lh: 1.18; --e-gap: 2.7pt; --e-lines: 2; }
  .t-showcase { --e-fs: 15pt;  --e-lh: 1.14; --e-gap: 3.6pt; --e-lines: 3; }

  .wk-ent { font-size: calc(7.6pt * var(--ts, 1)); line-height: 1.26; margin-bottom: 1.6pt;
    padding-left: 5pt; border-left: 2pt solid #9aa7a0; break-inside: avoid;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  /* A cell with a tier overrides the base row above, which stays exactly as it
     was so that Compact prints the sheet this church already has. */
  .wk-day[class*="t-"] .wk-ent { font-size: calc(var(--e-fs) * var(--ts, 1));
    line-height: var(--e-lh); margin-bottom: var(--e-gap); }
  .t-normal .wk-ent, .t-readable .wk-ent, .t-showcase .wk-ent {
    white-space: normal; overflow: visible; text-overflow: clip; }
  .t-readable .wk-ent .t, .t-showcase .wk-ent .t {
    display: -webkit-box; -webkit-box-orient: vertical;
    -webkit-line-clamp: var(--e-lines); line-clamp: var(--e-lines);
    overflow: hidden; overflow-wrap: break-word; }
  /* Inline-box, not box: a -webkit-box is block-level, and at `normal` — where
     the secondary sits inline after the title — that would push it onto a line
     of its own and overrun the height the cell was planned for. */
  .t-normal .wk-ent .t { display: -webkit-inline-box; -webkit-box-orient: vertical;
    -webkit-line-clamp: var(--e-lines); line-clamp: var(--e-lines);
    overflow: hidden; overflow-wrap: break-word; vertical-align: top; max-width: 100%; }
  .t-readable .wk-ent .w, .t-showcase .wk-ent .w {
    display: block; font-size: .82em; font-weight: 600; }
  /* A cell that could not afford to wrap at its tier holds every entry to one
     clamped line, with the ellipsis belonging to the row rather than to the
     clamp box — which does its own clipping and shows nothing. */
  .w-tight .wk-ent { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .w-tight .wk-ent .t { display: inline; overflow: visible;
    -webkit-line-clamp: none; line-clamp: none; }

  /* Grow to fit: a week takes the height its busiest day needs, nothing is
     clamped or hidden, and a week never splits across two sheets. */
  .wk.is-grow { break-inside: avoid; page-break-inside: avoid; }
  .wk.is-grow .wk-day { height: auto; min-height: calc(var(--row-h, 1.6in) * .8); overflow: visible; }
  .wk.is-grow .wk-ent, .wk.is-grow .w-tight .wk-ent { white-space: normal; overflow: visible; text-overflow: clip; }
  .wk.is-grow .wk-ent .t { display: inline; -webkit-line-clamp: none; line-clamp: none; overflow: visible; }
  .t-showcase .wk-ent { border-left: 0; padding-left: 0; text-align: center; }

  .wk-ent .t { font-weight: 700; }
  .wk-ent .w { color: #5c6b63; }
  .wk-more { font-size: calc(6.8pt * var(--ts, 1)); color: #6b7a72; font-style: italic; flex: 0 0 auto; }
  .wk-range { font-size: calc(9.5pt * var(--ts, 1)); font-weight: 700; margin: 0 0 4pt;
    letter-spacing: .02em; color: #16211c; }
  .wk-block { break-inside: avoid; }
  .wk-block:nth-child(n+5) { break-before: page; }
CSS;

ob_start();
if ($weeks === []): ?>
  <p class="empty">Nothing is scheduled in this period.</p>
<?php else: ?>
  <?php foreach ($weeks as $week):
      $days = $week['days'] ?? $week;
      $first = $days[0] ?? null;
      $last = $days[count($days) - 1] ?? null;
  ?>
  <section class="wk-block">
    <?php if ($first !== null && $last !== null): ?>
      <p class="wk-range">
        <?= $e((new DateTimeImmutable($first['date']))->format('j M')) ?>
        – <?= $e((new DateTimeImmutable($last['date']))->format('j M Y')) ?>
      </p>
    <?php endif; ?>
    <div class="wk<?= $grow ? ' is-grow' : '' ?>" style="--ts:<?= $e(number_format($ts, 3)) ?>;--row-h:<?= $e(number_format($rowHeightIn, 3)) ?>in">
      <?php foreach ($weekdays as $w): ?>
        <div class="wk-head"><?= $e($w) ?></div>
      <?php endforeach; ?>
      <?php foreach ($days as $day): ?>
        <?php
          $out = !($day['in_range'] ?? true);
          // Project each entry before planning, so the plan measures the text
          // that will actually be set rather than the raw title.
          $items = [];
          foreach ($day['entries'] as $entry) {
              $items[] = EntryPresentation::of($entry, $shortNames, !$legacy, !$legacy)
                  + ['source' => $entry['source']];
          }
          $plan = CellPlan::plan(
              $items, $entryMode, $grow ? $usableIn * 1.25 : $usableIn, $cellTextWidthIn, $ts, 0.134,
              $legacy && !$grow ? $perDay : null,
          );
          if ($grow) {
              $plan['shown'] = count($items);
              $plan['hidden'] = 0;
              $plan['wrap'] = true;
              if ($plan['state'] === 'overflow') {
                  $plan['state'] = 'dense';
              }
          }
          // Compact keeps the row it has always had, rather than being restyled
          // by a tier that happens to carry the same measurements.
          $tierClass = $legacy ? '' : ' t-' . $plan['tier'] . ' s-' . $plan['state']
              . ($plan['wrap'] ? '' : ' w-tight');
        ?>
        <div class="wk-day <?= $out ? 'out' : ($day['is_weekend'] ? 'wknd' : '') ?><?= $e($tierClass) ?>">
          <div class="wk-num"><?= (int) $day['day'] ?><?php if ((int) $day['day'] === 1): ?><span class="mon"><?= $e($day['month_short']) ?></span><?php endif; ?></div>
          <?php if ($plan['shown'] > 0): ?>
          <div class="wk-ents">
            <?php foreach (array_slice($items, 0, $plan['shown']) as $item): ?>
              <div class="wk-ent k-<?= $e($item['category']) ?><?= ($item['group'] ?? null) !== null ? ' mt-' . $e($item['group']) : '' ?>" style="border-left-color:<?= $e($colors[$item['source']] ?? '#9aa7a0') ?>">
                <span class="t"><?= $item['category'] === 'birth' && isset($memberTypes) ? $memberTypes->symbol((string) $item['memberType']) : '' ?><?= $e($item['primary']) ?></span>
                <?php if ($item['secondary'] !== ''): ?><span class="w"><?php
                    // Inline, a person's age reads as "(29)" and an event's time
                    // as "· 7:00pm". A bare "·" is what appeared when a long
                    // name wrapped and left the separator stranded.
                    if (CellPlan::secondaryIsBlock($plan['tier']) && !$legacy) {
                        echo $e($item['secondary']);
                    } elseif ($item['person'] && !$legacy) {
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
            <div class="wk-more">+<?= (int) $plan['hidden'] ?> more</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
<?php endif;
$body = (string) ob_get_clean();
