<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Pulls a street, city, province and postal code out of one free-text cell.
 *
 * The workbook's address column is written by people, not by a form. Of 613
 * staged addresses, the previous parser recovered 302: it accepted exactly one
 * shape — "street, city, PP postal" — and anything else fell through with the
 * whole line dumped into address1 and the city and postal code left empty.
 *
 * The shapes that failed were not exotic. Addresses with no commas at all
 * ("123 Main St Toronto ON M1M 1M1"), a unit written as a prefix ("Unit 5, …"
 * or "5-123 …"), a province spelled out rather than abbreviated, or simply no
 * province token between the city and the postal code.
 *
 * So this reads from the end instead of matching a template. The postal code
 * is the reliable anchor — 539 of those 613 lines contain one, and its format
 * is unmistakable — and the province is a closed vocabulary. Both are removed
 * from the line before the remainder is split into street and city, which
 * leaves a much easier problem.
 *
 * Nothing here reaches the network. Geocoding is for the residue this cannot
 * resolve, and is deliberately a separate, rate-limited step.
 */
final class AddressNormalizer
{
    /**
     * Canadian postal code, with or without the conventional space.
     *
     * Excludes D, F, I, O, Q and U, which Canada Post never uses, so that a
     * street name is not mistaken for a postal code.
     */
    private const POSTAL = '/\b([ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z])[ -]?(\d[ABCEGHJ-NPRSTV-Z]\d)\b/i';

    /**
     * Countries as people write them at the end of an address.
     *
     * Small on purpose. This exists to stop a country being mistaken for a
     * city, not to validate international addresses, and a name not listed here
     * is left alone rather than guessed at.
     */
    private const COUNTRIES = [
        'CANADA' => 'CA', 'CA' => 'CA', 'CAN' => 'CA',
        'UNITED STATES' => 'US', 'UNITED STATES OF AMERICA' => 'US',
        'USA' => 'US', 'US' => 'US', 'U S A' => 'US',
        'PHILIPPINES' => 'PH', 'PH' => 'PH',
        'UNITED KINGDOM' => 'GB', 'UK' => 'GB', 'GB' => 'GB',
    ];

    /** Province and territory names as people write them, to the postal code. */
    private const PROVINCES = [
        'ON' => 'ON', 'ONT' => 'ON', 'ONTARIO' => 'ON',
        'QC' => 'QC', 'PQ' => 'QC', 'QUE' => 'QC', 'QUEBEC' => 'QC', 'QUÉBEC' => 'QC',
        'BC' => 'BC', 'BRITISH COLUMBIA' => 'BC',
        'AB' => 'AB', 'ALTA' => 'AB', 'ALBERTA' => 'AB',
        'MB' => 'MB', 'MAN' => 'MB', 'MANITOBA' => 'MB',
        'SK' => 'SK', 'SASK' => 'SK', 'SASKATCHEWAN' => 'SK',
        'NS' => 'NS', 'NOVA SCOTIA' => 'NS',
        'NB' => 'NB', 'NEW BRUNSWICK' => 'NB',
        'NL' => 'NL', 'NFLD' => 'NL', 'NEWFOUNDLAND' => 'NL',
        'NEWFOUNDLAND AND LABRADOR' => 'NL',
        'PE' => 'PE', 'PEI' => 'PE', 'PRINCE EDWARD ISLAND' => 'PE',
        'YT' => 'YT', 'YUKON' => 'YT',
        'NT' => 'NT', 'NWT' => 'NT', 'NORTHWEST TERRITORIES' => 'NT',
        'NU' => 'NU', 'NUNAVUT' => 'NU',
    ];

    /**
     * Words that end a street name.
     *
     * These are what makes a comma-free line separable: in "123 Main St
     * Toronto", everything after the street type is the city. Without this the
     * line is an undifferentiated run of words.
     */
    private const STREET_TYPES = [
        'ST', 'STREET', 'AVE', 'AV', 'AVENUE', 'RD', 'ROAD', 'DR', 'DRIVE',
        'BLVD', 'BOULEVARD', 'CRES', 'CRESCENT', 'CRT', 'CT', 'COURT', 'WAY',
        'TRL', 'TRAIL', 'GATE', 'GATEWAY', 'TER', 'TERR', 'TERRACE', 'PL',
        'PLACE', 'PKWY', 'PARKWAY', 'CIR', 'CIRCLE', 'SQ', 'SQUARE', 'HWY',
        'HIGHWAY', 'LANE', 'LN', 'MEWS', 'GROVE', 'GRV', 'PATH', 'RIDGE',
        'ROW', 'CLOSE', 'GREEN', 'HEIGHTS', 'HTS', 'HILL', 'PARK', 'GARDENS',
        'GDNS', 'COMMON', 'LINE', 'CONCESSION', 'SIDEROAD', 'BYPASS', 'RUN',
    ];

    /** Compass words that trail a street name and belong to it, not the city. */
    private const DIRECTIONS = [
        'E', 'W', 'N', 'S', 'NE', 'NW', 'SE', 'SW',
        'EAST', 'WEST', 'NORTH', 'SOUTH',
        'NORTHEAST', 'NORTHWEST', 'SOUTHEAST', 'SOUTHWEST',
    ];

    /** @var array<string,string> uppercased city name => canonical spelling */
    private array $cityIndex;

    /**
     * @param list<string> $knownCities municipalities that may appear at the
     *        end of a comma-free line. Supplied rather than hardcoded so the
     *        list can grow from configuration as the roster spreads.
     * @param ?PostalAreaIndex $postal what the postal code itself can tell us.
     *        Optional: without it the parser still works, it just falls back to
     *        a default province instead of establishing one.
     */
    public function __construct(array $knownCities = [], private readonly ?PostalAreaIndex $postal = null)
    {
        $this->cityIndex = [];
        // The postal index carries every Canadian municipality it knows; the
        // curated list adds the local names and settles their spelling, so it
        // is applied second and wins.
        foreach ([$postal?->cityNames() ?? [], $knownCities] as $source) {
            foreach ($source as $city) {
                $city = trim($city);
                if ($city !== '') {
                    $this->cityIndex[$this->cityKey($city)] = $city;
                }
            }
        }
    }

    public static function fromFile(string $path, ?string $postalIndexPath = null): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $cities = is_array($decoded) ? ($decoded['cities'] ?? []) : [];

        return new self(
            is_array($cities) ? array_map(strval(...), $cities) : [],
            $postalIndexPath !== null && is_file($postalIndexPath)
                ? PostalAreaIndex::fromFile($postalIndexPath)
                : null,
        );
    }

    /**
     * @return array{
     *   address1:string, city:string, state:string, zip:string, country:string,
     *   confident:bool, issues:list<string>
     * }
     */
    public function parse(string $raw, string $defaultState = 'ON', string $defaultCountry = 'CA'): array
    {
        $out = [
            'address1' => '', 'city' => '', 'state' => '', 'zip' => '',
            'country' => $defaultCountry, 'confident' => false, 'issues' => [],
        ];

        $text = trim((string) preg_replace('/\s+/', ' ', $raw));
        $text = trim($text, " \t\n\r\0\x0B,;");
        if ($text === '') {
            $out['issues'][] = 'empty';

            return $out;
        }

        // The postal code first: it is the one unmistakable token, and taking
        // it out removes the part most likely to confuse a city match.
        if (preg_match(self::POSTAL, $text, $m, PREG_OFFSET_CAPTURE)) {
            $out['zip'] = strtoupper($m[1][0]) . ' ' . strtoupper($m[2][0]);
            $text = trim(substr_replace($text, ' ', $m[0][1], strlen($m[0][0])));
            $text = trim((string) preg_replace('/\s+/', ' ', $text), " ,;");
        } else {
            $out['issues'][] = 'no-postal-code';
        }

        // Take the country and province off the end before anything is read as
        // a city.
        //
        // "96-275 Manse Rd, Scarborough, ON M1E 4X8, Canada" used to come back
        // with a city of "Canada" and "Scarborough, ON" left inside the street.
        // Removing the postal code left "…, Scarborough, ON, Canada"; the
        // province check only inspected the very tail, found "Canada" there
        // rather than a province, gave up, and the split then took the last
        // comma-part as the city. Every address written with its country
        // spelled out went the same way.
        [$text, $country, $province] = $this->takeTail($text);
        if ($country !== null) {
            $out['country'] = $country;
        }

        if ($province !== null) {
            $out['state'] = $province;
        } elseif (($fromPostal = $this->postal?->province($out['zip'])) !== null) {
            // Not a fallback. The opening letter of a postal code identifies
            // the province outright, so this is as good as reading it off the
            // line — better, since nobody mistypes it.
            $out['state'] = $fromPostal;
        } else {
            $out['state'] = $defaultState;
            $out['issues'][] = 'no-province';
        }

        [$street, $city] = $this->splitStreetAndCity($text);
        $out['address1'] = $street;
        $out['city'] = $city;

        if ($city === '') {
            // The postal code names the neighbourhood, and names it with the
            // borough intact where a geocoder would flatten it to Toronto.
            $fromPostal = $this->postal?->city($out['zip']);
            if ($fromPostal !== null) {
                $out['city'] = $fromPostal;
                $out['issues'][] = 'city-from-postal-code';
            } else {
                $out['issues'][] = 'no-city';
            }
        }
        if ($street === '') {
            $out['issues'][] = 'no-street';
        } elseif (!preg_match('/\d/', $street)) {
            // A street with no number is not necessarily wrong, but it is the
            // shape a mis-split produces, so it is worth a human glance.
            $out['issues'][] = 'street-has-no-number';
        }

        $out['confident'] = $out['address1'] !== '' && $out['city'] !== '' && $out['zip'] !== '';

        return $out;
    }

    /**
     * Strip trailing country and province from the end of the line.
     *
     * They arrive in several shapes — as their own comma-separated parts
     * ("…, ON, Canada"), or trailing the last part ("… Toronto ON") — so both
     * are handled, repeatedly, until the tail is something that could be a
     * place. What is left is street and city and nothing else.
     *
     * @return array{0:string,1:?string,2:?string} text, country, province
     */
    private function takeTail(string $text): array
    {
        $parts = [];
        foreach (explode(',', $text) as $part) {
            $part = trim($part, " ,;");
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        $country = null;
        $province = null;

        // Whole parts first: "…, ON, Canada" is two of them.
        $consumed = true;
        while ($consumed && $parts !== []) {
            $consumed = false;
            $last = trim((string) end($parts), " .,;");
            $asCountry = self::COUNTRIES[strtoupper($last)] ?? null;
            if ($asCountry !== null) {
                $country ??= $asCountry;
                array_pop($parts);
                $consumed = true;
                continue;
            }
            $asProvince = self::PROVINCES[strtoupper($last)] ?? null;
            if ($asProvince !== null) {
                $province ??= $asProvince;
                array_pop($parts);
                $consumed = true;
            }
        }

        // Then whatever still trails the final part: "… Toronto ON".
        if ($parts !== []) {
            $lastIndex = count($parts) - 1;
            $tail = $parts[$lastIndex];
            [$tail, $tailCountry] = $this->takeTrailingWords($tail, self::COUNTRIES);
            if ($tailCountry !== null) {
                $country ??= $tailCountry;
            }
            [$tail, $tailProvince] = $this->takeTrailingWords($tail, self::PROVINCES);
            if ($tailProvince !== null) {
                $province ??= $tailProvince;
            }
            if (trim($tail) === '') {
                array_pop($parts);
            } else {
                $parts[$lastIndex] = $tail;
            }
        }

        return [implode(', ', $parts), $country, $province];
    }

    /**
     * Take a known one-to-three-word phrase off the end of a fragment.
     *
     * Only the end is considered: "Ontario" can sit inside a street name, and
     * removing it from the middle would corrupt the address being read.
     *
     * @param array<string,string> $vocabulary
     * @return array{0:string,1:?string}
     */
    private function takeTrailingWords(string $text, array $vocabulary): array
    {
        $trimmed = rtrim($text, " ,;.");
        foreach ([3, 2, 1] as $wordCount) {
            $words = preg_split('/\s+/', $trimmed) ?: [];
            if (count($words) <= $wordCount) {
                continue;
            }
            $tail = strtoupper(trim(implode(' ', array_slice($words, -$wordCount)), " ,.;"));
            if (isset($vocabulary[$tail])) {
                return [trim(implode(' ', array_slice($words, 0, -$wordCount)), " ,;"), $vocabulary[$tail]];
            }
        }

        return [$trimmed, null];
    }

    /**
     * Take a trailing province token off the line.
     *
     * Only the tail is considered. "Ontario" can legitimately appear inside a
     * street name ("Ontario St"), and stripping it from the middle of a line
     * would corrupt the address it was trying to read.
     *
     * @return array{0:string,1:?string}
     */
    private function takeProvince(string $text): array
    {
        $trimmed = rtrim($text, " ,;");
        foreach ([3, 2, 1] as $wordCount) {
            $words = preg_split('/\s+/', $trimmed) ?: [];
            if (count($words) <= $wordCount) {
                continue;
            }
            $tail = strtoupper(implode(' ', array_slice($words, -$wordCount)));
            $tail = trim($tail, " ,.;");
            if (isset(self::PROVINCES[$tail])) {
                $rest = implode(' ', array_slice($words, 0, -$wordCount));

                return [trim($rest, " ,;"), self::PROVINCES[$tail]];
            }
        }

        return [$trimmed, null];
    }

    /**
     * Separate the street from the city in what is left.
     *
     * @return array{0:string,1:string}
     */
    private function splitStreetAndCity(string $text): array
    {
        $text = trim($text, " ,;");
        if ($text === '') {
            return ['', ''];
        }

        $parts = [];
        foreach (explode(',', $text) as $part) {
            $part = trim($part, " ,;");
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        if (count($parts) >= 2) {
            // A trailing part that is a known city is the city. Otherwise the
            // last part is taken as the city anyway — with commas present, that
            // is what the writer meant by them. Unless it is a unit number,
            // which is part of the address, not a place.
            $city = array_pop($parts);
            if (!$this->looksLikeCity($city)) {
                return [$this->tidyStreet(implode(', ', $parts) . ' ' . $city), ''];
            }

            return [$this->tidyStreet(implode(', ', $parts)), $this->tidyCity($city)];
        }

        // No commas. Try a known municipality at the end of the line first,
        // because it is exact where the street-type rule is only a heuristic.
        $single = $parts[0] ?? '';
        $words = preg_split('/\s+/', $single) ?: [];
        for ($take = min(3, count($words) - 1); $take >= 1; $take--) {
            $candidate = implode(' ', array_slice($words, -$take));
            $known = $this->cityIndex[$this->cityKey($candidate)] ?? null;
            if ($known !== null) {
                return [$this->tidyStreet(implode(' ', array_slice($words, 0, -$take))), $known];
            }
        }

        // Fall back to the street type: whatever follows it, minus a trailing
        // compass direction, is the city.
        $lastType = -1;
        foreach ($words as $i => $word) {
            $bare = strtoupper(trim($word, " .,"));
            if (in_array($bare, self::STREET_TYPES, true)) {
                $lastType = $i;
            }
        }
        if ($lastType >= 0 && $lastType < count($words) - 1) {
            $cut = $lastType + 1;
            $next = strtoupper(trim($words[$cut] ?? '', ' .,'));
            if (in_array($next, self::DIRECTIONS, true)) {
                $cut++;
            }
            if ($cut < count($words)) {
                $tail = implode(' ', array_slice($words, $cut));
                // "123 Main St #210" ends in a unit, not a city. Treating it as
                // one wrote "#210" into a member's city field.
                if ($this->looksLikeCity($tail)) {
                    return [
                        $this->tidyStreet(implode(' ', array_slice($words, 0, $cut))),
                        $this->tidyCity($tail),
                    ];
                }
            }
        }

        return [$this->tidyStreet($single), ''];
    }

    /**
     * Could this fragment be the name of a place?
     *
     * Everything that trails a street name is not a city. Unit and apartment
     * numbers sit there too, and a fragment that is a number, or a number
     * behind a hash, names a door rather than a municipality.
     */
    private function looksLikeCity(string $fragment): bool
    {
        $fragment = trim($fragment, " ,.;");
        if ($fragment === '' || str_starts_with($fragment, '#')) {
            return false;
        }
        if (preg_match('/^(unit|apt\.?|apartment|suite|ste\.?|floor|fl\.?|rm\.?|room|bsmt|basement|lower|upper|main|ph)\b/i', $fragment)) {
            return false;
        }

        // A place name has a word in it. "210", "B", "12A" do not.
        return preg_match('/\p{L}{3}/u', $fragment) === 1;
    }

    private function tidyStreet(string $street): string
    {
        return trim((string) preg_replace('/\s+/', ' ', trim($street, " ,;.")));
    }

    private function tidyCity(string $city): string
    {
        $city = trim((string) preg_replace('/\s+/', ' ', trim($city, " ,;.")));
        if ($city === '') {
            return '';
        }
        $known = $this->cityIndex[$this->cityKey($city)] ?? null;
        if ($known !== null) {
            return $known;
        }

        // Only fix casing that is clearly wrong. A city the list does not know
        // is left as written rather than guessed at.
        if ($city === strtolower($city) || $city === strtoupper($city)) {
            return (string) preg_replace_callback(
                '/\b[\p{L}]+/u',
                static fn (array $m): string => mb_convert_case($m[0], MB_CASE_TITLE, 'UTF-8'),
                $city,
            );
        }

        return $city;
    }

    private function cityKey(string $city): string
    {
        $key = strtoupper(trim($city));

        return (string) preg_replace('/[^A-Z0-9]+/', '', $key);
    }
}
