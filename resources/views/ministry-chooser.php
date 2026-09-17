<?php

declare(strict_types=1);

/**
 * /ministries — ministry discovery and selection (approved "ministry-first"
 * direction, spec §2.3).
 *
 * The schedule/assignment board that previously lived here is unchanged and now
 * served at /schedule-board. Deep links /ministries/{id} and /ministry/{slug}
 * are untouched — they already resolved to the ministry workspace, not the
 * board, so the deep-link surface is unaffected by this split.
 *
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var array<int,array<string,mixed>> $availableMinistries
 * @var array<int,array<string,mixed>> $allMinistries
 * @var array<string,mixed> $campusSelector
 */

require_once __DIR__ . '/_portal-shell.php';

$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');

$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses       = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$campusNames    = [];
foreach ($campuses as $c) {
    if (isset($c['id'])) {
        $campusNames[(int) $c['id']] = (string) ($c['name'] ?? '');
    }
}

$list = [];
foreach (($allMinistries ?? []) as $m) {
    if (!is_array($m) || !isset($m['ministryId'])) {
        continue;
    }
    $list[] = $m;
}
usort($list, static fn (array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

// Ministries the actor can actually act in are surfaced first — "choose the
// ministry first" only helps if your own ministries are not buried.
$mineIds = [];
foreach (($availableMinistries ?? []) as $m) {
    if (isset($m['ministryId'])) {
        $mineIds[(int) $m['ministryId']] = true;
    }
}
$mine   = array_values(array_filter($list, static fn ($m) => isset($mineIds[(int) $m['ministryId']])));
$others = array_values(array_filter($list, static fn ($m) => !isset($mineIds[(int) $m['ministryId']])));

/** @param array<string,mixed> $m */
$renderCard = static function (array $m) use ($base, $campusNames): string {
    $id     = (int) $m['ministryId'];
    $name   = (string) ($m['name'] ?? 'Ministry');
    $campus = isset($m['campusId']) && $m['campusId'] !== null ? ($campusNames[(int) $m['campusId']] ?? '') : '';
    $badges = '';
    if ($campus !== '') {
        $badges .= pc_badge($campus, 'neutral');
    }
    if (!empty($m['canManageSchedules'])) {
        $badges .= pc_badge('Scheduling', 'brand');
    }
    if (!empty($m['canManageEvents'])) {
        $badges .= pc_badge('Events', 'brand');
    }
    $href = $base . '/ministries/' . $id;

    return '<li class="mc-card">'
        . '<a class="mc-link" href="' . $href . '">'
        . '<span class="mc-icon" aria-hidden="true">' . portal_icon((string) ($m['icon'] ?? 'ministry')) . '</span>'
        . '<span class="mc-body">'
        . '<span class="mc-name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>'
        . ($badges !== '' ? '<span class="mc-badges">' . $badges . '</span>' : '')
        . '</span>'
        . '<span class="mc-go" aria-hidden="true">&rsaquo;</span>'
        . '</a></li>';
};

$boardAction = pc_button([
    'label'   => 'Open schedule board',
    'href'    => $base . '/schedule-board',
    'variant' => 'secondary',
    'icon'    => 'calendar',
]);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Ministries · Church Portal</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}

        .mc-search{display:grid;gap:6px;margin:0 0 var(--sp-5,20px);max-width:420px}
        .mc-search label{font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}
        .mc-search input{min-height:48px;font:inherit;font-size:16px;color:var(--ink);background:var(--paper);
            border:1.5px solid var(--line);border-radius:var(--radius,8px);padding:0 12px}
        .mc-search input:focus{border-color:var(--teal);outline:none;box-shadow:0 0 0 3px rgba(17,123,109,.22)}

        .mc-group{margin:0 0 var(--sp-8,32px)}
        .mc-grid{list-style:none;margin:0;padding:0;display:grid;gap:var(--sp-3,12px);
            grid-template-columns:repeat(auto-fill,minmax(260px,1fr))}
        .mc-card{min-width:0}
        .mc-link{display:flex;align-items:center;gap:var(--sp-3,12px);min-height:72px;padding:var(--sp-3,12px) var(--sp-4,16px);
            background:var(--paper);border:1px solid var(--line);border-radius:var(--radius,8px);
            text-decoration:none;color:var(--ink);transition:border-color .18s,background .18s}
        .mc-link:hover{border-color:var(--teal);background:var(--soft)}
        .mc-icon{display:grid;place-items:center;width:44px;height:44px;flex:0 0 auto;border-radius:var(--radius,8px);
            background:var(--soft);color:var(--teal-ink,#117b6d)}
        .mc-icon svg{width:20px;height:20px}
        .mc-body{display:grid;gap:6px;min-width:0;flex:1}
        .mc-name{font-size:15px;font-weight:700;line-height:1.25;overflow-wrap:anywhere}
        .mc-badges{display:flex;flex-wrap:wrap;gap:4px}
        .mc-go{color:var(--muted);font-size:20px;line-height:1;flex:0 0 auto}
        .mc-none{display:none}
        @media(max-width:520px){.mc-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?>>
    <?= portal_header($basePath, '', '', $campuses, null, $actor, [], [], [], 'Sign in', $base . '/login') ?>

    <main id="portal-main" tabindex="-1">
        <?= pc_page_header([
            'kicker'      => 'Ministries',
            'title'       => 'Choose a ministry',
            'description' => 'Open a ministry to see its members, serving grid and posted lists. Creating a team is Administration → Members & leaders.',
            'actions'     => $boardAction,
        ]) ?>

        <div class="mc-search">
            <label for="ministryFilter">Find a ministry</label>
            <input id="ministryFilter" type="search" placeholder="Type a ministry name…" autocomplete="off">
        </div>

        <?php if ($mine !== []): ?>
            <section class="mc-group" aria-labelledby="mineHeading">
                <?= pc_section_header('Your ministries') ?>
                <ul class="mc-grid" id="mineGrid">
                    <?php foreach ($mine as $m) { echo $renderCard($m); } ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($others !== []): ?>
            <section class="mc-group" aria-labelledby="allHeading">
                <?= pc_section_header($mine !== [] ? 'All ministries' : 'Ministries') ?>
                <ul class="mc-grid" id="allGrid">
                    <?php foreach ($others as $m) { echo $renderCard($m); } ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($list === []): ?>
            <?= pc_empty_state([
                'icon'   => 'ministry',
                'title'  => 'No ministries to show',
                'body'   => $actor === null
                    ? 'Sign in to see the ministries you belong to.'
                    : 'No ministries are visible for the selected campus.',
                'action' => $actor === null
                    ? pc_button(['label' => 'Sign in', 'href' => $base . '/login', 'variant' => 'primary'])
                    : '',
            ]) ?>
        <?php endif; ?>

        <div class="mc-none" id="noMatch">
            <?= pc_empty_state(['icon' => 'search', 'title' => 'No ministries match', 'body' => 'Try a different name, or clear the filter.']) ?>
        </div>
    </main>

    <?= portal_footer('Church Portal', 'Ministry directory') ?>
</div>
<script>
(function(){
  var input=document.getElementById('ministryFilter');
  var cards=[].slice.call(document.querySelectorAll('.mc-card'));
  var none=document.getElementById('noMatch');
  if(!input) return;
  input.addEventListener('input',function(){
    var q=input.value.trim().toLowerCase();
    var shown=0;
    cards.forEach(function(c){
      var name=(c.querySelector('.mc-name')||{}).textContent||'';
      var hit=!q||name.toLowerCase().indexOf(q)!==-1;
      c.style.display=hit?'':'none';
      if(hit) shown++;
    });
    // Hide a group heading whose cards are all filtered out.
    document.querySelectorAll('.mc-group').forEach(function(g){
      var any=[].slice.call(g.querySelectorAll('.mc-card')).some(function(c){return c.style.display!=='none';});
      g.style.display=any?'':'none';
    });
    none.style.display=shown?'none':'block';
  });
})();
</script>
</body>
</html>
