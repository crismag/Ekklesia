<?php

declare(strict_types=1);

namespace App\Providers;

use App\Adapters\Sql\SqlCalendarAdapter;
use App\Adapters\Sql\SqlMinistryAdapter;
use App\Adapters\Sql\SqlEventAdapter;
use App\Adapters\Sql\SqlScheduleAdapter;
use App\Adapters\Sql\SqlAuthAdapter;
use App\Adapters\Sql\SqlAvailabilityAdapter;
use App\Contracts\AuthAdapter;
use App\Contracts\AuthRepository;
use App\Contracts\CalendarAdapter;
use App\Contracts\CalendarRepository;
use App\Contracts\AvailabilityAdapter;
use App\Contracts\AvailabilityRepository;
use App\Contracts\MinistryAdapter;
use App\Contracts\MinistryRepository;
use App\Contracts\ScheduleAdapter;
use App\Contracts\ScheduleRepository;
use App\Core\Config\EnvLoader;
use App\Core\Database\MembersConnection;
use App\Core\Security\PasswordHasher;
use App\Repositories\DefaultAuthRepository;
use App\Repositories\DefaultCalendarRepository;
use App\Repositories\DefaultAvailabilityRepository;
use App\Repositories\DefaultMinistryRepository;
use App\Repositories\DefaultScheduleRepository;
use App\Services\AuthService;
use App\Services\CalendarService;
use App\Services\AvailabilityService;
use App\Services\MinistryService;
use App\Services\ScheduleService;
use App\Services\EventService;

final class PortalServiceProvider
{
    /**
     * Service-container bindings for the portal.
     *
     * Layered architecture:
     *   ScheduleService      ←  ScheduleRepository contract
     *                              ↑
     *                         DefaultScheduleRepository  (source-agnostic)
     *                              ↑
     *                         ScheduleAdapter contract
     *                              ↑
     *                         SqlScheduleAdapter  (SQL only)
     *                              ↑
     *                         PDO from MembersConnection
     *
     * Swapping data source = swap the adapter binding only.
     *
     * @return array<class-string, class-string>
     */
    public function bindings(): array
    {
        return [
            \App\Contracts\EventTypeRepository::class => \App\Repositories\DefaultEventTypeRepository::class,
            \App\Contracts\EventTypeAdapter::class    => \App\Adapters\Sql\SqlEventTypeAdapter::class,
            CalendarRepository::class     => DefaultCalendarRepository::class,
            CalendarAdapter::class        => SqlCalendarAdapter::class,
            ScheduleRepository::class     => DefaultScheduleRepository::class,
            ScheduleAdapter::class        => SqlScheduleAdapter::class,
            MinistryRepository::class     => DefaultMinistryRepository::class,
            MinistryAdapter::class        => SqlMinistryAdapter::class,
            AuthRepository::class         => DefaultAuthRepository::class,
            AuthAdapter::class            => SqlAuthAdapter::class,
            AvailabilityRepository::class => DefaultAvailabilityRepository::class,
            AvailabilityAdapter::class    => SqlAvailabilityAdapter::class,
        ];
    }

    public function register(): void
    {
        // In a full Laravel install this method binds contracts via the
        // container. The bindings() map above is the single source of truth.
    }

    /**
     * Standalone-scaffold factory used by the public/index.php bootstrap and
     * by CLI smoke checks. Wires the full chain by hand:
     *   PDO  →  Adapter  →  Repository  →  Service.
     *
     * Once `composer install` is run, this is replaced by Laravel's container
     * resolving via bindings().
     */
    public static function makeScheduleService(): ScheduleService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $pdo = MembersConnection::get();
        $adapter = new SqlScheduleAdapter($pdo);
        $repository = new DefaultScheduleRepository($adapter);
        return new ScheduleService($repository);
    }

    /**
     * Standalone-scaffold factory for MinistryService.
     * Wires:  MembersConnection → SqlMinistryAdapter → DefaultMinistryRepository → MinistryService
     */
    public static function makeMinistryService(): MinistryService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $pdo = MembersConnection::get();
        $adapter = new SqlMinistryAdapter($pdo);
        $repository = new DefaultMinistryRepository($adapter);
        return new MinistryService($repository);
    }

    /**
     * Standalone-scaffold factory for EventService.
     * Wires:  MembersConnection → SqlEventAdapter → DefaultEventRepository → EventService
     */
    /** Saved calendar views: the print studio's reusable configurations. */
    public static function makeSavedViewService(): \App\Services\Calendar\SavedViewService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');

        return new \App\Services\Calendar\SavedViewService(
            new \App\Adapters\Sql\SqlSavedViewAdapter(),
        );
    }

    public static function makeEventService(): EventService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $pdo = MembersConnection::get();
        $adapter = new SqlEventAdapter($pdo);
        $repository = new \App\Repositories\DefaultEventRepository($adapter);

        // Ministry names come from the portal database for display on the
        // events list; failure to build it must not stop events loading.
        $ministries = null;
        try {
            $ministries = self::makeMinistryService();
        } catch (\Throwable) {
            $ministries = null;
        }

        // Event types decide who may read an event. As with ministries, a
        // failure here must not stop events loading: the type check is skipped
        // and the adapter's default-type fallback still applies.
        $eventTypes = null;
        try { $eventTypes = self::makeEventTypeService(); } catch (\Throwable) { $eventTypes = null; }

        return new EventService($repository, $ministries, $eventTypes);
    }

    /**
     * Event types — the categories that drive calendar layers and decide who
     * may read an event.
     *
     * Full chain (adapter -> repository -> service) rather than the direct-PDO
     * shape used by the other admin services: those predate the SQL boundary
     * rule and are grandfathered in BoundaryTest's shrink-only debt list. New
     * domain persistence does not get added to that list.
     */
    public static function makeEventTypeService(): \App\Services\EventTypeService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $adapter = new \App\Adapters\Sql\SqlEventTypeAdapter(MembersConnection::get());
        $repository = new \App\Repositories\DefaultEventTypeRepository($adapter);

        return new \App\Services\EventTypeService($repository);
    }

    /**
     * Campus administration (CRUD over campuses). Direct-PDO, no adapter.
     */
    public static function makeCampusAdminService(): \App\Services\CampusAdminService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\CampusAdminService(MembersConnection::get());
    }

    /**
     * Church identity/contact/location info, stored in portal-owned JSON
     * (config/church-info.json).
     */
    public static function makeChurchInfoService(): \App\Services\ChurchInfoService
    {
        return new \App\Services\ChurchInfoService(dirname(__DIR__, 2) . '/config/church-info.json');
    }

    /**
     * People administration (people + households + option lists, member DB).
     * Direct-PDO.
     */
    public static function makePersonAdminService(): \App\Services\PersonAdminService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\PersonAdminService(MembersConnection::get());
    }

    /**
     * Visitors & RSVPs. Two databases: visitors (SQLite) and members (MySQL);
     * promoted people are created through PersonAdminService on the same
     * member connection, so the promotion's member writes share a transaction.
     * The access-code prefixes come from the standalone modules' own config,
     * which the greeters' pages read too.
     */
    public static function makeVisitorService(): \App\Services\VisitorService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $root = dirname(__DIR__, 2);
        $prefix = static function (string $relative) use ($root): string {
            $file = $root . '/' . $relative;
            $config = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

            return (string) ($config['admin_access']['prefix'] ?? 'ChristLikeness');
        };
        $members = MembersConnection::get();

        return new \App\Services\VisitorService(
            new \App\Repositories\DefaultVisitorRepository(new \App\Adapters\Sql\SqlVisitorAdapter(\App\Core\Database\VisitorsConnection::get())),
            new \App\Repositories\DefaultVisitorMemberRepository(new \App\Adapters\Sql\SqlVisitorMemberAdapter($members)),
            new \App\Services\Visitors\PersonEditorVisitorPersonCreator(new \App\Services\PersonAdminService($members)),
            [
                'signup' => $prefix('people_signup/config/signup.config.json'),
                'rsvp' => $prefix('events_rsvp/config/rsvp.config.json'),
            ],
        );
    }

    public static function makeMaintenanceBackupService(): \App\Services\MaintenanceBackupService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\MaintenanceBackupService(new \App\Services\PrivateArchiveStore());
    }

    public static function makeMemberCampusImportService(): \App\Services\MemberCampusImportService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $db = MembersConnection::get();

        // Ministry assignment: the workbook's ministry cell becomes memberships.
        // Built defensively — if any part is unavailable the import still runs
        // and simply leaves memberships alone.
        $assigner = null;
        $ministryRepo = null;
        $portalAuth = null;
        $nameMap = null;
        try {
            $nameMap = self::makeMinistryNameMap();
            $assigner = new \App\Services\MemberMinistryAssigner(
                \App\Services\MinistryCatalog::fromFile(dirname(__DIR__, 2) . '/config/ministry-catalog.json'),
                static fn (): array => self::makeMinistryService()->listMinistriesPublic(null),
                $nameMap
            );
            $ministryRepo = new \App\Repositories\DefaultMinistryRepository(
                new \App\Adapters\Sql\SqlMinistryAdapter($db)
            );
            $portalAuth = new \App\Adapters\Sql\SqlAuthAdapter(\App\Core\Database\MembersConnection::get());
        } catch (\Throwable) {
            $assigner = null;
            $ministryRepo = null;
            $portalAuth = null;
        }

        return new \App\Services\MemberCampusImportService(
            $db,
            new \App\Repositories\DefaultMemberImportRepository(
                new \App\Adapters\Sql\SqlMemberImportAdapter($db)
            ),
            new \App\Services\PersonAdminService($db),
            new \App\Services\MemberWorkbookParser(),
            new \App\Services\MemberImportPlanner(),
            new \App\Services\MemberSheetMerger(),
            new \App\Services\MemberImportDeduper(),
            $assigner,
            $ministryRepo,
            $portalAuth,
            $nameMap ?? null
        );
    }

    /**
     * What the workbook calls a ministry, and what an administrator said it
     * meant. Shared by the import (which records what it saw) and the admin
     * page (which is where the decisions get made).
     */
    public static function makeMinistryNameMap(): \App\Services\MinistryNameMap
    {
        return \App\Services\MinistryNameMap::fromFile(
            dirname(__DIR__, 2) . '/config/ministry-name-map.json'
        );
    }

    /** Family administration (households + members). Direct-PDO. */
    public static function makeFamilyAdminService(): \App\Services\FamilyAdminService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\FamilyAdminService(MembersConnection::get());
    }

    /** Option manager (membership_statuses, household_roles, member_types). Direct-PDO. */
    public static function makeOptionAdminService(): \App\Services\OptionAdminService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\OptionAdminService(MembersConnection::get());
    }

    /** Related families (household_links). */
    public static function makeRelatedFamiliesService(): \App\Services\RelatedFamiliesService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\RelatedFamiliesService(
            new \App\Adapters\Sql\SqlHouseholdLinkAdapter(MembersConnection::get())
        );
    }

    /**
     * System user administration (user_accounts + role assignments). Direct-PDO on
     * the member database, using the same PasswordHasher as login.
     */
    public static function makeSystemUserService(): \App\Services\SystemUserService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\SystemUserService(MembersConnection::get(), new \App\Core\Security\PasswordHasher());
    }

    /**
     * Standalone-scaffold factory for CalendarService.
     * Wires:  MembersConnection → SqlCalendarAdapter → DefaultCalendarRepository → CalendarService
     */
    public static function makeCalendarService(): CalendarService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $pdo = MembersConnection::get();
        $adapter = new SqlCalendarAdapter($pdo);
        $repository = new DefaultCalendarRepository($adapter);
        return new CalendarService($repository);
    }

    /**
     * Standalone-scaffold factory for AvailabilityService.
     * Wires:  MembersConnection → SqlAvailabilityAdapter → DefaultAvailabilityRepository → AvailabilityService
     */
    public static function makeAvailabilityService(): AvailabilityService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $pdo = MembersConnection::get();
        $adapter = new SqlAvailabilityAdapter($pdo);
        $repository = new DefaultAvailabilityRepository($adapter);
        return new AvailabilityService($repository);
    }

    /**
     * Standalone-scaffold factory for RosterScheduleService.
     * Wires:  MembersConnection → RosterScheduleService.
     * Direct-PDO (no adapter) since rosters live entirely in the member
     * database and there's no plausible alternate backend to swap.
     */
    public static function makeRosterScheduleService(): \App\Services\RosterScheduleService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\RosterScheduleService(MembersConnection::get());
    }

    /**
     * Standalone-scaffold factory for AuthService.
     * Wires:  MembersConnection → SqlAuthAdapter → DefaultAuthRepository → AuthService
     */
    public static function makeAuthService(): AuthService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $membersPdo = MembersConnection::get();
        $authAdapter = new SqlAuthAdapter($membersPdo);
        $authRepository = new DefaultAuthRepository($authAdapter);
        $ministryAdapter = new SqlMinistryAdapter($membersPdo);
        $ministryRepository = new DefaultMinistryRepository($ministryAdapter);
        // The person identity resolver lets AuthService accept email or mobile
        // as a username, and offer a first login (default ChristLike#<FNI><LNI>#2026!
        // password) to the people with that contact, for the user to choose.
        $identityResolver = new \App\Services\PersonIdentityResolver($membersPdo);
        return new AuthService(
            $authRepository,
            $ministryRepository,
            new PasswordHasher(),
            60 * 60 * 12,
            $identityResolver,
        );
    }

    /**
     * Standalone-scaffold factory for PortalRequestContext, wired to AuthService
     * so HTTP requests carrying a session token resolve into a real ActorContext.
     */
    public static function makeRequestContext(): \App\Http\Requests\PortalRequestContext
    {
        return new \App\Http\Requests\PortalRequestContext(self::makeAuthService());
    }

    /**
     * Standalone-scaffold factory for HeroSettingsService.
     * Persists to config/hero.json — read by the dashboard hero rotator,
     * written by /admin/hero (portal-admin only).
     */
    public static function makeHeroSettingsService(): \App\Services\HeroSettingsService
    {
        return new \App\Services\HeroSettingsService(dirname(__DIR__, 2) . '/config/hero.json');
    }

    public static function makeAnnouncementSettingsService(): \App\Services\AnnouncementSettingsService
    {
        return new \App\Services\AnnouncementSettingsService(dirname(__DIR__, 2) . '/config/announcements.json');
    }

    public static function makeHomePageService(): \App\Services\HomePageService
    {
        return new \App\Services\HomePageService();
    }

    public static function makeThemeSettingsService(): \App\Services\ThemeSettingsService
    {
        return new \App\Services\ThemeSettingsService(dirname(__DIR__, 2) . '/config/theme.json');
    }

    public static function makeChromeSettingsService(): \App\Services\ChromeSettingsService
    {
        return new \App\Services\ChromeSettingsService(dirname(__DIR__, 2) . '/config/chrome.json');
    }

    public static function makePeopleSettingsService(): \App\Services\PeopleSettingsService
    {
        return new \App\Services\PeopleSettingsService(dirname(__DIR__, 2) . '/config/people.json');
    }

    public static function makeMinistriesSettingsService(): \App\Services\MinistriesSettingsService
    {
        return new \App\Services\MinistriesSettingsService(dirname(__DIR__, 2) . '/config/ministries.json');
    }
}
