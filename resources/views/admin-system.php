<?php

declare(strict_types=1);

/**
 * Advanced · System information.
 *
 * Everything here used to sit on the control board — the page an administrator
 * opens to do their daily work. Configuration filenames on disk and PHP version
 * numbers are useful exactly twice: when something is broken, and when someone
 * is helping over the phone. They earned a page, not a place on the dashboard.
 *
 * Strictly read-only. Nothing on this page changes anything, which is why it is
 * safe to show in full rather than hiding it behind another confirmation.
 *
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var array<string,mixed> $campusSelector
 */

require_once __DIR__ . '/_admin-shell.php';

$base = $basePath;
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && !empty($actor['isPortalWideAdmin']);

$dashboard = [];
if ($isAdmin) {
    $dashboard = (new \App\Services\AdminDashboardService())->build($basePath);
}

$ago = static function (mixed $ts): string {
    if (!$ts) {
        return '—';
    }
    $seconds = time() - (int) $ts;
    if ($seconds < 3600) {
        return max(1, intdiv($seconds, 60)) . ' min ago';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . ' hr ago';
    }
    return intdiv($seconds, 86400) . ' days ago';
};

ob_start();
?>
<style>
  /* The links into each editing screen are the only interactive things on this
     page. Padded to a 24px target so they satisfy WCAG 2.5.8 as standalone
     controls in a table cell rather than links inside a sentence. */
  .dash-rowhead a{display:inline-block;padding:4px 2px;min-height:24px;line-height:16px}
  .ek-table th.dash-rowhead{background:none;color:var(--ink);font-weight:600;font-size:13px}
</style>

<?php if (!$isAdmin): ?>
    <div class="ek-card"><div class="ek-card-body">System information is limited to portal-wide administrators.</div></div>
<?php else: ?>

    <div class="ek-alert" role="note">For troubleshooting, or when someone is helping you remotely. Nothing here can be changed from this page, and no passwords or keys are shown.</div>

    <section class="ek-card">
        <div class="ek-card-head"><div>
            <h2>Portal environment</h2>
            <p>How this copy of the portal is configured.</p>
        </div></div>
        <div class="ek-table-wrap">
                <table class="ek-table">
                    <caption class="sr-only">Portal environment settings</caption>
                    <thead><tr><th scope="col">Setting</th><th scope="col">Value</th></tr></thead>
                    <tbody>
                    <?php foreach (($dashboard['environment'] ?? []) as $k => $v): ?>
                        <tr><th scope="row" class="dash-rowhead"><?= $e($k) ?></th><td><code class="code"><?= $e($v) ?></code></td></tr>
                    <?php endforeach; ?>
                        <tr><th scope="row" class="dash-rowhead">Active theme</th><td><code class="code"><?= $e($dashboard['settings']['theme'] ?? '—') ?></code></td></tr>
                        <tr><th scope="row" class="dash-rowhead">Portal notices showing now</th><td><code class="code"><?= $e((string) ($dashboard['status']['noticesShowing'] ?? '—')) ?> of <?= $e((string) ($dashboard['status']['noticesTotal'] ?? '—')) ?></code></td></tr>
                    </tbody>
                </table>
        </div>
    </section>

    <section class="ek-card">
        <div class="ek-card-head"><div>
            <h2>Configuration files</h2>
            <p>Settings the portal keeps as files on the server. Each one has an
               ordinary screen for editing it — the file is shown here only so a
               problem can be diagnosed.</p>
        </div></div>
        <div class="ek-table-wrap">
                <table class="ek-table">
                    <caption class="sr-only">Configuration files and the screens that edit them</caption>
                    <thead><tr>
                        <th scope="col">Controls</th>
                        <th scope="col">File</th>
                        <th scope="col">Size</th>
                        <th scope="col">Last changed</th>
                        <th scope="col">State</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach (($dashboard['configFiles'] ?? []) as $c): ?>
                        <tr>
                            <th scope="row" class="dash-rowhead">
                                <?php if ($c['href']): ?><a href="<?= $e($c['href']) ?>"><?= $e($c['label']) ?></a>
                                <?php else: ?><?= $e($c['label']) ?><?php endif; ?>
                            </th>
                            <td><code class="code"><?= $e($c['file']) ?></code></td>
                            <td class="num"><?= $c['exists'] ? $e(number_format((int) $c['bytes'])) . ' B' : '—' ?></td>
                            <td class="num"><?= $e($ago($c['modified'])) ?></td>
                            <td>
                                <?php
                                // Spelled out rather than colour-coded: the state
                                // must survive being printed or read aloud.
                                if (!$c['exists']) {
                                    echo 'Not created yet';
                                } elseif (!$c['writable']) {
                                    echo 'Read-only on disk';
                                } elseif (!$c['href']) {
                                    echo 'No editing screen';
                                } else {
                                    echo 'Editable';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
        </div>
    </section>

<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath,
    'activeId' => 'system',
    'pageTitle' => 'System information',
    'pageSubtitle' => 'Technical details for troubleshooting',
    'sectionTitle' => 'System information',
    'sectionDescription' => 'Technical information used when troubleshooting the portal. Read-only.',
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'isAdmin' => $isAdmin,
], static fn (): string => $content);
