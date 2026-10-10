<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Parses a campus member workbook (xlsx). CSV is accepted only for tests /
 * explicit opt-in: it has no merge-cell metadata, so household addresses that
 * were merged in Excel exist only on the first row.
 *
 * No Composer spreadsheet library: xlsx is read via ZipArchive + SimpleXML.
 */
final class MemberWorkbookParser
{
    public const DEFAULT_SHEET = 'Christlikeness Hub - NY';
    public const HUB_SHEET = 'Christlikeness Hub - NY';
    public const NY_SHEET = 'North York';
    public const SC_HUB_SHEET = 'Christlikeness Hub-SC';
    public const SC_MEMBERS_SHEET = 'Scarborough Members 2026';

    public const CSV_MERGE_WARNING = 'CSV has no Excel merge ranges. A household address that was merged down a family in Google Sheets / Excel lives only on the first row after a CSV export. Use .xlsx or a Google Sheets link (downloaded as xlsx) so merged cells are copied onto every member.';

    /** @var array<string,array{primary:string,secondary:string,label:string}> */
    public const PRESETS = [
        'ny' => [
            'label' => 'North York',
            'primary' => self::HUB_SHEET,
            'secondary' => self::NY_SHEET,
        ],
        'sc' => [
            'label' => 'Scarborough',
            'primary' => self::SC_HUB_SHEET,
            'secondary' => self::SC_MEMBERS_SHEET,
        ],
    ];

    /** Built on first use: most parser calls never touch an address. */
    private ?AddressNormalizer $addresses = null;

    /**
     * @param ?AddressNormalizer $addresses substitutable so tests can supply a
     *        known city list rather than depending on the shipped file.
     */
    public function __construct(?AddressNormalizer $addresses = null)
    {
        $this->addresses = $addresses;
    }

    private function addresses(): AddressNormalizer
    {
        return $this->addresses ??= AddressNormalizer::fromFile(
            dirname(__DIR__, 2) . '/config/address-cities.json',
            dirname(__DIR__, 2) . '/config/ca-postal-areas.json',
        );
    }

    public function isCsvPath(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['csv', 'txt'], true);
    }

    /** @return list<string> */
    public function sheetNames(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'], true)) {
            return ['Sheet1'];
        }
        $zip = $this->openXlsx($path);
        $xml = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('Workbook is missing xl/workbook.xml.');
        }
        $wb = $this->xml($xml);
        $names = [];
        foreach ($wb->sheets->sheet as $sheet) {
            $names[] = (string) $sheet['name'];
        }
        return $names;
    }

    /**
     * Parse Hub (latest identity) and North York (extra fields) from one workbook.
     *
     * @return array{
     *   sheets:list<string>,
     *   hub:?array{sheet:string,updated:?string,rows:list<array<string,mixed>>},
     *   ny:?array{sheet:string,updated:?string,rows:list<array<string,mixed>>},
     *   warnings:list<string>
     * }
     */
    public function parseWorkbook(string $path, ?string $hubSheet = self::HUB_SHEET, ?string $nySheet = ''): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($this->isCsvPath($path)) {
            $hub = $this->parseFile($path, $hubSheet ?: 'Sheet1');
            return [
                'sheets' => ['Sheet1'],
                'hub' => $hub,
                'ny' => null,
                'warnings' => [
                    'CSV has a single sheet — treated as the primary member list.',
                    self::CSV_MERGE_WARNING,
                ],
            ];
        }
        $names = $this->sheetNames($path);
        $warnings = [];
        $hub = null;
        $ny = null;
        $hubSheet = trim((string) $hubSheet) ?: self::HUB_SHEET;
        $nySheet = $nySheet === null ? self::NY_SHEET : trim((string) $nySheet);
        try {
            $hub = $this->parseFile($path, $hubSheet);
        } catch (\Throwable $e) {
            $warnings[] = 'Primary worksheet: ' . $e->getMessage();
        }
        if ($nySheet !== '') {
            try {
                $ny = $this->parseFile($path, $nySheet);
            } catch (\Throwable $e) {
                $warnings[] = 'Secondary worksheet: ' . $e->getMessage();
            }
        }
        if ($hub === null && $ny === null) {
            throw new InvalidArgumentException(
                'Could not read the primary or secondary worksheets. Available: ' . implode(', ', $names)
            );
        }
        return ['sheets' => $names, 'hub' => $hub, 'ny' => $ny, 'warnings' => $warnings];
    }

    /**
     * @return array{sheet:string,updated:?string,rows:list<array<string,mixed>>}
     */
    public function parseFile(string $path, ?string $sheetName = self::DEFAULT_SHEET): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The uploaded file could not be read.');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'], true)) {
            return $this->parseGrid($this->readCsv($path), $sheetName ?: 'Sheet1');
        }
        if (!in_array($ext, ['xlsx', 'xlsm'], true)) {
            throw new InvalidArgumentException('Upload an Excel workbook (.xlsx). CSV drops merged household cells.');
        }
        $zip = $this->openXlsx($path);
        try {
            $target = $this->resolveSheet($zip, $sheetName);
            $grid = $this->readSheetGrid($zip, $target['path']);
            return $this->parseGrid($grid, $target['name']);
        } finally {
            $zip->close();
        }
    }

    /**
     * @param list<list<string>> $grid
     * @return array{sheet:string,updated:?string,rows:list<array<string,mixed>>}
     */
    public function parseGrid(array $grid, string $sheetName = ''): array
    {
        $updated = $this->findUpdatedNote($grid);
        $headerRow = $this->findHeaderRow($grid);
        if ($headerRow === null) {
            throw new InvalidArgumentException(
                'Could not find a header row (expected a "Last Name, First Name" or "Name" column).'
            );
        }
        $map = $this->mapColumns($grid[$headerRow]);
        $rows = [];
        for ($r = $headerRow + 1, $n = count($grid); $r < $n; $r++) {
            $parsed = $this->parseDataRow($grid[$r] ?? [], $map, $r + 1);
            if ($parsed !== null) {
                $rows[] = $parsed;
            }
        }
        return ['sheet' => $sheetName, 'updated' => $updated, 'rows' => $rows];
    }

    /** @param list<list<string>> $grid */
    private function findUpdatedNote(array $grid): ?string
    {
        foreach (array_slice($grid, 0, 5) as $row) {
            foreach ($row as $cell) {
                if (preg_match('/last\s*updated/i', $cell)) {
                    return trim($cell);
                }
            }
        }
        return null;
    }

    /** @param list<list<string>> $grid */
    private function findHeaderRow(array $grid): ?int
    {
        $limit = min(12, count($grid));
        for ($i = 0; $i < $limit; $i++) {
            $row = $grid[$i] ?? [];
            $joined = strtolower(implode(' ', $row));
            if (str_contains($joined, 'last name') && str_contains($joined, 'first name')) {
                return $i;
            }
            if (str_contains($joined, 'last name, first name')) {
                return $i;
            }
            foreach ($row as $cell) {
                if (strtolower(trim((string) $cell)) === 'name') {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * @param list<string> $header
     * @return array<string,int>
     */
    private function mapColumns(array $header): array
    {
        $map = [];
        $n = count($header);
        for ($i = 0; $i < $n; $i++) {
            $key = $this->classifyHeader((string) $header[$i]);
            if ($key !== null && !isset($map[$key])) {
                $map[$key] = $i;
            }
        }
        // Hub sheet: member type sits between "Member Since" and "Ministry" with a blank header.
        if (!isset($map['member_type']) && isset($map['member_since'])) {
            $start = $map['member_since'] + 1;
            $end = $map['ministry'] ?? $n;
            for ($i = $start; $i < $end; $i++) {
                if (trim((string) ($header[$i] ?? '')) === '') {
                    $map['member_type'] = $i;
                    break;
                }
            }
        }
        if (!isset($map['name'])) {
            throw new InvalidArgumentException('The worksheet is missing a name column.');
        }
        return $map;
    }

    private function classifyHeader(string $raw): ?string
    {
        $h = strtolower(trim($raw));
        if ($h === '') {
            return null;
        }
        $h = preg_replace('/\s+/', ' ', $h) ?? $h;
        if (str_contains($h, 'confirmed')) {
            return 'confirmed';
        }
        if (str_contains($h, 'preferred') || str_contains($h, 'preffered')) {
            return 'preferred';
        }
        if (str_contains($h, 'middle')) {
            return 'middle';
        }
        if (str_contains($h, 'birthday') || str_contains($h, 'birth day') || str_contains($h, 'birthdate')) {
            return 'birthday';
        }
        if (str_contains($h, 'address')) {
            return 'address';
        }
        if (str_contains($h, 'contact') || str_contains($h, 'phone')) {
            return 'phone';
        }
        if (str_contains($h, 'email')) {
            return 'email';
        }
        if (str_contains($h, 'member since')) {
            return 'member_since';
        }
        if (str_contains($h, 'ministry')) {
            return 'ministry';
        }
        if (str_contains($h, 'member type') || $h === 'type' || str_contains($h, 'classification')) {
            return 'member_type';
        }
        if (str_contains($h, 'last name') || str_contains($h, 'official name') || $h === 'name') {
            return 'name';
        }
        return null;
    }

    /**
     * @param list<string> $row
     * @param array<string,int> $map
     * @return array<string,mixed>|null
     */
    private function parseDataRow(array $row, array $map, int $line): ?array
    {
        $cell = static function (string $key) use ($row, $map): string {
            if (!isset($map[$key])) {
                return '';
            }
            return trim((string) ($row[$map[$key]] ?? ''));
        };
        $nameRaw = $cell('name');
        if ($nameRaw === '' || $this->isJunkName($nameRaw)) {
            return null;
        }
        $name = $this->splitName($nameRaw);
        if ($name['last'] === '') {
            return null;
        }
        $birthday = $this->parseDate($cell('birthday'));
        $since = $this->parseDate($cell('member_since'));
        $address = $this->parseAddress($cell('address'));
        $email = $this->normalizeEmail($cell('email'));
        $preferred = $cell('preferred');
        $first = $name['first'] !== '' ? $name['first'] : $preferred;

        return [
            'line' => $line,
            'confirmed' => $cell('confirmed'),
            'name_raw' => $nameRaw,
            'last_name' => $name['last'],
            'first_name' => $first,
            'middle_name' => $cell('middle'),
            'preferred_name' => $preferred,
            'birthday' => $birthday,
            'address_raw' => $cell('address'),
            // The address parser keeps its own field names; a member row uses
            // the member database's.
            'address_line1' => $address['address1'],
            'city' => $address['city'],
            'region' => $address['state'],
            'postal_code' => $address['zip'],
            'country' => $address['country'],
            'phone' => $this->normalizePhone($cell('phone')),
            'email' => $email,
            'member_since' => $since,
            'member_type' => $this->normalizeMemberType($cell('member_type')),
            'ministry' => $cell('ministry'),
        ];
    }

    private function isJunkName(string $name): bool
    {
        $n = strtolower(trim($name));
        return str_starts_with($n, 'last name')
            || str_contains($n, 'ask for official')
            || $n === 'name'
            || (bool) preg_match('/^\d+\s+(adults?|volunteers?|members?|children|kids)\b/i', $n)
            || (bool) preg_match('/^(adults?|volunteers?|members?|total|subtotal)\b/i', $n);
    }

    /** @return array{last:string,first:string} */
    public function splitName(string $raw): array
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        if (str_contains($raw, ',')) {
            [$last, $first] = array_map('trim', explode(',', $raw, 2));
            return ['last' => $last, 'first' => $first];
        }
        $parts = explode(' ', $raw);
        if (count($parts) === 1) {
            return ['last' => $parts[0], 'first' => ''];
        }
        $last = array_pop($parts);
        return ['last' => $last, 'first' => implode(' ', $parts)];
    }

    /** @return array{year:?int,month:?int,day:?int,iso:?string} */
    public function parseDate(string $raw): array
    {
        $empty = ['year' => null, 'month' => null, 'day' => null, 'iso' => null];
        $raw = trim($raw);
        if ($raw === '') {
            return $empty;
        }
        if (is_numeric($raw)) {
            // A four-digit whole number is a year someone typed, not a date
            // serial (serials in that range are 1902–1908); strtotime would
            // otherwise give it today's month and day.
            if (preg_match('/^(1[89]|2\d)\d\d$/', $raw)) {
                return $empty;
            }
            $serial = (float) $raw;
            if ($serial >= 1 && $serial < 80000) {
                return $this->fromExcelSerial($serial);
            }
            return $empty;
        }
        $fixed = str_ireplace(
            ['Janaury', 'Januray', 'Febuary', 'Febrary', 'Septemeber'],
            ['January', 'January', 'February', 'February', 'September'],
            $raw
        );
        $ts = strtotime($fixed);
        if ($ts === false) {
            return $empty;
        }
        $date = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        // strtotime reads a two-digit year 00–69 as 20xx, so "3/5/45" became a
        // birthday in 2045. Neither a birthday nor a membership date is in a
        // future year, so a year the text did not spell out goes back a century.
        $year = (int) $date->format('Y');
        if ($year > (int) date('Y') && !str_contains($fixed, (string) $year)) {
            $date = $date->modify('-100 years');
        }
        return [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'day' => (int) $date->format('j'),
            'iso' => $date->format('Y-m-d'),
        ];
    }

    /** @return array{year:?int,month:?int,day:?int,iso:?string} */
    private function fromExcelSerial(float $serial): array
    {
        // Google Sheets / Excel 1900 date system, origin 1899-12-30. Counted
        // in calendar days rather than Unix seconds: members born before 1970
        // have serials below the epoch and must convert the same way.
        $days = (int) floor($serial);
        $date = (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->modify('+' . $days . ' days');
        return [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'day' => (int) $date->format('j'),
            'iso' => $date->format('Y-m-d'),
        ];
    }

    /**
     * @return array{address1:string,city:string,state:string,zip:string,country:string}
     */
    public function parseAddress(string $raw): array
    {
        $parsed = $this->parseAddressDetailed($raw);
        unset($parsed['confident'], $parsed['issues']);

        return $parsed;
    }

    /**
     * The same parse, with what could not be established.
     *
     * The staging screen needs to know which addresses to put in front of an
     * administrator, and "city is empty" is not the same problem as "the
     * workbook never wrote a postal code". The issue codes carry that
     * distinction; parseAddress() drops them for callers that only want fields.
     *
     * @return array{
     *   address1:string,city:string,state:string,zip:string,country:string,
     *   confident:bool,issues:list<string>
     * }
     */
    public function parseAddressDetailed(string $raw): array
    {
        return $this->addresses()->parse($raw);
    }

    public function normalizeEmail(string $raw): string
    {
        $e = strtolower(trim($raw));
        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $e;
    }

    public function normalizePhone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^(n\/?a|none|null|-)$/i', $raw)) {
            return '';
        }
        if (preg_match('/^[0-9.]+e[+\-]?[0-9]+$/i', $raw)) {
            $raw = sprintf('%.0f', (float) $raw);
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        }
        return $raw;
    }

    public function normalizeMemberType(string $raw): string
    {
        $t = strtolower(trim($raw));
        if ($t === '') {
            return '';
        }
        if (str_contains($t, 'radical')) {
            return 'Radical';
        }
        if (str_contains($t, 'trail')) {
            return 'Trailblazer';
        }
        if (str_contains($t, 'gift') || $t === 'g&a' || str_contains($t, 'arrow')) {
            return 'Gifts & Arrows';
        }
        return trim($raw);
    }

    public function nameKey(string $last, string $first): string
    {
        $norm = static function (string $s): string {
            $s = strtolower(trim($s));
            $s = (string) preg_replace('/[^a-z0-9]+/', '', $s);
            return $s;
        };
        $firstToken = explode(' ', trim($first))[0] ?? '';
        return $norm($last) . '|' . $norm($firstToken);
    }

    /** @return list<list<string>> */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Could not open CSV file.');
        }
        $grid = [];
        while (($row = fgetcsv($fh)) !== false) {
            $grid[] = array_map(static fn ($c) => trim((string) $c), $row);
        }
        fclose($fh);
        return $grid;
    }

    private function openXlsx(string $path): ZipArchive
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to read Excel files.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('The Excel file could not be opened.');
        }
        return $zip;
    }

    /** @return array{name:string,path:string} */
    private function resolveSheet(ZipArchive $zip, ?string $wanted): array
    {
        $wbXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wbXml === false || $relsXml === false) {
            throw new RuntimeException('Workbook structure is incomplete.');
        }
        $wb = $this->xml($wbXml);
        $rels = $this->xml($relsXml);
        $ridToTarget = [];
        foreach ($rels->Relationship as $rel) {
            $ridToTarget[(string) $rel['Id']] = ltrim((string) $rel['Target'], '/');
        }
        $sheets = [];
        foreach ($wb->sheets->sheet as $sheet) {
            $name = (string) $sheet['name'];
            $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $ridToTarget[$rid] ?? '';
            if ($target === '') {
                continue;
            }
            if (!str_starts_with($target, 'xl/')) {
                $target = 'xl/' . $target;
            }
            $sheets[] = ['name' => $name, 'path' => $target];
        }
        if ($sheets === []) {
            throw new RuntimeException('No worksheets found in the workbook.');
        }
        $wanted = trim((string) $wanted);
        if ($wanted === '') {
            $wanted = self::DEFAULT_SHEET;
        }
        foreach ($sheets as $s) {
            if (strcasecmp($s['name'], $wanted) === 0) {
                return $s;
            }
        }
        foreach ($sheets as $s) {
            if (stripos($s['name'], $wanted) !== false) {
                return $s;
            }
        }
        if (strcasecmp($wanted, self::DEFAULT_SHEET) === 0) {
            foreach ($sheets as $s) {
                if (stripos($s['name'], 'christlikeness hub') !== false) {
                    return $s;
                }
            }
        }
        $available = implode(', ', array_map(static fn ($s) => $s['name'], $sheets));
        throw new InvalidArgumentException('Worksheet "' . $wanted . '" was not found. Available: ' . $available);
    }

    /** @return list<list<string>> */
    private function readSheetGrid(ZipArchive $zip, string $sheetPath): array
    {
        $strings = $this->sharedStrings($zip);
        $dateStyles = $this->dateStyles($zip);
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false) {
            throw new RuntimeException('Worksheet XML is missing.');
        }
        $sheet = $this->xml($xml);
        $grid = [];
        foreach ($sheet->sheetData->row as $row) {
            $rIdx = (int) $row['r'];
            if ($rIdx < 1) {
                continue;
            }
            while (count($grid) < $rIdx) {
                $grid[] = [];
            }
            $line = &$grid[$rIdx - 1];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = $this->colIndex($ref);
                while (count($line) <= $col) {
                    $line[] = '';
                }
                $line[$col] = $this->cellValue($c, $strings, $dateStyles);
            }
            unset($line);
        }
        $this->applyMergedCells($sheet, $grid);
        return $grid;
    }

    /**
     * Excel stores a merged range's value only in the top-left cell. Household
     * addresses are merged down a family, so copy that value into every cell
     * in the range before we parse people.
     *
     * @param list<list<string>> $grid
     */
    private function applyMergedCells(\SimpleXMLElement $sheet, array &$grid): void
    {
        $nodes = $sheet->xpath('.//*[local-name()="mergeCell"]') ?: [];
        foreach ($nodes as $mc) {
            $ref = trim((string) $mc['ref']);
            if ($ref === '' || !str_contains($ref, ':')) {
                continue;
            }
            [$start, $end] = explode(':', $ref, 2);
            [$c1, $r1] = $this->splitRef($start);
            [$c2, $r2] = $this->splitRef($end);
            $colStart = min($c1, $c2);
            $colEnd = max($c1, $c2);
            $rowStart = min($r1, $r2);
            $rowEnd = max($r1, $r2);
            $value = '';
            for ($r = $rowStart; $r <= $rowEnd; $r++) {
                for ($c = $colStart; $c <= $colEnd; $c++) {
                    $candidate = $grid[$r - 1][$c] ?? '';
                    if (trim((string) $candidate) !== '') {
                        $value = (string) $candidate;
                        break 2;
                    }
                }
            }
            if ($value === '') {
                continue;
            }
            for ($r = $rowStart; $r <= $rowEnd; $r++) {
                while (count($grid) < $r) {
                    $grid[] = [];
                }
                $line = &$grid[$r - 1];
                for ($c = $colStart; $c <= $colEnd; $c++) {
                    while (count($line) <= $c) {
                        $line[] = '';
                    }
                    if (trim((string) $line[$c]) === '') {
                        $line[$c] = $value;
                    }
                }
                unset($line);
            }
        }
    }

    /** @return array{0:int,1:int} 0-based column, 1-based row */
    private function splitRef(string $ref): array
    {
        return [$this->colIndex($ref), $this->rowIndex($ref)];
    }

    private function rowIndex(string $ref): int
    {
        $row = preg_replace('/\D+/', '', $ref) ?? '';
        return max(1, (int) $row);
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $sst = $this->xml($xml);
        $out = [];
        foreach ($sst->si as $si) {
            $out[] = $this->siText($si);
        }
        return $out;
    }

    private function siText(\SimpleXMLElement $si): string
    {
        $parts = [];
        foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
            $parts[] = (string) $t;
        }
        return implode('', $parts);
    }

    /**
     * Style indexes (cellXfs positions) whose number format shows a date.
     *
     * A date cell holds only a day serial; the style is what says it is a
     * date. Knowing that lets any date convert exactly, however old, instead
     * of guessing from the size of the number (1905 as a serial is a day in
     * 1905, as a typed number it is a year).
     *
     * @return array<int,true>
     */
    private function dateStyles(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }
        $styles = $this->xml($xml);
        $custom = [];
        foreach ($styles->numFmts->numFmt ?? [] as $fmt) {
            $custom[(int) $fmt['numFmtId']] = (string) $fmt['formatCode'];
        }
        $out = [];
        $i = 0;
        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (isset($custom[$id]) ? $this->isDateFormatCode($custom[$id]) : $this->isBuiltinDateFormat($id)) {
                $out[$i] = true;
            }
            $i++;
        }
        return $out;
    }

    private function isBuiltinDateFormat(int $id): bool
    {
        // 45–47 are time-only; 27–36 and 50–58 are the East Asian date formats.
        return ($id >= 14 && $id <= 17) || $id === 22 || ($id >= 27 && $id <= 36) || ($id >= 50 && $id <= 58);
    }

    private function isDateFormatCode(string $code): bool
    {
        // Ignore quoted literals, escaped characters and [colour]/[locale] tags.
        $bare = preg_replace(['/"[^"]*"/', '/\\\\./', '/\[[^\]]*\]/'], '', $code) ?? $code;
        return preg_match('/[dy]/i', $bare) === 1;
    }

    /**
     * @param list<string> $strings
     * @param array<int,true> $dateStyles
     */
    private function cellValue(\SimpleXMLElement $c, array $strings, array $dateStyles = []): string
    {
        $type = (string) $c['t'];
        if ($type === 's') {
            $idx = (int) (string) $c->v;
            return $strings[$idx] ?? '';
        }
        if ($type === 'inlineStr') {
            return $this->siText($c->is ?? $c);
        }
        $v = trim((string) $c->v);
        if (($type === '' || $type === 'n') && isset($dateStyles[(int) $c['s']]) && is_numeric($v) && (float) $v >= 1) {
            return (string) $this->fromExcelSerial((float) $v)['iso'];
        }
        return $v;
    }

    private function colIndex(string $ref): int
    {
        $col = preg_replace('/[^A-Z]/i', '', $ref) ?? '';
        $n = 0;
        foreach (str_split(strtoupper($col)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    private function xml(string $xml): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $el = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($el === false) {
            throw new RuntimeException('Spreadsheet XML could not be parsed.');
        }
        return $el;
    }
}
