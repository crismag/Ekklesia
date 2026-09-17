<?php
/**
 * Admin · Families — list with search + counts. Data via FamilyAdminService.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array{total:int,active:int} $stats
 * @var array{total:int,rows:list<array<string,mixed>>} $listing
 * @var int $page
 * @var int $perPage
 * @var string $search
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$total = (int) $listing['total'];
$pages = (int) max(1, ceil($total / max(1, $perPage)));
$noticeMap = ['saved' => ['ok', 'Family saved.'], 'deleted' => ['ok', 'Family deleted.'], 'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.']];
$pageUrl = static fn (int $p): string => $base . '/admin/families' . '?' . http_build_query(array_filter(['q' => $search, 'page' => $p > 1 ? $p : null]));

ob_start();
?>
<style>
  .fm-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .fm-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}.fm-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  .fm-stats{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 14px}
  .fm-stat{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;padding:12px 16px;min-width:120px}
  .fm-stat b{display:block;font-size:22px}.fm-stat span{color:var(--muted,#5c6b63);font-size:12px}
  .fm-actions{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px}
  form.fm-search{display:flex;gap:8px;align-items:end;margin:0 0 12px}
  form.fm-search input{font:inherit;padding:8px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px}
  .fm-btn{font:inherit;font-size:13px;font-weight:700;border:1px solid var(--line,#c7d4cd);background:#fff;border-radius:8px;padding:8px 14px;cursor:pointer;text-decoration:none;color:var(--ink,#1b2a24)}
  .fm-btn:hover{border-color:#137a5f}.fm-btn.primary{background:#0c5a45;border-color:#0c5a45;color:#fff}
  /* position:relative so an absolutely-positioned sr-only caption inside a wide
     table cannot escape this scroll container and push the page sideways. */
  .fm-scroll{overflow-x:auto;position:relative}
  table.fm{width:100%;border-collapse:collapse;font-size:13.5px;background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;overflow:hidden}
  table.fm th,table.fm td{padding:9px 11px;text-align:left;border-bottom:1px solid var(--line,#eef2f5);white-space:nowrap}
  table.fm th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63);background:var(--surface-2,#f8fafb)}
  /* The family name is the way into the record; it was an 18px inline link
     carrying its colour in a style attribute. Same treatment as the people
     directory. */
  .fm-name{display:inline-block;padding:3px 2px;min-height:24px;line-height:18px;
    font-weight:700;color:var(--blue-ink,#0f4e97);text-decoration:none;border-radius:4px}
  .fm-name:hover,.fm-name:focus-visible{text-decoration:underline}
  table.fm tr:last-child td{border-bottom:0}
  .fm-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700}
  .fm-on{background:#e6f7ec;color:#1a7a3a}.fm-off{background:#eceff3;color:#5a6b7b}
  .fm-pager{display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px}
  .muted{color:var(--muted,#5c6b63)}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="fm-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<div class="fm-stats">
  <div class="fm-stat"><b><?= (int) $stats['active'] ?></b><span>Active families</span></div>
  <div class="fm-stat"><b><?= (int) $stats['total'] ?></b><span>Total families</span></div>
</div>

<div class="fm-actions">
  <?php if ($isAdmin): ?><a class="fm-btn primary" href="<?= $base ?>/admin/families/edit">&#43; Add family</a><?php endif; ?>
  <?php if ($isAdmin): ?><a class="fm-btn" href="<?= $base ?>/admin/families/duplicates">Review duplicates</a><?php endif; ?>
  <a class="fm-btn" href="<?= $base ?>/admin/people">People</a>
</div>

<article class="admin-card">
  <div class="admin-card-head"><div><h2>Families</h2><p><?= $total ?> match<?= $total === 1 ? '' : 'es' ?>.</p></div></div>
  <div class="admin-card-body">
    <form class="fm-search" method="get" action="<?= $base ?>/admin/families">
      <div><label class="muted" for="fm_q" style="display:block;font-size:11px">Search</label><input id="fm_q" type="search" name="q" value="<?= $h($search) ?>" placeholder="name, city, email"></div>
      <button class="fm-btn primary" type="submit">Search</button>
      <?php if ($search !== ''): ?><a class="fm-btn" href="<?= $base ?>/admin/families">Clear</a><?php endif; ?>
    </form>

    <div class="fm-scroll">
      <table class="fm">
        <caption class="sr-only">Families, their size, location and status</caption>
        <thead><tr><th scope="col">Family</th><th scope="col">Members</th><th scope="col">City</th><th scope="col">Email</th><th scope="col">Status</th><?php if ($isAdmin): ?><th scope="col"><span class="sr-only">Actions</span></th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$listing['rows']): ?><tr><td colspan="<?= $isAdmin ? 6 : 5 ?>" class="muted" style="text-align:center;padding:20px">No families match.</td></tr><?php endif; ?>
        <?php foreach ($listing['rows'] as $f): $fid = (int) $f['id']; ?>
          <tr>
            <td><a class="fm-name" href="<?= $base ?>/admin/families/edit?id=<?= $fid ?>"><?= $h($f['name']) ?></a></td>
            <td><?= (int) $f['members'] ?></td>
            <td><?= $h($f['city']) ?: '<span class="muted">—</span>' ?></td>
            <td class="muted"><?= $h($f['email']) ?></td>
            <td><span class="fm-badge <?= $f['deactivated_on'] === null ? 'fm-on' : 'fm-off' ?>"><?= $f['deactivated_on'] === null ? 'Active' : 'Inactive' ?></span></td>
            <?php if ($isAdmin): ?><td><a class="fm-btn" href="<?= $base ?>/admin/families/edit?id=<?= $fid ?>">Manage</a></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="fm-pager">
        <?php if ($page > 1): ?><a class="fm-btn" href="<?= $h($pageUrl($page - 1)) ?>">&larr; Prev</a><?php endif; ?>
        <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="fm-btn" href="<?= $h($pageUrl($page + 1)) ?>">Next &rarr;</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</article>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'families',
    'pageTitle' => 'Families · Admin', 'pageSubtitle' => 'Household management.',
    'sectionTitle' => 'Families',
    'sectionDescription' => 'Add and edit households and their members.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
