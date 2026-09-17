<?php
/**
 * People & Records · Record history — the audit trail of person and household
 * records. Read-only. Data via RecordHistoryService (admin only).
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var bool $isAdmin
 * @var array<string,mixed>|null $history
 * @var array{actions:list<array{value:string,label:string}>,people:list<array{id:int,name:string}>} $options
 * @var ?string $personName
 * @var ?string $householdName
 * @var string $loadError
 */
require_once __DIR__ . '/_records-kit.php';

$base = records_h($basePath);
$h = 'records_h';
$criteria = $history['criteria'] ?? \App\Services\RecordHistoryService::criteria([]);
$type = count($criteria['types']) === 1 ? $criteria['types'][0] : '';
$filtered = $criteria['personId'] !== null || $criteria['householdId'] !== null || $criteria['action'] !== null
    || $criteria['from'] !== null || $criteria['to'] !== null || $type !== '';

// Every filter survives paging; page is the only thing a pager link changes.
$query = array_filter([
    'person' => $criteria['personId'],
    'household' => $criteria['householdId'],
    'type' => $type !== '' ? $type : null,
    'action' => $criteria['action'],
    'from' => $criteria['from'],
    'to' => $criteria['to'],
], static fn ($v): bool => $v !== null);
$pageUrl = static fn (int $p): string => $basePath . '/people/history?' . http_build_query($query + ($p > 1 ? ['page' => $p] : []));
$without = static function (string $key) use ($basePath, $query): string {
    $q = $query;
    unset($q[$key]);

    return $basePath . '/people/history' . ($q !== [] ? '?' . http_build_query($q) : '');
};

$recordHref = static function (array $e) use ($base): ?string {
    if ($e['recordName'] === null) {
        return null;
    }

    return $e['recordType'] === 'person'
        ? $base . '/admin/people/view?id=' . (int) $e['recordId']
        : $base . '/admin/families/edit?id=' . (int) $e['recordId'];
};

ob_start();
echo records_styles();
?>
<style>
  .rh-record{display:grid;gap:2px}
  .rh-type{font-size:12px;color:var(--muted,#627169)}
  .rh-summary{max-width:48ch;overflow-wrap:anywhere}
  .rh-when{white-space:nowrap;font-variant-numeric:tabular-nums}
  .rh-chips{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px)}
</style>

<?php if (!$isAdmin): ?>
  <section class="ek-empty">
    <strong>Record history is for portal administrators</strong>
    <p>It shows who changed member and household records. Ask a church administrator if you need to know about a change.</p>
    <a class="ek-btn" href="<?= $base ?>/people">Open the directory</a>
  </section>
<?php elseif ($loadError !== ''): ?>
  <?= records_notice('error', $loadError) ?>
<?php else: ?>

<section class="ek-card" aria-labelledby="rh-filter-title">
  <div class="ek-card-head"><div><h2 id="rh-filter-title">Find changes</h2>
    <p>Narrow by record, kind of change, or dates. Newest changes are listed first.</p></div></div>
  <div class="ek-card-body">
    <form class="rec-filters" method="get" action="<?= $base ?>/people/history">
      <?php if ($criteria['householdId'] !== null): ?>
        <input type="hidden" name="household" value="<?= (int) $criteria['householdId'] ?>">
      <?php endif; ?>
      <div class="ek-field rec-grow">
        <label for="rh-person">Person</label>
        <select class="ek-select" id="rh-person" name="person">
          <option value="">Anyone</option>
          <?php $listed = false; foreach ($options['people'] as $p): $sel = (int) $criteria['personId'] === $p['id']; $listed = $listed || $sel; ?>
            <option value="<?= (int) $p['id'] ?>"<?= $sel ? ' selected' : '' ?>><?= $h($p['name']) ?></option>
          <?php endforeach; ?>
          <?php if (!$listed && $criteria['personId'] !== null): ?>
            <option value="<?= (int) $criteria['personId'] ?>" selected><?= $h($personName ?? ('Person #' . $criteria['personId'])) ?></option>
          <?php endif; ?>
        </select>
      </div>
      <div class="ek-field">
        <label for="rh-type">Records</label>
        <select class="ek-select" id="rh-type" name="type">
          <option value="">People and households</option>
          <option value="person"<?= $type === 'person' ? ' selected' : '' ?>>People only</option>
          <option value="household"<?= $type === 'household' ? ' selected' : '' ?>>Households only</option>
        </select>
      </div>
      <div class="ek-field">
        <label for="rh-action">Change</label>
        <select class="ek-select" id="rh-action" name="action">
          <option value="">Any change</option>
          <?php foreach ($options['actions'] as $a): ?>
            <option value="<?= $h($a['value']) ?>"<?= $criteria['action'] === $a['value'] ? ' selected' : '' ?>><?= $h($a['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ek-field">
        <label for="rh-from">From</label>
        <input class="ek-input" type="date" id="rh-from" name="from" value="<?= $h($criteria['from']) ?>">
      </div>
      <div class="ek-field">
        <label for="rh-to">To</label>
        <input class="ek-input" type="date" id="rh-to" name="to" value="<?= $h($criteria['to']) ?>">
      </div>
      <div class="rec-filters-actions">
        <button class="ek-btn ek-btn-primary" type="submit">Show changes</button>
        <?php if ($filtered): ?><a class="ek-btn ek-btn-quiet" href="<?= $base ?>/people/history">Clear</a><?php endif; ?>
      </div>
    </form>
    <?php if ($criteria['householdId'] !== null): ?>
      <p class="rh-chips" style="margin:12px 0 0">
        <span class="rec-muted">Household:</span>
        <a class="ek-chip" href="<?= $h($without('household')) ?>" aria-label="Stop narrowing to <?= $h($householdName ?? ('household #' . $criteria['householdId'])) ?>">
          <?= $h($householdName ?? ('Household #' . $criteria['householdId'] . ' (no longer on record)')) ?> <span aria-hidden="true">&times;</span></a>
      </p>
    <?php endif; ?>
  </div>
</section>

<section class="ek-card" aria-labelledby="rh-list-title">
  <div class="ek-card-head"><div><h2 id="rh-list-title">Changes</h2>
    <p><?= (int) $history['total'] ?> <?= (int) $history['total'] === 1 ? 'change' : 'changes' ?><?= $filtered ? ' match these filters' : ' recorded' ?>.</p></div></div>
  <?php if ($history['entries'] === []): ?>
    <div class="ek-card-body">
      <div class="ek-empty">
        <strong><?= $filtered ? 'No changes match these filters' : 'No record changes have been recorded yet' ?></strong>
        <p><?= $filtered
            ? 'Try a wider date range, or clear the filters to see every change.'
            : 'Changes appear here when someone adds or edits a person or household in Member records or Households.' ?></p>
        <?php if ($filtered): ?>
          <a class="ek-btn" href="<?= $base ?>/people/history">Clear filters</a>
        <?php else: ?>
          <a class="ek-btn" href="<?= $base ?>/admin/people">Open member records</a>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="ek-table-wrap">
      <table class="ek-table rec-cards">
        <caption class="sr-only">Changes to person and household records, newest first</caption>
        <thead><tr>
          <th scope="col">Record</th>
          <th scope="col">Change</th>
          <th scope="col">By</th>
          <th scope="col">When</th>
        </tr></thead>
        <tbody>
        <?php foreach ($history['entries'] as $e): $href = $recordHref($e); ?>
          <tr>
            <td class="rec-title" data-label="Record"><span class="rh-record">
              <?php if ($href !== null): ?>
                <a class="rec-link" href="<?= $href ?>"><?= $h($e['recordName'] !== '' ? $e['recordName'] : ('#' . $e['recordId'])) ?></a>
              <?php else: ?>
                <span><?= $e['recordType'] === 'person' ? 'Person' : 'Household' ?> #<?= (int) $e['recordId'] ?> <span class="rec-muted">(no longer on record)</span></span>
              <?php endif; ?>
              <span class="rh-type"><?= $e['recordType'] === 'person' ? 'Person' : 'Household' ?></span>
            </span></td>
            <td data-label="Change"><strong><?= $h($e['actionLabel']) ?></strong>
              <?php if ($e['summary'] !== ''): ?><div class="rh-summary rec-muted"><?= $h($e['summary']) ?></div><?php endif; ?></td>
            <td data-label="By">
              <?php if ($e['whoPersonId'] !== null): ?>
                <a class="rec-link" href="<?= $base ?>/admin/people/view?id=<?= (int) $e['whoPersonId'] ?>"><?= $h($e['who']) ?></a>
              <?php else: ?>
                <span class="<?= $e['who'] === 'Not recorded' ? 'rec-muted' : '' ?>"><?= $h($e['who']) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="When" class="rh-when"><time datetime="<?= $h(str_replace(' ', 'T', $e['occurredAt'])) ?>"><?= $h(records_when($e['occurredAt'])) ?></time></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= records_pager((int) $history['page'], (int) $history['pages'], (int) $history['total'], 'changes', $pageUrl) ?>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'record-history',
    'pageTitle' => 'Record history',
    'pageSubtitle' => 'Changes to person and household records.',
    'sectionTitle' => 'Record history',
    'sectionDescription' => 'Who added or changed a person or household record, and when. Read-only.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
