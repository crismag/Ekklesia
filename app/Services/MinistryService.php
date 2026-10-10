<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MinistryRepository;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\DTO\MinistryDashboard\MinistryDashboardCard;
use App\DTO\MinistryDashboard\MinistrySchedulePreview;
use App\DTO\MinistryRoster\MinistryRole;
use App\DTO\MinistryRoster\MinistryRosterMember;
use App\DTO\MinistryRoster\MinistryRosterView;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use DateTimeImmutable;

/**
 * Ministry-scoped operational service.
 *
 * Owns:
 *   - Ministry roster reads (membership + serve history rollup, optional
 *     campus filter).
 *
 * Permission model:
 *   - ViewMinistrySchedule (or ManageSchedules) is required to view a roster.
 *   - The actor must have the ministry within their scope, OR be a portal-wide
 *     admin (which bypasses scope).
 *   - The campus filter, when supplied, must be within the actor's campus
 *     scope (or admin).
 */
final readonly class MinistryService
{
    public function __construct(
        private MinistryRepository $ministryRepository,
    ) {
    }

    /**
     * Ministries the actor may choose before entering leader workflows.
     *
     * @return list<array{ministryId:int,name:string,campusId:?int,canViewPeople:bool,canManageSchedules:bool,canManageEvents:bool}>
     */
    /**
     * Campus selector options for the portal shell. Default selection prefers
     * the linked person's primary campus, then actor campus scope, then the
     * named Scarborough campus, then the first active campus.
     *
     * @return array{campuses:list<array{id:int,name:string,selected:bool}>,defaultCampusId:?int}
     */
    public function getCampusSelector(ActorContext $context): array
    {
        $campuses = $this->ministryRepository->listCampuses();
        if (!$context->isPortalWideAdmin && $context->campusScopeIds !== []) {
            $allowed = array_flip($context->campusScopeIds);
            $campuses = array_values(array_filter(
                $campuses,
                static fn (array $campus): bool => isset($allowed[(int) $campus['campus_id']]),
            ));
        }

        $defaultCampusId = null;

        // A member's own campus is a sensible starting point for them. It is
        // not a sensible starting point for someone who oversees every campus:
        // defaulting a portal-wide admin to whichever congregation their person
        // record belongs to silently narrows every admin page to one campus,
        // and makes the selector look like it is stuck there.
        if ($context->personId !== null && !$context->isPortalWideAdmin) {
            $primaryCampusId = $this->ministryRepository->findPrimaryCampusIdForPerson($context->personId);
            if ($primaryCampusId !== null && $this->campusListContains($campuses, $primaryCampusId)) {
                $defaultCampusId = $primaryCampusId;
            }
        }

        if ($defaultCampusId === null && $context->currentCampusId !== null && $this->campusListContains($campuses, $context->currentCampusId)) {
            $defaultCampusId = $context->currentCampusId;
        }

        if ($defaultCampusId === null && $context->campusScopeIds !== []) {
            foreach ($context->campusScopeIds as $campusId) {
                if ($this->campusListContains($campuses, (int) $campusId)) {
                    $defaultCampusId = (int) $campusId;
                    break;
                }
            }
        }

        // Fall back to the campus an administrator marked as main, if any.
        //
        // This used to hardcode "Scarborough" by name and then, failing that,
        // the alphabetically first campus — so a signed-in user with no campus
        // of their own was silently pinned to one particular congregation on
        // every page that passes this default, and "All campuses" could never
        // be what they saw. Which campus leads is configuration, not something
        // a service should know the name of.
        if ($defaultCampusId === null) {
            foreach ($campuses as $campus) {
                if (!empty($campus['is_main'])) {
                    $defaultCampusId = (int) $campus['campus_id'];
                    break;
                }
            }
        }

        // No main campus chosen: show everything rather than guessing. The
        // admin control board surfaces that choice; until it is made, "All
        // campuses" is the honest default.

        return [
            'campuses' => array_map(
                static fn (array $campus): array => [
                    'id' => (int) $campus['campus_id'],
                    'name' => (string) $campus['campus_name'],
                    'selected' => $defaultCampusId !== null && (int) $campus['campus_id'] === $defaultCampusId,
                ],
                $campuses,
            ),
            'defaultCampusId' => $defaultCampusId,
        ];
    }

    /** @param list<array{campus_id:int,campus_name:string}> $campuses */
    private function campusListContains(array $campuses, int $campusId): bool
    {
        foreach ($campuses as $campus) {
            if ((int) $campus['campus_id'] === $campusId) {
                return true;
            }
        }

        return false;
    }

    public function listAccessibleMinistries(ActorContext $context): array
    {
        $canViewPeople = $context->hasPermission(PortalPermission::ViewMinistrySchedule)
            || $context->hasPermission(PortalPermission::ManageSchedules);
        $canManageSchedules = $context->hasPermission(PortalPermission::ManageSchedules);
        $canManageEvents = $context->hasPermission(PortalPermission::ManageEvents);

        if (!$canViewPeople && !$canManageSchedules && !$canManageEvents) {
            return [];
        }

        $ministryIds = $context->isPortalWideAdmin ? [] : $context->ministryScopeIds;
        if (!$context->isPortalWideAdmin && $ministryIds === []) {
            return [];
        }

        $ministries = $this->ministryRepository->listMinistries($ministryIds);
        return array_map(function (array $ministry) use ($canViewPeople, $canManageSchedules, $canManageEvents): array {
            return [
                'ministryId' => (int) $ministry['ministry_id'],
                'name' => (string) $ministry['name'],
                'campusId' => $ministry['campus_id'] === null ? null : (int) $ministry['campus_id'],
                'icon' => $this->ministryIconSlug((string) $ministry['name']),
                'canViewPeople' => $canViewPeople,
                'canManageSchedules' => $canManageSchedules,
                'canManageEvents' => $canManageEvents,
            ];
        }, $ministries);
    }

    /**
     * Full ministry catalog for directory-style browsing.
     *
     * @return list<array{ministryId:int,name:string,campusId:?int,icon:string,canViewPeople:bool,canManageSchedules:bool,canManageEvents:bool}>
     */
    public function listAllMinistries(ActorContext $context): array
    {
        $canViewPeople = $context->hasPermission(PortalPermission::ViewMinistrySchedule)
            || $context->hasPermission(PortalPermission::ManageSchedules);
        $canManageSchedules = $context->hasPermission(PortalPermission::ManageSchedules);
        $canManageEvents = $context->hasPermission(PortalPermission::ManageEvents);

        if (!$canViewPeople && !$canManageSchedules && !$canManageEvents) {
            return [];
        }

        $ministries = $this->ministryRepository->listMinistries();
        return array_map(function (array $ministry) use ($canViewPeople, $canManageSchedules, $canManageEvents): array {
            return [
                'ministryId' => (int) $ministry['ministry_id'],
                'name' => (string) $ministry['name'],
                'campusId' => $ministry['campus_id'] === null ? null : (int) $ministry['campus_id'],
                'icon' => $this->ministryIconSlug((string) $ministry['name']),
                'canViewPeople' => $canViewPeople,
                'canManageSchedules' => $canManageSchedules,
                'canManageEvents' => $canManageEvents,
            ];
        }, $ministries);
    }

    /**
     * @return list<MinistryDashboardCard>
     */
    public function listDashboardCards(
        ActorContext $context,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
    ): array {
        if ($until < $since) {
            throw new ValidationFailed('Dashboard end date cannot be before the start date.');
        }

        if (!$context->hasPermission(PortalPermission::ViewMinistryDashboard)
            && !$context->hasPermission(PortalPermission::ViewMinistrySchedule)
            && !$context->hasPermission(PortalPermission::ManageSchedules)
        ) {
            throw new PermissionDenied('Actor lacks permission to browse ministry schedules.');
        }

        $upcomingStart = new DateTimeImmutable('today');
        if ($upcomingStart < $since) {
            $upcomingStart = $since;
        }
        if ($upcomingStart > $until) {
            $upcomingStart = $until;
        }

        $rawCards = $this->ministryRepository->fetchDashboard([], $since, $upcomingStart, $until);

        return array_map(function (array $row) use ($context): MinistryDashboardCard {
            $ministryId = (int) $row['ministry_id'];
            $canViewPeople = $context->hasPermission(PortalPermission::ViewMinistrySchedule)
                && ($context->isPortalWideAdmin || in_array($ministryId, $context->ministryScopeIds, true));
            $canManageSchedules = $context->hasPermission(PortalPermission::ManageSchedules)
                && ($context->isPortalWideAdmin || in_array($ministryId, $context->ministryScopeIds, true));

            return new MinistryDashboardCard(
                ministryId: $ministryId,
                name: (string) $row['name'],
                campusId: $row['campus_id'] === null ? null : (int) $row['campus_id'],
                icon: $this->ministryIconSlug((string) $row['name']),
                canOpenSchedule: $canViewPeople || $canManageSchedules,
                canViewPeople: $canViewPeople,
                canManageSchedules: $canManageSchedules,
                pastAssignmentCount: (int) $row['past_assignment_count'],
                upcomingAssignmentCount: (int) $row['upcoming_assignment_count'],
                lastOccurrenceAt: $row['last_occurrence_at'] instanceof DateTimeImmutable ? $row['last_occurrence_at'] : null,
                nextOccurrenceAt: $row['next_occurrence_at'] instanceof DateTimeImmutable ? $row['next_occurrence_at'] : null,
                upcomingSchedule: array_map(
                    static fn (array $occurrence): MinistrySchedulePreview => new MinistrySchedulePreview(
                        occurrenceId: (int) $occurrence['occurrence_id'],
                        eventTitle: (string) $occurrence['event_title'],
                        startsOn: $occurrence['starts_on'],
                        endsOn: $occurrence['ends_on'],
                        assignmentCount: (int) $occurrence['assignment_count'],
                    ),
                    $row['upcoming_occurrences'] ?? [],
                ),
            );
        }, $rawCards);
    }

    public function getRoster(
        ActorContext $context,
        int $ministryId,
        DateTimeImmutable $since,
        ?int $campusId = null,
    ): MinistryRosterView {
        if ($ministryId <= 0) {
            throw new ValidationFailed('Ministry id is required.');
        }

        if (!$context->hasPermission(PortalPermission::ViewMinistrySchedule)
            && !$context->hasPermission(PortalPermission::ManageSchedules)
        ) {
            throw new PermissionDenied('Actor lacks permission to view ministry rosters.');
        }

        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }

        if ($campusId !== null && !$context->canAccessCampus($campusId)) {
            throw new PermissionDenied("Actor is outside the requested campus scope (campus $campusId).");
        }

        $raw = $this->ministryRepository->fetchRoster($ministryId, $since, $campusId);

        $members = array_map(
            static function (array $row): MinistryRosterMember {
                // Separate leader roles from regular roles
                $rolesServed = (string) $row['roles_served'];
                $allRoles = $rolesServed !== '' ? explode(', ', $rolesServed) : [];
                $leaderRoles = [];
                $regularRoles = [];

                foreach ($allRoles as $role) {
                    if (preg_match('/leader|head|coordinator|director|pastor/i', $role) === 1) {
                        $leaderRoles[] = $role;
                    } else {
                        $regularRoles[] = $role;
                    }
                }

                return new MinistryRosterMember(
                    personId: (int) $row['person_id'],
                    displayName: (string) $row['display_name'],
                    primaryCampusId: $row['primary_campus_id'] === null ? null : (int) $row['primary_campus_id'],
                    assignmentCount: (int) $row['assignment_count'],
                    lastServedAt: $row['last_served_at'] instanceof DateTimeImmutable ? $row['last_served_at'] : null,
                    rolesServed: $rolesServed,
                    leaderRoles: $leaderRoles,
                    regularRoles: $regularRoles,
                );
            },
            $raw['members'],
        );

        return new MinistryRosterView(
            ministryId: (int) $raw['ministry_id'],
            ministryName: (string) $raw['ministry_name'],
            campusId: $raw['campus_id'] === null ? null : (int) $raw['campus_id'],
            since: $raw['since'],
            campusFilter: $campusId,
            members: $members,
        );
    }

    /**
     * All people, regardless of ministry membership.
     *
     * @return list<array{
     *   personId:int,
     *   displayName:string,
     *   primaryCampusId:?int,
     *   assignmentCount:int,
     *   lastServedAt:?DateTimeImmutable,
     *   rolesServed:string,
     *   ministryCount:int,
     *   ministries:string
     * }>
     */
    public function listPeopleDirectory(
        ActorContext $context,
        DateTimeImmutable $since,
        ?int $campusId = null,
        ?int $personId = null,
    ): array {
        if (!$context->hasPermission(PortalPermission::ViewMinistrySchedule)
            && !$context->hasPermission(PortalPermission::ManageSchedules)
        ) {
            throw new PermissionDenied('Actor lacks permission to view the people directory.');
        }

        if ($campusId !== null && !$context->canAccessCampus($campusId)) {
            throw new PermissionDenied("Actor is outside the requested campus scope (campus $campusId).");
        }

        $rows = $this->ministryRepository->fetchPeopleDirectory($since, $campusId, $personId);

        return array_map(
            static fn (array $row): array => [
                'personId' => (int) $row['person_id'],
                'firstName' => (string) ($row['first_name'] ?? ''),
                'lastName' => (string) ($row['last_name'] ?? ''),
                'displayName' => (string) $row['display_name'],
                'familyId' => ($row['household_id'] ?? null) === null ? null : (int) $row['household_id'],
                'classificationId' => ($row['membership_status_id'] ?? null) === null ? null : (int) $row['membership_status_id'],
                'classificationName' => (string) ($row['membership_status_name'] ?? ''),
                'memberTypeId' => ($row['member_type_id'] ?? null) === null ? null : (int) $row['member_type_id'],
                'memberTypeName' => (string) ($row['member_type_name'] ?? ''),
                'familyName' => (string) ($row['household_name'] ?? ''),
                'isActive' => ($row['household_deactivated_on'] ?? null) === null,
                'birthMonth' => ($row['birth_month'] ?? null) === null ? null : (int) $row['birth_month'],
                'birthDay' => ($row['birth_day'] ?? null) === null ? null : (int) $row['birth_day'],
                'birthYear' => ($row['birth_year'] ?? null) === null ? null : (int) $row['birth_year'],
                'dateEntered' => ($row['created_at'] ?? null) instanceof DateTimeImmutable ? $row['created_at'] : null,
                'dateLastEdited' => ($row['updated_at'] ?? null) instanceof DateTimeImmutable ? $row['updated_at'] : null,
                'email' => (string) ($row['email'] ?? ''),
                'mobilePhone' => (string) ($row['mobile_phone'] ?? ''),
                'homePhone' => (string) ($row['home_phone'] ?? ''),
                'address' => [
                    'line1' => (string) ($row['address_line1'] ?? ''),
                    'line2' => (string) ($row['address_line2'] ?? ''),
                    'city' => (string) ($row['city'] ?? ''),
                    'state' => (string) ($row['region'] ?? ''),
                    'zip' => (string) ($row['postal_code'] ?? ''),
                    'country' => (string) ($row['country'] ?? ''),
                ],
                // Facebook / LinkedIn were never filled in and are not kept.
                'publicLinks' => [],
                'primaryCampusId' => $row['primary_campus_id'] === null ? null : (int) $row['primary_campus_id'],
                'primaryCampusName' => (string) ($row['primary_campus_name'] ?? ''),
                'assignmentCount' => (int) $row['assignment_count'],
                'lastServedAt' => $row['last_served_at'] instanceof DateTimeImmutable ? $row['last_served_at'] : null,
                'rolesServed' => (string) $row['roles_served'],
                'ministryCount' => (int) $row['ministry_count'],
                'ministries' => (string) $row['ministries'],
            ],
            $rows,
        );
    }

    public function findPeopleDirectoryPerson(
        ActorContext $context,
        int $personId,
        DateTimeImmutable $since,
        ?int $campusId = null,
    ): ?array {
        $rows = $this->listPeopleDirectory($context, $since, $campusId, $personId);
        return $rows[0] ?? null;
    }

    private function ministryIconSlug(string $name): string
    {
        $normalized = strtolower($name);
        return match (true) {
            str_contains($normalized, 'worship'),
            str_contains($normalized, 'psalm'),
            str_contains($normalized, 'music') => 'events',
            str_contains($normalized, 'youth'),
            str_contains($normalized, 'children'),
            str_contains($normalized, 'kids') => 'people',
            str_contains($normalized, 'prayer') => 'calendar',
            str_contains($normalized, 'media'),
            str_contains($normalized, 'tech') => 'search',
            str_contains($normalized, 'care'),
            str_contains($normalized, 'hospital') => 'availability',
            str_contains($normalized, 'outreach'),
            str_contains($normalized, 'missions') => 'ministry',
            default => 'ministry',
        };
    }

    /**
     * Get all roles for a ministry.
     *
     * @return list<MinistryRole>
     */
    public function getMinistryRoles(ActorContext $context, int $ministryId): array
    {
        if ($ministryId <= 0) {
            throw new ValidationFailed('Ministry id is required.');
        }

        if (!$context->hasPermission(PortalPermission::ViewMinistrySchedule)
            && !$context->hasPermission(PortalPermission::ManageSchedules)
        ) {
            throw new PermissionDenied('Actor lacks permission to view ministry roles.');
        }

        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }

        $rawRoles = $this->ministryRepository->fetchMinistryRoles($ministryId);

        return array_map(
            static function (array $row): MinistryRole {
                $isLeaderRole = preg_match('/leader|head|coordinator|director|pastor/i', $row['name']) === 1;

                return new MinistryRole(
                    id: (int) $row['id'],
                    name: (string) $row['name'],
                    isLeaderRole: $isLeaderRole,
                    order: (int) ($row['sort_order'] ?? 0),
                    active: (bool) ($row['is_active'] ?? true),
                    assignedCount: (int) ($row['assigned_count'] ?? 0),
                    assignedMembers: $row['assigned_members'] ?? [],
                );
            },
            $rawRoles,
        );
    }

    /**
     * Get members with leader roles for a ministry.
     *
     * @return list<MinistryRosterMember>
     */
    public function getMinistryLeaders(ActorContext $context, int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array
    {
        if ($ministryId <= 0) {
            throw new ValidationFailed('Ministry id is required.');
        }

        if (!$context->hasPermission(PortalPermission::ViewMinistrySchedule)
            && !$context->hasPermission(PortalPermission::ManageSchedules)
        ) {
            throw new PermissionDenied('Actor lacks permission to view ministry leaders.');
        }

        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }

        $rawLeaders = $this->ministryRepository->fetchMinistryLeaders($ministryId, $since, $campusId);

        return array_map(
            static fn (array $row): MinistryRosterMember => new MinistryRosterMember(
                personId: (int) $row['person_id'],
                displayName: (string) $row['display_name'],
                primaryCampusId: $row['primary_campus_id'] === null ? null : (int) $row['primary_campus_id'],
                assignmentCount: (int) $row['assignment_count'],
                lastServedAt: $row['last_served_at'] instanceof DateTimeImmutable ? $row['last_served_at'] : null,
                rolesServed: (string) $row['roles_served'],
                leaderRoles: $row['leader_roles'] ?? [],
                regularRoles: $row['regular_roles'] ?? [],
            ),
            $rawLeaders,
        );
    }

    /**
     * Create a new role for a ministry.
     */
    public function createRole(ActorContext $context, int $ministryId, array $data): MinistryRole
    {
        if ($ministryId <= 0) {
            throw new ValidationFailed('Ministry id is required.');
        }

        if (!isset($data['name']) || trim($data['name']) === '') {
            throw new ValidationFailed('Role name is required.');
        }

        if (!$context->hasPermission(PortalPermission::ManageSchedules)) {
            throw new PermissionDenied('Actor lacks permission to manage ministry roles.');
        }

        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }

        $roleData = [
            'name' => trim($data['name']),
            'order' => (int) ($data['order'] ?? 0),
            'active' => (bool) ($data['active'] ?? true),
        ];

        $rawRole = $this->ministryRepository->createMinistryRole($ministryId, $roleData);
        $isLeaderRole = preg_match('/leader|head|coordinator|director|pastor/i', $rawRole['name']) === 1;

        return new MinistryRole(
            id: (int) $rawRole['id'],
            name: (string) $rawRole['name'],
            isLeaderRole: $isLeaderRole,
            order: (int) ($rawRole['sort_order'] ?? 0),
            active: (bool) ($rawRole['is_active'] ?? true),
            assignedCount: 0,
            assignedMembers: [],
        );
    }

    /**
     * Update an existing role.
     */
    public function updateRole(ActorContext $context, int $roleId, array $data): MinistryRole
    {
        if ($roleId <= 0) {
            throw new ValidationFailed('Role id is required.');
        }

        if (!$context->hasPermission(PortalPermission::ManageSchedules)) {
            throw new PermissionDenied('Actor lacks permission to manage ministry roles.');
        }

        $this->assertRoleInScope($context, $roleId);

        $roleData = [];
        if (isset($data['name']) && trim($data['name']) !== '') {
            $roleData['name'] = trim($data['name']);
        }
        if (isset($data['order'])) {
            $roleData['order'] = (int) $data['order'];
        }
        if (isset($data['active'])) {
            $roleData['active'] = (bool) $data['active'];
        }

        if ($roleData === []) {
            throw new ValidationFailed('No valid data provided for role update.');
        }

        $rawRole = $this->ministryRepository->updateMinistryRole($roleId, $roleData);
        $isLeaderRole = preg_match('/leader|head|coordinator|director|pastor/i', $rawRole['name']) === 1;

        return new MinistryRole(
            id: (int) $rawRole['id'],
            name: (string) $rawRole['name'],
            isLeaderRole: $isLeaderRole,
            order: (int) ($rawRole['sort_order'] ?? 0),
            active: (bool) ($rawRole['is_active'] ?? true),
            assignedCount: (int) ($rawRole['assigned_count'] ?? 0),
            assignedMembers: $rawRole['assigned_members'] ?? [],
        );
    }

    /**
     * Delete a role.
     */
    public function deleteRole(ActorContext $context, int $roleId): bool
    {
        if ($roleId <= 0) {
            throw new ValidationFailed('Role id is required.');
        }

        if (!$context->hasPermission(PortalPermission::ManageSchedules)) {
            throw new PermissionDenied('Actor lacks permission to manage ministry roles.');
        }

        $this->assertRoleInScope($context, $roleId);

        return $this->ministryRepository->deleteMinistryRole($roleId);
    }

    /**
     * Assign a person to a role.
     */
    public function assignPersonToRole(ActorContext $context, int $personId, int $roleId): bool
    {
        if ($personId <= 0) {
            throw new ValidationFailed('Person id is required.');
        }

        if ($roleId <= 0) {
            throw new ValidationFailed('Role id is required.');
        }

        if (!$context->hasPermission(PortalPermission::ManageSchedules)) {
            throw new PermissionDenied('Actor lacks permission to manage role assignments.');
        }

        $this->assertRoleInScope($context, $roleId);

        return $this->ministryRepository->assignPersonToRole($personId, $roleId);
    }

    /**
     * Remove a person from a role.
     */
    public function removePersonFromRole(ActorContext $context, int $personId, int $roleId): bool
    {
        if ($personId <= 0) {
            throw new ValidationFailed('Person id is required.');
        }

        if ($roleId <= 0) {
            throw new ValidationFailed('Role id is required.');
        }

        if (!$context->hasPermission(PortalPermission::ManageSchedules)) {
            throw new PermissionDenied('Actor lacks permission to manage role assignments.');
        }

        $this->assertRoleInScope($context, $roleId);

        return $this->ministryRepository->removePersonFromRole($personId, $roleId);
    }

    // ---------------------------------------------------------------------
    // Ministry CRUD (portal-wide admin only)
    // ---------------------------------------------------------------------

    private function requireAdmin(ActorContext $context): void
    {
        if (!$context->isPortalWideAdmin) {
            throw new PermissionDenied('Only a portal-wide admin can manage ministries.');
        }
    }

    /**
     * @return list<array{ministry_id:int,name:string,active:bool,campus_id:?int,member_count:int,leader_count:int,role_count:int}>
     */
    public function listMinistriesAdmin(ActorContext $context, ?int $campusId = null): array
    {
        $this->requireAdmin($context);

        return $this->ministryRepository->listMinistriesAdmin($campusId);
    }

    /**
     * Public, ungated list of ACTIVE ministries (id, name, campus) for the
     * schedule board's ministry filter. Ministry names are already public on
     * the board, so no permission gate is applied.
     *
     * @return list<array{ministry_id:int,name:string,campus_id:?int}>
     */
    public function listMinistriesPublic(?int $campusId = null): array
    {
        $out = [];
        foreach ($this->ministryRepository->listMinistriesAdmin($campusId) as $m) {
            if (!($m['active'] ?? true)) {
                continue;
            }
            $out[] = [
                'ministry_id' => (int) $m['ministry_id'],
                'name' => (string) $m['name'],
                'campus_id' => $m['campus_id'] === null ? null : (int) $m['campus_id'],
            ];
        }

        return $out;
    }

    /**
     * All leaders grouped by ministry (admin overview).
     *
     * @return list<array{ministry_id:int,person_id:int,display_name:string}>
     */
    public function listLeadersByMinistry(ActorContext $context, ?int $campusId = null): array
    {
        $this->requireAdmin($context);

        return $this->ministryRepository->listLeadersByMinistry($campusId);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createMinistry(ActorContext $context, array $data): array
    {
        $this->requireAdmin($context);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationFailed('Ministry name is required.');
        }
        if (mb_strlen($name) > 50) {
            throw new ValidationFailed('Ministry name must be 50 characters or fewer.');
        }

        return $this->ministryRepository->createMinistry([
            'name' => $name,
            'description' => trim((string) ($data['description'] ?? '')),
            'campus_id' => isset($data['campus_id']) ? (int) $data['campus_id'] : null,
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function updateMinistry(ActorContext $context, int $ministryId, array $data): array
    {
        $this->requireAdmin($context);

        if ($ministryId <= 0) {
            throw new ValidationFailed('Ministry id is required.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationFailed('Ministry name is required.');
        }
        if (mb_strlen($name) > 50) {
            throw new ValidationFailed('Ministry name must be 50 characters or fewer.');
        }

        $payload = [
            'name' => $name,
            'description' => trim((string) ($data['description'] ?? '')),
        ];
        if (array_key_exists('campus_id', $data)) {
            $payload['campus_id'] = (int) $data['campus_id'];
        }

        return $this->ministryRepository->updateMinistry($ministryId, $payload);
    }

    public function setMinistryActive(ActorContext $context, int $ministryId, bool $active): bool
    {
        $this->requireAdmin($context);

        if ($ministryId <= 0) {
            throw new ValidationFailed('Ministry id is required.');
        }

        return $this->ministryRepository->setMinistryActive($ministryId, $active);
    }

    public function deleteMinistry(ActorContext $context, int $ministryId): bool
    {
        $this->requireAdmin($context);

        if ($ministryId <= 0) {
            throw new ValidationFailed('Ministry id is required.');
        }

        return $this->ministryRepository->deleteMinistry($ministryId);
    }

    /**
     * Full roster (every member) of a ministry with their role and positions.
     *
     * @return list<array{person_id:int,display_name:string,role:string,role_name:string,is_leader:bool,positions:list<string>}>
     */
    public function getMinistryMembers(ActorContext $context, int $ministryId, ?int $campusId = null): array
    {
        if ($ministryId <= 0) {
            throw new ValidationFailed('Ministry id is required.');
        }

        if (!$context->hasPermission(PortalPermission::ViewMinistrySchedule)
            && !$context->hasPermission(PortalPermission::ManageSchedules)
        ) {
            throw new PermissionDenied('Actor lacks permission to view ministry members.');
        }

        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }

        return $this->ministryRepository->listMinistryMembers($ministryId, $campusId);
    }

    /**
     * Membership roles (Member / Leader) for the "add member" picker.
     *
     * @return list<array{id:string,name:string,is_default:bool}>
     */
    public function getGroupRoles(ActorContext $context, int $ministryId): array
    {
        if ($ministryId <= 0) {
            throw new ValidationFailed('Ministry id is required.');
        }
        if (!$context->hasPermission(PortalPermission::ViewMinistrySchedule)
            && !$context->hasPermission(PortalPermission::ManageSchedules)
        ) {
            throw new PermissionDenied('Actor lacks permission to view ministry members.');
        }
        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }

        return $this->ministryRepository->listGroupRoles($ministryId);
    }

    /**
     * Set a member's role in a ministry: 'member' or 'leader'. Adds the
     * membership if the person isn't in the ministry yet.
     */
    public function setMemberRole(ActorContext $context, int $ministryId, int $personId, string $role): bool
    {
        if ($ministryId <= 0 || $personId <= 0) {
            throw new ValidationFailed('Ministry id and person id are required.');
        }

        if ($role !== 'member' && $role !== 'leader') {
            throw new ValidationFailed('A ministry role is member or leader.');
        }

        if (!$context->hasPermission(PortalPermission::ManageSchedules)) {
            throw new PermissionDenied('Actor lacks permission to manage ministry members.');
        }

        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }

        return $this->ministryRepository->setMemberRole($personId, $ministryId, $role);
    }

    public function removeMemberFromMinistry(ActorContext $context, int $ministryId, int $personId): bool
    {
        if ($ministryId <= 0 || $personId <= 0) {
            throw new ValidationFailed('Ministry id and person id are required.');
        }

        if (!$context->hasPermission(PortalPermission::ManageSchedules)) {
            throw new PermissionDenied('Actor lacks permission to manage ministry members.');
        }

        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }

        return $this->ministryRepository->removeMemberFromMinistry($personId, $ministryId);
    }

    /**
     * Make a member a leader of a ministry (role 'leader'). Serving roles on
     * the schedule are unaffected.
     */
    public function tagLeader(ActorContext $context, int $ministryId, int $personId): bool
    {
        $this->assertCanManageMembers($context, $ministryId, $personId);

        return $this->ministryRepository->addMinistryLeader($ministryId, $personId);
    }

    public function untagLeader(ActorContext $context, int $ministryId, int $personId): bool
    {
        $this->assertCanManageMembers($context, $ministryId, $personId);

        return $this->ministryRepository->removeMinistryLeader($ministryId, $personId);
    }

    /**
     * Replace a member's positions ("Usher", "Emcee") in a ministry.
     *
     * Positions are part of managing the ministry's membership, so the rule is
     * the membership editor's, narrowed to ministry-role managers: ManageSchedules
     * and ManageMinistryRoles inside the ministry's scope. False when the person
     * is not a member of the ministry.
     *
     * @param list<mixed> $positions
     */
    public function setMemberPositions(ActorContext $context, int $ministryId, int $personId, array $positions): bool
    {
        if ($ministryId <= 0 || $personId <= 0) {
            throw new ValidationFailed('Ministry id and person id are required.');
        }
        if (!$context->hasPermission(PortalPermission::ManageMinistryRoles)
            || !$context->hasPermission(PortalPermission::ManageSchedules)
        ) {
            throw new PermissionDenied('Actor lacks permission to manage ministry members.');
        }
        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }
        if (count($positions) > 20) {
            throw new ValidationFailed('A member can hold at most 20 positions.');
        }
        $clean = [];
        foreach ($positions as $position) {
            if (!is_string($position)) {
                throw new ValidationFailed('Positions are names.');
            }
            $name = trim($position);
            if (mb_strlen($name) > 60) {
                throw new ValidationFailed('A position name must be 60 characters or fewer.');
            }
            if ($name !== '') {
                $clean[] = $name;
            }
        }

        return $this->ministryRepository->setMemberPositions($personId, $ministryId, $clean);
    }

    // ---------------------------------------------------------------------
    // Ministries workspace (directory and per-ministry pages)
    // ---------------------------------------------------------------------

    /**
     * What this actor may see and do in one ministry's workspace.
     *
     * Every flag restates a rule the service or the API already enforces; the
     * workspace only uses them to decide what to offer. Management is offered
     * to holders of ManageMinistryRoles (leaders, admins) — the people the
     * workspace map has always offered member management to — although the
     * membership endpoints themselves accept ManageSchedules alone.
     *
     * @return array{canViewPeople:bool,canManageMembers:bool,canManageRoles:bool,canEditSchedule:bool,canPrintSchedule:bool,canManageMinistries:bool}
     */
    public function workspaceAccess(ActorContext $context, int $ministryId): array
    {
        $inScope = $ministryId > 0 && $context->canAccessMinistry($ministryId);
        $manages = $inScope
            && $context->hasPermission(PortalPermission::ManageSchedules)
            && $context->hasPermission(PortalPermission::ManageMinistryRoles);

        return [
            'canViewPeople'       => $inScope && ($context->hasPermission(PortalPermission::ViewMinistrySchedule)
                || $context->hasPermission(PortalPermission::ManageSchedules)),
            'canManageMembers'    => $manages,
            'canManageRoles'      => $manages,
            'canEditSchedule'     => $inScope && $context->hasPermission(PortalPermission::ManageSchedules),
            'canPrintSchedule'    => $context->isPortalWideAdmin
                || $context->hasPermission(PortalPermission::ViewMinistrySchedule)
                || $context->hasPermission(PortalPermission::ManageSchedules),
            'canManageMinistries' => $context->isPortalWideAdmin,
        ];
    }

    /**
     * The ministries directory for a signed-in actor.
     *
     * Everyone signed in sees each active ministry's name, campus, how many
     * people belong to it, whether it schedules people, and its next serving
     * date. Leader names are member data and appear only where the actor may
     * already read that ministry's people (getMinistryLeaders' rule); otherwise
     * 'leaders' is null. Inactive ministries are listed for administrators only.
     *
     * With a campus, ministries of that campus and church-wide ones are listed,
     * and counts are that campus's people.
     *
     * @return list<array{ministryId:int,name:string,campusId:?int,active:bool,memberCount:int,servingRoleCount:int,schedules:bool,upcomingCount:int,nextDate:?DateTimeImmutable,nextTitle:string,isMine:bool,leadsIt:bool,leaders:?list<string>}>
     */
    public function listDirectory(ActorContext $context, ?int $campusId, DateTimeImmutable $today): array
    {
        if ($campusId !== null && !$context->canAccessCampus($campusId)) {
            throw new PermissionDenied("Actor is outside the requested campus scope (campus $campusId).");
        }

        $from = $today->setTime(0, 0);
        $until = $from->modify('+60 days');
        $upcoming = [];
        foreach ($this->ministryRepository->fetchDashboard([], $from, $from, $until) as $row) {
            $upcoming[(int) $row['ministry_id']] = $row;
        }

        $leaders = [];
        foreach ($this->ministryRepository->listLeadersByMinistry($campusId) as $row) {
            $leaders[(int) $row['ministry_id']][] = (string) $row['display_name'];
        }

        [$mine, $led] = $this->membershipsOf($context);

        $out = [];
        foreach ($this->ministryRepository->listMinistriesAdmin($campusId) as $m) {
            $id = (int) $m['ministry_id'];
            $active = (bool) ($m['active'] ?? true);
            if (!$active && !$context->isPortalWideAdmin) {
                continue;
            }
            $occurrences = $upcoming[$id]['upcoming_occurrences'] ?? [];
            $occurrences = is_array($occurrences) ? array_values($occurrences) : [];
            $first = $occurrences[0] ?? null;
            $access = $this->workspaceAccess($context, $id);
            $out[] = [
                'ministryId'       => $id,
                'name'             => (string) $m['name'],
                'campusId'         => $m['campus_id'] === null ? null : (int) $m['campus_id'],
                'active'           => $active,
                'memberCount'      => (int) ($m['member_count'] ?? 0),
                'servingRoleCount' => (int) ($m['role_count'] ?? 0),
                'schedules'        => (int) ($m['role_count'] ?? 0) > 0,
                'upcomingCount'    => count($occurrences),
                'nextDate'         => $first !== null && ($first['starts_on'] ?? null) instanceof DateTimeImmutable ? $first['starts_on'] : null,
                'nextTitle'        => $first !== null ? (string) ($first['event_title'] ?? '') : '',
                'isMine'           => isset($mine[$id]) || (!$context->isPortalWideAdmin && in_array($id, $context->ministryScopeIds, true)),
                'leadsIt'          => isset($led[$id]),
                'leaders'          => $access['canViewPeople'] ? ($leaders[$id] ?? []) : null,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * One ministry's Overview: identity, counts, and what the actor may read of
     * its people, roles and upcoming serving dates. Null when there is no such
     * ministry, or it is inactive and the actor is not an administrator.
     *
     * @return ?array<string,mixed>
     */
    public function getWorkspaceOverview(ActorContext $context, int $ministryId, ?int $campusId, DateTimeImmutable $today): ?array
    {
        if ($ministryId <= 0) {
            return null;
        }
        if ($campusId !== null && !$context->canAccessCampus($campusId)) {
            throw new PermissionDenied("Actor is outside the requested campus scope (campus $campusId).");
        }

        $row = null;
        foreach ($this->ministryRepository->listMinistriesAdmin($campusId) as $m) {
            if ((int) $m['ministry_id'] === $ministryId) {
                $row = $m;
                break;
            }
        }
        if ($row === null && $campusId !== null) {
            // Another campus's ministry, opened while a campus is selected:
            // still the same ministry, counted across the church.
            foreach ($this->ministryRepository->listMinistriesAdmin(null) as $m) {
                if ((int) $m['ministry_id'] === $ministryId) {
                    $row = $m;
                    $campusId = null;
                    break;
                }
            }
        }
        if ($row === null || (!($row['active'] ?? true) && !$context->isPortalWideAdmin)) {
            return null;
        }

        $access = $this->workspaceAccess($context, $ministryId);
        $from = $today->setTime(0, 0);
        $cards = $this->ministryRepository->fetchDashboard([$ministryId], $from, $from, $from->modify('+60 days'));
        $upcoming = [];
        foreach (($cards[0]['upcoming_occurrences'] ?? []) as $o) {
            $upcoming[] = [
                'title'           => (string) ($o['event_title'] ?? ''),
                'startsOn'        => $o['starts_on'],
                'assignmentCount' => (int) ($o['assignment_count'] ?? 0),
            ];
        }

        $leaders = null;
        $positions = null;
        $roles = null;
        if ($access['canViewPeople']) {
            $leaders = [];
            $positions = [];
            foreach ($this->ministryRepository->listMinistryMembers($ministryId, $campusId) as $member) {
                if (!empty($member['is_leader'])) {
                    $leaders[] = ['personId' => (int) $member['person_id'], 'name' => (string) $member['display_name']];
                }
                foreach ($member['positions'] ?? [] as $p) {
                    $positions[(string) $p] = ($positions[(string) $p] ?? 0) + 1;
                }
            }
            ksort($positions, SORT_NATURAL | SORT_FLAG_CASE);
            $roles = array_map(static fn (array $r): array => [
                'id'            => (int) $r['id'],
                'name'          => (string) $r['name'],
                'active'        => (bool) ($r['is_active'] ?? true),
                'assignedCount' => (int) ($r['assigned_count'] ?? 0),
            ], $this->ministryRepository->fetchMinistryRoles($ministryId));
        }

        [$mine, $led] = $this->membershipsOf($context);

        return [
            'ministryId'       => $ministryId,
            'name'             => (string) $row['name'],
            'campusId'         => $row['campus_id'] === null ? null : (int) $row['campus_id'],
            'countCampusId'    => $campusId,
            'active'           => (bool) ($row['active'] ?? true),
            'memberCount'      => (int) ($row['member_count'] ?? 0),
            'leaderCount'      => (int) ($row['leader_count'] ?? 0),
            'servingRoleCount' => (int) ($row['role_count'] ?? 0),
            'upcoming'         => $upcoming,
            'leaders'          => $leaders,
            'positions'        => $positions,
            'roles'            => $roles,
            'isMine'           => isset($mine[$ministryId]),
            'leadsIt'          => isset($led[$ministryId]),
            'access'           => $access,
        ];
    }

    /**
     * The ministry an old name-based address meant: "victuals",
     * "gift-and-arrows" and "Gift and Arrows" all name the same one. Letters and
     * digits only, case-insensitive. Null when none or more than one match.
     * Resolving a name grants nothing; the page it leads to decides access.
     */
    public function findMinistryIdBySlug(string $slug): ?int
    {
        $norm = static fn (string $s): string => preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?? '';
        $want = $norm($slug);
        if ($want === '') {
            return null;
        }
        $found = [];
        foreach ($this->ministryRepository->listMinistriesAdmin(null) as $m) {
            if ($norm((string) $m['name']) === $want) {
                $found[] = (int) $m['ministry_id'];
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * The ministries the actor's own person record belongs to, and leads.
     *
     * @return array{0:array<int,true>,1:array<int,true>}
     */
    private function membershipsOf(ActorContext $context): array
    {
        $mine = [];
        $led = [];
        if ($context->personId === null || $context->personId <= 0) {
            return [$mine, $led];
        }
        foreach ($this->ministryRepository->listMinistryIdsForPeople([$context->personId])[$context->personId] ?? [] as $id) {
            $mine[(int) $id] = true;
        }
        foreach ($this->ministryRepository->listLeadersByMinistry(null) as $row) {
            if ((int) $row['person_id'] === $context->personId) {
                $led[(int) $row['ministry_id']] = true;
            }
        }

        return [$mine, $led];
    }

    /**
     * A role-by-id write acts on the role's ministry, so it is held to that
     * ministry's scope exactly like the ministry-scoped writes. An unknown role
     * is a validation failure, not a silent no-op.
     */
    private function assertRoleInScope(ActorContext $context, int $roleId): void
    {
        $ministryId = $this->ministryRepository->findMinistryIdForRole($roleId);
        if ($ministryId === null) {
            throw new ValidationFailed('Serving role not found.');
        }
        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }
    }

    private function assertCanManageMembers(ActorContext $context, int $ministryId, int $personId): void
    {
        if ($ministryId <= 0 || $personId <= 0) {
            throw new ValidationFailed('Ministry id and person id are required.');
        }
        if (!$context->hasPermission(PortalPermission::ManageSchedules)) {
            throw new PermissionDenied('Actor lacks permission to manage ministry members.');
        }
        if (!$context->canAccessMinistry($ministryId)) {
            throw new PermissionDenied('Actor is outside the requested ministry scope.');
        }
    }
}
