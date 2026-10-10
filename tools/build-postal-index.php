<?php

declare(strict_types=1);

/**
 * Build config/ca-postal-areas.json from the GeoNames postal dump.
 *
 *   php tools/build-postal-index.php            # download and rebuild
 *   php tools/build-postal-index.php --file=CA.txt
 *
 * Why this data. A Canadian postal code's first three characters — the forward
 * sortation area — identify a neighbourhood, and GeoNames publishes all 1,657
 * of them with a place name, a province and a centroid, under CC BY 4.0. That
 * is small enough to keep in the repository and answers offline what we were
 * otherwise asking a geocoder: which province, and which city.
 *
 * It answers one thing better than a geocoder does. Toronto amalgamated its
 * boroughs in 1998, so Nominatim and Photon both say "Toronto" for an address
 * in Scarborough. GeoNames keeps the borough in admin2, so this file gives
 * "Scarborough" — which is what the roster says and what the campuses are
 * named after.
 *
 * The full six-character dataset exists (CA_full.csv.zip) but is 6.4MB
 * compressed and far larger unpacked. Forward sortation areas are the right
 * trade: a fraction of the size, and enough to name the place.
 *
 * Rerun it when the roster spreads somewhere the file does not cover well.
 */

const SOURCE = 'https://download.geonames.org/export/zip/CA.zip';
const OUTPUT = __DIR__ . '/../config/ca-postal-areas.json';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$path = is_string($opts['file'] ?? null) ? $opts['file'] : null;
$temp = null;

if ($path === null) {
    echo 'Downloading ' . SOURCE . " …\n";
    $zipBytes = @file_get_contents(SOURCE, false, stream_context_create(['http' => [
        'header' => "User-Agent: ChristlikenessChurchPortal/1.0\r\n",
        'timeout' => 60,
    ]]));
    if (!is_string($zipBytes) || $zipBytes === '') {
        fwrite(STDERR, "Could not download the postal dump.\n");
        exit(1);
    }
    $temp = sys_get_temp_dir() . '/geonames-ca-' . getmypid();
    @mkdir($temp);
    file_put_contents($temp . '/CA.zip', $zipBytes);
    $zip = new ZipArchive();
    if ($zip->open($temp . '/CA.zip') !== true) {
        fwrite(STDERR, "Downloaded file is not a zip archive.\n");
        exit(1);
    }
    $zip->extractTo($temp);
    $zip->close();
    $path = $temp . '/CA.txt';
}

if (!is_file($path)) {
    fwrite(STDERR, "No CA.txt at {$path}.\n");
    exit(1);
}

$areas = [];
$skipped = 0;
foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    // country, postal, place, admin1, admin1code, admin2, admin2code,
    // admin3, admin3code, lat, lng, accuracy
    $f = explode("\t", $line);
    if (count($f) < 11) {
        $skipped++;
        continue;
    }
    $fsa = strtoupper(trim($f[1]));
    if (!preg_match('/^[A-Z]\d[A-Z]$/', $fsa)) {
        $skipped++;
        continue;
    }

    $place = trim($f[2]);
    $borough = trim($f[5]);

    // admin2 is the municipality proper — "Scarborough" where place reads
    // "Scarborough (Woburn / Cedarbrae)". Prefer it. Otherwise take the part
    // of place before the parenthesis, but only when there is no parenthesis
    // at all: "Eastern Alberta (St. Paul)" names a region, not a city, and
    // writing that into somebody's city field would be wrong.
    if ($borough !== '') {
        $city = $borough;
        $exact = true;
    } elseif (!str_contains($place, '(')) {
        $city = $place;
        $exact = true;
    } else {
        $city = trim(substr($place, 0, (int) strpos($place, '(')));
        $exact = false;
    }

    $areas[$fsa] = [
        'city' => $city,
        'province' => strtoupper(trim($f[4])),
        'lat' => round((float) $f[9], 4),
        'lng' => round((float) $f[10], 4),
        // Whether this name is safe to write into a record unprompted.
        'exact' => $exact,
    ];
}

ksort($areas);

$payload = [
    '_source' => SOURCE,
    '_license' => 'GeoNames postal code data, Creative Commons Attribution 4.0. '
        . 'Credit: https://www.geonames.org/',
    '_note' => 'Forward sortation areas (first three characters of a Canadian '
        . 'postal code). "exact" marks a name that identifies a municipality '
        . 'or borough rather than a rural region; only those are written into '
        . 'a record without review. Rebuild with tools/build-postal-index.php.',
    '_built_from_rows' => count($areas),
    'areas' => $areas,
];

file_put_contents(OUTPUT, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n");

if ($temp !== null) {
    @unlink($temp . '/CA.zip');
    @unlink($temp . '/CA.txt');
    @unlink($temp . '/readme.txt');
    @rmdir($temp);
}

$exactCount = count(array_filter($areas, static fn (array $a): bool => $a['exact']));
printf(
    "Wrote %s\n  %d forward sortation areas (%d name a municipality exactly)\n  %d line(s) skipped\n  %s\n",
    OUTPUT,
    count($areas),
    $exactCount,
    $skipped,
    number_format(filesize(OUTPUT) / 1024, 1) . ' KB',
);
