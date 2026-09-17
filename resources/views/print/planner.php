<?php
/**
 * Template C — the ministry planner.
 *
 * A table for leaders rather than for the notice board: date, what, when,
 * where, and a column left blank on purpose. Printed planners get written on.
 *
 * @var array<string,mixed> $model
 * @var array<string,string> $colors
 */
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$styles = <<<'CSS'
  table.plan { width: 100%; border-collapse: collapse; }
  table.plan th { font-size: 7.5pt; letter-spacing: .13em; text-transform: uppercase; font-weight: 700;
    color: #5c6b63; text-align: left; padding: 0 6pt 5pt 0; border-bottom: .75pt solid #16211c; }
  table.plan td { padding: 5pt 6pt 5pt 0; border-bottom: .5pt solid #e2e9e5; vertical-align: top; font-size: 8.6pt; }
  table.plan tr.newday td { border-top: .75pt solid #c9d4ce; }
  .c-date { width: 62pt; font-weight: 700; white-space: nowrap; }
  .c-date .dow { display: block; font-size: 7pt; letter-spacing: .1em; text-transform: uppercase; color: #6b7a72; font-weight: 700; }
  .c-when { width: 66pt; color: #5c6b63; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .c-where { width: 26%; color: #5c6b63; }
  /* Ruled and empty. A planner is a working document. */
  .c-note { width: 22%; border-bottom: .5pt solid #e2e9e5; }
  .tag { display: inline-block; width: 5pt; height: 5pt; border-radius: 50%; margin-right: 4pt; vertical-align: baseline; }
CSS;

$days = array_values(array_filter($model['days'], static fn (array $d): bool => $d['entries'] !== []));

ob_start(); ?>
<?php if ($days === []): ?>
  <p class="empty">Nothing is scheduled in this period.</p>
<?php else: ?>
  <table class="plan">
    <thead><tr>
      <th scope="col">Date</th><th scope="col">Activity</th><th scope="col">Time</th>
      <th scope="col">Where</th><th scope="col">Notes</th>
    </tr></thead>
    <tbody>
    <?php foreach ($days as $day): $first = true; ?>
      <?php foreach ($day['entries'] as $entry): ?>
        <tr class="<?= $first ? 'newday' : '' ?>">
          <td class="c-date"><?php if ($first): ?><?= $e($day['month_short']) ?> <?= (int) $day['day'] ?><span class="dow"><?= $e($day['weekday_short']) ?></span><?php endif; ?></td>
          <td><span class="tag" style="background:<?= $e($colors[$entry['source']] ?? '#9aa7a0') ?>"></span><?= $e($entry['title']) ?></td>
          <td class="c-when"><?= $entry['all_day'] ? '—' : $e($entry['time']) . ($entry['end_time'] ? '–' . $e($entry['end_time']) : '') ?></td>
          <td class="c-where"><?= $e($entry['location'] !== '' ? $entry['location'] : $entry['meta']) ?></td>
          <td class="c-note"></td>
        </tr>
      <?php $first = false; endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif;
$body = (string) ob_get_clean();
