<?php
/**
 * Admin · Family duplicates — review families that share a surname and merge the
 * true duplicates. Never auto-merges: an admin picks which record to keep and
 * which to merge into it. No schema change.
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
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$mode = ($mode ?? 'name') === 'address' ? 'address' : 'name';
$byAddress = $mode === 'address';
$noticeMap = ['saved' => ['ok', 'Families merged.'], 'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.']];

ob_start();
?>
<style>
  .dup-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .dup-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}.dup-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  .dup-group{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;margin:0 0 12px;overflow:hidden}
  .dup-head{padding:10px 14px;border-bottom:1px solid var(--line,#eef2f5);font-weight:800}
  .dup-head small{font-weight:600;color:var(--muted,#5c6b63)}
  .dup-evidence{font-weight:600;font-size:12.5px;color:#12633f;margin-top:3px}
  .dup-section{margin:0 0 18px}
  .dup-section-head{padding:8px 2px;font-size:13px;color:var(--ink,#1b2a24);min-height:44px;
    display:flex;align-items:center;flex-wrap:wrap;gap:8px}
  details.dup-section>summary.dup-section-head{cursor:pointer}
  .dup-n{display:inline-block;padding:1px 8px;border-radius:999px;background:var(--soft,#eef4f0);
    color:var(--muted,#5c6b63);font-size:12px;font-weight:700}
  .dup-blurb{font-weight:400;color:var(--muted,#5c6b63);font-size:12.5px;flex:1 1 260px}
  table.dup{width:100%;border-collapse:collapse;font-size:13px}
  table.dup th,table.dup td{padding:8px 11px;text-align:left;border-bottom:1px solid var(--line,#eef2f5);white-space:nowrap}
  table.dup th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63);background:var(--surface-2,#f8fafb)}
  table.dup tr:last-child td{border-bottom:0}
  .dup-foot{padding:10px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;border-top:1px solid var(--line,#eef2f5)}
  .dup-btn{font:inherit;font-size:13px;font-weight:800;border:0;border-radius:8px;padding:9px 14px;cursor:pointer;background:#0c5a45;color:#fff}
  .dup-btn.sec{background:#fff;color:var(--ink,#1b2a24);border:1px solid var(--line,#c7d4cd);font-weight:700}
  .muted{color:var(--muted,#5c6b63)}
  .flag{display:inline-block;font-size:12px;font-weight:800;padding:1px 7px;border-radius:999px;background:#fdf0d5;color:#8a5a00}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="dup-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
  <a class="dup-btn sec" href="<?= $base ?>/admin/families">&larr; Families</a>
</div>
<div style="display:flex;gap:6px;margin-bottom:14px">
  <a class="dup-btn <?= $byAddress ? 'sec' : '' ?>" href="<?= $base ?>/admin/families/duplicates?by=name" style="text-decoration:none">By surname (<?= (int) ($nameCount ?? 0) ?>)</a>
  <a class="dup-btn <?= $byAddress ? '' : 'sec' ?>" href="<?= $base ?>/admin/families/duplicates?by=address" style="text-decoration:none">By address (<?= (int) ($addressCount ?? 0) ?>)</a>
</div>

<?php if (!$isAdmin): ?>
  <article class="admin-card"><div class="admin-card-body" style="color:var(--muted)">Only a portal-wide admin can review and merge families.</div></article>
<?php elseif (!$groups): ?>
  <article class="admin-card"><div class="admin-card-body"><?= $byAddress ? 'No addresses are shared by more than one family. 🎉' : 'No duplicate surnames found. 🎉' ?></div></article>
<?php else: ?>
  <?php
    // Split by how much evidence there is, so a page that opens with 27
    // surname collisions does not bury the two real duplicates among them.
    $likely = array_values(array_filter($groups, static fn (array $g): bool => ($g['rank'] ?? 0) >= 2));
    $worth  = array_values(array_filter($groups, static fn (array $g): bool => ($g['rank'] ?? 0) === 1));
    $bare   = array_values(array_filter($groups, static fn (array $g): bool => ($g['rank'] ?? 0) === 0));
  ?>
  <?php if ($byAddress): ?>
    <p class="muted" style="margin:0 0 14px">Several households can share one address — adult children, a lodger, a hosted
      parent. <b>A shared address is normally correct, not a mistake.</b> Only merge records that are genuinely the same
      household, such as a surname typo that split one family in two.</p>
  <?php else: ?>
    <p class="muted" style="margin:0 0 14px">Families are grouped here because they share a surname. <b>A shared surname
      is not evidence</b> — <?= (int) ($nameCount ?? 0) ?> surnames on this roster belong to more than one family. Look for
      the ones that agree on something else as well.</p>
  <?php endif; ?>

  <?php
    // One counter across all sections: data-group ties a Keep radio to its own
    // checkboxes, and restarting the index per section would wire two different
    // groups together.
    $groupIndex = 0;
    $sections = [
      ['rows' => $likely, 'title' => 'Very likely the same family',
       'blurb' => 'These agree on more than the thing they were grouped by, including a member of the same name in each.'],
      ['rows' => $worth,  'title' => 'Worth opening',
       'blurb' => 'One other detail agrees. Check before merging.'],
      ['rows' => $bare,   'title' => $byAddress ? 'Share an address and nothing else' : 'Share a surname and nothing else',
       'blurb' => $byAddress
         ? 'Different households at one address. Usually these are exactly right as they are.'
         : 'Different families with the same surname. Usually these are exactly right as they are.'],
    ];
  ?>

  <?php foreach ($sections as $section): if ($section['rows'] === []) { continue; } ?>
  <?php $collapse = $section['rows'] === $bare && ($likely !== [] || $worth !== []); ?>
  <<?= $collapse ? 'details class="dup-section"' : 'section class="dup-section"' ?>>
    <<?= $collapse ? 'summary' : 'div' ?> class="dup-section-head">
      <b><?= $h($section['title']) ?></b> <span class="dup-n"><?= count($section['rows']) ?></span>
      <span class="dup-blurb"><?= $h($section['blurb']) ?></span>
    </<?= $collapse ? 'summary' : 'div' ?>>

  <?php foreach ($section['rows'] as $g): $gi = $groupIndex++; ?>
    <form method="post" action="<?= $base ?>/admin/families/merge" class="dup-group"
          onsubmit="return confirm('Merge the ticked records into the one marked Keep? People will be moved and the merged records deleted.');">
      <div class="dup-head"><?= $byAddress ? '📍 ' : '' ?><?= $h($g['name']) ?>
        <small>— <?= count($g['families']) ?> <?= $byAddress ? 'families here' : 'records' ?></small>
        <?php if (($g['evidence'] ?? []) !== []): ?>
          <div class="dup-evidence">They also share <?= $h(implode(', and ', $g['evidence'])) ?>.</div>
        <?php endif; ?>
      </div>
      <table class="dup">
        <thead><tr><th>Keep</th><th>Merge</th><th>Family #</th><th>Family name</th><th>Members</th><th>City</th><th>Address</th><th>Email</th></tr></thead>
        <tbody>
        <?php foreach ($g['families'] as $fi => $f): $addr = trim($f['addr']); ?>
          <tr>
            <td><input type="radio" name="keep_id" value="<?= (int) $f['id'] ?>" data-group="<?= $gi ?>"<?= $fi === 0 ? ' checked' : '' ?> required></td>
            <td><input type="checkbox" name="merge_ids[]" value="<?= (int) $f['id'] ?>" data-group="<?= $gi ?>"<?= $fi === 0 ? ' disabled' : '' ?>></td>
            <td><a href="<?= $base ?>/admin/families/edit?id=<?= (int) $f['id'] ?>" style="font-weight:700;color:var(--blue-ink,#0f4e97);text-decoration:none">#<?= (int) $f['id'] ?></a></td>
            <td><b><?= $h($f['name']) ?></b></td>
            <td><?= (int) $f['members'] ?></td>
            <td><?= $h($f['city']) ?: '<span class="muted">—</span>' ?></td>
            <td><?= $addr !== '' ? $h($addr) : '<span class="muted">—</span>' ?></td>
            <td class="muted"><?= $h($f['email']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="dup-foot">
        <button class="dup-btn" type="submit">Merge ticked → kept</button>
        <span class="muted" style="font-size:12px">Tip: tick only records that are truly the same household.</span>
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
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'families',
    'pageTitle' => 'Duplicate families · Admin', 'pageSubtitle' => 'Review & merge.',
    'sectionTitle' => 'Duplicate families',
    'sectionDescription' => 'Find and merge duplicate household records so families display accurately.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
