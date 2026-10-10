<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ValidationFailed;
use App\Http\Requests\PortalRequestContext;
use App\Services\RosterScheduleService;

final readonly class RosterScheduleController
{
    public function __construct(
        private RosterScheduleService $service,
        private PortalRequestContext $requestContext,
    ) {
    }

    public function listRosters(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $ministryId = isset($request['ministry_id']) && $request['ministry_id'] !== ''
            ? (int) $request['ministry_id']
            : null;
        return ['rosters' => $this->service->listRosters($actor, $ministryId)];
    }

    public function loadRoster(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $rosterId = (int) ($request['id'] ?? 0);
        if ($rosterId <= 0) throw new ValidationFailed('Roster id is required.');
        $roster = $this->service->loadRoster($actor, $rosterId);
        if ($roster === null) throw new ValidationFailed('Roster not found.');
        return ['roster' => $roster, 'memberTypes' => $this->service->listMemberTypes()];
    }

    public function createRoster(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $data = [
            'title'       => (string) ($request['title']    ?? ''),
            'subtitle'    => $request['subtitle']  ?? null,
            'ministryId'  => isset($request['ministryId']) && $request['ministryId'] !== '' ? (int) $request['ministryId'] : null,
            'campusId'    => isset($request['campusId'])   && $request['campusId']   !== '' ? (int) $request['campusId']   : null,
            'startsOn'    => (string) ($request['startsOn'] ?? ''),
            'endsOn'      => (string) ($request['endsOn']   ?? ''),
            'notes'       => $request['notes']     ?? null,
            'isPublished' => array_key_exists('isPublished', $request) ? (bool) $request['isPublished'] : true,
        ];
        $newId = $this->service->createRoster($actor, $data);
        return ['ok' => true, 'rosterId' => $newId];
    }

    public function updateRoster(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $rosterId = (int) ($request['id'] ?? 0);
        if ($rosterId <= 0) throw new ValidationFailed('Roster id is required.');

        $patch = [];
        foreach (['title','subtitle','ministryId','campusId','startsOn','endsOn','notes','isPublished'] as $k) {
            if (array_key_exists($k, $request)) $patch[$k] = $request[$k];
        }
        $this->service->updateRosterMeta($actor, $rosterId, $patch);
        if (array_key_exists('slots', $request) && is_array($request['slots'])) {
            $this->service->saveRosterSlots($actor, $rosterId, $request['slots']);
        }
        return ['ok' => true];
    }

    public function deleteRoster(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $rosterId = (int) ($request['id'] ?? 0);
        if ($rosterId <= 0) throw new ValidationFailed('Roster id is required.');
        $this->service->deleteRoster($actor, $rosterId);
        return ['ok' => true];
    }

    /**
     * GET /api/rosters/people-pool
     * People who can be assigned to a slot. Filters compose; see service docs.
     */
    public function peoplePool(array $request): array
    {
        $this->requestContext->fromArray($request); // assert authenticated
        $ministryId = isset($request['ministry_id'])  && $request['ministry_id']  !== '' ? (int) $request['ministry_id']  : null;
        $campusId   = isset($request['campus_id'])    && $request['campus_id']    !== '' ? (int) $request['campus_id']    : null;
        $includeAll = !empty($request['all_members']);
        $search     = isset($request['q']) ? (string) $request['q'] : null;

        // member_type_ids may arrive as 'a,b,c' OR as repeated query params.
        $rawTypes = $request['member_type_ids'] ?? '';
        if (is_array($rawTypes)) {
            $ids = array_values(array_filter(array_map('intval', $rawTypes)));
        } else {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $rawTypes))));
        }

        return [
            'people'      => $this->service->resolvePeoplePool($ministryId, $includeAll, $ids, $campusId, $search),
            'memberTypes' => $this->service->listMemberTypes(),
        ];
    }
}
