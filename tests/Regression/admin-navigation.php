<?php

declare(strict_types=1);

/**
 * Administration navigation: what each kind of account is offered.
 *
 * The navigation used to be a constant. `admin_sections()` took no actor and
 * consulted no permission, so an ordinary member with no administrative role
 * was shown all 26 entries — Control board, Maintenance, Users & Access,
 * Appearance — and every one of them led to an empty page.
 *
 * The pages themselves were, and remain, properly guarded: this was never a
 * data leak. It was navigation promising capability the application would then
 * refuse. These tests hold the promise and the capability together.
 *
 * They deliberately do not re-test authorisation. Route handlers and the API
 * are the boundary; hiding a link is a courtesy, not a control.
 */

require_once __DIR__ . '/../../resources/views/_admin-shell.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

/** @return array<string,mixed> */
function actor(bool $admin, array $permissions = []): array
{
    return ['isPortalWideAdmin' => $admin, 'permissions' => $permissions];
}

/** @return list<string> */
function groupLabels(array $nodes): array
{
    return array_map(static fn (array $n): string => (string) ($n['group'] ?? $n['label']), $nodes);
}

$admin = actor(true);
$member = actor(false);
$eventsLeader = actor(false, ['manage_events']);
$ministryLeader = actor(false, ['manage_ministry_roles']);

// --- who is offered the area at all ---------------------------------------

check('a portal admin can use the admin area', admin_can_use_admin($admin));
check('a member cannot', !admin_can_use_admin($member));
check('nobody signed in cannot', !admin_can_use_admin(null));
check('a leader who manages events can', admin_can_use_admin($eventsLeader));

// --- what each is shown ----------------------------------------------------

$adminNav = admin_visible_sections('/bp', $admin);
check('an admin sees every group', count($adminNav) === 8, (string) count($adminNav));
check('and the area opens with Overview', ($adminNav[0]['id'] ?? '') === 'overview');

// Everyday church work first, system administration last. The old order put
// Maintenance — backups, restores, imports — second out of ten, above People.
$labels = groupLabels($adminNav);
check('People comes before Data & maintenance',
    array_search('People & families', $labels, true) < array_search('Data & maintenance', $labels, true),
    implode(' | ', $labels));
check('and Advanced is last', end($labels) === 'Advanced', implode(' | ', $labels));

check('a member is offered no navigation at all', admin_visible_sections('/bp', $member) === []);

// A leader sees the part of the area they can actually work in, and nothing else.
$eventsNav = groupLabels(admin_visible_sections('/bp', $eventsLeader));
check('an events leader sees Calendar & events', in_array('Calendar & events', $eventsNav, true), implode(' | ', $eventsNav));
check('and is not offered Users & access or backups',
    !in_array('Portal management', $eventsNav, true) && !in_array('Data & maintenance', $eventsNav, true),
    implode(' | ', $eventsNav));

$ministryNav = groupLabels(admin_visible_sections('/bp', $ministryLeader));
check('a ministry leader sees Ministries', in_array('Ministries', $ministryNav, true), implode(' | ', $ministryNav));
check('and not Calendar & events', !in_array('Calendar & events', $ministryNav, true), implode(' | ', $ministryNav));

// A group with nothing left in it is dropped rather than rendered empty.
foreach (admin_visible_sections('/bp', $eventsLeader) as $node) {
    if (isset($node['group'])) {
        check('no group is shown empty: ' . $node['group'], ($node['children'] ?? []) !== []);
    }
}

// Overview alone would be a dashboard onto nothing.
check('Overview alone is not offered as an area',
    admin_visible_sections('/bp', actor(false, ['view_own_assignments'])) === []);

// --- individual needs ------------------------------------------------------

check('a null need is open to anyone in the area', admin_meets_need(null, $member));
check('an admin need refuses a member', !admin_meets_need('admin', $member));
check('an admin need admits an admin', admin_meets_need('admin', $admin));
check('a permission need matches the permission', admin_meets_need('perm:manage_events', $eventsLeader));
check('and refuses without it', !admin_meets_need('perm:manage_events', $ministryLeader));
check('a portal admin satisfies every permission need', admin_meets_need('perm:manage_events', $admin));
check('nobody signed in satisfies nothing', !admin_meets_need(null, null));

// --- pages that had no way in ----------------------------------------------

// Announcements, the home page banner and quick links were working screens
// that appeared in no menu; they were reachable only by typing the URL.
$hrefs = [];
foreach (admin_sections('/bp') as $node) {
    foreach ($node['children'] ?? [$node] as $child) {
        $hrefs[] = (string) ($child['href'] ?? '');
    }
}
foreach (['/bp/admin/announcements', '/bp/admin/hero', '/bp/admin/links', '/bp/admin/maintenance/import'] as $href) {
    check('reachable from the menu: ' . $href, in_array($href, $hrefs, true));
}

// Personal functions moved to the header user menu; they are not church
// administration and a member needs them without an admin role.
check('My Pages is gone from Administration',
    !in_array('/bp/admin/me', $hrefs, true));

$overview = (string) file_get_contents(__DIR__ . '/../../resources/views/admin.php');
check('Overview does not offer My Pages', !str_contains($overview, '/admin/me'));
check('Overview does not hero Site Links', !str_contains($overview, "'Site Links'"));
check('Overview does not dump PORTAL_* env', !str_contains($overview, 'PORTAL_BASE_PATH'));
check('Overview does not dump an environment snapshot', !str_contains($overview, 'Environment snapshot'));
check('Overview points diagnostics at System information', str_contains($overview, 'System information'));

$adminEvents = (string) file_get_contents(__DIR__ . '/../../resources/views/admin-events.php');
check('Admin Events New event goes to /events/new', str_contains($adminEvents, "/events/new"));
check('Admin Events no longer links the leftover occurrence generator', !str_contains($adminEvents, '/events/occurrence/new'));
check('Admin Events planned cards point at the roadmap', str_contains($adminEvents, '/admin/roadmap'));

$adminMe = (string) file_get_contents(__DIR__ . '/../../resources/views/admin-me.php');
check('Admin Me redirects to Account', str_contains($adminMe, "header('Location:") && str_contains($adminMe, '/account'));
check('Admin Me is not a second Account editor', !str_contains($adminMe, 'Personal notes'));

// ---------------------------------------------------------------------------
// Workspace navigation (Phase 2).
//
// The admin area's own sidebar is gone: every page, admin or not, shows the
// application's workspace sidebar, rendered from one map. What these tests
// hold is the same promise as above — navigation offers what the account can
// use, and the URL (never browser storage) decides where you are.
echo "\nWorkspace navigation\n";

use App\Core\Navigation\Workspaces;

/** @return array<string,list<string>> workspace id => page ids */
function offered(?array $actor): array
{
    $out = [];
    foreach (Workspaces::visible('/bp', $actor) as $ws) {
        $out[$ws['id']] = array_column($ws['pages'], 'id');
    }
    return $out;
}

$anon = offered(null);
check('a visitor is offered Home, Events & Calendar and guest sign-up only',
    array_keys($anon) === ['home', 'events', 'visitors'], implode(' | ', array_keys($anon)));
check('and only the public pages in them',
    $anon['home'] === ['home'] && $anon['events'] === ['calendar', 'events'] && $anon['visitors'] === ['signup'],
    json_encode($anon));
check('a visitor is not offered Ministries (that belongs to the church website)', !isset($anon['ministries']));

$memberNav = offered(actor(false, ['view_own_assignments']));
check('a member gets their own pages and the shared ones',
    ($memberNav['home'] ?? []) === ['home', 'my-schedule', 'availability', 'account']
    && isset($memberNav['ministries'], $memberNav['people']), json_encode($memberNav));
check('but no Admin workspace', !isset($memberNav['admin']));
check('and no administrative page anywhere',
    !in_array('records', $memberNav['people'] ?? [], true) && !in_array('manage', $memberNav['ministries'] ?? [], true));

$eventsLeaderNav = offered($eventsLeader);
check('an events leader is offered New event and Calendar settings',
    in_array('new-event', $eventsLeaderNav['events'] ?? [], true) && in_array('calendar-settings', $eventsLeaderNav['events'] ?? [], true));
check('but not Event categories, which is admin-only', !in_array('categories', $eventsLeaderNav['events'] ?? [], true));
check('a ministry leader is offered Manage members & leaders',
    in_array('members', offered($ministryLeader)['ministries'] ?? [], true));

$adminWs = offered($admin);
check('an admin is offered all seven workspaces', count($adminWs) === 7, implode(' | ', array_keys($adminWs)));

// Pages later phases build are placed, but never linked before they exist.
$allHtml = ek_workspace_nav('/bp', $admin, null);
foreach (['/bp/people/history', '/bp/admin/history'] as $href) {
    check('no link to an unbuilt page: ' . $href, !str_contains($allHtml, 'href="' . $href . '"'));
}

// Visitors & RSVPs replaced the Sign-ups & RSVP launcher.
check('an admin is offered Visitors, RSVPs and Access codes',
    ($adminWs['visitors'] ?? []) === ['visitors', 'rsvps', 'access', 'signup'], json_encode($adminWs['visitors'] ?? null));
check('a member is offered only the guest sign-up there', ($memberNav['visitors'] ?? []) === ['signup']);
check('a registration record is in Visitors', (Workspaces::locate('/visitors/12')['page'] ?? '') === 'visitors');
check('RSVPs is its own page, not a registration', (Workspaces::locate('/visitors/rsvps')['page'] ?? '') === 'rsvps');
check('the old launcher is no longer a page', !str_contains(json_encode(Workspaces::all(), JSON_UNESCAPED_SLASHES), '/admin/outreach'));
check('an unbuilt page is still placed in the map', Workspaces::locate('/people/history') === ['workspace' => 'people', 'page' => 'history', 'child' => null]);

// Locating a URL.
check('the home page is Home', Workspaces::locate('/') === ['workspace' => 'home', 'page' => 'home', 'child' => null]);
check('a person record is in Directory', (Workspaces::locate('/people/42')['page'] ?? '') === 'directory');
check('an exact page beats its parent wildcard', (Workspaces::locate('/events/new')['page'] ?? '') === 'new-event');
check('an event is in Events', (Workspaces::locate('/events/12')['page'] ?? '') === 'events');
check('member import is in People & Records, not Backups',
    Workspaces::locate('/admin/maintenance/import') === ['workspace' => 'people', 'page' => 'import', 'child' => null]);
check('a sub-page names its entry and itself',
    Workspaces::locate('/admin/theme') === ['workspace' => 'admin', 'page' => 'appearance', 'child' => 'theme']);
check('an unknown page is nowhere', Workspaces::locate('/docs') === null);

// Rendering: current workspace and page come from the location given.
$nav = ek_workspace_nav('/bp', $admin, Workspaces::locate('/admin/families/edit'));
check('exactly one workspace is current', substr_count($nav, 'class="ek-ws is-current"') === 1);
check('and it is named for assistive tech, not marked by colour alone', str_contains($nav, 'People &amp; Records (current workspace)'));
check('the active page is announced', substr_count($nav, 'aria-current="page"') === 1
    && str_contains($nav, 'href="/bp/admin/families" aria-current="page"'));
check('only the current workspace is expanded', substr_count($nav, '<ul class="ek-ws-pages">') === 1);
check('no location marks nothing', !str_contains(ek_workspace_nav('/bp', $admin, null), 'aria-current'));
check('a visitor on an admin URL is not placed in a workspace they cannot see',
    !str_contains(ek_workspace_nav('/bp', null, Workspaces::locate('/admin/outreach')), 'is-current'));

$tabs = ek_workspace_tabs('/bp', 'admin', 'appearance', $admin, 'theme');
check('workspace tabs mark the entry holding the sub-page', str_contains($tabs, 'aria-current="true">Portal appearance &amp; notices'));
check('and a second row marks the sub-page itself', str_contains($tabs, 'is-sub') && str_contains($tabs, 'aria-current="page">Theme'));
check('a workspace the actor cannot use renders no tabs', ek_workspace_tabs('/bp', 'admin', 'users', $member) === '');
check('page header escapes its text', str_contains(ek_page_header('A & B', '<x>'), 'A &amp; B') && str_contains(ek_page_header('A', '<x>'), '&lt;x&gt;'));

// Every admin page names a section the map knows, so none renders without tabs.
$sections = Workspaces::adminSections();
foreach (array_merge(glob(__DIR__ . '/../../resources/views/admin*.php') ?: [], [__DIR__ . '/../../admin/groups_and_ministries/ministries.php']) as $file) {
    if (preg_match_all("/'activeId'\s*=>\s*'([^']+)'/", (string) file_get_contents($file), $m)) {
        foreach ($m[1] as $id) {
            check('admin section is placed in a workspace: ' . basename($file) . ' → ' . $id, isset($sections[$id]));
        }
    }
}
foreach ($sections as $id => [$wsId, $pageId, $childId]) {
    $found = false;
    foreach (Workspaces::workspace($wsId)['pages'] ?? [] as $page) {
        if ($page['id'] === $pageId) {
            $found = $childId === null || in_array($childId, array_column($page['children'] ?? [], 'id'), true);
        }
    }
    check('section maps to a real page: ' . $id, $found);
}

$shell = (string) file_get_contents(__DIR__ . '/../../resources/views/_portal-shell.php');
check('storage never decides which page is current',
    !preg_match('/localStorage[^\n]*(aria-current|is-current|location)/i', $shell));
check('storage failures never break navigation', substr_count($shell, 'catch(e){return false;}') >= 1 && str_contains($shell, 'catch(e){}'));

$adminShell = (string) file_get_contents(__DIR__ . '/../../resources/views/_admin-shell.php');
check('the admin area no longer draws a sidebar of its own', !str_contains($adminShell, 'admin-sidebar'));
check('admin pages pin their place in the workspace map', str_contains($adminShell, 'Workspaces::setCurrent('));
check('and keep saying plainly when the area is not yours', str_contains($adminShell, 'You do not have access to this area'));

check('CRM people is labelled Member records, not People',
    str_contains($adminShell, "'label' => 'Member records'")
    && str_contains($adminShell, '/admin/people'));
check('ministry membership is labelled Members & leaders',
    str_contains($adminShell, "'label' => 'Members & leaders'"));
check('public ministry visibility is labelled Ministry list',
    str_contains($adminShell, "'label' => 'Ministry list'"));
check('the old Groups & Ministries sidebar label is gone',
    !str_contains($adminShell, "'label' => 'Groups & Ministries'"));

$peopleDir = (string) file_get_contents(__DIR__ . '/../../resources/views/people.php');
check('the People tab says adding a record is Member records',
    str_contains($peopleDir, 'Member records')
    && str_contains($peopleDir, 'not this page'));
$groups = (string) file_get_contents(__DIR__ . '/../../admin/groups_and_ministries/ministries.php');
check('the membership workspace is titled Members & leaders',
    str_contains($groups, "'sectionTitle'       => 'Members & leaders'"));
check('that workspace no longer titles itself Groups & Ministries',
    !str_contains($groups, "'sectionTitle'       => 'Groups & Ministries'"));
$navGuide = (string) file_get_contents(__DIR__ . '/../../resources/views/docs/sections/03-site-navigation.md');
check('the nav guide splits People lookup from Member records',
    str_contains($navGuide, 'Look someone up')
    && str_contains($navGuide, 'Member records'));
$peopleGuide = (string) file_get_contents(__DIR__ . '/../../resources/views/docs/sections/05-people-management.md');
check('the people guide sends add/edit to Member records, not the People tab',
    str_contains($peopleGuide, 'Administration → **Member records**')
    && str_contains($peopleGuide, 'look-up only')
    && !str_contains($peopleGuide, 'People dashboard'));
$leadersGuide = (string) file_get_contents(__DIR__ . '/../../resources/views/docs/sections/07-adding-leaders.md');
check('the leaders guide names Members & leaders, not Groups & Ministries',
    str_contains($leadersGuide, 'Members & leaders')
    && !str_contains($leadersGuide, 'Groups & Ministries'));
$ministriesGuide = (string) file_get_contents(__DIR__ . '/../../resources/views/docs/sections/11-ministries.md');
check('the ministries guide splits Members & leaders from Ministry list',
    str_contains($ministriesGuide, 'Members & leaders')
    && str_contains($ministriesGuide, 'Ministry list')
    && !str_contains($ministriesGuide, 'Groups & Ministries'));
$docsCatalog = (string) file_get_contents(__DIR__ . '/../../resources/views/docs.php');
check('the docs catalog titles the people section Member records',
    str_contains($docsCatalog, "'title' => 'Member records — Add, Edit & Manage'")
    && !str_contains($docsCatalog, "'title' => 'People — Add, Edit & Manage'"));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
