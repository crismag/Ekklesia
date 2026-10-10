<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Search, filters and counts for the Users & access list.
 *
 * Pure: it works on the rows SystemUserService::list() returns, so the rules
 * for "needs attention" are testable without a database and the list page and
 * the admin overview count the same things the same way.
 */
final class LoginDirectory
{
    /** Filter values offered on the list page, with their labels. */
    public const STATES = [
        '' => 'Any status',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'must-change' => 'Must change password',
        'never' => 'Never signed in',
        'unlinked' => 'Not linked to a person',
    ];

    /**
     * @param list<array<string,mixed>> $users
     * @return list<array<string,mixed>>
     */
    public static function filter(array $users, string $query, string $role, string $state): array
    {
        $needle = mb_strtolower(trim($query));

        return array_values(array_filter($users, static function (array $u) use ($needle, $role, $state): bool {
            if ($needle !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($u['email'] ?? ''),
                    (string) ($u['display_name'] ?? ''),
                    (string) ($u['person_name'] ?? ''),
                ]));
                if (!str_contains($haystack, $needle)) {
                    return false;
                }
            }
            if ($role !== '') {
                $roles = array_column($u['roles'] ?? [], 'role');
                if ($role === 'portal-admin') {
                    if (!self::isPortalWideAdmin($u)) {
                        return false;
                    }
                } elseif (!in_array($role, $roles, true)) {
                    return false;
                }
            }

            return match ($state) {
                'active' => !empty($u['is_active']),
                'inactive' => empty($u['is_active']),
                'must-change' => !empty($u['is_active']) && !empty($u['must_change_password']),
                'never' => empty($u['last_login_at']),
                'unlinked' => empty($u['person_id']),
                default => true,
            };
        }));
    }

    /**
     * @param list<array<string,mixed>> $users
     * @return array{total:int,active:int,inactive:int,portalAdmins:int,mustChange:int,never:int,unlinked:int}
     */
    public static function counts(array $users): array
    {
        $count = static fn (string $state): int => count(self::filter($users, '', '', $state));

        return [
            'total' => count($users),
            'active' => $count('active'),
            'inactive' => $count('inactive'),
            'portalAdmins' => count(array_filter(
                $users,
                static fn (array $u): bool => !empty($u['is_active']) && self::isPortalWideAdmin($u),
            )),
            'mustChange' => $count('must-change'),
            'never' => $count('never'),
            'unlinked' => $count('unlinked'),
        ];
    }

    /** @param array<string,mixed> $user */
    public static function isPortalWideAdmin(array $user): bool
    {
        foreach ($user['roles'] ?? [] as $r) {
            if (($r['role'] ?? '') === 'admin' && ($r['campus_id'] ?? null) === null && ($r['ministry_id'] ?? null) === null) {
                return true;
            }
        }

        return false;
    }
}
