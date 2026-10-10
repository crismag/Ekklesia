<?php

declare(strict_types=1);

namespace App\DTO\MinistryDashboard;

use DateTimeImmutable;

final readonly class MinistrySchedulePreview
{
    public function __construct(
        public int $occurrenceId,
        public string $eventTitle,
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
        public int $assignmentCount,
    ) {
    }

    public function toArray(): array
    {
        return [
            'occurrenceId' => $this->occurrenceId,
            'eventTitle' => $this->eventTitle,
            'startsOn' => $this->startsOn->format(DATE_ATOM),
            'endsOn' => $this->endsOn->format(DATE_ATOM),
            'assignmentCount' => $this->assignmentCount,
        ];
    }
}