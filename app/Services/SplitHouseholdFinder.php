<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One household entered as two family records.
 *
 * It happens in several shapes and they are all the same mistake. A married
 * couple as two one-person families. A son split off from his parents and
 * siblings. A daughter whose surname was typed without an "n", so nothing
 * grouped her with the rest. Thirteen households on this roster were split
 * this way.
 *
 * The postcode is the anchor rather than the street line. A Canadian postcode
 * is granular and hard to mistype into another valid one, whereas street text
 * is written differently every time — one of these pairs gave street numbers a
 * digit apart at the same unit, and no grouping on address text would ever
 * have put them together.
 *
 * Surnames are compared across members rather than taking the family's name,
 * which lags behind edits, and a household that only agrees on the postcode is
 * reported but never offered for merging: a postcode can cover a whole
 * building, and two unrelated families of one surname in one tower is a thing
 * that happens.
 *
 * Candidates, never merges. Merging keeps one record's address and discards the
 * other's, so whatever does not match is named rather than smoothed over.
 */
final class SplitHouseholdFinder
{
    /** Same surname, same address. */
    public const CERTAIN = 'certain';

    /** Same surname, addresses a typo apart — or a surname typo at one address. */
    public const LIKELY = 'likely';

    /** Only the postcode and a surname agree. Report; do not merge. */
    public const POSSIBLE = 'possible';

    /** Edits between two addresses that still read as one address typed twice. */
    private const MAX_ADDRESS_EDITS = 2;

    /** Edits between two surnames that still read as one name typed twice. */
    private const MAX_SURNAME_EDITS = 2;

    /** Below this length, two edits is a different surname rather than a slip. */
    private const MIN_SURNAME_LENGTH_FOR_TYPO = 6;

    /**
     * @param list<array{family_id:int,label:string,address:string,postcode:string,members:int,surnames:list<string>}> $families
     * @return list<array{
     *   a:array<string,mixed>, b:array<string,mixed>,
     *   confidence:string, agrees:list<string>, differs:list<string>
     * }>
     */
    public function find(array $families): array
    {
        $byPostcode = [];
        foreach ($families as $family) {
            $postcode = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) $family['postcode']));
            if ($postcode === '') {
                continue;   // nothing to anchor on
            }
            $byPostcode[$postcode][] = $family;
        }

        $pairs = [];
        foreach ($byPostcode as $group) {
            $count = count($group);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $pair = $this->assess($group[$i], $group[$j]);
                    if ($pair !== null) {
                        $pairs[] = $pair;
                    }
                }
            }
        }

        $order = [self::CERTAIN => 0, self::LIKELY => 1, self::POSSIBLE => 2];
        usort($pairs, static fn (array $x, array $y): int => $order[$x['confidence']] <=> $order[$y['confidence']]);

        return $pairs;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>|null
     */
    private function assess(array $a, array $b): ?array
    {
        $surnamesA = $this->surnames($a);
        $surnamesB = $this->surnames($b);
        if ($surnamesA === [] || $surnamesB === []) {
            return null;
        }

        $addressA = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $a['address']));
        $addressB = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $b['address']));
        // A different unit is a different home, however alike the rest reads.
        // "2895 Bathurst St #405" and "2895 Bathurst St #106" are one character
        // apart by every string measure and are two apartments.
        $unitA = $this->unit((string) $a['address']);
        $unitB = $this->unit((string) $b['address']);
        $differentDwelling = $unitA !== '' && $unitB !== '' && $unitA !== $unitB;

        $sameAddress = !$differentDwelling && $addressA !== '' && $addressA === $addressB;
        $nearAddress = !$differentDwelling && !$sameAddress && $addressA !== '' && $addressB !== ''
            && $this->within($addressA, $addressB, self::MAX_ADDRESS_EDITS, 1);

        $shared = array_values(array_intersect($surnamesA, $surnamesB));
        $agrees = ['the same postcode'];
        $differs = [];

        if ($shared !== []) {
            $agrees[] = 'the surname ' . $shared[0];
        } elseif ($sameAddress && $this->surnameTypo($surnamesA, $surnamesB)) {
            // The Cantimbuhan shape: one name typed two ways at one address.
            $agrees[] = 'surnames a typo apart';
        } else {
            return null;
        }

        if ($sameAddress) {
            $agrees[] = 'the same address';
            $confidence = $shared !== [] ? self::CERTAIN : self::LIKELY;
        } elseif ($nearAddress) {
            $confidence = self::LIKELY;
            // Named rather than glossed over: merging keeps one of the two.
            $differs[] = 'their addresses differ slightly — "' . trim((string) $a['address'])
                . '" and "' . trim((string) $b['address']) . '"';
        } elseif ($differentDwelling) {
            $confidence = self::POSSIBLE;
            $differs[] = 'they are in different units — ' . $unitA . ' and ' . $unitB
                . ' — so this is two homes in one building, however alike the addresses read';
        } else {
            // A postcode can cover a whole building. Two unrelated families of
            // one surname in one tower is not a merge, it is a coincidence.
            $confidence = self::POSSIBLE;
            $differs[] = 'their addresses do not match, so this may be two families in one building';
        }

        return ['a' => $a, 'b' => $b, 'confidence' => $confidence, 'agrees' => $agrees, 'differs' => $differs];
    }

    /**
     * The unit or apartment an address names, if it names one.
     *
     * Written half a dozen ways — "#602", "Unit 602", "Apt 602", "602-5740" —
     * so all of them are read. An address with no unit returns nothing, which
     * is not the same as two addresses agreeing on one.
     */
    private function unit(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }
        if (preg_match('/(?:#|\bunit\b|\bapt\.?\b|\bapartment\b|\bsuite\b|\bste\.?\b)\s*([a-z0-9-]+)/i', $address, $m) === 1) {
            return strtolower(trim($m[1]));
        }
        // "602-5740 Yonge St" — the unit leads, separated by a dash.
        if (preg_match('/^\s*([a-z0-9]+)\s*-\s*\d/i', $address, $m) === 1) {
            return strtolower(trim($m[1]));
        }

        return '';
    }

    /**
     * Surnames held by this family's members.
     *
     * Taken from the people rather than from the household name, which lags behind edits
     * and is not what anyone is actually called.
     *
     * @param array<string,mixed> $family
     * @return list<string>
     */
    private function surnames(array $family): array
    {
        $out = [];
        foreach ((array) ($family['surnames'] ?? []) as $surname) {
            $key = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $surname));
            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function surnameTypo(array $a, array $b): bool
    {
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($this->within($x, $y, self::MAX_SURNAME_EDITS, self::MIN_SURNAME_LENGTH_FOR_TYPO)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function within(string $x, string $y, int $maxEdits, int $minLength): bool
    {
        $distance = levenshtein($x, $y);

        return $distance > 0 && $distance <= $maxEdits && max(strlen($x), strlen($y)) >= $minLength;
    }
}
