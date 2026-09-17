<?php
/**
 * People & Records · Household duplicates — review households that share a
 * surname or an address, and merge the true duplicates. Never automatic: an
 * administrator picks the record to keep and the ones to merge into it. The
 * evidence ranking lives in FamilyAdminService.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var list<array{name:string,families:list<array<string,mixed>>}> $groups
 * @var string $mode  'name'|'address'
 * @var int $nameCount
 * @var int $addressCount
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_records-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = records_h($basePath);
$h = 'records_h';
$mode = ($mode ?? 'name') === 'address' ? 'address' : 'name';
$byAddress = $mode === 'address';
$noticeMap = ['saved' => ['ok', $flash !== '' ? $flash : 'Households merged.'], 'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.']];

ob_start();
echo records_styles();
?>
<style>
  .dup-group{margin:0}
  .dup-evidence{margin:4px 0 0;font-size:13px;color:var(--teal-ink,#117b6d);font-weight:600}
  .dup-section{display:grid;gap:var(--sp-3,12px)}
  .dup-section-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 10px;min-height:44px;padding:4px 0}
  .dup-section-head h2{margin:0;font-size:16px}
  details.dup-section>summary{cursor:pointer;list-style-position:outside}
  details.dup-section>summary h2{display:inline}
  .dup-blurb{flex:1 1 260px;margin:0;font-size:13px;color:var(--muted,#627169)}
  .dup-group td,.dup-group th{white-space:nowrap}
  .dup-group input[type=radio],.dup-group input[type=checkbox]{width:20px;height:20px}
  .dup-mark{display:flex;align-items:center;gap:8px}
</style>

<?= records_notice_for($notice, $noticeMap) ?>

<?php if (!$isAdmin): ?>
  <section class="ek-empty">
    <strong>Duplicates review is for portal administrators</strong>
    <p>Merging households moves people between records, so it is limited to administrators.</p>
    <a class="ek-btn" href="<?= $base ?>/people">Open the directory</a>
  </section>
<?php else: ?>

<nav class="ek-tabs is-sub" aria-label="Group households by">
  <a class="ek-tab" href="<?= $base ?>/admin/families/duplicates?by=name"<?= $byAddress ? '' : ' aria-current="page"' ?>>Same surname (<?= (int) ($nameCount ?? 0) ?>)</a>
  <a class="ek-tab" href="<?= $base ?>/admin/families/duplicates?by=address"<?= $byAddress ? ' aria-current="page"' : '' ?>>Same address (<?= (int) ($addressCount ?? 0) ?>)</a>
</nav>

<?php if (!$groups): ?>
  <section class="ek-empty">
    <strong><?= $byAddress ? 'No address is shared by more than one household' : 'No surname is shared by more than one household' ?></strong>
    <p>Nothing to review here. Duplicates can also be spotted from a household's “Same address” list.</p>
    <a class="ek-btn" href="<?= $base ?>/admin/families">Back to households</a>
  </section>
<?php else: ?>
  <?php
    // Split by how much evidence there is, so a page that opens with 27
    // surname collisions does not bury the two real duplicates among them.
    $likely = array_values(array_filter($groups, static fn (array $g): bool => ($g['rank'] ?? 0) >= 2));
    $worth  = array_values(array_filter($groups, static fn (array $g): bool => ($g['rank'] ?? 0) === 1));
    $bare   = array_values(array_filter($groups, static fn (array $g): bool => ($g['rank'] ?? 0) === 0));
  ?>
  <div class="ek-alert">
    <?php if ($byAddress): ?>
      <span>Several households can share one address — adult children, a lodger, a hosted parent. <strong>A shared address is normally correct, not a mistake.</strong>
      Only merge records that are genuinely the same household, such as a surname typo that split one household in two.</span>
    <?php else: ?>
      <span>These households are grouped because they share a surname. <strong>A shared surname is not evidence</strong> — <?= (int) ($nameCount ?? 0) ?>
      surnames on this roll belong to more than one household. Look for the ones that agree on something else as well.</span>
    <?php endif; ?>
  </div>

  <?php
    // One counter across all sections: data-group ties a Keep radio to its own
    // checkboxes, and restarting the index per section would wire two different
    // groups together.
    $groupIndex = 0;
    $sections = [
      ['rows' => $likely, 'title' => 'Very likely the same household',
       'blurb' => 'These agree on more than the thing they were grouped by, including a person of the same name in each.'],
      ['rows' => $worth,  'title' => 'Worth opening',
       'blurb' => 'One other detail agrees. Check before merging.'],
      ['rows' => $bare,   'title' => $byAddress ? 'Share an address and nothing else' : 'Share a surname and nothing else',
       'blurb' => $byAddress
         ? 'Different households at one address. Usually these are exactly right as they are.'
         : 'Different households with the same surname. Usually these are exactly right as they are.'],
    ];
  ?>

  <?php foreach ($sections as $section): if ($section['rows'] === []) { continue; } ?>
  <?php $collapse = $section['rows'] === $bare && ($likely !== [] || $worth !== []); ?>
  <<?= $collapse ? 'details class="dup-section"' : 'section class="dup-section"' ?>>
    <<?= $collapse ? 'summary' : 'div' ?> class="dup-section-head">
      <h2><?= $h($section['title']) ?></h2> <span class="ek-badge"><?= count($section['rows']) ?></span>
      <p class="dup-blurb"><?= $h($section['blurb']) ?></p>
    </<?= $collapse ? 'summary' : 'div' ?>>

  <?php foreach ($section['rows'] as $g): $gi = $groupIndex++; ?>
    <form method="post" action="<?= $base ?>/admin/families/merge" class="ek-card dup-group"
          onsubmit="return confirm('Merge the ticked households into the one marked Keep? Their people will be moved and the merged records deleted.');">
      <div class="ek-card-head"><div>
        <h3><?= $h($g['name']) ?> <span class="rec-muted" style="font-weight:400;font-size:13px">· <?= count($g['families']) ?> <?= $byAddress ? 'households at this address' : 'households' ?></span></h3>
        <?php if (($g['evidence'] ?? []) !== []): ?>
          <p class="dup-evidence">They also share <?= $h(implode(', and ', $g['evidence'])) ?>.</p>
        <?php endif; ?>
      </div></div>
      <div class="ek-table-wrap">
        <table class="ek-table">
          <caption class="sr-only">Households in the group <?= $h($g['name']) ?>: choose one to keep and tick the ones to merge into it</caption>
          <thead><tr><th scope="col">Keep</th><th scope="col">Merge</th><th scope="col">Household</th><th scope="col" class="is-num">People</th><th scope="col">City</th><th scope="col">Address</th><th scope="col">Email</th></tr></thead>
          <tbody>
          <?php foreach ($g['families'] as $fi => $f): $addr = trim($f['addr']); $fname = (string) $f['name']; ?>
            <tr>
              <td><input type="radio" name="keep_id" value="<?= (int) $f['id'] ?>" data-group="<?= $gi ?>"<?= $fi === 0 ? ' checked' : '' ?> required aria-label="Keep <?= $h($fname) ?> (#<?= (int) $f['id'] ?>)"></td>
              <td><input type="checkbox" name="merge_ids[]" value="<?= (int) $f['id'] ?>" data-group="<?= $gi ?>"<?= $fi === 0 ? ' disabled' : '' ?> aria-label="Merge <?= $h($fname) ?> (#<?= (int) $f['id'] ?>) into the kept household"></td>
              <td><a class="rec-link" href="<?= $base ?>/admin/families/edit?id=<?= (int) $f['id'] ?>"><?= $h($fname) ?></a> <span class="rec-muted">#<?= (int) $f['id'] ?></span></td>
              <td class="is-num"><?= (int) $f['members'] ?></td>
              <td><?= $h($f['city']) ?: '<span class="rec-muted">—</span>' ?></td>
              <td><?= $addr !== '' ? $h($addr) : '<span class="rec-muted">—</span>' ?></td>
              <td class="rec-muted"><?= $h($f['email']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="rec-card-foot">
        <button class="ek-btn ek-btn-primary" type="submit">Merge ticked into kept</button>
        <span class="rec-muted rec-small">Tick only records that are truly the same household.</span>
      </div>
    </form>
  <?php endforeach; ?>
  </<?= $collapse ? 'details' : 'section' ?>>
  <?php endforeach; ?>

  <script>
  // Keep the "Keep" record from also being a merge target: disable its checkbox.
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t && t.name === 'keep_id') {
      var g = t.getAttribute('data-group');
      document.querySelectorAll('input[name="merge_ids[]"][data-group="' + g + '"]').forEach(function (cb) {
        cb.disabled = (cb.value === t.value);
        if (cb.disabled) cb.checked = false;
      });
    }
  });
  </script>
<?php endif; ?>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'families',
    'pageTitle' => 'Duplicate households · Households', 'pageSubtitle' => 'Review and merge.',
    'sectionTitle' => 'Duplicate households',
    'sectionDescription' => 'Find household records that are really one household, and merge them so each household appears once.',
    'headerActions' => $isAdmin ? '<a class="ek-btn" href="' . $base . '/admin/families">All households</a>' : '',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
