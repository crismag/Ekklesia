<?php
/**
 * Admin · Member import — multi-step: ingest Hub + North York into staging,
 * clean rows, then apply ready records to a campus.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var list<array{campus_id:int,campus_name:string}> $campuses
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
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$noticeMap = [
    'ingested' => ['ok', $flash !== '' ? $flash : 'Workbook loaded into staging. Clean the rows, then apply.'],
    'imported' => ['ok', $flash !== '' ? $flash : 'Campus members updated from the cleaned staging list.'],
    'discarded' => ['ok', 'Staging batch discarded.'],
    'typed' => ['ok', $flash !== '' ? $flash : 'Member types suggested on the staged rows.'],
    'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.'],
];
$step = $batch ? 2 : 1;
if ($batch && ($batch['status'] ?? '') === 'applied') {
    $step = 3;
}

ob_start();
?>
<style>
  .mi-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .mi-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}
  .mi-alert.warn{background:#fff8e6;border:1px solid #f0d48a;color:#8a5a00}
  .mi-dupes{margin:0;padding-left:18px;font-size:13px;max-height:180px;overflow:auto}
  .mi-steps{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}
  .mi-step{padding:6px 12px;border-radius:999px;font-size:12px;font-weight:800;background:#eef2f5;color:#5c6b63}
  .mi-step.on{background:#0c5a45;color:#fff}
  .mi-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:0 0 16px}
  @media (max-width:880px){.mi-grid{grid-template-columns:1fr}}
  .mi-field{margin:0 0 10px}
  .mi-field label{display:block;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63);margin-bottom:4px}
  .mi-field input,.mi-field select{width:100%;font:inherit;padding:9px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff}
  .mi-help{font-size:13px;color:var(--muted,#5c6b63);margin:0 0 12px;line-height:1.45}
  .mi-btn{font:inherit;font-size:13px;font-weight:800;border:0;border-radius:8px;padding:10px 16px;cursor:pointer;background:#0c5a45;color:#fff;text-decoration:none;display:inline-block}
  .mi-btn.sec{background:#fff;color:var(--ink,#1b2a24);border:1px solid var(--line,#c7d4cd)}
  .mi-btn.warn{background:#8a1f17}
  .mi-stats{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 12px}
  .mi-stat{background:#fff;border:1px solid var(--line,#dbe4ec);border-radius:10px;padding:10px 14px;min-width:90px}
  .mi-stat b{display:block;font-size:20px}
  .mi-stat span{font-size:12px;color:var(--muted)}
  .mi-scroll{overflow:auto;max-height:70vh;border:1px solid var(--line,#dbe4ec);border-radius:8px}
  table.mi{width:max-content;min-width:100%;border-collapse:collapse;font-size:12.5px;background:#fff}
  /* Ministry-name review. A row awaiting a decision is marked by a rule and a
     word, never by colour alone — this table gets printed and photocopied. */
  tr.needs-decision th[scope=row]{border-left:3px solid #b3261e;padding-left:8px;font-weight:800}
  tr.needs-decision td{background:#fff8f8}
  table.mi select{font:inherit;font-size:12.5px;min-height:32px;padding:4px 6px;
    border:1px solid var(--line,#d0d7de);border-radius:6px;background:#fff;max-width:260px}
  table.mi select:focus-visible{outline:2px solid var(--teal,#117b6d);outline-offset:1px}
  .mi-note{font-size:12px;color:var(--muted,#57606a);margin:10px 0}
  table.mi th,table.mi td{padding:4px 6px;text-align:left;border-bottom:1px solid var(--line,#eef2f5);white-space:nowrap}
  table.mi th{font-size:11px;text-transform:uppercase;color:var(--muted);background:#f8fafb;position:sticky;top:0}
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
  .mi-tabs a{display:inline-block;margin:0 6px 10px 0;padding:4px 10px;border-radius:999px;background:#eef2f5;color:inherit;text-decoration:none;font-size:12px;font-weight:700}
  .mi-tabs a.on{background:#0c5a45;color:#fff}
  table.mi td.saving{box-shadow:inset 0 0 0 2px #f0a500}
  table.mi td.saved{box-shadow:inset 0 0 0 2px #1a7a3a}
  table.mi td.err{box-shadow:inset 0 0 0 2px #b3261e;background:#fdecea}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="mi-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <article class="admin-card"><div class="admin-card-body" style="color:var(--muted)">Only a portal-wide admin can import or export members.</div></article>
<?php else: ?>

<div class="mi-steps">
  <span class="mi-step<?= $step === 1 ? ' on' : '' ?>">1. Load worksheets</span>
  <span class="mi-step<?= $step === 2 ? ' on' : '' ?>">2. Clean staging</span>
  <span class="mi-step<?= $step === 3 ? ' on' : '' ?>">3. Apply to campus</span>
</div>

  <?php // Ministry names as the workbook wrote them, and where each one went.
        // Rendered whenever an import has recorded anything, so an
        // administrator can see the mapping rather than infer it from which
        // memberships appeared. ?>
  <?php if (!empty($ministryNameRows)): ?>
  <article class="admin-card">
    <div class="admin-card-head"><div>
      <h2>Ministry names from the workbook</h2>
      <p>
        Every ministry name a spreadsheet has used, and the ministry it maps to.
        <?php if (($ministryNamesPending ?? 0) > 0): ?>
          <strong><?= (int) $ministryNamesPending ?> still need a decision</strong> — until then those
          names are ignored and nobody is added to that ministry.
        <?php else: ?>
          Every name currently maps to a ministry.
        <?php endif; ?>
      </p>
    </div></div>
    <div class="admin-card-body">
      <form method="post" action="<?= $base ?>/admin/maintenance/import/ministry-names">
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
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="mi-note">
          Saving a mapping does not change anybody's membership on its own. Apply the batch again
          and the names will resolve.
        </p>
        <button type="submit">Save ministry name mappings</button>
      </form>
    </div>
  </article>
  <?php endif; ?>

<?php if (!$batch): ?>
<div class="mi-grid">
  <article class="admin-card">
    <div class="admin-card-head"><div>
      <h2>1. Load Drive / Excel into staging</h2>
      <p>Nothing is written to member records until you apply a cleaned batch.</p>
    </div></div>
    <div class="admin-card-body">
      <p class="mi-help">
        The <strong>Christlikeness Hub</strong> worksheet is enough for both North York
        and Scarborough. Merged household cells (one address covering several family
        members) are copied onto every person in that range — that only works with
        <strong>.xlsx</strong> or a Google Sheets link (downloaded as Excel). CSV drops
        those merges. A second worksheet is optional fill-in only.
      </p>
      <form method="post" action="<?= $base ?>/admin/maintenance/import/ingest" enctype="multipart/form-data">
        <div class="mi-field">
          <label>Campus this roster belongs to</label>
          <select name="campus_id" required>
            <option value="">Choose campus…</option>
            <?php foreach ($campuses as $c): ?>
              <option value="<?= (int) $c['campus_id'] ?>"<?= (int) $campusId === (int) $c['campus_id'] ? ' selected' : '' ?>><?= $h($c['campus_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mi-field">
          <label>Campus worksheet preset</label>
          <select name="preset" id="mi-preset">
            <option value="ny" data-primary="<?= $h(\App\Services\MemberWorkbookParser::HUB_SHEET) ?>">North York — Hub only</option>
            <option value="sc" data-primary="<?= $h(\App\Services\MemberWorkbookParser::SC_HUB_SHEET) ?>">Scarborough — Hub only</option>
            <option value="custom">Custom worksheet names</option>
          </select>
        </div>
        <div class="mi-field">
          <label for="mi-primary">Primary worksheet (Hub)</label>
          <input type="text" name="hub_sheet" id="mi-primary" value="<?= $h($hubSheet) ?>">
        </div>
        <div class="mi-field">
          <label for="mi-secondary">Secondary worksheet (optional fill-in)</label>
          <input type="text" name="ny_sheet" id="mi-secondary" value="<?= $h($nySheet) ?>" placeholder="Leave blank if the Hub is enough">
        </div>
        <div class="mi-field">
          <label for="mi-workbook">Excel workbook (.xlsx)</label>
          <input id="mi-workbook" type="file" name="workbook" accept=".xlsx,.xlsm,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        </div>
        <div class="mi-field">
          <label for="mi-google">…or Google Sheets link (shared with anyone with the link)</label>
          <input id="mi-google" type="url" name="google_url" value="<?= $h($googleUrl) ?>" placeholder="https://docs.google.com/spreadsheets/d/…">
        </div>
        <button class="mi-btn" type="submit">Load into staging</button>
      </form>
    </div>
  </article>

  <article class="admin-card">
    <div class="admin-card-head"><div>
      <h2>Export campus members</h2>
      <p>Download the portal roster after a successful apply.</p>
    </div></div>
    <div class="admin-card-body">
      <form method="post" action="<?= $base ?>/admin/maintenance/export-xlsx">
        <div class="mi-field">
          <label>Campus</label>
          <select name="campus_id" required>
            <option value="">Choose campus…</option>
            <?php foreach ($campuses as $c): ?>
              <option value="<?= (int) $c['campus_id'] ?>"<?= (int) $campusId === (int) $c['campus_id'] ? ' selected' : '' ?>><?= $h($c['campus_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="mi-btn sec" type="submit">Save styled Excel to archive</button>
      </form>

      <?php if ($batches): ?>
        <p class="mi-help" style="margin-top:16px"><strong>Recent staging batches</strong></p>
        <ul style="margin:0;padding-left:18px;font-size:13px">
          <?php foreach ($batches as $b): ?>
            <li>
              <a href="<?= $base ?>/admin/maintenance/import?batch=<?= (int) $b['id'] ?>"><?= $h($b['source_label'] ?: ('Batch #' . $b['id'])) ?></a>
              · <?= $h($b['status']) ?> · <?= (int) $b['row_count'] ?> rows
              · <?= $h($b['created_at']) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </article>
</div>
<?php else: ?>
  <?php
    $campusName = '';
    foreach ($campuses as $c) {
        if ((int) $c['campus_id'] === (int) $batch['campus_id']) {
            $campusName = (string) $c['campus_name'];
        }
    }
    $applied = ($batch['status'] ?? '') === 'applied';
  ?>
  <article class="admin-card">
    <div class="admin-card-head"><div>
      <h2>2. Clean <?= $h($campusName ?: 'campus') ?> staging</h2>
      <p>
        Hub: <?= $h($batch['hub_sheet'] ?? '—') ?><?= !empty($batch['hub_updated']) ? ' · ' . $h($batch['hub_updated']) : '' ?>
        <?php if (!empty($batch['ny_sheet'])): ?>
        · Secondary: <?= $h($batch['ny_sheet']) ?><?= !empty($batch['ny_updated']) ? ' · ' . $h($batch['ny_updated']) : '' ?>
        <?php endif; ?>
      </p>
    </div></div>
    <div class="admin-card-body">
      <?php
        $dupes = is_array($batch['duplicate_report'] ?? null) ? $batch['duplicate_report'] : [];
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
      <?php if ($dupes !== []): ?>
        <div class="mi-alert warn">
          <strong><?= count($dupes) ?> duplicate <?= count($dupes) === 1 ? 'row was' : 'rows were' ?> removed before any member record update.</strong>
          The kept row is the copy with more filled fields (email, phone, address). Review the list, then apply.
          <ul class="mi-dupes">
            <?php foreach ($dupes as $d): ?>
              <li>Removed <?= $h($d['name'] ?? '') ?> · kept <?= $h($d['kept'] ?? '') ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($otherWarnings !== []): ?>
        <div class="mi-alert err"><?= nl2br($h(implode("\n", $otherWarnings))) ?></div>
      <?php endif; ?>
      <div class="mi-stats">
        <div class="mi-stat"><b><?= (int) $counts['total'] ?></b><span>Staged</span></div>
        <div class="mi-stat"><b><?= (int) $counts['ready'] ?></b><span>Ready</span></div>
        <div class="mi-stat"><b><?= (int) $counts['draft'] ?></b><span>Needs review</span></div>
        <div class="mi-stat"><b><?= (int) $counts['skip'] ?></b><span>Skip</span></div>
      </div>
      <div class="mi-tabs">
        <?php foreach (['' => 'All', 'ready' => 'Ready', 'draft' => 'Draft', 'skip' => 'Skip'] as $k => $lab): ?>
          <a class="<?= $statusFilter === $k ? 'on' : '' ?>" href="<?= $base ?>/admin/maintenance/import?batch=<?= (int) $batch['id'] ?><?= $k !== '' ? '&status=' . $h($k) : '' ?>"><?= $lab ?></a>
        <?php endforeach; ?>
      </div>
      <p class="mi-help">Click a cell to edit (saves immediately). Hub values win; cells filled from North York are tagged. Draft rows and Skip rows are not applied.</p>
      <?php if (!$applied): ?>
        <form method="post" action="<?= $base ?>/admin/maintenance/import/apply" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 12px" onsubmit="return confirm('Apply <?= (int) $counts['ready'] ?> ready rows to <?= $h($campusName) ?>? Draft/skip rows will not be on this campus afterward.');">
          <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
          <label class="mi-help" style="display:flex;gap:8px;align-items:flex-start;margin:0">
            <input type="checkbox" name="confirm" value="1" required>
            <span>These ready rows are cleaned and should replace the <strong><?= $h($campusName) ?></strong> campus roster.</span>
          </label>
          <button class="mi-btn warn" type="submit"<?= $counts['ready'] < 1 ? ' disabled' : '' ?>>3. Apply ready rows</button>
        </form>
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
          <form method="post" action="<?= $base ?>/admin/maintenance/import/suggest-types" style="display:inline"
                onsubmit="return confirm('Suggest member types for <?= (int) $blankTypes ?> row(s) from each person\'s age?\n\nThese are suggestions, not what the spreadsheet said — age indicates a member type but does not settle it. They are written to the staged rows only, so you can change them, and nothing reaches member records until you apply.');">
            <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
            <button class="mi-btn sec" type="submit">Fill <?= (int) $blankTypes ?> blank member type<?= $blankTypes === 1 ? '' : 's' ?> from age</button>
          </form>
        <?php endif; ?>
        <form method="post" action="<?= $base ?>/admin/maintenance/import/discard" onsubmit="return confirm('Discard this spreadsheet?\n\n<?= (int) $counts['total'] ?> staged row(s) and any corrections you have made to them will be deleted. Member records are not affected — nothing from this spreadsheet has been saved to them yet.\n\nYou would need to upload the spreadsheet again to start over.');" style="display:inline">
          <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
          <button class="mi-btn sec" type="submit">Discard this spreadsheet</button>
        </form>
      <?php else: ?>
        <p class="mi-help">This batch was applied<?= !empty($batch['applied_at']) ? ' at ' . $h($batch['applied_at']) : '' ?>.</p>
      <?php endif; ?>
      <a class="mi-btn sec" href="<?= $base ?>/admin/maintenance">Maintenance</a>
      <a class="mi-btn sec" href="<?= $base ?>/admin/maintenance/import">New workbook</a>

      <div class="mi-scroll" style="margin-top:14px" data-save="<?= $base ?>/admin/maintenance/import/row">
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
              <td><span class="mi-badge <?= $h($r['source']) ?>"><?= $h($r['source']) ?></span></td>
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
  </article>
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
<?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'maintenance',
    'pageTitle' => 'Member import · Maintenance',
    'pageSubtitle' => 'Stage a campus Hub worksheet, clean rows, then replace that campus roster.',
    'sectionTitle' => 'Maintenance · Member import',
    'sectionDescription' => 'Load the Drive Excel workbook (.xlsx keeps merged household cells), review staging, then apply ready rows.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
