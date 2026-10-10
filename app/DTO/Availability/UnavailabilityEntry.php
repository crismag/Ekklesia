<?php

declare(strict_types=1);

namespace App\DTO\Availability;

use DateTimeImmutable;

final readonly class UnavailabilityEntry
{
    public function __construct(
        public int $id,
        public int $personId,
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
        public ?string $reason,
        public int $createdByAccountId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                    => $this->id,
            'personId'              => $this->personId,
            'startsOn'              => $this->startsOn->format('Y-m-d'),
            'endsOn'                => $this->endsOn->format('Y-m-d'),
            'reason'                => $this->reason,
            'createdByAccountId' => $this->createdByAccountId,
            'createdAt'             => $this->createdAt->format(DATE_ATOM),
            'updatedAt'             => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
