<?php

declare(strict_types=1);

/**
 * Shared shell for printable pages. Portal-themed on screen; a clean,
 * chrome-free layout under @media print so any page can be saved to PDF via the
 * browser's "Print → Save as PDF". Self-contained tokens (with fallbacks) so
 * pages always render even if the theme service is unavailable.
 */

require_once __DIR__ . '/../resources/views/_portal-shell.php';

if (!function_exists('printable_nav')) {
    /** @return list<array{href:string,label:string,icon:string}> */
    function printable_nav(string $base): array
    {
        return [
            ['href' => $base . '/',           'label' => 'Dashboard',  'icon' => 'dashboard'],
            ['href' => $base . '/ministries', 'label' => 'Ministries', 'icon' => 'ministry'],
            ['href' => $base . '/calendar',   'label' => 'Calendar',   'icon' => 'calendar'],
            ['href' => $base . '/events',     'label' => 'Events',     'icon' => 'events'],
            ['href' => $base . '/printables', 'label' => 'Printables', 'icon' => 'search'],
        ];
    }
}

if (!function_exists('printable_page')) {
    /**
     * @param array{
     *   basePath:string, actor:?array<string,mixed>, campusSelector:array<string,mixed>,
     *   title:string, subtitle?:string, printTitle?:string
     * } $a
     * @param callable():string $body inner HTML (toolbar + table etc.)
     */
    function printable_page(array $a, callable $body): string
    {
        $base = htmlspecialchars($a['basePath'], ENT_QUOTES, 'UTF-8');
        $campusSelector = is_array($a['campusSelector'] ?? null) ? $a['campusSelector'] : [];
        $campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
        $defaultCampusId = $campusSelector['defaultCampusId'] ?? null;
        $title = htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars((string) ($a['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
        $printTitle = htmlspecialchars((string) ($a['printTitle'] ?? $a['title']), ENT_QUOTES, 'UTF-8');

        ob_start();
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $title ?> · Printables · Church Portal</title>
    <?= function_exists('portal_theme_style_block') ? portal_theme_style_block() : '' ?>
    <style>
        :root{--ink:var(--ink,#17211b);--muted:var(--muted,#627169);--line:var(--line,#d9e4dd);--paper:#fff;--deep:var(--deep,#123b31);--teal:var(--teal,#117b6d);--soft:var(--soft,#eef4f0);--bg:var(--bg,#f7faf8);--gradient-top:var(--gradient-top,#0c2f28);--gradient-mid:var(--deep,#123b31)}
        *{box-sizing:border-box}
        body{margin:0;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:linear-gradient(180deg,var(--gradient-top) 0,var(--gradient-mid) 220px,var(--bg) 221px)}
        a{color:inherit}
        .shell{width:100%;max-width:none;margin:0;padding:0 0 40px}
        .pr-titleblock{margin:0 0 16px;color:#f8fffb}
        .pr-titleblock h1{margin:0;font-size:clamp(22px,3vw,30px);line-height:1.05}
        .pr-titleblock .sub{color:rgba(248,255,251,.8);font-size:13px;margin-top:4px}
        .pr-card{background:var(--paper);border:1px solid var(--line);border-radius:10px;box-shadow:0 16px 42px rgba(27,50,40,.08);overflow:hidden}
        .pr-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:end;padding:14px 16px;border-bottom:1px solid var(--line);background:#fbfdfc}
        .pr-toolbar .spacer{flex:1}
        .pr-field{display:grid;gap:4px}
        .pr-field label{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        .pr-field input,.pr-field select{border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:inherit;background:var(--paper);color:var(--ink)}
        .button{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:8px 14px;border-radius:8px;background:var(--teal);color:#fff;border:0;font:inherit;font-weight:800;cursor:pointer;text-decoration:none}
        .button.secondary{background:var(--paper);color:var(--deep);border:1px solid var(--line)}
        .pr-tbl{width:100%;border-collapse:collapse}
        .pr-tbl th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding:9px 12px;border-bottom:2px solid var(--line);white-space:nowrap;cursor:pointer;user-select:none}
        .pr-tbl th .arrow{opacity:.5;font-size:10px}
        .pr-tbl td{padding:9px 12px;border-bottom:1px solid var(--line);font-size:13px}
        .pr-tbl tr:last-child td{border-bottom:0}
        .pr-tbl tbody tr:hover{background:var(--soft)}
        .pr-count{color:var(--muted);font-size:12px;padding:10px 16px;border-top:1px solid var(--line)}
        .pr-empty{padding:28px;text-align:center;color:var(--muted)}
        .pr-empty a{display:inline-flex;align-items:center;justify-content:center;min-height:24px;padding:4px 10px;margin-top:6px;border:1px solid var(--line);border-radius:6px;color:var(--teal-ink,var(--teal));text-decoration:none;font-weight:700}
        .pr-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
        .pr-tile{display:flex;flex-direction:column;gap:6px;padding:16px;border:1px solid var(--line);border-radius:10px;background:var(--paper);text-decoration:none;color:var(--ink);box-shadow:0 12px 30px rgba(27,50,40,.06);transition:border-color .12s,box-shadow .12s}
        .pr-tile:hover{border-color:var(--teal);box-shadow:0 10px 26px rgba(17,123,109,.14)}
        .pr-tile h3{margin:0;font-size:15px}
        .pr-tile p{margin:0;color:var(--muted);font-size:12px}
        .pr-tile.soon{opacity:.6;pointer-events:none}
        .pr-tile .cta{margin-top:4px;color:var(--teal);font-weight:800;font-size:12px}
        /* roster / assignees layout (grouped by ministry) */
        .pr-checks{border:1px solid var(--line);border-radius:8px;padding:6px 8px;max-height:120px;overflow:auto;background:var(--paper);min-width:220px}
        .pr-checks label{display:flex;align-items:center;gap:7px;font-size:13px;padding:3px 2px;cursor:pointer}
        .pr-checks-tools{display:flex;gap:10px;margin-bottom:4px}
        .pr-checks-tools button{font:inherit;font-size:11px;color:var(--teal-ink,var(--teal));cursor:pointer;font-weight:800;background:none;border:0;padding:4px 6px;min-height:24px;min-width:24px}
        .pr-roster{padding:6px 0}
        .pr-min{padding:14px 18px;border-bottom:1px solid var(--line)}
        .pr-min:last-child{border-bottom:0}
        .pr-min-title{margin:0 0 10px;font-size:16px;color:var(--deep);display:flex;align-items:baseline;gap:8px;border-bottom:2px solid var(--teal);padding-bottom:5px}
        .pr-min-title .count{font-size:12px;color:var(--muted);font-weight:600}
        .pr-occ{margin:0 0 12px;padding-left:2px;break-inside:avoid}
        .pr-occ:last-child{margin-bottom:0}
        .pr-occ-head{font-weight:800;font-size:13px;margin-bottom:4px}
        .pr-occ-head .when{color:var(--muted);font-weight:600;font-size:12px}
        .pr-assign{font-size:13px;padding:2px 0 2px 12px;display:flex;gap:6px}
        .pr-assign .pr-role{color:var(--teal);font-weight:800;min-width:120px;flex-shrink:0}
        .pr-none{color:var(--muted);font-size:12px;padding-left:12px}
        .print-only{display:none}
        @media print{
            @page{margin:14mm}
            body{background:#fff;color:#000}
            .shell{width:100%;padding:0}
            .topbar,.portal-header,.portal-footer,.pr-titleblock,.pr-toolbar,.pr-count{display:none!important}
            .pr-card{border:0;box-shadow:none;border-radius:0}
            .print-only{display:block;margin-bottom:12px}
            .print-only h2{margin:0;font-size:18px}
            .print-only .meta{color:#333;font-size:12px;margin-top:2px}
            .pr-tbl th{color:#000;border-bottom:1px solid #000}
            .pr-tbl td{border-bottom:1px solid #bbb}
            .pr-tbl tbody tr:hover{background:none}
            .pr-tbl tr{break-inside:avoid}
        }
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>">
    <?= portal_header(
        $a['basePath'], '', '',
        $campuses,
        $defaultCampusId !== null ? (int) $defaultCampusId : null,
        $a['actor'] ?? null,
        printable_nav($a['basePath']),
        [], [],
        'Sign in',
        $a['basePath'] . '/login',
    ) ?>
<main id="portal-main" tabindex="-1">

    <div class="pr-titleblock">
        <h1><?= $title ?></h1>
        <?php if ($subtitle !== ''): ?><div class="sub"><?= $subtitle ?></div><?php endif; ?>
    </div>
    <div class="print-only"><h2><?= $printTitle ?></h2><div class="meta" id="printMeta"></div></div>
    <?= $body() ?>
    </main>
<?= portal_footer() ?>
</div>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }
}
