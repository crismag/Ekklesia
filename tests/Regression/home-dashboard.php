<?php

declare(strict_types=1);

/**
 * The portal home (docs/design/surfaces.md, "Home (portal dashboard)").
 *
 * Ekklesia is the church's portal, not its website. Signed out, Home is sign-in
 * and the functions open to everyone, with no church presentation. Signed in,
 * each section appears only for someone offered it.
 *
 * The view is rendered from composed data, so no database is needed.
 */

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

use App\Services\HomePageService;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
}

/** @param array<string,mixed> $home */
function render_home(?array $actor, array $home): string
{
    $basePath = '';
    $campusSelector = ['campuses' => [], 'defaultCampusId' => null];
    $_SERVER['REQUEST_URI'] = '/';
    ob_start();
    require dirname(__DIR__, 2) . '/resources/views/index.php';

    return (string) ob_get_clean();
}

/** The page's own content, without the shell's navigation. */
function main_of(string $html): string
{
    $start = strpos($html, '<main id="portal-main"');
    $end = strpos($html, '</main>');

    return $start !== false && $end !== false ? substr($html, $start, $end - $start) : '';
}

$member = ['actorId' => 2, 'personId' => 20, 'displayName' => 'Ruth Member', 'isPortalWideAdmin' => false,
    'permissions' => ['view_ministry_dashboard', 'view_own_assignments', 'manage_own_availability'], 'ministryScopeIds' => []];
$admin = ['actorId' => 1, 'personId' => 10, 'displayName' => 'Ada Admin', 'isPortalWideAdmin' => true,
    'permissions' => ['manage_schedules', 'view_own_assignments'], 'ministryScopeIds' => []];

echo "Signed out\n";
$anon = main_of(render_home(null, HomePageService::skeleton('', null)));
check('says what the portal is', str_contains($anon, 'portal for its records, ministries, calendar and serving'));
check('offers sign-in', str_contains($anon, 'href="/login"'));
foreach (['/calendar' => 'calendar', '/events' => 'events', '/people_signup/' => 'guest sign-up'] as $href => $what) {
    check("offers the {$what}", str_contains($anon, 'href="' . $href . '"'));
}
check('explains that RSVP is per event', str_contains($anon, 'RSVP'));
foreach ([
    'hero' => 'class="hero',
    'banner rotator' => 'heroTitle',
    'ministry marketing' => 'Choose the Ministry',
    'ministry tiles' => 'ministry-tile',
    'visit card' => 'Visit ',
    'phone link' => 'tel:',
    'email link' => 'mailto:',
    'announcements feed' => 'Announcements',
] as $what => $needle) {
    check("no {$what}", !str_contains($anon, $needle));
}
foreach (['Records at a glance', 'My serving', 'Open roles', 'My ministries', 'Workspaces'] as $section) {
    check("no signed-in section: {$section}", !str_contains($anon, $section));
}
check('offers no member or admin pages', !str_contains($anon, '/admin') && !str_contains($anon, '/my-schedule'));

echo "Workspace shortcuts follow the workspace map\n";
$ids = static fn (?array $actor): array => array_column(HomePageService::workspaceCards('', $actor), 'id');
check('a visitor gets calendar/events and the guest sign-up only', $ids(null) === ['events', 'visitors']);
check('a member is not offered Admin', !in_array('admin', $ids($member), true));
check('an administrator is offered Admin', in_array('admin', $ids($admin), true));
$memberHrefs = array_merge(...array_map(static fn (array $c): array => array_column($c['pages'], 'href'), HomePageService::workspaceCards('', $member)));
check('a member is not offered Member records', !in_array('/admin/people', $memberHrefs, true));

echo "Signed in: sections only with access\n";
$memberHome = HomePageService::skeleton('', $member);
$memberHome['events'] = ['ok' => true, 'items' => []];
$memberHome['serving'] = ['ok' => true, 'items' => []];
$memberHome['ministries'] = ['ok' => true, 'items' => [['ministryId' => 4, 'name' => 'Victuals', 'isLeader' => false, 'roles' => '']]];
$m = main_of(render_home($member, $memberHome));
check('member sees their serving with an empty state and a next step',
    str_contains($m, 'Nothing scheduled for you in the next two weeks.') && str_contains($m, 'href="/my-schedule"'));
check('member sees upcoming events with a calendar link', str_contains($m, 'Coming up') && str_contains($m, 'href="/calendar"'));
check('member ministries link to each ministry workspace', str_contains($m, 'href="/ministries/4"'));
check('member sees no records at a glance', !str_contains($m, 'Records at a glance'));
check('member sees no open roles', !str_contains($m, 'Open roles'));
check('member sees workspace shortcuts', str_contains($m, 'Workspaces'));

$adminHome = HomePageService::skeleton('', $admin);
$adminHome['records'] = ['ok' => true, 'items' => ['people' => 308, 'households' => 135, 'withoutCampus' => 67]];
$adminHome['openRoles'] = ['ok' => true, 'items' => [[
    'ministryId' => 4, 'ministryName' => 'Victuals', 'href' => '/schedules?ministry_id=4&start=2026-09-17&end=2026-10-08',
    'occurrences' => [['occurrenceId' => 1, 'eventTitle' => 'Sunday Service', 'startsOn' => '2026-09-20T08:00:00+00:00', 'roleCount' => 3, 'open' => ['Porter']]],
]]];
$adminHome['notices'] = ['ok' => true, 'items' => [['title' => 'Directory review <Sunday>', 'body' => 'Check your household.', 'tag' => 'Church']]];
$a = main_of(render_home($admin, $adminHome));
check('admin sees records at a glance', str_contains($a, 'Records at a glance') && str_contains($a, '308'));
check('open roles link to the schedule editor', str_contains($a, 'href="/schedules?ministry_id=4&amp;start=2026-09-17&amp;end=2026-10-08"'));
check('portal notices are shown, escaped', str_contains($a, 'Directory review &lt;Sunday&gt;'));
$failedHome = HomePageService::skeleton('', $member);
$failedHome['serving'] = ['ok' => false, 'items' => []];
check('a section that failed to load says so instead of looking empty',
    str_contains(main_of(render_home($member, $failedHome)), 'could not be loaded'));

echo "Open roles from a schedule grid\n";
$grid = [
    'roles' => [['id' => 1, 'name' => 'Porter'], ['id' => 2, 'name' => 'Server']],
    'occurrences' => [
        ['id' => 11, 'eventTitle' => 'Late', 'startsOn' => '2026-09-27T08:00:00+00:00'],
        ['id' => 10, 'eventTitle' => 'Early', 'startsOn' => '2026-09-20T08:00:00+00:00'],
        ['id' => 12, 'eventTitle' => 'Full', 'startsOn' => '2026-09-21T08:00:00+00:00'],
    ],
    'assignments' => [
        ['occurrenceId' => 10, 'roleId' => 1, 'personId' => 5, 'label' => '', 'displayName' => 'A'],
        ['occurrenceId' => 11, 'roleId' => 1, 'personId' => 0, 'label' => '', 'displayName' => ''],
        ['occurrenceId' => 11, 'roleId' => 2, 'personId' => 0, 'label' => 'Guest singer', 'displayName' => 'Guest singer'],
        ['occurrenceId' => 12, 'roleId' => 1, 'personId' => 6, 'label' => '', 'displayName' => 'B'],
        ['occurrenceId' => 12, 'roleId' => 2, 'personId' => 7, 'label' => '', 'displayName' => 'C'],
    ],
];
$open = HomePageService::openRolesFromGrid($grid);
check('fully staffed occurrences are left out', count($open) === 2);
check('in date order', $open[0]['eventTitle'] === 'Early' && $open[1]['eventTitle'] === 'Late');
check('lists the roles nobody holds', $open[0]['open'] === ['Server'] && $open[0]['roleCount'] === 2);
check('an empty recorded slot is open; an external name fills one', $open[1]['open'] === ['Porter']);
check('limit applies', count(HomePageService::openRolesFromGrid($grid, 1)) === 1);
check('a ministry with no roles has nothing open', HomePageService::openRolesFromGrid(['roles' => []] + $grid) === []);

echo "Upcoming events window\n";
$today = new DateTimeImmutable('2026-09-17');
$rows = HomePageService::upcomingEvents([
    '2026-09-16' => [['event_id' => 1, 'title' => 'Yesterday', 'starts_at' => '2026-09-16 10:00:00']],
    '2026-09-20' => [['event_id' => 3, 'title' => 'Sunday', 'starts_at' => '2026-09-20 08:00:00', 'is_cancelled' => true]],
    '2026-09-17' => [['event_id' => 2, 'title' => 'Today', 'starts_at' => '2026-09-17 19:00:00', 'location_name' => 'Hall']],
    '2026-10-01' => [['event_id' => 4, 'title' => 'Too far', 'starts_at' => '2026-10-01 08:00:00']],
], $today, 14, 8);
check('keeps today through the next two weeks, in order', array_column($rows, 'title') === ['Today', 'Sunday']);
check('carries cancellation and location', $rows[1]['cancelled'] === true && $rows[0]['location'] === 'Hall');

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
