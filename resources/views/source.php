<?php
/**
 * Source code: where the people using this installation can get its source.
 *
 * Open to everyone who can reach the portal (behind any whole-site gate the
 * installation uses). The link comes from the operator's configuration; see
 * App\Services\SourceCodeOffer and docs/licensing.md.
 *
 * @var string                          $basePath
 * @var ?array<string,mixed>            $actor
 * @var array<string,mixed>             $campusSelector
 * @var \App\Services\SourceCodeOffer   $offer
 */

declare(strict_types=1);

require_once __DIR__ . '/_portal-shell.php';

use App\Services\SourceCodeOffer;

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$url = $offer->url();
$version = $offer->version();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Source code · Ekklesia</title>
    <style>
        body{margin:0;min-height:100vh;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
        .src-grid{display:grid;gap:var(--sp-4,16px);max-width:72ch}
        .src-prose p{margin:0 0 var(--sp-3,12px);line-height:1.6}
        .src-prose p:last-child{margin-bottom:0}
        .src-link{display:inline-block;font-weight:700;word-break:break-all}
        .src-version{color:var(--muted)}
        .src-note{color:var(--muted)}
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?>>
    <?= portal_header($basePath, '', '', $campuses, $campusSelector['defaultCampusId'] ?? null, $actor, [], [], [], 'Sign in', $basePath . '/login') ?>

    <main id="portal-main" tabindex="-1" class="ek-page">
        <?= ek_page_header('Source code', 'Ekklesia is free software. You can get the source code of the version running here.') ?>

        <div class="src-grid">
            <section class="ek-card" aria-labelledby="src-get">
                <div class="ek-card-head"><h2 id="src-get">Get the source</h2></div>
                <div class="ek-card-body src-prose">
<?php if ($offer->isConfigured()): ?>
                    <p>The source code of the version this installation runs:</p>
                    <p><a class="src-link" href="<?= $e($url) ?>" rel="noopener"><?= $e($url) ?></a><?php if ($version !== null): ?>
                        <span class="src-version">· version <?= $e($version) ?></span><?php endif; ?></p>
<?php else: ?>
                    <p>This installation has not published a link to its own source code. Ekklesia
                        is developed at the upstream project:</p>
                    <p><a class="src-link" href="<?= $e($url) ?>" rel="noopener"><?= $e($url) ?></a></p>
                    <p class="src-note">The upstream project is a reference. If this installation runs a modified
                        version, its operator provides the source of that version; ask the people who run it.</p>
<?php endif; ?>
                </div>
            </section>

            <section class="ek-card" aria-labelledby="src-license">
                <div class="ek-card-head"><h2 id="src-license">License</h2></div>
                <div class="ek-card-body src-prose">
                    <p>Ekklesia is licensed under the
                        <a href="<?= $e(SourceCodeOffer::LICENSE_URL) ?>" rel="noopener"><?= $e(SourceCodeOffer::LICENSE_NAME) ?></a>
                        (<?= $e(SourceCodeOffer::LICENSE_SPDX) ?>). You may use, study, change and share it under that
                        license; parts of it from other projects keep their own licenses.</p>
                    <p>The source code does not include the church's records, personal information, passwords or
                        private settings. Those belong to this installation and are never published here.</p>
                </div>
            </section>
        </div>
    </main>
    <?= portal_footer('', '', $basePath) ?>
</div>
</body>
</html>
