<?php

declare(strict_types=1);

namespace App\DTO\MySchedule;

use DateTimeImmutable;

final readonly class MyScheduleView
{
    /**
     * @param list<MyAssignment> $assignments
     */
    public function __construct(
        public int $personId,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public array $assignments,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'personId' => $this->personId,
            'start' => $this->start->format('Y-m-d'),
            'end' => $this->end->format('Y-m-d'),
            'assignments' => array_map(
                static fn (MyAssignment $assignment): array => $assignment->toArray(),
                $this->assignments,
            ),
        ];
    }
}
