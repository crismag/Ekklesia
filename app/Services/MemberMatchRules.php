<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What must agree before two people are treated as the same person, on top of
 * the surname and the first word of the first name.
 *
 * The default (no rule chosen) is that name rule alone. It was not enough for a
 * parent "Jessie" and a child "Jessie James" who shared an email, phone and
 * address: both keyed as the same person and the parent was dropped. The
 * importer chooses extra rules when the name is not a sufficient determinant.
 * The same rules decide duplicates inside the workbook and which existing
 * record a row updates, so a row the report keeps apart cannot then be
 * matched onto the other person's record.
 *
 * Apart from the full first name, a rule only separates two people when both
 * sides have a value and the values differ: a blank cell is missing
 * information, not evidence of a different person.
 */
final class MemberMatchRules
{
    public const FULL_FIRST_NAME = 'full_first_name';
    public const BIRTHDAY = 'birthday';
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const MEMBER_TYPE = 'member_type';

    /** @var array<string,array{label:string,hint:string}> */
    public const LABELS = [
        self::FULL_FIRST_NAME => ['label' => 'Full first name', 'hint' => '"Jessie" and "Jessie James" are different people.'],
        self::BIRTHDAY => ['label' => 'Birthday', 'hint' => 'Different birthdays are different people.'],
        self::EMAIL => ['label' => 'Email', 'hint' => 'Different email addresses are different people.'],
        self::PHONE => ['label' => 'Phone', 'hint' => 'Different phone numbers are different people.'],
        self::MEMBER_TYPE => ['label' => 'Member type', 'hint' => 'Different member types are different people.'],
    ];

    /** @var list<string> */
    private array $rules;

    /** @param iterable<mixed> $rules */
    public function __construct(iterable $rules = [])
    {
        $chosen = [];
        foreach ($rules as $rule) {
            $rule = (string) $rule;
            if (isset(self::LABELS[$rule])) {
                $chosen[$rule] = true;
            }
        }
        // Stored in a fixed order so the same choice always reads the same.
        $this->rules = array_values(array_filter(array_keys(self::LABELS), static fn (string $r): bool => isset($chosen[$r])));
    }

    /** Rules stored on a batch as JSON (null or unreadable = the default). */
    public static function fromJson(?string $json): self
    {
        $decoded = json_decode((string) $json, true);

        return new self(is_array($decoded) ? $decoded : []);
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return $this->rules;
    }

    public function has(string $rule): bool
    {
        return in_array($rule, $this->rules, true);
    }

    public function isDefault(): bool
    {
        return $this->rules === [];
    }

    /**
     * @param bool $withName false leaves out "Full first name", for sentences
     *                       about the fields compared beside the name
     * @return list<string>
     */
    public function labels(bool $withName = true): array
    {
        $rules = $withName ? $this->rules : array_values(array_diff($this->rules, [self::FULL_FIRST_NAME]));

        return array_map(static fn (string $r): string => self::LABELS[$r]['label'], $rules);
    }

    /**
     * The name part of identity: surname plus the first word of the first
     * name, or the whole first name when that rule is chosen.
     */
    public function nameKey(string $last, string $first): string
    {
        $norm = static fn (string $s): string => (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim($s)));
        $given = $this->has(self::FULL_FIRST_NAME) ? $first : (explode(' ', trim($first))[0] ?? '');

        return $norm($last) . '|' . $norm($given);
    }

    /**
     * Whether two records may be the same person under the chosen rules. The
     * name is compared separately (nameKey); this checks everything else.
     *
     * Accepts a workbook row, a staged row, a planner row or a person from the
     * match index — they name the same facts slightly differently.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    public function agree(array $a, array $b): bool
    {
        if ($this->has(self::EMAIL) && !self::sameOrBlank(self::email($a), self::email($b))) {
            return false;
        }
        if ($this->has(self::PHONE) && !self::sameOrBlank(self::phone($a), self::phone($b))) {
            return false;
        }
        if ($this->has(self::MEMBER_TYPE) && !self::sameOrBlank(self::memberType($a), self::memberType($b))) {
            return false;
        }
        if ($this->has(self::BIRTHDAY)) {
            $x = self::birthday($a);
            $y = self::birthday($b);
            if ($x !== null && $y !== null) {
                if ($x['month'] !== $y['month'] || $x['day'] !== $y['day']) {
                    return false;
                }
                if ($x['year'] > 0 && $y['year'] > 0 && $x['year'] !== $y['year']) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function sameOrBlank(string $x, string $y): bool
    {
        return $x === '' || $y === '' || $x === $y;
    }

    /** @param array<string,mixed> $r */
    private static function email(array $r): string
    {
        return strtolower(trim((string) ($r['email'] ?? '')));
    }

    /** @param array<string,mixed> $r */
    private static function phone(array $r): string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) ($r['phone'] ?? $r['mobile_phone'] ?? ''));

        // Compare the local number, so "+1 416…" and "416…" agree.
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    /** @param array<string,mixed> $r */
    private static function memberType(array $r): string
    {
        return strtolower(trim((string) ($r['member_type'] ?? '')));
    }

    /**
     * @param array<string,mixed> $r
     * @return array{year:int,month:int,day:int}|null
     */
    private static function birthday(array $r): ?array
    {
        $b = is_array($r['birthday'] ?? null) ? $r['birthday'] : [];
        $month = (int) ($r['birth_month'] ?? $b['month'] ?? 0);
        $day = (int) ($r['birth_day'] ?? $b['day'] ?? 0);
        $year = (int) ($r['birth_year'] ?? $b['year'] ?? 0);
        if ($month < 1 || $day < 1) {
            return null;
        }

        return ['year' => $year, 'month' => $month, 'day' => $day];
    }
}
