<?php

declare(strict_types=1);

namespace App\DTO\Schedules;

use DateTimeImmutable;

final readonly class ScheduleOccurrence
{
    public function __construct(
        public int $id,
        public int $eventId,
        public string $eventTitle,
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
    ) {
    }
}
