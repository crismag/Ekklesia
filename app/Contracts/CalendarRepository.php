<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

interface CalendarRepository
{
    /**
     * @return list<array{
     *   kind:string,
     *   source:string,
     *   source_label:string,
     *   title:string,
     *   meta:?string,
     *   href:?string,
     *   date:string
     * }>
     */
    public function listSystemItems(DateTimeImmutable $start, DateTimeImmutable $end, ?int $campusId = null, array $audiences = []): array;
}
