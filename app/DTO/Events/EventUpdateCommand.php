<?php

declare(strict_types=1);

namespace App\DTO\Events;

final readonly class EventUpdateCommand
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?array $campusIds = null,
        // null leaves the type alone. A mis-typed leadership event has to be
        // correctable — otherwise the only remedy for an accidental exposure
        // is deleting the event.
        public ?int $eventTypeId = null,
        // The create path writes ministry_id and nothing could change it, so an
        // event filed under the wrong ministry stayed there. 0 clears it back
        // to church-wide; null leaves it alone.
        public ?int $ministryId = null,
        public ?bool $usesServingSchedule = null,
    ) {}

    public function toArray(): array
    {
        $campusIds = $this->campusIds;
        if ($campusIds !== null) {
            $campusIds = array_values(array_unique(array_filter(
                array_map('intval', $campusIds),
                fn (int $id): bool => $id > 0,
            )));
        }

        return [
            'title' => $this->title,
            'summary' => $this->description,
            'campus_ids' => $campusIds,
            'event_type_id' => $this->eventTypeId,
            'ministry_id' => $this->ministryId,
            'uses_serving_schedule' => $this->usesServingSchedule,
        ];
    }
}
