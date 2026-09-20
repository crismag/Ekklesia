<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Too many sign-in attempts from one place, or against one address.
 *
 * Deliberately says nothing about whether the address exists, whether the
 * password was close, or which limit was reached — somebody who is refused for
 * guessing must learn no more than somebody who simply mistyped.
 */
final class TooManyAttempts extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        string $message = 'Too many attempts. Please wait a few minutes and try again.',
    ) {
        parent::__construct($message);
    }
}
