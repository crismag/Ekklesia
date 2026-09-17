<?php

declare(strict_types=1);

namespace App\DTO\Availability;

use App\Exceptions\ValidationFailed;
use DateTimeImmutable;

final readonly class AvailabilityCommand
{
    public function __construct(
        public int $personId,
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
        public ?string $reason,
    ) {
        if ($personId <= 0) {
            throw new ValidationFailed('person_id is required.');
        }
        if ($endsOn < $startsOn) {
            throw new ValidationFailed('ends_on cannot be before starts_on.');
        }
        // Cap reason at 255 chars to match column width; longer values are
        // a UX concern, not a security one — fail fast rather than truncate.
        if ($reason !== null && strlen($reason) > 255) {
            throw new ValidationFailed('reason must be 255 characters or fewer.');
        }
    }
}
