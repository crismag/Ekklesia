<?php

declare(strict_types=1);

namespace App\DTO\Schedules;

final readonly class AssignmentSaveResult
{
    public function __construct(
        public bool $saved,
        public int $assignmentCount,
    ) {
    }
}

