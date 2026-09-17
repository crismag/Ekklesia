<?php

declare(strict_types=1);

/**
 * Address parsing, geocoder pacing, and the sweep's refusal to overwrite.
 *
 * The workbook's address column is free text. The parser it replaced accepted
 * exactly one shape — "street, city, PP postal" — and dumped everything else
 * into the street line with the city and postal code empty. Against the 613
 * staged addresses that recovered 302. Reading from the end instead, anchored
 * on the postal code, recovers 543, and finds a city for 609.
 *
 * The sweep's tests are mostly about what it refuses to do. Toronto absorbed
 * North York, Scarborough, Etobicoke and East York in 1998, so every geocoder
 * answers "Toronto" for all of them — and two of this church's campuses are
 * named after those boroughs.
 */

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Contracts\AddressRepository;
use App\Services\AddressNormalizer;
use App\Services\PostalAreaIndex;
use App\Services\AddressSweepService;
use App\Services\Geocoding\GeocodeHit;
use App\Services\Geocoding\GeocoderThrottled;
use App\Services\Geocoding\GeocodingProvider;
use App\Services\Geocoding\NominatimProvider;
use App\Services\Geocoding\HttpGet;
use App\Services\Geocoding\ProviderPool;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
}

$cities = json_decode((string) file_get_contents(__DIR__ . '/../../config/address-cities.json'), true)['cities'];
$n = new AddressNormalizer($cities);

// ---- shapes taken from the masked structure of the real staged column ------
// The addresses are invented; only the shapes are real.

$a = $n->parse('123 Fake St, Testville, ON M1M 1M1');
check('the shape the old parser handled still works',
    $a['address1'] === '123 Fake St' && $a['city'] === 'Testville' && $a['zip'] === 'M1M 1M1' && $a['state'] === 'ON');
check('and is marked confident', $a['confident'] === true);

// "9 A A A A A A9A 9A9" — 32 rows had no commas at all.
$b = $n->parse('123 Fake St Scarborough ON M1M 1M1');
check('a comma-free line splits on the street type',
    $b['address1'] === '123 Fake St' && $b['city'] === 'Scarborough', json_encode($b));

// "9- 9 A A A A, A9A 9A9" — unit prefix, and no province token.
$c = $n->parse('12- 34 Fake Ave North York, M2M 2M2');
check('a unit prefix stays with the street and the city is still found',
    $c['city'] === 'North York' && $c['zip'] === 'M2M 2M2', json_encode($c));
check('a missing province falls back to the default', $c['state'] === 'ON');
check('and the fallback is recorded as an issue', in_array('no-province', $c['issues'], true));

// Province spelled out — the old regex required exactly two letters.
$d = $n->parse('99 Sample Road, Pickering, Ontario L1V 1A1');
check('a spelled-out province is understood', $d['state'] === 'ON' && $d['city'] === 'Pickering');

// "A 9, 9 A A A, A A, A A9A 9A9" — leading unit, four parts.
$e = $n->parse('Unit 9, 55 Example Blvd, Etobicoke, ON M9M 9M9');
check('a leading unit does not become the city',
    $e['city'] === 'Etobicoke' && str_contains($e['address1'], '55 Example Blvd'), json_encode($e));

// Postal code written without its space.
$f = $n->parse('7 Test Cres, Ajax ON L1S2B3');
check('a postal code with no space is still read', $f['zip'] === 'L1S 2B3', json_encode($f));

// "9 A A, A" — 16 rows had no postal code at all.
$g = $n->parse('45 Nowhere Dr, Whitby');
check('a missing postal code does not lose the city', $g['city'] === 'Whitby');
check('and is reported rather than guessed', in_array('no-postal-code', $g['issues'], true));
check('a row missing a postal code is not called confident', $g['confident'] === false);

// A unit number trailing the street type is not a city. Treating it as one
// wrote "#210" into a real member's city field before this was caught.
foreach (['123 Main St #210', '45 Example Rd Unit 12', '9 Sample Ave Apt 3', '5 Real St 210'] as $unitLine) {
    $u = $n->parse($unitLine);
    check('a trailing unit is not mistaken for a city: ' . $unitLine,
        $u['city'] === '' && str_contains($u['address1'], trim(substr($unitLine, strrpos($unitLine, ' ')))),
        json_encode($u));
}
$uc = $n->parse('123 Main St, #210');
check('nor when a comma puts it in its own part', $uc['city'] === '', json_encode($uc));

check('an empty cell yields nothing and says so',
    $n->parse('')['issues'] === ['empty']);

// A street named after a province must not lose its name to the province rule.
$h = $n->parse('88 Ontario St, Toronto, ON M5A 1A1');
check('a province word inside a street name is left alone',
    $h['address1'] === '88 Ontario St' && $h['state'] === 'ON', json_encode($h));

// Casing is settled from the known list, not invented.
$i = $n->parse('12 Fake St, north york, ON M3M 3M3');
check('a known city is spelled the way the list spells it', $i['city'] === 'North York');

// A letter sequence that looks postal but uses letters Canada Post never issues.
$j = $n->parse('5 Fake St, Barrie, ON D1D 1D1');
check('an impossible postal code is not accepted as one',
    $j['zip'] === '' && in_array('no-postal-code', $j['issues'], true), json_encode($j));

// A country spelled out at the end used to become the city.
//
// "96-275 Manse Rd, Scarborough, ON M1E 4X8, Canada" came back with a city of
// "Canada" and "Scarborough, ON" still inside the street line. Removing the
// postal code left "…, Scarborough, ON, Canada"; the province check looked only
// at the very tail, found "Canada" rather than a province, gave up, and the
// split then took the last comma-part as the city. Every address written with
// its country went the same way, and some reached the database.
$withCountry = $n->parse('96-275 Manse Rd, Scarborough, ON M1E 4X8, Canada');
check('a trailing country is not the city', $withCountry['city'] === 'Scarborough', json_encode($withCountry));
check('and the province does not end up in the street',
    $withCountry['address1'] === '96-275 Manse Rd', json_encode($withCountry));
check('the province is still read', $withCountry['state'] === 'ON');
check('and the country is recorded', $withCountry['country'] === 'CA');
check('and the postal code survives', $withCountry['zip'] === 'M1E 4X8');

// The same line in the shapes people actually write.
foreach ([
    '12 Fake St, Toronto, ON M5A 1A1, Canada',
    '12 Fake St, Toronto, ON, Canada',
    '12 Fake St, Toronto, Ontario, Canada',
    '12 Fake St, Toronto ON, Canada',
    '12 Fake St, Toronto, ON M5A 1A1, CA',
] as $variant) {
    $v = $n->parse($variant);
    check('city survives the country in: ' . $variant, $v['city'] === 'Toronto', json_encode($v));
}

// A country with no province beside it must still not become the city.
$noProv = $n->parse('7 Test Cres, Ajax, Canada');
check('a country alone is still not a city', $noProv['city'] === 'Ajax', json_encode($noProv));

// Out of country. The parser is Canadian — it knows Canadian postal codes and
// Canadian provinces, not ZIP codes or state abbreviations — so it cannot pick
// Seattle out of "Seattle WA 98101", and it does not pretend to. What it must
// do is refuse to call the country a city, and report the address as one a
// person has to finish.
$us = $n->parse('500 Fifth Ave, Seattle, WA 98101, USA');
check('a United States address records its country', $us['country'] === 'US', json_encode($us));
check('and never takes USA as the city', $us['city'] !== 'USA' && $us['city'] !== 'US', json_encode($us));
check('and says the city is missing rather than guessing',
    in_array('no-city', $us['issues'], true), json_encode($us['issues']));

// The words must only be stripped from the end. A street named after a place
// keeps its name.
$street = $n->parse('88 Ontario St, Toronto, ON M5A 1A1');
check('a province word inside a street name is untouched',
    $street['address1'] === '88 Ontario St' && $street['city'] === 'Toronto', json_encode($street));
$canadaSt = $n->parse('12 Canada Way, Burnaby, BC V5G 1A1');
check('and so is a street named after a country',
    str_contains($canadaSt['address1'], 'Canada Way'), json_encode($canadaSt));

// An address that is nothing but a country tells us nothing, and must not
// pretend the country is a street.
$only = $n->parse('Canada');
check('a line with only a country yields no city', $only['city'] === '', json_encode($only));

// ---- what a postal code establishes on its own -----------------------------

echo "\nPostal area index\n";

$index = PostalAreaIndex::fromFile(__DIR__ . '/../../config/ca-postal-areas.json');
check('the shipped index covers the country', $index->count() > 1500, (string) $index->count());

// The opening letter allocates the province. This is how Canada Post assigned
// the alphabet, so it holds even for an area the file has never heard of.
check('M is Ontario', $index->province('M1G 2K3') === 'ON');
check('V is British Columbia', $index->province('V6B 1A1') === 'BC');
check('T is Alberta', $index->province('T2P 1J9') === 'AB');
check('a code from no province at all yields nothing, not a guess',
    $index->province('Z9Z 9Z9') === null);
check('a lowercase code with no space is still read',
    $index->province('m1g2k3') === 'ON');

// The reason this file is here rather than a geocoder: it keeps the boroughs.
check('M1G is Scarborough, not Toronto', $index->city('M1G 2K3') === 'Scarborough');
check('M2N is Willowdale', $index->city('M2N 5X5') === 'Willowdale');
check('M9W is Etobicoke', $index->city('M9W 1A1') === 'Etobicoke');
check('and downtown really is Toronto', $index->city('M5A 1A1') === 'Toronto');

// A rural area is recorded under a regional label, which is true but is not
// anybody's city, so it is not offered for writing into a record.
check('a regional label is not offered as a city', $index->city('T0A 1A1') === null);

check('a centroid comes back for a known area', $index->centroid('M1G 2K3') !== null);
check('and not for an unknown one', $index->centroid('Z9Z 9Z9') === null);

$near = $index->distanceFromCentroidKm('M1G 2K3', 43.7712, -79.2144);
check('a point on the centroid is no distance from it', $near !== null && $near < 1.0);
$far = $index->distanceFromCentroidKm('M1G 2K3', 45.4215, -75.6972);
check('and Ottawa is a long way from Scarborough', $far !== null && $far > 300);

$withIndex = new AddressNormalizer($cities, $index);

$pc = $withIndex->parse('45 Nowhere Dr M1G 2K3');
check('a bare postal code supplies the city', $pc['city'] === 'Scarborough', json_encode($pc));
check('and says where the city came from',
    in_array('city-from-postal-code', $pc['issues'], true));
check('and the province is established rather than defaulted',
    $pc['state'] === 'ON' && !in_array('no-province', $pc['issues'], true));

// Out of province, which is the point of keeping the whole country.
$bc = $withIndex->parse('9 Sample Rd V6B 1A1');
check('an address in another province is read correctly',
    $bc['state'] === 'BC' && $bc['city'] === 'Vancouver', json_encode($bc));

// What somebody wrote always wins over what the index would say.
$written = $withIndex->parse('5 Example Blvd, Toronto, ON M1G 2K3');
check('a city on the line is never replaced by the index',
    $written['city'] === 'Toronto', json_encode($written));

check('a line with no postal code still reports the province as unestablished',
    in_array('no-province', $withIndex->parse('45 Nowhere Dr, Whitby')['issues'], true));

// ---- provider pacing -------------------------------------------------------

echo "\nGeocoder pacing\n";

final class FakeClock
{
    public float $now = 1000.0;
}

/** A provider that answers instantly and records when it was asked. */
final class RecordingProvider implements GeocodingProvider
{
    /** @var list<float> */
    public array $calledAt = [];

    public function __construct(
        private readonly string $name,
        private readonly FakeClock $clock,
        private readonly ?GeocodeHit $answer,
        private readonly int $throttleTimes = 0,
        private int $throttled = 0,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function minIntervalMs(): int
    {
        return 1000;
    }

    public function lookup(array $address): ?GeocodeHit
    {
        $this->calledAt[] = $this->clock->now;
        if ($this->throttled < $this->throttleTimes) {
            $this->throttled++;
            throw new GeocoderThrottled('slow down', 30);
        }

        return $this->answer;
    }
}

$clock = new FakeClock();
$hit = new GeocodeHit(43.7, -79.4, 'Toronto', 'Ontario', 'M1M 1M1', 'ca', 'fake');
$slept = 0.0;
$sleep = static function (float $s) use ($clock, &$slept): void {
    $clock->now += $s;
    $slept += $s;
};
$now = static fn (): float => $clock->now;

$p1 = new RecordingProvider('one', $clock, $hit);
$p2 = new RecordingProvider('two', $clock, $hit);
$pool = new ProviderPool([$p1, $p2], $sleep, $now);

$addr = ['street' => '1 Fake St', 'city' => 'Toronto', 'state' => 'ON', 'zip' => 'M1M 1M1', 'country' => 'CA'];
for ($k = 0; $k < 4; $k++) {
    $pool->lookup($addr);
}
check('requests alternate between the providers',
    count($p1->calledAt) === 2 && count($p2->calledAt) === 2,
    count($p1->calledAt) . '/' . count($p2->calledAt));
$gaps = [];
foreach ([$p1, $p2] as $p) {
    for ($k = 1; $k < count($p->calledAt); $k++) {
        $gaps[] = $p->calledAt[$k] - $p->calledAt[$k - 1];
    }
}
check('no provider is called twice inside its own interval',
    $gaps !== [] && min($gaps) >= 1.0, json_encode($gaps));
check('the pool waited rather than racing', $slept > 0);

// A throttled provider stands down and the other carries the request.
$clock2 = new FakeClock();
$slept2 = 0.0;
$sleep2 = static function (float $s) use ($clock2, &$slept2): void {
    $clock2->now += $s;
    $slept2 += $s;
};
$t1 = new RecordingProvider('one', $clock2, $hit, throttleTimes: 1);
$t2 = new RecordingProvider('two', $clock2, $hit);
$pool2 = new ProviderPool([$t1, $t2], $sleep2, static fn (): float => $clock2->now);
$result = $pool2->lookup($addr);
check('a throttled provider does not fail the lookup', $result !== null);
check('the other provider answered it', count($t2->calledAt) === 1);
check('and the refusal is counted', $pool2->stats()['throttled'] === 1);

// Everyone refusing must stop the run, not spin on it.
$clock3 = new FakeClock();
$r1 = new RecordingProvider('one', $clock3, $hit, throttleTimes: 99);
$r2 = new RecordingProvider('two', $clock3, $hit, throttleTimes: 99);
$pool3 = new ProviderPool([$r1, $r2], static function (float $s) use ($clock3): void {
    $clock3->now += $s;
}, static fn (): float => $clock3->now);
$stopped = false;
try {
    $pool3->lookup($addr);
} catch (GeocoderThrottled) {
    $stopped = true;
}
check('a pool that is entirely refused gives up instead of looping', $stopped);

// Nominatim's 429 must be read as throttling, not as "no match".
final class CannedHttp implements HttpGet
{
    /** @param array{status:int,body:string,headers:array<string,string>} $response */
    public function __construct(private readonly array $response)
    {
    }

    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        return $this->response;
    }
}

$throttled = false;
try {
    (new NominatimProvider(new CannedHttp(['status' => 429, 'body' => '', 'headers' => ['retry-after' => '17']]), 'test'))
        ->lookup($addr);
} catch (GeocoderThrottled $e) {
    $throttled = $e->retryAfterSeconds === 17;
}
check('HTTP 429 is throttling, and Retry-After is honoured', $throttled);
check('an empty result set is a miss, not an error',
    (new NominatimProvider(new CannedHttp(['status' => 200, 'body' => '[]', 'headers' => []]), 'test'))->lookup($addr) === null);

// ---- the sweep's refusals --------------------------------------------------

echo "\nSweep safeguards\n";

final class FakeAddressRepo implements AddressRepository
{
    /** @param list<array<string,mixed>> $families */
    public function __construct(public array $families = [])
    {
    }

    /** @var array<int,array<string,string>> */
    public array $fieldWrites = [];

    /** @var array<int,array{float,float}> */
    public array $coordWrites = [];

    public function listFamilyAddresses(bool $onlyMissingCoordinates, ?int $limit = null, int $offset = 0): array
    {
        return $this->families;
    }

    public function listPersonAddresses(?int $limit = null): array
    {
        return [];
    }

    public function saveFamilyCoordinates(int $familyId, float $lat, float $lng): void
    {
        $this->coordWrites[$familyId] = [$lat, $lng];
    }

    public function saveFamilyAddressFields(int $familyId, array $fields): void
    {
        $this->fieldWrites[$familyId] = $fields;
    }

    public function savePersonAddressFields(int $personId, array $fields): void
    {
    }

    public function personLabel(int $personId): string
    {
        return 'Person ' . $personId;
    }

    public function familyLabel(int $familyId): string
    {
        return 'Family ' . $familyId;
    }
}

$family = static fn (array $over = []): array => $over + [
    'family_id' => 1, 'street' => '1 Fake St', 'address2' => '',
    'city' => 'North York', 'state' => 'ON', 'zip' => 'M1M 1M1',
    'country' => 'CA', 'lat' => null, 'lng' => null,
];

$torontoHit = new GeocodeHit(43.7, -79.4, 'Toronto', 'Ontario', 'M1M 1M1', 'ca', 'fake');
$makePool = static function (?GeocodeHit $answer) use ($clock): ProviderPool {
    return new ProviderPool(
        [new RecordingProvider('only', $clock, $answer)],
        static function (float $s): void {
        },
        static fn (): float => 0.0,
    );
};

$repo = new FakeAddressRepo([$family()]);
$sweep = new AddressSweepService($repo, $makePool($torontoHit), $n);
$out = $sweep->sweep(['apply' => true]);
check('a borough is never replaced by Toronto',
    ($repo->fieldWrites[1]['city'] ?? 'North York') === 'North York', json_encode($repo->fieldWrites));
check('and the protection is counted', $out['stats']['boroughProtected'] === 1);
check('coordinates are still written for that family', isset($repo->coordWrites[1]));

// A postal code from a different area means a different place.
$repo2 = new FakeAddressRepo([$family()]);
$elsewhere = new GeocodeHit(45.4, -75.7, 'Ottawa', 'Ontario', 'K1A 0B1', 'ca', 'fake');
$out2 = (new AddressSweepService($repo2, $makePool($elsewhere), $n))->sweep(['apply' => true]);
check('a hit in a different postal area is rejected', $repo2->coordWrites === []);
check('and reported as unresolved', count($out2['unresolved']) === 1);
check('with a reason that says why',
    str_contains($out2['unresolved'][0]['reason'] ?? '', 'different postal area'));

// Blanks are filled; existing values are not.
$repo3 = new FakeAddressRepo([$family(['city' => '', 'zip' => ''])]);
$goodHit = new GeocodeHit(43.7, -79.4, 'Pickering', 'Ontario', 'L1V 1A1', 'ca', 'fake');
(new AddressSweepService($repo3, $makePool($goodHit), $n))->sweep(['apply' => true]);
check('a blank city is filled from the geocoder',
    ($repo3->fieldWrites[1]['city'] ?? '') === 'Pickering', json_encode($repo3->fieldWrites));

$repo4 = new FakeAddressRepo([$family(['city' => 'Whitby'])]);
(new AddressSweepService($repo4, $makePool($goodHit), $n))->sweep(['apply' => true]);
check('a city already on file is never overwritten',
    !isset($repo4->fieldWrites[1]['city']), json_encode($repo4->fieldWrites));

// A dry run must not write.
$repo5 = new FakeAddressRepo([$family()]);
$dry = (new AddressSweepService($repo5, $makePool($torontoHit), $n))->sweep(['apply' => false]);
check('a dry run writes no coordinates', $repo5->coordWrites === []);
check('a dry run writes no fields', $repo5->fieldWrites === []);
check('but still reports what it would have done', $dry['stats']['coordinatesWritten'] === 1);

// No match at all is reported, with the person identified for follow-up.
$repo6 = new FakeAddressRepo([$family(['zip' => '', 'city' => ''])]);
$none = (new AddressSweepService($repo6, $makePool(null), $n))->sweep(['apply' => true]);
check('an address nothing can match is reported', count($none['unresolved']) === 1);
check('and names the record so it can be fixed',
    ($none['unresolved'][0]['label'] ?? '') === 'Family 1');

// A geocoder that returns no postal code slips past the string comparison.
// The postal area's centroid catches the bad match instead.
$repo7 = new FakeAddressRepo([$family()]);
$noPostalHit = new GeocodeHit(45.4215, -75.6972, 'Ottawa', 'Ontario', '', 'ca', 'fake');
$out7 = (new AddressSweepService($repo7, $makePool($noPostalHit), $n, $index))->sweep(['apply' => true]);
check('a hit far from the postal area is rejected even with no postcode to compare',
    $repo7->coordWrites === [], json_encode($repo7->coordWrites));
check('and the distance is named in the reason',
    str_contains($out7['unresolved'][0]['reason'] ?? '', 'from its postal area'),
    json_encode($out7['unresolved']));

// A hit inside the postal area is accepted.
$repo8 = new FakeAddressRepo([$family()]);
$closeHit = new GeocodeHit(43.7712, -79.2144, 'Toronto', 'Ontario', '', 'ca', 'fake');
(new AddressSweepService($repo8, $makePool($closeHit), $n, $index))->sweep(['apply' => true]);
check('a hit inside the postal area is accepted', isset($repo8->coordWrites[1]));

// The whole point: a row the postal code can answer costs no request.
$repo9 = new FakeAddressRepo([$family(['city' => '', 'lat' => 43.7, 'lng' => -79.2])]);
$countingPool = $makePool(null);
$out9 = (new AddressSweepService($repo9, $countingPool, $withIndex, $index))->sweep(['apply' => true]);
check('a blank city is filled from the postal code without a request',
    ($repo9->fieldWrites[1]['city'] ?? '') === 'Scarborough', json_encode($repo9->fieldWrites));
check('and no geocoder was asked', $countingPool->stats()['calls'] === 0);
check('and it is counted as resolved offline', $out9['stats']['resolvedOffline'] === 1);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
