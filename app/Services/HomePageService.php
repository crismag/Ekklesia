<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ActorContext;
use App\Core\Navigation\Workspaces;
use App\Core\PortalPermission;
use App\Providers\PortalServiceProvider;
use DateTimeImmutable;

/**
 * Composes the portal home: a dashboard into the workspaces
 * (docs/design/surfaces.md, "Home (portal dashboard)").
 *
 * Ekklesia is the church's records and operations portal, not its website, so
 * nothing here presents the church. Everything is read through the services
 * that already own it and already apply their rules — event audience, "only
 * your own schedule", ministry scope, admin-only record counts — so Home can
 * never show more than the page it links to.
 *
 * A section is null when this person is not offered it (the view renders
 * nothing), and ['ok' => false] when it is offered but could not be read, so a
 * failing query says so instead of pretending there is nothing to show.
 *
 * This service holds no SQL.
 */
final class HomePageService
{
    public const EVENT_WINDOW_DAYS = 14;
    public const EVENT_LIMIT = 8;
    public const SERVING_LIMIT = 6;
    public const OPEN_ROLE_WINDOW_DAYS = 21;
    /** Each ministry costs one schedule-grid read; a scheduler rarely has more. */
    public const OPEN_ROLE_MINISTRY_LIMIT = 6;
    public const OPEN_ROLE_OCCURRENCE_LIMIT = 3;

    /** One line per workspace saying what it is for. */
    public const WORKSPACE_PURPOSES = [
        'home' => 'Your schedule, availability and account.',
        'people' => 'The directory, member records and households.',
        'ministries' => 'Each ministry’s members, leaders, serving roles and schedule.',
        'events' => 'The church calendar and its events.',
        'serving' => 'Who serves when: schedules, the board, rosters and printables.',
        'visitors' => 'Guest sign-ups and event RSVPs.',
        'admin' => 'Accounts and access, church settings, backups.',
    ];

    /** What each page open to signed-out visitors does, keyed by its label. */
    public const PUBLIC_PAGE_PURPOSES = [
        'Calendar' => 'Month and week views of what is scheduled.',
        'Events' => 'Upcoming events, with times and places.',
        'Guest sign-up' => 'Register as a guest or visitor.',
    ];

    /** @param callable():mixed $fn */
    private function attempt(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function build(?ActorContext $ctx, string $basePath, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $home = self::skeleton($basePath, self::actorArray($ctx));
        if ($ctx === null) {
            return $home;
        }

        $home['notices'] = $this->section(fn (): array => $this->portalNotices($ctx, $today));

        $home['events'] = $this->section(static function () use ($ctx, $today): array {
            $byDate = PortalServiceProvider::makeEventService()
                ->agenda($ctx, $ctx->currentCampusId, 'upcoming', null, 60);

            return self::upcomingEvents($byDate, $today, self::EVENT_WINDOW_DAYS, self::EVENT_LIMIT);
        });

        if ($ctx->personId === null) {
            $home['unlinked'] = true;
        } else {
            if ($ctx->hasPermission(PortalPermission::ViewOwnAssignments)) {
                $home['serving'] = $this->section(static function () use ($ctx, $today): array {
                    $view = PortalServiceProvider::makeScheduleService()->getMySchedule(
                        $ctx,
                        (int) $ctx->personId,
                        $today,
                        $today->modify('+' . self::EVENT_WINDOW_DAYS . ' days'),
                    );

                    return self::servingRows(array_map(static fn ($a): array => $a->toArray(), $view->assignments));
                });
            }
            $home['ministries'] = $this->section(
                static fn (): array => self::myMinistries(PortalServiceProvider::makePersonAdminService()->ministriesFor((int) $ctx->personId)),
            );
        }

        if ($ctx->hasPermission(PortalPermission::ManageSchedules) && $ctx->ministryScopeIds !== []) {
            $home['openRoles'] = $this->section(fn (): array => $this->openRoles($ctx, $basePath, $today));
        }

        if ($ctx->isPortalWideAdmin) {
            $home['records'] = $this->section(static function () use ($ctx): array {
                $people = PortalServiceProvider::makePersonAdminService();
                $households = PortalServiceProvider::makeFamilyAdminService()->stats();

                $registrations = null;
                try {
                    $registrations = (int) (PortalServiceProvider::makeVisitorService()->registrationCounts($ctx)['new'] ?? 0);
                } catch (\Throwable) {
                    // The visitors database may be unavailable; the other counts still stand.
                }

                return [
                    'people' => $people->count([]),
                    'households' => (int) ($households['total'] ?? 0),
                    'withoutCampus' => $people->count(['campus' => PersonAdminService::NO_CAMPUS]),
                    'newRegistrations' => $registrations,
                ];
            });
        }

        return $home;
    }

    /**
     * Home before anything is read: the workspace shortcuts, and every
     * personal section absent.
     *
     * @param ?array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public static function skeleton(string $basePath, ?array $actor): array
    {
        return [
            'signedIn' => $actor !== null,
            'unlinked' => false,
            'workspaces' => self::workspaceCards($basePath, $actor),
            'notices' => null,
            'events' => null,
            'serving' => null,
            'ministries' => null,
            'openRoles' => null,
            'records' => null,
        ];
    }

    /**
     * @param callable():array<mixed> $fn
     * @return array{ok:bool,items:array<mixed>}
     */
    private function section(callable $fn): array
    {
        $items = $this->attempt($fn);

        return is_array($items) ? ['ok' => true, 'items' => $items] : ['ok' => false, 'items' => []];
    }

    /**
     * Short operational messages for portal users.
     *
     * The Portal notices the Admin workspace publishes, for this reader's
     * audience.
     *
     * @return list<array{title:string,body:string,tag:string}>
     */
    private function portalNotices(ActorContext $ctx, DateTimeImmutable $today): array
    {
        $out = [];
        // activeNotices applies the audience: admins-only notices reach admins.
        foreach (PortalServiceProvider::makeAnnouncementSettingsService()->activeNotices($ctx, $today) as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $out[] = ['title' => $title, 'body' => trim((string) ($item['body'] ?? '')), 'tag' => (string) ($item['tag'] ?? '')];
        }

        return $out;
    }

    /**
     * Unfilled roles in the ministries this scheduler is scoped to.
     *
     * @return list<array<string,mixed>>
     */
    private function openRoles(ActorContext $ctx, string $basePath, DateTimeImmutable $today): array
    {
        $names = [];
        foreach (PortalServiceProvider::makeMinistryService()->listAccessibleMinistries($ctx) as $m) {
            $names[(int) $m['ministryId']] = (string) $m['name'];
        }
        $schedules = PortalServiceProvider::makeScheduleService();
        $end = $today->modify('+' . self::OPEN_ROLE_WINDOW_DAYS . ' days');

        $ids = array_values(array_filter(
            $ctx->ministryScopeIds,
            static fn (int $id): bool => isset($names[$id]) && $ctx->canAccessMinistry($id),
        ));
        usort($ids, static fn (int $a, int $b): int => strcasecmp($names[$a], $names[$b]));

        $out = [];
        foreach (array_slice($ids, 0, self::OPEN_ROLE_MINISTRY_LIMIT) as $id) {
            // The editor's own read, with the events it opens with by default,
            // so "open" here means an empty cell there.
            $grid = $this->attempt(static fn () => $schedules->getScheduleGrid($ctx, $id, $today, $end)->toArray());
            if (!is_array($grid)) {
                continue;
            }
            $occurrences = self::openRolesFromGrid($grid, self::OPEN_ROLE_OCCURRENCE_LIMIT);
            if ($occurrences === []) {
                continue;
            }
            $out[] = [
                'ministryId' => $id,
                'ministryName' => $names[$id],
                'href' => rtrim($basePath, '/') . '/schedules?' . http_build_query([
                    'ministry_id' => $id,
                    'start' => $today->format('Y-m-d'),
                    'end' => $end->format('Y-m-d'),
                ]),
                'occurrences' => $occurrences,
            ];
        }

        return $out;
    }

    /**
     * Occurrences in a schedule grid that have roles nobody is assigned to.
     *
     * @param array<string,mixed> $grid ScheduleGrid::toArray()
     * @return list<array{occurrenceId:int,eventTitle:string,startsOn:string,roleCount:int,open:list<string>}>
     */
    public static function openRolesFromGrid(array $grid, int $limit = PHP_INT_MAX): array
    {
        $roles = [];
        foreach (is_array($grid['roles'] ?? null) ? $grid['roles'] : [] as $role) {
            if (is_array($role) && (int) ($role['id'] ?? 0) > 0) {
                $roles[(int) $role['id']] = (string) ($role['name'] ?? '');
            }
        }
        if ($roles === []) {
            return [];
        }
        $filled = [];
        foreach (is_array($grid['assignments'] ?? null) ? $grid['assignments'] : [] as $a) {
            if (!is_array($a)) {
                continue;
            }
            // A row with nobody on it (no person, no external name) is an open
            // slot the editor recorded, not a filled one.
            $someone = (int) ($a['personId'] ?? 0) > 0
                || trim((string) ($a['label'] ?? '')) !== ''
                || trim((string) ($a['displayName'] ?? '')) !== '';
            if ($someone) {
                $filled[(int) ($a['occurrenceId'] ?? 0)][(int) ($a['roleId'] ?? 0)] = true;
            }
        }
        $occurrences = array_values(array_filter(
            is_array($grid['occurrences'] ?? null) ? $grid['occurrences'] : [],
            'is_array',
        ));
        usort($occurrences, static fn (array $x, array $y): int => strcmp((string) ($x['startsOn'] ?? ''), (string) ($y['startsOn'] ?? '')));

        $out = [];
        foreach ($occurrences as $o) {
            $id = (int) ($o['id'] ?? 0);
            $open = [];
            foreach ($roles as $roleId => $name) {
                if (!isset($filled[$id][$roleId])) {
                    $open[] = $name;
                }
            }
            if ($open === []) {
                continue;
            }
            $out[] = [
                'occurrenceId' => $id,
                'eventTitle' => (string) ($o['eventTitle'] ?? ''),
                'startsOn' => (string) ($o['startsOn'] ?? ''),
                'roleCount' => count($roles),
                'open' => $open,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * The next occurrences within the window, from EventService::agenda().
     *
     * @param array<string,list<array<string,mixed>>> $byDate
     * @return list<array{eventId:int,title:string,startsAt:string,location:string,cancelled:bool}>
     */
    public static function upcomingEvents(array $byDate, DateTimeImmutable $today, int $days, int $limit): array
    {
        $from = $today->setTime(0, 0)->format('Y-m-d H:i:s');
        $to = $today->setTime(0, 0)->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
        $out = [];
        foreach ($byDate as $rows) {
            foreach (is_array($rows) ? $rows : [] as $r) {
                $at = (string) ($r['starts_at'] ?? '');
                if ($at < $from || $at >= $to) {
                    continue;
                }
                $out[] = [
                    'eventId' => (int) ($r['event_id'] ?? 0),
                    'title' => (string) ($r['title'] ?? ''),
                    'startsAt' => $at,
                    'location' => (string) ($r['location_name'] ?? $r['host_campus_name'] ?? ''),
                    'cancelled' => (bool) ($r['is_cancelled'] ?? false),
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['startsAt'], $b['startsAt']));

        return array_slice($out, 0, $limit);
    }

    /**
     * @param list<array<string,mixed>> $assignments MyAssignment::toArray()
     * @return list<array{eventId:int,eventTitle:string,roleName:string,ministryName:string,startsOn:string}>
     */
    public static function servingRows(array $assignments): array
    {
        $out = [];
        foreach ($assignments as $a) {
            $out[] = [
                'eventId' => (int) ($a['eventId'] ?? 0),
                'eventTitle' => (string) ($a['eventTitle'] ?? ''),
                'roleName' => (string) ($a['roleName'] ?? ''),
                'ministryName' => (string) ($a['ministryName'] ?? ''),
                'startsOn' => (string) ($a['startsOn'] ?? ''),
            ];
        }
        usort($out, static fn (array $x, array $y): int => strcmp($x['startsOn'], $y['startsOn']));

        return array_slice($out, 0, self::SERVING_LIMIT);
    }

    /**
     * @param list<array<string,mixed>> $rows PersonAdminService::ministriesFor()
     * @return list<array{ministryId:int,name:string,isLeader:bool,roles:string}>
     */
    public static function myMinistries(array $rows): array
    {
        $out = [];
        foreach ($rows as $m) {
            $id = (int) ($m['ministry_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'ministryId' => $id,
                'name' => (string) ($m['name'] ?? ''),
                'isLeader' => (bool) ($m['is_leader'] ?? false),
                'roles' => (string) ($m['roles'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * A card per workspace this person can use, from the workspace map, so
     * Home offers exactly what the sidebar offers.
     *
     * @param ?array<string,mixed> $actor
     * @return list<array{id:string,label:string,icon:string,purpose:string,href:string,pages:list<array{label:string,href:string,external:bool}>}>
     */
    public static function workspaceCards(string $basePath, ?array $actor): array
    {
        $cards = [];
        foreach (Workspaces::visible($basePath, $actor) as $ws) {
            if ($ws['id'] === 'home') {
                continue;   // Home is this page; its other pages are in the sidebar.
            }
            $pages = [];
            foreach ($ws['pages'] as $page) {
                $pages[] = ['label' => (string) $page['label'], 'href' => (string) $page['href'], 'external' => !empty($page['external'])];
            }
            if ($pages === []) {
                continue;
            }
            $cards[] = [
                'id' => (string) $ws['id'],
                'label' => (string) $ws['label'],
                'icon' => (string) $ws['icon'],
                'purpose' => self::WORKSPACE_PURPOSES[$ws['id']] ?? '',
                'href' => $pages[0]['href'],
                'pages' => $pages,
            ];
        }

        return $cards;
    }

    /** @return ?array<string,mixed> the shape Workspaces::visible() and the shell read */
    public static function actorArray(?ActorContext $ctx): ?array
    {
        if ($ctx === null) {
            return null;
        }

        return [
            'actorId' => $ctx->actorId,
            'personId' => $ctx->personId,
            'displayName' => $ctx->displayName,
            'isPortalWideAdmin' => $ctx->isPortalWideAdmin,
            'permissions' => array_map(static fn (PortalPermission $p): string => $p->value, $ctx->permissions),
            'ministryScopeIds' => $ctx->ministryScopeIds,
            'currentCampusId' => $ctx->currentCampusId,
            'currentCampusIds' => $ctx->currentCampusIds,
        ];
    }
}
