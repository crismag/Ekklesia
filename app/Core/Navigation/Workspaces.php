<?php

declare(strict_types=1);

namespace App\Core\Navigation;

/**
 * The application's workspaces and the pages inside each: the one place the
 * portal's navigation is defined (docs/design/surfaces.md).
 *
 * The sidebar, the phone drawer and the workspace tabs all render from this
 * map, so they cannot disagree about where a page lives or who is offered it.
 *
 * A page:
 *   id         stable within its workspace
 *   label      what the sidebar and tabs say
 *   href       path below the portal base path ('/admin/people')
 *   icon       a portal_icon() name
 *   need       who is offered it:
 *                null            everyone, signed in or not
 *                'signed-in'     any signed-in account
 *                'admin'         portal-wide administrators
 *                'perm:<value>'  holders of that PortalPermission (admins hold all)
 *   match      paths that count as "on this page"; a trailing '/*' matches
 *              everything below. The longest matching pattern wins, so
 *              '/people/history' beats '/people/*'.
 *   available  false while the page is built by a later phase. It is kept in
 *              the map so its place is decided, but never rendered as a link:
 *              no dead links, no placeholder pages. Set it to true (or remove
 *              the key) in the same change that adds the route.
 *   external   served outside the portal router (the standalone sign-up app).
 *   children   sub-pages that share one sidebar entry and appear as a second
 *              row of tabs on the page.
 *
 * Hiding a page is a courtesy, not a control. Route handlers and the API remain
 * the authorization boundary; nothing here widens who may see or do anything.
 */
final class Workspaces
{
    /** Set by a page that knows exactly where it is (the admin shell). */
    private static ?array $current = null;

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return [
            [
                'id' => 'home', 'label' => 'Home', 'icon' => 'dashboard',
                'pages' => [
                    ['id' => 'home', 'label' => 'Home', 'href' => '/', 'icon' => 'dashboard', 'need' => null, 'match' => ['/']],
                    ['id' => 'my-schedule', 'label' => 'My schedule', 'href' => '/my-schedule', 'icon' => 'calendar', 'need' => 'signed-in', 'match' => ['/my-schedule', '/my-schedule/*']],
                    ['id' => 'availability', 'label' => 'My availability', 'href' => '/availability', 'icon' => 'availability', 'need' => 'signed-in', 'match' => ['/availability', '/availability/*']],
                    ['id' => 'account', 'label' => 'Account', 'href' => '/account', 'icon' => 'profile', 'need' => 'signed-in', 'match' => ['/account', '/account/*', '/password/change', '/admin/me']],
                ],
            ],
            [
                'id' => 'people', 'label' => 'People & Records', 'icon' => 'people',
                'pages' => [
                    ['id' => 'directory', 'label' => 'Directory', 'href' => '/people', 'icon' => 'people', 'need' => 'signed-in', 'match' => ['/people', '/people/*']],
                    ['id' => 'records', 'label' => 'Member records', 'href' => '/admin/people', 'icon' => 'people', 'need' => 'admin', 'match' => ['/admin/people', '/admin/people/*']],
                    ['id' => 'households', 'label' => 'Households', 'href' => '/admin/families', 'icon' => 'people', 'need' => 'admin', 'match' => ['/admin/families', '/admin/families/*']],
                    ['id' => 'import', 'label' => 'Import & export', 'href' => '/admin/maintenance/import', 'icon' => 'docs', 'need' => 'admin', 'match' => ['/admin/maintenance/import', '/admin/maintenance/import/*']],
                    ['id' => 'history', 'label' => 'Record history', 'href' => '/people/history', 'icon' => 'availability-short', 'need' => 'admin', 'match' => ['/people/history'], 'available' => false],
                    ['id' => 'settings', 'label' => 'Record settings', 'href' => '/admin/options', 'icon' => 'settings', 'need' => 'admin', 'match' => ['/admin/options']],
                ],
            ],
            [
                'id' => 'ministries', 'label' => 'Ministries', 'icon' => 'ministry',
                'pages' => [
                    ['id' => 'ministries', 'label' => 'Ministries', 'href' => '/ministries', 'icon' => 'ministry', 'need' => 'signed-in', 'match' => ['/ministries', '/ministries/*', '/ministry/*']],
                    // The membership editor, kept whole until each ministry gets
                    // its own Members & leaders tab.
                    ['id' => 'members', 'label' => 'Manage members & leaders', 'href' => '/ministries/members-and-leaders', 'icon' => 'people', 'need' => 'perm:manage_ministry_roles', 'match' => ['/ministries/members-and-leaders', '/ministries/members-and-leaders/*', '/admin/groups-and-ministries', '/admin/groups-and-ministries/*']],
                    ['id' => 'manage', 'label' => 'Manage ministries', 'href' => '/admin/ministries', 'icon' => 'settings', 'need' => 'admin', 'match' => ['/admin/ministries']],
                ],
            ],
            [
                'id' => 'events', 'label' => 'Events & Calendar', 'icon' => 'calendar',
                'pages' => [
                    ['id' => 'calendar', 'label' => 'Calendar', 'href' => '/calendar', 'icon' => 'calendar', 'need' => null, 'match' => ['/calendar', '/calendar/settings']],
                    ['id' => 'events', 'label' => 'Events', 'href' => '/events', 'icon' => 'events', 'need' => null, 'match' => ['/events', '/events/*', '/admin/events']],
                    ['id' => 'new-event', 'label' => 'New event', 'href' => '/events/new', 'icon' => 'events', 'need' => 'perm:manage_events', 'match' => ['/events/new']],
                    ['id' => 'categories', 'label' => 'Event categories', 'href' => '/admin/event-types', 'icon' => 'settings', 'need' => 'admin', 'match' => ['/admin/event-types']],
                    ['id' => 'calendar-settings', 'label' => 'Calendar settings', 'href' => '/admin/calendar', 'icon' => 'settings', 'need' => 'perm:manage_events', 'match' => ['/admin/calendar', '/admin/calendar/*']],
                    ['id' => 'print', 'label' => 'Print calendar', 'href' => '/calendar/print-setup', 'icon' => 'docs', 'need' => 'signed-in', 'match' => ['/calendar/print-setup', '/calendar/print']],
                ],
            ],
            [
                'id' => 'serving', 'label' => 'Serving & Scheduling', 'icon' => 'availability',
                'pages' => [
                    ['id' => 'schedules', 'label' => 'Schedules', 'href' => '/schedules', 'icon' => 'calendar', 'need' => 'perm:manage_schedules', 'match' => ['/schedules', '/scheduler/*']],
                    ['id' => 'board', 'label' => 'Schedule board', 'href' => '/schedule-board', 'icon' => 'dashboard', 'need' => 'signed-in', 'match' => ['/schedule-board']],
                    ['id' => 'rosters', 'label' => 'Rosters', 'href' => '/rosters', 'icon' => 'people', 'need' => 'signed-in', 'match' => ['/rosters', '/rosters/*']],
                    ['id' => 'printables', 'label' => 'Printables', 'href' => '/printables', 'icon' => 'docs', 'need' => 'signed-in', 'match' => ['/printables', '/printables/*', '/schedules/ministry-print']],
                ],
            ],
            [
                'id' => 'visitors', 'label' => 'Visitors & RSVPs', 'icon' => 'profile',
                'pages' => [
                    ['id' => 'visitors', 'label' => 'Visitors', 'href' => '/visitors', 'icon' => 'people', 'need' => 'admin', 'match' => ['/visitors', '/visitors/*']],
                    ['id' => 'rsvps', 'label' => 'RSVPs', 'href' => '/visitors/rsvps', 'icon' => 'events', 'need' => 'admin', 'match' => ['/visitors/rsvps']],
                    ['id' => 'access', 'label' => 'Access codes', 'href' => '/visitors/access', 'icon' => 'admin', 'need' => 'admin', 'match' => ['/visitors/access']],
                    // The standalone guest app. RSVP has no page of its own without
                    // an event, so it is reached from each event rather than here.
                    ['id' => 'signup', 'label' => 'Guest sign-up', 'href' => '/people_signup/', 'icon' => 'profile', 'need' => null, 'match' => [], 'external' => true],
                ],
            ],
            [
                'id' => 'admin', 'label' => 'Admin', 'icon' => 'admin',
                'pages' => [
                    ['id' => 'overview', 'label' => 'Overview', 'href' => '/admin', 'icon' => 'dashboard', 'need' => 'admin', 'match' => ['/admin', '/admin/dashboard']],
                    ['id' => 'users', 'label' => 'Users & access', 'href' => '/admin/users', 'icon' => 'people', 'need' => 'admin', 'match' => ['/admin/users', '/admin/users/*']],
                    ['id' => 'church-info', 'label' => 'Church information', 'href' => '/admin/church-info', 'icon' => 'settings', 'need' => 'admin', 'match' => ['/admin/church-info']],
                    ['id' => 'campuses', 'label' => 'Campuses', 'href' => '/admin/campuses', 'icon' => 'ministry', 'need' => 'admin', 'match' => ['/admin/campuses']],
                    ['id' => 'appearance', 'label' => 'Portal appearance & notices', 'href' => '/admin/announcements', 'icon' => 'docs', 'need' => 'admin', 'match' => [], 'children' => [
                        ['id' => 'announcements', 'label' => 'Portal notices', 'href' => '/admin/announcements', 'need' => 'admin', 'match' => ['/admin/announcements']],
                        ['id' => 'hero', 'label' => 'Home banner', 'href' => '/admin/hero', 'need' => 'admin', 'match' => ['/admin/hero']],
                        ['id' => 'theme', 'label' => 'Theme', 'href' => '/admin/theme', 'need' => 'admin', 'match' => ['/admin/theme']],
                        ['id' => 'header', 'label' => 'Header', 'href' => '/admin/header', 'need' => 'admin', 'match' => ['/admin/header']],
                        ['id' => 'footer', 'label' => 'Footer', 'href' => '/admin/footer', 'need' => 'admin', 'match' => ['/admin/footer']],
                    ]],
                    ['id' => 'maintenance', 'label' => 'Backups & maintenance', 'href' => '/admin/maintenance', 'icon' => 'settings', 'need' => 'admin', 'match' => ['/admin/maintenance', '/admin/maintenance/*']],
                    ['id' => 'history', 'label' => 'Activity history', 'href' => '/admin/history', 'icon' => 'availability-short', 'need' => 'admin', 'match' => ['/admin/history'], 'available' => false],
                    ['id' => 'system', 'label' => 'System', 'href' => '/admin/system', 'icon' => 'settings', 'need' => 'admin', 'match' => [], 'children' => [
                        ['id' => 'system', 'label' => 'System information', 'href' => '/admin/system', 'need' => 'admin', 'match' => ['/admin/system']],
                        ['id' => 'roadmap', 'label' => 'Development roadmap', 'href' => '/admin/roadmap', 'need' => 'admin', 'match' => ['/admin/roadmap', '/admin/links']],
                    ]],
                ],
            ],
        ];
    }

    /**
     * Admin section ids (admin_render_page 'activeId') → [workspace, page, child].
     *
     * @return array<string,array{0:string,1:string,2:?string}>
     */
    public static function adminSections(): array
    {
        return [
            'overview'      => ['admin', 'overview', null],
            'dashboard'     => ['admin', 'overview', null],
            'users'         => ['admin', 'users', null],
            'church-info'   => ['admin', 'church-info', null],
            'campuses'      => ['admin', 'campuses', null],
            'announcements' => ['admin', 'appearance', 'announcements'],
            'hero'          => ['admin', 'appearance', 'hero'],
            'theme'         => ['admin', 'appearance', 'theme'],
            'header'        => ['admin', 'appearance', 'header'],
            'footer'        => ['admin', 'appearance', 'footer'],
            'links'         => ['admin', 'system', 'roadmap'],
            'maintenance'   => ['admin', 'maintenance', null],
            'system'        => ['admin', 'system', 'system'],
            'roadmap'       => ['admin', 'system', 'roadmap'],
            'people'        => ['people', 'records', null],
            'families'      => ['people', 'households', null],
            'import'        => ['people', 'import', null],
            'options'       => ['people', 'settings', null],
            'groups'        => ['ministries', 'members', null],
            'ministries'    => ['ministries', 'manage', null],
            'calendar'      => ['events', 'calendar-settings', null],
            'events'        => ['events', 'events', null],
            'event-types'   => ['events', 'categories', null],
            'visitors'      => ['visitors', 'visitors', null],
            'rsvps'         => ['visitors', 'rsvps', null],
            'access'        => ['visitors', 'access', null],
        ];
    }

    /**
     * Whether an actor is offered something with this need.
     *
     * Same semantics as the admin area's admin_meets_need(), plus null for
     * "anyone" and 'signed-in'.
     *
     * @param ?array<string,mixed> $actor
     */
    public static function meetsNeed(?string $need, ?array $actor): bool
    {
        if ($need === null || $need === '') {
            return true;
        }
        if ($actor === null) {
            return false;
        }
        if ($need === 'signed-in') {
            return true;
        }
        if (!empty($actor['isPortalWideAdmin'])) {
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

    /** @param array<string,mixed> $page */
    public static function isAvailable(array $page): bool
    {
        return ($page['available'] ?? true) !== false;
    }

    /**
     * The workspaces this actor is offered, with only the pages (and children)
     * they may use and that exist. Empty workspaces are dropped. Hrefs are
     * prefixed with the base path.
     *
     * @param ?array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    public static function visible(string $basePath, ?array $actor): array
    {
        $base = rtrim($basePath, '/');
        $out = [];
        foreach (self::all() as $workspace) {
            $pages = [];
            foreach ($workspace['pages'] as $page) {
                if (!self::isAvailable($page) || !self::meetsNeed($page['need'] ?? null, $actor)) {
                    continue;
                }
                if (isset($page['children'])) {
                    $children = [];
                    foreach ($page['children'] as $child) {
                        if (self::isAvailable($child) && self::meetsNeed($child['need'] ?? null, $actor)) {
                            $child['href'] = $base . $child['href'];
                            $children[] = $child;
                        }
                    }
                    if ($children === []) {
                        continue;
                    }
                    $page['children'] = $children;
                    // The entry opens the first sub-page this actor can use.
                    $page['href'] = $children[0]['href'];
                } else {
                    $page['href'] = $base . $page['href'];
                }
                $pages[] = $page;
            }
            if ($pages !== []) {
                $workspace['pages'] = $pages;
                $out[] = $workspace;
            }
        }

        return $out;
    }

    /**
     * Where a request path sits in the map: the most specific match wins.
     * Considers every page, available or not, so a page's own route is never
     * attributed to a neighbour.
     *
     * @return ?array{workspace:string,page:string,child:?string}
     */
    public static function locate(string $path): ?array
    {
        $path = '/' . trim($path, '/');
        $best = null;
        $bestScore = -1;
        foreach (self::all() as $workspace) {
            foreach ($workspace['pages'] as $page) {
                $candidates = [[null, $page['match'] ?? []]];
                foreach ($page['children'] ?? [] as $child) {
                    $candidates[] = [$child['id'], $child['match'] ?? []];
                }
                foreach ($candidates as [$childId, $patterns]) {
                    foreach ($patterns as $pattern) {
                        $score = self::matchScore($pattern, $path);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = ['workspace' => $workspace['id'], 'page' => $page['id'], 'child' => $childId];
                        }
                    }
                }
            }
        }

        return $best;
    }

    /** -1 when the pattern does not match; otherwise higher is more specific. */
    private static function matchScore(string $pattern, string $path): int
    {
        if (str_ends_with($pattern, '/*')) {
            $prefix = substr($pattern, 0, -2);

            return str_starts_with($path, $prefix . '/') ? strlen($prefix) * 2 : -1;
        }

        // An exact match outranks a wildcard over the same prefix.
        return $path === $pattern ? strlen($pattern) * 2 + 1 : -1;
    }

    /** Pin the current location for this request (used by the admin shell). */
    public static function setCurrent(string $workspaceId, string $pageId, ?string $childId = null): void
    {
        self::$current = ['workspace' => $workspaceId, 'page' => $pageId, 'child' => $childId];
    }

    /** @return ?array{workspace:string,page:string,child:?string} */
    public static function current(string $path): ?array
    {
        return self::$current ?? self::locate($path);
    }

    /** @return ?array<string,mixed> */
    public static function workspace(string $workspaceId): ?array
    {
        foreach (self::all() as $workspace) {
            if ($workspace['id'] === $workspaceId) {
                return $workspace;
            }
        }

        return null;
    }
}
