<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Portal-side permissions.
 *
 * Kept intentionally small — new permissions are added only as their service
 * surfaces are built (per the architecture's "expand the enum incrementally"
 * decision). When a new feature lands, its permission(s) are added here.
 */
enum PortalPermission: string
{
    /**
     * Manage scheduling: create, edit, save assignments for ministries within scope.
     */
    case ManageSchedules = 'manage_schedules';

    /**
     * Manage ministry roles: create, edit, delete roles and assign people to roles.
     */
    case ManageMinistryRoles = 'manage_ministry_roles';

    /**
     * Read-only ministry schedule (leader/scheduler with view-only access).
     */
    case ViewMinistrySchedule = 'view_ministry_schedule';

    /**
     * Read-only ministries gallery with past and upcoming assignment summaries.
     */
    case ViewMinistryDashboard = 'view_ministry_dashboard';

    /**
     * View one's own assignments and "My Schedule" — the base member capability.
     */
    case ViewOwnAssignments = 'view_own_assignments';

    /**
     * Manage one's own availability windows (create/list/delete unavailability entries).
     * The actor may only act on their own person link unless they also have ViewPersonAvailability + scope.
     */
    case ManageOwnAvailability = 'manage_own_availability';

    /**
     * View any person's availability within scope (for scheduler/leader use).
     * Cross-person reads still pass through ministry/campus scope checks.
     */
    case ViewPersonAvailability = 'view_person_availability';

    /**
     * Create and edit events (event header + generate occurrences).
     */
    case ManageEvents = 'manage_events';

    /**
     * Cancel (delete) occurrences.
     */
    case CancelOccurrences = 'cancel_occurrences';

    /**
     * Read events whose type carries the 'leaders' audience.
     *
     * A visibility permission, not a management one: leader-audience events
     * are filtered out in SQL on every read path, so an actor without this
     * cannot reach one by guessing a URL, calling the API, or toggling a
     * calendar layer.
     *
     * Named for the audience rather than the Leadership type because Ministry
     * events sit at the same level — the two are separate categories for
     * filtering, not separate access levels.
     *
     * Deliberately distinct from ManageEvents: a scheduler builds rosters for
     * services and has no business reading an elders' meeting agenda.
     */
    case ViewLeaderEvents = 'view_leader_events';

    /**
     * Build an ActorContext-friendly list of permissions from a portal_user_role row.
     * Encodes the role-to-permission mapping in one place.
     *
     * @return list<self>
     */
    public static function forRole(string $role): array
    {
        return match ($role) {
            'admin'     => [
                self::ManageSchedules, self::ManageMinistryRoles, self::ViewMinistrySchedule, self::ViewMinistryDashboard, self::ViewOwnAssignments,
                self::ManageOwnAvailability, self::ViewPersonAvailability, self::ManageEvents, self::CancelOccurrences,
                self::ViewLeaderEvents,
            ],
            'leader'    => [
                self::ManageSchedules, self::ManageMinistryRoles, self::ViewMinistrySchedule, self::ViewMinistryDashboard, self::ViewOwnAssignments,
                self::ManageOwnAvailability, self::ViewPersonAvailability, self::ManageEvents,
                self::ViewLeaderEvents,
            ],
            'scheduler' => [
                self::ManageSchedules, self::ViewMinistrySchedule, self::ViewMinistryDashboard, self::ViewOwnAssignments,
                self::ManageOwnAvailability, self::ViewPersonAvailability, self::ManageEvents, self::CancelOccurrences,
            ],
            'member'    => [self::ViewMinistryDashboard, self::ViewOwnAssignments, self::ManageOwnAvailability],
            default     => [],
        };
    }
}
