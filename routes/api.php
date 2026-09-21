<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\RosterScheduleController;
use App\Http\Controllers\Api\HeroSettingsController;
use App\Http\Controllers\Api\AnnouncementSettingsController;
use App\Http\Controllers\Api\ChromeSettingsController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\PeopleController;
use App\Http\Controllers\Api\MinistryRosterController;
use App\Http\Controllers\Api\MinistryController;
use App\Http\Controllers\Api\MyScheduleController;
use App\Http\Controllers\Api\ScheduleController;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Providers\PortalServiceProvider;

/**
 * API route map. The standalone-scaffold dispatcher (public/index.php) reads
 * "<METHOD> <PATH>" keys and invokes the closure with the merged request array.
 * Each closure builds its controller via PortalServiceProvider factories so
 * routes know nothing about wiring details.
 *
 * In a full Laravel install these become Route::get/post() calls; the closure
 * bodies stay identical.
 */
return [
    'POST /api/login' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->login($req),

    'POST /api/login/choose' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->choose($req),

    // "Email me a sign-in link". Answers the same way whether or not the
    // address has an account here, so the form cannot be used to ask who does.
    'POST /api/login/link' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->requestSignInLink($req),

    'POST /api/logout' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->logout($req),

    // Mandatory first-login password change. Distinct from /api/account/password
    // which is the routine "change my password" surface — kept separate so the
    // login page can post here without requiring the full account profile bundle.
    'POST /api/auth/password' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->changePassword($req),

    // Roster Schedules — leader/admin managed posted schedules (not bound to
    // a single ministry, multi-person assignments per slot, optional roles).
    // Mirrors the schedule editor's permission posture: portal admins +
    // anyone holding manage_schedules can manage; rosters with no ministry
    // pinned are open to any leader.
    'GET /api/rosters' => fn (array $req) => (new RosterScheduleController(
        PortalServiceProvider::makeRosterScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->listRosters($req),

    'GET /api/rosters/people-pool' => fn (array $req) => (new RosterScheduleController(
        PortalServiceProvider::makeRosterScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->peoplePool($req),

    'GET /api/rosters/{id}' => fn (array $req) => (new RosterScheduleController(
        PortalServiceProvider::makeRosterScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->loadRoster($req),

    'POST /api/rosters' => fn (array $req) => (new RosterScheduleController(
        PortalServiceProvider::makeRosterScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->createRoster($req),

    'POST /api/rosters/{id}' => fn (array $req) => (new RosterScheduleController(
        PortalServiceProvider::makeRosterScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->updateRoster($req),

    'DELETE /api/rosters/{id}' => fn (array $req) => (new RosterScheduleController(
        PortalServiceProvider::makeRosterScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->deleteRoster($req),

    'GET /api/me' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->me($req),

    'GET /api/account' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->account($req),

    'POST /api/account/profile' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->updateProfile($req),

    'POST /api/account/password' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->updatePassword($req),

    'GET /api/portal-access' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->portalAccess($req),

    'POST /api/portal-access' => fn (array $req) => (new AuthController(
        PortalServiceProvider::makeAuthService(),
        PortalServiceProvider::makeRequestContext(),
    ))->provisionPortalAccess($req),

    'GET /api/my-schedule' => fn (array $req) => (new MyScheduleController(
        PortalServiceProvider::makeScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->index($req),

    // Saved calendar views. One table for print and screen: PrintConfig carries
    // optional screen.view / screen.left, defaulted empty so old print rows
    // still open. Authorisation stays in SavedViewService — a shared view is
    // reusable by anyone who can reach the studio and editable only by its
    // owner or a portal administrator.
    'GET /api/calendar/views' => fn (array $req) => (new \App\Http\Controllers\Api\SavedViewController(
        PortalServiceProvider::makeSavedViewService(),
        PortalServiceProvider::makeRequestContext(),
    ))->index($req),

    'GET /api/calendar/views/{id}' => fn (array $req) => (new \App\Http\Controllers\Api\SavedViewController(
        PortalServiceProvider::makeSavedViewService(),
        PortalServiceProvider::makeRequestContext(),
    ))->show($req),

    'POST /api/calendar/views' => fn (array $req) => (new \App\Http\Controllers\Api\SavedViewController(
        PortalServiceProvider::makeSavedViewService(),
        PortalServiceProvider::makeRequestContext(),
    ))->store($req),

    'PUT /api/calendar/views/{id}' => fn (array $req) => (new \App\Http\Controllers\Api\SavedViewController(
        PortalServiceProvider::makeSavedViewService(),
        PortalServiceProvider::makeRequestContext(),
    ))->update($req),

    'DELETE /api/calendar/views/{id}' => fn (array $req) => (new \App\Http\Controllers\Api\SavedViewController(
        PortalServiceProvider::makeSavedViewService(),
        PortalServiceProvider::makeRequestContext(),
    ))->destroy($req),

    // Background pictures for printed calendars (PrintBackgroundService holds
    // the rules). The picture itself is served by GET /print/backgrounds/{id}.
    'GET /api/print/backgrounds' => fn (array $req) => (new \App\Http\Controllers\Api\PrintBackgroundController(
        PortalServiceProvider::makePrintBackgroundService(),
        PortalServiceProvider::makeRequestContext(),
    ))->index($req),

    'POST /api/print/backgrounds' => fn (array $req) => (new \App\Http\Controllers\Api\PrintBackgroundController(
        PortalServiceProvider::makePrintBackgroundService(),
        PortalServiceProvider::makeRequestContext(),
    ))->store($req),

    'DELETE /api/print/backgrounds/{id}' => fn (array $req) => (new \App\Http\Controllers\Api\PrintBackgroundController(
        PortalServiceProvider::makePrintBackgroundService(),
        PortalServiceProvider::makeRequestContext(),
    ))->destroy($req),

    'GET /print/backgrounds/{id}' => fn (array $req) => (new \App\Http\Controllers\Api\PrintBackgroundController(
        PortalServiceProvider::makePrintBackgroundService(),
        PortalServiceProvider::makeRequestContext(),
    ))->serve($req),

    'GET /api/calendar/sources' => fn (array $req) => (new CalendarController(
        PortalServiceProvider::makeCalendarService(),
        PortalServiceProvider::makeRequestContext(),
        PortalServiceProvider::makeRosterScheduleService(),
        PortalServiceProvider::makeEventTypeService(),
    ))->sources($req),

    // One date as an operations read model: the activities on it, and the role
    // lines belonging to them. Read-only, and no more permissive than the two
    // feeds it composes — the actor's own calendar and their schedule board.
    'GET /api/calendar/day' => fn (array $req) => (new CalendarController(
        PortalServiceProvider::makeCalendarService(),
        PortalServiceProvider::makeRequestContext(),
        PortalServiceProvider::makeRosterScheduleService(),
        PortalServiceProvider::makeEventTypeService(),
        PortalServiceProvider::makeScheduleService(),
    ))->day($req),

    // The chip list for the calendar. Audience-filtered server-side: a member
    // never receives a leader-only layer, not even its label.
    'GET /api/calendar/layers' => fn (array $req) => (new CalendarController(
        PortalServiceProvider::makeCalendarService(),
        PortalServiceProvider::makeRequestContext(),
        PortalServiceProvider::makeRosterScheduleService(),
        PortalServiceProvider::makeEventTypeService(),
    ))->layers($req),

    // Dashboard hero rotator config — read freely, write only as portal admin
    // (the controller enforces that itself).
    'GET /api/hero' => fn (array $req) => (new HeroSettingsController(
        PortalServiceProvider::makeHeroSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->show($req),

    'POST /api/hero' => fn (array $req) => (new HeroSettingsController(
        PortalServiceProvider::makeHeroSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->update($req),

    'GET /api/announcements' => fn (array $req) => (new AnnouncementSettingsController(
        PortalServiceProvider::makeAnnouncementSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->show($req),

    'POST /api/announcements' => fn (array $req) => (new AnnouncementSettingsController(
        PortalServiceProvider::makeAnnouncementSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->update($req),

    // Site chrome (header text + nav, footer text + minimal toggle)
    'GET /api/chrome' => fn (array $req) => (new ChromeSettingsController(
        PortalServiceProvider::makeChromeSettingsService(),
        PortalServiceProvider::makeThemeSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->showChrome($req),

    'POST /api/chrome' => fn (array $req) => (new ChromeSettingsController(
        PortalServiceProvider::makeChromeSettingsService(),
        PortalServiceProvider::makeThemeSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->updateChrome($req),

    // Theme picker — read public so the active preset id is visible to all
    // visitors; writes require portal-admin.
    'GET /api/theme' => fn (array $req) => (new ChromeSettingsController(
        PortalServiceProvider::makeChromeSettingsService(),
        PortalServiceProvider::makeThemeSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->showTheme($req),

    'POST /api/theme/active' => fn (array $req) => (new ChromeSettingsController(
        PortalServiceProvider::makeChromeSettingsService(),
        PortalServiceProvider::makeThemeSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->setActiveTheme($req),

    'POST /api/theme/preset' => fn (array $req) => (new ChromeSettingsController(
        PortalServiceProvider::makeChromeSettingsService(),
        PortalServiceProvider::makeThemeSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->updateThemePreset($req),

    'GET /api/ministries' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->list($req),

    'GET /api/campuses' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->campuses($req),

    'GET /api/ministry-dashboard' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->dashboard($req),

    'GET /api/ministry-board' => function (array $req): array {
        $context = new ActorContext(
            actorId: 0,
            personId: null,
            displayName: 'Public ministry board',
            permissions: [PortalPermission::ViewMinistryDashboard],
            ministryScopeIds: [],
            isPortalWideAdmin: true,
            campusScopeIds: [],
        );
        $since = new DateTimeImmutable((string) ($req['since'] ?? 'today'));
        $until = new DateTimeImmutable((string) ($req['until'] ?? '+30 days'));

        return [
            'cards' => array_map(
                static fn ($card): array => $card->toArray(),
                PortalServiceProvider::makeMinistryService()->listDashboardCards($context, $since, $until),
            ),
        ];
    },

    'GET /api/ministry-roster' => fn (array $req) => (new MinistryRosterController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->index($req),

    // Role management endpoints
    'GET /api/ministry/{id}/roles' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->getRoles($req),

    'POST /api/ministry/{id}/roles' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->createRole($req),

    'PUT /api/ministry/roles/{roleId}' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->updateRole($req),

    'DELETE /api/ministry/roles/{roleId}' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->deleteRole($req),

    'POST /api/ministry/roles/{roleId}/assign/{personId}' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->assignToRole($req),

    'DELETE /api/ministry/roles/{roleId}/assign/{personId}' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->removeFromRole($req),

    'GET /api/ministry/{id}/leaders' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->getLeaders($req),

    'GET /api/ministry/{id}/members' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->getMembers($req),

    'GET /api/ministry/{id}/group-roles' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->getGroupRoles($req),

    'POST /api/ministry/{id}/members/{personId}/role' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->setMemberRole($req),

    'POST /api/ministry/{id}/members/{personId}/positions' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->setMemberPositions($req),

    'DELETE /api/ministry/{id}/members/{personId}' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->removeMember($req),

    // Leader — ministry_members.role
    'POST /api/ministry/{id}/leaders/{personId}' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->tagLeader($req),

    'DELETE /api/ministry/{id}/leaders/{personId}' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->untagLeader($req),

    // Ministry group CRUD (portal-wide admin only)
    'GET /api/admin/ministries' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->listAdminMinistries($req),

    'GET /api/admin/leaders' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->listLeaders($req),

    'POST /api/admin/ministries' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->createMinistry($req),

    'PUT /api/admin/ministries/{id}' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->updateMinistry($req),

    'POST /api/admin/ministries/{id}/active' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->setMinistryActive($req),

    'DELETE /api/admin/ministries/{id}' => fn (array $req) => (new MinistryController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->deleteMinistry($req),

    'GET /api/people-directory' => fn (array $req) => (new PeopleController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->index($req),

    // Ministries settings
    'GET /api/ministries-settings' => fn (array $req) => (new \App\Http\Controllers\Api\MinistriesSettingsController(
        PortalServiceProvider::makeMinistriesSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->show($req),

    'POST /api/admin/ministries-settings' => fn (array $req) => (new \App\Http\Controllers\Api\MinistriesSettingsController(
        PortalServiceProvider::makeMinistriesSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->update($req),

    // People directory admin settings (GET is public for preview; POST requires admin)
    'GET /api/people-settings' => fn (array $req) => (new \App\Http\Controllers\Api\PeopleSettingsController(
        PortalServiceProvider::makePeopleSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->show($req),

    'POST /api/admin/people-settings' => fn (array $req) => (new \App\Http\Controllers\Api\PeopleSettingsController(
        PortalServiceProvider::makePeopleSettingsService(),
        PortalServiceProvider::makeRequestContext(),
    ))->update($req),

    'GET /api/public/people-directory' => fn (array $req) => (new PeopleController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->publicIndex($req),

    'GET /api/people/{id}' => fn (array $req) => (new PeopleController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->show($req),

    'GET /api/public/people/{id}' => fn (array $req) => (new PeopleController(
        PortalServiceProvider::makeMinistryService(),
        PortalServiceProvider::makeRequestContext(),
    ))->publicShow($req),

    'GET /api/schedules/grid' => fn (array $req) => (new ScheduleController(
        PortalServiceProvider::makeScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->grid($req),

    // Day inspector staffing: ids, people, and conflicts for one date, from
    // the same grid the serving editor uses. Writes still go through
    // POST /api/schedules/assignments — this is a read, not a second POST.
    'GET /api/schedules/staffing' => fn (array $req) => (new ScheduleController(
        PortalServiceProvider::makeScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->staffing($req),

    'GET /api/public/ministries' => function (array $req): array {
        $campusId = isset($req['current_campus_id']) && (int) $req['current_campus_id'] > 0
            ? (int) $req['current_campus_id'] : null;

        return ['ministries' => PortalServiceProvider::makeMinistryService()->listMinistriesPublic($campusId)];
    },

    'GET /api/public/schedule-board' => function (array $req): array {
        $campusId = isset($req['current_campus_id']) && (int) $req['current_campus_id'] > 0
            ? (int) $req['current_campus_id'] : null;
        $context = new ActorContext(
            actorId: 0,
            personId: null,
            displayName: 'Public schedule board',
            permissions: [PortalPermission::ViewMinistryDashboard],
            ministryScopeIds: [],
            isPortalWideAdmin: true,
            campusScopeIds: $campusId !== null ? [$campusId] : [],
            currentCampusIds: $campusId !== null ? [$campusId] : [],
        );
        $since = new DateTimeImmutable((string) ($req['since'] ?? 'today'));
        $until = new DateTimeImmutable((string) ($req['until'] ?? '+30 days'));

        return ['assignments' => PortalServiceProvider::makeScheduleService()->getScheduleBoard($context, $since, $until)];
    },

    'GET /api/public/schedules/occurrence' => fn (array $req) => (new ScheduleController(
        PortalServiceProvider::makeScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->publicOccurrence($req),

    // Events slice — signed-in listing is audience-aware (members/leaders).
    // GET /api/public/events is the church visiting feed (public types only).
    // Mutations below still require a signed-in manager.
    'GET /api/events' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->list($req),

    'GET /api/public/events' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->publicList($req),

    'GET /api/events/{id}' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->show($req),

    'POST /api/events' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->create($req),

    'PATCH /api/events/{id}' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->update($req),

    'POST /api/events/{id}/occurrences' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->generateOccurrences($req),

    'DELETE /api/events/{id}' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->destroy($req),

    'POST /api/occurrences/{id}/reset' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->clearOccurrenceOverride($req),

    'POST /api/occurrences/{id}/cancel' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->markOccurrenceCancelled($req),

    'POST /api/occurrences/{id}/restore' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->restoreOccurrence($req),

    'PATCH /api/occurrences/{id}' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->rescheduleOccurrence($req),

    // Series-level repairs. Correcting a mis-entered recurring event otherwise
    // meant deleting occurrences one at a time and regenerating.
    'GET /api/event-tags' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->listTags($req),

    'PUT /api/events/{id}/tags' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->setTags($req),

    'PUT /api/events/{id}/schedule' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->replaceSchedule($req),

    'POST /api/events/{id}/occurrences/retime' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->retimeOccurrences($req),

    'POST /api/events/{id}/occurrences/delete' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->deleteOccurrences($req),

    'DELETE /api/occurrences/{id}' => fn (array $req) => (new \App\Http\Controllers\Api\EventController(
        PortalServiceProvider::makeEventService(),
        PortalServiceProvider::makeRequestContext(),
    ))->cancelOccurrence($req),

    'POST /api/schedules/assignments' => fn (array $req) => (new ScheduleController(
        PortalServiceProvider::makeScheduleService(),
        PortalServiceProvider::makeRequestContext(),
    ))->saveAssignments($req),

    'GET /api/availability' => fn (array $req) => (new AvailabilityController(
        PortalServiceProvider::makeAvailabilityService(),
        PortalServiceProvider::makeRequestContext(),
    ))->index($req),

    'POST /api/availability' => fn (array $req) => (new AvailabilityController(
        PortalServiceProvider::makeAvailabilityService(),
        PortalServiceProvider::makeRequestContext(),
    ))->create($req),

    'DELETE /api/availability/{id}' => fn (array $req) => (new AvailabilityController(
        PortalServiceProvider::makeAvailabilityService(),
        PortalServiceProvider::makeRequestContext(),
    ))->delete($req),
];
