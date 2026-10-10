<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EventRepository;
use App\Core\ActorContext;
use App\Core\EventAudience;
use App\Core\PortalPermission;
use App\DTO\Events\EventCreateCommand;
use App\DTO\Events\EventDetailView;
use App\DTO\Events\EventSummary;
use App\DTO\Events\OccurrenceBatchResult;
use App\DTO\Events\OccurrenceGenerateCommand;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use DateInterval;
use DateTimeImmutable;

final readonly class EventService
{
    public function __construct(
        private EventRepository $events,
        // Optional: ministries live in a different database, so the name is
        // resolved for display only. Absent (as in tests) the id still persists.
        private ?MinistryService $ministries = null,
        // Optional too: absent, the type is simply not validated here and the
        // adapter falls back to the default type.
        private ?EventTypeService $eventTypes = null,
    ) {}

    /**
     * Occurrences for the Events agenda, grouped by calendar date.
     *
     * @return array<string,list<array<string,mixed>>> keyed Y-m-d, in order
     */
    public function agenda(
        ?ActorContext $ctx,
        ?int $campusId = null,
        string $range = 'upcoming',
        ?string $anchor = null,
        int $limit = 400
    ): array {
        [$from, $to, $desc] = $this->agendaWindow($range, $anchor);
        $rows = $this->events->listOccurrences($from, $to, $campusId, $limit, $desc, EventAudience::allowedFor($ctx));

        $ministryNames = $this->ministryNames();
        $byDate = [];
        foreach ($rows as $r) {
            $r['ministry_name'] = $r['ministry_id'] !== null ? ($ministryNames[(int) $r['ministry_id']] ?? null) : null;
            $byDate[substr((string) $r['starts_at'], 0, 10)][] = $r;
        }

        return $byDate;
    }

    /** @return array{0:string,1:string,2:bool} from, to, newest-first */
    private function agendaWindow(string $range, ?string $anchor): array
    {
        $base = $anchor !== null && $anchor !== '' ? new DateTimeImmutable($anchor) : new DateTimeImmutable('today');
        return match ($range) {
            // Past reads backwards: the most recent thing is what you want.
            'past' => [(new DateTimeImmutable('today'))->modify('-2 years')->format('Y-m-d H:i:s'),
                       (new DateTimeImmutable('today'))->format('Y-m-d H:i:s'), true],
            'all' => [(new DateTimeImmutable('today'))->modify('-2 years')->format('Y-m-d H:i:s'),
                      (new DateTimeImmutable('today'))->modify('+2 years')->format('Y-m-d H:i:s'), false],
            'month' => [$base->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:s'),
                        $base->modify('first day of this month')->setTime(0, 0)->modify('+1 month')->format('Y-m-d H:i:s'), false],
            'quarter' => (function () use ($base): array {
                $q = (int) floor(((int) $base->format('n') - 1) / 3);
                $from = $base->setDate((int) $base->format('Y'), $q * 3 + 1, 1)->setTime(0, 0);
                return [$from->format('Y-m-d H:i:s'), $from->modify('+3 months')->format('Y-m-d H:i:s'), false];
            })(),
            default => [(new DateTimeImmutable('today'))->format('Y-m-d H:i:s'),
                        (new DateTimeImmutable('today'))->modify('+1 year')->format('Y-m-d H:i:s'), false],
        };
    }

    /** @return array<int,string> */
    private function ministryNames(): array
    {
        $out = [];
        try {
            foreach ($this->ministries?->listMinistriesPublic(null) ?? [] as $m) {
                // listMinistriesPublic() returns rows keyed ministry_id. Reading
                // 'ministryId' first never matched, so every id fell through to
                // 0 and the guard below skipped every row — which is why the
                // ministry link added by migration 008 was inert end to end.
                $id = (int) ($m['ministry_id'] ?? $m['ministryId'] ?? $m['id'] ?? 0);
                if ($id > 0) {
                    $out[$id] = (string) ($m['name'] ?? '');
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    /**
     * Upcoming events anyone may see without a session — audience = public only.
     * A dummy ActorContext would also unlock member-only types; do not do that.
     *
     * @return EventSummary[]
     */
    public function listUpcomingPublic(
        int $limit = 50,
        ?int $campusId = null,
        string $range = 'upcoming',
        ?string $anchor = null
    ): array {
        return $this->summarizeUpcoming($limit, $campusId, $range, $anchor, EventAudience::allowedFor(null));
    }

    /** @return EventSummary[] */
    public function listUpcoming(
        ActorContext $ctx,
        int $limit = 50,
        ?int $campusId = null,
        string $range = 'upcoming',
        ?string $anchor = null
    ): array {
        return $this->summarizeUpcoming($limit, $campusId, $range, $anchor, EventAudience::allowedFor($ctx));
    }

    /**
     * @param list<string> $audiences
     * @return EventSummary[]
     */
    private function summarizeUpcoming(
        int $limit,
        ?int $campusId,
        string $range,
        ?string $anchor,
        array $audiences
    ): array {
        $allowed = ['upcoming', 'past', 'month', 'quarter', 'all'];
        if (!in_array($range, $allowed, true)) {
            $range = 'upcoming';
        }
        $rows = $this->events->listUpcoming($limit, $campusId, $range, $anchor, $audiences);

        // The ministry name is resolved in PHP rather than joined in SQL.
        // This used to be a verbatim copy of ministryNames(), which meant the
        // key-name bug above had to be fixed in two places; it is one now.
        $ministryNames = $this->ministryNames();

        return array_map(function (array $r) use ($ministryNames): EventSummary {
            return new EventSummary(
                eventId: (int) $r['event_id'],
                title: (string) $r['title'],
                description: $r['summary'] ?? null,
                occurrenceCount: (int) $r['occurrence_count'],
                nextOccurrenceAt: $r['next_occurrence_at'] ?? null,
                nextOccurrenceEnd: $r['next_occurrence_end'] ?? null,
                campusNames: $r['campus_names'] ?? null,
                hostCampusName: $r['host_campus_name'] ?? null,
                locationName: $r['location_name'] ?? null,
                ministryId: $r['ministry_id'] ?? null,
                ministryName: isset($r['ministry_id']) ? ($ministryNames[(int) $r['ministry_id']] ?? null) : null,
                eventTypeId: isset($r['event_type_id']) ? (int) $r['event_type_id'] : null,
                eventTypeLabel: $r['type_label'] ?? null,
                eventTypeColor: $r['type_color'] ?? null,
            );
        }, $rows);
    }

    public function getEvent(?ActorContext $ctx, int $eventId, DateTimeImmutable $start, DateTimeImmutable $end): ?EventDetailView
    {
        $row = $this->events->findEvent($eventId, $start, $end, EventAudience::allowedFor($ctx));
        if ($row === null) {
            return null;
        }
        $availableCampuses = $this->filterCampusesForActor($ctx, $row['available_campuses'] ?? []);
        return new EventDetailView(
            eventId: (int) $row['event_id'],
            title: (string) $row['title'],
            description: $row['summary'] ?? null,
            occurrences: $row['occurrences'] ?? [],
            campusIds: array_values(array_map('intval', $row['campus_ids'] ?? [])),
            availableCampuses: $availableCampuses,
            isMultiCampus: (bool) ($row['is_multi_campus'] ?? false),
            ministryId: isset($row['ministry_id']) ? (int) $row['ministry_id'] : null,
            ministryName: isset($row['ministry_id'])
                ? ($this->ministryNames()[(int) $row['ministry_id']] ?? null)
                : null,
            eventTypeId: isset($row['event_type_id']) ? (int) $row['event_type_id'] : null,
            eventTypeLabel: $row['type_label'] ?? null,
            eventTypeColor: $row['type_color'] ?? null,
            usesServingSchedule: $this->usesServingScheduleFrom($row),
        );
    }

    public function createEvent(ActorContext $ctx, EventCreateCommand $cmd): EventDetailView
    {
        if (!$ctx->hasPermission(PortalPermission::ManageEvents) && !$ctx->isPortalWideAdmin) {
            throw new PermissionDenied('Actor lacks permission to create events.');
        }
        $this->ensureEventCampusesAccessible($ctx, $cmd->campusIds);
        $this->ensureEventTypeUsable($ctx, $cmd->eventTypeId);
        $now = new DateTimeImmutable();

        // Resolve the schedule the administrator described into concrete dates
        // BEFORE inserting, so an unusable description fails without leaving a
        // half-created event behind.
        $dates = $this->resolveScheduleDates($cmd);
        if ($dates === []) {
            throw new ValidationFailed('That schedule produces no dates. Check the date, pattern and end condition.');
        }

        $payload = $cmd->toArray();
        $payload['starts_at'] = $dates[0]['start'];
        $payload['ends_at'] = $dates[0]['end'];
        $newId = $this->events->createEvent($payload, $now);

        // Every date becomes an occurrence, which is what the Calendar reads.
        // Creating the event and its dates in one step is the whole point: an
        // event with no occurrences never appears on the calendar at all.
        $rows = array_map(static fn (array $d): array => [
            'event_id' => $newId,
            'starts_at' => $d['start'],
            'ends_at' => $d['end'],
        ], $dates);
        $this->events->insertOccurrences($newId, $rows);

        // Keep the schedule, not only the dates it produced. Without this the
        // portal can list an event's occurrences but cannot say what rule made
        // them, which is why generating them had to be a manual step exposed to
        // whoever was creating the event.
        $this->events->saveRecurrence($newId, \App\Services\Events\RecurrenceRule::toStorage(
            $cmd->pattern,
            $cmd->startDate,
            $cmd->untilOn,
            $cmd->count,
        ));

        $row = $this->events->findEvent($newId, $now, $now, EventAudience::allowedFor($ctx));
        return new EventDetailView(
            eventId: $newId,
            title: $cmd->title,
            description: $cmd->description,
            occurrences: $row['occurrences'] ?? [],
            campusIds: array_values(array_map('intval', $row['campus_ids'] ?? $cmd->campusIds)),
            availableCampuses: $this->filterCampusesForActor($ctx, $row['available_campuses'] ?? []),
            isMultiCampus: (bool) ($row['is_multi_campus'] ?? (count($cmd->campusIds) > 1)),
            ministryId: isset($row['ministry_id']) ? (int) $row['ministry_id'] : $cmd->ministryId,
            ministryName: isset($row['ministry_id'])
                ? ($this->ministryNames()[(int) $row['ministry_id']] ?? null)
                : null,
            eventTypeId: isset($row['event_type_id']) ? (int) $row['event_type_id'] : $cmd->eventTypeId,
            eventTypeLabel: $row['type_label'] ?? null,
            eventTypeColor: $row['type_color'] ?? null,
            usesServingSchedule: $this->usesServingScheduleFrom($row ?? [], $cmd->usesServingSchedule),
        );
    }

    public function updateEvent(ActorContext $ctx, int $eventId, EventCreateCommand|\App\DTO\Events\EventUpdateCommand $cmd): EventDetailView
    {
        if (!$ctx->hasPermission(PortalPermission::ManageEvents) && !$ctx->isPortalWideAdmin) {
            throw new PermissionDenied('Actor lacks permission to update events.');
        }
        if ($cmd instanceof \App\DTO\Events\EventUpdateCommand && $cmd->campusIds !== null) {
            $this->ensureEventCampusesAccessible($ctx, $cmd->campusIds);
        }
        $this->ensureEventTypeUsable($ctx, $cmd->eventTypeId);
        // You may not edit an event you are not allowed to read. Without this,
        // ManageEvents alone would let a scheduler rewrite an elders' meeting
        // they cannot see, because the adapter's update path deliberately
        // ignores the audience filter.
        if ($this->events->findEvent($eventId, new DateTimeImmutable(), new DateTimeImmutable(), EventAudience::allowedFor($ctx)) === null) {
            throw new PermissionDenied('Actor cannot edit that event.');
        }
        $now = new DateTimeImmutable();
        $this->events->updateEvent($eventId, $cmd->toArray(), $now);
        $row = $this->events->findEvent($eventId, $now, $now, EventAudience::allowedFor($ctx));
        if ($row === null) {
            throw new ValidationFailed('Event not found after update');
        }
        return new EventDetailView(
            eventId: $eventId,
            title: $row['title'],
            description: $row['summary'] ?? null,
            occurrences: $row['occurrences'] ?? [],
            campusIds: array_values(array_map('intval', $row['campus_ids'] ?? [])),
            availableCampuses: $this->filterCampusesForActor($ctx, $row['available_campuses'] ?? []),
            isMultiCampus: (bool) ($row['is_multi_campus'] ?? false),
            ministryId: isset($row['ministry_id']) ? (int) $row['ministry_id'] : null,
            ministryName: isset($row['ministry_id'])
                ? ($this->ministryNames()[(int) $row['ministry_id']] ?? null)
                : null,
            eventTypeId: isset($row['event_type_id']) ? (int) $row['event_type_id'] : null,
            eventTypeLabel: $row['type_label'] ?? null,
            eventTypeColor: $row['type_color'] ?? null,
            usesServingSchedule: $this->usesServingScheduleFrom($row),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function usesServingScheduleFrom(array $row, bool $fallback = false): bool
    {
        if (array_key_exists('uses_serving_schedule', $row) && $row['uses_serving_schedule'] !== null) {
            return (int) $row['uses_serving_schedule'] === 1;
        }

        return $fallback;
    }

    /**
     * Turn the form's scheduling choices into concrete datetime pairs.
     *
     * Covers the patterns a church calendar actually needs:
     *   one_off   — a single date (Communion Sunday)
     *   weekly    — every 7 days, optionally bounded by a count or an end date
     *   biweekly  — every 14 days
     *   selected  — an explicit list of dates (RADICAL Life Service, Aug 7 & 14)
     *
     * "selected" is the one that did not exist. Without it an activity running
     * on a few chosen dates had to be entered as several unrelated events.
     *
     * Time is optional in both directions: no time at all (a holiday), a start
     * only, or a start and end. An all-day date is stored 00:00-00:00, which
     * needs no schema change and is how the calendar already reads birthdays.
     *
     * @return list<array{start:string,end:string}>
     */
    private function resolveScheduleDates(EventCreateCommand $cmd): array
    {
        $startTime = $cmd->allDay ? '00:00' : (($cmd->startTime ?? '') !== '' ? $cmd->startTime : '00:00');
        // All-day has no end time at all. Passing '00:00' sent it down the
        // end-time branch, where an end not after the start is treated as
        // running past midnight — so every holiday became a 24-hour span
        // and displayed as "12:00 AM - 12:00 AM".
        $endTime = $cmd->allDay ? null : (($cmd->endTime ?? '') !== '' ? $cmd->endTime : null);

        $make = static function (string $ymd) use ($cmd, $startTime, $endTime): ?array {
            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $ymd . ' ' . $startTime);
            if ($start === false) {
                return null;
            }
            if ($endTime !== null) {
                $end = DateTimeImmutable::createFromFormat('Y-m-d H:i', $ymd . ' ' . $endTime);
                // An end before the start means it runs past midnight.
                if ($end !== false && $end <= $start) {
                    $end = $end->add(new DateInterval('P1D'));
                }
            } else {
                // No end time was given, so none is invented. Storing the end
                // equal to the start records "starts at 5:30, no stated finish"
                // — which is how the Friday RADICAL Life Service is actually
                // published. A manufactured 90 minutes would be a fact nobody
                // supplied. All-day is the same shape at midnight.
                $end = $start;
            }

            return ['start' => $start->format('Y-m-d H:i:s'), 'end' => ($end ?: $start)->format('Y-m-d H:i:s')];
        };

        if ($cmd->pattern === 'selected') {
            $out = [];
            foreach ($cmd->selectedDates as $ymd) {
                $ymd = trim((string) $ymd);
                if ($ymd === '') {
                    continue;
                }
                $row = $make($ymd);
                if ($row !== null) {
                    $out[] = $row;
                }
            }
            usort($out, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

            return $out;
        }

        $first = trim((string) ($cmd->startDate ?? ''));
        if ($first === '') {
            return [];
        }
        $seed = $make($first);
        if ($seed === null) {
            return [];
        }
        if ($cmd->pattern === 'one_off') {
            return [$seed];
        }

        // "First Sunday of every month" cannot be reached by adding a fixed
        // interval — the date moves. It is walked month by month instead.
        if ($cmd->pattern === 'monthly_nth') {
            return $this->expandNthWeekday($cmd, $first, $make);
        }

        // Monthly steps by month rather than by a number of days, so the 6th
        // stays the 6th instead of drifting two days every February.
        $step = match ($cmd->pattern) {
            'weekly' => new DateInterval('P7D'),
            'biweekly' => new DateInterval('P14D'),
            'monthly' => new DateInterval('P1M'),
            default => null,
        };
        if ($step === null) {
            return [$seed];
        }

        $until = null;
        if (($cmd->untilOn ?? '') !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', (string) $cmd->untilOn);
            $until = $parsed !== false ? $parsed->setTime(23, 59, 59) : null;
        }
        // A repeat with neither an end date nor a count would run forever;
        // a year is the pragmatic ceiling and matches the calendar's horizon.
        $limit = $cmd->count !== null && $cmd->count > 0 ? min($cmd->count, 366) : 366;

        $out = [];
        $cursor = new DateTimeImmutable(substr($seed['start'], 0, 10));
        for ($i = 0; $i < $limit; $i++) {
            if ($until !== null && $cursor > $until) {
                break;
            }
            // An open-ended repeat stops after a year of itself: fifty-two
            // weeks, twenty-six fortnights, twelve months. Counting iterations
            // rather than days keeps "a year" meaning a year for each pattern.
            $openEndedCeiling = match ($cmd->pattern) {
                'biweekly' => 26,
                'monthly' => 12,
                default => 52,
            };
            if ($until === null && $cmd->count === null && $i >= $openEndedCeiling) {
                break;
            }
            $row = $make($cursor->format('Y-m-d'));
            if ($row !== null) {
                $out[] = $row;
            }
            $cursor = $cursor->add($step);
        }

        return $out;
    }

    /**
     * "The first Sunday of every month", and its siblings.
     *
     * Walks months rather than adding days: the date of the first Sunday moves,
     * so no fixed interval reaches it. The seed date decides which weekday and
     * which position — picking the 6th of September 2026 means first Sunday,
     * picking the 27th means last, because a date in the final seven days of
     * its month is the last of that weekday and stays the last in months that
     * have only four.
     *
     * @param callable(string):?array $make
     * @return list<array{start:string,end:string}>
     */
    private function expandNthWeekday(\App\DTO\Events\EventCreateCommand $cmd, string $first, callable $make): array
    {
        $seedDate = DateTimeImmutable::createFromFormat('Y-m-d', $first);
        if ($seedDate === false) {
            return [];
        }
        $weekday = (int) $seedDate->format('w');
        $dayOfMonth = (int) $seedDate->format('j');
        $nth = $dayOfMonth + 7 > (int) $seedDate->format('t') ? -1 : (int) ceil($dayOfMonth / 7);

        $until = null;
        if (($cmd->untilOn ?? '') !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', (string) $cmd->untilOn);
            $until = $parsed !== false ? $parsed->setTime(23, 59, 59) : null;
        }
        $limit = $cmd->count !== null && $cmd->count > 0 ? min($cmd->count, 120) : 120;

        $out = [];
        $month = $seedDate->modify('first day of this month');
        for ($i = 0; $i < $limit; $i++) {
            if ($until === null && $cmd->count === null && $i >= 12) {
                break;
            }
            $target = $this->nthWeekdayOfMonth($month, $weekday, $nth);
            if ($target === null) {
                $month = $month->modify('+1 month');
                continue;
            }
            if ($until !== null && $target > $until) {
                break;
            }
            // Skip a target before the seed: asking for the first Sunday from
            // the 6th should not also produce the 6th's own month if the seed
            // was later in it.
            if ($target->format('Y-m-d') >= $first) {
                $row = $make($target->format('Y-m-d'));
                if ($row !== null) {
                    $out[] = $row;
                }
            }
            $month = $month->modify('+1 month');
        }

        return $out;
    }

    /** The nth (or -1 = last) given weekday within a month. */
    private function nthWeekdayOfMonth(DateTimeImmutable $month, int $weekday, int $nth): ?DateTimeImmutable
    {
        $firstOfMonth = $month->modify('first day of this month');
        $offset = ($weekday - (int) $firstOfMonth->format('w') + 7) % 7;
        $firstMatch = $firstOfMonth->modify('+' . $offset . ' days');

        if ($nth === -1) {
            $candidate = $firstMatch;
            while ((int) $candidate->modify('+7 days')->format('n') === (int) $month->format('n')) {
                $candidate = $candidate->modify('+7 days');
            }

            return $candidate;
        }

        $candidate = $firstMatch->modify('+' . (($nth - 1) * 7) . ' days');

        // A month with only four of that weekday has no fifth. Returning null
        // skips the month rather than spilling into the next one.
        return (int) $candidate->format('n') === (int) $month->format('n') ? $candidate : null;
    }

    public function generateOccurrences(ActorContext $ctx, OccurrenceGenerateCommand $cmd): OccurrenceBatchResult
    {
        if (!$ctx->hasPermission(PortalPermission::ManageEvents) && !$ctx->isPortalWideAdmin) {
            throw new PermissionDenied('Actor lacks permission to generate occurrences.');
        }

        $now = new DateTimeImmutable();
        $startsAt = $cmd->startsAt;

        $lookback = $now->sub(new DateInterval('P7D'));
        if ($startsAt < $lookback) {
            throw new ValidationFailed('Occurrences may not start earlier than 7 days in the past.');
        }

        $intervalDays = match ($cmd->pattern) {
            'one_off' => 0,
            'weekly' => 7,
            'biweekly' => 14,
            default => throw new ValidationFailed('Unknown recurrence pattern.'),
        };

        $rows = [];
        if ($intervalDays === 0) {
            $end = $startsAt->add(new DateInterval('PT' . $cmd->durationMin . 'M'));
            $rows[] = ['event_id' => $cmd->eventId, 'starts_at' => $startsAt->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s')];
        } else {
            $current = $startsAt;
            $count = 0;
            while (true) {
                if ($cmd->count !== null && $count >= $cmd->count) break;
                if ($cmd->untilOn !== null && $current > $cmd->untilOn) break;
                $end = $current->add(new DateInterval('PT' . $cmd->durationMin . 'M'));
                $rows[] = ['event_id' => $cmd->eventId, 'starts_at' => $current->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s')];
                $current = $current->add(new DateInterval('P' . $intervalDays . 'D'));
                $count++;
            }
        }

        $inserted = $this->events->insertOccurrences($cmd->eventId, $rows);
        return new OccurrenceBatchResult($inserted);
    }

    /**
     * Move a single occurrence.
     *
     * Until this existed, a time entered wrongly could not be corrected at all:
     * the event form edits title, description and campuses, and the generator
     * only ever appends. The only route to a wrong time was deleting the
     * occurrence and generating a replacement — one row at a time.
     */
    public function rescheduleOccurrence(
        ActorContext $ctx,
        int $occurrenceId,
        DateTimeImmutable $startsAt,
        ?int $durationMin = null,
    ): void {
        $eventId = $this->ensureOccurrenceWritable($ctx, $occurrenceId);
        $existing = null;
        foreach ($this->events->listEventOccurrences($eventId) as $row) {
            if ($row['occurrence_id'] === $occurrenceId) {
                $existing = $row;
                break;
            }
        }
        if ($existing === null) {
            throw new ValidationFailed('Occurrence not found.');
        }
        // Keep the length the occurrence already had unless a new one is given.
        // Correcting a start time should not silently resize the event.
        if ($durationMin === null) {
            $oldStart = new DateTimeImmutable($existing['starts_at']);
            $oldEnd = new DateTimeImmutable($existing['ends_at']);
            $durationMin = max(1, (int) round(($oldEnd->getTimestamp() - $oldStart->getTimestamp()) / 60));
        }
        if ($durationMin < 1 || $durationMin > 1440) {
            throw new ValidationFailed('Duration must be between 1 and 1440 minutes.');
        }
        $end = $startsAt->add(new DateInterval('PT' . $durationMin . 'M'));
        // Same unique-start rule the series repair honours, checked here so the
        // clash is reported as a clash rather than as a database error.
        $startStr = $startsAt->format('Y-m-d H:i:s');
        foreach ($this->events->listEventOccurrences($eventId) as $row) {
            if ($row['occurrence_id'] !== $occurrenceId && $row['starts_at'] === $startStr) {
                throw new ValidationFailed(
                    'This event already has an occurrence on ' . $startsAt->format('D j M Y')
                    . ' at ' . $startsAt->format('H:i') . '.'
                );
            }
        }
        $this->events->updateOccurrenceTimes(
            $occurrenceId,
            $startsAt->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Put a whole series on the correct clock time, keeping its dates.
     *
     * This is the repair for the common mistake: a weekly service generated at
     * 07:30 when it meets at 19:00. The dates are right, only the time of day
     * is wrong, so the dates are what this preserves.
     *
     * @param string $scope 'upcoming' (default), 'all', or 'following' with a
     *        $from datetime. Past occurrences are left alone by default: they
     *        are a record of what happened.
     * @return int occurrences moved
     */
    public function retimeEventOccurrences(
        ActorContext $ctx,
        int $eventId,
        string $timeOfDay,
        ?int $durationMin,
        string $scope = 'upcoming',
        ?string $from = null,
    ): int {
        $this->ensureEventWritable($ctx, $eventId);
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $timeOfDay) !== 1) {
            throw new ValidationFailed('Time must be given as HH:MM in 24-hour form.');
        }
        if ($durationMin !== null && ($durationMin < 1 || $durationMin > 1440)) {
            throw new ValidationFailed('Duration must be between 1 and 1440 minutes.');
        }
        [$hour, $minute] = array_map('intval', explode(':', $timeOfDay));

        $all = $this->events->listEventOccurrences($eventId);
        $selected = $this->selectOccurrences($eventId, $scope, $from);
        $moving = array_flip(array_map(static fn (array $r): int => $r['occurrence_id'], $selected));

        // An event may not hold two occurrences with the same start — the table
        // enforces it. Collapsing a series onto one time of day can create such
        // a pair, so the whole batch is computed and checked before any of it is
        // written. Discovering the clash mid-run would leave half the series on
        // the old time and half on the new one.
        $taken = [];
        foreach ($all as $row) {
            if (!isset($moving[$row['occurrence_id']])) {
                $taken[$row['starts_at']] = true;
            }
        }

        $changes = [];
        foreach ($selected as $row) {
            $oldStart = new DateTimeImmutable($row['starts_at']);
            $oldEnd = new DateTimeImmutable($row['ends_at']);
            $length = $durationMin ?? max(1, (int) round(($oldEnd->getTimestamp() - $oldStart->getTimestamp()) / 60));
            $newStart = $oldStart->setTime($hour, $minute);
            $newEnd = $newStart->add(new DateInterval('PT' . $length . 'M'));
            $startStr = $newStart->format('Y-m-d H:i:s');
            if (isset($taken[$startStr])) {
                throw new ValidationFailed(
                    'Two occurrences would land on ' . $newStart->format('D j M Y') . ' at ' . $timeOfDay
                    . '. Delete the duplicate first, then set the time.'
                );
            }
            $taken[$startStr] = true;
            if ($startStr === $row['starts_at'] && $newEnd->format('Y-m-d H:i:s') === $row['ends_at']) {
                continue;
            }
            $changes[] = [
                'occurrence_id' => $row['occurrence_id'],
                'starts_at' => $startStr,
                'ends_at' => $newEnd->format('Y-m-d H:i:s'),
            ];
        }

        if ($changes === []) {
            return 0;
        }

        return $this->events->updateOccurrenceTimesBatch($eventId, $changes);
    }

    /**
     * Replace a repeating event's schedule with a different one.
     *
     * Retiming moves a series to a different clock time and keeps its dates.
     * This is the other half: changing the rule itself — weekly to fortnightly,
     * a different weekday, a new end date — which changes which dates exist.
     *
     * Dates already past are never touched. They record what actually happened,
     * and rewriting them would falsify the church's own history; the rule
     * applies from today forward. That is also why this cannot simply delete
     * everything and regenerate.
     *
     * @return array{removed:int,created:int,summary:string}
     */
    public function replaceSchedule(
        ActorContext $ctx,
        int $eventId,
        EventCreateCommand $cmd,
        bool $force = false,
    ): array {
        $this->ensureEventWritable($ctx, $eventId);

        $dates = $this->resolveScheduleDates($cmd);
        if ($dates === []) {
            throw new ValidationFailed('That schedule produces no dates. Check the date, pattern and end condition.');
        }

        $doomed = $this->selectOccurrences($eventId, 'upcoming');
        $ids = array_map(static fn (array $r): int => $r['occurrence_id'], $doomed);

        // Same guard the bulk delete applies, for the same reason: the dates
        // being replaced may have people rostered onto them.
        if ($ids !== []) {
            $assignments = $this->events->countAssignmentsForOccurrences($ids);
            if ($assignments > 0 && !$force) {
                throw new ValidationFailed(
                    'The dates being replaced carry ' . $assignments
                    . ' assignment(s). Confirm to replace the schedule anyway.'
                );
            }
        }

        // Keep whatever the new rule produced that is not already in the past,
        // so a schedule starting last month does not silently backfill history.
        $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $fresh = array_values(array_filter(
            $dates,
            static fn (array $d): bool => $d['start'] >= $todayStart,
        ));
        if ($fresh === []) {
            throw new ValidationFailed('That schedule produces no dates from today onward.');
        }

        // Remove first, then insert: event_occurrences is unique on
        // (event_id, start), so a new date landing on an old one would collide
        // if the order were reversed.
        $removed = $ids === [] ? 0 : $this->events->deleteOccurrencesForEvent($eventId, $ids);
        $this->events->insertOccurrences($eventId, array_map(
            static fn (array $d): array => [
                'event_id' => $eventId,
                'starts_at' => $d['start'],
                'ends_at' => $d['end'],
            ],
            $fresh,
        ));
        $this->events->saveRecurrence($eventId, \App\Services\Events\RecurrenceRule::toStorage(
            $cmd->pattern,
            $cmd->startDate,
            $cmd->untilOn,
            $cmd->count,
        ));

        return [
            'removed' => $removed,
            'created' => count($fresh),
            'summary' => $this->scheduleFor($ctx, $eventId)['summary'],
        ];
    }

    /**
     * Delete occurrences in bulk.
     *
     * A weekly event generated for a year is 52 rows. Deleting a mistake one
     * row at a time is not a workflow, and stopping halfway leaves a calendar
     * that is half wrong — which is harder to notice than one that is wholly
     * wrong.
     *
     * @param string $scope 'all', 'upcoming', or 'selected' (with $ids)
     * @param list<int> $ids used only when $scope is 'selected'
     * @return int occurrences deleted
     */
    public function deleteEventOccurrences(
        ActorContext $ctx,
        int $eventId,
        string $scope = 'upcoming',
        array $ids = [],
        bool $force = false,
    ): int {
        if (!$ctx->hasPermission(PortalPermission::CancelOccurrences) && !$ctx->isPortalWideAdmin) {
            throw new PermissionDenied('Actor lacks permission to cancel occurrences.');
        }
        $this->ensureEventWritable($ctx, $eventId);

        if ($scope === 'selected') {
            $wanted = array_flip(array_map('intval', $ids));
            $rows = array_values(array_filter(
                $this->events->listEventOccurrences($eventId),
                static fn (array $r): bool => isset($wanted[$r['occurrence_id']]),
            ));
        } else {
            $rows = $this->selectOccurrences($eventId, $scope);
        }
        if ($rows === []) {
            return 0;
        }
        $targets = array_map(static fn (array $r): int => $r['occurrence_id'], $rows);

        // Same guard the single-occurrence path uses, asked once for the batch.
        $assignments = $this->events->countAssignmentsForOccurrences($targets);
        if ($assignments > 0 && !$force) {
            throw new ValidationFailed(
                'These occurrences have ' . $assignments . ' assignment(s). Remove those first, or confirm to delete anyway.'
            );
        }

        return $this->events->deleteOccurrencesForEvent($eventId, $targets);
    }

    /**
     * The schedule an event repeats on, as a rule and as a sentence.
     *
     * Returned together on purpose: the caller needs the sentence to show and
     * the rule to prefill an editor, and deriving one from the other in a view
     * is how the two drift apart.
     *
     * @return array{rule:array<string,mixed>|null,observed:bool,summary:string}
     */
    public function scheduleFor(?ActorContext $ctx, int $eventId): array
    {
        $now = new DateTimeImmutable();
        // Read gate, same as every other read: an event whose audience the
        // actor cannot see reports no schedule rather than refusing, so the id
        // cannot be probed through this.
        if ($this->events->findEvent($eventId, $now, $now, EventAudience::allowedFor($ctx)) === null) {
            return ['rule' => null, 'observed' => false, 'summary' => ''];
        }
        $rule = $this->events->findRecurrence($eventId);
        $occurrences = $this->events->listEventOccurrences($eventId);

        // Every event created before the rule was persisted has dates and no
        // schedule. Saying "Does not repeat" above a list of fifty-two Fridays
        // would contradict the page itself, so the cadence is read off the
        // dates instead — reporting what they do, claiming nothing about why.
        $observed = false;
        if ($rule === null) {
            $rule = \App\Services\Events\RecurrenceRule::observe(
                array_map(static fn (array $r): string => $r['starts_at'], $occurrences),
            );
            $observed = $rule !== null;
        }

        // Times come from the occurrences, not from the rule: the repeat rule
        // stores when it repeats, never at what time of day.
        $first = $occurrences[0] ?? null;
        $startTime = $first === null ? null : substr($first['starts_at'], 11, 5);
        $endTime = $first === null ? null : substr($first['ends_at'], 11, 5);
        $allDay = $first !== null && $startTime === '00:00' && $endTime === '00:00';

        return [
            'rule' => $rule,
            'observed' => $observed,
            'summary' => \App\Services\Events\RecurrenceRule::describe(
                $rule,
                $allDay ? null : $startTime,
                $allDay ? null : $endTime,
                $allDay,
            ),
        ];
    }

    /**
     * Delete an event outright.
     *
     * The portal could create events and never remove one, so an event filed by
     * mistake — wrong title, wrong campus, wrong day entirely — stayed on the
     * calendar for good. Clearing its occurrences hid it from the calendar but
     * left a titled shell in the events list.
     *
     * @return bool false when no such event exists (or it is unreadable, which
     *              is reported identically so the id cannot be probed)
     */
    public function deleteEvent(ActorContext $ctx, int $eventId, bool $force = false): bool
    {
        if (!$ctx->hasPermission(PortalPermission::ManageEvents) && !$ctx->isPortalWideAdmin) {
            throw new PermissionDenied('Actor lacks permission to delete events.');
        }
        $now = new DateTimeImmutable();
        if ($this->events->findEvent($eventId, $now, $now, EventAudience::allowedFor($ctx)) === null) {
            return false;
        }

        $ids = array_map(
            static fn (array $r): int => $r['occurrence_id'],
            $this->events->listEventOccurrences($eventId),
        );
        // Assignments mean people were rostered. Say how many rather than
        // deleting them along with the event without a word.
        $assignments = $ids === [] ? 0 : $this->events->countAssignmentsForOccurrences($ids);
        if ($assignments > 0 && !$force) {
            throw new ValidationFailed(
                'This event has ' . $assignments . ' assignment(s) across its occurrences. Confirm to delete them with it.'
            );
        }

        return $this->events->deleteEvent($eventId);
    }

    /**
     * @return list<array{occurrence_id:int,starts_at:string,ends_at:string}>
     */
    private function selectOccurrences(int $eventId, string $scope, ?string $from = null): array
    {
        $all = $this->events->listEventOccurrences($eventId);
        if ($scope === 'all') {
            return $all;
        }
        // 'following' is what every calendar offers when you change one date of
        // a series: this one and everything after it, leaving earlier dates
        // alone whether or not they are in the past.
        if ($scope === 'following') {
            if ($from === null) {
                throw new ValidationFailed('Changing this and later dates needs a date to start from.');
            }
            $cutoff = $from;
        } elseif ($scope === 'upcoming') {
            $cutoff = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        } else {
            throw new ValidationFailed('Unknown scope.');
        }

        return array_values(array_filter(
            $all,
            static fn (array $r): bool => $r['starts_at'] >= $cutoff,
        ));
    }

    /**
     * An occurrence may only be written by someone who could manage its event
     * and is allowed to read that event's audience — the same two conditions
     * cancelOccurrence() applies, kept in one place now that three callers
     * need them. Returns the parent event id, which every caller then wants.
     */
    private function ensureOccurrenceWritable(ActorContext $ctx, int $occurrenceId): int
    {
        if (!$ctx->hasPermission(PortalPermission::ManageEvents) && !$ctx->isPortalWideAdmin) {
            throw new PermissionDenied('Actor lacks permission to change occurrences.');
        }
        $parent = $this->events->findOccurrenceAudience($occurrenceId);
        if ($parent === null) {
            throw new ValidationFailed('Occurrence not found.');
        }
        if (!in_array($parent['audience'], EventAudience::allowedFor($ctx), true)) {
            throw new PermissionDenied('Actor lacks permission to change occurrences.');
        }

        return (int) $parent['event_id'];
    }

    /** As above, but starting from the event. */
    private function ensureEventWritable(ActorContext $ctx, int $eventId): void
    {
        if (!$ctx->hasPermission(PortalPermission::ManageEvents) && !$ctx->isPortalWideAdmin) {
            throw new PermissionDenied('Actor lacks permission to change occurrences.');
        }
        $now = new DateTimeImmutable();
        if ($this->events->findEvent($eventId, $now, $now, EventAudience::allowedFor($ctx)) === null) {
            throw new PermissionDenied('Actor cannot edit that event.');
        }
    }

    /**
     * Mark one occurrence cancelled — the service is off, the date stays.
     *
     * This used to delete the row. That erased the very thing a congregation
     * needs to know: a Sunday with no service is not the same as a Sunday that
     * was never scheduled, and somebody turns up to find out which. The column
     * for it has existed since the schema was created and was never once set.
     *
     * Cancelling destroys nothing, so unlike deleting it needs no confirmation
     * about assignments — the roster is still there if the date is restored.
     */
    public function cancelOccurrence(ActorContext $ctx, int $occurrenceId, bool $force = false): void
    {
        $this->ensureOccurrenceCancellable($ctx, $occurrenceId);
        $this->events->setOccurrenceCancelled($occurrenceId, true);
    }

    /**
     * Every tag in use, for a picker.
     *
     * Readable by anyone who can read events at all: a tag is a label on a
     * public-facing calendar, not a permission.
     *
     * @return list<array{slug:string,label:string,usage_count:int}>
     */
    public function listTags(): array
    {
        return $this->events->listTags();
    }

    /** @return list<array{slug:string,label:string}> */
    public function tagsForEvent(?ActorContext $ctx, int $eventId): array
    {
        $now = new DateTimeImmutable();
        // An event the actor cannot read has no tags to show them, reported the
        // same way a missing one would be.
        if ($this->events->findEvent($eventId, $now, $now, EventAudience::allowedFor($ctx)) === null) {
            return [];
        }

        return $this->events->tagsForEvent($eventId);
    }

    /**
     * Set an event's tags to exactly this list.
     *
     * @param list<string> $raw as typed
     * @return list<array{slug:string,label:string}>
     */
    public function setEventTags(ActorContext $ctx, int $eventId, array $raw): array
    {
        $this->ensureEventWritable($ctx, $eventId);

        foreach ($raw as $one) {
            $one = trim((string) $one);
            if ($one === '') {
                continue;
            }
            if (!\App\Services\Events\TagName::isValid($one)) {
                throw new ValidationFailed(
                    'That is not a usable tag: "' . $one . '". Keep it under '
                    . \App\Services\Events\TagName::MAX_LENGTH . ' characters and include a letter or number.'
                );
            }
        }

        $tags = \App\Services\Events\TagName::normaliseList(array_map('strval', $raw));
        if (count($tags) > 12) {
            // Not a schema limit — a judgement. A dozen labels on one event is
            // past the point where they help anybody find it.
            throw new ValidationFailed('That is a lot of tags. Keep it to twelve or fewer.');
        }

        return $this->events->setEventTags($eventId, $tags);
    }

    /**
     * Give one date of a series its own title and description.
     *
     * The scope is deliberately only ever this occurrence. "This and following"
     * would mean splitting the series in two, which the repeat rule cannot
     * represent — it holds one rule per event and no notion of a series that
     * changed its name partway through. Offering the choice and then doing
     * something else would be worse than not offering it.
     *
     * Passing null (or an empty string) for either clears that field, and the
     * occurrence inherits from its event again, so an override is always
     * reversible.
     *
     * @return array{title:?string,description:?string,overridden:bool}
     */
    public function overrideOccurrence(
        ActorContext $ctx,
        int $occurrenceId,
        ?string $title,
        ?string $description,
    ): array {
        // Same permission as changing the time: this edits an occurrence of an
        // event, so it needs the right to manage that event and to read its
        // audience.
        $this->ensureOccurrenceWritable($ctx, $occurrenceId);

        $title = $this->trimToNull($title);
        $description = $this->trimToNull($description);

        if ($title !== null && mb_strlen($title) > 255) {
            // The column is varchar(255). Truncating silently would put a
            // half-sentence on the calendar.
            throw new ValidationFailed('That title is too long for one date — keep it under 255 characters.');
        }

        $this->events->setOccurrenceOverrides($occurrenceId, $title, $description);

        return [
            'title' => $title,
            'description' => $description,
            'overridden' => $title !== null || $description !== null,
        ];
    }

    /** Drop an override so the date inherits from its event again. */
    public function clearOccurrenceOverride(ActorContext $ctx, int $occurrenceId): void
    {
        $this->ensureOccurrenceWritable($ctx, $occurrenceId);
        $this->events->setOccurrenceOverrides($occurrenceId, null, null);
    }

    /** Blank and whitespace both mean "no override", never an empty title. */
    private function trimToNull(?string $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    /** Put a cancelled occurrence back on the calendar. */
    public function restoreOccurrence(ActorContext $ctx, int $occurrenceId): void
    {
        $this->ensureOccurrenceCancellable($ctx, $occurrenceId);
        $this->events->setOccurrenceCancelled($occurrenceId, false);
    }

    /**
     * Remove one occurrence outright — the date should not exist.
     *
     * The destructive counterpart to cancelling, and the behaviour this class
     * used to give to cancelOccurrence(). Kept separate because the two mean
     * different things to whoever reads the calendar.
     */
    public function deleteOccurrence(ActorContext $ctx, int $occurrenceId, bool $force = false): void
    {
        $this->ensureOccurrenceCancellable($ctx, $occurrenceId);

        $count = $this->events->countAssignmentsForOccurrence($occurrenceId);
        if ($count > 0 && !$force) {
            throw new ValidationFailed('Occurrence has ' . $count . ' assignments. Delete those first or use force.');
        }
        $this->events->deleteOccurrence($occurrenceId);
    }

    /**
     * The permission both paths share.
     *
     * Resolve the parent event before touching anything. deleteOccurrence() and
     * countAssignmentsForOccurrence() take a bare occurrence id, so without this
     * an actor holding CancelOccurrences could act on an occurrence of an event
     * they are not allowed to see — and the assignment count alone would
     * confirm the id exists.
     */
    private function ensureOccurrenceCancellable(ActorContext $ctx, int $occurrenceId): void
    {
        if (!$ctx->hasPermission(PortalPermission::CancelOccurrences) && !$ctx->isPortalWideAdmin) {
            throw new PermissionDenied('Actor lacks permission to cancel occurrences.');
        }
        $parent = $this->events->findOccurrenceAudience($occurrenceId);
        if ($parent !== null
            && !in_array($parent['audience'], EventAudience::allowedFor($ctx), true)) {
            throw new PermissionDenied('Actor lacks permission to cancel occurrences.');
        }
    }

    /**
     * Refuse a type whose audience the actor cannot read.
     *
     * Without this a scheduler could file an event as Leadership and instantly
     * lose the ability to see, edit or undo it — the event would still exist,
     * just not for them.
     *
     * Skipped when no EventTypeService is wired (tests, CLI): the type is then
     * unverifiable, and the adapter still falls back to the default type.
     */
    private function ensureEventTypeUsable(ActorContext $ctx, ?int $eventTypeId): void
    {
        if ($eventTypeId === null || $eventTypeId <= 0 || $this->eventTypes === null) {
            return;
        }
        if (!$this->eventTypes->actorMayUseType($ctx, $eventTypeId)) {
            throw new PermissionDenied('Actor cannot file an event under that event type.');
        }
    }

    /** @param list<int> $campusIds */
    private function ensureEventCampusesAccessible(ActorContext $ctx, array $campusIds): void
    {
        foreach ($campusIds as $campusId) {
            if (!$ctx->canAccessCampus($campusId)) {
                throw new PermissionDenied('Actor is outside the requested campus scope for event management.');
            }
        }
    }

    /** @param list<array{id:int,name:string}> $campuses
      * @return list<array{id:int,name:string}>
      */
    private function filterCampusesForActor(?ActorContext $ctx, array $campuses): array
    {
        if ($ctx === null || $ctx->isPortalWideAdmin || $ctx->campusScopeIds === []) {
            return $campuses;
        }

        return array_values(array_filter(
            $campuses,
            fn (array $campus): bool => $ctx->canAccessCampus((int) $campus['id'])
        ));
    }
}
