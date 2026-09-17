<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * OpenStreetMap's Nominatim. Free, no key, and strict about how it is used.
 *
 * Its usage policy caps callers at one request per second and requires a
 * User-Agent that identifies the application and a way to contact whoever runs
 * it. Both are honoured here rather than left to the caller, because a default
 * that violates the policy is a default that gets the church's address blocked.
 */
final class NominatimProvider implements GeocodingProvider
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    public function __construct(
        private readonly HttpGet $http,
        private readonly string $userAgent,
        private readonly int $minIntervalMs = 1200,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function name(): string
    {
        return 'nominatim';
    }

    public function minIntervalMs(): int
    {
        return $this->minIntervalMs;
    }

    /**
     * @param array{street:string,city:string,state:string,zip:string,country:string} $address
     */
    public function lookup(array $address): ?GeocodeHit
    {
        $query = array_filter([
            'street' => $address['street'],
            'city' => $address['city'],
            'state' => $address['state'],
            'postalcode' => $address['zip'],
            'country' => $address['country'],
        ], static fn (string $v): bool => trim($v) !== '');
        if ($query === []) {
            return null;
        }
        $query += ['format' => 'jsonv2', 'limit' => '1', 'addressdetails' => '1'];

        $res = $this->http->get(
            self::ENDPOINT . '?' . http_build_query($query),
            ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
            $this->timeoutSeconds,
        );

        $this->guardThrottling($res);
        if ($res['status'] !== 200) {
            return null;
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data) || !isset($data[0]['lat'], $data[0]['lon'])) {
            return null;
        }
        $hit = $data[0];
        $parts = is_array($hit['address'] ?? null) ? $hit['address'] : [];

        return new GeocodeHit(
            lat: (float) $hit['lat'],
            lng: (float) $hit['lon'],
            // Nominatim reports the settlement under whichever of these keys
            // fits the place, so they are tried in order of specificity.
            city: (string) ($parts['city'] ?? $parts['town'] ?? $parts['village']
                ?? $parts['municipality'] ?? $parts['suburb'] ?? ''),
            state: (string) ($parts['state'] ?? ''),
            zip: (string) ($parts['postcode'] ?? ''),
            country: (string) ($parts['country_code'] ?? ''),
            provider: $this->name(),
            label: (string) ($hit['display_name'] ?? ''),
        );
    }

    /** @param array{status:int,body:string,headers:array<string,string>} $res */
    private function guardThrottling(array $res): void
    {
        if (!in_array($res['status'], [429, 503, 403], true)) {
            return;
        }
        throw new GeocoderThrottled(
            'Nominatim returned HTTP ' . $res['status'] . '.',
            (int) ($res['headers']['retry-after'] ?? 0),
        );
    }
}
