<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use DateTimeImmutable;

/**
 * Result of a successful login or session validation.
 * Carries the session token plus everything needed to build an ActorContext.
 */
final readonly class AuthSession
{
    public function __construct(
        public int $accountId,
        public string $email,
        public ?string $displayName,
        public string $sessionToken,
        public DateTimeImmutable $expiresAt,
        public ?int $personId,
        // Set when the account was just provisioned from a person record
        // OR an admin reset the user's password and flagged the row. The login
        // UI uses this to redirect to /password/change before continuing.
        public bool $mustChangePassword = false,
    ) {
    }
}
