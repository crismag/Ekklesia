<?php
/**
 * Admin · Church information — the church's name, contact details, location
 * and time zone, as the portal uses them. Stored by ChurchInfoService
 * (config/church-info.json); saved through POST /admin/church-info, which is
 * limited to portal-wide admins.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,string> $info
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';
require_once __DIR__ . '/_admin-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = admin_e($basePath);
$val = static fn (string $k): string => admin_e($info[$k] ?? '');

/** One labelled input. */
$field = static function (string $name, string $label, int $max, string $type = 'text', string $extra = '') use ($val): string {
    return '<div class="ek-field"><label for="ci_' . $name . '">' . admin_e($label) . '</label>'
        . '<input class="ek-input" id="ci_' . $name . '" type="' . $type . '" name="' . $name . '" maxlength="' . $max . '" value="' . $val($name) . '"' . $extra . '></div>';
};

ob_start();
?>
<style>
  .ci-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--sp-3,12px)}
  .ci-grid .is-wide{grid-column:1 / -1}
  @media (max-width:640px){.ci-grid{grid-template-columns:minmax(0,1fr)}}
  .ci-form{display:grid;gap:var(--sp-4,16px)}
  .ci-bar{display:flex;flex-wrap:wrap;align-items:center;gap:var(--sp-3,12px)}
</style>

<?= admin_notice($notice, [
    'saved' => ['ok', 'Church information saved.'],
    'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.'],
]) ?>

<?php if (!$isAdmin): ?>
  <div class="ek-card"><div class="ek-card-body">Only a portal-wide admin can edit church information.</div></div>
<?php else: ?>
<form class="ci-form" method="post" action="<?= $base ?>/admin/church-info">
  <section class="ek-card" aria-labelledby="ciIdentity">
    <div class="ek-card-head"><div>
      <h2 id="ciIdentity">Name</h2>
      <p>How the portal names the church, and where its public website is.</p>
    </div></div>
    <div class="ek-card-body ci-grid">
      <?= $field('name', 'Church name (required)', 150, 'text', ' required') ?>
      <?= $field('website', 'Website', 200, 'text', ' inputmode="url" placeholder="https://"') ?>
    </div>
  </section>

  <section class="ek-card" aria-labelledby="ciContact">
    <div class="ek-card-head"><div>
      <h2 id="ciContact">Contact</h2>
      <p>The church office, for people who need help with the portal or their records.</p>
    </div></div>
    <div class="ek-card-body ci-grid">
      <?= $field('phone', 'Phone', 50, 'tel') ?>
      <?= $field('email', 'Email', 120, 'email') ?>
    </div>
  </section>

  <section class="ek-card" aria-labelledby="ciLocation">
    <div class="ek-card-head"><div>
      <h2 id="ciLocation">Location and time zone</h2>
      <p>Each campus keeps its own address under Campuses; this is the church's main address.</p>
    </div></div>
    <div class="ek-card-body ci-grid">
      <div class="is-wide"><?= $field('address', 'Street address', 150) ?></div>
      <?= $field('city', 'City', 100) ?>
      <?= $field('state', 'Province / state', 60) ?>
      <?= $field('zip', 'Postal code', 20) ?>
      <?= $field('country', 'Country', 100) ?>
      <div class="is-wide">
        <?= $field('timeZone', 'Time zone', 100, 'text', ' list="ciZones" aria-describedby="ciZoneHint" placeholder="America/Toronto"') ?>
        <span class="ek-hint" id="ciZoneHint">A place name such as America/Toronto. Start typing to see the options.</span>
        <datalist id="ciZones">
          <?php foreach (DateTimeZone::listIdentifiers() as $zone): ?><option value="<?= admin_e($zone) ?>"><?php endforeach; ?>
        </datalist>
      </div>
    </div>
  </section>

  <div class="ci-bar">
    <button class="ek-btn ek-btn-primary" type="submit">Save church information</button>
    <span class="ek-hint">Campus details are edited under <a href="<?= $base ?>/admin/campuses">Campuses</a>.</span>
  </div>
</form>
<?php endif; ?>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'church-info',
    'pageTitle' => 'Church information', 'pageSubtitle' => 'Name, contact and location.',
    'sectionTitle' => 'Church information',
    'sectionDescription' => 'The church’s name, contact details, address and time zone, as the portal uses them.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
