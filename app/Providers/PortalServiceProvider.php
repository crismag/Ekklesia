<?php

declare(strict_types=1);

namespace App\Providers;

use App\Adapters\ChurchCRM\ChurchCrmCalendarAdapter;
use App\Adapters\ChurchCRM\ChurchCrmMinistryAdapter;
use App\Adapters\ChurchCRM\ChurchCrmEventAdapter;
use App\Adapters\ChurchCRM\ChurchCrmScheduleAdapter;
use App\Adapters\Portal\PortalAuthAdapter;
use App\Adapters\Portal\PortalAvailabilityAdapter;
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
use App\Core\Database\ChurchCrmConnection;
use App\Core\Database\PortalConnection;
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
     *                         ChurchCrmScheduleAdapter  (ChurchCRM SQL only)
     *                              ↑
     *                         PDO from ChurchCrmConnection
     *
     * Swapping data source = swap the adapter binding only.
     *
     * @return array<class-string, class-string>
     */
    public function bindings(): array
    {
        return [
            \App\Contracts\EventTypeRepository::class => \App\Repositories\DefaultEventTypeRepository::class,
            \App\Contracts\EventTypeAdapter::class    => \App\Adapters\ChurchCRM\ChurchCrmEventTypeAdapter::class,
            CalendarRepository::class     => DefaultCalendarRepository::class,
            CalendarAdapter::class        => ChurchCrmCalendarAdapter::class,
            ScheduleRepository::class     => DefaultScheduleRepository::class,
            ScheduleAdapter::class        => ChurchCrmScheduleAdapter::class,
            MinistryRepository::class     => DefaultMinistryRepository::class,
            MinistryAdapter::class        => ChurchCrmMinistryAdapter::class,
            AuthRepository::class         => DefaultAuthRepository::class,
            AuthAdapter::class            => PortalAuthAdapter::class,
            AvailabilityRepository::class => DefaultAvailabilityRepository::class,
            AvailabilityAdapter::class    => PortalAvailabilityAdapter::class,
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
        $pdo = ChurchCrmConnection::get();
        $adapter = new ChurchCrmScheduleAdapter($pdo);
        $repository = new DefaultScheduleRepository($adapter);
        return new ScheduleService($repository);
    }

    /**
     * Standalone-scaffold factory for MinistryService.
     * Wires:  ChurchCrmConnection → ChurchCrmMinistryAdapter → DefaultMinistryRepository → MinistryService
     */
    public static function makeMinistryService(): MinistryService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $pdo = ChurchCrmConnection::get();
        $adapter = new ChurchCrmMinistryAdapter($pdo);
        $repository = new DefaultMinistryRepository($adapter);
        return new MinistryService($repository);
    }

    /**
     * Standalone-scaffold factory for EventService.
     * Wires:  ChurchCrmConnection → ChurchCrmEventAdapter → DefaultEventRepository → EventService
     */
    /** Saved calendar views: the print studio's reusable configurations. */
    public static function makeSavedViewService(): \App\Services\Calendar\SavedViewService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');

        return new \App\Services\Calendar\SavedViewService(
            new \App\Adapters\Portal\PortalSavedViewAdapter(),
        );
    }

    public static function makeEventService(): EventService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $pdo = ChurchCrmConnection::get();
        $adapter = new ChurchCrmEventAdapter($pdo);
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
        $adapter = new \App\Adapters\ChurchCRM\ChurchCrmEventTypeAdapter(ChurchCrmConnection::get());
        $repository = new \App\Repositories\DefaultEventTypeRepository($adapter);

        return new \App\Services\EventTypeService($repository);
    }

    /**
     * Campus administration (CRUD over church_campus). Direct-PDO, no adapter —
     * self-contained so it survives ChurchCRM decommissioning.
     */
    public static function makeCampusAdminService(): \App\Services\CampusAdminService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\CampusAdminService(ChurchCrmConnection::get());
    }

    /**
     * Church identity/contact/location info, stored in portal-owned JSON
     * (config/church-info.json). No ChurchCRM dependency.
     */
    public static function makeChurchInfoService(): \App\Services\ChurchInfoService
    {
        return new \App\Services\ChurchInfoService(dirname(__DIR__, 2) . '/config/church-info.json');
    }

    /**
     * People administration (person_per + family_fam + person_custom, shared DB).
     * Direct-PDO, no ChurchCRM dependency.
     */
    public static function makePersonAdminService(): \App\Services\PersonAdminService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\PersonAdminService(ChurchCrmConnection::get());
    }

    public static function makeMaintenanceBackupService(): \App\Services\MaintenanceBackupService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\MaintenanceBackupService(new \App\Services\PrivateArchiveStore());
    }

    public static function makeMemberCampusImportService(): \App\Services\MemberCampusImportService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $db = ChurchCrmConnection::get();

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
                new \App\Adapters\ChurchCRM\ChurchCrmMinistryAdapter($db)
            );
            $portalAuth = new \App\Adapters\Portal\PortalAuthAdapter(\App\Core\Database\PortalConnection::get());
        } catch (\Throwable) {
            $assigner = null;
            $ministryRepo = null;
            $portalAuth = null;
        }

        return new \App\Services\MemberCampusImportService(
            $db,
            new \App\Repositories\DefaultMemberImportRepository(
                new \App\Adapters\ChurchCRM\ChurchCrmMemberImportAdapter($db)
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

    /** Family administration (family_fam + members). Direct-PDO, no ChurchCRM dependency. */
    public static function makeFamilyAdminService(): \App\Services\FamilyAdminService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\FamilyAdminService(ChurchCrmConnection::get());
    }

    /** Option manager (list_lst). Direct-PDO, no ChurchCRM dependency. */
    public static function makeOptionAdminService(): \App\Services\OptionAdminService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\OptionAdminService(ChurchCrmConnection::get());
    }

    /** Related-families map (portal-owned JSON). No database columns. */
    public static function makeRelatedFamiliesService(): \App\Services\RelatedFamiliesService
    {
        return new \App\Services\RelatedFamiliesService(dirname(__DIR__, 2) . '/config/related-families.json');
    }

    /**
     * System user administration (portal_users + role assignments). Direct-PDO on
     * the portal DB, using the same PasswordHasher as login. No ChurchCRM dependency.
     */
    public static function makeSystemUserService(): \App\Services\SystemUserService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        return new \App\Services\SystemUserService(PortalConnection::get(), new \App\Core\Security\PasswordHasher());
    }

    /**
     * Standalone-scaffold factory for CalendarService.
     * Wires:  ChurchCrmConnection → ChurchCrmCalendarAdapter → DefaultCalendarRepository → CalendarService
     */
    public static function makeCalendarService(): CalendarService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $pdo = ChurchCrmConnection::get();
        $adapter = new ChurchCrmCalendarAdapter($pdo);
        $repository = new DefaultCalendarRepository($adapter);
        return new CalendarService($repository);
    }

    /**
     * Standalone-scaffold factory for AvailabilityService.
     * Wires:  PortalConnection → PortalAvailabilityAdapter → DefaultAvailabilityRepository → AvailabilityService
     */
    public static function makeAvailabilityService(): AvailabilityService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $pdo = PortalConnection::get();
        $adapter = new PortalAvailabilityAdapter($pdo);
        $repository = new DefaultAvailabilityRepository($adapter);
        return new AvailabilityService($repository);
    }

    /**
     * Standalone-scaffold factory for RosterScheduleService.
     * Wires:  Portal+ChurchCRM PDO → RosterScheduleService.
     * Direct-PDO (no adapter) since rosters live entirely in the portal DB
     * and there's no plausible alternate backend to swap.
     */
    public static function makeRosterScheduleService(): \App\Services\RosterScheduleService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $portal    = PortalConnection::get();
        $churchcrm = ChurchCrmConnection::get();
        return new \App\Services\RosterScheduleService($portal, $churchcrm);
    }

    /**
     * Standalone-scaffold factory for AuthService.
     * Wires:  PortalConnection → PortalAuthAdapter → DefaultAuthRepository → AuthService
     */
    public static function makeAuthService(): AuthService
    {
        EnvLoader::loadOnce(dirname(__DIR__, 2) . '/.env');
        $portalPdo = PortalConnection::get();
        $authAdapter = new PortalAuthAdapter($portalPdo);
        $authRepository = new DefaultAuthRepository($authAdapter);
        $churchCrmPdo = ChurchCrmConnection::get();
        $ministryAdapter = new ChurchCrmMinistryAdapter($churchCrmPdo);
        $ministryRepository = new DefaultMinistryRepository($ministryAdapter);
        // ChurchCRM identity resolver lets AuthService accept email or mobile
        // as a username and provision portal_users automatically when the
        // submitted password matches the default ChristLike#<FNI><LNI>#2026!
        // formula. Falls back to null gracefully if ChurchCRM is unavailable.
        $identityResolver = $churchCrmPdo !== null
            ? new \App\Services\ChurchCrmIdentityResolver($churchCrmPdo)
            : null;
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
