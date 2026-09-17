<?php

declare(strict_types=1);

/**
 * /ministries — the ministries directory (Ministries workspace).
 *
 * A portal directory for signed-in people, not church presentation: each
 * ministry's campus, size, leaders (only where this account may already read
 * that ministry's people), whether it schedules people and when it next
 * serves. The viewer's own ministries come first. Everything comes from
 * MinistryService::listDirectory(); this template queries nothing.
 *
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var array<string,mixed> $campusSelector
 * @var ?int $campusId
 * @var list<array<string,mixed>> $directory
 */

require_once __DIR__ . '/_portal-shell.php';

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$base = $e($basePath);
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$campusNames = [];
foreach ($campuses as $c) {
    $campusNames[(int) ($c['id'] ?? 0)] = (string) ($c['name'] ?? '');
}
$isAdmin = !empty($actor['isPortalWideAdmin']);

$mine = array_values(array_filter($directory, static fn (array $m): bool => !empty($m['isMine'])));
$others = array_values(array_filter($directory, static fn (array $m): bool => empty($m['isMine'])));

/** @param array<string,mixed> $m */
$card = static function (array $m) use ($e, $base, $campusNames): string {
    $id = (int) $m['ministryId'];
    $campus = $m['campusId'] === null ? 'All campuses' : ($campusNames[(int) $m['campusId']] ?? 'Campus');
    $badges = '<span class="ek-badge">' . $e($campus) . '</span>';
    if (!empty($m['leadsIt'])) {
        $badges .= '<span class="ek-badge is-ok">You lead</span>';
    } elseif (!empty($m['isMine'])) {
        $badges .= '<span class="ek-badge is-ok">Your ministry</span>';
    }
    if (empty($m['active'])) {
        $badges .= '<span class="ek-badge is-warn">Inactive</span>';
    }

    $count = (int) $m['memberCount'];
    $facts = [$count . ($count === 1 ? ' member' : ' members')];
    $roles = (int) $m['servingRoleCount'];
    $facts[] = $roles > 0 ? $roles . ($roles === 1 ? ' serving role' : ' serving roles') : 'Does not schedule people';

    $leaders = '';
    if (is_array($m['leaders'])) {
        $leaders = '<p class="md-line"><span class="md-k">Leaders</span> '
            . ($m['leaders'] === [] ? '<span class="md-muted">None named yet</span>' : $e(implode(', ', $m['leaders'])))
            . '</p>';
    }

    if ($m['nextDate'] instanceof DateTimeImmutable) {
        $more = (int) $m['upcomingCount'] - 1;
        $next = '<p class="md-line"><span class="md-k">Next</span> '
            . '<time datetime="' . $e($m['nextDate']->format('Y-m-d')) . '">' . $e($m['nextDate']->format('D j M')) . '</time>'
            . ($m['nextTitle'] !== '' ? ' · ' . $e($m['nextTitle']) : '')
            . ($more > 0 ? ' <span class="md-muted">+' . $more . ' more</span>' : '')
            . '</p>';
    } elseif (!empty($m['schedules'])) {
        $next = '<p class="md-line md-muted">Nobody scheduled in the next 60 days</p>';
    } else {
        $next = '';
    }

    return '<li class="md-item" data-name="' . $e(strtolower((string) $m['name'])) . '">'
        . '<a class="md-card" href="' . $base . '/ministries/' . $id . '">'
        . '<span class="md-head"><span class="md-name">' . $e($m['name']) . '</span>'
        . '<span class="md-go" aria-hidden="true">&rsaquo;</span></span>'
        . '<span class="md-badges">' . $badges . '</span>'
        . '<p class="md-line md-facts">' . $e(implode(' · ', $facts)) . '</p>'
        . $leaders . $next
        . '</a></li>';
};

$actions = '';
if ($actor !== null) {
    $actions .= '<a class="ek-btn" href="' . $base . '/schedule-board">' . portal_icon('calendar') . 'Schedule board</a>';
    if ($isAdmin) {
        $actions .= '<a class="ek-btn" href="' . $base . '/admin/ministries">' . portal_icon('settings') . 'Manage ministries</a>';
    }
}

$campusLink = static function (?int $id, string $label) use ($e, $base, $campusId): string {
    $current = $id === $campusId;
    return '<a class="ek-chip" href="' . $base . '/ministries?campus=' . ($id === null ? 'all' : $id) . '"'
        . ($current ? ' aria-current="true"' : '') . '>' . $e($label) . '</a>';
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Ministries · Ekklesia</title>
    <style>
        body{margin:0;min-height:100vh;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
        .md-tools{display:flex;flex-wrap:wrap;gap:var(--sp-3,12px);align-items:center;justify-content:space-between}
        .md-campus{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px);align-items:center}
        .md-campus .ek-chip[aria-current]{background:var(--soft,#eef4f0);border-color:var(--teal,#117b6d);color:var(--teal-ink,#117b6d);font-weight:700}
        .md-search{flex:0 1 280px;min-width:0}
        .md-section{display:grid;gap:var(--sp-3,12px)}
        .md-section h2{margin:0;font-size:15px;font-weight:700;color:var(--ink)}
        .md-section h2 small{font-weight:500;color:var(--muted);font-size:13px}
        .md-list{list-style:none;margin:0;padding:0;display:grid;gap:var(--sp-3,12px);grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr))}
        .md-item{min-width:0}
        .md-card{display:grid;gap:6px;height:100%;box-sizing:border-box;padding:var(--sp-3,12px) var(--sp-4,16px);background:var(--paper,#fff);
            border:1px solid var(--line,#d9e4dd);border-radius:var(--radius-lg,12px);color:var(--ink);text-decoration:none}
        .md-card:hover{border-color:var(--teal,#117b6d)}
        .md-card:focus-visible{outline:2px solid var(--teal,#117b6d);outline-offset:2px}
        .md-head{display:flex;align-items:center;gap:8px;min-width:0}
        .md-name{flex:1;min-width:0;font-size:15px;font-weight:700;line-height:1.3;overflow-wrap:anywhere}
        .md-go{color:var(--muted);font-size:20px;line-height:1}
        .md-badges{display:flex;flex-wrap:wrap;gap:4px}
        .md-line{margin:0;font-size:13px;line-height:1.45;overflow-wrap:anywhere}
        .md-k{display:inline-block;min-width:4.5em;font-size:12px;font-weight:650;color:var(--muted)}
        .md-muted{color:var(--muted)}
        .md-facts{color:var(--ink)}
        [hidden]{display:none!important}
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?>>
    <?= portal_header($basePath, '', '', $campuses, $campusSelector['defaultCampusId'] ?? null, $actor, [], [], [], 'Sign in', $basePath . '/login') ?>

    <main id="portal-main" tabindex="-1" class="ek-page">
        <?= ek_workspace_tabs($basePath, 'ministries', 'ministries', $actor) ?>
        <?= ek_page_header('Ministries', 'Every ministry in the church: who leads it, how many serve, and when it next serves. Open one to see its members, serving roles and schedule.', $actions) ?>

        <?php if ($actor === null): ?>
            <?= pc_signed_out($basePath, 'the church\'s ministries') ?>
        <?php else: ?>
            <div class="md-tools">
                <?php if (count($campuses) > 1): ?>
                <nav class="md-campus" aria-label="Campus">
                    <?= $campusLink(null, 'All campuses') ?>
                    <?php foreach ($campuses as $c): ?>
                        <?= $campusLink((int) $c['id'], (string) $c['name']) ?>
                    <?php endforeach; ?>
                </nav>
                <?php endif; ?>
                <?php if ($directory !== []): ?>
                <div class="ek-field md-search">
                    <label class="sr-only" for="mdFilter">Find a ministry</label>
                    <input class="ek-input" id="mdFilter" type="search" placeholder="Find a ministry…" autocomplete="off">
                </div>
                <?php endif; ?>
            </div>

            <?php if ($campusId !== null): ?>
                <p class="ek-hint" style="margin:0">Showing ministries of <?= $e($campusNames[$campusId] ?? 'this campus') ?> and those serving every campus; member counts are people from <?= $e($campusNames[$campusId] ?? 'this campus') ?>.</p>
            <?php endif; ?>

            <?php if ($directory === []): ?>
                <div class="ek-card"><div class="ek-empty">
                    <strong>No ministries yet</strong>
                    <p><?= $isAdmin ? 'Create the church\'s first ministry, then add its members and serving roles.' : 'An administrator has not added any ministries yet.' ?></p>
                    <?php if ($isAdmin): ?><a class="ek-btn ek-btn-primary" href="<?= $base ?>/admin/ministries">Create a ministry</a><?php endif; ?>
                </div></div>
            <?php else: ?>
                <?php if ($mine !== []): ?>
                <section class="md-section" aria-labelledby="mdMine">
                    <h2 id="mdMine">Your ministries <small>(<?= count($mine) ?>)</small></h2>
                    <ul class="md-list"><?php foreach ($mine as $m) { echo $card($m); } ?></ul>
                </section>
                <?php endif; ?>
                <?php if ($others !== []): ?>
                <section class="md-section" aria-labelledby="mdAll">
                    <h2 id="mdAll"><?= $mine !== [] ? 'Other ministries' : 'All ministries' ?> <small>(<?= count($others) ?>)</small></h2>
                    <ul class="md-list"><?php foreach ($others as $m) { echo $card($m); } ?></ul>
                </section>
                <?php endif; ?>
                <div class="ek-card" id="mdNoMatch" hidden><div class="ek-empty" role="status">
                    <strong>No ministry by that name</strong>
                    <p>Check the spelling, or clear the search to see every ministry.</p>
                </div></div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <?= portal_footer('Ekklesia', 'Ministries') ?>
</div>
<script>
(function () {
  var input = document.getElementById('mdFilter');
  if (!input) return;
  var items = [].slice.call(document.querySelectorAll('.md-item'));
  var none = document.getElementById('mdNoMatch');
  input.addEventListener('input', function () {
    var q = input.value.trim().toLowerCase();
    var shown = 0;
    items.forEach(function (li) {
      var hit = !q || (li.getAttribute('data-name') || '').indexOf(q) !== -1;
      li.hidden = !hit;
      if (hit) shown++;
    });
    [].slice.call(document.querySelectorAll('.md-section')).forEach(function (s) {
      s.hidden = !s.querySelector('.md-item:not([hidden])');
    });
    if (none) none.hidden = shown !== 0;
  });
})();
</script>
</body>
</html>
