<?php

declare(strict_types=1);

namespace App\DTO\MinistryRoster;

use DateTimeImmutable;

final readonly class MinistryRosterView
{
    /**
     * @param list<MinistryRosterMember> $members
     */
    public function __construct(
        public int $ministryId,
        public string $ministryName,
        public ?int $campusId,
        public DateTimeImmutable $since,
        public ?int $campusFilter,
        public array $members,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ministryId'   => $this->ministryId,
            'ministryName' => $this->ministryName,
            'campusId'     => $this->campusId,
            'since'        => $this->since->format('Y-m-d'),
            'campusFilter' => $this->campusFilter,
            'memberCount'  => count($this->members),
            'members'      => array_map(
                static fn (MinistryRosterMember $m): array => $m->toArray(),
                $this->members,
            ),
        ];
    }
}
