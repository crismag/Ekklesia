<?php

declare(strict_types=1);

namespace App\DTO\Events;

final readonly class EventDetailView
{
    /** @param array<int, array{occurrence_id:int, starts_at:string, ends_at:string}> $occurrences */
    public function __construct(
        public int $eventId,
        public string $title,
        public ?string $description,
        public array $occurrences,
        public array $campusIds = [],
        public array $availableCampuses = [],
        public bool $isMultiCampus = false,
        // The detail view carried neither ministry nor type, so the page could
        // not show what the list already knew.
        public ?int $ministryId = null,
        public ?string $ministryName = null,
        public ?int $eventTypeId = null,
        public ?string $eventTypeLabel = null,
        public ?string $eventTypeColor = null,
        public bool $usesServingSchedule = false,
    ) {}

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'title' => $this->title,
            'description' => $this->description,
            'occurrences' => $this->occurrences,
            'campus_ids' => $this->campusIds,
            'available_campuses' => $this->availableCampuses,
            'is_multi_campus' => $this->isMultiCampus,
            'ministry_id' => $this->ministryId,
            'ministry_name' => $this->ministryName,
            'event_type_id' => $this->eventTypeId,
            'event_type_label' => $this->eventTypeLabel,
            'event_type_color' => $this->eventTypeColor,
            'uses_serving_schedule' => $this->usesServingSchedule,
            'usesServingSchedule' => $this->usesServingSchedule,
        ];
    }
}
