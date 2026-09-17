<?php

declare(strict_types=1);

/**
 * Fetch public holidays and cache them in config/holidays.json.
 *
 *   php tools/fetch-holidays.php                                  # list what is cached
 *   php tools/fetch-holidays.php --country=CA --region=CA-ON      # preview a set
 *   php tools/fetch-holidays.php --country=CA --region=CA-ON --write
 *   php tools/fetch-holidays.php --country=PH --write --label="Philippine holidays"
 *   php tools/fetch-holidays.php --country=US --write --years=6
 *   php tools/fetch-holidays.php --refresh                        # re-fetch every set
 *   php tools/fetch-holidays.php --remove=us
 *
 * Several sets can be cached at once — a congregation keeps more than one
 * country's holidays in view — and each becomes its own calendar layer that
 * viewers can switch off.
 *
 * Holidays are fetched rather than computed. Their rules look simple and are
 * not: an earlier attempt to derive Ontario's list from first principles
 * included Easter Monday and Remembrance Day, and neither is a statutory
 * holiday in Ontario — the first is for federal employees, the second is
 * observed in nine other provinces. Legislatures also change their minds;
 * National Day for Truth and Reconciliation did not exist before 2021.
 *
 * They are cached rather than fetched at render time. The calendar has to draw
 * with no network, on a page people open constantly, and a holiday list that is
 * a year old is still a correct holiday list.
 *
 * Source: Nager.Date (https://date.nager.at), a public holiday API requiring no
 * key. Re-run once a year, or whenever a holiday is added or moved.
 */

const ENDPOINT = 'https://date.nager.at/api/v3/PublicHolidays/%d/%s';
const OUTPUT = __DIR__ . '/../config/holidays.json';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$write = isset($opts['write']);
$years = max(1, min(10, (int) ($opts['years'] ?? 6)));

$config = is_file(OUTPUT) ? json_decode((string) file_get_contents(OUTPUT), true) : null;
$calendars = is_array($config['calendars'] ?? null) ? $config['calendars'] : [];

/** Fetch one country/region for a run of years. */
$fetch = static function (string $country, ?string $region, int $years): array {
    $context = stream_context_create(['http' => [
        'header' => "User-Agent: ChristlikenessChurchPortal/1.0\r\nAccept: application/json\r\n",
        'timeout' => 20,
    ]]);
    $firstYear = (int) date('Y');
    $out = [];
    $seen = [];
    for ($year = $firstYear; $year < $firstYear + $years; $year++) {
        $raw = @file_get_contents(sprintf(ENDPOINT, $year, $country), false, $context);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new RuntimeException("Could not fetch {$country} for {$year}.");
        }
        foreach ($decoded as $entry) {
            $counties = is_array($entry['counties'] ?? null) ? $entry['counties'] : [];
            $national = (bool) ($entry['global'] ?? false);
            // National holidays always count. A regional one counts only where
            // a region was asked for and observes it — otherwise a country-wide
            // set would inherit every province's local days.
            if (!$national && ($region === null || !in_array($region, $counties, true))) {
                continue;
            }
            $date = (string) $entry['date'];
            if (isset($seen[$date])) {
                continue;   // two regions can name one date differently
            }
            $seen[$date] = true;
            $out[] = [
                'date' => $date,
                'name' => (string) ($entry['localName'] ?? $entry['name']),
                'national' => $national,
            ];
        }
    }
    usort($out, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

    return $out;
};

$save = static function (array $config, array $calendars): void {
    $payload = [
        '_comment' => 'Holiday calendars shown on the church calendar. Fetched, not computed: '
            . 'the rules look simple and are not, and legislatures change them. Cached rather than '
            . 'fetched at render time so the calendar draws with no network. Each set is a layer '
            . 'viewers can switch off. Refresh with tools/fetch-holidays.php --refresh.',
        '_source' => 'https://date.nager.at',
        'calendars' => array_values($calendars),
    ];
    file_put_contents(OUTPUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
};

// --- remove ----------------------------------------------------------------

if (isset($opts['remove'])) {
    $id = strtolower((string) $opts['remove']);
    $before = count($calendars);
    $calendars = array_values(array_filter($calendars, static fn (array $c): bool => strtolower((string) $c['id']) !== $id));
    if (count($calendars) === $before) {
        fwrite(STDERR, "No cached calendar with id \"{$id}\".\n");
        exit(1);
    }
    $save($config ?? [], $calendars);
    printf("Removed \"%s\". %d calendar(s) remain.\n", $id, count($calendars));
    exit(0);
}

// --- refresh every cached set ----------------------------------------------

if (isset($opts['refresh'])) {
    if ($calendars === []) {
        fwrite(STDERR, "Nothing cached yet. Add one with --country=CA --region=CA-ON --write.\n");
        exit(1);
    }
    foreach ($calendars as $i => $calendar) {
        $dates = $fetch((string) $calendar['country'], $calendar['region'] ?? null, $years);
        $calendars[$i]['holidays'] = $dates;
        $calendars[$i]['fetched_on'] = date('Y-m-d');
        printf("  %-28s %d holiday(s) through %s\n", $calendar['label'], count($dates), end($dates)['date'] ?? '—');
    }
    if ($write) {
        $save($config ?? [], $calendars);
        echo "\nWritten.\n";
    } else {
        echo "\nNothing written. Add --write.\n";
    }
    exit(0);
}

// --- add or replace one set ------------------------------------------------

if (isset($opts['country'])) {
    $country = strtoupper((string) $opts['country']);
    $region = isset($opts['region']) ? strtoupper((string) $opts['region']) : null;
    $id = strtolower($region !== null ? str_replace('-', '-', $region) : $country);
    $label = (string) ($opts['label'] ?? ($region !== null ? "{$country} holidays ({$region})" : "{$country} holidays"));
    $color = (string) ($opts['color'] ?? '#8a4b12');

    printf("Fetching %s%s for %d year(s)\n\n", $country, $region !== null ? " / {$region}" : '', $years);
    $dates = $fetch($country, $region, $years);
    printf("%d holiday(s), through %s. This year:\n", count($dates), end($dates)['date'] ?? '—');
    foreach (array_filter($dates, static fn (array $h): bool => str_starts_with($h['date'], (string) date('Y'))) as $h) {
        printf("  %s  %-46s %s\n", $h['date'], $h['name'], $h['national'] ? 'national' : (string) $region);
    }

    if (!$write) {
        echo "\nNothing written. Add --write to cache this set.\n";
        exit(0);
    }

    $entry = [
        'id' => $id,
        'label' => $label,
        'country' => $country,
        'region' => $region,
        'color' => $color,
        'enabled' => true,
        'fetched_on' => date('Y-m-d'),
        'holidays' => $dates,
    ];
    $replaced = false;
    foreach ($calendars as $i => $calendar) {
        if (strtolower((string) $calendar['id']) === $id) {
            // Keep whether somebody had switched it off.
            $entry['enabled'] = (bool) ($calendar['enabled'] ?? true);
            $entry['label'] = (string) ($opts['label'] ?? $calendar['label']);
            $entry['color'] = (string) ($opts['color'] ?? $calendar['color']);
            $calendars[$i] = $entry;
            $replaced = true;
            break;
        }
    }
    if (!$replaced) {
        $calendars[] = $entry;
    }
    $save($config ?? [], $calendars);
    printf("\n%s \"%s\". %d calendar(s) cached.\n", $replaced ? 'Updated' : 'Added', $label, count($calendars));
    exit(0);
}

// --- list ------------------------------------------------------------------

if ($calendars === []) {
    echo "No holiday calendars cached yet.\n\n  php tools/fetch-holidays.php --country=CA --region=CA-ON --write\n";
    exit(0);
}
printf("%d holiday calendar(s) cached:\n\n", count($calendars));
foreach ($calendars as $calendar) {
    $dates = $calendar['holidays'] ?? [];
    printf(
        "  %-10s %-32s %-4s %3d holiday(s)  through %s  fetched %s\n",
        $calendar['id'],
        $calendar['label'],
        ($calendar['enabled'] ?? true) ? 'on' : 'off',
        count($dates),
        $dates === [] ? '—' : end($dates)['date'],
        $calendar['fetched_on'] ?? '—',
    );
}
