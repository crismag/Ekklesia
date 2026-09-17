<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * The operator asked us to back off.
 *
 * Distinct from "no match": a throttled request tells us nothing about the
 * address, so retrying it later is right, whereas retrying a genuine miss is
 * just more load for the same answer.
 */
final class GeocoderThrottled extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds = 0,
    ) {
        parent::__construct($message);
    }
}
