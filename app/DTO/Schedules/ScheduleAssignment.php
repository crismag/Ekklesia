<?php

declare(strict_types=1);

namespace App\DTO\Schedules;

use DateTimeImmutable;

final readonly class ScheduleAssignment
{
    public function __construct(
        public ?int $id,
        public int $personId,
        public int $roleId,
        public DateTimeImmutable $startsOn,
        public string $label = '',
        public ?int $occurrenceId = null,
        public string $displayName = '',
    ) {
    }
}

