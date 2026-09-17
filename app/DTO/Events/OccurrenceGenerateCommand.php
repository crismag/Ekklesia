<?php

declare(strict_types=1);

namespace App\DTO\Events;

use DateTimeImmutable;

final readonly class OccurrenceGenerateCommand
{
    public function __construct(
        public int $eventId,
        public string $pattern, // 'one_off' | 'weekly' | 'biweekly'
        public DateTimeImmutable $startsAt,
        public int $durationMin,
        public ?int $count = null,
        public ?DateTimeImmutable $untilOn = null,
    ) {}

    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId,
            'pattern' => $this->pattern,
            'startsAt' => $this->startsAt->format(DATE_ATOM),
            'durationMin' => $this->durationMin,
            'count' => $this->count,
            'untilOn' => $this->untilOn?->format('Y-m-d'),
        ];
    }
}
