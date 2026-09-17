#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Saved calendar views, and who is allowed to change one.
 *
 * The point of a saved view is that one administrator configures the Sunday
 * wall calendar once and everybody else selects it and prints. That only works
 * if the canonical configuration is safe from the people reusing it — so the
 * tests that matter most here are the refusals.
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

use App\Contracts\SavedViewRepository;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use App\Services\Calendar\PrintConfig;
use App\Services\Calendar\SavedViewService;

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
        check($label, $e instanceof $class, $e::class . ': ' . $e->getMessage());
    }
}

/** In-memory stand-in for the table; the service owns the rules, not the SQL. */
final class FakeViewAdapter implements SavedViewRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    private int $next = 1;

    public function listFor(int $userId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $r): bool => $r['ownerId'] === $userId || $r['visibility'] === 'shared',
        ));
    }

    public function find(int $viewId): ?array
    {
        return $this->rows[$viewId] ?? null;
    }

    public function create(string $name, int $ownerId, string $visibility, int $version, array $config): int
    {
        $id = $this->next++;
        $this->rows[$id] = ['id' => $id, 'name' => $name, 'ownerId' => $ownerId,
            'visibility' => $visibility, 'configVersion' => $version, 'config' => $config,
            'updatedAt' => '2026-08-27 00:00:00'];

        return $id;
    }

    public function update(int $viewId, string $name, string $visibility, int $version, array $config): bool
    {
        if (!isset($this->rows[$viewId])) {
            return false;
        }
        $this->rows[$viewId] = ['id' => $viewId, 'name' => $name,
            'ownerId' => $this->rows[$viewId]['ownerId'], 'visibility' => $visibility,
            'configVersion' => $version, 'config' => $config, 'updatedAt' => '2026-08-27 00:00:00'];

        return true;
    }

    public function delete(int $viewId): bool
    {
        unset($this->rows[$viewId]);

        return true;
    }
}

$actor = static fn (int $id, bool $admin = false): ActorContext => new ActorContext(
    actorId: $id, personId: $id, displayName: 'User ' . $id,
    permissions: PortalPermission::forRole($admin ? 'admin' : 'leader'),
    ministryScopeIds: [], isPortalWideAdmin: $admin,
);

$alice = $actor(1);
$bob = $actor(2);
$admin = $actor(9, true);
$anon = $actor(0);

$repo = new FakeViewAdapter();
$svc = new SavedViewService($repo);
$config = PrintConfig::fromArray(['layout' => 'monthly', 'date' => ['mode' => 'this-month']]);

echo "Saving and reopening\n";
$id = $svc->create($alice, 'Scarborough Monthly Ministry', 'private', $config);
check('a view is saved', $id > 0);
$row = $svc->open($alice, $id);
check('its owner can open it', $row !== null && $row['name'] === 'Scarborough Monthly Ministry');
check('and may edit it', $row['canEdit'] === true);
check('and it is marked as theirs', $row['mine'] === true);
check('the configuration comes back through the current model',
    $svc->configOf($row)->get('date.mode') === 'this-month');

// A view stores the *mode*. Reopening it four months later must move with the
// calendar rather than reprinting August.
[$s, $e] = $svc->configOf($row)->resolveRange(new DateTimeImmutable('2026-12-10'));
check('and resolves to the month it is reopened in',
    $s->format('Y-m-d') === '2026-12-01' && $e->format('Y-m-d') === '2026-12-31',
    $s->format('Y-m-d'));

echo "\nA private view is nobody else's business\n";
// Reported as missing, not forbidden: "exists but not yours" is a way to
// enumerate ids and an answer nobody needs.
check('somebody else cannot open it', $svc->open($bob, $id) === null);
check('and it is not in their list',
    !in_array($id, array_column($svc->listFor($bob), 'id'), true));
throws(PermissionDenied::class,
    static fn () => $svc->update($bob, $id, 'Hijacked', 'private', $config),
    'nor overwrite it');
check('and the original is untouched', $repo->rows[$id]['name'] === 'Scarborough Monthly Ministry');

echo "\nA shared view is reusable, not editable\n";
$sharedId = $svc->create($admin, 'Sunday Ministry Wall Calendar', 'shared', $config);
$asBob = $svc->open($bob, $sharedId);
check('anybody may open it', $asBob !== null);
check('but it is not theirs', $asBob['mine'] === false);
check('and they may not edit it', $asBob['canEdit'] === false);
throws(PermissionDenied::class,
    static fn () => $svc->update($bob, $sharedId, 'Bob rewrote this', 'shared', $config),
    'saving over a colleague\'s shared view is refused');
check('so the canonical configuration survives',
    $repo->rows[$sharedId]['name'] === 'Sunday Ministry Wall Calendar');
throws(PermissionDenied::class,
    static fn () => $svc->delete($bob, $sharedId),
    'and they cannot delete it either');

// The way out is always to take a copy.
$copyId = $svc->create($bob, 'Sunday Ministry Wall Calendar', 'private', $config);
check('but they can save it as their own', $copyId > 0 && $copyId !== $sharedId);
check('which belongs to them', $svc->open($bob, $copyId)['canEdit'] === true);
check('and does not touch the shared one', $repo->rows[$sharedId]['ownerId'] === 9);

echo "\nSharing is deliberate\n";
throws(PermissionDenied::class,
    static fn () => $svc->create($alice, 'Everyone should see this', 'shared', $config),
    'an ordinary user cannot publish a view to the whole portal');
check('an administrator can', $svc->create($admin, 'House style', 'shared', $config) > 0);
check('an unknown visibility falls back to private, never to shared',
    $repo->rows[$svc->create($alice, 'Odd scope', 'everyone', $config)]['visibility'] === 'private');

// An owner can still edit their own; an admin can edit anyone's, because they
// already administer every other shared setting in this portal.
$svc->update($admin, $id, 'Renamed by an admin', 'private', $config);
check('a portal administrator can correct a view whose owner has gone',
    $repo->rows[$id]['name'] === 'Renamed by an admin');
check('and it still belongs to its owner', $repo->rows[$id]['ownerId'] === 1);

echo "\nSigning in, and naming\n";
throws(PermissionDenied::class,
    static fn () => $svc->create($anon, 'Anonymous', 'private', $config),
    'a signed-out visitor cannot save a view');
check('nor edit one', $svc->mayEdit($anon, $repo->rows[$sharedId]) === false);
throws(ValidationFailed::class,
    static fn () => $svc->create($alice, '   ', 'private', $config),
    'a view needs a name');
throws(ValidationFailed::class,
    static fn () => $svc->create($alice, 'Renamed by an admin', 'private', $config),
    'and two of the owner\'s views cannot share one');
check('but another owner may use the same name',
    $svc->create($bob, 'Renamed by an admin', 'private', $config) > 0);
throws(ValidationFailed::class,
    static fn () => $svc->update($alice, 99999, 'Ghost', 'private', $config),
    'updating a view that no longer exists says so');

echo "\nScreen keys ride on the same row as print\n";
$screenCfg = PrintConfig::fromArray([
    'content' => ['sources' => ['birthdays']],
    'screen' => ['view' => 'agenda', 'left' => 'open'],
]);
$screenId = $svc->create($alice, 'Agenda with sources', 'private', $screenCfg);
$opened = $svc->configOf($svc->open($alice, $screenId));
check('reopening keeps the screen view', $svc->screenOf($opened)['view'] === 'agenda');
check('and the left rail', $svc->screenOf($opened)['left'] === 'open');
check('a print-only row reports empty screen state rather than inventing month',
    $svc->screenOf($svc->configOf($row))['view'] === '');

echo "\nA corrupted row opens as defaults rather than taking the screen down\n";
$repo->rows[$id]['config'] = [];
check('an empty configuration still resolves',
    $svc->configOf($repo->rows[$id])->get('appearance.density') === 'standard');

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
