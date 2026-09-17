<?php

declare(strict_types=1);

namespace App\Services\Ministry;

/**
 * A ministry's schedule grid read as "who serves on each date".
 *
 * The serving grid (ScheduleGrid::toArray()) is shaped for editing: roles,
 * occurrences and assignments as separate lists. The ministry workspace only
 * reads it, so this folds the three into one list per date, in date order,
 * with each role's people. Pure: no database, no permission — the grid was
 * already authorised by ScheduleService when it was fetched.
 */
final class ServingDates
{
    /**
     * @param array<string,mixed> $grid ScheduleGrid::toArray()
     * @return list<array{occurrenceId:int,title:string,startsOn:string,roles:list<array{role:string,people:list<string>}>,unfilled:list<string>}>
     */
    public static function fromGrid(array $grid): array
    {
        $roleNames = [];
        foreach ((array) ($grid['roles'] ?? []) as $role) {
            $roleNames[(int) ($role['id'] ?? 0)] = (string) ($role['name'] ?? '');
        }

        $dates = [];
        foreach ((array) ($grid['occurrences'] ?? []) as $o) {
            $id = (int) ($o['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $dates[$id] = [
                'occurrenceId' => $id,
                'title'        => (string) ($o['eventTitle'] ?? ''),
                'startsOn'     => (string) ($o['startsOn'] ?? ''),
                'byRole'       => [],
            ];
        }

        foreach ((array) ($grid['assignments'] ?? []) as $a) {
            $occurrenceId = (int) ($a['occurrenceId'] ?? 0);
            if (!isset($dates[$occurrenceId])) {
                continue;
            }
            $roleId = (int) ($a['roleId'] ?? 0);
            $name = trim((string) ($a['displayName'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($a['label'] ?? ''));
            }
            if ($name === '') {
                continue;
            }
            if (!in_array($name, $dates[$occurrenceId]['byRole'][$roleId] ?? [], true)) {
                $dates[$occurrenceId]['byRole'][$roleId][] = $name;
            }
        }

        $out = [];
        foreach ($dates as $date) {
            $roles = [];
            $unfilled = [];
            // Roles in the grid's own order, so every date reads the same way.
            foreach ($roleNames as $roleId => $roleName) {
                if (isset($date['byRole'][$roleId])) {
                    $roles[] = ['role' => $roleName, 'people' => $date['byRole'][$roleId]];
                } else {
                    $unfilled[] = $roleName;
                }
            }
            $out[] = [
                'occurrenceId' => $date['occurrenceId'],
                'title'        => $date['title'],
                'startsOn'     => $date['startsOn'],
                'roles'        => $roles,
                'unfilled'     => $unfilled,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['startsOn'], $b['startsOn']) ?: $a['occurrenceId'] <=> $b['occurrenceId']);

        return $out;
    }
}
