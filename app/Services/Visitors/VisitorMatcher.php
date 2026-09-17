<?php

declare(strict_types=1);

namespace App\Services\Visitors;

/**
 * Whether a visitor looks like someone we already know.
 *
 * A port of the sign-up and RSVP modules' comparison (sg_person /
 * sg_components / sg_match_level in people_signup/includes/helpers.php, and the
 * rv_ twins), so the Visitors workspace and the greeters' pages agree about a
 * registration. Pure: candidates are fetched elsewhere and handed in.
 *
 * Five components, each counted only when both sides really gave a value:
 * first name, last name, birth month/year, email, mobile. "Exact" needs the
 * person's own name plus one strong identifier. A household shares email and
 * phone (children under a guardian, older members using a relative's), so a
 * shared contact with a shared surname is a family member, and only "possible".
 */
final class VisitorMatcher
{
    /** Values people type when they have nothing to give; they never match. */
    private const PLACEHOLDERS = [
        '', 'n/a', 'na', 'none', 'null', 'nil', 'nan', 'unknown', 'unkown',
        'tbd', 'test', '-', '--', '.', 'x', 'xx', 'xxx', 'xxxx', 'notgiven',
        'noemail', 'no email', 'nophone', 'no phone', '0', '00', '000',
    ];

    /** Lower-cased and trimmed; '' for blanks and placeholders. */
    public static function normalize(mixed $value): string
    {
        $s = strtolower(trim((string) ($value ?? '')));

        return in_array($s, self::PLACEHOLDERS, true) ? '' : $s;
    }

    /** The last ten digits of a phone number, or '' when it has fewer than seven. */
    public static function phoneKey(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';

        return strlen($digits) >= 7 ? substr($digits, -10) : '';
    }

    /**
     * @param list<mixed> $emails
     * @param list<mixed> $phones
     * @return array{first:string,last:string,bm:int,by:int,emails:list<string>,phones:list<string>}
     */
    public static function person(mixed $first, mixed $last, mixed $birthMonth, mixed $birthYear, array $emails, array $phones): array
    {
        $e = [];
        foreach ($emails as $email) {
            $email = self::normalize($email);
            if ($email !== '') {
                $e[$email] = true;
            }
        }
        $p = [];
        foreach ($phones as $phone) {
            $phone = self::phoneKey($phone);
            if ($phone !== '') {
                $p[$phone] = true;
            }
        }

        return [
            'first' => self::normalize($first),
            'last' => self::normalize($last),
            'bm' => (int) $birthMonth,
            'by' => (int) $birthYear,
            'emails' => array_map('strval', array_keys($e)),
            'phones' => array_map('strval', array_keys($p)),
        ];
    }

    /** Exact, the same first word ("Vince" / "Vince Cedric"), or one a prefix of the other. */
    public static function firstNamesAgree(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || strtok($a, ' ') === strtok($b, ' ')) {
            return true;
        }

        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }

    /**
     * Which components two people share.
     *
     * @param array{first:string,last:string,bm:int,by:int,emails:list<string>,phones:list<string>} $a
     * @param array{first:string,last:string,bm:int,by:int,emails:list<string>,phones:list<string>} $b
     * @return list<string>
     */
    public static function components(array $a, array $b): array
    {
        $why = [];
        if (self::firstNamesAgree($a['first'], $b['first'])) {
            $why[] = 'first name';
        }
        if ($a['last'] !== '' && $a['last'] === $b['last']) {
            $why[] = 'last name';
        }
        if ($a['bm'] > 0 && $a['by'] > 0 && $a['bm'] === $b['bm'] && $a['by'] === $b['by']) {
            $why[] = 'birth month/year';
        }
        if (array_intersect($a['emails'], $b['emails']) !== []) {
            $why[] = 'email';
        }
        if (array_intersect($a['phones'], $b['phones']) !== []) {
            $why[] = 'mobile';
        }

        return $why;
    }

    /**
     * 'exact' | 'possible' | 'none'.
     *
     * @param list<string> $why
     */
    public static function level(array $why): string
    {
        $has = static fn (string $k): bool => in_array($k, $why, true);
        $strong = $has('email') || $has('mobile') || $has('birth month/year');

        if ($has('first name') && $has('last name') && $strong) {
            return 'exact';
        }
        if (count($why) >= 2 || $has('email') || $has('mobile')) {
            return 'possible';
        }

        return 'none';
    }

    /**
     * A registration (or RSVP) as a comparable person.
     *
     * @param array<string,mixed> $row visitor_registrations columns
     * @return array{first:string,last:string,bm:int,by:int,emails:list<string>,phones:list<string>}
     */
    public static function fromRegistration(array $row): array
    {
        return self::person(
            $row['first_name'] ?? '', $row['last_name'] ?? '',
            $row['birth_month'] ?? 0, $row['birth_year'] ?? 0,
            [$row['email'] ?? ''], [$row['phone'] ?? ''],
        );
    }

    /**
     * Whether it is worth looking for candidates at all: the modules need a last
     * name, an email or a phone.
     *
     * @param array{last:string,emails:list<string>,phones:list<string>} $person
     */
    public static function searchable(array $person): bool
    {
        return $person['last'] !== '' || $person['emails'] !== [] || $person['phones'] !== [];
    }

    /**
     * Sort candidates into exact and possible matches for a person.
     *
     * Each candidate carries 'id', 'first_name', 'last_name', 'birth_month',
     * 'birth_year', and 'emails' / 'phones' lists. The card returned keeps the
     * candidate's other keys and adds 'why'.
     *
     * @param array{first:string,last:string,bm:int,by:int,emails:list<string>,phones:list<string>} $person
     * @param list<array<string,mixed>> $candidates
     * @return array{exact:list<array<string,mixed>>,possible:list<array<string,mixed>>}
     */
    public static function classify(array $person, array $candidates): array
    {
        $out = ['exact' => [], 'possible' => []];
        foreach ($candidates as $candidate) {
            $other = self::person(
                $candidate['first_name'] ?? '', $candidate['last_name'] ?? '',
                $candidate['birth_month'] ?? 0, $candidate['birth_year'] ?? 0,
                (array) ($candidate['emails'] ?? []), (array) ($candidate['phones'] ?? []),
            );
            $why = self::components($person, $other);
            $level = self::level($why);
            if ($level === 'none') {
                continue;
            }
            $out[$level][] = $candidate + ['why' => $why];
        }

        return $out;
    }
}
