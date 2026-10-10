<?php

declare(strict_types=1);

/**
 * Admin · Calendar — holiday calendars and calendar sources.
 *
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var array<string,mixed> $campusSelector
 */

require_once __DIR__ . '/_admin-shell.php';

$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);

$sync = new \App\Services\HolidayCalendarSync(dirname(__DIR__, 2) . '/config/holidays.json');
$calendars = $sync->calendars();
$today = new DateTimeImmutable();
$runningOut = $calendars->exhausted($today->modify('+6 months'));
$runningOutIds = array_column($runningOut, 'id');

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$flash = (string) ($_SESSION['calendar_flash'] ?? '');
$flashKind = (string) ($_SESSION['calendar_flash_kind'] ?? 'ok');
unset($_SESSION['calendar_flash'], $_SESSION['calendar_flash_kind']);

ob_start();
?>
<style>
  .hc-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .hc-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}
  .hc-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  .hc-list{list-style:none;margin:0;padding:0}
  .hc-item{display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;padding:12px 0;
    border-bottom:1px solid var(--line,#eef2f5)}
  .hc-item:last-child{border-bottom:0}
  .hc-swatch{width:14px;height:14px;border-radius:4px;flex:0 0 auto}
  .hc-name{font-weight:700;font-size:14px;flex:1 1 200px;min-width:0}
  .hc-facts{font-size:12.5px;color:var(--muted,#5c6b63);flex:1 1 260px}
  .hc-stale{color:#8a4b12;font-weight:700}
  .hc-actions{display:flex;gap:6px;flex-wrap:wrap}
  .hc-btn{font:inherit;font-size:12px;font-weight:700;border:1px solid var(--line,#c7d4cd);
    background:#fff;border-radius:7px;padding:8px 12px;min-height:36px;cursor:pointer;
    color:var(--ink,#1b2a24);display:inline-flex;align-items:center}
  .hc-btn:hover{border-color:#137a5f}
  .hc-btn.primary{background:#0c5a45;border-color:#0c5a45;color:#fff}
  .hc-btn.danger{color:#b3261e;border-color:#f0c3bd}
  .hc-off{opacity:.55}
  .hc-add{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-top:6px}
  .hc-field label{display:block;font-size:12px;font-weight:800;text-transform:uppercase;
    letter-spacing:.03em;color:var(--muted,#5c6b63);margin-bottom:4px}
  .hc-field input{width:100%;font:inherit;padding:9px 10px;min-height:40px;
    border:1px solid var(--line,#c7d4cd);border-radius:8px}
  .hc-hint{font-size:12px;color:var(--muted,#5c6b63);margin:8px 0 0}
</style>

<?php if ($flash !== ''): ?>
  <div class="hc-alert <?= $flashKind === 'err' ? 'err' : 'ok' ?>"><?= $h($flash) ?></div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <article class="admin-card"><div class="admin-card-body" style="color:var(--muted)">
    Only a portal administrator can change calendar sources.
  </div></article>
<?php else: ?>

<article class="admin-card">
  <div class="admin-card-head"><div>
    <h2>Holiday calendars</h2>
    <p>Public holidays drawn on the church calendar. Each one is a layer people can switch off.</p>
  </div></div>
  <div class="admin-card-body">
    <?php if ($runningOut !== []): ?>
      <div class="hc-alert err">
        <strong><?= count($runningOut) ?> holiday calendar<?= count($runningOut) === 1 ? '' : 's' ?>
        <?= count($runningOut) === 1 ? 'is' : 'are' ?> running out.</strong>
        Sync <?= count($runningOut) === 1 ? 'it' : 'them' ?> to bring in the coming years — a calendar
        that has run out shows no holidays, which looks exactly like a quiet year.
      </div>
    <?php endif; ?>

    <?php if ($calendars->all() === []): ?>
      <p class="hc-hint" style="margin-bottom:14px"><strong>No holiday calendars yet.</strong>
        Add one below — <code class="code">CA</code> with region <code class="code">CA-ON</code> for
        Ontario, <code class="code">PH</code> for the Philippines, <code class="code">US</code> for the
        United States.</p>
    <?php else: ?>
      <ul class="hc-list">
        <?php foreach ($calendars->all() as $calendar): ?>
          <?php
            $holidays = $calendar['holidays'];
            $last = end($holidays);
            $through = $last === false ? null : $last['date'];
            $stale = in_array($calendar['id'], $runningOutIds, true);
          ?>
          <li class="hc-item<?= $calendar['enabled'] ? '' : ' hc-off' ?>">
            <span class="hc-swatch" style="background:<?= $h($calendar['color']) ?>" aria-hidden="true"></span>
            <span class="hc-name">
              <?= $h($calendar['label']) ?>
              <?php if (!$calendar['enabled']): ?><span class="hc-facts">(hidden)</span><?php endif; ?>
            </span>
            <span class="hc-facts">
              <?= count($holidays) ?> holidays
              <?php if ($through !== null): ?>
                · through <span class="<?= $stale ? 'hc-stale' : '' ?>"><?= $h(substr($through, 0, 4)) ?></span>
              <?php endif; ?>
              <?php if ($calendar['fetched_on'] !== ''): ?>
                · synced <?= $h($calendar['fetched_on']) ?>
              <?php endif; ?>
            </span>
            <span class="hc-actions">
              <form method="post" action="<?= $base ?>/admin/calendar/holidays" style="display:inline">
                <input type="hidden" name="action" value="refresh">
                <input type="hidden" name="id" value="<?= $h($calendar['id']) ?>">
                <button class="hc-btn<?= $stale ? ' primary' : '' ?>" type="submit">Sync</button>
              </form>
              <form method="post" action="<?= $base ?>/admin/calendar/holidays" style="display:inline">
                <input type="hidden" name="action" value="<?= $calendar['enabled'] ? 'disable' : 'enable' ?>">
                <input type="hidden" name="id" value="<?= $h($calendar['id']) ?>">
                <button class="hc-btn" type="submit"><?= $calendar['enabled'] ? 'Hide' : 'Show' ?></button>
              </form>
              <form method="post" action="<?= $base ?>/admin/calendar/holidays" style="display:inline"
                    onsubmit="return confirm('Remove <?= $h($calendar['label']) ?> from the calendar?\n\nIts holidays stop appearing and the cached dates are deleted. You can add it back at any time, which fetches them again.');">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="id" value="<?= $h($calendar['id']) ?>">
                <button class="hc-btn danger" type="submit">Remove</button>
              </form>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>

      <form method="post" action="<?= $base ?>/admin/calendar/holidays" style="margin-top:14px">
        <input type="hidden" name="action" value="refresh-all">
        <button class="hc-btn primary" type="submit">Sync every calendar</button>
      </form>
    <?php endif; ?>

    <p class="hc-hint">
      Each sync fetches <strong>six full years</strong> from today, so there is nothing to do again for
      a long while. Once a year is plenty; there is no schedule and nothing runs on its own, because a
      job that silently stopped working is the worst way for a calendar to go out of date. This page
      tells you when one is running low.
    </p>
  </div>
</article>

<article class="admin-card">
  <div class="admin-card-head"><div>
    <h2>Add a country</h2>
    <p>Holidays come from a public source, so nothing has to be typed in by hand.</p>
  </div></div>
  <div class="admin-card-body">
    <form method="post" action="<?= $base ?>/admin/calendar/holidays">
      <input type="hidden" name="action" value="add">
      <div class="hc-add">
        <div class="hc-field"><label for="hc_country">Country</label>
          <input id="hc_country" name="country" maxlength="2" placeholder="CA" required></div>
        <div class="hc-field"><label for="hc_region">Province or state</label>
          <input id="hc_region" name="region" maxlength="6" placeholder="CA-ON"></div>
        <div class="hc-field"><label for="hc_label">Name to show</label>
          <input id="hc_label" name="label" maxlength="60" placeholder="Canada holidays (Ontario)"></div>
        <div class="hc-field"><label for="hc_color">Colour</label>
          <input id="hc_color" name="color" type="color" value="#8a4b12"></div>
      </div>
      <p class="hc-hint">Two-letter country code. Leave the province blank for national holidays only —
        with it, you also get the ones that province observes. <code class="code">CA</code> +
        <code class="code">CA-ON</code> gives Ontario's eleven; <code class="code">CA</code> alone gives
        the six that are national.</p>
      <p style="margin-top:12px"><button class="hc-btn primary" type="submit">Add and sync</button></p>
    </form>
  </div>
</article>

<article class="admin-card">
  <div class="admin-card-head"><div>
    <h2>Other calendar sources</h2>
    <p>Birthdays, anniversaries, ministry schedules and rosters.</p>
  </div></div>
  <div class="admin-card-body" style="color:var(--muted);font-size:13px;line-height:1.55">
    These come from the church's own records and are always available — each is a layer on the
    calendar that a viewer can switch on or off for themselves. There is nothing to configure here.
  </div>
</article>

<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath,
    'activeId' => 'calendar',
    'pageTitle' => 'Calendar · Admin',
    'pageSubtitle' => 'Holiday calendars and calendar sources',
    'sectionTitle' => 'Calendar',
    'sectionDescription' => 'Which holidays and which sources appear on the church calendar.',
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'isAdmin' => $isAdmin,
], static fn (): string => $content);
