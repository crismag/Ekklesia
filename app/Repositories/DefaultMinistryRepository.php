<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\MinistryAdapter;
use App\Contracts\MinistryRepository;
use App\Exceptions\ValidationFailed;
use DateTimeImmutable;

/**
 * Source-agnostic ministry repository.
 *
 * Composes a MinistryAdapter (the source-specific data layer) into the shape
 * the service expects. Zero SQL: a different data source is a different
 * adapter binding; this repository is unchanged.
 */
final class DefaultMinistryRepository implements MinistryRepository
{
    public function __construct(
        private readonly MinistryAdapter $adapter,
    ) {
    }

    /**
     * @return array{ministry_id:int, name:string, campus_id:?int}|null
     */
    public function findMinistry(int $ministryId): ?array
    {
        return $this->adapter->findMinistry($ministryId);
    }

    /**
     * @param list<int> $ministryIds
     * @return list<array{ministry_id:int,name:string,campus_id:?int}>
     */
    public function listMinistries(array $ministryIds = []): array
    {
        return $this->adapter->listMinistries($ministryIds);
    }

    /**
     * @return list<array{campus_id:int,campus_name:string}>
     */
    public function listCampuses(): array
    {
        return $this->adapter->listCampuses();
    }

    public function findPrimaryCampusIdForPerson(int $personId): ?int
    {
        return $this->adapter->findPrimaryCampusIdForPerson($personId);
    }

    public function findDisplayNameForPerson(int $personId): ?string
    {
        return $this->adapter->findDisplayNameForPerson($personId);
    }

    /**
     * @return array{campus_id:int,campus_name:string,ministry_id:int,name:string}|null
     */
    public function resolveScheduleRoute(string $campusSlug, string $ministrySlug): ?array
    {
        return $this->adapter->resolveScheduleRoute($campusSlug, $ministrySlug);
    }

    /**
     * @param list<int> $campusIds
     * @return array{ministry_id:int,name:string,campus_id:?int}|null
     */
    public function resolveScheduleRouteByMinistry(string $ministrySlug, array $campusIds = []): ?array
    {
        return $this->adapter->resolveScheduleRouteByMinistry($ministrySlug, $campusIds);
    }

    /**
     * @param list<string> $campusSlugs
     * @return list<array{campus_id:int,campus_name:string}>
     */
    public function resolveCampuses(array $campusSlugs): array
    {
        return $this->adapter->resolveCampuses($campusSlugs);
    }

    /**
     * @param list<int> $ministryIds
     * @return list<array<string,mixed>>
     */
    public function fetchDashboard(
        array $ministryIds,
        DateTimeImmutable $since,
        DateTimeImmutable $upcomingStart,
        DateTimeImmutable $until,
    ): array
    {
        return $this->adapter->listMinistryDashboard($ministryIds, $since, $upcomingStart, $until);
    }

    /**
     * @return array{
     *   ministry_id:int,
     *   ministry_name:string,
     *   campus_id:?int,
     *   since:DateTimeImmutable,
     *   members:list<array<string, mixed>>
     * }
     */
    public function fetchRoster(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array
    {
        $ministry = $this->adapter->findMinistry($ministryId);
        if ($ministry === null) {
            throw new ValidationFailed("Ministry $ministryId does not exist.");
        }

        $members = $this->adapter->listMinistryRoster($ministryId, $since, $campusId);

        return [
            'ministry_id'   => $ministry['ministry_id'],
            'ministry_name' => $ministry['name'],
            'campus_id'     => $ministry['campus_id'],
            'since'         => $since,
            'members'       => $members,
        ];
    }

    public function fetchPeopleDirectory(
        DateTimeImmutable $since,
        ?int $campusId = null,
        ?int $personId = null,
    ): array {
        return $this->adapter->listPeopleDirectory($since, $campusId, $personId);
    }

    public function fetchMinistryRoles(int $ministryId): array
    {
        return $this->adapter->fetchMinistryRoles($ministryId);
    }

    public function fetchMinistryLeaders(int $ministryId, DateTimeImmutable $since, ?int $campusId = null): array
    {
        return $this->adapter->fetchMinistryLeaders($ministryId, $since, $campusId);
    }

    public function createMinistryRole(int $ministryId, array $data): array
    {
        return $this->adapter->createMinistryRole($ministryId, $data);
    }

    public function updateMinistryRole(int $roleId, array $data): array
    {
        return $this->adapter->updateMinistryRole($roleId, $data);
    }

    public function deleteMinistryRole(int $roleId): bool
    {
        return $this->adapter->deleteMinistryRole($roleId);
    }

    public function assignPersonToRole(int $personId, int $roleId): bool
    {
        return $this->adapter->assignPersonToRole($personId, $roleId);
    }

    public function removePersonFromRole(int $personId, int $roleId): bool
    {
        return $this->adapter->removePersonFromRole($personId, $roleId);
    }

    public function listMinistriesAdmin(?int $campusId = null): array
    {
        return $this->adapter->listMinistriesAdmin($campusId);
    }

    public function createMinistry(array $data): array
    {
        return $this->adapter->createMinistry($data);
    }

    public function updateMinistry(int $ministryId, array $data): array
    {
        return $this->adapter->updateMinistry($ministryId, $data);
    }

    public function setMinistryActive(int $ministryId, bool $active): bool
    {
        return $this->adapter->setMinistryActive($ministryId, $active);
    }

    public function deleteMinistry(int $ministryId): bool
    {
        return $this->adapter->deleteMinistry($ministryId);
    }

    public function listMinistryMembers(int $ministryId, ?int $campusId = null): array
    {
        return $this->adapter->listMinistryMembers($ministryId, $campusId);
    }

    public function listMinistryIdsForPeople(array $personIds): array
    {
        return $this->adapter->listMinistryIdsForPeople($personIds);
    }

    public function setMemberRole(int $personId, int $ministryId, string $role): bool
    {
        return $this->adapter->setMemberRole($personId, $ministryId, $role);
    }

    public function setMemberPositions(int $personId, int $ministryId, array $positions): bool
    {
        return $this->adapter->setMemberPositions($personId, $ministryId, $positions);
    }

    public function removeMemberFromMinistry(int $personId, int $ministryId): bool
    {
        return $this->adapter->removeMemberFromMinistry($personId, $ministryId);
    }

    public function addMinistryLeader(int $ministryId, int $personId): bool
    {
        return $this->adapter->addMinistryLeader($ministryId, $personId);
    }

    public function removeMinistryLeader(int $ministryId, int $personId): bool
    {
        return $this->adapter->removeMinistryLeader($ministryId, $personId);
    }

    public function listLeadersByMinistry(?int $campusId = null): array
    {
        return $this->adapter->listLeadersByMinistry($campusId);
    }

    public function listGroupRoles(int $ministryId): array
    {
        return $this->adapter->listGroupRoles($ministryId);
    }
}
