<?php
/**
 * Admin · Maintenance — backups, archives, member import/export.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var list<array{campus_id:int,campus_name:string}> $campuses
 * @var int $campusId
 * @var string $notice
 * @var string $flash
 * @var list<array{relative:string,filename:string,bytes:int,mtime:int}> $archives
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$noticeMap = [
    'ok' => ['ok', $flash !== '' ? $flash : 'Saved to the private archive.'],
    'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.'],
];
$fmtSize = static function (int $n): string {
    if ($n < 1024) {
        return $n . ' B';
    }
    if ($n < 1048576) {
        return round($n / 1024, 1) . ' KB';
    }
    return round($n / 1048576, 1) . ' MB';
};


if (!function_exists('maintenance_describe_archive')) {
    /**
     * Turn an archive filename into something an administrator can read.
     *
     * The list used to print the stored path — "2026/08/mysql.members.08_25.2345.sql"
     * — and leave the reader to work out what was in it. Someone deciding which
     * backup to restore should not have to parse a filename to find out whether
     * it holds member records or visitor sign-ups.
     *
     * The filename is still available, under Technical details.
     *
     * @return array{contents:string,kind:string}
     */
    function maintenance_describe_archive(string $relative): array
    {
        $name = basename($relative);

        if (str_starts_with($name, 'mysql.')) {
            return ['contents' => 'Member database', 'kind' => 'Database backup'];
        }

        if (str_starts_with($name, 'sqlite.')) {
            return ['contents' => 'Visitor sign-ups & RSVPs', 'kind' => 'Database backup'];
        }

        if (str_starts_with($name, 'state.')) {
            $part = explode('.', $name)[1] ?? '';
            $contents = match ($part) {
                'events'    => 'Events',
                'schedules' => 'Schedules',
                'members'   => 'Member records',
                default     => 'Church data',
            };

            return ['contents' => $contents . ' snapshot', 'kind' => 'Snapshot'];
        }

        if (str_ends_with($name, '.xlsx')) {
            return ['contents' => 'Member records', 'kind' => 'Excel export'];
        }

        return ['contents' => $name, 'kind' => 'File'];
    }
}

ob_start();
?>
<style>
  .mt-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .mt-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}
  .mt-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  .mt-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:0 0 16px}
  @media (max-width:880px){.mt-grid{grid-template-columns:1fr}}
  .mt-help{font-size:13px;color:var(--muted,#5c6b63);margin:0 0 12px;line-height:1.45}
  .mt-btn{font:inherit;font-size:13px;font-weight:800;border:0;border-radius:8px;padding:10px 16px;cursor:pointer;background:#0c5a45;color:#fff;text-decoration:none;display:inline-block}
  .mt-btn.sec{background:#fff;color:var(--ink,#1b2a24);border:1px solid var(--line,#c7d4cd)}
  .mt-field{margin:0 0 10px}
  .mt-field label{display:block;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);margin-bottom:4px}
  .mt-field select{width:100%;font:inherit;padding:9px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff}
  .mt-actions{display:flex;flex-wrap:wrap;gap:8px}
  table.mt{width:100%;border-collapse:collapse;font-size:13px;background:#fff}
  table.mt th,table.mt td{padding:8px 10px;text-align:left;border-bottom:1px solid var(--line,#eef2f5)}
  table.mt th{font-size:11px;text-transform:uppercase;color:var(--muted);background:#f8fafb}
  .mt-code{font-family:ui-monospace,monospace;font-size:12px}
  /* Advanced options are available, not prominent: closed by default, and
     styled as a quiet control rather than a call to action. */
  .mt-advanced{margin-top:16px;border:1px solid var(--line,#e3eae6);border-radius:8px;background:var(--soft,#f7faf8)}
  .mt-advanced>summary{cursor:pointer;padding:12px 14px;font-size:13px;font-weight:700;
    color:var(--muted,#5c6b63);list-style:none;min-height:44px;display:flex;align-items:center}
  .mt-advanced>summary::-webkit-details-marker{display:none}
  .mt-advanced>summary::before{content:'\25B8';margin-right:8px;transition:transform .15s}
  .mt-advanced[open]>summary::before{transform:rotate(90deg)}
  .mt-advanced>summary:focus-visible{outline:2px solid var(--deep,#0c5a45);outline-offset:-2px}
  .mt-advanced-body{padding:0 14px 14px}
  .mt-filelist{margin:0;padding-left:18px;line-height:1.7}
  table.mt td.nowrap,table.mt th.nowrap{white-space:nowrap}
  /* A standalone control in a table cell, not a link inside a sentence, so it
     needs a real target rather than the height of its text (WCAG 2.5.8). Every
     row says "Download", so each carries a hidden note naming what it is. */
  .mt-download{display:inline-block;padding:10px 12px;min-height:44px;line-height:24px;
    border-radius:6px;font-weight:700;text-decoration:none;color:var(--deep,#0c5a45)}
  .mt-download:hover,.mt-download:focus-visible{background:var(--soft,#eef4f0);text-decoration:underline}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="mt-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <article class="admin-card"><div class="admin-card-body" style="color:var(--muted)">
    Importing members, exporting records and creating backups are limited to portal administrators.
  </div></article>
<?php else: ?>

<div class="mt-grid">
  <article class="admin-card">
    <div class="admin-card-head"><div>
      <h2>Import members from a spreadsheet</h2>
      <p>Bring member information in from an Excel workbook.</p>
    </div></div>
    <div class="admin-card-body">
      <p class="mt-help">You will be able to check every row and fix problems before anything is
         saved to the member records. Nothing changes until you confirm.</p>
      <a class="mt-btn" href="<?= $base ?>/admin/maintenance/import">Choose a spreadsheet</a>
    </div>
  </article>

  <article class="admin-card">
    <div class="admin-card-head"><div>
      <h2>Download member records</h2>
      <p>Save member information as an Excel workbook.</p>
    </div></div>
    <div class="admin-card-body">
      <form method="post" action="<?= $base ?>/admin/maintenance/export-xlsx">
        <div class="mt-field">
          <label for="exportCampus">Which members</label>
          <select id="exportCampus" name="campus_id">
            <option value="0">Everyone (one sheet per campus)</option>
            <?php foreach ($campuses as $c): ?>
              <option value="<?= (int) $c['campus_id'] ?>"<?= (int) $campusId === (int) $c['campus_id'] ? ' selected' : '' ?>><?= $h($c['campus_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="mt-btn" type="submit">Download Excel workbook</button>
      </form>
    </div>
  </article>
</div>

<article class="admin-card">
  <div class="admin-card-head"><div>
    <h2>Back up church data</h2>
    <p>Create a safe copy of church records, logins, and visitor sign-ups.</p>
  </div></div>
  <div class="admin-card-body">
    <p class="mt-help">Keep a copy before a large import, or before anything else that changes many
       records at once. Backups are stored on the server in a private folder that is not reachable
       from the web.</p>
    <form method="post" action="<?= $base ?>/admin/maintenance/backup" class="mt-actions">
      <input type="hidden" name="kind" value="mysql">
      <button class="mt-btn" type="submit" name="target" value="all">Create backup</button>
    </form>

    <!-- Progressive disclosure rather than removal: the separate database
         backups and the JSON state snapshots are still needed occasionally,
         they simply are not the thing an administrator came here to do. -->
    <details class="mt-advanced">
      <summary>Advanced backup options</summary>
      <div class="mt-advanced-body">
        <p class="mt-help">"Create backup" above copies the member database and the visitor sign-ups
           together, which is what is normally wanted. These back up one at a time.</p>
        <form method="post" action="<?= $base ?>/admin/maintenance/backup" class="mt-actions">
          <input type="hidden" name="kind" value="mysql">
          <button class="mt-btn sec" type="submit" name="target" value="members">Member database only</button>
          <button class="mt-btn sec" type="submit" name="target" value="visitors">Visitor sign-ups only</button>
        </form>
        <p class="mt-help" style="margin-top:12px">Snapshots write the current events, schedules and
           member records as separate data files, useful when comparing what changed between two
           points in time.</p>
        <form method="post" action="<?= $base ?>/admin/maintenance/backup">
          <input type="hidden" name="kind" value="state">
          <button class="mt-btn sec" type="submit">Save event, schedule and member snapshots</button>
        </form>
      </div>
    </details>
  </div>
</article>

<article class="admin-card">
  <div class="admin-card-head"><div>
    <h2>Recent backups &amp; exports</h2>
    <p>What has been saved, most recent first.</p>
  </div></div>
  <div class="admin-card-body" style="padding:0">
    <?php if (!$archives): ?>
      <!-- An empty state that says what to do, not "0 files". -->
      <div style="padding:18px">
        <p class="mt-help" style="margin-bottom:12px"><strong>No backups have been made yet.</strong><br>
          Once you create one it will be listed here, with the date and what it contains.</p>
      </div>
    <?php else: ?>
      <div class="admin-tablewrap">
      <table class="mt">
        <caption class="sr-only">Backups and exports saved on the server</caption>
        <thead><tr>
          <th scope="col">Saved</th>
          <th scope="col">Contents</th>
          <th scope="col">Kind</th>
          <th scope="col">Size</th>
          <th scope="col"><span class="sr-only">Download</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($archives as $f): ?>
          <?php $d = maintenance_describe_archive((string) $f['relative']); ?>
          <tr>
            <td class="nowrap"><?= $h(date('j M Y, H:i', (int) $f['mtime'])) ?></td>
            <th scope="row" style="font-weight:600"><?= $h($d['contents']) ?></th>
            <td><?= $h($d['kind']) ?></td>
            <td class="nowrap"><?= $h($fmtSize((int) $f['bytes'])) ?></td>
            <td class="nowrap"><a class="mt-download"
                href="<?= $base ?>/admin/maintenance/file?path=<?= urlencode((string) $f['relative']) ?>"
                ><?= $h('Download') ?><span class="sr-only"> <?= $h($d['contents']) ?> saved <?= $h(date('j M Y', (int) $f['mtime'])) ?></span></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <details class="mt-advanced" style="margin:0;border-top:1px solid var(--line,#eef2f5);border-radius:0">
        <summary>Technical details</summary>
        <div class="mt-advanced-body">
          <p class="mt-help">Stored under <span class="mt-code">storage/private/YYYY/mm/</span> on the
             server, outside the web root. Filenames as written:</p>
          <ul class="mt-filelist">
            <?php foreach ($archives as $f): ?>
              <li class="mt-code"><?= $h($f['relative']) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </details>
    <?php endif; ?>
  </div>
</article>

<?php endif; ?>
<?php
$content = ob_get_clean();
echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'maintenance',
    'pageTitle' => 'Maintenance · Admin',
    'pageSubtitle' => 'Import, export and backups',
    'sectionTitle' => 'Maintenance',
    'sectionDescription' => 'Import and export member records, and keep safe copies of church data.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
