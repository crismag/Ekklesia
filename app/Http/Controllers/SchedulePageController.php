<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\ActorContext;
use App\Http\Requests\PortalRequestContext;
use App\Services\ScheduleService;
use DateTimeImmutable;

final readonly class SchedulePageController
{
    public function __construct(
        private ScheduleService $scheduleService,
        private PortalRequestContext $requestContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function index(array $request): array
    {
        $ministryId = (int) ($request['ministry_id'] ?? 0);
        $start = new DateTimeImmutable((string) ($request['start'] ?? 'now'));
        $end = new DateTimeImmutable((string) ($request['end'] ?? $start->modify('+28 days')->format('Y-m-d')));

        $grid = $this->scheduleService->getScheduleGrid(
            context: $this->contextFromRequest($request),
            ministryId: $ministryId,
            start: $start,
            end: $end,
            requestedEventIds: $this->eventIdsFromRequest($request),
        );

        return [
            'component' => 'Schedules/Index',
            'props' => [
                'initialGrid' => $grid->toArray(),
            ],
        ];
    }

    private function contextFromRequest(array $request): ActorContext
    {
        return $this->requestContext->fromArray($request);
    }

    /**
     * @param array<string, mixed> $request
     * @return list<int>|null
     */
    private function eventIdsFromRequest(array $request): ?array
    {
        if (!array_key_exists('event_ids', $request) && !array_key_exists('eventIds', $request)) {
            return null;
        }
        $raw = $request['event_ids'] ?? $request['eventIds'];
        if (is_string($raw)) {
            $raw = preg_split('/[,\s]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
