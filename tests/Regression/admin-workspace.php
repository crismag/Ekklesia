<?php

declare(strict_types=1);

/**
 * The Admin workspace's rules that do not need a database:
 *
 *  - Activity history is for portal-wide administrators, refuses a bad date
 *    range instead of quietly showing everything, and pages safely;
 *  - Users & access counts and filters logins the same way everywhere;
 *  - a portal notice reaches only its audience, and older notices keep the
 *    audience they had (everyone).
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use App\Contracts\ActivityHistoryRepository;
use App\Core\ActorContext;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use App\Services\ActivityHistoryService;
use App\Services\AnnouncementSettingsService;
use App\Services\LoginDirectory;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : " ({$detail})");
}

function actorFor(bool $admin): ActorContext
{
    return new ActorContext(actorId: $admin ? 1 : 2, personId: null, displayName: 'x', permissions: [], ministryScopeIds: [], isPortalWideAdmin: $admin);
}

echo "Activity history\n";

final class FakeHistory implements ActivityHistoryRepository
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function __construct(private int $rows) {}

    public function search(array $filters, int $limit, int $offset): array
    {
        $this->calls[] = ['search', $filters, $limit, $offset];

        return array_map(static fn (int $i): array => ['id' => $i], range(1, max(0, min($limit, $this->rows - $offset))) ?: []);
    }

    public function count(array $filters): int
    {
        $this->calls[] = ['count', $filters];

        return $this->rows;
    }

    public function facets(): array
    {
        return ['actions' => ['person.updated'], 'targetTypes' => ['person'], 'accounts' => []];
    }
}

$repo = new FakeHistory(120);
$history = new ActivityHistoryService($repo);
try {
    $history->page(actorFor(false), []);
    check('a member cannot read activity history', false);
} catch (PermissionDenied) {
    check('a member cannot read activity history', $repo->calls === [], 'nothing is read before refusing');
}

$page = $history->page(actorFor(true), ['page' => '3']);
check('an administrator gets a page of 50', $page['perPage'] === 50 && $page['pages'] === 3 && $page['page'] === 3);
check('page 3 starts after the first 100 entries', end($repo->calls)[3] === 100);
$page = $history->page(actorFor(true), ['page' => '99']);
check('a page past the end shows the last page, not an empty one', $page['page'] === 3);

$history->page(actorFor(true), ['target_id' => '17']);
check('a record id without its kind of record is ignored', !isset(end($repo->calls)[1]['targetId']));
$history->page(actorFor(true), ['target_type' => 'user_account', 'target_id' => '17', 'account' => '4', 'action' => 'x; DROP']);
$filters = end($repo->calls)[1];
check('filters reach the repository', ($filters['targetType'] ?? '') === 'user_account' && ($filters['targetId'] ?? '') === '17' && ($filters['accountId'] ?? 0) === 4);
check('an action that is not a plain name is dropped', !isset($filters['action']));

foreach ([
    ['from' => '2026-02-30'],
    ['to' => 'yesterday'],
    ['from' => '2026-09-10', 'to' => '2026-09-01'],
] as $bad) {
    try {
        $history->page(actorFor(true), $bad);
        check('refuses ' . json_encode($bad), false);
    } catch (ValidationFailed) {
        check('refuses ' . json_encode($bad) . ' instead of showing everything', true);
    }
}
check('a same-day range is fine', ActivityHistoryService::normalise(['from' => '2026-09-01', 'to' => '2026-09-01'])['to'] === '2026-09-01');
check('actions read as words', ActivityHistoryService::describeAction('user_account.role.add') === 'Added a role'
    && ActivityHistoryService::describeAction('something.new') === 'something.new');

echo "\nUsers & access\n";

$users = [
    ['id' => 1, 'email' => 'admin@x.org', 'display_name' => 'Pat Admin', 'person_name' => 'Patricia Cruz', 'person_id' => 9, 'is_active' => true, 'must_change_password' => false, 'last_login_at' => '2026-09-01 10:00:00',
     'roles' => [['role' => 'admin', 'campus_id' => null, 'ministry_id' => null]]],
    ['id' => 2, 'email' => 'lead@x.org', 'display_name' => '', 'person_name' => '', 'person_id' => null, 'is_active' => true, 'must_change_password' => true, 'last_login_at' => null,
     'roles' => [['role' => 'leader', 'campus_id' => null, 'ministry_id' => 4], ['role' => 'admin', 'campus_id' => 2, 'ministry_id' => null]]],
    ['id' => 3, 'email' => 'old@x.org', 'display_name' => 'Old', 'person_name' => 'Juan Dela Cruz', 'person_id' => 5, 'is_active' => false, 'must_change_password' => true, 'last_login_at' => '2025-01-01 10:00:00',
     'roles' => []],
];
$counts = LoginDirectory::counts($users);
check('counts active and inactive logins', $counts['active'] === 2 && $counts['inactive'] === 1 && $counts['total'] === 3);
check('a campus admin is not a portal-wide admin', $counts['portalAdmins'] === 1);
check('an inactive login is not counted as needing a password change', $counts['mustChange'] === 1);
check('counts never signed in and unlinked', $counts['never'] === 1 && $counts['unlinked'] === 1);
check('search matches the person the login belongs to', array_column(LoginDirectory::filter($users, 'cruz', '', ''), 'id') === [1, 3]);
check('role filter matches any scope', array_column(LoginDirectory::filter($users, '', 'admin', ''), 'id') === [1, 2]);
check('portal-wide admin filter excludes scoped admins', array_column(LoginDirectory::filter($users, '', 'portal-admin', ''), 'id') === [1]);
check('filters combine', array_column(LoginDirectory::filter($users, 'x.org', 'leader', 'never'), 'id') === [2]);

echo "\nPortal notices\n";

$file = sys_get_temp_dir() . '/portal-notices-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($file, json_encode(['items' => [
    ['id' => 'legacy', 'title' => 'Old note', 'body' => 'From before audiences.', 'tag' => 'Church', 'published' => true, 'startsOn' => '', 'endsOn' => ''],
]]));
$notices = new AnnouncementSettingsService($file);
check('a notice from before audiences keeps showing to everyone', ($notices->load()['items'][0]['audience'] ?? '') === 'public'
    && count($notices->activeNotices(null)) === 1);

$notices->save(['items' => [
    ['id' => 'pub', 'title' => 'Public', 'body' => 'b', 'audience' => 'public', 'published' => true],
    ['id' => 'mem', 'title' => 'Members', 'body' => 'b', 'audience' => 'signed-in', 'published' => true],
    ['id' => 'adm', 'title' => 'Admins', 'body' => 'b', 'audience' => 'admins', 'published' => true],
    ['id' => 'odd', 'title' => 'Odd', 'body' => 'b', 'audience' => 'everyone!', 'published' => true],
    ['id' => 'draft', 'title' => 'Draft', 'body' => 'b', 'audience' => 'public', 'published' => false],
    ['id' => 'later', 'title' => 'Later', 'body' => 'b', 'audience' => 'public', 'published' => true, 'startsOn' => '2099-01-01'],
]]);
$ids = static fn (array $items): array => array_column($items, 'id');
check('a visitor sees public notices only', $ids($notices->activeNotices(null)) === ['pub']);
check('publishedNow() is the visitor view', $ids($notices->publishedNow()) === ['pub']);
check('a signed-in member also sees signed-in notices', $ids($notices->activeNotices(actorFor(false))) === ['pub', 'mem']);
check('the portal actor array works the same way', $ids($notices->activeNotices(['actorId' => 2, 'isPortalWideAdmin' => false])) === ['pub', 'mem']);
check('an administrator sees administrator notices too', $ids($notices->activeNotices(actorFor(true))) === ['pub', 'mem', 'adm', 'odd']);
check('an unknown audience is narrowed to administrators', ($notices->load()['items'][3]['audience'] ?? '') === 'admins');

$notices->appendDrafts([['title' => 'From the banner', 'body' => 'Carried over.']]);
$last = $notices->load()['items'][6] ?? [];
check('banner messages arrive as unpublished drafts for signed-in users',
    ($last['title'] ?? '') === 'From the banner' && ($last['published'] ?? true) === false && ($last['audience'] ?? '') === 'signed-in');
@unlink($file);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
