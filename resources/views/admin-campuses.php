<?php
/**
 * Admin · Campuses — the church's locations, which one is main, and what the
 * scheduler opens first for each. Data and rules: CampusAdminService; every
 * change posts to POST /admin/campuses (portal-wide admins only).
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var list<array<string,mixed>> $campuses
 * @var array{count:int,main:?string} $stats
 * @var array<string,mixed> $editingCampus
 * @var list<array<string,mixed>> $assignmentEvents
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';
require_once __DIR__ . '/_admin-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = admin_e($basePath);
$h = static fn ($v): string => admin_e($v);
$ec = $editingCampus;
$v = static fn (string $k): string => admin_e($ec[$k] ?? '');
$editing = (int) ($ec['id'] ?? 0) > 0;

$field = static function (string $name, string $label, int $max, string $type = 'text', string $extra = '', bool $wide = false) use ($v): string {
    return '<div class="ek-field' . ($wide ? ' cx-wide' : '') . '"><label for="cx_' . $name . '">' . admin_e($label) . '</label>'
        . '<input class="ek-input" id="cx_' . $name . '" type="' . $type . '" name="' . $name . '" maxlength="' . $max . '" value="' . $v($name) . '"' . $extra . '></div>';
};

ob_start();
?>
<style>
  .cx-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--sp-3,12px)}
  .cx-grid .cx-wide{grid-column:1 / -1}
  @media (max-width:640px){.cx-grid{grid-template-columns:minmax(0,1fr)}}
  .cx-checks{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px) var(--sp-5,20px)}
  .cx-check{display:inline-flex;align-items:center;gap:8px;min-height:44px;font-weight:600}
  .cx-check input{width:18px;height:18px}
  .cx-actions{display:flex;flex-wrap:wrap;gap:4px;justify-content:flex-end}
  .cx-actions form{margin:0}
  .cx-name{font-weight:650}
  .cx-table td{vertical-align:middle}
  .cx-editing td{background:color-mix(in srgb,var(--soft) 70%,transparent)}
</style>

<?= admin_notice($notice, [
    'saved' => ['ok', 'Campus saved.'],
    'main' => ['ok', 'Main campus updated.'],
    'deleted' => ['ok', 'Campus deleted.'],
    'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.'],
    'denied' => ['error', 'You do not have permission to manage campuses.'],
]) ?>

<?php if (!$isAdmin): ?>
  <div class="ek-card"><div class="ek-card-body">Only a portal-wide admin can add or edit campuses.</div></div>
<?php else: ?>

<section class="ek-card" aria-labelledby="cxListHeading">
  <div class="ek-card-head">
    <div>
      <h2 id="cxListHeading">Campuses</h2>
      <p><?= $h((int) $stats['count'] . ((int) $stats['count'] === 1 ? ' campus' : ' campuses') . ' · main campus: ' . ($stats['main'] ?? 'not chosen')) ?></p>
    </div>
    <?php if ($editing): ?><a class="ek-btn" href="<?= $base ?>/admin/campuses#campus-form">Add a campus</a><?php endif; ?>
  </div>
  <?php if (!$campuses): ?>
    <div class="ek-empty">
      <strong>No campuses yet</strong>
      <p>Add the church's first location below. Events, people and the calendar use it.</p>
    </div>
  <?php else: ?>
  <div class="ek-table-wrap">
    <table class="ek-table cx-table">
      <caption class="sr-only">Campuses, their codes, city and status</caption>
      <thead><tr><th scope="col">Name</th><th scope="col">Code</th><th scope="col">City</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($campuses as $c): $cid = (int) $c['id']; ?>
        <tr<?= $editing && (int) $ec['id'] === $cid ? ' class="cx-editing"' : '' ?>>
          <td><span class="cx-name"><?= $h($c['name']) ?></span>
            <?php if ((int) $c['is_main'] === 1): ?> <span class="ek-badge is-ok">Main</span><?php endif; ?></td>
          <td><?= $h($c['code']) ?></td>
          <td><?= $h(trim(($c['city'] ?? '') . (($c['region'] ?? '') !== '' ? ', ' . $c['region'] : ''))) ?></td>
          <td><span class="ek-badge"><?= (int) $c['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
          <td><div class="cx-actions">
            <a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/campuses?campus_id=<?= $cid ?>#campus-form" aria-label="Edit <?= $h($c['name']) ?>">Edit</a>
            <?php if ((int) $c['is_main'] !== 1): ?>
              <form method="post" action="<?= $base ?>/admin/campuses">
                <input type="hidden" name="action" value="setmain"><input type="hidden" name="campus_id" value="<?= $cid ?>">
                <button class="ek-btn ek-btn-quiet" type="submit" aria-label="Make <?= $h($c['name']) ?> the main campus">Make main</button>
              </form>
              <form method="post" action="<?= $base ?>/admin/campuses"
                    onsubmit="return confirm('Delete the campus <?= $h(addslashes((string) $c['name'])) ?>? This cannot be undone.');">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="campus_id" value="<?= $cid ?>">
                <button class="ek-btn ek-btn-quiet" type="submit" aria-label="Delete <?= $h($c['name']) ?>">Delete</button>
              </form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="ek-card" id="campus-form" aria-labelledby="cxFormHeading" tabindex="-1">
  <div class="ek-card-head"><div>
    <h2 id="cxFormHeading"><?= $editing ? $h('Edit ' . ($ec['name'] ?? 'campus')) : 'Add a campus' ?></h2>
    <p><?= $editing ? 'Changes apply wherever this campus is shown.' : 'Only the name is required.' ?></p>
  </div></div>
  <div class="ek-card-body">
    <form method="post" action="<?= $base ?>/admin/campuses" style="display:grid;gap:var(--sp-4,16px)">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($ec['id'] ?? 0) ?>">
      <div class="cx-grid">
        <?= $field('name', 'Name (required)', 150, 'text', ' required') ?>
        <?= $field('code', 'Short code', 40) ?>
        <?= $field('address_line1', 'Address line 1', 150, 'text', '', true) ?>
        <?= $field('address_line2', 'Address line 2', 150, 'text', '', true) ?>
        <?= $field('city', 'City', 100) ?>
        <?= $field('region', 'Province / state', 50) ?>
        <?= $field('postal_code', 'Postal code', 20) ?>
        <?= $field('country', 'Country', 100) ?>
        <?= $field('phone', 'Phone', 50, 'tel') ?>
        <?= $field('email', 'Email', 120, 'email') ?>
        <?= $field('website', 'Website', 200, 'text', ' inputmode="url"') ?>
        <?= $field('time_zone', 'Time zone', 64, 'text', ' placeholder="America/Toronto"') ?>
        <?php if ($editing):
            $currentDefault = (int) ($ec['default_scheduling_event_id'] ?? 0);
            $assignmentEvents = is_array($assignmentEvents ?? null) ? $assignmentEvents : [];
            $defaultStillListed = false;
            foreach ($assignmentEvents as $ev) {
                if ((int) ($ev['id'] ?? 0) === $currentDefault) {
                    $defaultStillListed = true;
                    break;
                }
            }
        ?>
        <div class="ek-field cx-wide">
          <label for="cx_default_scheduling_event_id">Default assignment event</label>
          <select class="ek-select" id="cx_default_scheduling_event_id" name="default_scheduling_event_id" aria-describedby="cxDefaultHint">
            <option value="">None — the scheduler opens with no event chosen</option>
            <?php if ($currentDefault > 0 && !$defaultStillListed): ?>
              <option value="<?= $currentDefault ?>" selected>Current default (event #<?= $currentDefault ?>, no longer eligible)</option>
            <?php endif; ?>
            <?php foreach ($assignmentEvents as $ev): $eid = (int) ($ev['id'] ?? 0); ?>
              <option value="<?= $eid ?>"<?= $eid === $currentDefault ? ' selected' : '' ?>><?= $h($ev['title'] ?? ('Event #' . $eid)) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="ek-hint" id="cxDefaultHint">Opened first when this campus is selected in assignment scheduling. Turn on “Allow ministry assignments” on an event to list it here.</span>
        </div>
        <?php endif; ?>
        <div class="ek-field cx-wide"><label for="cx_notes">Notes</label><textarea class="ek-input" id="cx_notes" name="notes" rows="2"><?= $v('notes') ?></textarea></div>
      </div>
      <div class="cx-checks">
        <label class="cx-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= (int) ($ec['is_active'] ?? 1) === 1 ? 'checked' : '' ?>> Active</label>
        <label class="cx-check"><input type="hidden" name="is_main" value="0"><input type="checkbox" name="is_main" value="1" <?= (int) ($ec['is_main'] ?? 0) === 1 ? 'checked' : '' ?>> Main campus</label>
      </div>
      <div class="ek-toolbar">
        <button class="ek-btn ek-btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Add campus' ?></button>
        <?php if ($editing): ?><a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/campuses">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</section>

<?php endif; ?>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'campuses',
    'pageTitle' => 'Campuses', 'pageSubtitle' => 'Church locations.',
    'sectionTitle' => 'Campuses',
    'sectionDescription' => 'The church’s locations, which one is main, and the event the scheduler opens first for each. Used by events, people and the calendar.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
