<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Map a staged / planned member row onto PersonAdminService::save() input.
 * Updates keep existing CRM values when the spreadsheet cell is blank so a
 * Hub-only import does not wipe phones, emails, or addresses already on file.
 */
final class MemberImportPayload
{
    /**
     * @param array<string,mixed> $row
     * @param array<string,int> $typeIds
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
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
        foreach (['per_Email', 'per_WorkEmail'] as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $bad[$field] = $value;
            }
        }

        return $bad;
    }

    public static function forSave(
        array $row,
        int $campusId,
        int $clsId,
        array $typeIds,
        int $personId,
        ?array $existing,
    ): array {
        $birth = self::birthParts($row);
        $since = self::memberSinceIso($row);
        $incoming = [
            'per_ID' => $personId,
            'per_FirstName' => (string) ($row['first_name'] ?? ''),
            'per_MiddleName' => (string) ($row['middle_name'] ?? ''),
            'per_LastName' => (string) ($row['last_name'] ?? ''),
            'per_Email' => (string) ($row['email'] ?? ''),
            'per_CellPhone' => (string) ($row['phone'] ?? ''),
            'per_Address1' => (string) ($row['address1'] ?? ''),
            'per_City' => (string) ($row['city'] ?? ''),
            'per_State' => (string) ($row['state'] ?? ''),
            'per_Zip' => (string) ($row['zip'] ?? ''),
            'per_Country' => (string) ($row['country'] ?? ''),
            'per_BirthMonth' => $birth['month'],
            'per_BirthDay' => $birth['day'],
            'per_BirthYear' => $birth['year'],
            'per_MembershipDate' => $since,
            'per_cls_ID' => $clsId,
            'member_type_id' => self::memberTypeId((string) ($row['member_type'] ?? ''), $typeIds),
            'primary_campus_id' => $campusId,
        ];
        if ($personId <= 0 || !is_array($existing)) {
            if (trim((string) $incoming['per_State']) === '') {
                $incoming['per_State'] = 'Ontario';
            }
            if (trim((string) $incoming['per_Country']) === '') {
                $incoming['per_Country'] = 'CA';
            }
            return self::withoutUnusableEmails($incoming);
        }
        $payload = $existing;
        $payload['per_ID'] = $personId;
        $payload['per_LastName'] = $incoming['per_LastName'];
        $payload['per_cls_ID'] = $clsId;
        $payload['primary_campus_id'] = $campusId;
        foreach ([
            'per_FirstName', 'per_MiddleName', 'per_Email', 'per_CellPhone',
            'per_Address1', 'per_City', 'per_State', 'per_Zip', 'per_Country',
            'per_MembershipDate',
        ] as $key) {
            if (!self::isBlank($incoming[$key] ?? '')) {
                $payload[$key] = $incoming[$key];
            }
        }
        if ((int) $incoming['per_BirthMonth'] > 0 && (int) $incoming['per_BirthDay'] > 0) {
            $payload['per_BirthMonth'] = $incoming['per_BirthMonth'];
            $payload['per_BirthDay'] = $incoming['per_BirthDay'];
            $payload['per_BirthYear'] = $incoming['per_BirthYear'];
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
