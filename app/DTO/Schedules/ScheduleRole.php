<?php

declare(strict_types=1);

namespace App\DTO\Schedules;

final readonly class ScheduleRole
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}

