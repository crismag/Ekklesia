<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Pure matching: decide which spreadsheet rows create, update, or drop from a
 * campus roster, keeping existing people's ids so logins and schedule
 * assignments stay attached.
 *
 * Identity is the NAME. An email address or phone number is not a person: a
 * household routinely shares one (children under a guardian's address, older
 * members using a relative's). Matching on email first sent every row with a
 * shared address to whichever family member came first, so the real match was
 * "already taken", a duplicate person was created, and the family member the
 * row actually described was dropped from the campus.
 *
 * So matching runs in two passes:
 *  1. by last name + first-name token. When several people share that name, the
 *     one with the row's email wins, then one already on this campus.
 *  2. rows still unmatched fall back to email, but only when exactly one person
 *     in the database has that address and nobody else has claimed them — the
 *     case of a changed or misspelt name, with nothing ambiguous about who it is.
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
        /** @var array<string,list<int>> $byEmail */
        $byEmail = [];
        /** @var array<string,list<int>> $byName */
        $byName = [];
        $peopleById = [];
        foreach ($people as $p) {
            $id = (int) $p['id'];
            $peopleById[$id] = $p;
            $email = strtolower(trim((string) $p['email']));
            if ($email !== '') {
                $byEmail[$email][] = $id;
            }
            $key = $this->parser->nameKey((string) $p['last_name'], (string) $p['first_name']);
            if ($key !== '|') {
                $byName[$key][] = $id;
            }
        }

        $usedIds = [];
        /** @var array<int,int> $matches row index => person id */
        $matches = [];
        $warnings = [];

        // Pass 1: the name.
        foreach ($fileRows as $i => $row) {
            $key = $this->parser->nameKey((string) $row['last_name'], (string) $row['first_name']);
            $candidates = array_values(array_filter(
                $byName[$key] ?? [],
                static fn (int $id): bool => !isset($usedIds[$id]),
            ));
            if ($candidates === []) {
                if (($byName[$key] ?? []) !== []) {
                    $warnings[] = 'Duplicate match for ' . $row['name_raw']
                        . ' (everyone with that name is already matched); a new record will be created unless the email identifies someone.';
                }
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $pick = null;
            if ($email !== '') {
                foreach ($candidates as $id) {
                    if (strtolower(trim((string) $peopleById[$id]['email'])) === $email) {
                        $pick = $id;
                        break;
                    }
                }
            }
            if ($pick === null) {
                foreach ($candidates as $id) {
                    if ((int) ($peopleById[$id]['campus_id'] ?? 0) === $campusId) {
                        $pick = $id;
                        break;
                    }
                }
            }
            $pick ??= $candidates[0];
            $matches[$i] = $pick;
            $usedIds[$pick] = true;
        }

        // Pass 2: an email address that belongs to exactly one person.
        foreach ($fileRows as $i => $row) {
            if (isset($matches[$i])) {
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $owners = $email !== '' ? ($byEmail[$email] ?? []) : [];
            if (count($owners) !== 1 || isset($usedIds[$owners[0]])) {
                continue;
            }
            $id = $owners[0];
            $matches[$i] = $id;
            $usedIds[$id] = true;
            $warnings[] = 'Matched ' . $row['name_raw'] . ' to person #' . $id . ' ('
                . trim($peopleById[$id]['last_name'] . ', ' . $peopleById[$id]['first_name'])
                . ') by email; the name in the sheet is different.';
        }

        $create = [];
        $update = [];
        foreach ($fileRows as $i => $row) {
            if (!isset($matches[$i])) {
                $create[] = ['row' => $row];
                continue;
            }
            $existing = $peopleById[$matches[$i]];
            $update[] = [
                'row' => $row,
                'person_id' => $matches[$i],
                'existing' => $existing,
                'campus_move' => (int) ($existing['campus_id'] ?? 0) !== $campusId,
            ];
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
