<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/** One geocoder answer, reduced to the fields worth keeping. */
final readonly class GeocodeHit
{
    public function __construct(
        public float $lat,
        public float $lng,
        public string $city = '',
        public string $state = '',
        public string $zip = '',
        public string $country = '',
        public string $provider = '',
        public string $label = '',
    ) {
    }
}
