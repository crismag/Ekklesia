<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Pure matching: decide which spreadsheet rows create, update, or drop from a
 * campus roster. Existing people are matched by email first, then last name +
 * first-name token, so portal logins and schedule assignments keep their ids.
 */
final class MemberImportPlanner
{
    public function __construct(private MemberWorkbookParser $parser = new MemberWorkbookParser())
    {
    }

    /**
     * @param list<array<string,mixed>> $fileRows
     * @param list<array{id:int,first_name:string,last_name:string,email:string,campus_id:?int}> $people
     * @return array{
     *   create:list<array<string,mixed>>,
     *   update:list<array<string,mixed>>,
     *   remove:list<array<string,mixed>>,
     *   warnings:list<string>
     * }
     */
    public function plan(array $fileRows, array $people, int $campusId): array
    {
        $byEmail = [];
        $byName = [];
        foreach ($people as $p) {
            $id = (int) $p['id'];
            $email = strtolower(trim((string) $p['email']));
            if ($email !== '') {
                $byEmail[$email] = $byEmail[$email] ?? $id;
            }
            $key = $this->parser->nameKey((string) $p['last_name'], (string) $p['first_name']);
            if ($key !== '|') {
                $byName[$key] = $byName[$key] ?? $id;
            }
        }
        $peopleById = [];
        foreach ($people as $p) {
            $peopleById[(int) $p['id']] = $p;
        }

        $usedIds = [];
        $create = [];
        $update = [];
        $warnings = [];

        foreach ($fileRows as $row) {
            $matchId = 0;
            $email = (string) ($row['email'] ?? '');
            if ($email !== '' && isset($byEmail[$email])) {
                $matchId = (int) $byEmail[$email];
            } else {
                $key = $this->parser->nameKey((string) $row['last_name'], (string) $row['first_name']);
                if (isset($byName[$key])) {
                    $matchId = (int) $byName[$key];
                }
            }
            if ($matchId > 0 && isset($usedIds[$matchId])) {
                $warnings[] = 'Duplicate match for ' . $row['name_raw']
                    . ' (already matched person #' . $matchId . '); a new record will be created.';
                $matchId = 0;
            }
            if ($matchId > 0) {
                $usedIds[$matchId] = true;
                $existing = $peopleById[$matchId];
                $update[] = [
                    'row' => $row,
                    'person_id' => $matchId,
                    'existing' => $existing,
                    'campus_move' => (int) ($existing['campus_id'] ?? 0) !== $campusId,
                ];
            } else {
                $create[] = ['row' => $row];
            }
        }

        $remove = [];
        foreach ($people as $p) {
            $id = (int) $p['id'];
            if ((int) ($p['campus_id'] ?? 0) !== $campusId) {
                continue;
            }
            if (isset($usedIds[$id])) {
                continue;
            }
            $remove[] = [
                'person_id' => $id,
                'first_name' => (string) $p['first_name'],
                'last_name' => (string) $p['last_name'],
                'email' => (string) $p['email'],
            ];
        }

        return [
            'create' => $create,
            'update' => $update,
            'remove' => $remove,
            'warnings' => $warnings,
        ];
    }
}
