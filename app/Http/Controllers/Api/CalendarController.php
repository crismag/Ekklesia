<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\PortalRequestContext;
use App\Services\CalendarService;
use App\Services\RosterScheduleService;
use DateTimeImmutable;

final readonly class CalendarController
{
    public function __construct(
        private CalendarService $service,
        private PortalRequestContext $requestContext,
        private ?RosterScheduleService $rosterService = null,
        private ?\App\Services\EventTypeService $eventTypes = null,
        // Optional, like the roster service above: a portal without schedule
        // reads is exactly a portal whose day inspector shows activities and no
        // roles, rather than one that fails to answer at all.
        private ?\App\Services\ScheduleService $schedules = null,
    ) {
    }

    /**
     * GET /api/calendar/layers
     *
     * The chips the calendar offers, and their colours.
     *
     * Served rather than derived in the browser for two reasons. A layer with
     * no items in the current window would lose its chip and the saved on/off
     * state behind it. And deriving the list client-side would mean shipping a
     * "Leadership" chip to members — the label alone confirms that leadership
     * events exist and were withheld, which is most of what the audience filter
     * is for.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    /**
     * The cached holiday calendars.
     *
     * Read on demand rather than injected, so a portal with no holiday cache is
     * exactly a portal with no holiday layers, and nothing has to be wired up
     * for that to be true.
     */
    private function holidays(): \App\Services\HolidayCalendars
    {
        // Built per call rather than held: this class is readonly, the file is
        // small, and each endpoint is its own request anyway.
        return \App\Services\HolidayCalendars::fromFile(
            dirname(__DIR__, 4) . '/config/holidays.json'
        );
    }

    public function layers(array $request): array
    {
        $actor = null;
        try {
            $actor = $this->requestContext->fromArray($request);
        } catch (\Throwable) {
            $actor = null;
        }

        // Event layers are audience-filtered by the service.
        $layers = [];
        if ($this->eventTypes !== null) {
            try {
                $layers = $this->eventTypes->listLayers($actor);
            } catch (\Throwable) {
                $layers = [];
            }
        }
        if ($layers === []) {
            // No types configured yet: keep one bucket so events never become
            // unreachable because an admin has not visited the types screen.
            $layers[] = ['source' => 'events:general', 'label' => 'Events', 'color' => '#2c6ea5', 'kind' => 'event', 'group' => 'events'];
        }

        // The non-event layers are fixed; they come from people and rosters,
        // not from event types.
        foreach ([
            ['assignments',   'Role assignments',  '#3f6f9c', 'assignment', 'schedule'],
            ['schedules',     'Ministry schedules', '#4a7c59', 'schedule',   'schedule'],
            ['rosters',       'Ministry rosters',  '#6b7f9e', 'roster',     'schedule'],
            ['birthdays',     'Birthdays',         '#8a5d9d', 'birth',      'people'],
            ['anniversaries', 'Anniversaries',     '#a2647c', 'anniv',      'people'],
            ['custom',        'Others',            '#5c6b63', 'custom',     'other'],
        ] as [$source, $label, $color, $kind, $group]) {
            $layers[] = ['source' => $source, 'label' => $label, 'color' => $color, 'kind' => $kind, 'group' => $group];
        }

        // One chip per enabled holiday calendar, so a congregation that keeps
        // more than one country's holidays in view can switch each on and off.
        foreach ($this->holidays()->layers() as $layer) {
            $layers[] = $layer;
        }

        return ['layers' => $layers];
    }

    /**
     * GET /api/calendar/sources
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function sources(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $start = new DateTimeImmutable((string) ($request['start'] ?? 'now'));
        $end   = new DateTimeImmutable((string) ($request['end']   ?? '+30 days'));

        return ['items' => $this->composeItems($actor, $start, $end)];
    }

    /**
     * GET /api/calendar/day?date=YYYY-MM-DD
     *
     * One date, as an operations read model: what is happening, and who is on
     * it. The calendar could already say *that* something is on a Sunday; it
     * could not say that the keyboard slot has nobody in it without somebody
     * opening a ministry grid to find out.
     *
     * A read model over sources that already exist — the same activity feed the
     * month grid draws, plus the assignments already published on the schedule
     * board. No new tables, no new authority: an actor sees here exactly what
     * the calendar and the board would each tell them separately.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function day(array $request): array
    {
        $actor = $this->requestContext->fromArray($request);
        $day = $this->requestedDay($request);

        // A day is a closed window on itself. listSystemItems widens the end to
        // cover the whole final date, so passing the same date twice is one day
        // rather than an empty range.
        $items = $this->composeItems($actor, $day, $day);

        return [
            'date' => $day->format('Y-m-d'),
            'items' => $this->withRoles($actor, $day, $items),
        ];
    }

    /**
     * The date asked for, or today.
     *
     * Parsed strictly. `new DateTimeImmutable('garbage')` throws, and a lenient
     * parse would happily read "next tuesday" as a date the caller never asked
     * for — so the format is checked before it reaches the constructor.
     */
    private function requestedDay(array $request): DateTimeImmutable
    {
        $raw = trim((string) ($request['date'] ?? ''));
        if ($raw === '') {
            return new DateTimeImmutable('today');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
            throw new \App\Exceptions\ValidationFailed('Date must be formatted YYYY-MM-DD.');
        }

        return $parsed;
    }

    /**
     * The calendar's items for a window, from every source it draws.
     *
     * Extracted so the month feed and the day inspector cannot disagree about
     * what is on a date. They were one method with a wider range; making the
     * day endpoint a second copy would have been two feeds to keep in step.
     *
     * @return list<array<string, mixed>>
     */
    private function composeItems(
        \App\Core\ActorContext $actor,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): array {
        $items = $this->service->listSystemItems($actor, $start, $end);

        // Layer Roster Schedule slots on top. Each expanded (slot, date) pair
        // becomes one all-day, non-blocking calendar item under the source
        // 'rosters' so the calendar UI's filter chip can hide them.
        if ($this->rosterService !== null) {
            try {
                $rosterItems = $this->rosterService->expandRostersInWindow(
                    $start->format('Y-m-d'),
                    $end->format('Y-m-d'),
                );
                foreach ($rosterItems as $r) {
                    $assignees = !empty($r['assignees']) ? implode(', ', $r['assignees']) : 'Open';
                    $title = $r['label']
                        ? $r['label'] . ' — ' . $assignees
                        : ($r['title'] . ' — ' . $assignees);
                    $meta = $r['location']
                        ?? ($r['title'] !== ($r['label'] ?? null) ? $r['title'] : '');
                    $items[] = [
                        'kind'         => 'roster',
                        'source'       => 'rosters',
                        'source_label' => 'Roster schedules',
                        'title'        => $title,
                        'meta'         => $meta,
                        'href'         => null,
                        'date'         => $r['date'],
                    ];
                }
            } catch (\Throwable) {
                // Never let a roster failure poison the rest of the feed.
            }
        }

        try {
            foreach ($this->holidays()->itemsBetween($start, $end) as $holiday) {
                $items[] = $holiday;
            }
        } catch (\Throwable) {
            // Same rule: a missing or malformed holiday cache costs the church
            // its holidays, not its calendar.
        }

        return $items;
    }

    /**
     * Attach role lines to the activities they belong to.
     *
     * Joined on occurrence id — the calendar item already carries `occ:N` as its
     * identity, and the board returns the same number. Matching on title and
     * date would merge two genuinely different services that share a name, which
     * is the defect the item id was introduced to fix in the first place.
     *
     * A role with nobody in it is the point of the exercise, so an assignment
     * row with no person becomes a line with `person: null` rather than being
     * dropped.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function withRoles(
        \App\Core\ActorContext $actor,
        DateTimeImmutable $day,
        array $items,
    ): array {
        if ($this->schedules === null) {
            return $items;
        }

        // The board's window is half-open: occurrence_start >= start AND
        // < end. Passing the same date twice therefore asks for no time at all
        // and returns nothing, which is the same end-boundary trap that once
        // hid every event on the last day of a printed range. One day means
        // midnight to midnight.
        $from = $day->setTime(0, 0, 0);
        $until = $from->modify('+1 day');

        $byOccurrence = [];
        try {
            foreach ($this->schedules->getScheduleBoard($actor, $from, $until) as $row) {
                $occurrenceId = (int) ($row['occurrence_id'] ?? 0);
                if ($occurrenceId <= 0) {
                    continue;
                }
                $person = trim((string) ($row['person_name'] ?? ''));
                $byOccurrence['occ:' . $occurrenceId][] = [
                    'name' => (string) ($row['role_name'] ?? ''),
                    'person' => $person !== '' ? $person : null,
                    'ministry' => (string) ($row['ministry_name'] ?? ''),
                ];
            }
        } catch (\Throwable) {
            // Same rule the roster and holiday reads follow: a schedule failure
            // costs the day its role lines, not its activities.
            return $items;
        }

        foreach ($items as $i => $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id !== '' && isset($byOccurrence[$id])) {
                $items[$i]['roles'] = $byOccurrence[$id];
            }
        }

        return $items;
    }
}
