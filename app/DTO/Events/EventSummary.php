<?php

declare(strict_types=1);

namespace App\DTO\Events;

final readonly class EventSummary
{
    public function __construct(
        public int $eventId,
        public string $title,
        public ?string $description,
        public int $occurrenceCount,
        public ?string $nextOccurrenceAt,
        public ?string $nextOccurrenceEnd = null,
        public ?string $campusNames = null,
        public ?string $hostCampusName = null,
        public ?string $locationName = null,
        public ?int $ministryId = null,
        public ?string $ministryName = null,
        public ?int $eventTypeId = null,
        public ?string $eventTypeLabel = null,
        public ?string $eventTypeColor = null,
    ) {}

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'title' => $this->title,
            'description' => $this->description,
            'occurrence_count' => $this->occurrenceCount,
            'next_occurrence_at' => $this->nextOccurrenceAt,
            'next_occurrence_end' => $this->nextOccurrenceEnd,
            'campus_names' => $this->campusNames,
            'host_campus_name' => $this->hostCampusName,
            'location_name' => $this->locationName,
            'ministry_id' => $this->ministryId,
            'ministry_name' => $this->ministryName,
            'event_type_id' => $this->eventTypeId,
            'event_type_label' => $this->eventTypeLabel,
            'event_type_color' => $this->eventTypeColor,
        ];
    }
}
