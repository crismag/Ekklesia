<?php

declare(strict_types=1);

/**
 * Small presentation helpers shared by the Admin workspace pages (Users &
 * access, Activity history, the overview). Page-level only: no data access and
 * no authorization — routes and services decide what reaches a view.
 */

if (!function_exists('admin_e')) {
    function admin_e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_role_label')) {
    /**
     * "Leader · Worship team", "Admin (portal-wide)", "Member @ North campus".
     *
     * @param array<string,mixed> $role
     * @param array<int,string>   $campusName
     * @param array<int,string>   $ministryName
     */
    function admin_role_label(array $role, array $campusName, array $ministryName): string
    {
        $label = ucfirst((string) $role['role']);
        $campusId = $role['campus_id'] ?? null;
        $ministryId = $role['ministry_id'] ?? null;
        if ($campusId === null && $ministryId === null) {
            return $label . ($role['role'] === 'admin' ? ' (portal-wide)' : '');
        }
        if ($ministryId !== null) {
            $label .= ' · ' . ($ministryName[(int) $ministryId] ?? 'ministry #' . $ministryId);
        }
        if ($campusId !== null) {
            $label .= ' @ ' . ($campusName[(int) $campusId] ?? 'campus #' . $campusId);
        }

        return $label;
    }
}

if (!function_exists('admin_login_state_badge')) {
    /**
     * Whether a login works, in words (never colour alone).
     *
     * @param array<string,mixed> $user
     */
    function admin_login_state_badge(array $user): string
    {
        if (empty($user['is_active'])) {
            return '<span class="ek-badge">Inactive</span>';
        }
        if (!empty($user['must_change_password'])) {
            return '<span class="ek-badge is-warn">Must change password</span>';
        }

        return '<span class="ek-badge is-ok">Active</span>';
    }
}

if (!function_exists('admin_when')) {
    /**
     * A stored date-time as "12 Sep 2026, 14:05", or $empty when there is none.
     */
    function admin_when(mixed $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }
        $ts = is_int($value) ? $value : strtotime((string) $value);
        if ($ts === false) {
            return (string) $value;
        }

        return date('j M Y, H:i', $ts);
    }
}

if (!function_exists('admin_ago')) {
    /** "3 days ago" for a timestamp; $empty when there is none. */
    function admin_ago(?int $ts, string $empty = '—'): string
    {
        if ($ts === null || $ts <= 0) {
            return $empty;
        }
        $d = max(0, time() - $ts);
        if ($d < 3600) {
            $m = max(1, intdiv($d, 60));

            return $m . ($m === 1 ? ' minute ago' : ' minutes ago');
        }
        if ($d < 86400) {
            $hrs = intdiv($d, 3600);

            return $hrs . ($hrs === 1 ? ' hour ago' : ' hours ago');
        }
        $days = intdiv($d, 86400);

        return $days . ($days === 1 ? ' day ago' : ' days ago');
    }
}

if (!function_exists('admin_notice')) {
    /**
     * The alert for a ?notice= redirect, or '' when the notice is unknown.
     *
     * @param array<string,array{0:string,1:string}> $map notice => [ok|error, message]
     */
    function admin_notice(string $notice, array $map): string
    {
        if ($notice === '' || !isset($map[$notice])) {
            return '';
        }
        [$kind, $message] = $map[$notice];
        $class = $kind === 'error' ? 'is-error' : 'is-ok';
        $role = $kind === 'error' ? 'alert' : 'status';

        return '<div class="ek-alert ' . $class . '" role="' . $role . '">' . admin_e($message) . '</div>';
    }
}
