<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ValidationFailed;
use App\Http\Requests\PortalRequestContext;
use App\Services\ScheduleService;
use DateTimeImmutable;

final readonly class MyScheduleController
{
    public function __construct(
        private ScheduleService $scheduleService,
        private PortalRequestContext $requestContext,
    ) {
    }

    /**
     * GET /api/my-schedule
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function index(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);

        if ($actor->personId === null) {
            throw new ValidationFailed('Portal user is not linked to a person record.');
        }

        $start = new DateTimeImmutable((string) ($request['start'] ?? 'now'));
        $end   = new DateTimeImmutable((string) ($request['end'] ?? $start->modify('+90 days')->format('Y-m-d')));

        $view = $this->scheduleService->getMySchedule(
            context: $actor,
            personId: $actor->personId,
            start: $start,
            end: $end,
        );

        return $view->toArray();
    }
}
