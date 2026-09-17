<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

interface CalendarAdapter
{
    /**
     * starts_at / ends_at are optional ISO-style datetimes; when supplied
     * the front-end prefers them over `date` for day/week placement so a
     * 12:00 PM event lands at the right hour. Items without a clock (e.g.
     * birthdays) only set `date` and the renderer treats them as all-day.
     *
     * @return list<array{
     *   kind:string,
     *   source:string,
     *   source_label:string,
     *   title:string,
     *   meta:?string,
     *   href:?string,
     *   date:string,
     *   starts_at?:string,
     *   ends_at?:string,
     *   color?:?string,
     *   id?:string
     * }>
     *
     * `source` is the calendar layer key. Event items namespace it as
     * "events:<type-slug>" so a type named after an existing layer cannot
     * collide with it. `color` is the layer colour an admin chose; `id` is a
     * stable identity the front-end prefers over date|title|source when
     * de-duplicating.
     */
    public function listSystemItems(DateTimeImmutable $start, DateTimeImmutable $end, ?int $campusId = null, array $audiences = []): array;
}
