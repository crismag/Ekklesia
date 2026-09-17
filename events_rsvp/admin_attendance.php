<?php
/**
 * Events RSVP — admin attendance / RSVP list (standalone, token-gated).
 * Pick an event, see who RSVP'd, tallies, and mark check-in / no-show / cancel.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
rv_admin_gate();

$db = rv_db();
$members = rv_members_db();
$flash = '';
$ATT = ['registered', 'checked_in', 'no_show', 'cancelled'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'attendance') {
    if (!rv_admin_csrf_ok()) {
        $flash = 'Session expired — please retry.';
    } else {
        $id  = (int) ($_POST['id'] ?? 0);
        $new = (string) ($_POST['attendance'] ?? '');
        if ($id > 0 && in_array($new, $ATT, true)) {
            try {
                $st = $db->prepare("UPDATE visitor_rsvps SET attendance = :s, updated_at = datetime('now') WHERE id = :id");
                $st->execute([':s' => $new, ':id' => $id]);
                $flash = "Updated #$id.";
            } catch (Throwable $ex) {
                error_log('[events_rsvp admin] ' . $ex->getMessage());
                $flash = 'Update failed.';
            }
        }
    }
    $_SESSION['rv_admin_flash'] = $flash;
    header('Location: admin_attendance.php?event_id=' . (int) ($_POST['event_id'] ?? 0));
    exit;
}
if (!empty($_SESSION['rv_admin_flash'])) { $flash = (string) $_SESSION['rv_admin_flash']; unset($_SESSION['rv_admin_flash']); }

// Events that have received RSVPs, newest activity first.
$events = $db->query(
    'SELECT event_id, COUNT(*) c, MAX(created_at) last
       FROM visitor_rsvps GROUP BY event_id ORDER BY last DESC'
)->fetchAll();

$selId = (int) ($_GET['event_id'] ?? ($events[0]['event_id'] ?? 0));

$rows = [];
$tally = ['yes' => 0, 'maybe' => 0, 'no' => 0, 'people' => 0];
$eventInfo = null;
if ($selId > 0) {
    $eventInfo = rv_load_event($members, $selId);
    $st = $db->prepare('SELECT * FROM visitor_rsvps WHERE event_id = :id ORDER BY created_at DESC');
    $st->execute([':id' => $selId]);
    $rows = $st->fetchAll();
    foreach ($rows as $r) {
        $tally[$r['response']] = ($tally[$r['response']] ?? 0) + 1;
        if ($r['response'] !== 'no') { $tally['people'] += max(1, (int) $r['party_size']); }
    }
}

$csrf = rv_csrf();
$badge = ['registered' => '', 'checked_in' => 'b-in', 'no_show' => 'b-no', 'cancelled' => 'b-cx'];
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>RSVP Attendance</title>
<link rel="stylesheet" href="assets/rsvp.css"><?= theme_tokens_style_block() ?>
<style>
  body{background:var(--bg)}
  .wrap{width:min(1080px,calc(100% - 24px));padding-top:16px}
  .brand{color:var(--ink)}
  .picker{margin:0 0 14px}
  .picker select{max-width:100%}
  .stats{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 14px}
  .stat{background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px 14px;min-width:96px}
  .stat b{display:block;font-size:22px}.stat span{color:var(--muted);font-size:12px}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden;font-size:13px}
  th,td{padding:9px 10px;text-align:left;border-bottom:1px solid var(--line);vertical-align:top}
  th{background:#fbfdff;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted)}
  tr:last-child td{border-bottom:0}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;background:#eef2f7;color:#41506a}
  .b-in{background:#e6f7ec;color:#1a7a3a}.b-no{background:#fdecea;color:#7a1c16}.b-cx{background:#eceff3;color:#5a6b7b}
  .r-yes{color:#1a7a3a;font-weight:800}.r-maybe{color:#8a5a00;font-weight:800}.r-no{color:#7a1c16;font-weight:800}
  select{font:inherit;padding:5px 7px;border:1px solid #c5d2de;border-radius:7px;min-height:34px}
  .muted{color:var(--muted)}.scroll{overflow-x:auto}
  @media (max-width:640px){th:nth-child(4),td:nth-child(4){display:none}}
</style></head>
<body><div class="wrap">
  <div class="brand"><h1>RSVP &amp; Attendance</h1></div>

  <?php if ($flash !== ''): ?><div class="alert" style="background:#e8f1fb;border:1px solid #bcd6f5;color:#0f4e97"><?= e($flash) ?></div><?php endif; ?>

  <?php if (!$events): ?>
    <div class="card"><div class="notice"><h2>No RSVPs yet</h2><p>Once guests start responding, events will appear here.</p></div></div>
  <?php else: ?>
    <form class="picker" method="get" onchange="this.submit()">
      <label for="ev" class="muted">Event:</label>
      <select id="ev" name="event_id" onchange="this.form.submit()">
        <?php foreach ($events as $ev):
          $info = rv_load_event($members, (int) $ev['event_id']);
          $label = $info['title'] ?? ('Event #' . (int) $ev['event_id']);
        ?>
          <option value="<?= (int) $ev['event_id'] ?>"
            <?= $selId === (int) $ev['event_id'] ? 'selected' : '' ?>>
            <?= e($label) ?> — <?= (int) $ev['c'] ?> response<?= (int) $ev['c'] === 1 ? '' : 's' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>

    <?php if ($eventInfo): ?>
      <p class="muted" style="margin-top:-6px">
        <?= e($eventInfo['title']) ?>
        <?php if (!empty($eventInfo['date'])): ?> · <?= e(date('D, M j, Y', (int) strtotime($eventInfo['date']))) ?><?php endif; ?>
      </p>
    <?php endif; ?>

    <div class="stats">
      <div class="stat"><b class="r-yes"><?= (int) $tally['yes'] ?></b><span>Yes</span></div>
      <div class="stat"><b class="r-maybe"><?= (int) $tally['maybe'] ?></b><span>Maybe</span></div>
      <div class="stat"><b class="r-no"><?= (int) $tally['no'] ?></b><span>No</span></div>
      <div class="stat"><b><?= (int) $tally['people'] ?></b><span>Expected heads</span></div>
    </div>

    <div class="scroll"><table>
      <thead><tr><th>Name</th><th>RSVP</th><th>Party</th><th>Contact / Notes</th><th>Type</th><th>Attendance</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="muted" style="text-align:center;padding:24px">No responses for this event.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
        <tr>
          <td><b><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></b>
            <?php if ($r['city']): ?><br><span class="muted"><?= e($r['city']) ?></span><?php endif; ?></td>
          <td><span class="r-<?= e($r['response']) ?>"><?= e(ucfirst($r['response'])) ?></span></td>
          <td><?= (int) $r['party_size'] ?></td>
          <td class="muted">
            <?php if ($r['email']): ?><?= e($r['email']) ?><br><?php endif; ?>
            <?php if ($r['phone']): ?><?= e($r['phone']) ?><br><?php endif; ?>
            <?php if ($r['notes']): ?>&ldquo;<?= e($r['notes']) ?>&rdquo;<?php endif; ?>
          </td>
          <td>
            <span class="badge"><?= $r['person_id'] ? 'member' : 'visitor' ?></span>
            <?php if ($r['person_id']): ?><br><span class="muted">member #<?= (int) $r['person_id'] ?></span>
            <?php elseif ($r['visitor_registration_id']): ?><br><span class="muted">signup #<?= (int) $r['visitor_registration_id'] ?></span><?php endif; ?>
          </td>
          <td>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="attendance">
              <input type="hidden" name="id" value="<?= $rid ?>">
              <input type="hidden" name="event_id" value="<?= $selId ?>">
              <select name="attendance" onchange="this.form.submit()">
                <?php foreach ($ATT as $a): ?>
                  <option value="<?= e($a) ?>"<?= $r['attendance'] === $a ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $a)) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div></body></html>
