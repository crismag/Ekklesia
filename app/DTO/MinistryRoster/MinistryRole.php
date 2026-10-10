<?php

declare(strict_types=1);

namespace App\DTO\MinistryRoster;

final readonly class MinistryRole
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isLeaderRole,
        public int $order,
        public bool $active,
        public int $assignedCount,
        public array $assignedMembers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'isLeaderRole'     => $this->isLeaderRole,
            'order'            => $this->order,
            'active'           => $this->active,
            'assignedCount'    => $this->assignedCount,
            'assignedMembers'  => $this->assignedMembers,
        ];
    }
}