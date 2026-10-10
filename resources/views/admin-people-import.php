<?php
/**
 * People & Records · Import & export.
 *
 * Import: load a campus workbook into staging, clean the rows, then apply the
 * ready rows to that campus (MemberCampusImportService). Export: download the
 * member workbook (POST /admin/maintenance/export-xlsx, return=import).
 *
 * The staging grid's markup, classes and script are the working editor and
 * are kept as they were; only the page around it is laid out on the kit.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var list<array{id:int,name:string}> $campuses
 * @var int $campusId
 * @var string $hubSheet
 * @var string $nySheet
 * @var string $googleUrl
 * @var string $notice
 * @var string $flash
 * @var list<array<string,mixed>> $batches
 * @var array<string,mixed>|null $batch
 * @var list<array<string,mixed>> $rows
 * @var array<string,int> $counts
 * @var string $statusFilter
 * @var list<array{group:int,decision:string,keep:?int,suggested:int,rows:list<array<string,mixed>>,filled:array<string,mixed>}> $duplicateGroups
 * @var list<array{name:string,kept:string}> $legacyDuplicates
 * @var list<string> $matchRuleLabels
 * @var list<array{relative:string,filename:string,bytes:int,mtime:int}> $exports
 */
require_once __DIR__ . '/_records-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = records_h($basePath);
$h = 'records_h';
$exports = $exports ?? [];
$noticeMap = [
    'ingested' => ['ok', $flash !== '' ? $flash : 'Workbook loaded into staging. Clean the rows, then apply.'],
    'imported' => ['ok', $flash !== '' ? $flash : 'Campus members updated from the cleaned staging list.'],
    'discarded' => ['ok', 'Staging batch discarded.'],
    'typed' => ['ok', $flash !== '' ? $flash : 'Member types suggested on the staged rows.'],
    'ok' => ['ok', $flash !== '' ? $flash : 'Saved.'],
    'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.'],
];
$step = $batch ? 2 : 1;
if ($batch && ($batch['status'] ?? '') === 'applied') {
    $step = 3;
}
$campusNames = [];
foreach ($campuses as $c) {
    $campusNames[(int) $c['id']] = (string) $c['name'];
}
$fmtSize = static fn (int $n): string => $n < 1048576 ? max(1, (int) round($n / 1024)) . ' KB' : round($n / 1048576, 1) . ' MB';

ob_start();
echo records_styles();
?>
<style>
  .mi-steps{display:flex;gap:var(--sp-2,8px);flex-wrap:wrap;margin:0;padding:0;list-style:none;counter-reset:mi}
  .mi-steps li{display:inline-flex;align-items:center;gap:8px;min-height:32px;padding:0 12px 0 4px;border:1px solid var(--line,#d9e4dd);
    border-radius:var(--radius-full,999px);background:var(--paper,#fff);font-size:13px;font-weight:650;color:var(--muted,#627169)}
  .mi-steps li::before{counter-increment:mi;content:counter(mi);display:grid;place-items:center;width:24px;height:24px;border-radius:50%;
    background:var(--soft,#eef4f0);color:var(--ink,#17211b);font-size:12px}
  .mi-steps li[aria-current]{border-color:var(--teal,#117b6d);color:var(--teal-ink,#117b6d)}
  .mi-steps li[aria-current]::before{background:var(--teal,#117b6d);color:var(--on-teal,#fff)}
  .mi-help{font-size:13px;color:var(--muted,#627169);margin:0;line-height:1.5}
  .mi-dupes{margin:6px 0 0;padding-left:18px;font-size:13px;max-height:180px;overflow:auto}
  .mi-actions{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px);align-items:center}
  .mi-confirm{display:flex;gap:8px;align-items:flex-start;font-size:13px;margin:0;max-width:56ch}
  .mi-confirm input{width:18px;height:18px;margin-top:2px;flex:0 0 auto}
  .mi-form{display:grid;gap:var(--sp-3,12px)}
  .mi-rules{display:grid;gap:var(--sp-2,8px);margin:0;padding:var(--sp-3,12px);border:1px solid var(--line,#d9e4dd);border-radius:8px}
  .mi-rules legend{font-weight:650;padding:0 4px}
  .mi-dup-list{display:grid;gap:var(--sp-3,12px)}
  .mi-dup-list h3{margin:0}
  .mi-dup{display:grid;gap:var(--sp-2,8px);padding:var(--sp-3,12px);border:1px solid var(--line,#d9e4dd);border-radius:8px;scroll-margin-top:80px}
  .mi-dup-state{margin:0;font-size:13px}
  .mi-dup-table td{padding:6px 8px;vertical-align:top}
  .mi-tag{display:inline-block;margin-left:6px;padding:0 6px;border:1px solid var(--line,#d9e4dd);border-radius:999px;font-size:11px;color:var(--muted,#627169)}

  /* ---- Staging grid and ministry-name table: unchanged editor styles ---- */
  .mi-scroll{overflow:auto;max-height:70vh;border:1px solid var(--line,#dbe4ec);border-radius:8px}
  table.mi{width:max-content;min-width:100%;border-collapse:collapse;font-size:12.5px;background:var(--paper,#fff)}
  /* Ministry-name review. A row awaiting a decision is marked by a rule and a
     word, never by colour alone — this table gets printed and photocopied. */
  tr.needs-decision th[scope=row]{border-left:3px solid var(--rose,#b3261e);padding-left:8px;font-weight:800}
  tr.needs-decision td{background:color-mix(in srgb,var(--rose,#b3261e) 6%,var(--paper,#fff))}
  table.mi select{font:inherit;font-size:12.5px;min-height:32px;padding:4px 6px;
    border:1px solid var(--line,#d0d7de);border-radius:6px;background:var(--paper,#fff);max-width:260px}
  table.mi select:focus-visible{outline:2px solid var(--teal,#117b6d);outline-offset:1px}
  .mi-note{font-size:12px;color:var(--muted,#57606a);margin:10px 0}
  table.mi th,table.mi td{padding:4px 6px;text-align:left;border-bottom:1px solid var(--line,#eef2f5);white-space:nowrap}
  table.mi th{font-size:11px;text-transform:uppercase;color:var(--muted);background:var(--soft,#f8fafb);position:sticky;top:0}
  table.mi input,table.mi select{font:inherit;border:0;background:transparent;padding:4px;min-width:70px;width:100%}
  table.mi input:focus,table.mi select:focus{background:#fffbe6;outline:2px solid #137a5f}
  .mi-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800}
  .mi-badge.hub{background:#e6f7ec;color:#1a7a3a}
  .mi-badge.ny{background:#fff8e6;color:#8a5a00}
  /* Themed foreground on a fixed background is how a pair drifts out of
     contrast: two presets landed at 4.47 and 4.49 against the old literal.
     Both halves come from the theme now, and --blue-ink on --soft is a pair the
     contrast gate already checks for every preset. */
  .mi-badge.merged{background:var(--soft,#e8f1fb);color:var(--blue-ink,#0f4e97)}
  table.mi td.saving{box-shadow:inset 0 0 0 2px #f0a500}
  table.mi td.saved{box-shadow:inset 0 0 0 2px #1a7a3a}
  table.mi td.err{box-shadow:inset 0 0 0 2px #b3261e;background:#fdecea}
</style>

<?= records_notice_for($notice, $noticeMap) ?>

<?php if (!$isAdmin): ?>
  <section class="ek-empty">
    <strong>Import &amp; export is for portal administrators</strong>
    <p>Importing replaces a campus roster and exporting downloads every member's details, so both are limited to administrators.</p>
    <a class="ek-btn" href="<?= $base ?>/people">Open the directory</a>
  </section>
<?php else: ?>

<ol class="mi-steps" aria-label="Import steps">
  <li<?= $step === 1 ? ' aria-current="step"' : '' ?>>Load a workbook</li>
  <li<?= $step === 2 ? ' aria-current="step"' : '' ?>>Review staging</li>
  <li<?= $step === 3 ? ' aria-current="step"' : '' ?>>Apply to the campus</li>
</ol>

<?php // Ministry names as the workbook wrote them, and where each one went.
      // Rendered whenever an import has recorded anything, so an
      // administrator can see the mapping rather than infer it from which
      // memberships appeared. ?>
<?php if (!empty($ministryNameRows)): ?>
<details class="ek-card"<?= ($ministryNamesPending ?? 0) > 0 ? ' open' : '' ?>>
  <summary class="ek-card-head" style="cursor:pointer"><div>
    <h2 style="display:inline">Ministry names from the workbook</h2>
    <p>
      Every ministry name a spreadsheet has used, and the ministry it maps to.
      <?php if (($ministryNamesPending ?? 0) > 0): ?>
        <strong><?= (int) $ministryNamesPending ?> still need a decision</strong> — until then those
        names are ignored and nobody is added to that ministry.
      <?php else: ?>
        Every name currently maps to a ministry.
      <?php endif; ?>
    </p>
  </div></summary>
  <div class="ek-card-body">
    <form method="post" action="<?= $base ?>/admin/maintenance/import/ministry-names">
      <div class="ek-table-wrap">
      <table class="mi">
        <thead>
          <tr>
            <th scope="col">Name in the workbook</th>
            <th scope="col">Rows</th>
            <th scope="col">Status</th>
            <th scope="col">Maps to</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($ministryNameRows as $row): ?>
          <?php
            $decided = $row['decidedId'];
            $current = $decided !== null ? (string) $decided : '';
            $statusLabel = $row['needsDecision'] ? 'Not recognised'
                : ($decided === 0 ? 'Ignored — not a ministry'
                : ($decided !== null ? 'Mapped by you' : 'Matched automatically'));
          ?>
          <tr<?= $row['needsDecision'] ? ' class="needs-decision"' : '' ?>>
            <th scope="row"><?= $h($row['raw']) ?></th>
            <td><?= (int) $row['count'] ?></td>
            <td><?= $h($statusLabel) ?></td>
            <td>
              <label class="sr-only" for="mn-<?= $h($row['key']) ?>">Ministry for <?= $h($row['raw']) ?></label>
              <select id="mn-<?= $h($row['key']) ?>" name="decision[<?= $h($row['raw']) ?>]">
                <option value=""<?= $current === '' ? ' selected' : '' ?>>
                  <?= $row['needsDecision'] ? '— choose a ministry —' : 'Leave as it is' ?>
                </option>
                <option value="0"<?= $current === '0' ? ' selected' : '' ?>>Not a ministry — ignore it</option>
                <?php foreach (($ministryChoices ?? []) as $choice): ?>
                  <option value="<?= (int) $choice['id'] ?>"<?= $current === (string) $choice['id'] ? ' selected' : '' ?>>
                    <?= $h($choice['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php // Positions the name carried, beside the ministry it maps to. ?>
              <?php if (!empty($row['positions'])):
                  $positionOf = '';
                  foreach (($ministryChoices ?? []) as $choice) {
                      if ((int) $choice['id'] === (int) ($decided ?? $row['resolvedId'] ?? 0)) {
                          $positionOf = (string) $choice['name'];
                      }
                  } ?>
                <span class="mi-note"><?= $h(trim($positionOf . ' (' . implode(', ', $row['positions']) . ')')) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <p class="mi-note">
        Saving a mapping does not change anybody's membership on its own. Apply the batch again
        and the names will resolve.
      </p>
      <button class="ek-btn ek-btn-primary" type="submit">Save ministry name mappings</button>
    </form>
  </div>
</details>
<?php endif; ?>

<?php if (!$batch): ?>
<div class="ek-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr))">
  <section class="ek-card" aria-labelledby="mi-import">
    <div class="ek-card-head"><div>
      <h2 id="mi-import">Import a campus roster</h2>
      <p>Load the workbook into staging. Nothing changes in member records until you review the rows and apply them.</p>
    </div></div>
    <div class="ek-card-body">
      <p class="mi-help" style="margin-bottom:12px">
        The <strong>Christlikeness Hub</strong> worksheet is enough for both North York
        and Scarborough. Merged household cells (one address covering several family
        members) are copied onto every person in that range — that only works with
        <strong>.xlsx</strong> or a Google Sheets link (downloaded as Excel). CSV drops
        those merges. A second worksheet is optional fill-in only.
      </p>
      <form class="mi-form" method="post" action="<?= $base ?>/admin/maintenance/import/ingest" enctype="multipart/form-data">
        <div class="ek-field">
          <label for="mi-campus">Campus this roster belongs to</label>
          <select class="ek-select" id="mi-campus" name="campus_id" required>
            <option value="">Choose a campus</option>
            <?php foreach ($campuses as $c): ?>
              <option value="<?= (int) $c['id'] ?>"<?= (int) $campusId === (int) $c['id'] ? ' selected' : '' ?>><?= $h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ek-field">
          <label for="mi-preset">Worksheet preset</label>
          <select class="ek-select" name="preset" id="mi-preset">
            <option value="ny" data-primary="<?= $h(\App\Services\MemberWorkbookParser::HUB_SHEET) ?>">North York — Hub only</option>
            <option value="sc" data-primary="<?= $h(\App\Services\MemberWorkbookParser::SC_HUB_SHEET) ?>">Scarborough — Hub only</option>
            <option value="custom">Custom worksheet names</option>
          </select>
        </div>
        <div class="ek-field">
          <label for="mi-primary">Primary worksheet (Hub)</label>
          <input class="ek-input" type="text" name="hub_sheet" id="mi-primary" value="<?= $h($hubSheet) ?>">
        </div>
        <div class="ek-field">
          <label for="mi-secondary">Secondary worksheet (optional fill-in)</label>
          <input class="ek-input" type="text" name="ny_sheet" id="mi-secondary" value="<?= $h($nySheet) ?>" placeholder="Leave blank if the Hub is enough">
        </div>
        <div class="ek-field">
          <label for="mi-workbook">Excel workbook (.xlsx)</label>
          <input class="ek-input" id="mi-workbook" type="file" name="workbook" accept=".xlsx,.xlsm,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        </div>
        <div class="ek-field">
          <label for="mi-google">Or a Google Sheets link</label>
          <input class="ek-input" id="mi-google" type="url" name="google_url" value="<?= $h($googleUrl) ?>" placeholder="https://docs.google.com/spreadsheets/d/…">
          <span class="ek-hint">The sheet must be shared with anyone who has the link.</span>
        </div>
        <fieldset class="mi-rules">
          <legend>When are two rows the same person?</legend>
          <p class="mi-help">Always when the surname and the first word of the first name match. Tick what must
            also agree when that is not enough — for example a parent and child sharing a name, email and address.
            A blank cell never counts as a difference.</p>
          <?php foreach (\App\Services\MemberMatchRules::LABELS as $ruleKey => $rule): ?>
            <label class="mi-confirm">
              <input type="checkbox" name="match[]" value="<?= $h($ruleKey) ?>">
              <span><strong><?= $h($rule['label']) ?></strong> — <?= $h($rule['hint']) ?></span>
            </label>
          <?php endforeach; ?>
        </fieldset>
        <div><button class="ek-btn ek-btn-primary" type="submit">Load into staging</button></div>
      </form>
    </div>
  </section>

  <div class="rec-main">
    <section class="ek-card" aria-labelledby="mi-export">
      <div class="ek-card-head"><div>
        <h2 id="mi-export">Export member records</h2>
        <p>Download the roster as an Excel workbook, in the same layout the import reads back.</p>
      </div></div>
      <div class="ek-card-body">
        <form class="mi-form" method="post" action="<?= $base ?>/admin/maintenance/export-xlsx">
          <input type="hidden" name="return" value="import">
          <div class="ek-field">
            <label for="mi-export-campus">Which members</label>
            <select class="ek-select" id="mi-export-campus" name="campus_id">
              <option value="0">Everyone (one sheet per campus)</option>
              <?php foreach ($campuses as $c): ?>
                <option value="<?= (int) $c['id'] ?>"<?= (int) $campusId === (int) $c['id'] ? ' selected' : '' ?>><?= $h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><button class="ek-btn ek-btn-primary" type="submit">Download Excel workbook</button></div>
          <p class="ek-hint" style="margin:0">A copy is kept in the private archive, listed below and on <a class="rec-link" href="<?= $base ?>/admin/maintenance">Backups &amp; maintenance</a>.</p>
        </form>
      </div>
      <?php if ($exports !== []): ?>
        <div class="ek-card-body" style="border-top:1px solid var(--line,#d9e4dd)">
          <h3 class="rec-small rec-muted" style="margin:0 0 6px">Recent exports</h3>
          <ul class="rec-history">
            <?php foreach ($exports as $x): ?>
              <li style="grid-template-columns:minmax(0,1fr) auto;align-items:center">
                <span><?= $h(date('j M Y, H:i', (int) $x['mtime'])) ?> <span class="rec-muted">· <?= $h($fmtSize((int) $x['bytes'])) ?></span></span>
                <a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/maintenance/file?path=<?= urlencode((string) $x['relative']) ?>">Download<span class="sr-only"> the workbook exported <?= $h(date('j M Y, H:i', (int) $x['mtime'])) ?></span></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>

    <section class="ek-card" aria-labelledby="mi-batches">
      <div class="ek-card-head"><div><h2 id="mi-batches">Staging batches</h2><p>Workbooks loaded before. Open one to keep cleaning it.</p></div></div>
      <?php if (!$batches): ?>
        <div class="ek-card-body"><p class="mi-help">No workbook has been loaded yet. Start with <a class="rec-link" href="#mi-import">Import a campus roster</a>.</p></div>
      <?php else: ?>
        <div class="ek-table-wrap">
          <table class="ek-table rec-cards">
            <caption class="sr-only">Staging batches, newest first</caption>
            <thead><tr><th scope="col">Workbook</th><th scope="col">Campus</th><th scope="col">Status</th><th scope="col" class="is-num">Rows</th><th scope="col">Loaded</th></tr></thead>
            <tbody>
            <?php foreach ($batches as $b): $bStatus = (string) $b['status']; ?>
              <tr>
                <td class="rec-title" data-label="Workbook"><a class="rec-link" href="<?= $base ?>/admin/maintenance/import?batch=<?= (int) $b['id'] ?>"><?= $h($b['source_label'] ?: ('Batch #' . $b['id'])) ?></a></td>
                <td data-label="Campus"><?= $h($campusNames[(int) ($b['campus_id'] ?? 0)] ?? '—') ?></td>
                <td data-label="Status"><span class="ek-badge<?= $bStatus === 'applied' ? ' is-ok' : '' ?>"><?= $h(ucfirst($bStatus)) ?></span></td>
                <td data-label="Rows" class="is-num"><?= (int) $b['row_count'] ?></td>
                <td data-label="Loaded" style="white-space:nowrap"><?= $h(records_when((string) $b['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<script>
(function () {
  var preset = document.getElementById('mi-preset');
  var primary = document.getElementById('mi-primary');
  if (!preset || !primary) return;
  preset.addEventListener('change', function () {
    var opt = preset.options[preset.selectedIndex];
    var p = opt.getAttribute('data-primary');
    if (p) primary.value = p;
  });
})();
</script>
<?php else: ?>
  <?php
    $campusName = $campusNames[(int) $batch['campus_id']] ?? '';
    $applied = ($batch['status'] ?? '') === 'applied';
  ?>
  <section class="ek-card" aria-labelledby="mi-staging">
    <div class="ek-card-head">
      <div>
        <h2 id="mi-staging"><?= $applied ? 'Applied to ' : 'Review staging for ' ?><?= $h($campusName ?: 'the campus') ?></h2>
        <p>
          Hub: <?= $h($batch['hub_sheet'] ?? '—') ?><?= !empty($batch['hub_updated']) ? ' · ' . $h($batch['hub_updated']) : '' ?>
          <?php if (!empty($batch['ny_sheet'])): ?>
          · Secondary: <?= $h($batch['ny_sheet']) ?><?= !empty($batch['ny_updated']) ? ' · ' . $h($batch['ny_updated']) : '' ?>
          <?php endif; ?>
        </p>
      </div>
      <a class="ek-btn" href="<?= $base ?>/admin/maintenance/import">Import &amp; export</a>
    </div>
    <div class="ek-card-body" style="display:grid;gap:var(--sp-3,12px)">
      <?php
        $duplicateGroups = $duplicateGroups ?? [];
        $legacyDuplicates = $legacyDuplicates ?? [];
        $matchRuleLabels = $matchRuleLabels ?? [];
        $otherWarnings = [];
        foreach (preg_split("/\r\n|\n|\r/", (string) ($batch['warnings'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'Removed duplicate:') || str_contains($line, 'duplicate worksheet')) {
                continue;
            }
            $otherWarnings[] = $line;
        }
      ?>
      <p class="mi-help">
        Same person when the surname and first word of the first name match<?= $matchRuleLabels === []
          ? '.'
          : ', and ' . $h(strtolower(implode(', ', $matchRuleLabels))) . ' agree.' ?>
      </p>
      <?php if ($legacyDuplicates !== []): ?>
        <div class="ek-alert" style="border-left-color:var(--gold,#c98a2b)">
          <div>
            <strong><?= count($legacyDuplicates) ?> duplicate <?= count($legacyDuplicates) === 1 ? 'row was' : 'rows were' ?> removed before any member record update.</strong>
            This spreadsheet was loaded before duplicates could be decided one by one. To decide them, discard it and load it again.
            <ul class="mi-dupes">
              <?php foreach ($legacyDuplicates as $d): ?>
                <li>Removed <?= $h($d['name'] ?? '') ?> · kept <?= $h($d['kept'] ?? '') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>
      <?php if ($duplicateGroups !== []): ?>
        <section class="mi-dup-list" aria-labelledby="mi-dups-title">
          <h3 id="mi-dups-title"><?= count($duplicateGroups) ?> possible <?= count($duplicateGroups) === 1 ? 'duplicate' : 'duplicates' ?></h3>
          <p class="mi-help">
            These rows look like the same person. For each group, keep the row that is the person — the others are
            set to Skip and fill its blank cells — or keep every row if they are different people.
            The suggestion is the row with more filled fields. You can change a decision until the batch is applied.
          </p>
          <?php foreach ($duplicateGroups as $g): $gid = (int) $g['group']; ?>
            <form class="mi-dup" id="mi-dup-<?= $gid ?>" method="post" action="<?= $base ?>/admin/maintenance/import/duplicate">
              <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
              <input type="hidden" name="group" value="<?= $gid ?>">
              <p class="mi-dup-state">
                <?php if ($g['decision'] === 'separate'): ?>
                  <strong>Decided: different people.</strong> Every row is kept.
                <?php else:
                  $keptName = '';
                  foreach ($g['rows'] as $r) {
                      if ((int) $r['id'] === (int) $g['keep']) {
                          $keptName = trim($r['last_name'] . ', ' . ($r['first_name'] ?? ''));
                      }
                  } ?>
                  <strong>Decided: same person.</strong> Keeping <?= $h($keptName) ?>.
                  <?php if (($g['filled'] ?? []) !== []):
                    $filledText = [];
                    foreach ($g['filled'] as $field => $value) {
                        $filledText[] = str_replace('_', ' ', (string) $field) . ': ' . (string) $value;
                    } ?>
                    Filled on the kept row from the others — <?= $h(implode('; ', $filledText)) ?>.
                  <?php endif; ?>
                <?php endif; ?>
              </p>
              <div class="mi-scroll">
                <table class="mi mi-dup-table">
                  <thead>
                    <tr><th>Keep</th><th>Name</th><th>Email</th><th>Phone</th><th>Birthday</th><th>Type</th><th>Address</th><th>Status</th></tr>
                  </thead>
                  <tbody>
                  <?php foreach ($g['rows'] as $r):
                    $rid = (int) $r['id'];
                    $bm = (int) ($r['birth_month'] ?? 0);
                    $bd = (int) ($r['birth_day'] ?? 0);
                    $by = (int) ($r['birth_year'] ?? 0);
                    $birthday = $bm > 0 && $bd > 0 ? $bm . '/' . $bd . ($by > 0 ? '/' . $by : '') : '';
                    $checked = $g['decision'] === 'keep' && (int) $g['keep'] === $rid; ?>
                    <tr>
                      <td><input type="radio" name="choice" value="<?= $rid ?>" id="mi-dup-<?= $gid ?>-<?= $rid ?>"<?= $checked ? ' checked' : '' ?><?= $applied ? ' disabled' : '' ?>></td>
                      <td><label for="mi-dup-<?= $gid ?>-<?= $rid ?>"><?= $h(trim($r['last_name'] . ', ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? ''))) ?></label>
                        <?= $rid === (int) $g['suggested'] ? '<span class="mi-tag">Suggested</span>' : '' ?></td>
                      <td><?= $h($r['email'] ?? '') ?></td>
                      <td><?= $h($r['phone'] ?? '') ?></td>
                      <td><?= $h($birthday) ?></td>
                      <td><?= $h($r['member_type'] ?? '') ?></td>
                      <td><?= $h($r['address_raw'] ?? '') ?></td>
                      <td><?= $h(ucfirst((string) $r['status'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="mi-actions">
                <label class="mi-confirm">
                  <input type="radio" name="choice" value="separate"<?= $g['decision'] === 'separate' ? ' checked' : '' ?><?= $applied ? ' disabled' : '' ?>>
                  <span><strong>Different people</strong> — keep every row</span>
                </label>
                <?php if (!$applied): ?>
                  <button class="ek-btn" type="submit">Save decision</button>
                <?php endif; ?>
              </div>
            </form>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
      <?php if ($otherWarnings !== []): ?>
        <div class="ek-alert is-error"><div><strong>Check these before applying.</strong><br><?= nl2br($h(implode("\n", $otherWarnings))) ?></div></div>
      <?php endif; ?>

      <div class="ek-stats">
        <div class="ek-stat"><span class="ek-stat-label">Staged</span><span class="ek-stat-value"><?= (int) $counts['total'] ?></span></div>
        <div class="ek-stat"><span class="ek-stat-label">Ready</span><span class="ek-stat-value"><?= (int) $counts['ready'] ?></span></div>
        <div class="ek-stat"><span class="ek-stat-label">Needs review</span><span class="ek-stat-value"><?= (int) $counts['draft'] ?></span></div>
        <div class="ek-stat"><span class="ek-stat-label">Skip</span><span class="ek-stat-value"><?= (int) $counts['skip'] ?></span></div>
      </div>

      <?php if (!$applied): ?>
        <div class="mi-actions">
          <form method="post" action="<?= $base ?>/admin/maintenance/import/apply" class="mi-actions" onsubmit="return confirm('Apply <?= (int) $counts['ready'] ?> ready rows to <?= $h($campusName) ?>? Draft/skip rows will not be on this campus afterward.');">
            <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
            <label class="mi-confirm">
              <input type="checkbox" name="confirm" value="1" required>
              <span>These ready rows are cleaned and should replace the <strong><?= $h($campusName) ?></strong> campus roster.</span>
            </label>
            <button class="ek-btn ek-btn-primary" type="submit"<?= $counts['ready'] < 1 ? ' disabled' : '' ?>>Apply <?= (int) $counts['ready'] ?> ready <?= (int) $counts['ready'] === 1 ? 'row' : 'rows' ?></button>
          </form>
        </div>
        <div class="mi-actions">
          <?php
            // Offered only when there is something to act on, so it is not a
            // button that quietly does nothing.
            $blankTypes = 0;
            foreach ($rows as $r) {
                if (trim((string) ($r['member_type'] ?? '')) === '' && (int) ($r['birth_year'] ?? 0) > 1900) {
                    $blankTypes++;
                }
            }
          ?>
          <?php if ($blankTypes > 0): ?>
            <form method="post" action="<?= $base ?>/admin/maintenance/import/suggest-types" class="rec-inline-form"
                  onsubmit="return confirm('Suggest member types for <?= (int) $blankTypes ?> row(s) from each person\'s age?\n\nThese are suggestions, not what the spreadsheet said — age indicates a member type but does not settle it. They are written to the staged rows only, so you can change them, and nothing reaches member records until you apply.');">
              <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
              <button class="ek-btn" type="submit">Fill <?= (int) $blankTypes ?> blank member type<?= $blankTypes === 1 ? '' : 's' ?> from age</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= $base ?>/admin/maintenance/import/discard" class="rec-inline-form" onsubmit="return confirm('Discard this spreadsheet?\n\n<?= (int) $counts['total'] ?> staged row(s) and any corrections you have made to them will be deleted. Member records are not affected — nothing from this spreadsheet has been saved to them yet.\n\nYou would need to upload the spreadsheet again to start over.');">
            <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
            <button class="ek-btn ek-btn-danger" type="submit">Discard this spreadsheet</button>
          </form>
        </div>
      <?php else: ?>
        <p class="mi-help">This batch was applied<?= !empty($batch['applied_at']) ? ' on ' . $h(records_when((string) $batch['applied_at'])) : '' ?>. The rows below are kept for reference and can no longer be edited.
          <a class="rec-link" href="<?= $base ?>/admin/people">Open member records</a></p>
      <?php endif; ?>

      <nav class="ek-tabs is-sub" aria-label="Show rows">
        <?php foreach (['' => 'All', 'ready' => 'Ready', 'draft' => 'Draft', 'skip' => 'Skip'] as $k => $lab): ?>
          <a class="ek-tab" href="<?= $base ?>/admin/maintenance/import?batch=<?= (int) $batch['id'] ?><?= $k !== '' ? '&amp;status=' . $h($k) : '' ?>"<?= $statusFilter === $k ? ' aria-current="page"' : '' ?>><?= $lab ?></a>
        <?php endforeach; ?>
      </nav>
      <p class="mi-help">Click a cell to edit; it saves as you leave it. Hub values win; cells filled from the secondary worksheet are tagged. Draft and Skip rows are not applied.</p>

      <div class="mi-scroll" data-save="<?= $base ?>/admin/maintenance/import/row">
        <table class="mi" id="stagingGrid">
          <thead>
            <tr>
              <th>Src</th><th>Status</th><th>Last</th><th>First</th><th>Preferred</th><th>Middle</th>
              <th>Type</th><th>Email</th><th>Phone</th><th>Address</th><th>City</th>
              <th>Birth m/d/y</th><th>Member since</th><th>Ministry</th><th>Match #</th><th>Notes</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="16" class="mi-help" style="padding:20px;text-align:center">No rows in this filter.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
            <tr data-id="<?= $rid ?>">
              <td><span class="mi-badge <?= $h($r['source']) ?>"><?= $h($r['source']) ?></span>
                <?php if ((int) ($r['duplicate_group'] ?? 0) > 0): ?><a class="mi-tag" href="#mi-dup-<?= (int) $r['duplicate_group'] ?>" title="Possible duplicate: see the decision">Dup</a><?php endif; ?></td>
              <td><select <?= $applied ? 'disabled' : '' ?> data-field="status">
                <?php foreach (['draft' => 'Draft', 'ready' => 'Ready', 'skip' => 'Skip'] as $sv => $sl): ?>
                  <option value="<?= $sv ?>"<?= ($r['status'] ?? '') === $sv ? ' selected' : '' ?>><?= $sl ?></option>
                <?php endforeach; ?>
              </select></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="last_name" value="<?= $h($r['last_name']) ?>"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="first_name" value="<?= $h($r['first_name']) ?>"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="preferred_name" value="<?= $h($r['preferred_name']) ?>"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="middle_name" value="<?= $h($r['middle_name']) ?>"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="member_type" value="<?= $h($r['member_type']) ?>" style="width:110px"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="email" value="<?= $h($r['email']) ?>" style="min-width:160px"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="phone" value="<?= $h($r['phone']) ?>" style="width:110px"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="address_raw" value="<?= $h($r['address_raw']) ?>" style="min-width:180px"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="city" value="<?= $h($r['city']) ?>"></td>
              <td>
                <input <?= $applied ? 'disabled' : '' ?> data-field="birth_month" value="<?= $h($r['birth_month']) ?>" style="width:36px" placeholder="m">
                <input <?= $applied ? 'disabled' : '' ?> data-field="birth_day" value="<?= $h($r['birth_day']) ?>" style="width:36px" placeholder="d">
                <input <?= $applied ? 'disabled' : '' ?> data-field="birth_year" value="<?= $h($r['birth_year']) ?>" style="width:52px" placeholder="yyyy">
              </td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="member_since" value="<?= $h($r['member_since']) ?>" style="width:100px"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="ministry" value="<?= $h($r['ministry']) ?>" style="min-width:140px"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="matched_person_id" value="<?= $h($r['matched_person_id']) ?>" style="width:60px"></td>
              <td><input <?= $applied ? 'disabled' : '' ?> data-field="notes" value="<?= $h($r['notes']) ?>" style="min-width:160px"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
  <script>
  (function () {
    var root = document.querySelector('[data-save]');
    if (!root) return;
    var url = root.getAttribute('data-save');
    function save(el) {
      var td = el.closest('td');
      var tr = el.closest('tr');
      if (!td || !tr) return;
      td.classList.remove('saved', 'err');
      td.classList.add('saving');
      var body = new URLSearchParams();
      body.set('id', tr.getAttribute('data-id'));
      body.set('field', el.getAttribute('data-field'));
      body.set('value', el.value);
      fetch(url, {method: 'POST', headers: {'Accept': 'application/json'}, body: body, credentials: 'same-origin'})
        .then(function (r) { return r.json().then(function (j) { return {ok: r.ok, j: j}; }); })
        .then(function (res) {
          td.classList.remove('saving');
          if (!res.ok || (res.j && res.j.success === false)) {
            td.classList.add('err');
            td.title = (res.j && res.j.error) ? res.j.error : 'Save failed';
            return;
          }
          td.classList.add('saved');
          td.title = '';
        })
        .catch(function () { td.classList.remove('saving'); td.classList.add('err'); });
    }
    root.addEventListener('change', function (e) {
      var t = e.target;
      if (t && t.getAttribute('data-field')) save(t);
    });
    root.addEventListener('blur', function (e) {
      var t = e.target;
      if (t && t.tagName === 'INPUT' && t.getAttribute('data-field')) save(t);
    }, true);
  })();
  </script>
<?php endif; ?>

<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'import',
    'pageTitle' => 'Import & export',
    'pageSubtitle' => 'Stage a campus workbook, clean the rows, apply them; download the member workbook.',
    'sectionTitle' => 'Import & export',
    'sectionDescription' => 'Bring a campus roster in from the Drive workbook, checking every row before it reaches member records, or download the member records as an Excel workbook.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
