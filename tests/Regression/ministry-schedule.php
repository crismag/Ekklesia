#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The ministry schedule: consolidation, layout, and what reaches the page.
 *
 * The printed sheet is the one output nobody can correct after the fact. It
 * goes on a noticeboard and stays there for a week, so the things worth testing
 * are the ones a proof-read would not catch: two scheduling systems disagreeing
 * about the same ministry, a name that never made it out of the builder, a
 * layout that comes out differently the second time it is printed.
 *
 * Nothing here touches a database. That is the point of the builder boundary —
 * the consolidation is testable precisely because the template does not query.
 */

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Contracts\ScheduleRepository;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Documents\MinistryScheduleDocument;
use App\Exceptions\PermissionDenied;
use App\Services\Ministry\ColumnBalancer;
use App\Services\Ministry\MinistryScheduleDocumentBuilder;
use App\Services\ScheduleService;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}
function throws(string $class, callable $fn, string $label): void
{
    try {
        $fn();
        check($label, false, 'no exception');
    } catch (Throwable $e) {
        check($label, $e instanceof $class, $e::class);
    }
}

/** Stands in for the assignments → serving_roles → ministries source. */
final class FakeScheduleRepository implements ScheduleRepository
{
    /** @var list<array<string,mixed>> */
    public array $board = [];

    public function fetchScheduleBoard(DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], array $ministryIds = []): array
    {
        return $this->board;
    }

    public function fetchScheduleGrid(int $ministryId, DateTimeImmutable $start, DateTimeImmutable $end, array $campusIds = [], ?array $eventIds = null): array { return []; }
    public function listEligibleSchedulingEvents(array $campusIds = []): array { return []; }
    public function listSchedulableEventsInRange(array $campusIds, DateTimeImmutable $start, DateTimeImmutable $end): array { return []; }
    public function listDefaultSchedulingEventIds(array $campusIds = []): array { return []; }
    public function fetchMySchedule(int $personId, DateTimeImmutable $start, DateTimeImmutable $end): array { return []; }
    public function saveAssignments(\App\DTO\Schedules\AssignmentBatchCommand $command): array { return ['saved' => true, 'assignment_count' => 0]; }
    public function __call(string $name, array $args): mixed { return []; }
}

$row = static fn (string $ministry, string $role, string $person, int $mid = 1): array => [
    'occurrence_id' => 9, 'event_id' => 5, 'event_title' => 'Sunday Worship Service',
    'starts_on' => '2026-08-23T09:00:00+00:00', 'ends_on' => '2026-08-23T11:00:00+00:00',
    'ministry_id' => $mid, 'ministry_name' => $ministry,
    'serving_role_id' => 1, 'role_name' => $role,
    'person_id' => null, 'person_name' => $person,
];

$admin = new ActorContext(
    actorId: 1, personId: 1, displayName: 'Admin',
    permissions: PortalPermission::forRole('admin'), ministryScopeIds: [], isPortalWideAdmin: true,
);
$date = new DateTimeImmutable('2026-08-23');

echo "Consolidating what the church actually recorded\n";
$repo = new FakeScheduleRepository();
$repo->board = [
    $row('Facilities', 'Main', 'Bro Aldo'),
    $row('Facilities', 'Main', 'Bro Teng'),
    $row('Facilities', 'Set-Up', 'Bro Ram'),
    $row('Altar', 'Opening', 'Bro Ramil', 2),
];
$builder = new MinistryScheduleDocumentBuilder(new ScheduleService($repo));
$doc = $builder->build($admin, $date);

check('the ministries become sections', count($doc->sections) === 2, (string) count($doc->sections));
check('and are ordered the same way every time — alphabetically',
    array_column($doc->sections, 'title') === ['Altar', 'Facilities'],
    json_encode(array_column($doc->sections, 'title')));

$facilities = $doc->sections[1];
check('roles become assignments, not one per person',
    count($facilities['assignments']) === 2, (string) count($facilities['assignments']));
check('two people on one role are one assignment with two people',
    count($facilities['assignments'][0]['people']) === 2);
check('and their names survive',
    array_column($facilities['assignments'][0]['people'], 'name') === ['Bro Aldo', 'Bro Teng']);
check('the service title comes from the event', $doc->serviceTitle === 'Sunday Worship Service');
check('the date is carried both ways', $doc->serviceDate === '2026-08-23'
    && $doc->displayDate === 'AUGUST 23, 2026', $doc->displayDate);

echo "\nCounting\n";
check('ministries counted', $doc->stats['ministries'] === 2);
check('assignments counted', $doc->stats['assignments'] === 3, (string) $doc->stats['assignments']);
check('volunteers counted once each', $doc->stats['volunteers'] === 4, (string) $doc->stats['volunteers']);

$dupRepo = new FakeScheduleRepository();
$dupRepo->board = [$row('Altar', 'Opening', 'Bro Ram'), $row('Victuals', 'Server', 'Bro Ram', 3)];
$dupDoc = (new MinistryScheduleDocumentBuilder(new ScheduleService($dupRepo)))->build($admin, $date);
check('one person serving twice is counted as one volunteer',
    $dupDoc->stats['volunteers'] === 1, (string) $dupDoc->stats['volunteers']);

echo "\nWhat is worth saying before printing\n";
// A church runs on people who do two things. This is information, never an
// error, and never a reason to stop somebody printing.
$multi = $dupDoc->warningsOfLevel(MinistryScheduleDocument::LEVEL_INFO);
check('serving twice is reported', count($multi) === 1);
check('as information, not as a problem', $multi[0]['kind'] === 'multiple_assignments');
check('and names who', str_contains($multi[0]['message'], 'Bro Ram'), $multi[0]['message']);
check('it is not a warning', $dupDoc->warningsOfLevel(MinistryScheduleDocument::LEVEL_WARN) === []);

$openRepo = new FakeScheduleRepository();
$openRepo->board = [$row('Altar', 'Pulpit', ''), $row('Altar', 'Opening', 'Bro Ram')];
$openDoc = (new MinistryScheduleDocumentBuilder(new ScheduleService($openRepo)))->build($admin, $date);
$warn = $openDoc->warningsOfLevel(MinistryScheduleDocument::LEVEL_WARN);
check('a role with nobody on it is a warning', count($warn) === 1 && $warn[0]['kind'] === 'unfilled_role',
    json_encode($warn));
check('and it names the ministry and the role',
    str_contains($warn[0]['message'], 'Altar') && str_contains($warn[0]['message'], 'Pulpit'),
    $warn[0]['message']);
// It stays on the sheet: an unfilled duty is the most useful thing a schedule
// can say before Sunday, so it is never quietly dropped.
$roles = array_column($openDoc->sections[0]['assignments'], 'role');
check('the unfilled role still appears on the page', in_array('Pulpit', $roles, true), json_encode($roles));

echo "\nA role whose ministry was deleted\n";
$orphan = new FakeScheduleRepository();
$orphan->board = [$row('', 'Runner', 'Bro Cris', 0)];
$orphanDoc = (new MinistryScheduleDocumentBuilder(new ScheduleService($orphan)))->build($admin, $date);
check('its people are not silently lost',
    $orphanDoc->stats['volunteers'] === 1 && count($orphanDoc->sections) === 1);
check('under a plain heading rather than an empty one',
    $orphanDoc->sections[0]['title'] === 'Unassigned ministry', $orphanDoc->sections[0]['title']);

echo "\nAuthorisation\n";
// Volunteer names are member data. The public board endpoint is a deliberate
// separate thing and this is not it.
$member = new ActorContext(
    actorId: 2, personId: 2, displayName: 'Member',
    permissions: PortalPermission::forRole('member'), ministryScopeIds: [],
);
throws(PermissionDenied::class,
    static fn () => (new MinistryScheduleDocumentBuilder(new ScheduleService($repo)))->build($member, $date),
    'an ordinary member cannot build a ministry schedule');
$leader = new ActorContext(
    actorId: 3, personId: 3, displayName: 'Leader',
    permissions: [PortalPermission::ViewMinistrySchedule], ministryScopeIds: [],
);
$leaderDoc = (new MinistryScheduleDocumentBuilder(new ScheduleService($repo)))->build($leader, $date);
check('somebody who may read ministry schedules can', $leaderDoc->stats['ministries'] === 2);

echo "\nColumn placement is decided here, not by the browser\n";
$s = static fn (string $t, int $w): array => ['title' => $t, 'weight' => $w, 'assignments' => []];
$cols = ColumnBalancer::distribute([$s('a', 10), $s('b', 10), $s('c', 10), $s('d', 5)], 3);
check('three columns come back', count($cols) === 3);
check('the fourth card goes to the shortest column',
    count($cols[0]) === 2 && $cols[0][1]['title'] === 'd',
    json_encode(array_map(static fn ($c) => array_column($c, 'title'), $cols)));
// A document printed twice must come out the same both times.
$again = ColumnBalancer::distribute([$s('a', 10), $s('b', 10), $s('c', 10), $s('d', 5)], 3);
check('the same document places identically every time', $cols === $again);
check('a tie goes left, so nothing depends on iteration order',
    ColumnBalancer::distribute([$s('x', 5)], 3)[0][0]['title'] === 'x');
check('no sections yields three empty columns',
    ColumnBalancer::distribute([], 3) === [[], [], []]);
check('every section is placed somewhere — none are dropped',
    array_sum(array_map('count', ColumnBalancer::distribute(array_map(
        static fn (int $i): array => $s('m' . $i, $i), range(1, 17)), 3))) === 17);

echo "\nCapacity is reported, never used to shrink the type\n";
check('the reference density fits one sheet',
    !ColumnBalancer::overflows(ColumnBalancer::distribute([$s('a', 12), $s('b', 12), $s('c', 12)], 3)));
check('far more than that does not',
    ColumnBalancer::overflows(ColumnBalancer::distribute([$s('a', 200)], 3)));
check('a card taller than a column on its own is flagged so it may break',
    ColumnBalancer::isTall($s('huge', ColumnBalancer::COLUMN_CAPACITY + 1)));
check('an ordinary card is not',
    !ColumnBalancer::isTall($s('ordinary', 12)));

echo "\nThe document can be written down\n";
$array = $doc->toArray();
check('it declares what it is', $array['documentType'] === 'ministry_schedule');
check('and round-trips through JSON without loss',
    json_decode(json_encode($array), true) === $array);
check('service, sections, warnings and stats are all in it',
    isset($array['service'], $array['sections'], $array['warnings'], $array['stats']));

echo "\nThe template renders, and never queries\n";
$src = file_get_contents(__DIR__ . '/../../resources/views/print/ministry-schedule/classic.php');
foreach (['PDO', 'query(', 'prepare(', 'Repository', 'Service', 'SELECT'] as $forbidden) {
    check("the template contains no {$forbidden}", !str_contains($src, $forbidden));
}

// Render it for real, from a document alone.
$fixtures = require __DIR__ . '/../Fixtures/ministry-schedules.php';
foreach ($fixtures as $name => $case) {
    $document = new MinistryScheduleDocument(
        title: 'MINISTRY SCHEDULE', serviceDate: $case['date'], serviceTitle: 'Sunday Worship Service',
        displayDate: strtoupper((new DateTimeImmutable($case['date']))->format('F j, Y')),
        sections: $case['sections'],
    );
    $columns = ColumnBalancer::distribute($document->sections, 3);
    $overflows = ColumnBalancer::overflows($columns);
    ob_start();
    require __DIR__ . '/../../resources/views/print/ministry-schedule/classic.php';
    $html = (string) ob_get_clean();

    check("{$name} renders", $html !== '' && str_contains($html, 'ms-sheet'));
    $cards = substr_count($html, 'ms-card-head');
    check("{$name}: every ministry reaches the page",
        $cards === count($case['sections']), "$cards of " . count($case['sections']));

    // Every person named in the document must appear in the output. Silent
    // omission is the failure mode that matters: nobody proof-reads a roster
    // against the database.
    $missing = [];
    foreach ($case['sections'] as $section) {
        foreach ($section['assignments'] as $a) {
            foreach ($a['people'] as $p) {
                if (!str_contains($html, htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'))) {
                    $missing[] = $p['name'];
                }
            }
        }
    }
    check("{$name}: nobody is silently dropped", $missing === [], implode(', ', array_slice($missing, 0, 3)));
    check("{$name}: no screen furniture", !preg_match('/<button|<nav|<form/i', $html));
}
check('an oversized schedule says so on the sheet itself',
    (static function () use ($fixtures): bool {
        $case = $fixtures['oversized'];
        $document = new MinistryScheduleDocument('MINISTRY SCHEDULE', $case['date'], '', 'X', $case['sections']);
        $columns = ColumnBalancer::distribute($document->sections, 3);
        $overflows = ColumnBalancer::overflows($columns);
        ob_start();
        require __DIR__ . '/../../resources/views/print/ministry-schedule/classic.php';

        return str_contains((string) ob_get_clean(), 'continues on the next page');
    })());

echo "\nEscaping\n";
// Names come out of the member database, which is not a reason to trust them.
$evil = new MinistryScheduleDocument(
    title: 'MINISTRY SCHEDULE', serviceDate: '2026-08-23',
    serviceTitle: '<script>alert(1)</script>', displayDate: 'AUGUST 23, 2026',
    sections: [[
        'title' => '<img src=x onerror=alert(1)>',
        'ministryId' => null, 'source' => 'assignments', 'weight' => 4,
        'assignments' => [[
            'role' => '"><b>role', 'note' => "<i>note", 
            'people' => [['name' => '<script>alert("x")</script>', 'personId' => null]],
        ]],
    ]],
);
$document = $evil;
$columns = ColumnBalancer::distribute($document->sections, 3);
$overflows = false;
ob_start();
require __DIR__ . '/../../resources/views/print/ministry-schedule/classic.php';
$out = (string) ob_get_clean();
check('a name containing a script tag is escaped', !str_contains($out, '<script>'));
check('a ministry title cannot inject an element', !str_contains($out, '<img src=x'));
check('a role cannot break out of its attribute or tag', !str_contains($out, '"><b>role'));
check('but the text is still there to read', str_contains($out, '&lt;script&gt;'));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
