<?php
/**
 * People Sign-Up — address lookup (standalone).
 * Forward-geocodes a typed address via OpenStreetMap Nominatim (free, no key) and
 * returns normalized components + coordinates, so the guest can verify/correct
 * their address. CSRF-gated to the signup session; lightly throttled to respect
 * Nominatim's usage policy.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

sg_session();
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sg_json(['ok' => false, 'error' => 'POST required'], 405);
}
if (!sg_csrf_ok($_POST['csrf'] ?? null)) {
    sg_json(['ok' => false, 'error' => 'Session expired — reload the page.'], 419);
}

// Throttle: at most one lookup per second per session.
$now = time();
if (isset($_SESSION['sg_geo_last']) && ($now - (int) $_SESSION['sg_geo_last']) < 1) {
    sg_json(['ok' => false, 'error' => 'Please wait a moment and try again.'], 429);
}
$_SESSION['sg_geo_last'] = $now;

$street = sg_str($_POST['address'] ?? '');
$unit   = sg_str($_POST['address2'] ?? '');
$city   = sg_str($_POST['city'] ?? '');
$state  = sg_str($_POST['state'] ?? '');
$zip    = sg_str($_POST['zip'] ?? '');
$country = sg_str($_POST['country'] ?? '') ?: (string) sg_cfg('defaults.country', 'Canada');

if ($street === '' && $zip === '' && $city === '') {
    sg_json(['ok' => false, 'error' => 'Enter at least a postal code or street address first.'], 422);
}

// Structured Nominatim query — more reliable than free text.
$params = array_filter([
    'street'     => trim($street),
    'city'       => $city,
    'state'      => $state,
    'postalcode' => $zip,
    'country'    => $country,
    'format'         => 'jsonv2',
    'addressdetails' => '1',
    'limit'          => '1',
], static fn ($v) => $v !== '');

$url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);

$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'header'  => "User-Agent: ChristlikenessChurchSignup/1.0 (csmagala@gmail.com)\r\nAccept: application/json\r\n",
    'timeout' => 6,
]]);

$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) {
    sg_json(['ok' => false, 'error' => 'Address service is unavailable right now. You can type your address manually.'], 502);
}
$data = json_decode($raw, true);
if (!is_array($data) || !$data) {
    sg_json(['ok' => false, 'error' => 'No match found. Please check the address or enter it manually.']);
}

$hit = $data[0];
$a   = $hit['address'] ?? [];
$cityVal = $a['city'] ?? ($a['town'] ?? ($a['village'] ?? ($a['municipality'] ?? ($a['hamlet'] ?? ''))));
$streetVal = trim(($a['house_number'] ?? '') . ' ' . ($a['road'] ?? ''));

sg_json(['ok' => true,
    'lat' => isset($hit['lat']) ? (float) $hit['lat'] : null,
    'lon' => isset($hit['lon']) ? (float) $hit['lon'] : null,
    'display_name' => (string) ($hit['display_name'] ?? ''),
    'address' => [
        'street'  => $streetVal,
        'city'    => (string) $cityVal,
        'state'   => (string) ($a['state'] ?? ''),
        'postcode' => (string) ($a['postcode'] ?? ''),
        'country' => (string) ($a['country'] ?? ''),
    ],
]);
