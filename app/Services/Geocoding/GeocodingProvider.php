<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * A geocoding service this portal is willing to ask.
 *
 * Two exist so the work can be split across operators rather than aimed at
 * one. Each declares its own minimum interval, because the polite rate is a
 * property of the service, not of our loop.
 */
interface GeocodingProvider
{
    public function name(): string;

    /** The shortest gap this operator's usage policy permits between requests. */
    public function minIntervalMs(): int;

    /**
     * @param array{street:string,city:string,state:string,zip:string,country:string} $address
     * @throws GeocoderThrottled when the operator asked us to slow down or stop
     */
    public function lookup(array $address): ?GeocodeHit;
}
