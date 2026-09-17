<?php
/**
 * Admin · Activity history — the audit log across every kind of record.
 *
 * Read-only. Data from ActivityHistoryService::page(), which is called only
 * for portal-wide admins and refuses anyone else itself.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,mixed>|null $history
 * @var string $historyError
 * @var array<string,mixed> $req
 */
require_once __DIR__ . '/_admin-shell.php';
require_once __DIR__ . '/_admin-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = admin_e($basePath);
$h = static fn ($v): string => admin_e($v);

// When the filters were refused, show what was typed so it can be corrected.
$filters = is_array($history['filters'] ?? null) ? $history['filters'] : [
    'account' => (int) ($req['account'] ?? 0),
    'person' => (int) ($req['person'] ?? 0),
    'action' => (string) ($req['action'] ?? ''),
    'target_type' => (string) ($req['target_type'] ?? ''),
    'target_id' => (string) ($req['target_id'] ?? ''),
    'from' => (string) ($req['from'] ?? ''),
    'to' => (string) ($req['to'] ?? ''),
];
$facets = is_array($history['facets'] ?? null) ? $history['facets'] : ['actions' => [], 'targetTypes' => [], 'accounts' => []];
$entries = is_array($history['entries'] ?? null) ? $history['entries'] : [];
$active = array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0);

$typeLabel = static fn (?string $type): string => match ($type) {
    'person' => 'Person',
    'household' => 'Household',
    'campus' => 'Campus',
    'user_account' => 'Login',
    null, '' => '—',
    default => ucfirst(str_replace('_', ' ', $type)),
};
/** Where the record an entry is about can be opened, when it has a page. */
$targetHref = static function (array $e) use ($base): ?string {
    $id = (string) ($e['targetId'] ?? '');
    if ($id === '' || !ctype_digit($id)) {
        return null;
    }

    return match ($e['targetType'] ?? null) {
        'person' => $base . '/admin/people/view?id=' . $id,
        'household' => $base . '/admin/families/edit?id=' . $id,
        'campus' => $base . '/admin/campuses?campus_id=' . $id,
        'user_account' => $base . '/admin/users/' . $id,
        default => null,
    };
};
$pageLink = static function (int $page) use ($filters, $base): string {
    $q = array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0);
    if ($page > 1) {
        $q['page'] = $page;
    }

    return $base . '/admin/history' . ($q === [] ? '' : '?' . http_build_query($q, '', '&amp;'));
};

ob_start();
?>
<style>
  .ah-filters{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,180px),1fr));gap:var(--sp-3,12px);align-items:end}
  .ah-when{white-space:nowrap;font-variant-numeric:tabular-nums}
  .ah-what{display:grid;gap:2px;min-width:14rem}
  .ah-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:var(--muted)}
  .ah-sub{font-size:12px;color:var(--muted)}
  .ah-details summary{cursor:pointer;font-size:12px;color:var(--teal-ink,var(--teal));min-height:24px;display:inline-flex;align-items:center}
  .ah-details pre{margin:6px 0 0;padding:8px;background:var(--soft);border-radius:6px;font-size:12px;white-space:pre-wrap;overflow-wrap:anywhere;max-width:40rem}
  .ah-pager{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:var(--sp-3,12px);padding:var(--sp-3,12px) var(--sp-5,20px);border-top:1px solid var(--line)}
  @media (max-width:760px){
    .ah-table thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
    .ah-table,.ah-table tbody,.ah-table tr,.ah-table td{display:block;width:100%}
    .ah-table tr{padding:var(--sp-3,12px) var(--sp-4,16px);border-bottom:1px solid var(--line)}
    .ah-table td{border:0;padding:2px 0}
    .ah-what{min-width:0}
  }
</style>

<?php if (!$isAdmin): ?>
  <div class="ek-card"><div class="ek-card-body">Activity history is limited to portal-wide administrators.</div></div>
<?php else: ?>

<?php if ($historyError !== ''): ?>
  <div class="ek-alert is-error" role="alert"><?= $h($historyError) ?></div>
<?php endif; ?>

<section class="ek-card" aria-labelledby="ahFilterHeading">
  <div class="ek-card-head"><div>
    <h2 id="ahFilterHeading">Find a change</h2>
    <p>Filter by who made it, what was done, the kind of record, or when.</p>
  </div></div>
  <div class="ek-card-body">
    <form class="ah-filters" method="get" action="<?= $base ?>/admin/history">
      <div class="ek-field">
        <label for="ahAccount">Who</label>
        <select class="ek-select" id="ahAccount" name="account">
          <option value="">Anyone</option>
          <?php foreach ($facets['accounts'] as $acc): ?>
            <option value="<?= (int) $acc['id'] ?>"<?= (int) $filters['account'] === (int) $acc['id'] ? ' selected' : '' ?>><?= $h($acc['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ek-field">
        <label for="ahAction">What was done</label>
        <select class="ek-select" id="ahAction" name="action">
          <option value="">Anything</option>
          <?php foreach ($facets['actions'] as $action): ?>
            <option value="<?= $h($action) ?>"<?= $filters['action'] === $action ? ' selected' : '' ?>><?= $h(\App\Services\ActivityHistoryService::describeAction($action)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ek-field">
        <label for="ahType">Kind of record</label>
        <select class="ek-select" id="ahType" name="target_type">
          <option value="">Any</option>
          <?php foreach ($facets['targetTypes'] as $type): ?>
            <option value="<?= $h($type) ?>"<?= $filters['target_type'] === $type ? ' selected' : '' ?>><?= $h($typeLabel($type)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ek-field">
        <label for="ahFrom">From</label>
        <input class="ek-input" id="ahFrom" type="date" name="from" value="<?= $h($filters['from']) ?>">
      </div>
      <div class="ek-field">
        <label for="ahTo">To</label>
        <input class="ek-input" id="ahTo" type="date" name="to" value="<?= $h($filters['to']) ?>">
      </div>
      <?php if ($filters['target_id'] !== ''): ?><input type="hidden" name="target_id" value="<?= $h($filters['target_id']) ?>"><?php endif; ?>
      <?php if ((int) $filters['person'] > 0): ?><input type="hidden" name="person" value="<?= (int) $filters['person'] ?>"><?php endif; ?>
      <div class="ek-toolbar">
        <button class="ek-btn" type="submit">Apply</button>
        <?php if ($active !== []): ?><a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/history">Clear</a><?php endif; ?>
      </div>
    </form>
    <?php if ($filters['target_id'] !== '' || (int) $filters['person'] > 0): ?>
      <p class="ek-hint" style="margin:10px 0 0">
        <?= $filters['target_id'] !== '' ? $h('Showing one ' . strtolower($typeLabel($filters['target_type'])) . ' record (#' . $filters['target_id'] . ').') : '' ?>
        <?= (int) $filters['person'] > 0 ? $h('Showing changes by or about person #' . (int) $filters['person'] . '.') : '' ?>
        <a href="<?= $base ?>/admin/history">Show everything</a>
      </p>
    <?php endif; ?>
  </div>
</section>

<?php if ($history !== null): ?>
<section class="ek-card" aria-labelledby="ahListHeading">
  <div class="ek-card-head"><div>
    <h2 id="ahListHeading">Changes</h2>
    <p><?= $h(number_format((int) $history['total']) . ((int) $history['total'] === 1 ? ' change' : ' changes') . ($active !== [] ? ' match' : ' recorded') . ', newest first') ?></p>
  </div></div>

  <?php if ($entries === []): ?>
    <div class="ek-empty">
      <?php if ($active !== []): ?>
        <strong>No changes match</strong>
        <p>Widen the dates or clear a filter.</p>
        <a class="ek-btn" href="<?= $base ?>/admin/history">Clear filters</a>
      <?php else: ?>
        <strong>Nothing recorded yet</strong>
        <p>Changes to logins, people, households and campuses appear here as they are made.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
  <div class="ek-table-wrap">
    <table class="ek-table ah-table">
      <caption class="sr-only">Recorded changes, newest first</caption>
      <thead><tr>
        <th scope="col">When</th>
        <th scope="col">Who</th>
        <th scope="col">What</th>
        <th scope="col">Record</th>
      </tr></thead>
      <tbody>
      <?php foreach ($entries as $e): ?>
        <tr>
          <td class="ah-when"><time datetime="<?= $h($e['occurredAt']) ?>"><?= $h(admin_when($e['occurredAt'])) ?></time></td>
          <td>
            <?php if ($e['accountId'] !== null): ?>
              <a href="<?= $base ?>/admin/users/<?= (int) $e['accountId'] ?>"><?= $h($e['accountName'] ?? $e['accountEmail'] ?? ('Login #' . $e['accountId'])) ?></a>
              <?php if ($e['actorPersonName'] !== null && $e['actorPersonName'] !== ($e['accountName'] ?? null)): ?><div class="ah-sub"><?= $h($e['actorPersonName']) ?></div><?php endif; ?>
            <?php else: ?>
              <span class="ah-sub">Unknown or deleted login</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="ah-what">
              <span><?= $h($e['summary'] ?? \App\Services\ActivityHistoryService::describeAction((string) $e['action'])) ?></span>
              <?php if ($e['summary'] !== null): ?><span class="ah-sub"><?= $h(\App\Services\ActivityHistoryService::describeAction((string) $e['action'])) ?></span><?php endif; ?>
              <?php if ($e['details'] !== null || $e['ipAddress'] !== null): ?>
                <details class="ah-details">
                  <summary>Details</summary>
                  <pre><?= $h(json_encode(array_filter([
                      'action' => $e['action'],
                      'details' => $e['details'],
                      'ip' => $e['ipAddress'],
                      'browser' => $e['userAgent'],
                  ], static fn ($v): bool => $v !== null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                </details>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <?php $href = $targetHref($e); $label = $e['targetLabel'] ?? ($e['targetId'] !== null ? '#' . $e['targetId'] : '—'); ?>
            <div class="ah-sub"><?= $h($typeLabel($e['targetType'])) ?></div>
            <?php if ($href !== null && $e['targetLabel'] !== null): ?>
              <a href="<?= $h($href) ?>"><?= $h($label) ?></a>
            <?php else: ?>
              <span><?= $h($label) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ((int) $history['pages'] > 1): ?>
    <nav class="ah-pager" aria-label="Pages">
      <span class="ah-sub">Page <?= (int) $history['page'] ?> of <?= (int) $history['pages'] ?></span>
      <span class="ek-toolbar">
        <?php if ((int) $history['page'] > 1): ?><a class="ek-btn" href="<?= $pageLink((int) $history['page'] - 1) ?>" rel="prev">Newer</a><?php endif; ?>
        <?php if ((int) $history['page'] < (int) $history['pages']): ?><a class="ek-btn" href="<?= $pageLink((int) $history['page'] + 1) ?>" rel="next">Older</a><?php endif; ?>
      </span>
    </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'history',
    'pageTitle' => 'Activity history', 'pageSubtitle' => 'Who changed what, and when.',
    'sectionTitle' => 'Activity history',
    'sectionDescription' => 'Every recorded change to logins, people, households and campuses. Read-only.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
