<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Map a staged / planned member row onto PersonAdminService::save() input.
 * Updates keep existing values when the spreadsheet cell is blank so a
 * Hub-only import does not wipe phones, emails, or addresses already on file.
 */
final class MemberImportPayload
{
    /**
     * Email addresses already on file that cannot be written back.
     *
     * PersonAdminService validates the whole record on save, so a malformed
     * address stored years ago fails an import that never touched it. The
     * import merges the existing record and then cannot persist it, and the
     * whole batch aborts on a value the administrator did not supply.
     *
     * @param array<string,mixed> $payload
     * @return array<string,string> field => the unusable value
     */
    public static function unusableEmails(array $payload): array
    {
        $bad = [];
        foreach (['email'] as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $bad[$field] = $value;
            }
        }

        return $bad;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int> $typeIds
     * @param array<string,mixed>|null $existing the `people` row
     * @return array<string,mixed>
     */
    public static function forSave(
        array $row,
        int $campusId,
        int $statusId,
        array $typeIds,
        int $personId,
        ?array $existing,
    ): array {
        $birth = self::birthParts($row);
        $since = self::memberSinceIso($row);
        $incoming = [
            'id' => $personId,
            'first_name' => (string) ($row['first_name'] ?? ''),
            'middle_name' => (string) ($row['middle_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'mobile_phone' => (string) ($row['phone'] ?? ''),
            'address_line1' => (string) ($row['address_line1'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'region' => (string) ($row['region'] ?? ''),
            'postal_code' => (string) ($row['postal_code'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'birth_month' => $birth['month'],
            'birth_day' => $birth['day'],
            'birth_year' => $birth['year'],
            'member_since' => $since,
            'membership_status_id' => $statusId,
            'member_type_id' => self::memberTypeId((string) ($row['member_type'] ?? ''), $typeIds),
            'campus_id' => $campusId,
        ];
        if ($personId <= 0 || !is_array($existing)) {
            if (trim((string) $incoming['region']) === '') {
                $incoming['region'] = 'Ontario';
            }
            if (trim((string) $incoming['country']) === '') {
                $incoming['country'] = 'CA';
            }
            return self::withoutUnusableEmails($incoming);
        }
        // save() writes every column on each call, so an update starts from the
        // whole existing row: anything the sheet does not speak about is kept.
        $payload = $existing;
        $payload['id'] = $personId;
        $payload['last_name'] = $incoming['last_name'];
        $payload['membership_status_id'] = $statusId;
        $payload['campus_id'] = $campusId;
        foreach ([
            'first_name', 'middle_name', 'email', 'mobile_phone',
            'address_line1', 'city', 'region', 'postal_code', 'country',
            'member_since',
        ] as $key) {
            if (!self::isBlank($incoming[$key] ?? '')) {
                $payload[$key] = $incoming[$key];
            }
        }
        if ((int) $incoming['birth_month'] > 0 && (int) $incoming['birth_day'] > 0) {
            $payload['birth_month'] = $incoming['birth_month'];
            $payload['birth_day'] = $incoming['birth_day'];
            $payload['birth_year'] = $incoming['birth_year'];
        }
        if ($incoming['member_type_id'] !== null) {
            $payload['member_type_id'] = $incoming['member_type_id'];
        }

        return self::withoutUnusableEmails($payload);
    }

    /**
     * Drop an email that could never be saved.
     *
     * Not silent: applyBatch() reports every address dropped this way, so the
     * administrator learns which records hold unusable data instead of the
     * import failing on it. Keeping it would guarantee the save is rejected,
     * which is what blocked the whole batch before.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function withoutUnusableEmails(array $payload): array
    {
        foreach (array_keys(self::unusableEmails($payload)) as $field) {
            $payload[$field] = '';
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{month:int|string,day:int|string,year:int|string}
     */
    public static function birthParts(array $row): array
    {
        if (is_array($row['birthday'] ?? null)) {
            $b = $row['birthday'];
            return [
                'month' => $b['month'] ?? 0,
                'day' => $b['day'] ?? 0,
                'year' => $b['year'] ?? '',
            ];
        }
        return [
            'month' => $row['birth_month'] ?? 0,
            'day' => $row['birth_day'] ?? 0,
            'year' => $row['birth_year'] ?? '',
        ];
    }

    /** @param array<string,mixed> $row */
    public static function memberSinceIso(array $row): string
    {
        if (is_array($row['member_since'] ?? null)) {
            return (string) ($row['member_since']['iso'] ?? '');
        }
        return (string) ($row['member_since'] ?? '');
    }

    /** @param array<string,int> $typeIds */
    public static function memberTypeId(string $typeName, array $typeIds): ?int
    {
        $typeName = strtolower(trim($typeName));
        if ($typeName === '') {
            return null;
        }
        if (isset($typeIds[$typeName])) {
            return $typeIds[$typeName];
        }
        foreach ($typeIds as $name => $id) {
            if (str_contains($name, $typeName) || str_contains($typeName, $name)) {
                return $id;
            }
        }
        return null;
    }

    private static function isBlank(mixed $v): bool
    {
        if ($v === null) {
            return true;
        }
        if (is_int($v) || is_float($v)) {
            return (int) $v === 0;
        }
        return trim((string) $v) === '';
    }
}
