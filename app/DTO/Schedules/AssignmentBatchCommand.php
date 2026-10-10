<?php

declare(strict_types=1);

namespace App\DTO\Schedules;

final readonly class AssignmentBatchCommand
{
    /**
     * Full-state batch: $assignments lists every assignment that should
     * exist for ($ministryId, [start, end)). Server diffs against existing
     * rows in that window and applies inserts/updates/deletes.
     *
     * Pass null for $start/$end only in legacy/test paths that don't need
     * the diff window (the persistence stub honors both shapes).
     *
     * @param list<ScheduleAssignment> $assignments
     * @param list<int>|null $eventIds null = legacy unscoped window; [] = no event
     *                                 scope (do not delete unmatched rows);
     *                                 non-empty = diff only those events
     */
    public function __construct(
        public int $ministryId,
        public array $assignments,
        public ?\DateTimeImmutable $start = null,
        public ?\DateTimeImmutable $end = null,
        public ?int $campusId = null,
        public array $campusIds = [],
        public string $reason = 'schedule_assignment_save',
        public ?array $eventIds = null,
    ) {
    }
}

