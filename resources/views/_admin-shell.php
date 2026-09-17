<?php

declare(strict_types=1);

require_once __DIR__ . '/_portal-shell.php';

if (!function_exists('admin_can_use_admin')) {
    /**
     * Whether this actor has any business in the administration area at all.
     *
     * Not the authorisation boundary — the route handlers and the API are, and
     * they were already correct. This decides whether to *offer* the area. A
     * member with no admin capability previously saw all 26 navigation entries
     * and got an empty page from every one of them.
     *
     * @param ?array<string,mixed> $actor
     */
    function admin_can_use_admin(?array $actor): bool
    {
        return admin_visible_sections('', $actor) !== [];
    }
}

if (!function_exists('admin_sections')) {
    /**
     * Admin navigation, ordered by how often the work is done.
     *
     * The previous order led with the control board and then Maintenance —
     * backups, restores and imports — above People. That ranked the rarest and
     * most destructive area second out of ten. Everyday church work now comes
     * first and system administration last, so the shape of the menu matches
     * the shape of the job.
     *
     * Each node carries a 'need':
     *   null              anyone who can reach the admin area
     *   'admin'           portal-wide administrators only
     *   'perm:<value>'    holders of that PortalPermission
     *
     * A node is either a link ('id'/'href') or a group ('group'/'children').
     *
     * @return list<array<string,mixed>>
     */
    function admin_sections(string $basePath): array
    {
        return [
            ['id' => 'overview', 'label' => 'Overview', 'href' => $basePath . '/admin', 'icon' => 'dashboard', 'need' => null],

            ['group' => 'People & families', 'icon' => 'people', 'need' => 'admin', 'children' => [
                ['id' => 'people',   'label' => 'Member records',   'href' => $basePath . '/admin/people',   'icon' => 'people',   'need' => 'admin'],
                ['id' => 'families', 'label' => 'Families',         'href' => $basePath . '/admin/families', 'icon' => 'people',   'need' => 'admin'],
                ['id' => 'outreach', 'label' => 'Sign-ups & RSVP',  'href' => $basePath . '/admin/outreach', 'icon' => 'people',   'need' => 'admin'],
                // "Options" said nothing about what it configures.
                ['id' => 'options',  'label' => 'Member types',     'href' => $basePath . '/admin/options',  'icon' => 'settings', 'need' => 'admin'],
            ]],

            ['group' => 'Ministries', 'icon' => 'ministry', 'need' => 'perm:manage_ministry_roles', 'children' => [
                ['id' => 'groups',     'label' => 'Members & leaders', 'href' => $basePath . '/admin/groups-and-ministries', 'icon' => 'people',   'need' => 'perm:manage_ministry_roles'],
                ['id' => 'ministries', 'label' => 'Ministry list',     'href' => $basePath . '/admin/ministries',            'icon' => 'ministry', 'need' => 'admin'],
            ]],

            // Personal destinations (Account, My Schedule) belong in the
            // header overflow, not here. /admin/me redirects to /account.
            ['group' => 'Calendar & events', 'icon' => 'calendar', 'need' => 'perm:manage_events', 'children' => [
                ['id' => 'calendar',    'label' => 'Calendar',         'href' => $basePath . '/admin/calendar',    'icon' => 'calendar', 'need' => 'perm:manage_events'],
                ['id' => 'events',      'label' => 'Events',           'href' => $basePath . '/admin/events',      'icon' => 'events',   'need' => 'perm:manage_events'],
                // "Event types" reads as a data structure; these are the
                // categories an administrator picks when creating an event.
                ['id' => 'event-types', 'label' => 'Event categories', 'href' => $basePath . '/admin/event-types', 'icon' => 'events',   'need' => 'admin'],
            ]],

            ['group' => 'Church setup', 'icon' => 'settings', 'need' => 'admin', 'children' => [
                ['id' => 'church-info', 'label' => 'Church information', 'href' => $basePath . '/admin/church-info', 'icon' => 'settings', 'need' => 'admin'],
                ['id' => 'campuses',    'label' => 'Campuses',           'href' => $basePath . '/admin/campuses',    'icon' => 'ministry', 'need' => 'admin'],
            ]],

            // Announcements, hero and links were working pages that appeared in
            // no menu at all — reachable only by typing the URL.
            ['group' => 'Portal management', 'icon' => 'admin', 'need' => 'admin', 'children' => [
                ['id' => 'users',         'label' => 'Users & access',  'href' => $basePath . '/admin/users',         'icon' => 'people',   'need' => 'admin'],
                ['id' => 'announcements', 'label' => 'Announcements',   'href' => $basePath . '/admin/announcements', 'icon' => 'docs',     'need' => 'admin'],
                ['id' => 'hero',          'label' => 'Home page banner','href' => $basePath . '/admin/hero',          'icon' => 'dashboard','need' => 'admin'],
                ['id' => 'theme',         'label' => 'Theme & colours', 'href' => $basePath . '/admin/theme',         'icon' => 'settings', 'need' => 'admin'],
                ['id' => 'header',        'label' => 'Header menu',     'href' => $basePath . '/admin/header',        'icon' => 'menu',     'need' => 'admin'],
                ['id' => 'footer',        'label' => 'Footer',          'href' => $basePath . '/admin/footer',        'icon' => 'menu',     'need' => 'admin'],
                ['id' => 'links',         'label' => 'Quick links',     'href' => $basePath . '/admin/links',         'icon' => 'menu',     'need' => 'admin'],
            ]],

            ['group' => 'Data & maintenance', 'icon' => 'settings', 'need' => 'admin', 'children' => [
                ['id' => 'import',      'label' => 'Import members',      'href' => $basePath . '/admin/maintenance/import', 'icon' => 'people',   'need' => 'admin'],
                ['id' => 'maintenance', 'label' => 'Backups & exports',   'href' => $basePath . '/admin/maintenance',        'icon' => 'settings', 'need' => 'admin'],
            ]],

            // Deliberately last and deliberately dull. Troubleshooting
            // information and product planning are not church administration.
            ['group' => 'Advanced', 'icon' => 'docs', 'need' => 'admin', 'children' => [
                ['id' => 'system',  'label' => 'System information', 'href' => $basePath . '/admin/system',  'icon' => 'settings', 'need' => 'admin'],
                ['id' => 'roadmap', 'label' => 'Development roadmap','href' => $basePath . '/admin/roadmap', 'icon' => 'docs',     'need' => 'admin'],
            ]],
        ];
    }
}

if (!function_exists('admin_meets_need')) {
    /**
     * @param ?array<string,mixed> $actor
     */
    function admin_meets_need(?string $need, ?array $actor): bool
    {
        if ($actor === null) {
            return false;
        }
        $isAdmin = (bool) ($actor['isPortalWideAdmin'] ?? false);
        if ($need === null || $need === '') {
            return true;
        }
        if ($isAdmin) {
            return true;   // portal-wide admins hold everything below
        }
        if ($need === 'admin') {
            return false;
        }
        if (str_starts_with($need, 'perm:')) {
            $permissions = $actor['permissions'] ?? [];

            return is_array($permissions) && in_array(substr($need, 5), $permissions, true);
        }

        return false;
    }
}

if (!function_exists('admin_visible_sections')) {
    /**
     * The navigation this actor can actually use.
     *
     * A group whose children are all unavailable is dropped rather than shown
     * empty, and the Overview entry only survives when something else does —
     * otherwise the area would offer a dashboard onto nothing.
     *
     * @param ?array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    function admin_visible_sections(string $basePath, ?array $actor): array
    {
        $out = [];
        foreach (admin_sections($basePath) as $node) {
            if (isset($node['group'])) {
                $children = array_values(array_filter(
                    $node['children'],
                    static fn (array $c): bool => admin_meets_need($c['need'] ?? null, $actor),
                ));
                if ($children !== []) {
                    $node['children'] = $children;
                    $out[] = $node;
                }
                continue;
            }
            if (admin_meets_need($node['need'] ?? null, $actor)) {
                $out[] = $node;
            }
        }

        // Only Overview survived: nothing to administer, so offer nothing.
        if (count($out) === 1 && ($out[0]['id'] ?? '') === 'overview') {
            return [];
        }

        return $out;
    }
}

if (!function_exists('admin_side_link')) {
    /** @param array<string,mixed> $item */
    function admin_side_link(array $item, string $activeId): string
    {
        $isActive = ($item['id'] ?? '') === $activeId;
        return sprintf(
            // aria-label mirrors the visible label so the control still has an
            // accessible name while its <details> group is collapsed, and the
            // decorative icon is hidden from assistive tech.
            '<a class="admin-side-item%s" href="%s"%s aria-label="%s"><span class="admin-side-icon" aria-hidden="true">%s</span><span class="admin-side-label">%s</span>%s</a>',
            $isActive ? ' is-active' : '',
            htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8'),
            $isActive ? ' aria-current="page"' : '',
            htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8'),
            portal_icon((string) $item['icon']),
            htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8'),
            isset($item['badge'])
                ? '<span class="admin-side-badge">' . htmlspecialchars((string) $item['badge'], ENT_QUOTES, 'UTF-8') . '</span>'
                : '',
        );
    }
}

if (!function_exists('admin_side_group_key')) {
    /** A stable identifier for a nav group, for remembering it open or shut. */
    function admin_side_group_key(string $label): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)) ?? '', '-');
    }
}

if (!function_exists('admin_sidebar_html')) {
    /** @param ?array<string,mixed> $actor */
    function admin_sidebar_html(string $basePath, string $activeId, ?array $actor = null): string
    {
        $caret = '<svg class="admin-side-caret" viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M8 5l8 7-8 7z"/></svg>';
        $out = '';
        foreach (admin_visible_sections($basePath, $actor) as $node) {
            if (isset($node['group'])) {
                $childHtml = '';
                $open = false;
                foreach ($node['children'] as $child) {
                    if (($child['id'] ?? '') === $activeId) { $open = true; }
                    $childHtml .= admin_side_link($child, $activeId);
                }
                // Which section you are in is a fact about the URL, so it is
                // decided here and never read back from browser storage. The
                // group carries it separately from `open`, because a group can
                // be collapsed by hand and still be the one you are inside —
                // and that is exactly when the marker is worth having.
                $groupKey = admin_side_group_key((string) $node['group']);
                $out .= sprintf(
                    '<details class="admin-side-group%s"%s data-group="%s"%s>'
                    . '<summary class="admin-side-summary"><span class="admin-side-icon">%s</span>'
                    . '<span class="admin-side-label">%s</span>%s%s</summary>'
                    . '<div class="admin-side-children">%s</div></details>',
                    $open ? ' is-current' : '',
                    $open ? ' open' : '',
                    htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8'),
                    $open ? ' data-current="1"' : '',
                    portal_icon((string) $node['icon']),
                    htmlspecialchars((string) $node['group'], ENT_QUOTES, 'UTF-8'),
                    // Named for assistive tech, which cannot see the highlight.
                    $open ? '<span class="sr-only"> (current section)</span>' : '',
                    $caret,
                    $childHtml,
                );
            } else {
                $out .= admin_side_link($node, $activeId);
            }
        }
        return '<button class="admin-sidebar-toggle" type="button" id="adminNavToggle"'
            . ' aria-expanded="false" aria-controls="adminSidebar">Admin sections</button>'
            . '<aside class="admin-sidebar" id="adminSidebar" aria-label="Admin navigation">' . $out . '</aside>'
            . '<script>(function(){var b=document.getElementById("adminNavToggle"),n=document.getElementById("adminSidebar");'
            . 'if(!b||!n)return;var mq=window.matchMedia("(max-width:880px)");'
            . 'function sync(){if(mq.matches){n.hidden=b.getAttribute("aria-expanded")!=="true";}else{n.hidden=false;}}'
            . 'b.addEventListener("click",function(){b.setAttribute("aria-expanded",b.getAttribute("aria-expanded")==="true"?"false":"true");sync();});'
            . 'mq.addEventListener?mq.addEventListener("change",sync):mq.addListener(sync);sync();})();</script>'
            // Remember which groups the administrator left open. Only that:
            // which page is active comes from the URL and is rendered above, so
            // storage can never disagree with the address bar about where you
            // are. The group containing the current page is always open on
            // arrival regardless of what was remembered — being taken to a page
            // whose section is shut is disorienting.
            . '<script>(function(){var KEY="portal_admin_nav_open_v1";'
            . 'var groups=[].slice.call(document.querySelectorAll(".admin-side-group[data-group]"));'
            . 'if(!groups.length)return;var shut={};'
            . 'try{shut=JSON.parse(localStorage.getItem(KEY)||"{}")||{};}catch(e){shut={};}'
            . 'groups.forEach(function(g){'
            . 'if(g.dataset.current==="1"){g.open=true;return;}'
            . 'if(Object.prototype.hasOwnProperty.call(shut,g.dataset.group)){g.open=!shut[g.dataset.group];}'
            . '});'
            // Record only a toggle that actually changed something. Chromium
            // fires "toggle" for a server-rendered <details open> during parse,
            // so listening naively wrote an entry for whichever group the
            // server had opened — and storage quietly became a record of every
            // section you had ever visited, which then stayed open on every
            // later page. Comparing against the state at attach time makes a
            // parse-time toggle a no-op and a click a real change.
            . 'var last={};groups.forEach(function(g){last[g.dataset.group]=g.open;});'
            . 'groups.forEach(function(g){g.addEventListener("toggle",function(){'
            . 'if(g.open===last[g.dataset.group])return;'
            . 'last[g.dataset.group]=g.open;shut[g.dataset.group]=!g.open;'
            . 'try{localStorage.setItem(KEY,JSON.stringify(shut));}catch(e){}'
            . '});});})();</script>';
    }
}

if (!function_exists('admin_section_status_badge')) {
    /** Render a small badge: "Live", "Planned", "Beta", etc. */
    /**
     * Development status, shown only when it tells an administrator something.
     *
     * There were 67 LIVE / BETA / PLANNED badges across fourteen admin views.
     * They describe the state of the software to the people building it. To a
     * church administrator, "LIVE" on a working page is noise on every row, and
     * "BETA" invites doubt about a feature they are expected to rely on.
     *
     * So working functionality carries no badge at all — that is the normal
     * case and needs no announcement. Only something genuinely unavailable is
     * marked, and in words rather than in project vocabulary.
     *
     * The signature is unchanged so all thirty-six callers keep working; what
     * they render is what changed.
     */
    function admin_section_status_badge(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if ($kind === 'planned' || $kind === 'soon') {
            return '<span class="admin-status admin-status-planned">Coming soon</span>';
        }

        // live, beta, and anything else that is actually usable.
        return '';
    }
}

if (!function_exists('admin_shell_styles')) {
    /**
     * Single source of truth for all admin-page styles. Pages inline this
     * once via <?= admin_shell_styles() ?> at the top of <head>.
     */
    function admin_shell_styles(): string
    {
        return <<<'CSS'
<style>
*{box-sizing:border-box}
body{margin:0;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
/* Available to assistive technology, absent from the page. Used for table
   captions and for distinguishing controls that repeat identical text down a
   column ("Download", "Details"). The codebase had no such utility, so the
   first caption written against it would have rendered visibly. */
.sr-only{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}
a{color:inherit}
.shell{width:100%;max-width:none;margin:0;padding:0 0 40px}
.admin-titleblock{position:relative;isolation:isolate}
.admin-titleblock::before{content:"";position:absolute;top:-18px;bottom:-14px;left:calc(-1 * var(--portal-gutter,1.5rem));right:calc(-1 * var(--portal-gutter,1.5rem));
  width:auto;background:linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 100%);z-index:-1}
.admin-titleblock{margin:0 0 18px;color:#f8fffb}
.admin-titleblock .crumbs{margin-bottom:6px;color:rgba(248,255,251,.78);font-size:12px}
.admin-titleblock .crumbs a{color:#fff;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;min-height:24px}
.admin-titleblock h1{margin:0;font-size:clamp(24px,3.2vw,34px);line-height:1.04}
.admin-titleblock .sub{color:rgba(248,255,251,.78);font-size:13px;margin-top:4px}
.admin-layout:not(:has(.admin-sidebar)){grid-template-columns:minmax(0,1fr)}
.admin-layout{display:grid;grid-template-columns:240px minmax(0,1fr);gap:18px;align-items:start;width:100%;min-width:0}
/* No sidebar to sit beside, so the notice gets the full width. */
.admin-layout.is-unavailable{grid-template-columns:minmax(0,1fr)}
.admin-sidebar{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius,10px);box-shadow:0 16px 42px rgba(27,50,40,.08);overflow:hidden;position:sticky;top:14px;display:grid}
.admin-side-item{display:flex;align-items:center;gap:10px;padding:11px 14px;text-decoration:none;color:var(--ink);font-weight:600;font-size:13px;border-left:3px solid transparent;transition:background .12s,border-color .12s}
.admin-side-item:hover{background:var(--soft)}
.admin-side-item.is-active{background:var(--soft);border-left-color:var(--teal);color:var(--deep)}
.admin-side-icon{width:28px;height:28px;display:grid;place-items:center;border-radius:6px;background:var(--soft);color:var(--teal);flex-shrink:0}
.admin-side-icon svg{width:16px;height:16px}
.admin-side-label{flex:1}
.admin-side-badge{font-size:12px;font-weight:800;background:var(--gold);color:var(--on-gold);padding:2px 7px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em}
.admin-side-group{display:block;border-top:1px solid var(--line)}
.admin-side-group:first-child,.admin-side-item:first-child+.admin-side-group{border-top:0}
.admin-side-summary{display:flex;align-items:center;gap:10px;padding:11px 14px;cursor:pointer;color:var(--ink);font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.03em;list-style:none;user-select:none}
.admin-side-summary::-webkit-details-marker{display:none}
.admin-side-summary:hover{background:var(--soft)}
.admin-side-caret{margin-left:auto;opacity:.55;transition:transform .15s}
.admin-side-group[open]>.admin-side-summary .admin-side-caret{transform:rotate(90deg)}
.admin-side-group[open]>.admin-side-summary{color:var(--deep)}
/* The section you are in, marked whether or not it is expanded. A collapsed
   group is exactly when this is worth having, and the bar is not colour alone:
   the label also goes bold and the group is named to assistive tech. */
.admin-side-group.is-current>.admin-side-summary{color:var(--deep);font-weight:800;
  box-shadow:inset 3px 0 0 var(--teal);background:var(--soft)}
.admin-side-children{display:grid;padding-bottom:4px}
.admin-side-children .admin-side-item{padding-left:20px;font-size:12.5px}
.admin-side-children .admin-side-icon{width:22px;height:22px}
.admin-side-children .admin-side-icon svg{width:13px;height:13px}
.admin-content{display:grid;gap:14px;width:100%;min-width:0;max-width:none}
.admin-card-body .field,.admin-card-body .field-row{max-width:48rem}
.admin-card-body .field-row{max-width:72rem}
.admin-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius,10px);box-shadow:0 16px 42px rgba(27,50,40,.06);overflow:hidden}
.admin-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;padding:16px;border-bottom:1px solid var(--line)}
.admin-card-head h2{margin:0;font-size:15px}
.admin-card-head p{margin:3px 0 0;color:var(--muted);font-size:12px;line-height:1.4}
.admin-card-body{padding:14px 16px}
.admin-status{font-size:12px;padding:3px 7px;border-radius:999px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;flex-shrink:0;display:inline-block}
.admin-status-live{background:#e6f6ee;color:#1a6b4a}
.admin-status-beta{background:#fff4d4;color:#7a5400}
.admin-status-planned{background:#eef0f2;color:#5a6976}
.tile-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.tile{display:flex;flex-direction:column;gap:6px;padding:14px;border:1px solid var(--line);border-radius:var(--radius,10px);background:#fbfdfc;text-decoration:none;color:var(--ink);transition:border-color .12s,box-shadow .12s}
.tile:hover{border-color:var(--teal);box-shadow:0 8px 22px rgba(17,123,109,.12)}
.tile.is-disabled{opacity:.5;pointer-events:none}
.tile h3{margin:0;font-size:14px;display:flex;align-items:center;justify-content:space-between;gap:6px}
.tile p{margin:0;color:var(--muted);font-size:12px;line-height:1.4}
.tile .tile-cta{font-weight:800;color:var(--teal);font-size:12px;margin-top:4px}
.field{display:grid;gap:5px;margin-bottom:12px}
.field label{font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.field input,.field textarea,.field select{width:100%;border:1px solid var(--line);border-radius:8px;padding:10px 12px;font:inherit;background:var(--paper);color:var(--ink)}
.field-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.switch{display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid var(--line);border-radius:8px;background:#fbfdfc;font-weight:600}
.button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 14px;border-radius:8px;background:var(--teal);color:var(--on-teal);border:0;font:inherit;font-weight:800;cursor:pointer;text-decoration:none}
.button.secondary{background:var(--paper);color:var(--deep);border:1px solid var(--line)}
.button.danger{background:#fff5f5;color:var(--danger);border:1px solid #f3c7c7}
.button:disabled,.button[aria-disabled="true"]{opacity:.5;cursor:not-allowed;pointer-events:none}
.actions-bar{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:14px 16px;border-top:1px solid var(--line);background:#fbfdfc}
.toast{font-size:12px;color:var(--muted)}
.toast.success{color:var(--teal)}
.toast.error{color:var(--danger)}
.read-only-banner{padding:11px 14px;background:#fff8e6;border:1px solid #f3e0a8;color:#7c5b07;font-size:13px;border-radius:8px;margin-bottom:14px}
.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:var(--muted);background:var(--soft);padding:2px 6px;border-radius:4px}
.theme-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px;padding:14px 16px}
.theme-card{position:relative;border:2px solid var(--line);border-radius:10px;overflow:hidden;background:#fff;cursor:pointer;display:grid;grid-template-rows:90px 1fr;transition:border-color .12s,box-shadow .12s}
.theme-card:hover{border-color:var(--teal)}
.theme-card.is-active{border-color:var(--teal);box-shadow:0 8px 22px rgba(17,123,109,.18)}
.theme-card.is-active::after{content:"✓ Active";position:absolute;top:8px;right:8px;background:var(--teal);color:var(--on-teal);font-size:12px;padding:3px 8px;border-radius:999px;font-weight:800}
.theme-swatch{display:flex;height:100%;width:100%}
.theme-swatch>span{flex:1}
.theme-info{padding:10px 12px;display:grid;gap:3px}
.theme-info b{font-size:13px}
.theme-info small{color:var(--muted);font-size:12px}
/* Below 880px the sidebar used to reflow into wrapped 50%-width items, which
   produced a ragged multi-row strip with misaligned partial underlines (audit
   H7 / Cursor finding 8). It is a navigation list, so on small screens it stays
   a full-width vertical list inside a disclosure — the section groupings the
   markup already expresses via <details> are preserved. */
@media(max-width:880px){
  .admin-layout{grid-template-columns:1fr}
  .admin-sidebar{position:static;display:block}
  .admin-side-item{display:flex;min-height:48px;flex:none;width:100%;border-left:3px solid transparent;border-bottom:1px solid var(--line)}
  .admin-side-item.is-active{border-left-color:var(--teal);border-bottom-color:var(--line)}
  .admin-side-summary{min-height:48px}
  .admin-side-children .admin-side-item{padding-left:26px}
  .field-row{grid-template-columns:1fr}
  .admin-sidebar-toggle{display:flex}
  .admin-sidebar[hidden]{display:none}
}
/* Shared admin responsive layer (Phase 1.5 primitives). Applied once here so
   all 24 admin views inherit it — eight of them had no media query at all
   (audit H7). Presentation only: no authorization, form action or confirmation
   behaviour is touched. */
.admin-content table{width:100%;border-collapse:collapse}
/* position:relative matters here. Absolutely-positioned descendants — a
   screen-reader-only span inside a wide table — otherwise resolve against
   the initial containing block, escape this scroll container and push the
   whole document sideways on a phone. */
.admin-tablewrap{overflow-x:auto;-webkit-overflow-scrolling:touch;position:relative;max-width:100%}
@media(max-width:900px){
  /* Tables stay tabular but scroll inside their own region rather than forcing
     the page wide; rows and controls reach touch size. */
  .admin-content table{min-width:560px}
  .admin-content td,.admin-content th{padding:10px 12px}
  .admin-content td a,.admin-content td button{min-height:44px;display:inline-flex;align-items:center}
  .admin-content input:not([type=checkbox]):not([type=radio]),
  .admin-content select,.admin-content textarea{min-height:48px;font-size:16px;width:100%}
  .admin-content input[type=checkbox],.admin-content input[type=radio]{width:20px;height:20px}
  .admin-content label{font-size:13px}
  .admin-content .button,.admin-content button{min-height:44px}
  .admin-card-body{padding:14px}
  /* Action clusters stack instead of squeezing. */
  .admin-actions,.admin-card-actions{display:flex;flex-wrap:wrap;gap:8px}
  .admin-actions>*,.admin-card-actions>*{flex:1 1 auto}
}
.admin-sidebar-toggle{display:none;align-items:center;gap:8px;width:100%;min-height:48px;padding:0 14px;
  margin:0 0 12px;background:var(--soft);border:1px solid var(--line);border-radius:var(--radius,10px);
  font:inherit;font-size:14px;font-weight:700;color:var(--ink);cursor:pointer}
</style>
CSS;
    }
}

if (!function_exists('admin_render_page')) {
    /**
     * Render a complete admin sub-page (topbar + breadcrumb + sidebar + content).
     * Call from each /admin/{section} route view.
     *
     * @param array{
     *   basePath:string,
     *   activeId:string,
     *   pageTitle:string,
     *   pageSubtitle:string,
     *   sectionTitle:string,
     *   sectionDescription:string,
     *   actor:?array<string,mixed>,
     *   campusSelector:array<string,mixed>,
     *   isAdmin:bool
     * } $args
     * @param callable():string $renderBody     callable returning the inner HTML
     */
    function admin_render_page(array $args, callable $renderBody): string
    {
        $base = htmlspecialchars($args['basePath'], ENT_QUOTES, 'UTF-8');
        $campusSelector = is_array($args['campusSelector'] ?? null) ? $args['campusSelector'] : [];
        $campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
        $defaultCampusId = $campusSelector['defaultCampusId'] ?? null;

        $title = htmlspecialchars($args['pageTitle'], ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars($args['pageSubtitle'], ENT_QUOTES, 'UTF-8');

        ob_start();
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $title ?> · Admin · Church Portal</title>
    <?= admin_shell_styles() ?>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>" data-can-save="<?= !empty($args['isAdmin']) ? '1' : '0' ?>">
    <?= portal_header(
        $args['basePath'],
        '',
        '',
        $campuses,
        $defaultCampusId !== null ? (int) $defaultCampusId : null,
        $args['actor'] ?? null,
        [],
        [],
        [],
        'Sign in',
        $args['basePath'] . '/login',
    ) ?>
    <div class="admin-titleblock">
        <?php if ($args['activeId'] !== 'overview'): ?><div class="crumbs"><a href="<?= $base ?>/admin">Administration</a><?= $args['activeId'] !== 'overview' ? ' &rsaquo; ' . htmlspecialchars($args['sectionTitle'], ENT_QUOTES, 'UTF-8') : '' ?></div><?php endif; ?>
        <h1><?= htmlspecialchars($args['sectionTitle'], ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="sub"><?= htmlspecialchars($args['sectionDescription'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php
        // A member with no administrative capability used to get the full
        // navigation and an empty page from every entry in it. Say plainly
        // that the area is not theirs, and point at what is.
        $canUseAdmin = admin_can_use_admin($args['actor'] ?? null);
    ?>
    <div class="admin-layout<?= $canUseAdmin ? '' : ' is-unavailable' ?>">
        <?php if ($canUseAdmin): ?>
        <?= admin_sidebar_html($args['basePath'], $args['activeId'], $args['actor'] ?? null) ?>
        <?php endif; ?>
        <main class="admin-content" id="portal-main" tabindex="-1">
            <?php if ($canUseAdmin): ?>
                <?= $renderBody() ?>
            <?php else: ?>
                <section class="admin-card">
                    <div class="admin-card-head"><div>
                        <h2><?= ($args['actor'] ?? null) === null ? 'Please sign in' : 'You do not have access to this area' ?></h2>
                        <p><?= ($args['actor'] ?? null) === null
                            ? 'Administration is available to signed-in staff and ministry leaders.'
                            : 'Administration is for staff and ministry leaders who manage church records. Your account does not include it.' ?></p>
                    </div></div>
                    <div class="admin-card-body">
                        <p class="muted">If you think this is wrong, ask a church administrator to review your account.</p>
                        <p>
                            <a class="button" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/">Go to the home page</a>
                            <?php if (($args['actor'] ?? null) !== null): ?>
                            <a class="button secondary" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/my-schedule">My schedule</a>
                            <?php endif; ?>
                        </p>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
    <?= portal_footer() ?>
</div>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }
}
