<?php

declare(strict_types=1);

namespace App\DTO\Schedules;

/**
 * An event that may appear in the assignment scheduler's event pool.
 *
 * Eligibility is an event-level property. Selection (whether this option is
 * currently contributing occurrences to the grid) is a scheduler concern and
 * lives on ScheduleGrid::$selectedEventIds.
 */
final readonly class SchedulingEventOption
{
    public function __construct(
        public int $id,
        public string $title,
        public bool $isDefault = false,
    ) {
    }

    /**
     * @return array{id:int,title:string,isDefault:bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'isDefault' => $this->isDefault,
        ];
    }
}
