<?php
/**
 * Template E — the year at a glance.
 *
 * A planning document, not twelve monthly grids stapled together. Somebody
 * looking at this is deciding when to put something in the year, so it has to
 * fit on a wall and be readable from a step back.
 *
 * That means leaving things out, and the rules for what:
 *
 *  - Birthdays and anniversaries go. There are hundreds; on a yearly sheet they
 *    are noise, and they already have their own layers on screen.
 *  - A series collapses to one line per month. "Sunday Worship — 4 dates" is
 *    the fact a planner needs; four identical rows is the same fact four times.
 *  - Descriptions go entirely. A year does not have room to explain itself.
 *
 * Nothing is invented: every one of those rules reads a field the feed already
 * carries — kind, title, and the dates themselves.
 *
 * @var array<string,mixed> $model
 * @var array<string,string> $colors
 */
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$styles = <<<'CSS'
  .yr { column-count: 3; column-gap: 16pt; }
  @media print { .yr { column-count: 4; column-gap: 12pt; } }
  .yr-month { break-inside: avoid; margin: 0 0 11pt; }
  .yr-name { font-size: 9pt; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
    border-bottom: .75pt solid #16211c; padding-bottom: 2.5pt; margin: 0 0 4pt; }
  .yr-list { list-style: none; margin: 0; padding: 0; }
  .yr-list li { font-size: 8pt; line-height: 1.35; padding: 1.5pt 0;
    border-bottom: .4pt solid #eef2f0; display: flex; gap: 5pt; }
  .yr-list li:last-child { border-bottom: 0; }
  .yr-when { flex: 0 0 auto; font-weight: 700; font-variant-numeric: tabular-nums; color: #33443c; min-width: 26pt; }
  .yr-what { flex: 1 1 auto; min-width: 0; }
  .yr-times { color: #5c6b63; font-weight: 400; }
  .yr-none { font-size: 8pt; color: #8a968f; font-style: italic; }
  .yr-hol { font-weight: 700; }
  .yr-legend { margin: 10pt 0 0; font-size: 7.5pt; color: #5c6b63; border-top: .5pt solid #c9d4ce; padding-top: 5pt; }
CSS;

/** Days of the month a set of entries falls on, as "4", "4, 11" or "4 + 3 more". */
$spread = static function (array $dates): string {
    $dates = array_values(array_unique($dates));
    sort($dates);
    $days = array_map(static fn (string $d): string => (string) (int) substr($d, 8, 2), $dates);
    if (count($days) <= 3) {
        return implode(', ', $days);
    }

    return $days[0] . ' +' . (count($days) - 1);
};

ob_start(); ?>
<div class="yr">
<?php foreach ($model['months'] as $month): ?>
  <?php
    // Gather the month, dropping what a year-view cannot use.
    $byTitle = [];
    foreach ($model['days'] as $day) {
        if ($day['date'] < $month['start'] || $day['date'] > $month['end']) {
            continue;
        }
        foreach ($day['entries'] as $entry) {
            $kind = (string) ($entry['kind'] ?? '');
            if ($kind === 'birth' || $kind === 'anniv') {
                continue;
            }
            $title = (string) $entry['title'];
            // Group by what it is called, so a weekly service is one line with
            // its dates rather than one line per week.
            $byTitle[$title] ??= ['dates' => [], 'kind' => $kind, 'time' => $entry['time'] ?? null];
            // Keyed by date, because two holiday calendars both listing
            // Christmas produced "25, 25". The same name on the same day is one
            // line however many sources supplied it.
            $byTitle[$title]['dates'][$day['date']] = $day['date'];
        }
    }
    // Holidays first — they are the fixed points a year is planned around —
    // then whatever runs most often, then alphabetically so the order is
    // stable between printings.
    uasort($byTitle, static function (array $a, array $b): int {
        $ah = $a['kind'] === 'holiday' ? 0 : 1;
        $bh = $b['kind'] === 'holiday' ? 0 : 1;

        return $ah <=> $bh ?: count($b['dates']) <=> count($a['dates']);
    });
  ?>
  <section class="yr-month">
    <h2 class="yr-name"><?= $e($month['name']) ?><?= count($model['months']) > 12 ? ' ' . $e((string) $month['year']) : '' ?></h2>
    <?php if ($byTitle === []): ?>
      <p class="yr-none">Nothing scheduled.</p>
    <?php else: ?>
      <ul class="yr-list">
      <?php foreach ($byTitle as $title => $group): ?>
        <li>
          <span class="yr-when"><?= $e($spread(array_values($group['dates']))) ?></span>
          <span class="yr-what<?= $group['kind'] === 'holiday' ? ' yr-hol' : '' ?>">
            <?= $e($title) ?><?php if (count($group['dates']) > 3): ?>
              <span class="yr-times"><?= count($group['dates']) ?> dates</span>
            <?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</div>
<p class="yr-legend">A day number followed by <strong>+n</strong> means the first
date of a series and how many more follow that month. Birthdays and
anniversaries are left out of the yearly view.</p>
<?php
$body = ob_get_clean();
