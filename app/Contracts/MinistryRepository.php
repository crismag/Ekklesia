<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

interface MinistryRepository
{
    /**
     * @param list<int> $ministryIds
     * @return list<array{ministry_id:int,name:string,campus_id:?int}>
     */
    public function listMinistries(array $ministryIds = []): array;

    /**
     * @return list<array{campus_id:int,campus_name:string}>
     */
    public function listCampuses(): array;

    public function findPrimaryCampusIdForPerson(int $personId): ?int;

    public function findDisplayNameForPerson(int $personId): ?string;

    /**
     * @return array{
     *   ministry_id:int,
     *   name:string,
     *   campus_id:?int
     * }|null
     */
    public function findMinistry(int $ministryId): ?array;

    /**
     * @return array{campus_id:int,campus_name:string,ministry_id:int,name:string}|null
     */
    public function resolveScheduleRoute(string $campusSlug, string $ministrySlug): ?array;

    /**
     * @param list<int> $campusIds
     * @return array{ministry_id:int,name:string,campus_id:?int}|null
     */
    public function resolveScheduleRouteByMinistry(string $ministrySlug, array $campusIds = []): ?array;

    /**
     * @param list<string> $campusSlugs
     * @return list<array{campus_id:int,campus_name:string}>
     */
    public function resolveCampuses(array $campusSlugs): array;

    /**
     * @param list<int> $ministryIds
     * @return list<array{
     *   ministry_id:int,
     *   name:string,
     *   campus_id:?int,
     *   past_assignment_count:int,
     *   upcoming_assignment_count:int,
     *   next_occurrence_at:?DateTimeImmutable,
     *   last_occurrence_at:?DateTimeImmutable,
     *   upcoming_occurrences:list<array{
     *     occurrence_id:int,
     *     event_title:string,
     *     starts_on:DateTimeImmutable,
     *     ends_on:DateTimeImmutable,
     *     assignment_count:int
     *   }>
     * }>
     */
    public function fetchDashboard(
        array $ministryIds,
        DateTimeImmutable $since,
        DateTimeImmutable $upcomingStart,
        DateTimeImmutable $until,
    ): array;

    /**
     * @return array{
     *   ministry_id:int,
     *   ministry_name:string,
     *   campus_id:?int,
     *   since:DateTimeImmutable,
     *   members:list<array<string, mixed>>
     * }
     */
    public function fetchRoster(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array;

    /**
     * @return list<array{
     *   person_id:int,
     *   display_name:string,
     *   primary_campus_id:?int,
     *   assignment_count:int,
     *   last_served_at:?DateTimeImmutable,
     *   roles_served:string,
     *   ministry_count:int,
     *   ministries:string
     * }>
     */
    public function fetchPeopleDirectory(
        DateTimeImmutable $since,
        ?int $campusId = null,
        ?int $personId = null,
    ): array;

    /**
     * Fetch all serving roles for a ministry.
     *
     * @return list<array{
     *   id:int,
     *   name:string,
     *   sort_order:int,
     *   is_active:bool,
     *   assigned_count:int,
     *   assigned_members:list<array{person_id:int,display_name:string}>
     * }>
     */
    public function fetchMinistryRoles(int $ministryId): array;

    /**
     * Fetch the leaders (role 'leader') of a ministry.
     *
     * @return list<array{
     *   person_id:int,
     *   display_name:string,
     *   primary_campus_id:?int,
     *   assignment_count:int,
     *   last_served_at:?DateTimeImmutable,
     *   roles_served:string,
     *   leader_roles:list<string>,
     *   regular_roles:list<string>
     * }>
     */
    public function fetchMinistryLeaders(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array;

    /**
     * Create a new role for a ministry.
     *
     * @param array{name:string,order:int,active:bool} $data
     * @return array{id:int,name:string,sort_order:int,is_active:bool}
     */
    public function createMinistryRole(int $ministryId, array $data): array;

    /**
     * Update an existing role.
     *
     * @param array{name?:string,order?:int,active?:bool} $data
     * @return array{id:int,name:string,sort_order:int,is_active:bool,assigned_count:int,assigned_members:list<array{person_id:int,display_name:string}>}
     */
    public function updateMinistryRole(int $roleId, array $data): array;

    /**
     * Delete a role.
     */
    public function deleteMinistryRole(int $roleId): bool;

    /**
     * Make a person a member of a serving role's ministry.
     */
    public function assignPersonToRole(int $personId, int $roleId): bool;

    /**
     * No person-to-serving-role link exists outside the schedule; kept for
     * the API route, always false.
     */
    public function removePersonFromRole(int $personId, int $roleId): bool;

    /**
     * Admin listing of all ministries with member/leader/serving-role counts.
     *
     * @return list<array{ministry_id:int,name:string,active:bool,campus_id:?int,member_count:int,leader_count:int,role_count:int}>
     */
    public function listMinistriesAdmin(?int $campusId = null): array;

    /**
     * @param array{name:string,description?:string,campus_id?:?int} $data
     * @return array<string,mixed>
     */
    public function createMinistry(array $data): array;

    /**
     * @param array{name:string,description?:string,campus_id?:?int} $data
     * @return array<string,mixed>
     */
    public function updateMinistry(int $ministryId, array $data): array;

    public function setMinistryActive(int $ministryId, bool $active): bool;

    public function deleteMinistry(int $ministryId): bool;

    /**
     * @return list<array{person_id:int,display_name:string,role:string,role_name:string,is_leader:bool,positions:list<string>}>
     */
    public function listMinistryMembers(int $ministryId, ?int $campusId = null): array;

    /** @param list<int> $personIds @return array<int,list<int>> */
    public function listMinistryIdsForPeople(array $personIds): array;

    /**
     * Add or update a membership with role 'member' or 'leader'.
     */
    public function setMemberRole(int $personId, int $ministryId, string $role): bool;

    /**
     * Replace a member's positions ("Usher", "Emcee") in a ministry. False
     * when the person is not a member of it.
     *
     * @param list<string> $positions
     */
    public function setMemberPositions(int $personId, int $ministryId, array $positions): bool;

    public function removeMemberFromMinistry(int $personId, int $ministryId): bool;

    public function addMinistryLeader(int $ministryId, int $personId): bool;

    public function removeMinistryLeader(int $ministryId, int $personId): bool;

    /**
     * @return list<array{ministry_id:int,person_id:int,display_name:string}>
     */
    public function listLeadersByMinistry(?int $campusId = null): array;

    /**
     * @return list<array{id:string,name:string,is_default:bool}>
     */
    public function listGroupRoles(int $ministryId): array;
}
