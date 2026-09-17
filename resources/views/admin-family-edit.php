<?php
/**
 * Admin · Family editor — add/edit a household + view its members.
 * Data via FamilyAdminService. Reuses /admin/people/geocode-family for the
 * refresh-coordinates button.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,mixed> $family
 * @var list<array<string,mixed>> $members
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$f = $family;
$v = static fn (string $k) => htmlspecialchars((string) ($f[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$fid = (int) ($f['id'] ?? 0);
$editing = $fid > 0;
$active = ($f['deactivated_on'] ?? null) === null;
$noticeMap = ['saved' => ['ok', 'Family saved.'], 'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.']];

ob_start();
?>
<style>
  .fe-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .fe-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}.fe-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  .fe-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media (max-width:600px){.fe-grid{grid-template-columns:1fr}}
  .fe-field label{display:block;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63);margin-bottom:4px}
  .fe-field input,.fe-field select{width:100%;font:inherit;padding:9px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff;color:var(--ink,#1b2a24)}
  .fe-field.full{grid-column:1 / -1}
  .fe-sub{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--muted,#5c6b63);margin:16px 0 8px}
  .fe-checks{display:flex;gap:18px;margin:10px 0}.fe-checks label{display:flex;gap:7px;align-items:center;font-weight:700;font-size:13px}
  .fe-req{color:#b3261e}
  .fe-btn{font:inherit;font-size:13px;font-weight:800;border:0;border-radius:8px;padding:10px 16px;cursor:pointer;background:#0c5a45;color:#fff;text-decoration:none;display:inline-block}
  .fe-btn.sec{background:#fff;color:var(--ink,#1b2a24);border:1px solid var(--line,#c7d4cd)}
  .fe-btn.danger{background:#fff;color:#b3261e;border:1px solid #f0c3bd}
  .fe-mini{border:1px solid var(--line,#c7d4cd);background:#fff;border-radius:6px;padding:6px 10px;font:inherit;font-size:12px;cursor:pointer;color:var(--ink,#1b2a24)}
  table.fe-mem{width:100%;border-collapse:collapse;font-size:13px;margin-top:6px}
  table.fe-mem td,table.fe-mem th{padding:7px 9px;border-bottom:1px solid var(--line,#eef2f5);text-align:left}
  table.fe-mem th{font-size:12px;text-transform:uppercase;color:var(--muted,#5c6b63)}
  .muted{color:var(--muted,#5c6b63)}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="fe-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <article class="admin-card"><div class="admin-card-body" style="color:var(--muted)">Only a portal-wide admin can add or edit families.</div></article>
<?php else: ?>

<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
  <a class="fe-btn sec" href="<?= $base ?>/admin/families">&larr; Families</a>
  <span style="flex:1"></span>
  <?php if ($editing): ?>
    <form method="post" action="<?= $base ?>/admin/families/delete" onsubmit="return confirm('Delete family &quot;<?= $h($f['name']) ?>&quot;? Only works if it has no members.');">
      <input type="hidden" name="id" value="<?= $fid ?>">
      <button class="fe-btn danger" type="submit">Delete family</button>
    </form>
  <?php endif; ?>
</div>

<article class="admin-card">
  <div class="admin-card-head"><div><h2><?= $editing ? 'Edit family' : 'Add family' ?></h2>
    <p><?= $editing ? $h($f['name']) . ' · #' . $fid : 'A household groups people and shares an address.' ?></p></div></div>
  <div class="admin-card-body">
    <form method="post" action="<?= $base ?>/admin/families/save">
      <input type="hidden" name="id" value="<?= $fid ?>">
      <div class="fe-grid">
        <div class="fe-field full"><label>Family name <span class="fe-req">*</span></label><input type="text" name="name" maxlength="100" required value="<?= $v('name') ?>" placeholder="e.g. The Smith Family"></div>
        <div class="fe-field full"><label>Address line 1</label><input type="text" name="address_line1" maxlength="150" value="<?= $v('address_line1') ?>"></div>
        <div class="fe-field full"><label>Address line 2</label><input type="text" name="address_line2" maxlength="150" value="<?= $v('address_line2') ?>"></div>
        <div class="fe-field"><label>City</label><input type="text" name="city" maxlength="100" value="<?= $v('city') ?>"></div>
        <div class="fe-field"><label>Province / State</label><input type="text" name="region" maxlength="50" value="<?= $v('region') ?>"></div>
        <div class="fe-field"><label>Postal code</label><input type="text" name="postal_code" maxlength="20" value="<?= $v('postal_code') ?>"></div>
        <div class="fe-field"><label>Country</label><input type="text" name="country" maxlength="60" value="<?= $v('country') ?>"></div>
        <div class="fe-field"><label>Home phone</label><input type="tel" name="home_phone" maxlength="40" value="<?= $v('home_phone') ?>"></div>
        <div class="fe-field"><label>Email</label><input type="email" name="email" maxlength="120" value="<?= $v('email') ?>"></div>
        <div class="fe-field"><label>Wedding / anniversary date</label><input type="date" name="wedding_date" value="<?= $f['wedding_date'] ? $h(substr((string) $f['wedding_date'], 0, 10)) : '' ?>"></div>
      </div>
      <div class="fe-checks">
        <label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>> Active</label>
        <label><input type="hidden" name="send_newsletter" value="0"><input type="checkbox" name="send_newsletter" value="1" <?= (int) ($f['send_newsletter'] ?? 0) === 1 ? 'checked' : '' ?>> Send newsletter</label>
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin-top:6px">
        <button class="fe-btn" type="submit"><?= $editing ? 'Save changes' : 'Create family' ?></button>
        <?php if ($editing): ?>
          <button type="button" class="fe-mini" id="famRefreshCoords" data-family-id="<?= $fid ?>">&#10227; Refresh coordinates</button>
          <span id="famCoordsMsg" class="muted"></span>
        <?php endif; ?>
      </div>
    </form>
  </div>
</article>

<?php if ($editing): ?>
<article class="admin-card">
  <div class="admin-card-head"><div><h2>Members</h2><p><?= count($members) ?> in this family.</p></div></div>
  <div class="admin-card-body">
    <?php if ($members): ?>
      <table class="fe-mem">
        <thead><tr><th>Name</th><th>Family role</th><th>Contact</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($members as $m): ?>
          <tr>
            <td><a href="<?= $base ?>/admin/people/view?id=<?= (int) $m['id'] ?>" style="font-weight:700;color:var(--blue-ink,#0f4e97);text-decoration:none"><?= $h(trim($m['first_name'] . ' ' . $m['last_name'])) ?></a></td>
            <td class="muted"><?= $h($m['family_role'] ?? '') ?></td>
            <td class="muted"><?= $h($m['email'] ?: $m['mobile_phone'] ?: '') ?></td>
            <td><a class="fe-mini" href="<?= $base ?>/admin/people/edit?id=<?= (int) $m['id'] ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="muted">No members yet.</p>
    <?php endif; ?>
    <p style="margin-top:12px"><a class="fe-btn sec" href="<?= $base ?>/admin/people/edit">&#43; Add a person</a>
      <span class="muted" style="font-size:12px">— set their Family to “<?= $h($f['name']) ?>” to add them here.</span></p>
  </div>
</article>

<!-- Related families (admin-confirmed links) -->
<article class="admin-card">
  <div class="admin-card-head"><div><h2>Related families</h2>
    <p>Link this household to another family record — for a married child's family, extended family, or a shared household.</p></div></div>
  <div class="admin-card-body">
    <?php if ($relatedLinks): ?>
      <table class="fe-mem"><tbody>
        <?php foreach ($relatedLinks as $l): ?>
          <tr>
            <td><a href="<?= $base ?>/admin/families/edit?id=<?= (int) $l['other'] ?>" style="font-weight:700;color:var(--blue-ink,#0f4e97);text-decoration:none"><?= $h($l['name']) ?></a></td>
            <td class="muted"><?= $h($l['label']) ?></td>
            <td>
              <form method="post" action="<?= $base ?>/admin/families/link" style="display:inline">
                <input type="hidden" name="action" value="unlink"><input type="hidden" name="id" value="<?= $fid ?>"><input type="hidden" name="other_id" value="<?= (int) $l['other'] ?>">
                <button class="fe-mini" type="submit">Unlink</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    <?php else: ?>
      <p class="muted">No related families linked yet.</p>
    <?php endif; ?>

    <form method="post" action="<?= $base ?>/admin/families/link" class="fe-grid" style="margin-top:12px;align-items:end">
      <input type="hidden" name="action" value="link"><input type="hidden" name="id" value="<?= $fid ?>">
      <div class="fe-field"><label>Link to family</label>
        <select name="other_id" required>
          <option value="">— choose a family —</option>
          <?php foreach ($familyPickList as $pf): ?><option value="<?= (int) $pf['id'] ?>"><?= $h($pf['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="fe-field"><label>Relationship</label>
        <select name="rel" id="relSelect">
          <?php foreach ($relTypes as $k => $lbl): ?><option value="<?= $h($k) ?>"><?= $h($lbl) ?></option><?php endforeach; ?>
        </select></div>
      <div class="fe-field" id="dirWrap"><label>This family is the…</label>
        <select name="direction"><option value="parent">Parents' family</option><option value="child">Married child's family</option></select></div>
      <div class="fe-field"><label>&nbsp;</label><button class="fe-btn" type="submit">Link family</button></div>
    </form>
    <p class="muted" style="font-size:12px;margin-top:8px">Direction applies to “Parent ↔ married child”. This link is a presentation aid — it doesn't move anyone between families.</p>
  </div>
</article>

<!-- Same residence (derived from matching address) -->
<?php if ($residenceMates): ?>
<article class="admin-card">
  <div class="admin-card-head"><div><h2>Same residence</h2>
    <p>Other family records that share this street address. <em>Suggested</em> — a shared address doesn't mean one family.</p></div></div>
  <div class="admin-card-body">
    <table class="fe-mem"><tbody>
      <?php foreach ($residenceMates as $m): ?>
        <tr>
          <td><a href="<?= $base ?>/admin/families/edit?id=<?= (int) $m['id'] ?>" style="font-weight:700;color:var(--blue-ink,#0f4e97);text-decoration:none"><?= $h($m['name']) ?></a></td>
          <td class="muted"><?= (int) $m['members'] ?> member<?= (int) $m['members'] === 1 ? '' : 's' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
</article>
<?php endif; ?>
<?php endif; ?>

<script>
(function () {
  var btn = document.getElementById('famRefreshCoords');
  if (!btn) return;
  var msg = document.getElementById('famCoordsMsg');
  var base = <?= json_encode($base) ?>;
  btn.addEventListener('click', function () {
    btn.disabled = true; var label = btn.innerHTML; btn.textContent = 'Refreshing…';
    if (msg) { msg.style.color = ''; msg.textContent = ''; }
    var body = new URLSearchParams(); body.set('family_id', btn.getAttribute('data-family-id'));
    fetch(base + '/admin/people/geocode-family', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        btn.disabled = false; btn.innerHTML = label;
        if (res.ok && res.j && res.j.success) { if (msg) { msg.style.color = '#1a7a3a'; msg.textContent = 'Coordinates updated (' + res.j.lat.toFixed(4) + ', ' + res.j.lng.toFixed(4) + ').'; } }
        else if (msg) { msg.style.color = '#b3261e'; msg.textContent = (res.j && res.j.error) || 'Could not geocode.'; }
      }).catch(function () { btn.disabled = false; btn.innerHTML = label; if (msg) { msg.style.color = '#b3261e'; msg.textContent = 'Network error.'; } });
  });
})();
(function () {
  var rel = document.getElementById('relSelect');
  var dir = document.getElementById('dirWrap');
  if (!rel || !dir) return;
  function sync() { dir.style.display = rel.value === 'parent_child' ? '' : 'none'; }
  rel.addEventListener('change', sync); sync();
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'families',
    'pageTitle' => ($editing ? 'Edit family' : 'Add family') . ' · Admin', 'pageSubtitle' => 'Household management.',
    'sectionTitle' => $editing ? 'Edit family' : 'Add family',
    'sectionDescription' => 'Household details, address, and members.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
