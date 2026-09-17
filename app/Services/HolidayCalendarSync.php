<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Adds, refreshes and removes the cached holiday calendars.
 *
 * The reading side is HolidayCalendars; this is the writing side, shared by the
 * admin screen and the command line so there is one implementation of what a
 * refresh means.
 *
 * Syncing is on demand. A church needs its holidays topped up about once a
 * year, and a scheduled job that runs unattended for something that rare is
 * more machinery than the problem deserves — it would also fail quietly, which
 * is the worst way for a cache to go stale. The screen shows when each set was
 * last fetched and how far it reaches, and says so when one is running out.
 *
 * A whole year is always fetched, never a window. Holidays are known years
 * ahead, and half a year of them is a worse answer than none.
 */
final class HolidayCalendarSync
{
    private const ENDPOINT = 'https://date.nager.at/api/v3/PublicHolidays/%d/%s';

    /** Years fetched by default: enough that nobody is surprised, few enough to stay quick. */
    public const DEFAULT_YEARS = 6;

    /** @var callable(string $country, int $year): array<int,array<string,mixed>> */
    private $fetchYear;

    /**
     * @param ?callable(string,int):array $fetchYear supplied by tests so the
     *        rules can be exercised without the network
     */
    public function __construct(
        private readonly string $path,
        ?callable $fetchYear = null,
    ) {
        $this->fetchYear = $fetchYear ?? function (string $country, int $year): array {
            $context = stream_context_create(['http' => [
                'header' => "User-Agent: ChristlikenessChurchPortal/1.0\r\nAccept: application/json\r\n",
                'timeout' => 15,
            ]]);
            $raw = @file_get_contents(sprintf(self::ENDPOINT, $year, $country), false, $context);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                throw new RuntimeException(
                    'Could not reach the holiday service for ' . $country . ' ' . $year . '. '
                    . 'The cached holidays are unchanged.'
                );
            }

            return $decoded;
        };
    }

    public function calendars(): HolidayCalendars
    {
        return HolidayCalendars::fromFile($this->path);
    }

    /**
     * Add a country, or replace it if it is already cached.
     *
     * @return array{id:string,label:string,count:int,through:?string}
     */
    public function add(
        string $country,
        ?string $region,
        string $label = '',
        string $color = '#8a4b12',
        int $years = self::DEFAULT_YEARS,
    ): array {
        $country = strtoupper(trim($country));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException('A country is a two-letter code, like CA or PH.');
        }
        $region = $region !== null && trim($region) !== '' ? strtoupper(trim($region)) : null;
        if ($region !== null && preg_match('/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $region) !== 1) {
            throw new InvalidArgumentException('A region looks like CA-ON. Leave it blank for the whole country.');
        }

        $id = strtolower($region ?? $country);
        $holidays = $this->fetch($country, $region, $years);
        if ($holidays === []) {
            throw new RuntimeException(
                'The holiday service knows no holidays for ' . $country . ($region !== null ? ' / ' . $region : '')
                . '. Check the codes before adding it.'
            );
        }

        $config = $this->read();
        $entry = [
            'id' => $id,
            'label' => trim($label) !== '' ? trim($label) : ($region !== null ? $country . ' holidays (' . $region . ')' : $country . ' holidays'),
            'country' => $country,
            'region' => $region,
            'color' => $color,
            'enabled' => true,
            'fetched_on' => date('Y-m-d'),
            'holidays' => $holidays,
        ];

        foreach ($config['calendars'] as $i => $existing) {
            if ($existing['id'] === $id) {
                // Whoever switched it off, or renamed it, meant it.
                $entry['enabled'] = (bool) ($existing['enabled'] ?? true);
                $entry['label'] = trim($label) !== '' ? trim($label) : (string) $existing['label'];
                $entry['color'] = $color !== '' ? $color : (string) $existing['color'];
                $config['calendars'][$i] = $entry;
                $this->write($config);

                return $this->summarise($entry);
            }
        }

        $config['calendars'][] = $entry;
        $this->write($config);

        return $this->summarise($entry);
    }

    /**
     * Re-fetch one cached calendar.
     *
     * @return array{id:string,label:string,count:int,through:?string}
     */
    public function refresh(string $id, int $years = self::DEFAULT_YEARS): array
    {
        $config = $this->read();
        foreach ($config['calendars'] as $i => $calendar) {
            if (strtolower((string) $calendar['id']) !== strtolower($id)) {
                continue;
            }
            $holidays = $this->fetch(
                (string) $calendar['country'],
                $calendar['region'] ?? null,
                $years,
            );
            if ($holidays === []) {
                // Keeping the old list beats replacing it with nothing.
                throw new RuntimeException('The holiday service returned nothing for ' . $calendar['label']
                    . '. The cached holidays are unchanged.');
            }
            $config['calendars'][$i]['holidays'] = $holidays;
            $config['calendars'][$i]['fetched_on'] = date('Y-m-d');
            $this->write($config);

            return $this->summarise($config['calendars'][$i]);
        }

        throw new InvalidArgumentException('There is no holiday calendar called "' . $id . '".');
    }

    /**
     * Re-fetch everything.
     *
     * One failure does not abandon the rest: a country whose service is down
     * should not stop the others being brought up to date.
     *
     * @return array{refreshed:list<array<string,mixed>>,failed:list<array{label:string,why:string}>}
     */
    public function refreshAll(int $years = self::DEFAULT_YEARS): array
    {
        $refreshed = [];
        $failed = [];
        foreach ($this->calendars()->all() as $calendar) {
            try {
                $refreshed[] = $this->refresh((string) $calendar['id'], $years);
            } catch (\Throwable $e) {
                $failed[] = ['label' => (string) $calendar['label'], 'why' => $e->getMessage()];
            }
        }

        return ['refreshed' => $refreshed, 'failed' => $failed];
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $config = $this->read();
        foreach ($config['calendars'] as $i => $calendar) {
            if (strtolower((string) $calendar['id']) === strtolower($id)) {
                $config['calendars'][$i]['enabled'] = $enabled;
                $this->write($config);

                return;
            }
        }
        throw new InvalidArgumentException('There is no holiday calendar called "' . $id . '".');
    }

    public function remove(string $id): void
    {
        $config = $this->read();
        $before = count($config['calendars']);
        $config['calendars'] = array_values(array_filter(
            $config['calendars'],
            static fn (array $c): bool => strtolower((string) $c['id']) !== strtolower($id),
        ));
        if (count($config['calendars']) === $before) {
            throw new InvalidArgumentException('There is no holiday calendar called "' . $id . '".');
        }
        $this->write($config);
    }

    /**
     * Whole calendar years from this one, filtered to what the place observes.
     *
     * @return list<array{date:string,name:string,national:bool}>
     */
    private function fetch(string $country, ?string $region, int $years): array
    {
        $years = max(1, min(10, $years));
        $firstYear = (int) date('Y');
        $out = [];
        $seen = [];

        for ($year = $firstYear; $year < $firstYear + $years; $year++) {
            foreach (($this->fetchYear)($country, $year) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $counties = is_array($entry['counties'] ?? null) ? $entry['counties'] : [];
                $national = (bool) ($entry['global'] ?? false);
                // National days always count. A regional one counts only where a
                // region was named and observes it — otherwise a country-wide set
                // inherits every province's local days.
                if (!$national && ($region === null || !in_array($region, $counties, true))) {
                    continue;
                }
                $date = (string) ($entry['date'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || isset($seen[$date])) {
                    continue;   // two regions can name one date differently
                }
                $seen[$date] = true;
                $out[] = [
                    'date' => $date,
                    'name' => (string) ($entry['localName'] ?? $entry['name'] ?? ''),
                    'national' => $national,
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $out;
    }

    /** @return array{_comment:string,_source:string,calendars:list<array<string,mixed>>} */
    private function read(): array
    {
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return [
            '_comment' => 'Holiday calendars drawn on the church calendar. Fetched, not computed: '
                . 'the rules look simple and are not, and legislatures change them. Cached rather than '
                . 'fetched at render time so the calendar draws with no network. Managed from '
                . 'Administration → Calendar & events → Calendar.',
            '_source' => 'https://date.nager.at',
            'calendars' => is_array($decoded['calendars'] ?? null) ? array_values($decoded['calendars']) : [],
        ];
    }

    /** @param array<string,mixed> $config */
    private function write(array $config): void
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not encode the holiday calendars.');
        }
        // Write beside and rename, so a failure halfway cannot leave the
        // calendar reading a half-written file.
        $temp = $this->path . '.writing';
        if (file_put_contents($temp, $json . "\n") === false || !rename($temp, $this->path)) {
            @unlink($temp);
            throw new RuntimeException('Could not save the holiday calendars. Check the file is writable.');
        }
    }

    /**
     * @param array<string,mixed> $entry
     * @return array{id:string,label:string,count:int,through:?string}
     */
    private function summarise(array $entry): array
    {
        $holidays = (array) $entry['holidays'];
        $last = end($holidays);

        return [
            'id' => (string) $entry['id'],
            'label' => (string) $entry['label'],
            'count' => count($holidays),
            'through' => $last === false ? null : (string) $last['date'],
        ];
    }
}
