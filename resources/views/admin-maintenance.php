<?php
/**
 * Admin · Backups & maintenance — safe copies of church data, and the saved
 * files to download.
 *
 * Importing and exporting member records moved to People & Records › Import &
 * export; this page links there. The export route (POST
 * /admin/maintenance/export-xlsx) is unchanged, and exported workbooks still
 * appear in the saved files list below.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var string $notice
 * @var string $flash
 * @var list<array{relative:string,filename:string,bytes:int,mtime:int}> $archives
 */
require_once __DIR__ . '/_admin-shell.php';
require_once __DIR__ . '/_admin-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = admin_e($basePath);
$h = static fn ($v): string => admin_e($v);
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

$newest = 0;
foreach ($archives as $f) {
    if (str_starts_with(basename((string) $f['relative']), 'mysql.')) {
        $newest = max($newest, (int) $f['mtime']);
    }
}

ob_start();
?>
<style>
  .mt-layout{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:var(--sp-4,16px);align-items:start}
  @media (max-width:1000px){.mt-layout{grid-template-columns:minmax(0,1fr)}}
  .mt-help{margin:0 0 var(--sp-3,12px);color:var(--muted);font-size:13px;line-height:1.5}
  .mt-more{margin-top:var(--sp-4,16px);border:1px solid var(--line);border-radius:var(--radius,8px);background:var(--soft)}
  .mt-more>summary{cursor:pointer;padding:10px 14px;min-height:44px;display:flex;align-items:center;font-size:13px;font-weight:650;color:var(--muted)}
  .mt-more>summary:focus-visible{outline:2px solid var(--teal);outline-offset:-2px}
  .mt-more-body{padding:0 14px 14px}
  .mt-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
  .mt-files{margin:0;padding-left:18px;line-height:1.7;overflow-wrap:anywhere}
  .mt-nowrap{white-space:nowrap}
  .mt-download{display:inline-flex;align-items:center;min-height:44px;padding:0 8px;font-weight:650}
</style>

<?= admin_notice($notice, [
    'ok' => ['ok', $flash !== '' ? $flash : 'Saved to the private archive.'],
    'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.'],
]) ?>

<?php if (!$isAdmin): ?>
  <div class="ek-card"><div class="ek-card-body">Backups are limited to portal-wide administrators.</div></div>
<?php else: ?>

<div class="mt-layout">
  <section class="ek-card" aria-labelledby="mtBackupHeading">
    <div class="ek-card-head">
      <div>
        <h2 id="mtBackupHeading">Back up church data</h2>
        <p>A copy of the member database (records, schedules and logins) and the visitor sign-ups.</p>
      </div>
      <span class="ek-badge<?= $newest === 0 ? ' is-warn' : '' ?>"><?= $newest === 0 ? 'No backup yet' : $h('Last: ' . admin_ago($newest)) ?></span>
    </div>
    <div class="ek-card-body">
      <p class="mt-help">Take one before a large import or merge, or anything else that changes many records at once.
        Backups are stored on the server in a private folder that is not reachable from the web. Nothing takes them automatically.</p>
      <form method="post" action="<?= $base ?>/admin/maintenance/backup">
        <input type="hidden" name="kind" value="mysql">
        <button class="ek-btn ek-btn-primary" type="submit" name="target" value="all">Back up now</button>
      </form>

      <details class="mt-more">
        <summary>Other backup options</summary>
        <div class="mt-more-body">
          <p class="mt-help">"Back up now" copies both databases together, which is what is normally wanted. These copy one at a time.</p>
          <form method="post" action="<?= $base ?>/admin/maintenance/backup" class="ek-toolbar">
            <input type="hidden" name="kind" value="mysql">
            <button class="ek-btn" type="submit" name="target" value="members">Member database only</button>
            <button class="ek-btn" type="submit" name="target" value="visitors">Visitor sign-ups only</button>
          </form>
          <p class="mt-help" style="margin-top:12px">Snapshots save the current events, schedules and member records as separate data files,
            useful for comparing what changed between two points in time. They are not a replacement for a backup.</p>
          <form method="post" action="<?= $base ?>/admin/maintenance/backup">
            <input type="hidden" name="kind" value="state">
            <button class="ek-btn" type="submit">Save snapshots</button>
          </form>
        </div>
      </details>
    </div>
  </section>

  <section class="ek-card" aria-labelledby="mtMovedHeading">
    <div class="ek-card-head"><div>
      <h2 id="mtMovedHeading">Importing or exporting members?</h2>
      <p>Spreadsheet import and the Excel export are in People &amp; Records, with the records they change.</p>
    </div></div>
    <div class="ek-card-body">
      <a class="ek-btn" href="<?= $base ?>/admin/maintenance/import">Go to Import &amp; export</a>
    </div>
  </section>
</div>

<section class="ek-card" aria-labelledby="mtFilesHeading">
  <div class="ek-card-head"><div>
    <h2 id="mtFilesHeading">Saved backups and exports</h2>
    <p>Most recent first.</p>
  </div></div>
  <?php if (!$archives): ?>
    <div class="ek-empty">
      <strong>No backups have been made yet</strong>
      <p>Once you back up, each copy is listed here with the date and what it contains, ready to download.</p>
    </div>
  <?php else: ?>
    <div class="ek-table-wrap">
      <table class="ek-table">
        <caption class="sr-only">Backups and exports saved on the server</caption>
        <thead><tr>
          <th scope="col">Saved</th>
          <th scope="col">Contents</th>
          <th scope="col">Kind</th>
          <th scope="col" class="is-num">Size</th>
          <th scope="col"><span class="sr-only">Download</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($archives as $f): ?>
          <?php $d = maintenance_describe_archive((string) $f['relative']); ?>
          <tr>
            <td class="mt-nowrap"><?= $h(date('j M Y, H:i', (int) $f['mtime'])) ?></td>
            <th scope="row" style="font-weight:600;background:none;color:inherit;font-size:13px"><?= $h($d['contents']) ?></th>
            <td><?= $h($d['kind']) ?></td>
            <td class="is-num mt-nowrap"><?= $h($fmtSize((int) $f['bytes'])) ?></td>
            <td class="mt-nowrap"><a class="mt-download"
                href="<?= $base ?>/admin/maintenance/file?path=<?= urlencode((string) $f['relative']) ?>"
                >Download<span class="sr-only"> <?= $h($d['contents']) ?> saved <?= $h(date('j M Y', (int) $f['mtime'])) ?></span></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="ek-card-body">
      <details class="mt-more" style="margin:0">
        <summary>Technical details</summary>
        <div class="mt-more-body">
          <p class="mt-help">Stored under <span class="mt-code">storage/private/YYYY/mm/</span> on the server, outside the web root. Filenames as written:</p>
          <ul class="mt-files">
            <?php foreach ($archives as $f): ?>
              <li class="mt-code"><?= $h($f['relative']) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </details>
    </div>
  <?php endif; ?>
</section>

<?php endif; ?>
<?php
$content = ob_get_clean();
echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'maintenance',
    'pageTitle' => 'Backups & maintenance',
    'pageSubtitle' => 'Safe copies of church data',
    'sectionTitle' => 'Backups & maintenance',
    'sectionDescription' => 'Keep safe copies of church data, and download the copies already saved.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
