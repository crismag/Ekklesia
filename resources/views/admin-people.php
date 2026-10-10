<?php
/**
 * People & Records · Member records — find a person's record, filter the
 * roll, add a person. Data via PersonAdminService (portal administrators).
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,mixed> $stats
 * @var list<array<string,mixed>> $people
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var array<string,mixed> $filters
 * @var list<array{id:int,name:string}> $classifications
 * @var list<array{id:int,name:string}> $memberTypes
 * @var list<array{id:int,name:string}> $campuses
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_records-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = records_h($basePath);
$h = 'records_h';
$months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$pages = (int) max(1, ceil($total / max(1, $perPage)));
$q = (string) $filters['search'];
$isFiltered = $q !== '' || (int) $filters['classification'] !== 0 || (int) $filters['member_type'] !== 0;

$noticeMap = [
    'saved' => ['ok', 'Person saved.'],
    'deleted' => ['ok', 'Person deleted.'],
    'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.'],
];

// Campus is deliberately absent from these links. It comes from the top bar's
// selector, and pinning it into the URL would freeze page 2 onto whichever
// campus was chosen when page 1 was rendered.
$pageUrl = static function (int $p) use ($basePath, $q, $filters): string {
    $qs = http_build_query(array_filter([
        'q' => $q, 'cls' => (int) $filters['classification'] ?: null,
        'type' => (int) $filters['member_type'] ?: null,
        'page' => $p > 1 ? $p : null,
    ]));
    return $basePath . '/admin/people' . ($qs ? '?' . $qs : '');
};

$campusName = '';
foreach ($campuses as $c) {
    if ((int) $c['id'] === (int) $filters['campus']) {
        $campusName = (string) $c['name'];
    }
}

$typeIcons = \App\Services\MemberTypeIcons::fromFile(dirname(__DIR__, 2) . '/config/member-type-icons.json');
$clsStatus = \App\Services\ClassificationStatus::fromFile(dirname(__DIR__, 2) . '/config/classification-status.json');

/**
 * Membership status in words, with a dot saying attending or not. The dot is a
 * second cue; the word is what carries the meaning.
 */
$statusCell = static function (string $value) use ($h, $clsStatus): string {
    if (trim($value) === '') {
        return '<span class="rec-muted">Not set</span>';
    }
    $status = $clsStatus->statusFor($value);

    return '<span class="mr-status"><span class="mr-dot mr-dot-' . $h($status) . '" aria-hidden="true"></span>'
        . $h($value) . '<span class="sr-only"> (' . $h($clsStatus->statusLabel($status)) . ')</span></span>';
};

/** Member type in words, with its symbol when one is assigned. */
$typeCell = static function (string $value) use ($h, $typeIcons): string {
    if (trim($value) === '') {
        return '<span class="rec-muted">Not set</span>';
    }
    $symbol = $typeIcons->symbolFor($value);

    return '<span class="mr-type">' . ($symbol !== null
        ? '<span class="mr-sym mr-sym-' . $h($symbol) . '" aria-hidden="true">' . $typeIcons->svg($symbol) . '</span>'
        : '') . $h($value) . '</span>';
};

$typedCount = array_sum(array_map(static fn ($m) => (int) $m['count'], $stats['memberTypes']));
$unclassified = 0;
foreach ($stats['classifications'] as $c) {
    if ((int) $c['id'] === 0) {
        $unclassified = (int) $c['count'];
    }
}

ob_start();
echo records_styles();
?>
<style>
  .mr-status,.mr-type{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
  .mr-dot{width:9px;height:9px;border-radius:50%;flex:0 0 9px;background:var(--muted,#627169)}
  .mr-dot-active{background:var(--teal,#117b6d)}
  .mr-dot-prospective{background:var(--blue,#2f6fae)}
  .mr-dot-inactive{background:var(--gold,#c98a2b)}
  .mr-sym{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:var(--soft,#eef4f0);color:var(--teal-ink,#117b6d)}
  .mr-sym svg{width:14px;height:14px}
  .mr-name{font-weight:650}
  .mr-contact{color:var(--muted,#627169);overflow-wrap:anywhere}
  .mr-breakdown{display:grid;gap:6px;margin:0;padding:0;list-style:none;font-size:13px}
  .mr-breakdown li{display:flex;justify-content:space-between;gap:12px}
  .mr-breakdown a{color:var(--teal-ink,#117b6d);text-decoration:none}
  .mr-breakdown a:hover{text-decoration:underline}
  .mr-breakdown .n{font-variant-numeric:tabular-nums;color:var(--muted,#627169)}
  .mr-context{margin:0;font-size:13px;color:var(--muted,#627169)}
</style>

<?= records_notice_for($notice, $noticeMap) ?>

<?php if (!$isAdmin): ?>
  <section class="ek-empty">
    <strong>Member records are for portal administrators</strong>
    <p>You can look people up in the directory, which shows what your account is allowed to see.</p>
    <a class="ek-btn" href="<?= $base ?>/people">Open the directory</a>
  </section>
<?php else: ?>

<div class="ek-stats">
  <div class="ek-stat"><span class="ek-stat-label">People</span><span class="ek-stat-value"><?= (int) $stats['people'] ?></span></div>
  <a class="ek-stat" href="<?= $base ?>/admin/families"><span class="ek-stat-label">Active households</span><span class="ek-stat-value"><?= (int) $stats['activeFamilies'] ?></span></a>
  <a class="ek-stat" href="<?= $base ?>/admin/people?type=-1"><span class="ek-stat-label">Without a member type</span><span class="ek-stat-value"><?= max(0, (int) $stats['people'] - $typedCount) ?></span></a>
  <?php if ($unclassified > 0): ?>
  <div class="ek-stat"><span class="ek-stat-label">Without a status</span><span class="ek-stat-value"><?= $unclassified ?></span></div>
  <?php endif; ?>
</div>

<section class="ek-card" aria-labelledby="mr-list-title">
  <div class="ek-card-head">
    <div><h2 id="mr-list-title">People</h2>
      <p><?= (int) $total ?> <?= $total === 1 ? 'person' : 'people' ?><?= $isFiltered ? ' match' : '' ?><?= $campusName !== '' ? ' at ' . $h($campusName) : '' ?>.</p></div>
  </div>
  <div class="ek-card-body">
    <form class="rec-filters" method="get" action="<?= $base ?>/admin/people" role="search">
      <div class="ek-field rec-grow">
        <label for="mr-q">Search</label>
        <input class="ek-input" id="mr-q" type="search" name="q" value="<?= $h($q) ?>" placeholder="Name or email">
      </div>
      <div class="ek-field">
        <label for="mr-cls">Membership status</label>
        <select class="ek-select" id="mr-cls" name="cls"><option value="">Any status</option>
          <?php foreach ($classifications as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $filters['classification'] === (int) $c['id'] ? ' selected' : '' ?>><?= $h($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="ek-field">
        <label for="mr-type">Member type</label>
        <select class="ek-select" id="mr-type" name="type"><option value="">Any type</option>
          <option value="-1"<?= (int) $filters['member_type'] === -1 ? ' selected' : '' ?>>Not set</option>
          <?php foreach ($memberTypes as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $filters['member_type'] === (int) $t['id'] ? ' selected' : '' ?>><?= $h($t['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="rec-filters-actions">
        <button class="ek-btn ek-btn-primary" type="submit">Find</button>
        <?php if ($isFiltered): ?><a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/people">Clear</a><?php endif; ?>
      </div>
    </form>
    <p class="mr-context" style="margin-top:10px"><?= $campusName !== ''
        ? 'Showing people whose campus is ' . $h($campusName) . '. Change the campus in the top bar.'
        : 'Showing every campus. Choose a campus in the top bar to narrow the list.' ?></p>
  </div>

  <?php if (!$people): ?>
    <div class="ek-card-body">
      <div class="ek-empty">
        <strong><?= $isFiltered ? 'Nobody matches' : 'No member records yet' ?></strong>
        <p><?= $isFiltered ? 'Check the spelling, or clear the filters to see everyone.' : 'Add a person, or bring in a roster from a spreadsheet.' ?></p>
        <?php if ($isFiltered): ?>
          <a class="ek-btn" href="<?= $base ?>/admin/people">Clear filters</a>
        <?php else: ?>
          <span class="rec-actions"><a class="ek-btn ek-btn-primary" href="<?= $base ?>/admin/people/edit">Add person</a>
          <a class="ek-btn" href="<?= $base ?>/admin/maintenance/import">Import a spreadsheet</a></span>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="ek-table-wrap">
      <table class="ek-table rec-cards">
        <caption class="sr-only">People, <?= (int) $total ?> matching the current filters, by last name</caption>
        <thead><tr>
          <th scope="col">Name</th>
          <th scope="col">Membership status</th>
          <th scope="col">Member type</th>
          <th scope="col">Campus</th>
          <th scope="col">Household</th>
          <th scope="col">Contact</th>
          <th scope="col">Birthday</th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($people as $p):
          $pid = (int) $p['id'];
          $last = trim((string) ($p['last_name'] ?? ''));
          $first = trim((string) ($p['first_name'] ?? ''));
          $display = $last !== '' && $first !== '' ? $last . ', ' . $first : ($last . $first ?: 'Person #' . $pid);
          $contact = (string) ($p['email'] ?: $p['mobile_phone'] ?: '');
        ?>
          <tr>
            <td class="rec-title" data-label="Name"><a class="rec-link mr-name" href="<?= $base ?>/admin/people/view?id=<?= $pid ?>"><?= $h($display) ?></a></td>
            <td data-label="Status"><?= $statusCell((string) ($p['classification'] ?? '')) ?></td>
            <td data-label="Member type"><?= $typeCell((string) ($p['member_type'] ?? '')) ?></td>
            <td data-label="Campus" class="<?= $p['campus'] ? '' : 'is-blank' ?>"><?= $p['campus'] ? $h($p['campus']) : '<span class="rec-muted">—</span>' ?></td>
            <td data-label="Household" class="<?= (int) $p['household_id'] > 0 ? '' : 'is-blank' ?>"><?= (int) $p['household_id'] > 0
                ? '<a class="rec-link" style="font-weight:400" href="' . $base . '/admin/families/edit?id=' . (int) $p['household_id'] . '">' . $h($p['household']) . '</a>'
                : '<span class="rec-muted">—</span>' ?></td>
            <td data-label="Contact" class="mr-contact<?= $contact === '' ? ' is-blank' : '' ?>"><?= $contact !== '' ? $h($contact) : '—' ?></td>
            <td data-label="Birthday" class="<?= (int) $p['birth_month'] > 0 ? '' : 'is-blank' ?>" style="white-space:nowrap"><?= (int) $p['birth_month'] > 0 ? $h($months[(int) $p['birth_month']] . ' ' . (int) $p['birth_day']) : '<span class="rec-muted">—</span>' ?></td>
            <td class="rec-action"><a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/people/edit?id=<?= $pid ?>">Edit<span class="sr-only"> <?= $h($display) ?></span></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= records_pager($page, $pages, $total, $total === 1 ? 'person' : 'people', $pageUrl) ?>
  <?php endif; ?>
</section>

<div class="ek-grid">
  <section class="ek-card" aria-labelledby="mr-by-status">
    <div class="ek-card-head"><div><h2 id="mr-by-status">By membership status</h2><p>Everyone on the roll, every campus.</p></div></div>
    <div class="ek-card-body">
      <ul class="mr-breakdown">
        <?php foreach ($stats['classifications'] as $c): $name = (string) $c['name']; ?>
          <li><span class="mr-status"><span class="mr-dot mr-dot-<?= $h($clsStatus->statusFor($name)) ?>" aria-hidden="true"></span>
            <?php if ((int) $c['id'] > 0): ?><a href="<?= $base ?>/admin/people?cls=<?= (int) $c['id'] ?>"><?= $h($name) ?></a><?php else: ?><?= $h($name) ?><?php endif; ?></span>
            <span class="n"><?= (int) $c['count'] ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
  <section class="ek-card" aria-labelledby="mr-by-type">
    <div class="ek-card-head"><div><h2 id="mr-by-type">By member type</h2><p>Change the lists in <a class="rec-link" href="<?= $base ?>/admin/options">Record settings</a>.</p></div></div>
    <div class="ek-card-body">
      <ul class="mr-breakdown">
        <?php foreach ($stats['memberTypes'] as $m): ?>
          <li><span class="mr-type"><?php $sym = $typeIcons->symbolFor((string) $m['name']); if ($sym !== null): ?><span class="mr-sym mr-sym-<?= $h($sym) ?>" aria-hidden="true"><?= $typeIcons->svg($sym) ?></span><?php endif; ?>
            <a href="<?= $base ?>/admin/people?type=<?= (int) $m['id'] ?>"><?= $h($m['name']) ?></a></span>
            <span class="n"><?= (int) $m['count'] ?></span></li>
        <?php endforeach; ?>
        <li><span class="mr-type"><a href="<?= $base ?>/admin/people?type=-1">Not set</a></span><span class="n"><?= max(0, (int) $stats['people'] - $typedCount) ?></span></li>
      </ul>
    </div>
  </section>
</div>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'people',
    'pageTitle' => 'Member records', 'pageSubtitle' => 'Person records.',
    'sectionTitle' => 'Member records',
    'sectionDescription' => 'The church roll: find a person\'s record, see their membership and household, and keep it up to date. The Directory is the look-up everyone else uses; records are added and edited here.',
    'headerActions' => $isAdmin ? '<a class="ek-btn ek-btn-primary" href="' . $base . '/admin/people/edit">Add person</a>' : '',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
