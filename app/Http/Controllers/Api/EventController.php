<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTO\Events\EventCreateCommand;
use App\DTO\Events\EventUpdateCommand;
use App\DTO\Events\OccurrenceGenerateCommand;
use App\Providers\PortalServiceProvider;
use App\Services\EventService;
use App\Http\Requests\PortalRequestContext;
use DateTimeImmutable;

final readonly class EventController
{
    public function __construct(
        private EventService $service,
        private PortalRequestContext $requestContext,
    ) {
    }

    public function list(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $limit = (int) ($request['limit'] ?? 50);
        $list = $this->service->listUpcoming($actor, $limit);
        return ['events' => array_map(fn($e) => $e->toArray(), $list)];
    }

    public function publicList(array $request): array
    {
        $limit = (int) ($request['limit'] ?? 50);
        $campusId = isset($request['current_campus_id']) && (int) $request['current_campus_id'] > 0
            ? (int) $request['current_campus_id']
            : null;
        $list = $this->service->listUpcomingPublic($limit, $campusId);
        return ['events' => array_map(fn($e) => $e->toArray(), $list)];
    }

    public function show(array $request): array
    {
        try {
            $actor = $this->requestContext->fromArray($request);
        } catch (\App\Exceptions\PermissionDenied) {
            $actor = null;
        }
        $id = (int) ($request['id'] ?? 0);
        $start = isset($request['start']) ? new DateTimeImmutable((string) $request['start']) : new DateTimeImmutable('-7 days');
        $end = isset($request['end']) ? new DateTimeImmutable((string) $request['end']) : new DateTimeImmutable('+90 days');
        $detail = $this->service->getEvent($actor, $id, $start, $end);
        return $detail === null ? ['event' => null] : $detail->toArray();
    }

    public function create(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $title = (string) ($request['title'] ?? '');
        if ($title === '') throw new \App\Exceptions\ValidationFailed('title is required');
        $selected = $request['selectedDates'] ?? $request['selected_dates'] ?? [];
        if (is_string($selected)) {
            $selected = array_filter(array_map('trim', explode(',', $selected)));
        }
        $str = static function (mixed $v): ?string {
            $v = is_string($v) ? trim($v) : '';

            return $v !== '' ? $v : null;
        };
        $cmd = new EventCreateCommand(
            title: $title,
            description: $str($request['description'] ?? null),
            startDate: $str($request['startDate'] ?? ($request['start_date'] ?? null)),
            startTime: $str($request['startTime'] ?? ($request['start_time'] ?? null)),
            endTime: $str($request['endTime'] ?? ($request['end_time'] ?? null)),
            allDay: filter_var($request['allDay'] ?? ($request['all_day'] ?? false), FILTER_VALIDATE_BOOLEAN),
            pattern: (string) ($request['pattern'] ?? 'one_off'),
            count: isset($request['count']) && $request['count'] !== '' ? (int) $request['count'] : null,
            untilOn: $str($request['untilOn'] ?? ($request['until_on'] ?? null)),
            selectedDates: is_array($selected) ? array_values($selected) : [],
            campusIds: $this->campusIdsFromRequest($request),
            hostCampusId: isset($request['hostCampusId']) && (int) $request['hostCampusId'] > 0 ? (int) $request['hostCampusId'] : null,
            locationName: $str($request['locationName'] ?? ($request['location_name'] ?? null)),
            locationAddress: $str($request['locationAddress'] ?? ($request['location_address'] ?? null)),
            ministryId: isset($request['ministryId']) && (int) $request['ministryId'] > 0 ? (int) $request['ministryId'] : null,
            eventTypeId: $this->eventTypeIdFromRequest($request),
            defaultDurationMin: isset($request['defaultDurationMin']) ? (int) $request['defaultDurationMin'] : 90,
            usesServingSchedule: $this->boolFromRequest($request, 'usesServingSchedule', 'uses_serving_schedule', false) ?? false,
        );
        $detail = $this->service->createEvent($actor, $cmd);

        // Tags after the event exists, because they hang off its id. A tagging
        // failure must not lose the event that was just created, so it is
        // reported rather than thrown: the event is real either way.
        $out = $detail->toArray();
        $rawTags = $request['tags'] ?? null;
        if ($rawTags !== null) {
            if (is_string($rawTags)) {
                $rawTags = \App\Services\Events\TagName::split($rawTags);
            }
            if (is_array($rawTags) && $rawTags !== []) {
                try {
                    $out['tags'] = $this->service->setEventTags($actor, $detail->eventId, array_values($rawTags));
                } catch (\App\Exceptions\ValidationFailed $e) {
                    $out['tag_error'] = $e->getMessage();
                }
            }
        }

        return $out;
    }

    public function update(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = (int) ($request['id'] ?? 0);
        if ($id <= 0) throw new \App\Exceptions\ValidationFailed('id required');
        $cmd = new EventUpdateCommand(
            title: $request['title'] ?? null,
            description: $request['description'] ?? null,
            campusIds: array_key_exists('campusIds', $request) || array_key_exists('campus_ids', $request)
                ? $this->campusIdsFromRequest($request)
                : null,
            eventTypeId: $this->eventTypeIdFromRequest($request),
            ministryId: array_key_exists('ministryId', $request) || array_key_exists('ministry_id', $request)
                ? (int) ($request['ministryId'] ?? $request['ministry_id'] ?? 0)
                : null,
            usesServingSchedule: $this->boolFromRequest($request, 'usesServingSchedule', 'uses_serving_schedule'),
        );
        $detail = $this->service->updateEvent($actor, $id, $cmd);

        $out = $detail->toArray();
        // Absent means "leave them alone"; an empty list means "remove them".
        // The two have to stay distinguishable or clearing tags is impossible.
        if (array_key_exists('tags', $request)) {
            $rawTags = $request['tags'];
            if (is_string($rawTags)) {
                $rawTags = \App\Services\Events\TagName::split($rawTags);
            }
            if (is_array($rawTags)) {
                $out['tags'] = $this->service->setEventTags($actor, $id, array_values($rawTags));
            }
        }

        return $out;
    }

    /**
     * Accepts either casing, as every other field here does. Null means "not
     * supplied": on create the adapter substitutes the default type, and on
     * update the existing type is left alone.
     *
     * @param array<string,mixed> $request
     */
    private function eventTypeIdFromRequest(array $request): ?int
    {
        $raw = $request['eventTypeId'] ?? $request['event_type_id'] ?? null;

        return $raw !== null && (int) $raw > 0 ? (int) $raw : null;
    }

    private function boolFromRequest(array $request, string $camel, string $snake, ?bool $default = null): ?bool
    {
        if (!array_key_exists($camel, $request) && !array_key_exists($snake, $request)) {
            return $default;
        }
        $raw = $request[$camel] ?? $request[$snake];
        if ($raw === null) {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public function generateOccurrences(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $eventId = (int) ($request['id'] ?? 0);
        if ($eventId <= 0) throw new \App\Exceptions\ValidationFailed('event id required');
        $pattern = (string) ($request['pattern'] ?? 'one_off');
        $startsAt = new DateTimeImmutable((string) ($request['startsAt'] ?? ''));
        $duration = (int) ($request['durationMin'] ?? 90);
        $count = isset($request['count']) ? (int) $request['count'] : null;
        $until = isset($request['untilOn']) && $request['untilOn'] !== '' ? new DateTimeImmutable((string) $request['untilOn']) : null;
        $cmd = new OccurrenceGenerateCommand(eventId: $eventId, pattern: $pattern, startsAt: $startsAt, durationMin: $duration, count: $count, untilOn: $until);
        $res = $this->service->generateOccurrences($actor, $cmd);
        return $res->toArray();
    }

    /**
     * Patch one occurrence: its time, its own title and description, or both.
     *
     * A field that is absent is left alone; a field present and empty clears
     * the override, so a date can go back to inheriting from its series. The
     * two are separable because moving a date and renaming it are different
     * decisions that happen to share a resource.
     */
    public function rescheduleOccurrence(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = (int) ($request['id'] ?? 0);
        if ($id <= 0) throw new \App\Exceptions\ValidationFailed('occurrence id required');

        $out = ['ok' => true, 'id' => $id];

        $raw = trim((string) ($request['startsAt'] ?? $request['starts_at'] ?? ''));
        if ($raw !== '') {
            $duration = isset($request['durationMin']) && $request['durationMin'] !== ''
                ? (int) $request['durationMin']
                : null;
            $this->service->rescheduleOccurrence($actor, $id, new DateTimeImmutable($raw), $duration);
        }

        $hasTitle = array_key_exists('title', $request) || array_key_exists('overrideTitle', $request);
        $hasDesc = array_key_exists('description', $request) || array_key_exists('overrideDesc', $request);
        if ($hasTitle || $hasDesc) {
            $out['override'] = $this->service->overrideOccurrence(
                $actor,
                $id,
                $hasTitle ? (string) ($request['title'] ?? $request['overrideTitle'] ?? '') : null,
                $hasDesc ? (string) ($request['description'] ?? $request['overrideDesc'] ?? '') : null,
            );
        } elseif ($raw === '') {
            throw new \App\Exceptions\ValidationFailed(
                'Nothing to change: supply a start time, a title or a description.'
            );
        }

        return $out;
    }

    /** Drop an occurrence's own title and description. */
    public function clearOccurrenceOverride(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = (int) ($request['id'] ?? 0);
        if ($id <= 0) throw new \App\Exceptions\ValidationFailed('occurrence id required');
        $this->service->clearOccurrenceOverride($actor, $id);

        return ['ok' => true, 'id' => $id, 'override' => null];
    }

    /** Put a whole series on a corrected time of day, keeping its dates. */
    public function retimeOccurrences(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $eventId = (int) ($request['id'] ?? 0);
        if ($eventId <= 0) throw new \App\Exceptions\ValidationFailed('event id required');
        $time = trim((string) ($request['timeOfDay'] ?? $request['time_of_day'] ?? ''));
        $duration = isset($request['durationMin']) && $request['durationMin'] !== ''
            ? (int) $request['durationMin']
            : null;
        $scope = (string) ($request['scope'] ?? 'upcoming');
        $from = trim((string) ($request['from'] ?? ''));
        $moved = $this->service->retimeEventOccurrences(
            $actor, $eventId, $time, $duration, $scope, $from === '' ? null : $from,
        );

        return ['ok' => true, 'moved' => $moved];
    }

    /**
     * Replace a repeating event's schedule.
     *
     * Retiming keeps the dates and moves the clock. This changes which dates
     * exist, so it takes the same shape as creating one.
     */
    public function replaceSchedule(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $eventId = (int) ($request['id'] ?? 0);
        if ($eventId <= 0) throw new \App\Exceptions\ValidationFailed('event id required');

        $str = static function (mixed $v): ?string {
            $v = is_string($v) ? trim($v) : $v;

            return $v === null || $v === '' ? null : (string) $v;
        };
        $cmd = new \App\DTO\Events\EventCreateCommand(
            // Title is required by the command but irrelevant here: only the
            // schedule fields are read, and the event keeps its own name.
            title: 'schedule',
            startDate: $str($request['startDate'] ?? $request['start_date'] ?? null),
            startTime: $str($request['startTime'] ?? $request['start_time'] ?? null),
            endTime: $str($request['endTime'] ?? $request['end_time'] ?? null),
            allDay: !empty($request['allDay']) || !empty($request['all_day']),
            pattern: (string) ($request['pattern'] ?? 'one_off'),
            count: isset($request['count']) && $request['count'] !== '' ? (int) $request['count'] : null,
            untilOn: $str($request['untilOn'] ?? $request['until_on'] ?? null),
            selectedDates: is_array($request['selectedDates'] ?? null) ? $request['selectedDates'] : [],
        );

        return $this->service->replaceSchedule($actor, $eventId, $cmd, !empty($request['force']));
    }

    /** Every tag in use, for a picker. */
    public function listTags(array $request): array
    {
        $this->requestContext->fromArray($request);

        return ['tags' => $this->service->listTags()];
    }

    /** Replace one event's tags with exactly the list supplied. */
    public function setTags(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = (int) ($request['id'] ?? 0);
        if ($id <= 0) throw new \App\Exceptions\ValidationFailed('event id required');

        $raw = $request['tags'] ?? [];
        // Accept a typed string as readily as a list: the editor sends one
        // field, an API client is likelier to send an array.
        if (is_string($raw)) {
            $raw = \App\Services\Events\TagName::split($raw);
        }
        if (!is_array($raw)) {
            throw new \App\Exceptions\ValidationFailed('tags must be a list or a comma-separated string.');
        }

        return ['tags' => $this->service->setEventTags($actor, $id, array_values($raw))];
    }

    /** Remove an event filed by mistake, with its occurrences. */
    public function destroy(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = (int) ($request['id'] ?? 0);
        if ($id <= 0) throw new \App\Exceptions\ValidationFailed('event id required');
        $deleted = $this->service->deleteEvent($actor, $id, !empty($request['force']));
        if (!$deleted) throw new \App\Exceptions\ValidationFailed('Event not found.');

        return ['ok' => true, 'id' => $id];
    }

    /** Delete a whole series, its future, or a chosen set of occurrences. */
    public function deleteOccurrences(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $eventId = (int) ($request['id'] ?? 0);
        if ($eventId <= 0) throw new \App\Exceptions\ValidationFailed('event id required');
        $scope = (string) ($request['scope'] ?? 'upcoming');
        $ids = is_array($request['occurrenceIds'] ?? null) ? $request['occurrenceIds'] : [];
        $force = !empty($request['force']);
        $deleted = $this->service->deleteEventOccurrences($actor, $eventId, $scope, $ids, $force);

        return ['ok' => true, 'deleted' => $deleted];
    }

    /**
     * DELETE removes the date. Cancelling is a different act with its own
     * endpoint below: one erases a mistake, the other publishes "no service
     * this week".
     */
    public function cancelOccurrence(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = (int) ($request['id'] ?? 0);
        if ($id <= 0) throw new \App\Exceptions\ValidationFailed('occurrence id required');
        $force = isset($request['force']) && (int) $request['force'] === 1;
        $this->service->deleteOccurrence($actor, $id, $force);
        return ['ok' => true, 'id' => $id];
    }

    /** Mark one occurrence cancelled — the date stays, the meeting is off. */
    public function markOccurrenceCancelled(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = (int) ($request['id'] ?? 0);
        if ($id <= 0) throw new \App\Exceptions\ValidationFailed('occurrence id required');
        $this->service->cancelOccurrence($actor, $id);
        return ['ok' => true, 'id' => $id, 'cancelled' => true];
    }

    /** Put a cancelled occurrence back. */
    public function restoreOccurrence(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $id = (int) ($request['id'] ?? 0);
        if ($id <= 0) throw new \App\Exceptions\ValidationFailed('occurrence id required');
        $this->service->restoreOccurrence($actor, $id);
        return ['ok' => true, 'id' => $id, 'cancelled' => false];
    }

    /** @return list<int> */
    private function campusIdsFromRequest(array $request): array
    {
        $raw = $request['campusIds'] ?? $request['campus_ids'] ?? [];
        if (!is_array($raw)) {
            throw new \App\Exceptions\ValidationFailed('campusIds must be an array.');
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            fn (int $id): bool => $id > 0,
        )));
    }
}
