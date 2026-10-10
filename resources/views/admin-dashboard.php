<?php

declare(strict_types=1);

/**
 * Admin · Overview (/admin).
 *
 * For the people who run the portal: is it healthy (sign-in, backups, data
 * checks), what needs doing, and the way into each admin area. Not a report on
 * the church — People & Records, Ministries and the rest have their own pages.
 *
 * Data: AdminDashboardService::build(), assembled by the route only for a
 * portal-wide admin.
 *
 * @var string                    $basePath
 * @var array<string,mixed>|null  $actor
 * @var array<string,mixed>       $campusSelector
 * @var array<string,mixed>       $dashboard
 */

require_once __DIR__ . '/_admin-shell.php';
require_once __DIR__ . '/_admin-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = admin_e($basePath);
$h = static fn (mixed $v): string => admin_e($v);

$status = is_array($dashboard['status'] ?? null) ? $dashboard['status'] : [];
$logins = is_array($status['logins'] ?? null) ? $status['logins'] : null;
$num = static fn (mixed $v): string => $v === null ? '—' : number_format((int) $v);
$lastBackup = $status['lastBackupAt'] ?? null;

/**
 * One tile: a figure an administrator acts on, where it leads, and a word on
 * what it means when it is not obvious from the number.
 */
$tile = static function (string $label, string $value, string $href, string $note = '', string $tone = '') use ($h): string {
    return '<a class="ek-stat ao-stat' . ($tone !== '' ? ' is-' . $tone : '') . '" href="' . $h($href) . '">'
        . '<span class="ek-stat-label">' . $h($label) . '</span>'
        . '<span class="ek-stat-value">' . $h($value) . '</span>'
        . ($note !== '' ? '<span class="ao-note">' . $h($note) . '</span>' : '')
        . '</a>';
};

$backupValue = $lastBackup === null ? 'Unknown' : ($lastBackup === 0 ? 'Never' : admin_ago((int) $lastBackup));
$backupDays = is_int($lastBackup) && $lastBackup > 0 ? intdiv(time() - $lastBackup, 86400) : null;
$backupTone = $lastBackup === null || $lastBackup === 0 || ($backupDays !== null && $backupDays >= 30) ? 'warn' : '';

$areas = [
    ['Users & access', '/admin/users', 'Logins, roles, passwords, and the person each login belongs to.',
        $logins !== null ? $num($logins['active']) . ' active ' . ((int) $logins['active'] === 1 ? 'login' : 'logins') : ''],
    ['Church information', '/admin/church-info', 'Name, contact details and time zone used across the portal.', ''],
    ['Campuses', '/admin/campuses', 'Locations, the main campus, and each campus’s default scheduling event.',
        ($status['campuses'] ?? null) !== null ? $num($status['campuses']) . ' ' . ((int) $status['campuses'] === 1 ? 'campus' : 'campuses') : ''],
    ['Portal notices', '/admin/announcements', 'Short messages for portal users, by audience and date.',
        ($status['noticesShowing'] ?? null) !== null ? $num($status['noticesShowing']) . ' showing now' : ''],
    ['Theme, header & footer', '/admin/theme', 'The portal’s colours, brand name and footer text.', ''],
    ['Backups & maintenance', '/admin/maintenance', 'Back up the databases and download saved copies.',
        $lastBackup === null ? '' : 'Last backup: ' . strtolower($backupValue)],
    ['Activity history', '/admin/history', 'Who changed which login or record, and when.', ''],
    ['System', '/admin/system', 'Configuration and environment, for troubleshooting.', ''],
];

$elsewhere = [
    ['Member records', '/admin/people', 'People & Records'],
    ['Households', '/admin/families', 'People & Records'],
    ['Import & export', '/admin/maintenance/import', 'People & Records'],
    ['Record settings', '/admin/options', 'People & Records'],
    ['Manage ministries', '/admin/ministries', 'Ministries'],
    ['Event categories', '/admin/event-types', 'Events & Calendar'],
    ['Calendar settings', '/admin/calendar', 'Events & Calendar'],
];

ob_start();
?>
<style>
  .ao-stat{align-content:start}
  .ao-stat.is-warn{border-color:color-mix(in srgb,var(--gold,#c79a3a) 60%,var(--line))}
  .ao-stat.is-warn .ek-stat-value{color:var(--gold-ink,#92651c)}
  .ao-note{font-size:12px;color:var(--muted)}
  .ao-alerts{list-style:none;margin:0;padding:0;display:grid}
  .ao-alert{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:var(--sp-3,12px);align-items:center;padding:var(--sp-3,12px) var(--sp-5,20px);border-top:1px solid var(--line)}
  .ao-alert:first-child{border-top:0}
  .ao-alert h3{margin:0;font-size:14px;font-weight:650}
  .ao-alert p{margin:2px 0 0;font-size:13px;color:var(--muted)}
  .ao-level{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
  @media (max-width:640px){
    .ao-alert{grid-template-columns:minmax(0,1fr)}
  }
  .ao-areas{display:grid;gap:var(--sp-3,12px);grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr))}
  .ao-area{display:grid;gap:4px;align-content:start;padding:var(--sp-3,12px) var(--sp-4,16px);border:1px solid var(--line);border-radius:var(--radius-lg,12px);background:var(--paper);color:var(--ink);text-decoration:none;min-height:44px}
  .ao-area:hover{border-color:var(--teal)}
  .ao-area:focus-visible{outline:2px solid var(--teal);outline-offset:2px}
  .ao-area strong{font-size:15px}
  .ao-area span{font-size:13px;color:var(--muted)}
  .ao-area em{font-style:normal;font-size:12px;font-weight:650;color:var(--teal-ink,var(--teal))}
  .ao-links{list-style:none;margin:0;padding:0;display:grid;gap:2px 16px;grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr))}
  .ao-links a{display:flex;flex-direction:column;padding:6px 0;min-height:44px;justify-content:center;text-decoration:none;color:var(--ink)}
  .ao-links a:hover strong{text-decoration:underline}
  .ao-links span{font-size:12px;color:var(--muted)}
</style>

<?php if (!$isAdmin): ?>
  <div class="ek-card"><div class="ek-card-body">The administration overview is for portal-wide administrators.</div></div>
<?php else: ?>

<section aria-labelledby="aoStatusHeading">
  <h2 id="aoStatusHeading" class="sr-only">Portal status</h2>
  <div class="ek-stats">
    <?= $tile('Active logins', $logins !== null ? $num($logins['active']) : '—', $basePath . '/admin/users?state=active',
        $logins !== null ? $num($logins['inactive']) . ' inactive' : 'Could not be read') ?>
    <?= $tile('Portal-wide admins', $logins !== null ? $num($logins['portalAdmins']) : '—', $basePath . '/admin/users?role=portal-admin',
        $logins !== null && (int) $logins['portalAdmins'] === 1 ? 'Only one' : '', $logins !== null && (int) $logins['portalAdmins'] < 2 ? 'warn' : '') ?>
    <?= $tile('Must change password', $logins !== null ? $num($logins['mustChange']) : '—', $basePath . '/admin/users?state=must-change',
        'Cannot use the portal until changed') ?>
    <?= $tile('Never signed in', $logins !== null ? $num($logins['never']) : '—', $basePath . '/admin/users?state=never') ?>
    <?= $tile('Last backup', $backupValue, $basePath . '/admin/maintenance',
        $lastBackup === null ? 'Backup folder cannot be read' : ($lastBackup === 0 ? 'No backup taken yet' : admin_when((int) $lastBackup)), $backupTone) ?>
    <?= $tile('Possible duplicate households', $num($status['duplicateHouseholds'] ?? null), $basePath . '/admin/families/duplicates',
        'Same name and same address or email', (int) ($status['duplicateHouseholds'] ?? 0) > 0 ? 'warn' : '') ?>
  </div>
</section>

<section class="ek-card" aria-labelledby="aoAttentionHeading">
  <div class="ek-card-head"><div>
    <h2 id="aoAttentionHeading">Needs attention</h2>
    <p>Most serious first.</p>
  </div></div>
  <?php $alerts = is_array($dashboard['attention'] ?? null) ? $dashboard['attention'] : []; ?>
  <?php if ($alerts === []): ?>
    <div class="ek-empty">
      <strong>Nothing needs attention</strong>
      <p>Logins look in order and a recent backup exists. Taking a backup before a large import is still a good habit.</p>
      <a class="ek-btn" href="<?= $base ?>/admin/maintenance">Backups &amp; maintenance</a>
    </div>
  <?php else: ?>
    <ul class="ao-alerts">
      <?php foreach ($alerts as $a): ?>
        <li class="ao-alert">
          <span class="ek-badge <?= $a['level'] === 'high' ? 'is-error' : ($a['level'] === 'medium' ? 'is-warn' : '') ?> ao-level"><?= $h(['high' => 'Urgent', 'medium' => 'Soon', 'low' => 'When you can'][$a['level']] ?? $a['level']) ?></span>
          <div>
            <h3><?= $h($a['title']) ?></h3>
            <p><?= $h($a['body']) ?></p>
          </div>
          <a class="ek-btn" href="<?= $h($a['href']) ?>"><?= $h($a['action']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="ek-card" aria-labelledby="aoAreasHeading">
  <div class="ek-card-head"><div>
    <h2 id="aoAreasHeading">Admin areas</h2>
  </div></div>
  <div class="ek-card-body">
    <div class="ao-areas">
      <?php foreach ($areas as [$label, $href, $desc, $detail]): ?>
        <a class="ao-area" href="<?= $base . $h($href) ?>">
          <strong><?= $h($label) ?></strong>
          <span><?= $h($desc) ?></span>
          <?php if ($detail !== ''): ?><em><?= $h($detail) ?></em><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="ek-card" aria-labelledby="aoElsewhereHeading">
  <div class="ek-card-head"><div>
    <h2 id="aoElsewhereHeading">Administration in other workspaces</h2>
    <p>Settings that belong with the records they affect.</p>
  </div></div>
  <div class="ek-card-body">
    <ul class="ao-links">
      <?php foreach ($elsewhere as [$label, $href, $workspace]): ?>
        <li><a href="<?= $base . $h($href) ?>"><strong><?= $h($label) ?></strong><span><?= $h($workspace) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath,
    'activeId' => 'overview',
    'pageTitle' => 'Administration',
    'pageSubtitle' => 'Portal status and admin areas',
    'sectionTitle' => 'Administration',
    'sectionDescription' => 'Whether the portal is in order — sign-in, backups and data checks — and the way into each admin area.',
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'isAdmin' => $isAdmin,
], static fn (): string => $content);
