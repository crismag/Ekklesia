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
 *
 * The importer's match rules (MemberMatchRules) narrow both passes: a person
 * who disagrees with the row on a chosen rule is never its match. "Full first
 * name" turns pass 2 off, since pass 2 exists for names that do not agree.
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
    public function plan(array $fileRows, array $people, int $campusId, MemberMatchRules $rules = new MemberMatchRules()): array
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
            $key = $rules->nameKey((string) $p['last_name'], (string) $p['first_name']);
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
            $key = $rules->nameKey((string) $row['last_name'], (string) $row['first_name']);
            $sameName = $byName[$key] ?? [];
            $agreeing = array_values(array_filter(
                $sameName,
                static fn (int $id): bool => $rules->agree($row, $peopleById[$id]),
            ));
            $candidates = array_values(array_filter(
                $agreeing,
                static fn (int $id): bool => !isset($usedIds[$id]),
            ));
            if ($candidates === []) {
                if ($sameName !== [] && $agreeing === []) {
                    $warnings[] = $row['name_raw'] . ' has the same name as ' . count($sameName)
                        . ' existing ' . (count($sameName) === 1 ? 'person' : 'people')
                        . ' but differs on ' . strtolower(implode(' or ', $rules->labels(false)))
                        . '; kept apart from ' . (count($sameName) === 1 ? 'them' : 'each of them') . '.';
                } elseif ($agreeing !== []) {
                    $warnings[] = 'Duplicate match for ' . $row['name_raw']
                        . ' (everyone with that name is already matched); a new record will be created unless the email identifies someone.';
                }
                continue;
            }
            $pick = $this->bestCandidate($row, $candidates, $peopleById, $campusId);
            $matches[$i] = $pick;
            $usedIds[$pick] = true;
        }

        // Pass 2: an email address that belongs to exactly one person.
        foreach ($fileRows as $i => $row) {
            if (isset($matches[$i])) {
                continue;
            }
            if ($rules->has(MemberMatchRules::FULL_FIRST_NAME)) {
                break;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $owners = $email !== '' ? ($byEmail[$email] ?? []) : [];
            if (count($owners) !== 1 || isset($usedIds[$owners[0]]) || !$rules->agree($row, $peopleById[$owners[0]])) {
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
                $create[] = ['row' => $row, 'index' => $i];
                continue;
            }
            $existing = $peopleById[$matches[$i]];
            $update[] = [
                'index' => $i,
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

    /**
     * Among people who share the row's name, the likeliest one: the same full
     * first name first ("Jessie James" is not "Jessie" when both are on file),
     * then the same email, then already on this campus; ties go to the first.
     *
     * @param array<string,mixed> $row
     * @param list<int> $candidates
     * @param array<int,array<string,mixed>> $peopleById
     */
    private function bestCandidate(array $row, array $candidates, array $peopleById, int $campusId): int
    {
        $norm = static fn (mixed $v): string => (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) $v)));
        $first = $norm($row['first_name'] ?? '');
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $best = $candidates[0];
        $bestScore = -1;
        foreach ($candidates as $id) {
            $p = $peopleById[$id];
            $score = 0;
            if ($first !== '' && $norm($p['first_name'] ?? '') === $first) {
                $score += 4;
            }
            if ($email !== '' && strtolower(trim((string) ($p['email'] ?? ''))) === $email) {
                $score += 2;
            }
            if ((int) ($p['campus_id'] ?? 0) === $campusId) {
                $score += 1;
            }
            if ($score > $bestScore) {
                $best = $id;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
