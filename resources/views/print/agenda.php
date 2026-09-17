<?php
/**
 * Template B — the agenda.
 *
 * Date by date, in order. Reads faster than a grid and fits far more on a
 * page, which is why the brief calls it a foundation for printed schedules.
 *
 * Empty days are dropped: a printed list of nothing happening is paper spent
 * badly.
 *
 * @var array<string,mixed> $model
 * @var array<string,string> $colors
 */
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$styles = <<<'CSS'
  .agenda { column-count: 2; column-gap: 16pt; }
  .day { break-inside: avoid; margin-bottom: 9pt; }
  .day-head { display: flex; align-items: baseline; gap: 6pt; border-bottom: .5pt solid #d3ddd7; padding-bottom: 2pt; margin-bottom: 4pt; }
  .dow { font-size: 7.5pt; letter-spacing: .14em; text-transform: uppercase; font-weight: 700; color: #5c6b63; }
  .dnum { font-size: 13pt; font-weight: 700; line-height: 1; }
  .dmon { font-size: 7.5pt; letter-spacing: .12em; text-transform: uppercase; color: #6b7a72; font-weight: 700; }
  .day.weekend .dnum { color: #8a4b12; }
  .row { display: flex; gap: 6pt; margin-bottom: 3pt; break-inside: avoid; }
  .when { flex: 0 0 46pt; font-size: 7.6pt; color: #5c6b63; font-variant-numeric: tabular-nums; padding-top: .5pt; }
  .what { flex: 1 1 auto; font-size: 8.6pt; line-height: 1.3; border-left: 2pt solid #9aa7a0; padding-left: 5pt; }
  .what .t { font-weight: 700; }
  .what .m { display: block; font-size: 7.4pt; color: #5c6b63; }
CSS;

$days = array_values(array_filter($model['days'], static fn (array $d): bool => $d['entries'] !== []));

ob_start(); ?>
<?php if ($days === []): ?>
  <p class="empty">Nothing is scheduled in this period.</p>
<?php else: ?>
  <div class="agenda">
    <?php foreach ($days as $day): ?>
      <section class="day<?= $day['is_weekend'] ? ' weekend' : '' ?>">
        <div class="day-head">
          <span class="dow"><?= $e($day['weekday_short']) ?></span>
          <span class="dnum"><?= (int) $day['day'] ?></span>
          <span class="dmon"><?= $e($day['month_short']) ?></span>
        </div>
        <?php foreach ($day['entries'] as $entry): ?>
          <div class="row">
            <div class="when"><?= $entry['all_day'] ? 'All day' : $e($entry['time']) . ($entry['end_time'] ? '–' . $e($entry['end_time']) : '') ?></div>
            <div class="what" style="border-left-color:<?= $e($colors[$entry['source']] ?? '#9aa7a0') ?>">
              <span class="t"><?= $e($entry['title']) ?></span>
              <?php if ($entry['meta'] !== '' && $entry['meta'] !== $entry['title']): ?>
                <span class="m"><?= $e($entry['meta']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif;
$body = (string) ob_get_clean();
