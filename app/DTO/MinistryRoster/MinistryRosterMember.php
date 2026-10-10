<?php

declare(strict_types=1);

namespace App\DTO\MinistryRoster;

use DateTimeImmutable;

final readonly class MinistryRosterMember
{
    public function __construct(
        public int $personId,
        public string $displayName,
        public ?int $primaryCampusId,
        public int $assignmentCount,
        public ?DateTimeImmutable $lastServedAt,
        public string $rolesServed,
        public array $leaderRoles,
        public array $regularRoles,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'personId'         => $this->personId,
            'displayName'      => $this->displayName,
            'primaryCampusId'  => $this->primaryCampusId,
            'assignmentCount'  => $this->assignmentCount,
            'lastServedAt'     => $this->lastServedAt?->format(DATE_ATOM),
            'rolesServed'      => $this->rolesServed,
            'leaderRoles'      => $this->leaderRoles,
            'regularRoles'     => $this->regularRoles,
        ];
    }
}
