<?php
/**
 * People & Records · Record settings — the lists person records choose from:
 * membership statuses, household roles and member types. Add, rename,
 * reorder, delete (only when unused). Rules in OptionAdminService.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,array{label:string,desc:string,options:list<array<string,mixed>>}> $lists
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_records-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = records_h($basePath);
$h = 'records_h';
$noticeMap = ['saved' => ['ok', 'Record settings updated.'], 'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.']];

// The names these lists go by everywhere else in People & Records, and where
// the people using each option can be found.
$names = [
    'membership_statuses' => ['title' => 'Membership statuses', 'noun' => 'status', 'filter' => 'cls'],
    'household_roles' => ['title' => 'Household roles', 'noun' => 'role', 'filter' => null],
    'member_types' => ['title' => 'Member types', 'noun' => 'member type', 'filter' => 'type'],
];

ob_start();
echo records_styles();
?>
<style>
  .os-nav{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px)}
  .os-table td{vertical-align:middle}
  .os-rename{display:flex;gap:var(--sp-2,8px);align-items:center;min-width:0}
  .os-rename .ek-input{min-width:0;max-width:22rem}
  .os-order{display:flex;gap:4px}
  .os-order .ek-btn{min-width:40px;padding:0 10px}
  .os-add{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px);align-items:flex-end}
  .os-add .ek-field{flex:1 1 220px;max-width:22rem}
  @media(max-width:720px){
    .os-table .rec-title{display:block}
    .os-rename{flex-wrap:wrap}
    .os-rename .ek-input{max-width:none;flex:1 1 180px}
  }
</style>

<?= records_notice_for($notice, $noticeMap) ?>

<?php if (!$isAdmin): ?>
  <section class="ek-empty">
    <strong>Record settings are for portal administrators</strong>
    <p>These lists shape every member record, so only an administrator can change them.</p>
    <a class="ek-btn" href="<?= $base ?>/people">Open the directory</a>
  </section>
<?php else: ?>
  <nav class="os-nav" aria-label="Lists on this page">
    <?php foreach ($lists as $listKey => $list): ?>
      <a class="ek-chip" href="#list-<?= $h($listKey) ?>"><?= $h($names[$listKey]['title'] ?? $list['label']) ?> <span class="rec-muted">(<?= count($list['options']) ?>)</span></a>
    <?php endforeach; ?>
  </nav>

  <?php foreach ($lists as $listKey => $list):
    $meta = $names[$listKey] ?? ['title' => $list['label'], 'noun' => 'option', 'filter' => null];
    $count = count($list['options']);
  ?>
    <section class="ek-card" id="list-<?= $h($listKey) ?>" aria-labelledby="title-<?= $h($listKey) ?>">
      <div class="ek-card-head"><div><h2 id="title-<?= $h($listKey) ?>"><?= $h($meta['title']) ?></h2><p><?= $h($list['desc']) ?></p></div></div>
      <?php if (!$list['options']): ?>
        <div class="ek-card-body"><p class="rec-muted" style="margin:0">No <?= $h($meta['noun']) ?> has been added yet. Add the first one below.</p></div>
      <?php else: ?>
        <div class="ek-table-wrap">
          <table class="ek-table rec-cards os-table">
            <caption class="sr-only"><?= $h($meta['title']) ?>, in the order they are offered</caption>
            <thead><tr><th scope="col">Name</th><th scope="col" class="is-num">People</th><th scope="col">Order</th><th scope="col"><span class="sr-only">Delete</span></th></tr></thead>
            <tbody>
            <?php foreach ($list['options'] as $i => $o): $oid = (int) $o['id']; $usage = (int) $o['usage']; ?>
              <tr>
                <td class="rec-title" data-label="Name">
                  <form method="post" action="<?= $base ?>/admin/options" class="os-rename">
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="list" value="<?= $h($listKey) ?>">
                    <input type="hidden" name="option_id" value="<?= $oid ?>">
                    <input class="ek-input" type="text" name="name" value="<?= $h($o['name']) ?>" maxlength="50" required
                           aria-label="Name of <?= $h($meta['noun']) ?> &quot;<?= $h($o['name']) ?>&quot;">
                    <button class="ek-btn" type="submit">Rename<span class="sr-only"> <?= $h($o['name']) ?></span></button>
                  </form>
                </td>
                <td data-label="People" class="is-num"><?php if ($usage > 0 && $meta['filter'] !== null): ?>
                  <a class="rec-link" href="<?= $base ?>/admin/people?<?= $meta['filter'] ?>=<?= $oid ?>"><?= $usage ?><span class="sr-only"> people with <?= $h($o['name']) ?></span></a>
                <?php else: ?><?= $usage ?><?php endif; ?></td>
                <td data-label="Order">
                  <span class="os-order">
                    <form method="post" action="<?= $base ?>/admin/options" class="rec-inline-form">
                      <input type="hidden" name="action" value="move"><input type="hidden" name="list" value="<?= $h($listKey) ?>"><input type="hidden" name="option_id" value="<?= $oid ?>"><input type="hidden" name="dir" value="up">
                      <button class="ek-btn" type="submit"<?= $i === 0 ? ' disabled' : '' ?>><span aria-hidden="true">&uarr;</span><span class="sr-only">Move <?= $h($o['name']) ?> up</span></button>
                    </form>
                    <form method="post" action="<?= $base ?>/admin/options" class="rec-inline-form">
                      <input type="hidden" name="action" value="move"><input type="hidden" name="list" value="<?= $h($listKey) ?>"><input type="hidden" name="option_id" value="<?= $oid ?>"><input type="hidden" name="dir" value="down">
                      <button class="ek-btn" type="submit"<?= $i === $count - 1 ? ' disabled' : '' ?>><span aria-hidden="true">&darr;</span><span class="sr-only">Move <?= $h($o['name']) ?> down</span></button>
                    </form>
                  </span>
                </td>
                <td class="rec-action">
                  <?php if ($usage > 0): ?>
                    <span class="rec-muted rec-small">In use</span>
                  <?php else: ?>
                    <form method="post" action="<?= $base ?>/admin/options" class="rec-inline-form" onsubmit="return confirm('Delete the <?= $h($meta['noun']) ?> &quot;<?= $h(addslashes((string) $o['name'])) ?>&quot;?\n\nIt is removed from the list and can no longer be chosen.\n\nThis cannot be undone.');">
                      <input type="hidden" name="action" value="delete"><input type="hidden" name="list" value="<?= $h($listKey) ?>"><input type="hidden" name="option_id" value="<?= $oid ?>">
                      <button class="ek-btn ek-btn-quiet" type="submit">Delete<span class="sr-only"> <?= $h($o['name']) ?></span></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <form class="rec-card-foot os-add" method="post" action="<?= $base ?>/admin/options">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="list" value="<?= $h($listKey) ?>">
        <div class="ek-field"><label for="add-<?= $h($listKey) ?>">New <?= $h($meta['noun']) ?></label>
          <input class="ek-input" id="add-<?= $h($listKey) ?>" type="text" name="name" maxlength="50" required></div>
        <button class="ek-btn ek-btn-primary" type="submit">Add <?= $h($meta['noun']) ?></button>
      </form>
    </section>
  <?php endforeach; ?>
  <p class="rec-muted rec-small" style="margin:0">A <?= 'status, role or member type' ?> that people still have cannot be deleted. Change those people first — the number in the People column opens them.</p>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'options',
    'pageTitle' => 'Record settings', 'pageSubtitle' => 'The lists person records choose from.',
    'sectionTitle' => 'Record settings',
    'sectionDescription' => 'The choices offered on every person record: membership statuses, household roles and member types. Order here is the order people see.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
