<?php
/**
 * Standalone validation helpers for the People Sign-Up module.
 * Each validator returns true when the value is acceptable.
 */
declare(strict_types=1);

if (!function_exists('sg_v_required')) {
    function sg_v_required($v): bool { return is_string($v) ? trim($v) !== '' : ($v !== null && $v !== ''); }
    function sg_v_name($v, int $max = 60): bool { return sg_v_required($v) && mb_strlen(trim((string) $v)) <= $max; }
    function sg_v_optlen($v, int $max): bool { return $v === null || $v === '' || mb_strlen(trim((string) $v)) <= $max; }
    function sg_v_email($v): bool { return $v === null || trim((string) $v) === '' || (bool) filter_var(trim((string) $v), FILTER_VALIDATE_EMAIL); }
    function sg_v_phone($v): bool { return $v === null || trim((string) $v) === '' || (bool) preg_match('/^[0-9+()\-.\s]{6,40}$/', trim((string) $v)); }
    function sg_v_month($v): bool { $n = (int) $v; return $n >= 1 && $n <= 12; }
    function sg_v_day_opt($v): bool { if ($v === null || $v === '') { return true; } $n = (int) $v; return $n >= 1 && $n <= 31; }
    function sg_v_year($v): bool { $n = (int) $v; return $n >= 1900 && $n <= (int) date('Y'); }
    function sg_v_in($v, array $allowed): bool { return in_array((string) $v, $allowed, true); }

    /**
     * Validate a simple-signup submission. Returns a list of error messages
     * (empty = valid). Mirrors the required fields in signup.config.json.
     *
     * @param array<string,mixed> $in
     * @return list<string>
     */
    function sg_validate_simple(array $in): array
    {
        $errors = [];
        $maxName = (int) sg_cfg('validation.max_name', 60);
        $maxCity = (int) sg_cfg('validation.max_city', 80);

        if (!sg_v_name($in['first_name'] ?? '', $maxName)) { $errors[] = 'First name is required.'; }
        if (!sg_v_name($in['last_name'] ?? '', $maxName))  { $errors[] = 'Last name is required.'; }
        if (!sg_v_name($in['city'] ?? '', $maxCity))       { $errors[] = 'Town/City is required.'; }

        if (sg_cfg('features.require_reason', true) && !sg_v_required($in['reason_for_visit'] ?? '')) {
            $errors[] = 'Please choose a reason for your visit.';
        }
        if (sg_cfg('features.require_birth_month_year', true)) {
            if (!sg_v_month($in['birth_month'] ?? '')) { $errors[] = 'Please select a valid birth month.'; }
            if (!sg_v_year($in['birth_year'] ?? ''))   { $errors[] = 'Please enter a valid birth year.'; }
        }
        if (!sg_v_day_opt($in['birth_day'] ?? null))   { $errors[] = 'Birth day looks invalid.'; }
        if (!sg_v_email($in['email'] ?? null))         { $errors[] = 'Email address looks invalid.'; }
        if (!sg_v_phone($in['phone'] ?? null))         { $errors[] = 'Phone number looks invalid.'; }
        if (!sg_v_optlen($in['invited_by'] ?? null, 120)) { $errors[] = 'Invited-by is too long.'; }

        return $errors;
    }

    /**
     * Validate a continuation (advanced/extra) submission: only the fields that
     * were actually posted. Required identity fields already live on the row, so
     * they're only checked when this page tries to blank them.
     *
     * @param array<string,mixed> $posted column => value (null for empty)
     * @return list<string>
     */
    function sg_validate_extra(array $posted): array
    {
        $errors = [];
        $has = static fn (string $k): bool => array_key_exists($k, $posted);

        foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'city' => 'Town/City'] as $k => $label) {
            if ($has($k) && ($posted[$k] === null || trim((string) $posted[$k]) === '')) {
                $errors[] = "$label cannot be empty.";
            }
        }
        if ($has('email') && $posted['email'] !== null && !filter_var($posted['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address looks invalid.';
        }
        if ($has('phone') && $posted['phone'] !== null && !preg_match('/^[0-9+()\-.\s]{6,40}$/', (string) $posted['phone'])) {
            $errors[] = 'Phone number looks invalid.';
        }
        if ($has('birth_month') && $posted['birth_month'] !== null && ((int) $posted['birth_month'] < 1 || (int) $posted['birth_month'] > 12)) {
            $errors[] = 'Birth month looks invalid.';
        }
        if ($has('birth_year') && $posted['birth_year'] !== null && ((int) $posted['birth_year'] < 1900 || (int) $posted['birth_year'] > (int) date('Y'))) {
            $errors[] = 'Birth year looks invalid.';
        }
        if ($has('birth_day') && $posted['birth_day'] !== null && ((int) $posted['birth_day'] < 1 || (int) $posted['birth_day'] > 31)) {
            $errors[] = 'Birth day looks invalid.';
        }
        if ($has('gender') && $posted['gender'] !== null && !in_array((int) $posted['gender'], [1, 2], true)) {
            $errors[] = 'Gender is invalid.';
        }
        if ($has('member_type') && $posted['member_type'] !== null && !in_array((int) $posted['member_type'], [1, 2, 3], true)) {
            $errors[] = 'Member type is invalid.';
        }
        if ($has('is_married') && $posted['is_married'] !== null && !in_array((int) $posted['is_married'], [0, 1], true)) {
            $errors[] = 'Marital selection is invalid.';
        }
        if ($has('latitude') && $posted['latitude'] !== null && ((float) $posted['latitude'] < -90 || (float) $posted['latitude'] > 90)) {
            $errors[] = 'Latitude out of range.';
        }
        if ($has('longitude') && $posted['longitude'] !== null && ((float) $posted['longitude'] < -180 || (float) $posted['longitude'] > 180)) {
            $errors[] = 'Longitude out of range.';
        }
        return $errors;
    }
}
