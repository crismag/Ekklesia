<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTO\Availability\AvailabilityCommand;
use App\DTO\Availability\UnavailabilityEntry;
use App\Exceptions\ValidationFailed;
use App\Http\Requests\PortalRequestContext;
use App\Services\AvailabilityService;
use DateTimeImmutable;

final readonly class AvailabilityController
{
    public function __construct(
        private AvailabilityService $availabilityService,
        private PortalRequestContext $requestContext,
    ) {
    }

    /**
     * GET /api/availability?person_id=&active_from=YYYY-MM-DD
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function index(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);

        // Default to the actor's own person link when the caller doesn't
        // pass an explicit person_id. This makes the member-facing default
        // case "show me my own list" work without parameters.
        $personId = (int) ($request['person_id'] ?? 0);
        if ($personId <= 0) {
            if ($actor->personId === null) {
                throw new ValidationFailed('person_id is required (your account is not linked to a person record).');
            }
            $personId = $actor->personId;
        }

        $activeFrom = null;
        if (isset($request['active_from']) && $request['active_from'] !== '') {
            $activeFrom = new DateTimeImmutable((string) $request['active_from']);
        }

        $entries = $this->availabilityService->listForPerson($actor, $personId, $activeFrom);

        return [
            'personId' => $personId,
            'activeFrom' => $activeFrom?->format('Y-m-d'),
            'entries' => array_map(
                static fn (UnavailabilityEntry $e): array => $e->toArray(),
                $entries,
            ),
        ];
    }

    /**
     * POST /api/availability
     * Body: { person_id?, starts_on, ends_on, reason? }
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function create(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);

        $personId = (int) ($request['person_id'] ?? 0);
        if ($personId <= 0) {
            if ($actor->personId === null) {
                throw new ValidationFailed('person_id is required (your account is not linked to a person record).');
            }
            $personId = $actor->personId;
        }

        $startsOnRaw = (string) ($request['starts_on'] ?? '');
        $endsOnRaw   = (string) ($request['ends_on']   ?? '');
        if ($startsOnRaw === '' || $endsOnRaw === '') {
            throw new ValidationFailed('Both starts_on and ends_on are required (YYYY-MM-DD).');
        }

        $reason = isset($request['reason']) ? trim((string) $request['reason']) : '';
        $command = new AvailabilityCommand(
            personId: $personId,
            startsOn: new DateTimeImmutable($startsOnRaw),
            endsOn:   new DateTimeImmutable($endsOnRaw),
            reason:   $reason === '' ? null : $reason,
        );

        $entry = $this->availabilityService->create($actor, $command);
        return $entry->toArray();
    }

    /**
     * DELETE /api/availability/{id}   (or POST with id= for older clients)
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function delete(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id    = (int) ($request['id'] ?? 0);
        if ($id <= 0) {
            throw new ValidationFailed('Availability id is required.');
        }
        $this->availabilityService->delete($actor, $id);
        return ['ok' => true, 'id' => $id];
    }
}
