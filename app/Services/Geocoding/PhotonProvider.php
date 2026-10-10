<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * Photon, run by Komoot. Also free, also OpenStreetMap data, different operator.
 *
 * It exists here so a run is not one service's problem. Alternating halves
 * what either host sees, and when one starts refusing the other carries on.
 * Its query is free-text only, so the structured address is flattened.
 */
final class PhotonProvider implements GeocodingProvider
{
    private const ENDPOINT = 'https://photon.komoot.io/api/';

    public function __construct(
        private readonly HttpGet $http,
        private readonly string $userAgent,
        private readonly int $minIntervalMs = 1200,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function name(): string
    {
        return 'photon';
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
        $text = implode(', ', array_filter([
            $address['street'], $address['city'], $address['state'],
            $address['zip'], $address['country'],
        ], static fn (string $v): bool => trim($v) !== ''));
        if ($text === '') {
            return null;
        }

        $res = $this->http->get(
            self::ENDPOINT . '?' . http_build_query(['q' => $text, 'limit' => '1']),
            ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
            $this->timeoutSeconds,
        );

        if (in_array($res['status'], [429, 503, 403], true)) {
            throw new GeocoderThrottled(
                'Photon returned HTTP ' . $res['status'] . '.',
                (int) ($res['headers']['retry-after'] ?? 0),
            );
        }
        if ($res['status'] !== 200) {
            return null;
        }

        $data = json_decode($res['body'], true);
        $feature = $data['features'][0] ?? null;
        // GeoJSON orders coordinates longitude first. Reading them the other
        // way round puts Toronto in the Indian Ocean, so it is worth stating.
        $coords = is_array($feature['geometry']['coordinates'] ?? null)
            ? $feature['geometry']['coordinates']
            : null;
        if (!is_array($coords) || !isset($coords[0], $coords[1])) {
            return null;
        }
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

        return new GeocodeHit(
            lat: (float) $coords[1],
            lng: (float) $coords[0],
            city: (string) ($props['city'] ?? $props['district'] ?? $props['county'] ?? ''),
            state: (string) ($props['state'] ?? ''),
            zip: (string) ($props['postcode'] ?? ''),
            country: (string) ($props['countrycode'] ?? ''),
            provider: $this->name(),
            label: (string) ($props['name'] ?? ''),
        );
    }
}
