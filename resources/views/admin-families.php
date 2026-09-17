<?php
/**
 * People & Records · Households — find a household, add one, and reach the
 * duplicates review. Data via FamilyAdminService (portal administrators).
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
require_once __DIR__ . '/_records-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = records_h($basePath);
$h = 'records_h';
$total = (int) $listing['total'];
$pages = (int) max(1, ceil($total / max(1, $perPage)));
$noticeMap = [
    'saved' => ['ok', 'Household saved.'],
    'deleted' => ['ok', 'Household deleted.'],
    'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.'],
];
$pageUrl = static fn (int $p): string => $basePath . '/admin/families?' . http_build_query(array_filter(['q' => $search, 'page' => $p > 1 ? $p : null]));

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
<?php else: ?>

<div class="ek-stats">
  <div class="ek-stat"><span class="ek-stat-label">Active households</span><span class="ek-stat-value"><?= (int) $stats['active'] ?></span></div>
  <div class="ek-stat"><span class="ek-stat-label">All household records</span><span class="ek-stat-value"><?= (int) $stats['total'] ?></span></div>
</div>

<section class="ek-card" aria-labelledby="hh-list-title">
  <div class="ek-card-head"><div><h2 id="hh-list-title">Households</h2>
    <p><?= $total ?> <?= $total === 1 ? 'household' : 'households' ?><?= $search !== '' ? ' match' : '' ?>.</p></div></div>
  <div class="ek-card-body">
    <form class="rec-filters" method="get" action="<?= $base ?>/admin/families" role="search">
      <div class="ek-field rec-grow">
        <label for="hh-q">Search</label>
        <input class="ek-input" id="hh-q" type="search" name="q" value="<?= $h($search) ?>" placeholder="Household name, city or email">
      </div>
      <div class="rec-filters-actions">
        <button class="ek-btn ek-btn-primary" type="submit">Find</button>
        <?php if ($search !== ''): ?><a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/families">Clear</a><?php endif; ?>
      </div>
    </form>
  </div>

  <?php if (!$listing['rows']): ?>
    <div class="ek-card-body">
      <div class="ek-empty">
        <strong><?= $search !== '' ? 'No household matches' : 'No households yet' ?></strong>
        <p><?= $search !== '' ? 'Check the spelling, or clear the search to see every household.' : 'Add a household, then add people to it from their records.' ?></p>
        <?php if ($search !== ''): ?>
          <a class="ek-btn" href="<?= $base ?>/admin/families">Clear search</a>
        <?php else: ?>
          <a class="ek-btn ek-btn-primary" href="<?= $base ?>/admin/families/edit">Add household</a>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="ek-table-wrap">
      <table class="ek-table rec-cards">
        <caption class="sr-only">Households, their size, location and status</caption>
        <thead><tr>
          <th scope="col">Household</th>
          <th scope="col" class="is-num">People</th>
          <th scope="col">City</th>
          <th scope="col">Email</th>
          <th scope="col">Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($listing['rows'] as $f):
          $fid = (int) $f['id'];
          $city = trim((string) $f['city'] . ((string) ($f['region'] ?? '') !== '' ? ', ' . $f['region'] : ''), ', ');
        ?>
          <tr>
            <td class="rec-title" data-label="Household"><a class="rec-link" href="<?= $base ?>/admin/families/edit?id=<?= $fid ?>"><?= $h($f['name']) ?></a></td>
            <td data-label="People" class="is-num"><?= (int) $f['members'] ?></td>
            <td data-label="City" class="<?= $city === '' ? 'is-blank' : '' ?>"><?= $city !== '' ? $h($city) : '<span class="rec-muted">—</span>' ?></td>
            <td data-label="Email" class="rec-muted<?= trim((string) $f['email']) === '' ? ' is-blank' : '' ?>" style="overflow-wrap:anywhere"><?= trim((string) $f['email']) !== '' ? $h($f['email']) : '—' ?></td>
            <td data-label="Status"><?= $f['deactivated_on'] === null
                ? '<span class="ek-badge is-ok">Active</span>'
                : '<span class="ek-badge">Inactive</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= records_pager($page, $pages, $total, $total === 1 ? 'household' : 'households', $pageUrl) ?>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'families',
    'pageTitle' => 'Households', 'pageSubtitle' => 'Household records.',
    'sectionTitle' => 'Households',
    'sectionDescription' => 'The people who live together, their shared address, and how households relate to each other.',
    'headerActions' => $isAdmin
        ? '<a class="ek-btn ek-btn-primary" href="' . $base . '/admin/families/edit">Add household</a>'
          . '<a class="ek-btn" href="' . $base . '/admin/families/duplicates">Review duplicates</a>'
        : '',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
