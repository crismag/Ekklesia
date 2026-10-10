<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\CalendarAdapter;
use App\Contracts\CalendarRepository;
use DateTimeImmutable;

final class DefaultCalendarRepository implements CalendarRepository
{
    public function __construct(private readonly CalendarAdapter $adapter) {}

    public function listSystemItems(DateTimeImmutable $start, DateTimeImmutable $end, ?int $campusId = null, array $audiences = []): array
    {
        return $this->adapter->listSystemItems($start, $end, $campusId, $audiences);
    }
}
