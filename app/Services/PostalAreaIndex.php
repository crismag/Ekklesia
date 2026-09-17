<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What a Canadian postal code tells us without asking anyone.
 *
 * Two layers, and the first needs no data file at all: the opening letter of a
 * postal code identifies the province outright. That is a property of how
 * Canada Post allocated the alphabet, not a lookup — every K, L, M, N and P
 * code is in Ontario, every T is in Alberta. Only X is shared, between the
 * Northwest Territories and Nunavut.
 *
 * The second layer is config/ca-postal-areas.json, built from GeoNames' postal
 * dump: 1,651 forward sortation areas with a place name and a centroid. It
 * names the neighbourhood as well as the province, and it does so with the
 * boroughs intact — "Scarborough" where a geocoder insists on "Toronto",
 * because Toronto absorbed its boroughs in 1998 and geocoders index the result
 * rather than the names people still use.
 *
 * The point of both is that an address carrying a postal code needs no network
 * request to establish where it is. Of the addresses staged from the workbook,
 * 89% carry one.
 */
final class PostalAreaIndex
{
    /**
     * Postal code opening letter to province.
     *
     * Reliable enough to use on a code whose forward sortation area is missing
     * from the file, which is why it is kept separately from it.
     */
    private const PROVINCE_BY_LETTER = [
        'A' => 'NL', 'B' => 'NS', 'C' => 'PE', 'E' => 'NB',
        'G' => 'QC', 'H' => 'QC', 'J' => 'QC',
        'K' => 'ON', 'L' => 'ON', 'M' => 'ON', 'N' => 'ON', 'P' => 'ON',
        'R' => 'MB', 'S' => 'SK', 'T' => 'AB', 'V' => 'BC',
        'Y' => 'YT',
        // X covers both the Northwest Territories and Nunavut, so it is
        // deliberately absent: a guess between two provinces is not a fact.
    ];

    /** @var array<string,array{city:string,province:string,lat:float,lng:float,exact:bool}> */
    private array $areas;

    /** @param array<string,array<string,mixed>> $areas */
    public function __construct(array $areas = [])
    {
        $this->areas = [];
        foreach ($areas as $fsa => $area) {
            $this->areas[strtoupper((string) $fsa)] = [
                'city' => (string) ($area['city'] ?? ''),
                'province' => strtoupper((string) ($area['province'] ?? '')),
                'lat' => (float) ($area['lat'] ?? 0),
                'lng' => (float) ($area['lng'] ?? 0),
                'exact' => (bool) ($area['exact'] ?? false),
            ];
        }
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $areas = is_array($decoded) ? ($decoded['areas'] ?? []) : [];

        return new self(is_array($areas) ? $areas : []);
    }

    /** The forward sortation area: the first three characters, normalised. */
    public function fsa(string $postal): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $postal));

        return preg_match('/^[A-Z]\d[A-Z]/', $clean) === 1 ? substr($clean, 0, 3) : '';
    }

    /**
     * @return array{city:string,province:string,lat:float,lng:float,exact:bool}|null
     */
    public function area(string $postal): ?array
    {
        $fsa = $this->fsa($postal);

        return $fsa === '' ? null : ($this->areas[$fsa] ?? null);
    }

    /**
     * The province a postal code belongs to.
     *
     * The file first, then the letter. The letter alone is enough when the
     * file has not heard of the area, which is why this never returns a guess:
     * either something establishes the province or nothing does.
     */
    public function province(string $postal): ?string
    {
        $area = $this->area($postal);
        if ($area !== null && $area['province'] !== '') {
            return $area['province'];
        }
        $fsa = $this->fsa($postal);

        return $fsa === '' ? null : (self::PROVINCE_BY_LETTER[$fsa[0]] ?? null);
    }

    /**
     * The place name, but only when it names a municipality.
     *
     * Rural areas are recorded under regional labels — "Eastern Alberta" —
     * which are true but are not anybody's city, so they are not offered for
     * writing into a record.
     */
    public function city(string $postal): ?string
    {
        $area = $this->area($postal);

        return $area !== null && $area['exact'] && $area['city'] !== '' ? $area['city'] : null;
    }

    /** @return array{lat:float,lng:float}|null */
    public function centroid(string $postal): ?array
    {
        $area = $this->area($postal);
        if ($area === null || ($area['lat'] === 0.0 && $area['lng'] === 0.0)) {
            return null;
        }

        return ['lat' => $area['lat'], 'lng' => $area['lng']];
    }

    /**
     * Roughly how far a point is from the middle of its postal area, in km.
     *
     * Used to sanity-check a geocoder: a forward sortation area spans a few
     * kilometres in a city and rather more in the country, so a hit tens of
     * kilometres from the centroid is describing somewhere else. The equirect-
     * angular approximation is far more precision than that judgement needs.
     */
    public function distanceFromCentroidKm(string $postal, float $lat, float $lng): ?float
    {
        $centre = $this->centroid($postal);
        if ($centre === null) {
            return null;
        }
        $meanLat = deg2rad(($centre['lat'] + $lat) / 2);
        $dLat = deg2rad($lat - $centre['lat']);
        $dLng = deg2rad($lng - $centre['lng']) * cos($meanLat);

        return sqrt($dLat ** 2 + $dLng ** 2) * 6371.0;
    }

    /**
     * Municipality names, for splitting an address line that has no commas.
     *
     * @return list<string>
     */
    public function cityNames(): array
    {
        $names = [];
        foreach ($this->areas as $area) {
            if ($area['exact'] && $area['city'] !== '') {
                $names[$area['city']] = true;
            }
        }

        return array_keys($names);
    }

    public function count(): int
    {
        return count($this->areas);
    }
}
