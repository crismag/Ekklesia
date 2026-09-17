<?php
/**
 * Admin · Campus Locations — self-contained CRUD over church_campus.
 * Data + actions come from CampusAdminService (no ChurchCRM dependency).
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var list<array<string,mixed>> $campuses
 * @var array{count:int,main:?string} $stats
 * @var array<string,mixed> $editingCampus
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$ec = $editingCampus;
$v = static fn (string $k) => htmlspecialchars((string) ($ec[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$editing = (int) ($ec['campus_id'] ?? 0) > 0;

$noticeMap = [
    'saved'   => ['ok', 'Campus saved.'],
    'main'    => ['ok', 'Main campus updated.'],
    'deleted' => ['ok', 'Campus deleted.'],
    'error'   => ['err', $flash !== '' ? $flash : 'Something went wrong.'],
    'denied'  => ['err', 'You do not have permission to manage campuses.'],
];

ob_start();
?>
<style>
  .cx-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .cx-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}
  .cx-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  .cx-stats{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 14px}
  .cx-stat{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;padding:10px 14px;min-width:140px}
  .cx-stat b{display:block;font-size:20px}.cx-stat span{color:var(--muted,#5c6b63);font-size:12px}
  .cx-scroll{overflow-x:auto;position:relative}
  table.cx{width:100%;border-collapse:collapse;font-size:13.5px;background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;overflow:hidden}
  table.cx th,table.cx td{padding:9px 11px;text-align:left;border-bottom:1px solid var(--line,#eef2f5);white-space:nowrap}
  table.cx th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63);background:var(--surface-2,#f8fafb)}
  table.cx tr:last-child td{border-bottom:0}
  .cx-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:800}
  .cx-main{background:#0c5a45;color:#fff}.cx-off{background:#eceff3;color:#5a6b7b}.cx-on{background:#e6f7ec;color:#1a7a3a}
  .cx-actions{display:flex;gap:6px;flex-wrap:wrap}
  .cx-btn{font:inherit;font-size:12px;font-weight:700;border:1px solid var(--line,#c7d4cd);background:#fff;border-radius:7px;padding:5px 10px;cursor:pointer;text-decoration:none;color:var(--ink,#1b2a24)}
  .cx-btn:hover{border-color:#137a5f}
  .cx-btn.primary{background:#0c5a45;border-color:#0c5a45;color:#fff}
  .cx-btn.danger{color:#b3261e;border-color:#f0c3bd}
  form.cx-form{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;padding:16px;margin-top:16px}
  .cx-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media (max-width:640px){.cx-grid{grid-template-columns:1fr}}
  .cx-field label{display:block;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63);margin-bottom:4px}
  .cx-check{display:inline-flex;align-items:center;gap:8px;min-height:44px}
  .cx-check input[type=checkbox]{width:18px;height:18px;flex:0 0 auto}
  .cx-field input,.cx-field textarea{width:100%;font:inherit;padding:9px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff;color:var(--ink,#1b2a24)}
  .cx-field.full{grid-column:1 / -1}
  .cx-checks{display:flex;gap:18px;align-items:center;margin:4px 0}
  .cx-checks label{display:flex;gap:7px;align-items:center;font-weight:700;font-size:13px}
  .cx-req{color:#b3261e}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="cx-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<div class="cx-stats">
  <div class="cx-stat"><b><?= (int) $stats['count'] ?></b><span>Configured campuses</span></div>
  <div class="cx-stat"><b><?= $h($stats['main'] ?? '—') ?></b><span>Main campus</span></div>
</div>

<article class="admin-card">
  <div class="admin-card-head"><div><h2>Campuses</h2><p>Locations used across events, people, and the calendar.</p></div></div>
  <div class="admin-card-body cx-scroll">
    <table class="cx">
      <caption class="sr-only">Campuses, their codes and status</caption>
      <thead><tr><th scope="col">Name</th><th scope="col">Code</th><th scope="col">City</th><th scope="col">Status</th><?php if ($isAdmin): ?><th scope="col">Actions</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$campuses): ?>
        <tr><td colspan="<?= $isAdmin ? 5 : 4 ?>" style="color:var(--muted);padding:18px;text-align:center">No campuses yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($campuses as $c): $cid = (int) $c['campus_id']; ?>
        <tr>
          <td><b><?= $h($c['campus_name']) ?></b>
            <?php if ((int) $c['is_main'] === 1): ?> <span class="cx-badge cx-main">Main</span><?php endif; ?></td>
          <td><?= $h($c['campus_code']) ?></td>
          <td><?= $h(trim(($c['city'] ?? '') . (($c['state'] ?? '') !== '' ? ', ' . $c['state'] : ''))) ?></td>
          <td><span class="cx-badge <?= (int) $c['is_active'] === 1 ? 'cx-on' : 'cx-off' ?>"><?= (int) $c['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
          <?php if ($isAdmin): ?>
          <td><div class="cx-actions">
            <a class="cx-btn" href="<?= $base ?>/admin/campuses?campus_id=<?= $cid ?>">Edit</a>
            <?php if ((int) $c['is_main'] !== 1): ?>
              <form method="post" action="<?= $base ?>/admin/campuses" style="display:inline">
                <input type="hidden" name="action" value="setmain"><input type="hidden" name="campus_id" value="<?= $cid ?>">
                <button class="cx-btn" type="submit">Set main</button>
              </form>
              <form method="post" action="<?= $base ?>/admin/campuses" style="display:inline"
                    onsubmit="return confirm('Delete campus &quot;<?= $h($c['campus_name']) ?>&quot;? This cannot be undone.');">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="campus_id" value="<?= $cid ?>">
                <button class="cx-btn danger" type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </div></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</article>

<?php if ($isAdmin): ?>
<form class="cx-form" method="post" action="<?= $base ?>/admin/campuses">
  <h3 style="margin:0 0 12px"><?= $editing ? 'Edit campus' : 'Add a campus' ?></h3>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="campus_id" value="<?= (int) ($ec['campus_id'] ?? 0) ?>">
  <div class="cx-grid">
    <div class="cx-field"><label for="cx_campus_name">Name <span class="cx-req">*</span></label><input id="cx_campus_name" type="text" name="campus_name" maxlength="150" required value="<?= $v('campus_name') ?>"></div>
    <div class="cx-field"><label for="cx_campus_code">Code</label><input id="cx_campus_code" type="text" name="campus_code" maxlength="40" value="<?= $v('campus_code') ?>"></div>
    <div class="cx-field full"><label for="cx_address1">Address line 1</label><input id="cx_address1" type="text" name="address1" maxlength="150" value="<?= $v('address1') ?>"></div>
    <div class="cx-field full"><label for="cx_address2">Address line 2</label><input id="cx_address2" type="text" name="address2" maxlength="150" value="<?= $v('address2') ?>"></div>
    <div class="cx-field"><label for="cx_city">City</label><input id="cx_city" type="text" name="city" maxlength="100" value="<?= $v('city') ?>"></div>
    <div class="cx-field"><label for="cx_state">Province / State</label><input id="cx_state" type="text" name="state" maxlength="50" value="<?= $v('state') ?>"></div>
    <div class="cx-field"><label for="cx_zip">Postal code</label><input id="cx_zip" type="text" name="zip" maxlength="20" value="<?= $v('zip') ?>"></div>
    <div class="cx-field"><label for="cx_country">Country</label><input id="cx_country" type="text" name="country" maxlength="100" value="<?= $v('country') ?>"></div>
    <div class="cx-field"><label for="cx_phone">Phone</label><input id="cx_phone" type="text" name="phone" maxlength="50" value="<?= $v('phone') ?>"></div>
    <div class="cx-field"><label for="cx_email">Email</label><input id="cx_email" type="email" name="email" maxlength="120" value="<?= $v('email') ?>"></div>
    <div class="cx-field"><label for="cx_website">Website</label><input id="cx_website" type="text" name="website" maxlength="200" value="<?= $v('website') ?>"></div>
    <div class="cx-field"><label for="cx_time_zone">Time zone</label><input id="cx_time_zone" type="text" name="time_zone" maxlength="100" value="<?= $v('time_zone') ?>"></div>
    <?php if ($editing):
        $currentDefault = (int) ($ec['default_assignment_event_id'] ?? 0);
        $assignmentEvents = is_array($assignmentEvents ?? null) ? $assignmentEvents : [];
        $defaultStillListed = false;
        foreach ($assignmentEvents as $ev) {
            if ((int) ($ev['id'] ?? 0) === $currentDefault) {
                $defaultStillListed = true;
                break;
            }
        }
    ?>
    <div class="cx-field full">
      <label for="cx_default_assignment_event_id">Default assignment event</label>
      <select id="cx_default_assignment_event_id" name="default_assignment_event_id" style="width:100%;font:inherit;padding:9px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff">
        <option value="">None — scheduler opens with an empty event list until one is chosen</option>
        <?php if ($currentDefault > 0 && !$defaultStillListed): ?>
          <option value="<?= $currentDefault ?>" selected>Current default (event #<?= $currentDefault ?> — no longer eligible)</option>
        <?php endif; ?>
        <?php foreach ($assignmentEvents as $ev): $eid = (int) ($ev['id'] ?? 0); ?>
          <option value="<?= $eid ?>"<?= $eid === $currentDefault ? ' selected' : '' ?>><?= $h($ev['title'] ?? ('Event #' . $eid)) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="ee-hint" style="margin:6px 0 0;font-size:12px;color:var(--muted,#5c6b63)">Opened first when this campus is selected in Assignment Scheduling. Enable “Allow ministry assignments” on an event to list it here.</p>
    </div>
    <?php endif; ?>
    <div class="cx-field full"><label for="cx_notes">Notes</label><textarea id="cx_notes" name="notes" rows="2"><?= $v('notes') ?></textarea></div>
  </div>
  <div class="cx-checks">
    <label class="cx-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= (int) ($ec['is_active'] ?? 1) === 1 ? 'checked' : '' ?>> Active</label>
    <label class="cx-check"><input type="hidden" name="is_main" value="0"><input type="checkbox" name="is_main" value="1" <?= (int) ($ec['is_main'] ?? 0) === 1 ? 'checked' : '' ?>> Main campus</label>
  </div>
  <div class="cx-actions" style="margin-top:12px">
    <button class="cx-btn primary" type="submit"><?= $editing ? 'Save changes' : 'Create campus' ?></button>
    <?php if ($editing): ?><a class="cx-btn" href="<?= $base ?>/admin/campuses">Cancel / new</a><?php endif; ?>
  </div>
</form>
<?php else: ?>
  <p style="color:var(--muted);margin-top:14px">Only a portal-wide admin can add or edit campuses.</p>
<?php endif; ?>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'campuses',
    'pageTitle' => 'Campus Locations · Admin', 'pageSubtitle' => 'Manage church campuses.',
    'sectionTitle' => 'Campus Locations',
    'sectionDescription' => 'Add, edit, activate, or set the main campus. Used by events, people, and the calendar.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
