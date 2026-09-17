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
     * The administration capabilities, grouped, and who holds each.
     *
     * Since Phase 2 this no longer renders any navigation: the workspace map
     * (App\Core\Navigation\Workspaces) places every admin page in its
     * workspace, and the application sidebar shows it. This list still decides
     * whether an actor has any business in the admin area at all
     * (admin_can_use_admin), which is what the "not yours" notice rests on.
     *
     * Originally: admin navigation, ordered by how often the work is done.
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
                ['id' => 'groups',     'label' => 'Members & leaders', 'href' => $basePath . '/ministries/members-and-leaders', 'icon' => 'people',   'need' => 'perm:manage_ministry_roles'],
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
@media(max-width:880px){
  .field-row{grid-template-columns:1fr}
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
</style>
CSS;
    }
}

if (!function_exists('admin_render_page')) {
    /**
     * Render a complete admin sub-page: the application shell, the workspace
     * tabs for the page's workspace, a page header and the content.
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
     *   isAdmin:bool,
     *   headerActions?:string
     * } $args   headerActions: trusted HTML for the page header's action slot
     * @param callable():string $renderBody     callable returning the inner HTML
     */
    function admin_render_page(array $args, callable $renderBody): string
    {
        $base = htmlspecialchars($args['basePath'], ENT_QUOTES, 'UTF-8');
        $campusSelector = is_array($args['campusSelector'] ?? null) ? $args['campusSelector'] : [];
        $campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
        $defaultCampusId = $campusSelector['defaultCampusId'] ?? null;

        $title = htmlspecialchars($args['pageTitle'], ENT_QUOTES, 'UTF-8');

        // A member with no administrative capability used to get the full
        // navigation and an empty page from every entry in it. Say plainly
        // that the area is not theirs, and point at what is.
        $canUseAdmin = admin_can_use_admin($args['actor'] ?? null);

        // Each admin section belongs to a workspace page (surfaces.md). Pin it,
        // so the sidebar, the top bar and the tabs agree with this page even
        // when its URL is a deep link (/admin/people/view?id=…).
        $place = \App\Core\Navigation\Workspaces::adminSections()[(string) $args['activeId']] ?? null;
        $crumbs = '';
        if ($place !== null) {
            \App\Core\Navigation\Workspaces::setCurrent($place[0], $place[1], $place[2]);
            // Breadcrumbs name only what the tabs do not: the workspace, and the
            // parent entry of a sub-page.
            $workspace = \App\Core\Navigation\Workspaces::workspace($place[0]);
            $visible = [];
            foreach (\App\Core\Navigation\Workspaces::visible($args['basePath'], $args['actor'] ?? null) as $ws) {
                $visible[$ws['id']] = $ws;
            }
            if ($workspace !== null) {
                $wsLabel = htmlspecialchars((string) $workspace['label'], ENT_QUOTES, 'UTF-8');
                $crumbs = isset($visible[$place[0]])
                    ? '<a href="' . htmlspecialchars((string) $visible[$place[0]]['pages'][0]['href'], ENT_QUOTES, 'UTF-8') . '">' . $wsLabel . '</a>'
                    : $wsLabel;
                if ($place[2] !== null) {
                    foreach ($workspace['pages'] as $page) {
                        if ($page['id'] === $place[1]) {
                            $crumbs .= ' &rsaquo; ' . htmlspecialchars((string) $page['label'], ENT_QUOTES, 'UTF-8');
                        }
                    }
                }
            }
        }

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
    <main class="admin-content ek-page" id="portal-main" tabindex="-1">
        <?php
            $tabs = $canUseAdmin && $place !== null
                ? ek_workspace_tabs($args['basePath'], $place[0], $place[1], $args['actor'] ?? null, $place[2])
                : '';
        ?>
        <?php if ($tabs !== ''): ?>
        <?= $tabs ?>
        <?php elseif ($crumbs !== ''): /* the tabs already say where you are */ ?>
        <p class="ek-crumbs"><?= $crumbs ?></p>
        <?php endif; ?>
        <?= ek_page_header((string) $args['sectionTitle'], (string) $args['sectionDescription'], $canUseAdmin ? (string) ($args['headerActions'] ?? '') : '') ?>
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
    <?= portal_footer() ?>
</div>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }
}
