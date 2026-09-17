<?php

declare(strict_types=1);

namespace App\DTO\Schedules;

final readonly class SchedulePerson
{
    public function __construct(
        public int $id,
        public string $displayName,
    ) {
    }
}

