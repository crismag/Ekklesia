<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * The visitors database (SQLite, database/visitors/001_schema.sql):
 * registrations, RSVPs, promotions and the greeters' access codes.
 *
 * Timestamps are church local time strings (Y-m-d H:i:s), as the sign-up and
 * RSVP modules write them; callers pass them in.
 */
interface VisitorAdapter
{
    /**
     * Registrations per status: new, reviewed, duplicate, promoted, rejected.
     *
     * @return array<string,int>
     */
    public function countRegistrationsByStatus(): array;

    /**
     * Registrations in a status (null for all), newest first, matching a search
     * over name, email, phone and city.
     *
     * @return list<array<string,mixed>>
     */
    public function listRegistrations(?string $status, string $search, int $limit, int $offset): array;

    public function countRegistrations(?string $status, string $search): int;

    /** @return array<string,mixed>|null */
    public function findRegistration(int $registrationId): ?array;

    /**
     * Other open registrations (new, reviewed, duplicate) with this last name or
     * email: the candidates for "someone registered twice".
     *
     * @return list<array<string,mixed>>
     */
    public function registrationCandidates(string $lastName, string $email, int $excludeId): array;

    /** Set a review decision; reviewed_at is stamped for anything but 'new'. */
    public function setRegistrationStatus(int $registrationId, string $status, string $now): void;

    public function setReviewerNotes(int $registrationId, ?string $notes, string $now): void;

    /**
     * Mark a registration promoted and record visitor_promotions, together.
     */
    public function recordPromotion(int $registrationId, int $personId, string $outcome, ?int $accountId, ?string $notes, string $now): void;

    /** @return list<array<string,mixed>> visitor_promotions rows, newest first */
    public function promotionsFor(int $registrationId): array;

    /** @return list<array<string,mixed>> RSVPs filed under this registration */
    public function rsvpsForRegistration(int $registrationId): array;

    /**
     * Event dates that have RSVPs: event_id, occurrence_id, responses, last_response_at.
     *
     * @return list<array<string,mixed>>
     */
    public function rsvpOccasions(): array;

    /** @return list<array<string,mixed>> */
    public function rsvpsFor(int $eventId, ?int $occurrenceId): array;

    /** @return array<string,mixed>|null */
    public function findRsvp(int $rsvpId): ?array;

    public function setRsvpAttendance(int $rsvpId, string $attendance, string $now): void;

    /** @return array<string,mixed>|null the latest code issued for 'signup' or 'rsvp' */
    public function latestAccessCode(string $module): ?array;

    public function insertAccessCode(string $module, string $code, string $issuedAt, string $expiresAt, ?string $note): void;
}
