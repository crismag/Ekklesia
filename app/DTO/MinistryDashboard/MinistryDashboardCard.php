<?php

declare(strict_types=1);

namespace App\DTO\MinistryDashboard;

use DateTimeImmutable;

final readonly class MinistryDashboardCard
{
    /**
     * @param list<MinistrySchedulePreview> $upcomingSchedule
     */
    public function __construct(
        public int $ministryId,
        public string $name,
        public ?int $campusId,
        public string $icon,
        public bool $canOpenSchedule,
        public bool $canViewPeople,
        public bool $canManageSchedules,
        public int $pastAssignmentCount,
        public int $upcomingAssignmentCount,
        public ?DateTimeImmutable $lastOccurrenceAt,
        public ?DateTimeImmutable $nextOccurrenceAt,
        public array $upcomingSchedule,
    ) {
    }

    public function toArray(): array
    {
        return [
            'ministryId' => $this->ministryId,
            'name' => $this->name,
            'campusId' => $this->campusId,
            'icon' => $this->icon,
            'canOpenSchedule' => $this->canOpenSchedule,
            'canViewPeople' => $this->canViewPeople,
            'canManageSchedules' => $this->canManageSchedules,
            'pastAssignmentCount' => $this->pastAssignmentCount,
            'upcomingAssignmentCount' => $this->upcomingAssignmentCount,
            'lastOccurrenceAt' => $this->lastOccurrenceAt?->format(DATE_ATOM),
            'nextOccurrenceAt' => $this->nextOccurrenceAt?->format(DATE_ATOM),
            'upcomingSchedule' => array_map(
                static fn (MinistrySchedulePreview $preview): array => $preview->toArray(),
                $this->upcomingSchedule,
            ),
        ];
    }
}
