<?php
/**
 * User documentation hub.
 *
 * Variables expected from the route:
 *   - $basePath:       string
 *   - $actor:          ?array
 *   - $campusSelector: array
 *   - $section:        ?string  Optional slug. Null/empty -> index page.
 */

require_once __DIR__ . '/_portal-shell.php';

$base = htmlspecialchars((string) ($basePath ?? ''), ENT_QUOTES, 'UTF-8');
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$defaultCampusId = $campusSelector['defaultCampusId'] ?? null;

/**
 * Section catalog. The order here drives the sidebar order.
 * Slugs are the URL-facing identifier; files live in docs/sections/.
 */
$DOCS_SECTIONS = [
    ['slug' => 'navigation',        'file' => '03-site-navigation.md',   'title' => 'Site & Navigation',             'group' => 'Getting Started'],
    ['slug' => 'ministries',        'file' => '11-ministries.md',        'title' => 'Ministries — Create & Manage',  'group' => 'People & Ministries'],
    ['slug' => 'people',            'file' => '05-people-management.md',  'title' => 'Member records — Add, Edit & Manage', 'group' => 'People & Ministries'],
    ['slug' => 'adding-leaders',    'file' => '07-adding-leaders.md',    'title' => 'Roles, Leaders & Members',      'group' => 'People & Ministries'],
    ['slug' => 'calendar',          'file' => '14-calendar.md',          'title' => 'Calendar, views and print',     'group' => 'Schedules & Events'],
    ['slug' => 'viewing-schedules', 'file' => '01-viewing-schedules.md', 'title' => 'Viewing & Updating Schedules',  'group' => 'Schedules & Events'],
    ['slug' => 'schedule-editor',   'file' => '02-schedule-editor.md',   'title' => 'Filling the serving grid',      'group' => 'Schedules & Events'],
    ['slug' => 'creating-events',   'file' => '04-creating-events.md',   'title' => 'Creating & Managing Events',    'group' => 'Schedules & Events'],
    ['slug' => 'printing',          'file' => '15-printing.md',          'title' => 'Printing & Publications',       'group' => 'Schedules & Events'],
    ['slug' => 'permissions',       'file' => '06-permissions.md',       'title' => 'Permissions, Login & Accounts', 'group' => 'Access'],
    ['slug' => 'families',          'file' => '13-families.md',          'title' => 'Families & Households',         'group' => 'People & Ministries'],
    ['slug' => 'maintenance',       'file' => '12-maintenance.md',       'title' => 'Import, Export & Backups',      'group' => 'Access'],
    ['slug' => 'help',              'file' => '08-help-support.md',      'title' => 'Help & Support',                'group' => 'Reference'],
    ['slug' => 'faq',               'file' => '09-faq.md',               'title' => 'FAQ',                           'group' => 'Reference'],
    ['slug' => 'about',             'file' => '10-about.md',             'title' => 'About',                         'group' => 'Reference'],
];

// Backwards-compatible alias map for the previous /docs/{member|leader|shortcuts} URLs.
$LEGACY_ALIASES = [
    'member'    => 'viewing-schedules',
    'leader'    => 'schedule-editor',
    'shortcuts' => 'navigation',
];

$rawSection = isset($section) ? (string) $section : '';
$rawSection = strtolower(trim($rawSection));
if ($rawSection !== '' && isset($LEGACY_ALIASES[$rawSection])) {
    $rawSection = $LEGACY_ALIASES[$rawSection];
}

$activeSection = null;
if ($rawSection !== '') {
    foreach ($DOCS_SECTIONS as $s) {
        if ($s['slug'] === $rawSection) { $activeSection = $s; break; }
    }
}

/**
 * Minimal Markdown -> HTML renderer.
 * Supports the subset we use in section files:
 *   - headings (# ## ###), paragraphs
 *   - unordered lists (-) and ordered lists (1.)
 *   - bold **x**, italic *x*, inline code `x`
 *   - blockquotes with "> **Tip:**", "> **Warning:**", "> **Best Practice:**" callouts
 *   - GFM-style tables
 *   - "[Insert Screenshot: ...]" -> placeholder box
 */
function docs_render_markdown(string $md): string
{
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $lines = explode("\n", $md);
    $out = [];
    $i = 0;
    $n = count($lines);

    $inline = static function (string $s): string {
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        // inline code
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
        // bold
        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
        // italic (avoid matching ** boundaries — bold is already replaced)
        $s = preg_replace('/(?<![\*\w])\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s);
        // screenshot placeholder
        $s = preg_replace_callback('/\[Insert Screenshot:\s*([^\]]+)\]/', function ($m) {
            return '<span class="ss-placeholder"><span class="ss-icon">&#128247;</span> Screenshot: ' . $m[1] . '</span>';
        }, $s);
        return $s;
    };

    while ($i < $n) {
        $line = $lines[$i];
        $trim = trim($line);

        // blank line
        if ($trim === '') { $i++; continue; }

        // headings
        if (preg_match('/^(#{1,3})\s+(.*)$/', $trim, $m)) {
            $level = strlen($m[1]);
            $out[] = '<h' . $level . '>' . $inline($m[2]) . '</h' . $level . '>';
            $i++;
            continue;
        }

        // GFM table: header line containing | and next line is separator |---|
        if (str_contains($trim, '|') && $i + 1 < $n && preg_match('/^\s*\|?[\s\-:]+\|[\s\-:|]+$/', $lines[$i + 1])) {
            $headerCells = array_map('trim', array_filter(explode('|', trim($trim, " |\t")), static fn($c) => $c !== ''));
            $i += 2; // skip header + separator
            $rows = [];
            while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                $rowLine = trim($lines[$i], " |\t");
                $cells = array_map('trim', explode('|', $rowLine));
                $rows[] = $cells;
                $i++;
            }
            $html = '<div class="tbl-wrap"><table class="md-tbl"><thead><tr>';
            foreach ($headerCells as $h) { $html .= '<th>' . $inline($h) . '</th>'; }
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $c) { $html .= '<td>' . $inline($c) . '</td>'; }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
            $out[] = $html;
            continue;
        }

        // blockquote / callout
        if (str_starts_with($trim, '>')) {
            $bqLines = [];
            while ($i < $n && str_starts_with(trim($lines[$i]), '>')) {
                $bqLines[] = ltrim(ltrim(trim($lines[$i]), '>'));
                $i++;
            }
            $bqText = trim(implode(' ', $bqLines));
            $kind = 'note';
            if (preg_match('/^\*\*Tip:\*\*\s*/i', $bqText)) {
                $kind = 'tip'; $bqText = preg_replace('/^\*\*Tip:\*\*\s*/i', '', $bqText);
            } elseif (preg_match('/^\*\*Warning:\*\*\s*/i', $bqText)) {
                $kind = 'warning'; $bqText = preg_replace('/^\*\*Warning:\*\*\s*/i', '', $bqText);
            } elseif (preg_match('/^\*\*Best Practice:\*\*\s*/i', $bqText)) {
                $kind = 'best'; $bqText = preg_replace('/^\*\*Best Practice:\*\*\s*/i', '', $bqText);
            }
            $label = ['tip' => 'Tip', 'warning' => 'Warning', 'best' => 'Best Practice', 'note' => 'Note'][$kind];
            $out[] = '<aside class="callout callout-' . $kind . '"><span class="callout-label">' . $label . '</span><div class="callout-body">' . $inline($bqText) . '</div></aside>';
            continue;
        }

        // unordered list
        if (preg_match('/^[-\*]\s+/', $trim)) {
            $items = [];
            while ($i < $n && preg_match('/^[-\*]\s+(.*)$/', trim($lines[$i]), $m)) {
                $items[] = $m[1];
                $i++;
            }
            $html = '<ul>';
            foreach ($items as $it) { $html .= '<li>' . $inline($it) . '</li>'; }
            $html .= '</ul>';
            $out[] = $html;
            continue;
        }

        // ordered list
        if (preg_match('/^\d+\.\s+/', $trim)) {
            $items = [];
            while ($i < $n && preg_match('/^\d+\.\s+(.*)$/', trim($lines[$i]), $m)) {
                $items[] = $m[1];
                $i++;
            }
            $html = '<ol>';
            foreach ($items as $it) { $html .= '<li>' . $inline($it) . '</li>'; }
            $html .= '</ol>';
            $out[] = $html;
            continue;
        }

        // paragraph (collapse consecutive non-blank lines)
        $paraLines = [$line];
        $i++;
        while ($i < $n) {
            $next = $lines[$i];
            $ntrim = trim($next);
            if ($ntrim === '') break;
            if (preg_match('/^(#{1,3})\s+/', $ntrim)) break;
            if (preg_match('/^[-\*]\s+/', $ntrim)) break;
            if (preg_match('/^\d+\.\s+/', $ntrim)) break;
            if (str_starts_with($ntrim, '>')) break;
            if (str_contains($ntrim, '|') && $i + 1 < $n && preg_match('/^\s*\|?[\s\-:]+\|[\s\-:|]+$/', $lines[$i + 1])) break;
            $paraLines[] = $next;
            $i++;
        }
        $out[] = '<p>' . $inline(trim(implode(' ', $paraLines))) . '</p>';
    }

    return implode("\n", $out);
}

// Group sections for the sidebar
$grouped = [];
foreach ($DOCS_SECTIONS as $s) {
    $grouped[$s['group']][] = $s;
}

$pageTitle = $activeSection ? $activeSection['title'] . ' — Docs' : 'User Guide — Docs';
$pageSub   = $activeSection ? 'Ekklesia documentation' : 'Detailed tutorials and reference for ministry leaders';

$contentHtml = '';
if ($activeSection) {
    $path = __DIR__ . '/docs/sections/' . $activeSection['file'];
    if (is_readable($path)) {
        $contentHtml = docs_render_markdown((string) file_get_contents($path));
    } else {
        $contentHtml = '<p><em>This section is not available yet.</em></p>';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root{
            --tip:#0d7c52; --warn:#a64a13; --best:#1f5fa6;
        }
        *{box-sizing:border-box}
        body{margin:0;font:14.5px/1.65 Inter,system-ui,Segoe UI,Arial;color:var(--ink);background:#fbfdfc}
        .docs-grid{display:grid;grid-template-columns:260px 1fr;gap:24px;margin-top:14px}
        @media (max-width:880px){.docs-grid{grid-template-columns:1fr}.docs-side{position:static;max-height:none}}
        .docs-side{position:sticky;top:14px;align-self:start;max-height:calc(100vh - 30px);overflow:auto;background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px}
        .docs-side h3{margin:6px 0 4px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
        .docs-side ul{list-style:none;padding:0;margin:0 0 12px}
        .docs-side li{margin:2px 0}
        .docs-side a{display:block;padding:7px 9px;border-radius:7px;color:var(--ink);text-decoration:none;font-weight:500}
        .docs-side a:hover{background:var(--soft)}
        .docs-side a.active{background:#0f2f28;color:#fff}
        /* The docs *page* fills the workspace (.docs-grid). The article stays
           a readable column so prose is not stretched to 2560px. */
        .docs-main{background:#fff;border:1px solid var(--line);border-radius:10px;padding:22px 26px;min-width:0;max-width:52rem}
        .docs-main h1{margin:0 0 4px;font-size:26px;line-height:1.25}
        .docs-main h2{margin:22px 0 8px;font-size:19px;border-bottom:1px solid var(--line);padding-bottom:4px}
        .docs-main h3{margin:18px 0 4px;font-size:15.5px;color:#0f2f28}
        .docs-main p{margin:8px 0;color:#1f2c25}
        .docs-main ul,.docs-main ol{margin:8px 0 8px 22px;padding:0}
        .docs-main li{margin:3px 0}
        .docs-main code{background:#f1f6f3;border:1px solid var(--line);padding:1px 5px;border-radius:4px;font:12.5px/1.5 ui-monospace,Consolas,Menlo,monospace}
        .docs-main strong{color:#0f2f28}
        .docs-main .tbl-wrap{overflow-x:auto;margin:10px 0}
        .docs-main table.md-tbl{border-collapse:collapse;width:100%;font-size:13.5px}
        .docs-main table.md-tbl th,.docs-main table.md-tbl td{border:1px solid var(--line);padding:7px 10px;text-align:left;vertical-align:top}
        .docs-main table.md-tbl thead th{background:var(--soft);font-weight:600}
        .callout{margin:12px 0;padding:10px 14px;border-left:4px solid var(--line);border-radius:6px;background:#f7fbf9}
        .callout-label{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;margin-right:8px;padding:1px 7px;border-radius:99px;color:#fff}
        .callout-tip{border-left-color:var(--tip);background:#effaf3}
        .callout-tip .callout-label{background:var(--tip)}
        .callout-warning{border-left-color:var(--warn);background:#fdf3ec}
        .callout-warning .callout-label{background:var(--warn)}
        .callout-best{border-left-color:var(--best);background:#eef4fc}
        .callout-best .callout-label{background:var(--best)}
        .callout-note{background:#f7fbf9}
        .callout-note .callout-label{background:#5d6c64}
        .callout-body{display:inline}
        .ss-placeholder{display:inline-block;border:1.5px dashed #b8c8bf;background:#f5f9f7;color:var(--muted);padding:3px 8px;border-radius:6px;font-size:12.5px;margin:0 2px}
        .ss-icon{margin-right:4px}
        .docs-hero{background:linear-gradient(135deg,#0f2f28,#1a4a3e);color:#fff;border-radius:10px;padding:18px 22px;margin-bottom:14px}
        .docs-hero h1{margin:0 0 4px;font-size:22px}
        .docs-hero p{margin:0;color:#dffbed;opacity:.85}
        .crumbs{font-size:12px;color:var(--muted);margin-bottom:6px}
        .crumbs a{color:var(--muted);display:inline-flex;align-items:center;min-height:24px}
        .index-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin-top:12px}
        .index-card{display:block;padding:12px 14px;border:1px solid var(--line);border-radius:8px;text-decoration:none;color:var(--ink);background:#fff}
        .index-card:hover{background:var(--soft);border-color:#bcd2c4}
        .index-card .ix-group{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin-bottom:2px}
        .index-card .ix-title{font-weight:600}
        @media print{
            .docs-side,.crumbs{display:none}
            .docs-grid{grid-template-columns:1fr}
            .docs-main{border:0;padding:0}
        }
    
        @media(max-width:1023px){
          /* Docs section navigation: 15 links sat under the touch minimum. */
          .docs-side a,.docs-side li>a{min-height:44px;display:flex;align-items:center}
          nav ul li a{min-height:44px;display:flex;align-items:center}
        }
    
        /* WCAG 2.5.8 (AA): section links are list navigation, not inline prose,
           so the 24px minimum applies. Mobile already gets 44px above. */
        @media (min-width:1024px){
          .docs-side a,nav ul li a{min-height:24px;display:flex;align-items:center}
        }
        /* Content list links render at 20px. padding-block reaches the 24px
           minimum without switching display and disturbing prose flow. */
        main li > a{padding-block:2px}
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?>>
    <?= portal_header($basePath, 'User Guide', 'Detailed tutorials for ministry leaders', $campuses, $defaultCampusId, $actor) ?>

    <div class="docs-grid">
        <nav class="docs-side" aria-label="Documentation sections">
            <h3>Sections</h3>
            <?php foreach ($grouped as $groupName => $items): ?>
                <h3><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></h3>
                <ul>
                    <?php foreach ($items as $s):
                        $isActive = $activeSection && $activeSection['slug'] === $s['slug']; ?>
                        <li>
                            <a href="<?= $base ?>/docs/<?= htmlspecialchars($s['slug'], ENT_QUOTES, 'UTF-8') ?>"
                               class="<?= $isActive ? 'active' : '' ?>">
                                <?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <main class="docs-main" id="portal-main" tabindex="-1">
            <?php if ($activeSection): ?>
                <div class="crumbs">
                    <a href="<?= $base ?>/docs">User Guide</a> &nbsp;/&nbsp;
                    <span><?= htmlspecialchars($activeSection['title'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?= $contentHtml ?>
            <?php else: ?>
                <div class="docs-hero">
                    <h1>User Guide</h1>
                    <p>Plain-language tutorials for ministry leaders, members, and admins. Pick a topic to get started.</p>
                </div>

                <p>This guide is organized around the things leaders actually do in the portal: viewing schedules, editing the rotation, creating events, managing people, and finding help when something doesn't behave as expected. Each section is short, practical, and includes real examples.</p>

                <h2>Browse by topic</h2>
                <div class="index-grid">
                    <?php foreach ($DOCS_SECTIONS as $s): ?>
                        <a class="index-card" href="<?= $base ?>/docs/<?= htmlspecialchars($s['slug'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="ix-group"><?= htmlspecialchars($s['group'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="ix-title"><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <h2>Where to start</h2>
                <ul>
                    <li><strong>New to the portal?</strong> Start with <a href="<?= $base ?>/docs/navigation">Site Navigation</a>.</li>
                    <li><strong>Just became a leader?</strong> Read <a href="<?= $base ?>/docs/calendar">Calendar, views and print</a> and <a href="<?= $base ?>/docs/permissions">Permissions &amp; Access</a>.</li>
                    <li><strong>Looking up a quick answer?</strong> Try the <a href="<?= $base ?>/docs/faq">FAQ</a>.</li>
                </ul>
            <?php endif; ?>
        </main>
    </div>
</div>
</body>
</html>
