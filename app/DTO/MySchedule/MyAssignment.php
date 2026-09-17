<?php

declare(strict_types=1);

namespace App\DTO\MySchedule;

use DateTimeImmutable;

final readonly class MyAssignment
{
    public function __construct(
        public int $id,
        public int $personId,
        public int $roleId,
        public string $roleName,
        public int $ministryId,
        public string $ministryName,
        public int $eventId,
        public string $eventTitle,
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
        public string $status,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'personId' => $this->personId,
            'roleId' => $this->roleId,
            'roleName' => $this->roleName,
            'ministryId' => $this->ministryId,
            'ministryName' => $this->ministryName,
            'eventId' => $this->eventId,
            'eventTitle' => $this->eventTitle,
            'startsOn' => $this->startsOn->format(DateTimeImmutable::ATOM),
            'endsOn' => $this->endsOn->format(DateTimeImmutable::ATOM),
            'status' => $this->status,
        ];
    }
}
