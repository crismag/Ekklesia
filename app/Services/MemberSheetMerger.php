<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Combine the two North York layouts:
 *  - Christlikeness Hub - NY is the latest valid member record (names, preferred,
 *    confirmed, and any field it actually filled in).
 *  - North York supplies additional / older-layout fields when Hub left them blank
 *    (address, phone, email, ministry, member since).
 */
final class MemberSheetMerger
{
    /** Fields copied from NY only when Hub is empty. */
    private const FILL_FIELDS = [
        'email', 'phone', 'address_raw', 'address1', 'city', 'state', 'zip',
        'country', 'member_type', 'ministry', 'middle_name',
    ];

    public function __construct(private MemberWorkbookParser $parser = new MemberWorkbookParser())
    {
    }

    /**
     * @param list<array<string,mixed>> $hubRows
     * @param list<array<string,mixed>> $nyRows
     * @return list<array<string,mixed>>
     */
    public function merge(array $hubRows, array $nyRows): array
    {
        $nyByEmail = [];
        $nyByName = [];
        $nyByRaw = [];
        foreach ($nyRows as $i => $row) {
            $email = (string) ($row['email'] ?? '');
            if ($email !== '') {
                $nyByEmail[$email] = $nyByEmail[$email] ?? $i;
            }
            $nyByName[$this->parser->nameKey((string) $row['last_name'], (string) $row['first_name'])] = $nyByName[$this->parser->nameKey((string) $row['last_name'], (string) $row['first_name'])] ?? $i;
            $raw = strtolower(trim((string) ($row['name_raw'] ?? '')));
            if ($raw !== '') {
                $nyByRaw[$raw] = $nyByRaw[$raw] ?? $i;
            }
        }

        $usedNy = [];
        $out = [];
        foreach ($hubRows as $hub) {
            $nyIndex = $this->findNy($hub, $nyByEmail, $nyByRaw, $nyByName);
            $ny = $nyIndex !== null ? $nyRows[$nyIndex] : null;
            if ($nyIndex !== null) {
                $usedNy[$nyIndex] = true;
            }
            $out[] = $this->combine($hub, $ny);
        }
        foreach ($nyRows as $i => $ny) {
            if (isset($usedNy[$i])) {
                continue;
            }
            $row = $this->flatten($ny, 'ny');
            $row['status'] = 'draft';
            $row['notes'] = 'On North York only — not on the Hub latest list. Confirm before applying.';
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<string,int> $byEmail
     * @param array<string,int> $byRaw
     * @param array<string,int> $byName
     */
    private function findNy(array $hub, array $byEmail, array $byRaw, array $byName): ?int
    {
        $email = (string) ($hub['email'] ?? '');
        if ($email !== '' && isset($byEmail[$email])) {
            return $byEmail[$email];
        }
        $raw = strtolower(trim((string) ($hub['name_raw'] ?? '')));
        if ($raw !== '' && isset($byRaw[$raw])) {
            return $byRaw[$raw];
        }
        $key = $this->parser->nameKey((string) ($hub['last_name'] ?? ''), (string) ($hub['first_name'] ?? ''));
        if ($key !== '|' && isset($byName[$key])) {
            return $byName[$key];
        }
        return null;
    }

    /** @param array<string,mixed>|null $ny */
    private function combine(array $hub, ?array $ny): array
    {
        $row = $this->flatten($hub, 'hub');
        $filled = is_array($row['filled_from']) ? $row['filled_from'] : [];
        if ($ny === null) {
            $row['source'] = 'hub';
            $row['status'] = 'ready';
            return $row;
        }
        foreach (self::FILL_FIELDS as $field) {
            if ($this->isEmpty($row[$field] ?? '') && !$this->isEmpty($ny[$field] ?? '')) {
                $row[$field] = $ny[$field];
                $filled[$field] = 'ny';
            }
        }
        $hubB = is_array($hub['birthday'] ?? null) ? $hub['birthday'] : [];
        $nyB = is_array($ny['birthday'] ?? null) ? $ny['birthday'] : [];
        if (($row['birth_month'] ?? null) === null && ($nyB['month'] ?? null)) {
            $row['birth_year'] = $nyB['year'] ?? null;
            $row['birth_month'] = $nyB['month'] ?? null;
            $row['birth_day'] = $nyB['day'] ?? null;
            $filled['birthday'] = 'ny';
        }
        $hubS = is_array($hub['member_since'] ?? null) ? $hub['member_since'] : [];
        $nyS = is_array($ny['member_since'] ?? null) ? $ny['member_since'] : [];
        if (($row['member_since'] ?? '') === '' && ($nyS['iso'] ?? '') !== '') {
            $row['member_since'] = $nyS['iso'];
            $filled['member_since'] = 'ny';
        }
        $row['filled_from'] = $filled;
        $row['source'] = $filled === [] ? 'hub' : 'merged';
        $row['status'] = 'ready';
        return $row;
    }

    /** @param array<string,mixed> $parsed */
    private function flatten(array $parsed, string $source): array
    {
        $b = is_array($parsed['birthday'] ?? null) ? $parsed['birthday'] : [];
        $s = is_array($parsed['member_since'] ?? null) ? $parsed['member_since'] : [];
        $filled = [];
        foreach (['last_name', 'first_name', 'middle_name', 'preferred_name', 'email', 'phone', 'address_raw', 'address1', 'city', 'member_type', 'ministry'] as $f) {
            if (!$this->isEmpty($parsed[$f] ?? '')) {
                $filled[$f] = $source;
            }
        }
        if (($b['iso'] ?? '') !== '') {
            $filled['birthday'] = $source;
        }
        if (($s['iso'] ?? '') !== '') {
            $filled['member_since'] = $source;
        }
        return [
            'last_name' => (string) ($parsed['last_name'] ?? ''),
            'first_name' => (string) ($parsed['first_name'] ?? ''),
            'middle_name' => (string) ($parsed['middle_name'] ?? ''),
            'preferred_name' => (string) ($parsed['preferred_name'] ?? ''),
            'email' => (string) ($parsed['email'] ?? ''),
            'phone' => (string) ($parsed['phone'] ?? ''),
            'address_raw' => (string) ($parsed['address_raw'] ?? ''),
            'address1' => (string) ($parsed['address1'] ?? ''),
            'city' => (string) ($parsed['city'] ?? ''),
            'state' => (string) ($parsed['state'] ?? 'Ontario'),
            'zip' => (string) ($parsed['zip'] ?? ''),
            'country' => (string) ($parsed['country'] ?? 'CA'),
            'birth_year' => $b['year'] ?? null,
            'birth_month' => $b['month'] ?? null,
            'birth_day' => $b['day'] ?? null,
            'member_since' => $s['iso'] ?? '',
            'member_type' => (string) ($parsed['member_type'] ?? ''),
            'ministry' => (string) ($parsed['ministry'] ?? ''),
            'confirmed' => (string) ($parsed['confirmed'] ?? ''),
            'source' => $source,
            'filled_from' => $filled,
            'status' => $source === 'ny' ? 'draft' : 'ready',
            'notes' => '',
            'name_raw' => (string) ($parsed['name_raw'] ?? ''),
        ];
    }

    private function isEmpty(mixed $v): bool
    {
        return trim((string) $v) === '';
    }
}
