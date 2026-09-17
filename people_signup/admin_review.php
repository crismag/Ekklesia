<?php
/**
 * People Sign-Up — admin review (standalone, token-gated).
 * Compact, spreadsheet-style grid with in-place editing. Cells auto-save to
 * admin_save.php. Nothing here writes to the member database — it only edits
 * registrations in visitor_registrations. Promotion is migrate.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
sg_admin_gate();

$db = sg_db();
$membersDb = sg_members_db();
$STATUSES = ['new', 'reviewed', 'duplicate', 'promoted', 'rejected'];
$STATUS_LABELS = ['new' => 'New', 'reviewed' => 'Reviewed', 'duplicate' => 'Duplicate', 'promoted' => 'Promoted to member', 'rejected' => 'Rejected'];

// ---- Listing -------------------------------------------------------------
$filter = (string) ($_GET['status'] ?? '');
$where  = in_array($filter, $STATUSES, true) ? 'WHERE status = :f' : '';
$st = $db->prepare("SELECT * FROM visitor_registrations $where ORDER BY created_at DESC, id DESC LIMIT 500");
$st->execute($where ? [':f' => $filter] : []);
$rows = $st->fetchAll();

$counts = [];
foreach ($db->query('SELECT status s, COUNT(*) c FROM visitor_registrations GROUP BY status') as $r) {
    $counts[$r['s']] = (int) $r['c'];
}
$total = array_sum($counts);

$csrf = sg_csrf();
$months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** An inline-editable text cell. */
function cell_text(array $r, string $field, string $csrf, int $size = 0): string
{
    $v = e($r[$field] ?? '');
    $st = $size ? ' style="width:' . $size . 'px"' : '';
    return '<input class="c" type="text" data-id="' . (int) $r['id'] . '" data-field="' . $field . '" value="' . $v . '" data-original="' . $v . '"' . $st . '>';
}
function cell_num(array $r, string $field, int $w, string $ph = ''): string
{
    $v = ($r[$field] === null || $r[$field] === '') ? '' : (string) (int) $r[$field];
    return '<input class="c num" type="number" data-id="' . (int) $r['id'] . '" data-field="' . $field . '" value="' . e($v) . '" data-original="' . e($v) . '" placeholder="' . e($ph) . '" style="width:' . $w . 'px">';
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Sign-Up Review</title>
<link rel="stylesheet" href="assets/signup.css"><?= theme_tokens_style_block() ?>
<style>
  :root{--grid:#d0dbd4;--head:#eef4f0;--focus:#137a5f;}
  body{background:#eef1ef;margin:0}
  .bar{position:sticky;top:0;z-index:5;background:#0c5a45;color:#eafaf3;padding:10px 14px;display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center}
  .bar h1{font-size:16px;margin:0;font-weight:800}
  .bar .sp{flex:1}
  .tabs{display:flex;flex-wrap:wrap;gap:6px}
  .tabs a{padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.14);color:#eafaf3;text-decoration:none;font-size:12px;font-weight:700}
  .tabs a.on{background:#fff;color:#0c5a45}
  .tabs a .n{opacity:.75}
  .flash{padding:8px 14px;background:#e8f1fb;color:#0f4e97;font-size:13px;border-bottom:1px solid #bcd6f5}
  .wrapg{overflow:auto;max-height:calc(100vh - 46px)}
  table{border-collapse:collapse;font-size:12.5px;background:#fff;width:max-content;min-width:100%}
  th,td{border:1px solid var(--grid);padding:0;white-space:nowrap}
  thead th{position:sticky;top:0;z-index:2;background:var(--head);color:#2a3a33;font-size:11px;text-transform:uppercase;
    letter-spacing:.02em;padding:6px 8px;text-align:left;font-weight:800}
  tbody td{height:28px}
  tbody tr:nth-child(even) td{background:#fafcfb}
  tbody tr:hover td{background:#f0f7f3}
  td.idc{padding:0 8px;color:#6b7a72;text-align:right;font-variant-numeric:tabular-nums;background:var(--head)!important;position:sticky;left:0;z-index:1;font-weight:700}
  thead th.idc{position:sticky;left:0;z-index:3}
  input.c,select.c{border:0;background:transparent;font:inherit;color:var(--ink);width:100%;min-width:70px;padding:5px 7px;outline:none}
  input.num{min-width:0;text-align:center;font-variant-numeric:tabular-nums}
  input.c:focus,select.c:focus{background:#fffbe6;box-shadow:inset 0 0 0 2px var(--focus)}
  select.c{appearance:none;cursor:pointer;padding-right:6px}
  td.saving{box-shadow:inset 0 0 0 2px #f0a500}
  td.saved{box-shadow:inset 0 0 0 2px #1a7a3a}
  td.err{box-shadow:inset 0 0 0 2px #b3261e;background:#fdecea!important}
  .st-new select{color:#0c5a45}.st-reviewed select{color:#0f4e97}.st-duplicate select{color:#8a5a00}
  .st-promoted select{color:#1a7a3a}.st-rejected select{color:#b3261e}
  .sugg{padding:2px 4px;display:flex;gap:3px;flex-wrap:wrap;max-width:220px}
  .chip{border:1px solid #bcd6f5;background:#eaf1fb;color:#0f4e97;border-radius:5px;font-size:11px;padding:2px 6px;cursor:pointer;line-height:1.3}
  .chip:hover{background:#d9e8fb}
  .mock{color:#8a5a00;font-weight:800}
  .hint{color:#6b7a72;font-size:11px;padding:4px 8px}
  .tbtn{font:inherit;font-size:12px;font-weight:800;background:#fff;color:#0c5a45;border:0;border-radius:6px;padding:5px 10px;cursor:pointer}
  .tbtn:hover{background:#eafaf3}
  #membership_status_id{font:inherit;font-size:12px;border-radius:6px;border:0;padding:4px 6px}
  td.chk{text-align:center;background:var(--head)!important}
  td.act{padding:0 6px;text-align:center}
  .mg{font:inherit;font-size:11px;font-weight:800;background:#0c5a45;color:#fff;border:0;border-radius:5px;padding:4px 9px;cursor:pointer;white-space:nowrap}
  .mg:hover{background:#137a5f}
  .mg[disabled]{background:#cdd8d2;cursor:default}
  .done{color:#1a7a3a;font-weight:800;font-size:11px}
  noscript{display:block;padding:10px 14px;background:#fdecea;color:#7a1c16}
</style></head>
<body>
<input type="hidden" id="csrf" value="<?= e($csrf) ?>">
<div class="bar">
  <h1>Guest Sign-Up Review</h1>
  <div class="tabs">
    <a href="admin_review.php" class="<?= $filter === '' ? 'on' : '' ?>">All <span class="n"><?= $total ?></span></a>
    <?php foreach ($STATUSES as $s): ?>
      <a href="?status=<?= e($s) ?>" class="<?= $filter === $s ? 'on' : '' ?>"><?= e($STATUS_LABELS[$s]) ?> <span class="n"><?= (int) ($counts[$s] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </div>
  <span class="sp"></span>
  <label style="font-size:12px">as
    <select id="membership_status_id" title="Membership status for promoted people">
      <option value="1" selected>Member</option>
      <option value="2">Regular Attender</option>
      <option value="3">Guest</option>
      <option value="5">Non-Attender</option>
    </select>
  </label>
  <button type="button" id="bulkPromote" class="tbtn">Promote selected &#9656;</button>
</div>
<div class="hint" style="padding:4px 14px;background:#fff;border-bottom:1px solid var(--grid)">
  Click a cell to edit (auto-saves). Tick rows and use <b>Promote selected</b>, or the per-row <b>Promote</b> button, to add them to the member records. To link a guest to an existing member instead, set <b>Member&nbsp;#</b> (or click a suggestion) and promote.
</div>
<noscript>In-place editing needs JavaScript. The grid is read-only right now.</noscript>

<div class="wrapg">
<table>
  <thead><tr>
    <th class="chk"><input type="checkbox" id="checkAll" title="Select all"></th>
    <th class="idc">#</th>
    <th>First</th><th>Last</th><th>City</th><th>Address</th><th>Unit</th>
    <th>Mon</th><th>Year</th><th>Day</th>
    <th>Email</th><th>Phone</th>
    <th>Reason</th><th>Invited by</th>
    <th>Member type</th>
    <th>Status</th><th>Member&nbsp;#</th><th>Possible match</th>
    <th>Facebook</th><th>LinkedIn</th><th>X</th>
    <th>Notes</th><th>Source</th><th>When</th><th>Promote</th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="25" class="hint" style="text-align:center;padding:24px">No entries<?= $filter ? ' with status “' . e($filter) . '”' : '' ?>.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r):
    $rid = (int) $r['id'];
    $m = sg_match_members($membersDb, [
        'first_name' => $r['first_name'], 'last_name' => $r['last_name'],
        'email' => $r['email'], 'phone' => $r['phone'],
        'birth_month' => $r['birth_month'], 'birth_year' => $r['birth_year'],
    ]);
    $suggest = array_slice(array_merge($m['exact'], $m['possible']), 0, 3);
    $isMock = strtoupper(trim((string) $r['reviewer_notes'])) === 'MOCK';
    $promoted = $r['status'] === 'promoted';
    // Rows that should not be promoted: already promoted, rejected, or a known duplicate.
    $noImport = in_array($r['status'], ['promoted', 'rejected', 'duplicate'], true);
  ?>
    <tr data-row="<?= $rid ?>">
      <td class="chk"><?php if (!$noImport): ?><input type="checkbox" class="rowchk" value="<?= $rid ?>"><?php endif; ?></td>
      <td class="idc"><?= $rid ?></td>
      <td><?= cell_text($r, 'first_name', $csrf, 96) ?></td>
      <td><?= cell_text($r, 'last_name', $csrf, 110) ?></td>
      <td><?= cell_text($r, 'city', $csrf, 110) ?></td>
      <td><?= cell_text($r, 'address_line1', $csrf, 150) ?></td>
      <td><?= cell_text($r, 'address_line2', $csrf, 80) ?></td>
      <td>
        <select class="c" data-id="<?= $rid ?>" data-field="birth_month" data-original="<?= (int) $r['birth_month'] ?>">
          <option value="">—</option>
          <?php for ($i = 1; $i <= 12; $i++): ?>
            <option value="<?= $i ?>"<?= (int) $r['birth_month'] === $i ? ' selected' : '' ?>><?= $months[$i] ?></option>
          <?php endfor; ?>
        </select>
      </td>
      <td><?= cell_num($r, 'birth_year', 56, 'yyyy') ?></td>
      <td><?= cell_num($r, 'birth_day', 44, 'dd') ?></td>
      <td><?= cell_text($r, 'email', $csrf, 180) ?></td>
      <td><?= cell_text($r, 'phone', $csrf, 120) ?></td>
      <td><?= cell_text($r, 'reason_for_visit', $csrf, 150) ?></td>
      <td><?= cell_text($r, 'invited_by', $csrf, 130) ?></td>
      <td>
        <select class="c" data-id="<?= $rid ?>" data-field="member_type_name" data-original="<?= e($r['member_type_name'] ?? '') ?>">
          <option value="">—</option>
          <?php foreach (sg_member_type_names() as $mt): ?>
          <option value="<?= e($mt) ?>"<?= (string) $r['member_type_name'] === $mt ? ' selected' : '' ?>><?= e($mt) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td class="st-<?= e($r['status']) ?>">
        <select class="c" data-id="<?= $rid ?>" data-field="status" data-original="<?= e($r['status']) ?>"
                onchange="this.closest('td').className='st-'+this.value">
          <?php foreach ($STATUSES as $s): ?>
            <option value="<?= e($s) ?>"<?= $r['status'] === $s ? ' selected' : '' ?>><?= e($STATUS_LABELS[$s]) ?></option>
          <?php endforeach; ?>
        </select>
      </td>
      <td><?= cell_num($r, 'matched_person_id', 70, '—') ?></td>
      <td>
        <?php if ($suggest): ?>
          <div class="sugg">
            <?php foreach ($suggest as $sm): ?>
              <span class="chip" data-suggest-id="<?= (int) $sm['id'] ?>" title="matches: <?= e(implode(', ', $sm['why'])) ?>">#<?= (int) $sm['id'] ?> <?= e($sm['name']) ?></span>
            <?php endforeach; ?>
          </div>
        <?php else: ?><span class="hint">—</span><?php endif; ?>
      </td>
      <td><?= cell_text($r, 'facebook', $csrf, 120) ?></td>
      <td><?= cell_text($r, 'linkedin', $csrf, 120) ?></td>
      <td><?= cell_text($r, 'twitter', $csrf, 120) ?></td>
      <td><?= $isMock ? '<span class="mock" style="padding:5px 7px;display:inline-block">MOCK</span>' : cell_text($r, 'reviewer_notes', $csrf, 150) ?></td>
      <td class="hint"><?= e($r['source']) ?><?= $r['source_event_id'] ? ' #' . (int) $r['source_event_id'] : '' ?></td>
      <td class="hint"><?= e(substr((string) $r['created_at'], 0, 16)) ?></td>
      <td class="act">
        <?php if ($promoted): ?>
          <span class="done">&#10003; #<?= (int) $r['matched_person_id'] ?></span>
        <?php elseif ($noImport): ?>
          <button type="button" class="mg" data-promote-id="<?= $rid ?>" disabled
                  title="<?= e($STATUS_LABELS[$r['status']] ?? ucfirst($r['status'])) ?> rows can't be promoted. Change status to enable.">Promote &#9656;</button>
        <?php else: ?>
          <button type="button" class="mg" data-promote-id="<?= $rid ?>">Promote &#9656;</button>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<script src="assets/admin.js" defer></script>
</body></html>
