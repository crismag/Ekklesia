<?php
/**
 * Template D — the Sunday schedule.
 *
 * Not a calendar. A working sheet for the people running a service: what
 * happens, when, where, and who is on. It goes on a noticeboard and into a
 * folder, so it is built for a photocopier — no colour carries meaning, every
 * rule is black, and a Sunday never breaks across a page.
 *
 * Only Sundays appear, and only the ones with something on them. A church that
 * prints this wants the service days, not the week around them.
 *
 * Where the columns come from, and nowhere else:
 *   Time      the occurrence's own start and end
 *   Activity  the event title, or an occurrence's override of it
 *   Details   the feed's meta line — location, campus, "repeats"
 *   Serving   roster entries, which arrive as "Role — names"
 *   Notes     ruled and empty, because printed schedules get written on
 *
 * There is no ministry column. The feed does not carry a ministry per item, and
 * a column filled from the calendar-layer label would read like data somebody
 * entered when it is not.
 *
 * @var array<string,mixed> $model
 * @var array<string,string> $colors
 */
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$styles = <<<'CSS'
  .sun { break-inside: avoid; margin: 0 0 14pt; }
  .sun + .sun { border-top: 1.25pt solid #16211c; padding-top: 10pt; }
  .sun-head { display: flex; align-items: baseline; gap: 8pt; margin: 0 0 6pt; }
  .sun-date { font-size: 13pt; font-weight: 800; letter-spacing: -.2pt; }
  .sun-sub { font-size: 8pt; letter-spacing: .12em; text-transform: uppercase; color: #5c6b63; font-weight: 700; }
  table.sun-tbl { width: 100%; border-collapse: collapse; }
  table.sun-tbl th { font-size: 7.5pt; letter-spacing: .13em; text-transform: uppercase; font-weight: 700;
    color: #5c6b63; text-align: left; padding: 0 6pt 4pt 0; border-bottom: .75pt solid #16211c; }
  table.sun-tbl td { padding: 5pt 6pt 5pt 0; border-bottom: .5pt solid #e2e9e5; vertical-align: top; font-size: 9pt; }
  table.sun-tbl tr:last-child td { border-bottom: .5pt solid #c9d4ce; }
  .s-time { width: 74pt; white-space: nowrap; font-weight: 700; font-variant-numeric: tabular-nums; }
  .s-what { font-weight: 600; }
  .s-what .det { display: block; font-weight: 400; font-size: 8pt; color: #5c6b63; }
  .s-who { width: 30%; color: #33443c; font-size: 8.4pt; }
  /* Ruled and empty on purpose. */
  .s-note { width: 18%; border-bottom: .5pt solid #e2e9e5; }
  .s-off { font-weight: 700; letter-spacing: .08em; text-transform: uppercase; font-size: 7.5pt; }
  tr.is-off .s-what, tr.is-off .s-time { text-decoration: line-through; }
CSS;

// Sundays only, and only those with anything on them. weekday 0 is Sunday.
$sundays = array_values(array_filter(
    $model['days'],
    static function (array $d): bool {
        if ($d['entries'] === []) {
            return false;
        }
        return (int) (new DateTimeImmutable($d['date']))->format('w') === 0;
    },
));

ob_start(); ?>
<?php if ($sundays === []): ?>
  <p class="empty">No Sundays in this period have anything scheduled.</p>
<?php else: ?>
  <?php foreach ($sundays as $day):
      $date = new DateTimeImmutable($day['date']);

      // A roster entry names who is serving; everything else is the programme.
      // Splitting them means the "Serving" column can be filled from the only
      // place the feed actually carries people.
      $serving = [];
      $programme = [];
      foreach ($day['entries'] as $entry) {
          $kind = (string) ($entry['kind'] ?? '');
          // A service sheet is not a birthday list. They belong to the person
          // whose birthday it is, not to the people running the morning.
          if ($kind === 'birth' || $kind === 'anniv') {
              continue;
          }
          if ($kind === 'roster') {
              $serving[] = $entry;
          } else {
              $programme[] = $entry;
          }
      }
      if ($programme === []) {
          $programme = $serving;
          $serving = [];
      }
  ?>
  <section class="sun">
    <div class="sun-head">
      <span class="sun-date"><?= $e($date->format('l, j F Y')) ?></span>
      <span class="sun-sub"><?= count($programme) ?> item<?= count($programme) === 1 ? '' : 's' ?></span>
    </div>
    <table class="sun-tbl">
      <thead><tr>
        <th scope="col">Time</th>
        <th scope="col">Activity</th>
        <th scope="col">Serving</th>
        <th scope="col">Notes</th>
      </tr></thead>
      <tbody>
      <?php foreach ($programme as $entry):
          // "Cancelled — " is put on the title by the feed so the word survives
          // into print, where a strike-through alone would not.
          $off = str_starts_with((string) $entry['title'], 'Cancelled — ');
          $label = $off ? substr((string) $entry['title'], strlen('Cancelled — ')) : (string) $entry['title'];

          $when = $entry['all_day']
              ? 'All day'
              : trim((string) $entry['time'] . (($entry['end_time'] ?? '') !== '' ? ' – ' . $entry['end_time'] : ''));

          // Roster entries arrive as "Role — names". Matching them to an
          // activity by time is the only join the feed permits; anything
          // cleverer would be guessing.
          $who = [];
          foreach ($serving as $slot) {
              if (($slot['time'] ?? null) === ($entry['time'] ?? null) || ($slot['all_day'] ?? false)) {
                  $who[] = (string) $slot['title'];
              }
          }
      ?>
        <tr<?= $off ? ' class="is-off"' : '' ?>>
          <td class="s-time"><?= $e($when) ?></td>
          <td class="s-what">
            <?= $off ? '<span class="s-off">Cancelled — </span>' : '' ?><?= $e($label) ?>
            <?php
              // The feed's meta opens with the time, which this table already
              // has its own column for. Printing it twice wastes the width the
              // Notes column needs.
              $det = trim(preg_replace('/^[^·]*·\s*/u', '', (string) ($entry['meta'] ?? '')) ?? '');
              if ($det === (string) ($entry['meta'] ?? '') && preg_match('/^[\d:apmAPM\s–-]+$/u', $det) === 1) {
                  $det = '';
              }
            ?>
            <?php if ($det !== ''): ?>
              <span class="det"><?= $e($det) ?></span>
            <?php endif; ?>
          </td>
          <td class="s-who"><?= $who === [] ? '' : $e(implode('; ', $who)) ?></td>
          <td class="s-note"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endforeach; ?>
<?php endif; ?>
<?php
$body = ob_get_clean();
