<?php
/**
 * Standalone validation helpers for the Events RSVP module.
 */
declare(strict_types=1);

if (!function_exists('rv_v_required')) {
    function rv_v_required($v): bool { return is_string($v) ? trim($v) !== '' : ($v !== null && $v !== ''); }
    function rv_v_name($v, int $max = 60): bool { return rv_v_required($v) && mb_strlen(trim((string) $v)) <= $max; }
    function rv_v_email($v): bool { return $v === null || trim((string) $v) === '' || (bool) filter_var(trim((string) $v), FILTER_VALIDATE_EMAIL); }
    function rv_v_phone($v): bool { return $v === null || trim((string) $v) === '' || (bool) preg_match('/^[0-9+()\-.\s]{6,40}$/', trim((string) $v)); }
    function rv_v_month_opt($v): bool { if ($v === null || $v === '') { return true; } $n = (int) $v; return $n >= 1 && $n <= 12; }
    function rv_v_year_opt($v): bool { if ($v === null || $v === '') { return true; } $n = (int) $v; return $n >= 1900 && $n <= (int) date('Y'); }

    /**
     * Validate an RSVP submission. Returns a list of error messages (empty = ok).
     * @param array<string,mixed> $in
     * @return list<string>
     */
    function rv_validate(array $in): array
    {
        $errors = [];
        if (!rv_v_name($in['first_name'] ?? '')) { $errors[] = 'First name is required.'; }
        if (!rv_v_name($in['last_name'] ?? ''))  { $errors[] = 'Last name is required.'; }
        if (!rv_v_email($in['email'] ?? null))   { $errors[] = 'Email address looks invalid.'; }
        if (!rv_v_phone($in['phone'] ?? null))   { $errors[] = 'Phone number looks invalid.'; }
        if (!rv_v_month_opt($in['birth_month'] ?? null)) { $errors[] = 'Birth month looks invalid.'; }
        if (!rv_v_year_opt($in['birth_year'] ?? null))   { $errors[] = 'Birth year looks invalid.'; }

        $party = (int) ($in['party_size'] ?? 1);
        if ($party < 1 || $party > 50) { $errors[] = 'Party count must be between 1 and 50.'; }

        $status = (string) ($in['response'] ?? 'yes');
        $allowed = rv_cfg('features.allow_maybe', true) ? ['yes', 'no', 'maybe'] : ['yes', 'no'];
        if (!in_array($status, $allowed, true)) { $errors[] = 'Invalid RSVP status.'; }

        return $errors;
    }
}
