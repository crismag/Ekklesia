<?php
/**
 * Admin · Option manager — edit the classification (membership status), family
 * role (household role) and member type lists. Data via OptionAdminService.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,array{label:string,desc:string,options:list<array<string,mixed>>}> $lists
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$noticeMap = ['saved' => ['ok', 'Options updated.'], 'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.']];

ob_start();
?>
<style>
  .op-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .op-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}.op-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  .op-row{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--line,#eef2f5)}
  .op-row:last-of-type{border-bottom:0}
  .op-row form{display:flex;align-items:center;gap:6px;margin:0}
  .op-row input[type=text]{font:inherit;padding:7px 9px;border:1px solid var(--line,#c7d4cd);border-radius:7px;min-width:180px}
  .op-btn{font:inherit;font-size:12px;font-weight:700;border:1px solid var(--line,#c7d4cd);background:#fff;border-radius:6px;padding:6px 9px;cursor:pointer;color:var(--ink,#1b2a24)}
  .op-btn:hover{border-color:#137a5f}.op-btn.primary{background:#0c5a45;border-color:#0c5a45;color:#fff}.op-btn.danger{color:#b3261e;border-color:#f0c3bd}
  .op-btn[disabled]{opacity:.4;cursor:default}
  .op-usage{font-size:12px;color:var(--muted,#5c6b63);min-width:64px}
  .op-add{display:flex;gap:8px;margin-top:12px}
  .op-add input{font:inherit;padding:8px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;min-width:200px}
  .muted{color:var(--muted,#5c6b63)}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="op-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <article class="admin-card"><div class="admin-card-body" style="color:var(--muted)">Only a portal-wide admin can manage options.</div></article>
<?php else: ?>
  <?php foreach ($lists as $listKey => $list): ?>
    <article class="admin-card">
      <div class="admin-card-head"><div><h2><?= $h($list['label']) ?></h2><p><?= $h($list['desc']) ?></p></div></div>
      <div class="admin-card-body">
        <?php $count = count($list['options']); foreach ($list['options'] as $i => $o): ?>
          <div class="op-row">
            <!-- rename -->
            <form method="post" action="<?= $base ?>/admin/options">
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="list" value="<?= $h($listKey) ?>">
              <input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
              <input type="text" name="name" value="<?= $h($o['name']) ?>" maxlength="50"
                     aria-label="Rename &quot;<?= $h($o['name']) ?>&quot;">
              <button class="op-btn" type="submit">Save</button>
            </form>
            <span class="op-usage"><?= (int) $o['usage'] ?> in use</span>
            <!-- reorder -->
            <form method="post" action="<?= $base ?>/admin/options">
              <input type="hidden" name="action" value="move"><input type="hidden" name="list" value="<?= $h($listKey) ?>"><input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="dir" value="up">
              <button class="op-btn" type="submit" title="Move up"<?= $i === 0 ? ' disabled' : '' ?>>&uarr;</button>
            </form>
            <form method="post" action="<?= $base ?>/admin/options">
              <input type="hidden" name="action" value="move"><input type="hidden" name="list" value="<?= $h($listKey) ?>"><input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="dir" value="down">
              <button class="op-btn" type="submit" title="Move down"<?= $i === $count - 1 ? ' disabled' : '' ?>>&darr;</button>
            </form>
            <!-- delete -->
            <form method="post" action="<?= $base ?>/admin/options" onsubmit="return confirm('Delete the option &quot;<?= $h($o['name']) ?>&quot;?\n\nIt is removed from the list and can no longer be chosen. People already using it keep it until you change them.\n\nThis cannot be undone.');">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="list" value="<?= $h($listKey) ?>"><input type="hidden" name="option_id" value="<?= (int) $o['id'] ?>">
              <button class="op-btn danger" type="submit"<?= (int) $o['usage'] > 0 ? ' disabled title="In use"' : '' ?>>Delete</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (!$list['options']): ?><p class="muted">No options yet.</p><?php endif; ?>

        <form class="op-add" method="post" action="<?= $base ?>/admin/options">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="list" value="<?= $h($listKey) ?>">
          <input type="text" name="name" maxlength="50" placeholder="New option name"
                 aria-label="New option for <?= $h($list['label'] ?? 'this list') ?>">
          <button class="op-btn primary" type="submit">&#43; Add</button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'options',
    'pageTitle' => 'Options · Admin', 'pageSubtitle' => 'Dropdown option lists.',
    'sectionTitle' => 'Option lists',
    'sectionDescription' => 'Add, rename, reorder, and remove the options used in people & family dropdowns.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
