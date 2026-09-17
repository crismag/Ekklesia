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

check('overflow helper treats Events as already a tab',
    portal_nav_has_path([['href' => '/bp/events']], '/bp', '/events'));
check('overflow helper does not match a different path',
    !portal_nav_has_path([['href' => '/bp/events']], '/bp', '/docs'));
check('overflow helper normalises a trailing slash',
    portal_nav_has_path([['href' => '/bp/docs/']], '/bp', '/docs'));


// ---------------------------------------------------------------------------
// Which section you are in is a fact about the URL.
//
// The sidebar marked the active link and opened its group, but nothing said
// which *area* you were in once a group was collapsed — which is exactly when
// it is worth saying. And nothing remembered the groups an administrator had
// deliberately opened or shut.
//
// The dividing line these tests hold: the route decides where you are, browser
// storage only remembers what the user chose to expand.
echo "\nCurrent section, and what may be remembered\n";
// The sidebar is permission-filtered, so it needs an actor or it renders empty
// — which is itself the correct behaviour and worth stating.
$adminActor = ['isPortalWideAdmin' => true, 'permissions' => []];
// Assert on the rendered <aside>, not the whole string: the behaviour script
// mentions .admin-side-group as a selector, which is not navigation.
$signedOut = admin_sidebar_html('/bp', 'events', null);
preg_match('/<aside[^>]*>(.*?)<\/aside>/s', $signedOut, $asideOut);
check('a signed-out visitor gets no admin navigation at all',
    trim($asideOut[1] ?? 'x') === '', $asideOut[1] ?? '(no aside)');

$sidebar = admin_sidebar_html('/bp', 'events', $adminActor);

check('the group holding the active page is marked as current',
    substr_count($sidebar, 'admin-side-group is-current') === 1,
    (string) substr_count($sidebar, 'admin-side-group is-current'));
check('exactly one group is open on arrival',
    substr_count($sidebar, '<details class="admin-side-group is-current" open') === 1);
check('and it is named for assistive tech, not marked by colour alone',
    str_contains($sidebar, '(current section)'));
check('every group carries a stable key to be remembered by',
    substr_count($sidebar, 'data-group="') === substr_count($sidebar, '<details class="admin-side-group'));
check('the active link is still marked', str_contains($sidebar, 'admin-side-item is-active'));
check('and announced', str_contains($sidebar, 'aria-current="page"'));

// A different page must move the marker, not add a second one.
$elsewhere = admin_sidebar_html('/bp', 'users', $adminActor);
check('a different page marks a different group',
    substr_count($elsewhere, 'admin-side-group is-current') === 1
    && $elsewhere !== $sidebar);

// An unknown or empty active id must not mark anything, rather than guessing.
$none = admin_sidebar_html('/bp', '', $adminActor);
check('no active page marks no section',
    substr_count($none, 'admin-side-group is-current') === 0);
check('and opens none of them',
    substr_count($none, '<details class="admin-side-group" open') === 0);

$shell = file_get_contents(__DIR__ . '/../../resources/views/_admin-shell.php');
check('storage is never consulted for which page is active',
    !preg_match('/localStorage[^\n]*(active|current|activeId)/i', $shell));
check('the current group is forced open regardless of what was remembered',
    str_contains($shell, 'if(g.dataset.current==="1"){g.open=true;return;}'));
// Chromium fires "toggle" for a server-rendered <details open> during parse.
// Recording that turned storage into a list of every section ever visited,
// which then stayed open on every later page.
check('a toggle that changed nothing is not recorded',
    str_contains($shell, 'if(g.open===last[g.dataset.group])return;'));
check('storage failures never break navigation',
    substr_count($shell, 'catch(e){}') >= 1);

check('group keys are url-safe and stable',
    admin_side_group_key('People & families') === 'people-families',
    admin_side_group_key('People & families'));
check('and collapse punctuation rather than emitting empties',
    admin_side_group_key('Calendar & events') === 'calendar-events');

check('CRM people is labelled Member records, not People',
    str_contains($shell, "'label' => 'Member records'")
    && str_contains($shell, '/admin/people'));
check('ministry membership is labelled Members & leaders',
    str_contains($shell, "'label' => 'Members & leaders'"));
check('public ministry visibility is labelled Ministry list',
    str_contains($shell, "'label' => 'Ministry list'"));
check('the old Groups & Ministries sidebar label is gone',
    !str_contains($shell, "'label' => 'Groups & Ministries'"));

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
