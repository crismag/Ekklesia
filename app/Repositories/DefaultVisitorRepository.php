<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\VisitorAdapter;
use App\Contracts\VisitorRepository;

final class DefaultVisitorRepository implements VisitorRepository
{
    public function __construct(private readonly VisitorAdapter $adapter) {}

    public function countRegistrationsByStatus(): array
    {
        return $this->adapter->countRegistrationsByStatus();
    }

    public function listRegistrations(?string $status, string $search, int $limit, int $offset): array
    {
        return $this->adapter->listRegistrations($status, $search, $limit, $offset);
    }

    public function countRegistrations(?string $status, string $search): int
    {
        return $this->adapter->countRegistrations($status, $search);
    }

    public function findRegistration(int $registrationId): ?array
    {
        return $this->adapter->findRegistration($registrationId);
    }

    public function registrationCandidates(string $lastName, string $email, int $excludeId): array
    {
        return $this->adapter->registrationCandidates($lastName, $email, $excludeId);
    }

    public function setRegistrationStatus(int $registrationId, string $status, string $now): void
    {
        $this->adapter->setRegistrationStatus($registrationId, $status, $now);
    }

    public function setReviewerNotes(int $registrationId, ?string $notes, string $now): void
    {
        $this->adapter->setReviewerNotes($registrationId, $notes, $now);
    }

    public function recordPromotion(int $registrationId, int $personId, string $outcome, ?int $accountId, ?string $notes, string $now): void
    {
        $this->adapter->recordPromotion($registrationId, $personId, $outcome, $accountId, $notes, $now);
    }

    public function promotionsFor(int $registrationId): array
    {
        return $this->adapter->promotionsFor($registrationId);
    }

    public function rsvpsForRegistration(int $registrationId): array
    {
        return $this->adapter->rsvpsForRegistration($registrationId);
    }

    public function rsvpOccasions(): array
    {
        return $this->adapter->rsvpOccasions();
    }

    public function rsvpsFor(int $eventId, ?int $occurrenceId): array
    {
        return $this->adapter->rsvpsFor($eventId, $occurrenceId);
    }

    public function findRsvp(int $rsvpId): ?array
    {
        return $this->adapter->findRsvp($rsvpId);
    }

    public function setRsvpAttendance(int $rsvpId, string $attendance, string $now): void
    {
        $this->adapter->setRsvpAttendance($rsvpId, $attendance, $now);
    }

    public function latestAccessCode(string $module): ?array
    {
        return $this->adapter->latestAccessCode($module);
    }

    public function insertAccessCode(string $module, string $code, string $issuedAt, string $expiresAt, ?string $note): void
    {
        $this->adapter->insertAccessCode($module, $code, $issuedAt, $expiresAt, $note);
    }
}
