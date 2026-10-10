<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\Exceptions\ValidationFailed;
use App\Http\Requests\PortalRequestContext;
use App\Services\MinistryService;
use DateTimeImmutable;

final readonly class PeopleController
{
    public function __construct(
        private MinistryService $ministryService,
        private PortalRequestContext $requestContext,
    ) {
    }

    /**
     * GET /api/people-directory?since=YYYY-MM-DD&campus_id=<id>
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function index(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $since = new DateTimeImmutable((string) ($request['since'] ?? '-180 days'));

        // campus_id="all" (or "0") explicitly requests every campus and skips
        // the actor's current-campus fallback; a positive id filters to that
        // campus; omitting it falls back to the actor's current campus.
        $rawCampus = $request['campus_id'] ?? null;
        $explicitAll = $rawCampus === 'all' || $rawCampus === '0' || $rawCampus === 0;

        $campusFilter = null;
        if (!$explicitAll && $rawCampus !== null && $rawCampus !== '') {
            $campusFilter = (int) $rawCampus;
            if ($campusFilter <= 0) {
                $campusFilter = null;
            }
        }
        if (!$explicitAll && $campusFilter === null && $actor->currentCampusId !== null) {
            $campusFilter = $actor->currentCampusId;
        }

        $people = $this->ministryService->listPeopleDirectory($actor, $since, $campusFilter);

        return [
            'since' => $since->format('Y-m-d'),
            'campusFilter' => $campusFilter,
            'peopleCount' => count($people),
            'withMinistryCount' => count(array_filter($people, static fn (array $person): bool => (int) ($person['ministryCount'] ?? 0) > 0)),
            'withAssignmentsCount' => count(array_filter($people, static fn (array $person): bool => (int) ($person['assignmentCount'] ?? 0) > 0)),
            'withoutMinistryCount' => count(array_filter($people, static fn (array $person): bool => (int) ($person['ministryCount'] ?? 0) === 0)),
            'people' => array_map(fn (array $person): array => $this->serializePerson($person, true), $people),
        ];
    }

    /**
     * GET /api/public/people-directory?since=YYYY-MM-DD&campus_id=<id>
     *
     * Public directory access deliberately exposes only non-sensitive profile
     * fields. Logged-in privileged actors receive contact/address data.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function publicIndex(array $request): array
    {
        $publicActor = $this->publicDirectoryActor();
        $actualActor = $this->optionalActor($request);
        $canViewPrivileged = $actualActor !== null && (
            $actualActor->hasPermission(PortalPermission::ViewPersonAvailability)
            || $actualActor->hasPermission(PortalPermission::ViewMinistrySchedule)
            || $actualActor->hasPermission(PortalPermission::ManageSchedules)
        );

        $since = new DateTimeImmutable((string) ($request['since'] ?? '-180 days'));
        $campusFilter = $this->campusFilter($request, null);
        $people = $this->ministryService->listPeopleDirectory($publicActor, $since, $campusFilter);

        return [
            'since' => $since->format('Y-m-d'),
            'campusFilter' => $campusFilter,
            'canViewPrivileged' => $canViewPrivileged,
            'peopleCount' => count($people),
            'withMinistryCount' => count(array_filter($people, static fn (array $person): bool => (int) ($person['ministryCount'] ?? 0) > 0)),
            'withAssignmentsCount' => count(array_filter($people, static fn (array $person): bool => (int) ($person['assignmentCount'] ?? 0) > 0)),
            'withoutMinistryCount' => count(array_filter($people, static fn (array $person): bool => (int) ($person['ministryCount'] ?? 0) === 0)),
            'activeCount' => count(array_filter($people, static fn (array $person): bool => (bool) ($person['isActive'] ?? true))),
            'inactiveCount' => count(array_filter($people, static fn (array $person): bool => !(bool) ($person['isActive'] ?? true))),
            'people' => array_map(fn (array $person): array => $this->serializePerson($person, $canViewPrivileged), $people),
        ];
    }

    /**
     * GET /api/people/{id}?since=YYYY-MM-DD&campus_id=<id>
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function show(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $personId = (int) ($request['id'] ?? 0);
        if ($personId <= 0) {
            throw new ValidationFailed('Person id is required.');
        }

        $since = new DateTimeImmutable((string) ($request['since'] ?? '-180 days'));

        $campusFilter = null;
        if (isset($request['campus_id']) && $request['campus_id'] !== '' && $request['campus_id'] !== null) {
            $campusFilter = (int) $request['campus_id'];
            if ($campusFilter <= 0) {
                $campusFilter = null;
            }
        }
        if ($campusFilter === null && $actor->currentCampusId !== null) {
            $campusFilter = $actor->currentCampusId;
        }

        $person = $this->ministryService->findPeopleDirectoryPerson($actor, $personId, $since, $campusFilter);

        if ($person === null) {
            throw new ValidationFailed("Person $personId was not found in the directory.");
        }

        return [
            'since' => $since->format('Y-m-d'),
            'campusFilter' => $campusFilter,
            'person' => $this->serializePerson($person, true),
        ];
    }

    /**
     * GET /api/public/people/{id}
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function publicShow(array $request): array
    {
        $personId = (int) ($request['id'] ?? 0);
        if ($personId <= 0) {
            throw new ValidationFailed('Person id is required.');
        }

        $actualActor = $this->optionalActor($request);
        $canViewPrivileged = $actualActor !== null && (
            $actualActor->hasPermission(PortalPermission::ViewPersonAvailability)
            || $actualActor->hasPermission(PortalPermission::ViewMinistrySchedule)
            || $actualActor->hasPermission(PortalPermission::ManageSchedules)
        );

        $since = new DateTimeImmutable((string) ($request['since'] ?? '-180 days'));
        $campusFilter = $this->campusFilter($request, null);
        $person = $this->ministryService->findPeopleDirectoryPerson(
            $this->publicDirectoryActor(),
            $personId,
            $since,
            $campusFilter,
        );

        if ($person === null) {
            throw new ValidationFailed("Person $personId was not found in the directory.");
        }

        return [
            'since' => $since->format('Y-m-d'),
            'campusFilter' => $campusFilter,
            'canViewPrivileged' => $canViewPrivileged,
            'person' => $this->serializePerson($person, $canViewPrivileged),
        ];
    }

    /**
     * @param array<string, mixed> $request
     */
    private function campusFilter(array $request, ?ActorContext $actor): ?int
    {
        $campusFilter = null;
        if (isset($request['campus_id']) && $request['campus_id'] !== '' && $request['campus_id'] !== null) {
            $campusFilter = (int) $request['campus_id'];
            if ($campusFilter <= 0) {
                $campusFilter = null;
            }
        }
        if ($campusFilter === null && $actor?->currentCampusId !== null) {
            $campusFilter = $actor->currentCampusId;
        }

        return $campusFilter;
    }

    private function optionalActor(array $request): ?ActorContext
    {
        try {
            return $this->requestContext->fromArray($request);
        } catch (\Throwable) {
            return null;
        }
    }

    private function publicDirectoryActor(): ActorContext
    {
        return new ActorContext(
            actorId: 0,
            personId: null,
            displayName: 'Public people directory',
            permissions: [PortalPermission::ViewMinistrySchedule],
            ministryScopeIds: [],
            isPortalWideAdmin: true,
            campusScopeIds: [],
        );
    }

    /**
     * @param array<string, mixed> $person
     * @return array<string, mixed>
     */
    private function serializePerson(array $person, bool $privileged): array
    {
        $firstName = (string) ($person['firstName'] ?? '');
        $lastName = (string) ($person['lastName'] ?? '');
        $base = [
            'personId' => (int) $person['personId'],
            'displayName' => $privileged ? (string) $person['displayName'] : $this->maskedName($firstName, $lastName, (int) $person['personId']),
            'firstName' => $firstName,
            'lastInitial' => $lastName !== '' ? strtoupper(substr($lastName, 0, 1)) . '.' : '',
            'familyId' => $privileged && $person['familyId'] !== null ? (int) $person['familyId'] : null,
            'familyName' => $privileged ? (string) ($person['familyName'] ?? '') : '',
            'classificationId' => $person['classificationId'] === null ? null : (int) $person['classificationId'],
            'classificationName' => (string) ($person['classificationName'] ?? ''),
            'memberTypeId' => $person['memberTypeId'] === null ? null : (int) $person['memberTypeId'],
            'memberTypeName' => (string) ($person['memberTypeName'] ?? ''),
            'isActive' => (bool) ($person['isActive'] ?? true),
            'birthMonth' => $person['birthMonth'] === null ? null : (int) $person['birthMonth'],
            'birthDay' => $person['birthDay'] === null ? null : (int) $person['birthDay'],
            'birthYear' => $person['birthYear'] === null ? null : (int) $person['birthYear'],
            'dateEntered' => $person['dateEntered'] instanceof DateTimeImmutable ? $person['dateEntered']->format(DATE_ATOM) : null,
            'dateLastEdited' => $person['dateLastEdited'] instanceof DateTimeImmutable ? $person['dateLastEdited']->format(DATE_ATOM) : null,
            'primaryCampusId' => $person['primaryCampusId'] === null ? null : (int) $person['primaryCampusId'],
            'primaryCampusName' => (string) ($person['primaryCampusName'] ?? ''),
            'assignmentCount' => (int) $person['assignmentCount'],
            'lastServedAt' => $person['lastServedAt'] instanceof DateTimeImmutable ? $person['lastServedAt']->format(DATE_ATOM) : null,
            'rolesServed' => (string) $person['rolesServed'],
            'ministryCount' => (int) $person['ministryCount'],
            'ministries' => (string) $person['ministries'],
            'publicLinks' => $person['publicLinks'] ?? [],
        ];

        if ($privileged) {
            $base['lastName'] = $lastName;
            $base['contact'] = [
                'email' => (string) ($person['email'] ?? ''),
                'mobilePhone' => (string) ($person['mobilePhone'] ?? ''),
                'homePhone' => (string) ($person['homePhone'] ?? ''),
            ];
            $base['address'] = $person['address'] ?? [];
        }

        return $base;
    }

    private function maskedName(string $firstName, string $lastName, int $personId): string
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ($firstName === '') {
            return 'Person #' . $personId;
        }

        return $firstName . ($lastName !== '' ? ' ' . strtoupper(substr($lastName, 0, 1)) . '.' : '');
    }

}
