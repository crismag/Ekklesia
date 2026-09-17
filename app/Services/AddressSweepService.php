<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AddressRepository;
use App\Services\Geocoding\GeocodeHit;
use App\Services\Geocoding\GeocoderThrottled;
use App\Services\Geocoding\ProviderPool;

/**
 * Sweeps the addresses already on file: fills the gaps, refreshes coordinates,
 * and names the ones a person has to look at.
 *
 * Two rules shape everything here.
 *
 * The first is that a stored value is never overwritten by a geocoder. Only
 * blanks are filled. Somebody typed what is on file; a geocoder's opinion is a
 * guess from an index, and a guess does not get to overrule a person.
 *
 * The second is specific to this church and is the reason the first rule is not
 * merely cautious. Toronto amalgamated North York, Scarborough, Etobicoke and
 * East York in 1998, so every geocoder answers "Toronto" for all of them. Two
 * of this church's campuses are named North York and Scarborough. A sweep that
 * accepted the geocoder's city would rewrite most of the roster to "Toronto"
 * and erase exactly the distinction the congregation is organised around. So
 * the boroughs are protected by name, and the geocoder is never allowed to
 * replace one with its amalgamated parent.
 *
 * Coordinates are only written when the answer can be checked. A postal code
 * that disagrees with the one on file means the geocoder matched a different
 * place, and a confident wrong coordinate is worse than none.
 */
final class AddressSweepService
{
    /**
     * Former municipalities now inside the City of Toronto.
     *
     * Kept because they are how this congregation refers to where people live,
     * and because the campuses share the names.
     */
    private const TORONTO_BOROUGHS = [
        'NORTH YORK', 'SCARBOROUGH', 'ETOBICOKE', 'EAST YORK', 'YORK',
        'WILLOWDALE', 'DOWNSVIEW', 'AGINCOURT', 'REXDALE', 'WESTON',
        'DON MILLS', 'NORTH TORONTO',
    ];

    /**
     * How far a geocoded point may sit from the middle of its postal area.
     *
     * Generous, because a rural forward sortation area really is large. It is
     * there to catch the wrong town, not to measure precision.
     */
    private const MAX_KM_FROM_POSTAL_AREA = 40.0;

    public function __construct(
        private readonly AddressRepository $addresses,
        private readonly ProviderPool $pool,
        private readonly AddressNormalizer $normalizer,
        private readonly ?PostalAreaIndex $postal = null,
    ) {
    }

    /**
     * @param array{
     *   onlyMissingCoordinates?:bool, limit?:?int, offset?:int, apply?:bool,
     *   progress?:?callable(int,int,string):void
     * } $options
     * @return array{
     *   stats:array<string,int>,
     *   unresolved:list<array{id:int,label:string,reason:string,issues:list<string>}>,
     *   providers:array<string,int>,
     *   stopped:?string
     * }
     */
    public function sweep(array $options = []): array
    {
        $onlyMissing = $options['onlyMissingCoordinates'] ?? true;
        $limit = $options['limit'] ?? null;
        $apply = $options['apply'] ?? false;
        $progress = $options['progress'] ?? null;

        $families = $this->addresses->listFamilyAddresses($onlyMissing, $limit, (int) ($options['offset'] ?? 0));
        $total = count($families);

        $stats = [
            'examined' => 0, 'geocoded' => 0, 'coordinatesWritten' => 0,
            'fieldsFilled' => 0, 'noMatch' => 0, 'postalMismatch' => 0,
            'boroughProtected' => 0, 'alreadyComplete' => 0, 'skippedNoQuery' => 0,
            'resolvedOffline' => 0, 'farFromPostalArea' => 0,
        ];
        $unresolved = [];
        $stopped = null;

        foreach ($families as $i => $family) {
            $stats['examined']++;
            if ($progress !== null) {
                $progress($i + 1, $total, 'family#' . $family['family_id']);
            }

            // Re-read the stored line first. A street that still carries the
            // city or postal code inside it is fixable without a request, and
            // every request avoided is one the operators do not have to serve.
            $reparsed = $this->normalizer->parse($this->fullLine($family));
            $fill = $this->fieldsToFill($family, [
                'city' => $reparsed['city'],
                'state' => $reparsed['state'],
                'zip' => $reparsed['zip'],
            ]);

            if ($fill !== []) {
                // The postal code answered it. No request needed for this row.
                $stats['resolvedOffline']++;
            }

            $needsCoordinates = ($family['lat'] ?? 0.0) == 0.0 || ($family['lng'] ?? 0.0) == 0.0;
            $stillBlank = $this->blankFields($family, $fill);

            if (!$needsCoordinates && $stillBlank === [] && $fill === []) {
                $stats['alreadyComplete']++;
                continue;
            }

            $hit = null;
            if ($needsCoordinates || $stillBlank !== []) {
                $query = $this->query($family, $fill);
                if (trim($query['street']) === '' && trim($query['zip']) === '') {
                    $stats['skippedNoQuery']++;
                    $unresolved[] = $this->unresolvedRow($family, 'nothing specific enough to look up', $reparsed['issues']);
                    continue;
                }
                try {
                    $hit = $this->pool->lookup($query);
                    $stats['geocoded']++;
                } catch (GeocoderThrottled $e) {
                    $stopped = $e->getMessage();
                    break;
                }
            }

            if ($hit === null && ($needsCoordinates || $stillBlank !== [])) {
                $stats['noMatch']++;
                $unresolved[] = $this->unresolvedRow($family, 'no geocoder could match this address', $reparsed['issues']);
            }

            if ($hit !== null) {
                $verdict = $this->verify($family, $fill, $hit);
                if ($verdict !== null) {
                    if (str_contains($verdict, 'from its postal area')) {
                        $stats['farFromPostalArea']++;
                    } else {
                        $stats['postalMismatch']++;
                    }
                    $unresolved[] = $this->unresolvedRow($family, $verdict, $reparsed['issues']);
                } elseif ($needsCoordinates) {
                    if ($apply) {
                        $this->addresses->saveFamilyCoordinates($family['family_id'], $hit->lat, $hit->lng);
                    }
                    $stats['coordinatesWritten']++;
                }

                if ($verdict === null) {
                    $fromHit = $this->fieldsToFill($family, [
                        'city' => $this->cityFrom($family, $fill, $hit, $stats),
                        'state' => $hit->state,
                        'zip' => $hit->zip,
                    ], $fill);
                    $fill += $fromHit;
                }
            }

            if ($fill !== []) {
                if ($apply) {
                    $this->addresses->saveFamilyAddressFields($family['family_id'], $fill);
                }
                $stats['fieldsFilled'] += count($fill);
            }
        }

        return [
            'stats' => $stats,
            'unresolved' => $unresolved,
            'providers' => $this->pool->stats(),
            'stopped' => $stopped,
        ];
    }

    /**
     * The geocoder's city, unless taking it would erase a borough.
     *
     * @param array<string,mixed> $family
     * @param array<string,string> $fill
     * @param array<string,int> $stats
     */
    private function cityFrom(array $family, array $fill, GeocodeHit $hit, array &$stats): string
    {
        $existing = trim((string) ($fill['city'] ?? $family['city']));
        if ($existing === '') {
            return $hit->city;
        }
        if ($this->isBoroughOf($existing, $hit->city)) {
            $stats['boroughProtected']++;
        }

        return '';
    }

    /** True when the geocoder answered with the amalgamated parent of what we hold. */
    private function isBoroughOf(string $existing, string $suggested): bool
    {
        return strtoupper(trim($suggested)) === 'TORONTO'
            && in_array(strtoupper(trim($existing)), self::TORONTO_BOROUGHS, true);
    }

    /**
     * Reject a hit that plainly describes somewhere else.
     *
     * @param array<string,mixed> $family
     * @param array<string,string> $fill
     */
    private function verify(array $family, array $fill, GeocodeHit $hit): ?string
    {
        $postal = (string) ($fill['zip'] ?? $family['zip']);
        $ours = $this->fsa($postal);
        $theirs = $this->fsa($hit->zip);
        if ($ours !== '' && $theirs !== '' && $ours !== $theirs) {
            return 'the geocoder matched a different postal area than the one on file';
        }

        // A geocoder that returns no postal code slips past the check above.
        // The postal area's centroid catches it instead: a forward sortation
        // area spans a few kilometres in a city, so a point tens of kilometres
        // away is describing somewhere else whatever it calls itself.
        $distance = $this->postal?->distanceFromCentroidKm($postal, $hit->lat, $hit->lng);
        if ($distance !== null && $distance > self::MAX_KM_FROM_POSTAL_AREA) {
            return sprintf(
                'the geocoder placed this %dkm from its postal area',
                (int) round($distance),
            );
        }

        return null;
    }

    /** The forward sortation area: the first three characters of a Canadian postal code. */
    private function fsa(string $postal): string
    {
        $clean = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $postal));

        return strlen($clean) >= 3 ? substr($clean, 0, 3) : '';
    }

    /**
     * Which of the candidate values are worth writing.
     *
     * Only blanks. A field that already holds something keeps it.
     *
     * @param array<string,mixed> $family
     * @param array<string,string> $candidates
     * @param array<string,string> $already
     * @return array<string,string>
     */
    private function fieldsToFill(array $family, array $candidates, array $already = []): array
    {
        $out = [];
        foreach ($candidates as $field => $value) {
            $value = trim($value);
            if ($value === '' || isset($already[$field])) {
                continue;
            }
            if (trim((string) ($family[$field] ?? '')) === '') {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $family
     * @param array<string,string> $fill
     * @return list<string>
     */
    private function blankFields(array $family, array $fill): array
    {
        $blank = [];
        foreach (['city', 'zip'] as $field) {
            if (trim((string) ($family[$field] ?? '')) === '' && !isset($fill[$field])) {
                $blank[] = $field;
            }
        }

        return $blank;
    }

    /**
     * @param array<string,mixed> $family
     * @param array<string,string> $fill
     * @return array{street:string,city:string,state:string,zip:string,country:string}
     */
    private function query(array $family, array $fill): array
    {
        // Unit and apartment tokens defeat geocoders, which index buildings
        // rather than the doors inside them.
        $street = trim((string) preg_replace(
            '/\s*(#|\bunit\b|\bapt\.?\b|\bsuite\b|\bste\.?\b)\s*\S+/i',
            '',
            (string) $family['street'],
        ));
        $street = trim((string) preg_replace('/^\s*\d+\s*-\s*/', '', $street));

        return [
            'street' => $street !== '' ? $street : (string) $family['street'],
            'city' => (string) ($fill['city'] ?? $family['city']),
            'state' => (string) ($fill['state'] ?? $family['state']),
            'zip' => (string) ($fill['zip'] ?? $family['zip']),
            'country' => (string) ($family['country'] !== '' ? $family['country'] : 'CA'),
        ];
    }

    /** @param array<string,mixed> $family */
    private function fullLine(array $family): string
    {
        return implode(', ', array_filter([
            (string) $family['street'], (string) $family['city'],
            (string) $family['state'], (string) $family['zip'],
        ], static fn (string $v): bool => trim($v) !== ''));
    }

    /**
     * @param array<string,mixed> $family
     * @param list<string> $issues
     * @return array{id:int,label:string,reason:string,issues:list<string>}
     */
    private function unresolvedRow(array $family, string $reason, array $issues): array
    {
        return [
            'id' => (int) $family['family_id'],
            'label' => $this->addresses->familyLabel((int) $family['family_id']),
            'reason' => $reason,
            'issues' => $issues,
        ];
    }
}
