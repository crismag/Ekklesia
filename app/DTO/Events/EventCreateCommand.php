<?php

declare(strict_types=1);

namespace App\DTO\Events;

/**
 * Everything needed to create a calendar event in one step.
 *
 * This previously carried only a title, description, duration and campus list —
 * no date at all — while events.starts_on is NOT NULL with no default.
 * The adapter compensated by inserting NULL, so every create attempt failed with
 * an integrity-constraint violation. Creating an event through the portal has
 * therefore never worked.
 *
 * An event here is "something that appears on the church calendar". RSVP is a
 * separate, optional concern and is deliberately not part of this command.
 */
final readonly class EventCreateCommand
{
    /**
     * @param list<int>    $campusIds     campuses the event is *relevant to*
     * @param list<string> $selectedDates Y-m-d list, used when pattern is 'selected'
     */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $startDate = null,
        public ?string $startTime = null,
        public ?string $endTime = null,
        public bool $allDay = false,
        public string $pattern = 'one_off',
        public ?int $count = null,
        public ?string $untilOn = null,
        public array $selectedDates = [],
        public array $campusIds = [],
        // The campus that physically hosts it, which is not always the campus
        // the event is for: a joint service can be relevant to Scarborough and
        // held at North York.
        public ?int $hostCampusId = null,
        public ?string $locationName = null,
        public ?string $locationAddress = null,
        public ?int $ministryId = null,
        // Which category the event is filed under. This decides who may read it,
        // so null means "use the default type" rather than "no type" — an
        // untyped event would fall back to the members audience by accident.
        public ?int $eventTypeId = null,
        public ?int $defaultDurationMin = 90,
        public bool $usesServingSchedule = false,
    ) {}

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->description,
            'start_date' => $this->startDate,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'all_day' => $this->allDay,
            'pattern' => $this->pattern,
            'count' => $this->count,
            'until_on' => $this->untilOn,
            'event_type_id' => $this->eventTypeId,
            'selected_dates' => array_values(array_filter(array_map('strval', $this->selectedDates))),
            'campus_ids' => array_values(array_unique(array_filter(
                array_map('intval', $this->campusIds),
                static fn (int $id): bool => $id > 0,
            ))),
            'host_campus_id' => $this->hostCampusId,
            'location_name' => $this->locationName,
            'location_address' => $this->locationAddress,
            'ministry_id' => $this->ministryId,
            'defaultDurationMin' => $this->defaultDurationMin,
            'uses_serving_schedule' => $this->usesServingSchedule,
        ];
    }
}
