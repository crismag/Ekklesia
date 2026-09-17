<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CalendarRepository;
use App\Core\ActorContext;
use App\Core\EventAudience;
use DateTimeImmutable;

final readonly class CalendarService
{
    public function __construct(private CalendarRepository $calendars) {}

    /**
     * @return list<array{kind:string,source:string,source_label:string,title:string,meta:?string,href:?string,date:string}>
     */
    public function listSystemItems(ActorContext $ctx, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $campusId = $ctx->currentCampusId;

        // The calendar is a read path like any other, so leader-audience
        // events are filtered out in SQL rather than hidden in the browser.
        return $this->calendars->listSystemItems($start, $end, $campusId, EventAudience::allowedFor($ctx));
    }
}
