<?php
/**
 * People & Records · Household record — the people in a household, its
 * address and details, the households it is related to, others at the same
 * address, and its recent history. Data via FamilyAdminService and
 * RelatedFamiliesService; the refresh-location button reuses
 * /admin/people/geocode-family.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,mixed> $family
 * @var bool $missing
 * @var list<array<string,mixed>> $members
 * @var list<array<string,mixed>> $relatedLinks
 * @var list<array<string,mixed>> $residenceMates
 * @var list<array<string,mixed>> $familyPickList
 * @var array<string,string> $relTypes
 * @var array{entries:list<array<string,mixed>>,total:int} $recentHistory
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_records-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = records_h($basePath);
$h = 'records_h';
$f = $family;
$v = static fn (string $k): string => records_h($f[$k] ?? '');
$fid = (int) ($f['id'] ?? 0);
$missing = $missing ?? false;
$editing = $fid > 0 && !$missing;
$active = ($f['deactivated_on'] ?? null) === null;
$recentHistory = $recentHistory ?? ['entries' => [], 'total' => 0];
$noticeMap = ['saved' => ['ok', 'Household saved.'], 'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.']];
$familyName = trim((string) ($f['name'] ?? ''));
$address = implode(', ', array_filter(array_map(
    static fn (string $k): string => trim((string) ($f[$k] ?? '')),
    ['address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country'],
)));

ob_start();
echo records_styles();
?>
<?= records_notice_for($notice, $noticeMap) ?>

<?php if (!$isAdmin): ?>
  <section class="ek-empty">
    <strong>Households are for portal administrators</strong>
    <p>Household records hold addresses and contact details. You can look people up in the directory.</p>
    <a class="ek-btn" href="<?= $base ?>/people">Open the directory</a>
  </section>
<?php elseif ($missing): http_response_code(404); ?>
  <section class="ek-empty">
    <strong>This household is not on record</strong>
    <p>It may have been deleted, or merged into another household.</p>
    <span class="rec-actions"><a class="ek-btn" href="<?= $base ?>/admin/families">Back to households</a>
    <a class="ek-btn" href="<?= $base ?>/people/history?household=<?= $fid ?>">Look at its history</a></span>
  </section>
<?php else: ?>

<?php ob_start(); /* the details form: the whole page for a new household, the side column otherwise */ ?>
<section class="ek-card" aria-labelledby="fe-details">
  <div class="ek-card-head"><div><h2 id="fe-details"><?= $editing ? 'Household details' : 'New household' ?></h2>
    <p><?= $editing ? 'Address and contact shared by the people in this household.' : 'Name the household. You can add people to it next.' ?></p></div></div>
  <form method="post" action="<?= $base ?>/admin/families/save">
    <input type="hidden" name="id" value="<?= $editing ? $fid : 0 ?>">
    <div class="ek-card-body rec-fields" style="grid-template-columns:minmax(0,1fr)">
      <div class="ek-field"><label for="fe-name">Household name <span class="rec-req" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>
        <input class="ek-input" id="fe-name" type="text" name="name" maxlength="100" required value="<?= $v('name') ?>" placeholder="e.g. Cruz, Ana &amp; Ben"></div>
      <div class="ek-field"><label for="fe-addr1">Address line 1</label><input class="ek-input" id="fe-addr1" type="text" name="address_line1" maxlength="150" value="<?= $v('address_line1') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="fe-addr2">Address line 2</label><input class="ek-input" id="fe-addr2" type="text" name="address_line2" maxlength="150" value="<?= $v('address_line2') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="fe-city">City</label><input class="ek-input" id="fe-city" type="text" name="city" maxlength="100" value="<?= $v('city') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="fe-region">Province / state</label><input class="ek-input" id="fe-region" type="text" name="region" maxlength="50" value="<?= $v('region') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="fe-postal">Postal code</label><input class="ek-input" id="fe-postal" type="text" name="postal_code" maxlength="20" value="<?= $v('postal_code') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="fe-country">Country</label><input class="ek-input" id="fe-country" type="text" name="country" maxlength="60" value="<?= $v('country') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="fe-phone">Home phone</label><input class="ek-input" id="fe-phone" type="tel" name="home_phone" maxlength="40" value="<?= $v('home_phone') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="fe-email">Email</label><input class="ek-input" id="fe-email" type="email" name="email" maxlength="120" value="<?= $v('email') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="fe-wedding">Wedding anniversary</label><input class="ek-input" id="fe-wedding" type="date" name="wedding_date" value="<?= !empty($f['wedding_date']) ? $h(substr((string) $f['wedding_date'], 0, 10)) : '' ?>"></div>
      <label class="rec-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>> Active household</label>
      <label class="rec-check"><input type="hidden" name="send_newsletter" value="0"><input type="checkbox" name="send_newsletter" value="1" <?= (int) ($f['send_newsletter'] ?? 0) === 1 ? 'checked' : '' ?>> Send the newsletter</label>
    </div>
    <div class="rec-card-foot">
      <button class="ek-btn ek-btn-primary" type="submit"><?= $editing ? 'Save details' : 'Add household' ?></button>
      <?php if (!$editing): ?><a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/families">Cancel</a><?php endif; ?>
    </div>
  </form>
</section>
<?php $detailsCard = (string) ob_get_clean(); ?>

<?php if (!$editing): ?>
  <div style="max-width:40rem"><?= $detailsCard ?></div>
<?php else: ?>
<div class="rec-layout">
  <div class="rec-main">
    <section class="ek-card" aria-labelledby="fe-members">
      <div class="ek-card-head">
        <div><h2 id="fe-members">People</h2><p><?= count($members) ?> <?= count($members) === 1 ? 'person' : 'people' ?> in this household<?= $address !== '' ? ' · ' . $h($address) : '' ?>.</p></div>
        <a class="ek-btn" href="<?= $base ?>/admin/people/edit?household_id=<?= $fid ?>">Add a person</a>
      </div>
      <?php if ($members): ?>
        <div class="ek-table-wrap">
          <table class="ek-table rec-cards">
            <caption class="sr-only">People in <?= $h($familyName) ?></caption>
            <thead><tr><th scope="col">Name</th><th scope="col">Role in household</th><th scope="col">Contact</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): $mname = trim($m['first_name'] . ' ' . $m['last_name']); $contact = (string) ($m['email'] ?: $m['mobile_phone'] ?: ''); ?>
              <tr>
                <td class="rec-title" data-label="Name"><a class="rec-link" href="<?= $base ?>/admin/people/view?id=<?= (int) $m['id'] ?>"><?= $h($mname) ?></a></td>
                <td data-label="Role"><?= ($m['family_role'] ?? '') !== '' ? $h($m['family_role']) : '<span class="rec-muted">Not set</span>' ?></td>
                <td data-label="Contact" class="rec-muted<?= $contact === '' ? ' is-blank' : '' ?>" style="overflow-wrap:anywhere"><?= $contact !== '' ? $h($contact) : '—' ?></td>
                <td class="rec-action"><a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/people/edit?id=<?= (int) $m['id'] ?>">Edit<span class="sr-only"> <?= $h($mname) ?></span></a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="ek-card-body">
          <div class="ek-empty">
            <strong>Nobody is in this household yet</strong>
            <p>Add a new person here, or open an existing person's record and choose this household.</p>
            <a class="ek-btn ek-btn-primary" href="<?= $base ?>/admin/people/edit?household_id=<?= $fid ?>">Add a person</a>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section class="ek-card" aria-labelledby="fe-related">
      <div class="ek-card-head"><div><h2 id="fe-related">Related households</h2>
        <p>Link households that belong together — a married child's household, extended family, a shared home. A link never moves anyone.</p></div></div>
      <div class="ek-card-body">
        <?php if ($relatedLinks): ?>
          <ul class="rec-history">
            <?php foreach ($relatedLinks as $l): ?>
              <li style="grid-template-columns:minmax(0,1fr) auto;align-items:center">
                <span><a class="rec-link" href="<?= $base ?>/admin/families/edit?id=<?= (int) $l['other'] ?>"><?= $h($l['name']) ?></a> <span class="rec-muted">· <?= $h($l['label']) ?></span></span>
                <form method="post" action="<?= $base ?>/admin/families/link" class="rec-inline-form">
                  <input type="hidden" name="action" value="unlink"><input type="hidden" name="id" value="<?= $fid ?>"><input type="hidden" name="other_id" value="<?= (int) $l['other'] ?>">
                  <button class="ek-btn ek-btn-quiet" type="submit">Unlink<span class="sr-only"> <?= $h($l['name']) ?></span></button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="rec-muted" style="margin:0">No related households are linked.</p>
        <?php endif; ?>
      </div>
      <form method="post" action="<?= $base ?>/admin/families/link" class="rec-card-foot" style="display:block">
        <input type="hidden" name="action" value="link"><input type="hidden" name="id" value="<?= $fid ?>">
        <div class="rec-filters">
          <div class="ek-field rec-grow"><label for="fe-other">Link to household</label>
            <select class="ek-select" id="fe-other" name="other_id" required>
              <option value="">Choose a household</option>
              <?php foreach ($familyPickList as $pf): ?><option value="<?= (int) $pf['id'] ?>"><?= $h($pf['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="ek-field"><label for="relSelect">Relationship</label>
            <select class="ek-select" name="rel" id="relSelect">
              <?php foreach ($relTypes as $k => $lbl): ?><option value="<?= $h($k) ?>"><?= $h($lbl) ?></option><?php endforeach; ?>
            </select></div>
          <div class="ek-field" id="dirWrap"><label for="fe-direction">This household is the</label>
            <select class="ek-select" id="fe-direction" name="direction"><option value="parent">Parents' household</option><option value="child">Married child's household</option></select></div>
          <div class="rec-filters-actions"><button class="ek-btn" type="submit">Link household</button></div>
        </div>
      </form>
    </section>

    <?php if ($residenceMates): ?>
    <section class="ek-card" aria-labelledby="fe-residence">
      <div class="ek-card-head">
        <div><h2 id="fe-residence">Same address</h2>
          <p>Other households recorded at this street address. Usually that is correct — several households can share a home.</p></div>
        <a class="ek-btn" href="<?= $base ?>/admin/families/duplicates?by=address">Review as duplicates</a>
      </div>
      <div class="ek-card-body">
        <ul class="rec-history">
          <?php foreach ($residenceMates as $m): ?>
            <li><span><a class="rec-link" href="<?= $base ?>/admin/families/edit?id=<?= (int) $m['id'] ?>"><?= $h($m['name']) ?></a> <span class="rec-muted">· <?= (int) $m['members'] ?> <?= (int) $m['members'] === 1 ? 'person' : 'people' ?></span></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
    <?php endif; ?>

    <section class="ek-card" aria-labelledby="fe-history">
      <div class="ek-card-head">
        <div><h2 id="fe-history">Recent history</h2>
          <p><?= (int) $recentHistory['total'] === 0 ? 'No recorded changes.' : 'The latest ' . min(5, (int) $recentHistory['total']) . ' of ' . (int) $recentHistory['total'] . ' recorded ' . ((int) $recentHistory['total'] === 1 ? 'change' : 'changes') . '.' ?></p></div>
        <?php if ((int) $recentHistory['total'] > 0): ?><a class="ek-btn" href="<?= $base ?>/people/history?household=<?= $fid ?>&amp;type=household">All history</a><?php endif; ?>
      </div>
      <div class="ek-card-body"><?= records_history_list($recentHistory['entries'], 'Changes to this household will be listed here.') ?></div>
    </section>
  </div>

  <aside class="rec-aside" aria-label="Household details">
    <?= $detailsCard ?>
    <section class="ek-card" aria-labelledby="fe-location">
      <div class="ek-card-head"><div><h2 id="fe-location">Map location</h2><p>Used for maps and nearby-household lists.</p></div></div>
      <div class="ek-card-body">
        <p class="rec-muted rec-small" style="margin:0 0 8px"><?= ($f['latitude'] ?? null) !== null && (float) $f['latitude'] !== 0.0
            ? 'Located from the address.' : 'Not located yet.' ?></p>
        <button type="button" class="ek-btn" id="famRefreshCoords" data-family-id="<?= $fid ?>"<?= $address === '' ? ' disabled' : '' ?>>Refresh from the address</button>
        <p id="famCoordsMsg" class="rec-muted rec-small" role="status" style="margin:8px 0 0"><?= $address === '' ? 'Add an address first.' : '' ?></p>
      </div>
    </section>
    <section class="ek-card" aria-labelledby="fe-delete">
      <div class="ek-card-head"><div><h2 id="fe-delete" style="color:var(--rose-ink,#b84957)">Delete household</h2>
        <p>Only a household with nobody in it can be deleted. To combine two households, use <a class="rec-link" href="<?= $base ?>/admin/families/duplicates">duplicates review</a>.</p></div></div>
      <div class="ek-card-body">
        <form method="post" action="<?= $base ?>/admin/families/delete" onsubmit="return confirm('Delete the household &quot;<?= $h(addslashes($familyName)) ?>&quot;? This only works when nobody is in it, and cannot be undone.');">
          <input type="hidden" name="id" value="<?= $fid ?>">
          <button class="ek-btn ek-btn-danger" type="submit"<?= $members ? ' disabled title="People are still in this household"' : '' ?>>Delete household</button>
        </form>
      </div>
    </section>
  </aside>
</div>

<script>
(function () {
  var btn = document.getElementById('famRefreshCoords');
  if (!btn) return;
  var msg = document.getElementById('famCoordsMsg');
  var base = <?= json_encode($basePath) ?>;
  btn.addEventListener('click', function () {
    btn.disabled = true; var label = btn.innerHTML; btn.textContent = 'Looking up…';
    if (msg) { msg.textContent = ''; }
    var body = new URLSearchParams(); body.set('family_id', btn.getAttribute('data-family-id'));
    fetch(base + '/admin/people/geocode-family', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        btn.disabled = false; btn.innerHTML = label;
        if (res.ok && res.j && res.j.success) { if (msg) { msg.textContent = 'Location updated (' + res.j.lat.toFixed(4) + ', ' + res.j.lng.toFixed(4) + ').'; } }
        else if (msg) { msg.textContent = (res.j && res.j.error) || 'Could not find that address on the map.'; }
      }).catch(function () { btn.disabled = false; btn.innerHTML = label; if (msg) { msg.textContent = 'Network error. Try again.'; } });
  });
})();
(function () {
  var rel = document.getElementById('relSelect');
  var dir = document.getElementById('dirWrap');
  if (!rel || !dir) return;
  function sync() { dir.hidden = rel.value !== 'parent_child'; }
  rel.addEventListener('change', sync); sync();
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

$title = $missing ? 'Household not found' : ($editing ? ($familyName !== '' ? $familyName : 'Household #' . $fid) : 'Add household');
echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'families',
    'pageTitle' => $title . ' · Households', 'pageSubtitle' => 'Household record.',
    'sectionTitle' => $title,
    'sectionDescription' => $editing ? 'Household record: its people, address, related households and history.' : ($missing ? '' : 'A household groups the people who live together and share an address.'),
    'headerActions' => $isAdmin && $editing ? '<a class="ek-btn" href="' . $base . '/admin/families">All households</a>' : '',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
