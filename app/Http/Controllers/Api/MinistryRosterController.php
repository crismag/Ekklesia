<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ValidationFailed;
use App\Http\Requests\PortalRequestContext;
use App\Services\MinistryService;
use DateTimeImmutable;

final readonly class MinistryRosterController
{
    public function __construct(
        private MinistryService $ministryService,
        private PortalRequestContext $requestContext,
    ) {
    }

    /**
     * GET /api/ministry-roster?ministry_id=<id>&since=YYYY-MM-DD&campus_id=<id>
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function index(array $request): array
    {
        $actor      = $this->requestContext->fromArray($request);
        $ministryId = (int) ($request['ministry_id'] ?? 0);
        if ($ministryId <= 0) {
            throw new ValidationFailed('Query parameter ministry_id is required.');
        }

        $since = new DateTimeImmutable((string) ($request['since'] ?? '-180 days'));

        $campusFilter = null;
        if (isset($request['campus_id']) && $request['campus_id'] !== '' && $request['campus_id'] !== null) {
            $campusFilter = (int) $request['campus_id'];
            if ($campusFilter <= 0) {
                $campusFilter = null;
            }
        }
        // Default the campus filter to the actor's current campus selector when present.
        if ($campusFilter === null && $actor->currentCampusId !== null) {
            $campusFilter = $actor->currentCampusId;
        }

        $view = $this->ministryService->getRoster(
            context: $actor,
            ministryId: $ministryId,
            since: $since,
            campusId: $campusFilter,
        );

        return $view->toArray();
    }
}
