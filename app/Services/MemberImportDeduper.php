<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Collapse workbook rows that are the same person listed twice (common on Hub
 * household blocks). Happens in staging, before any write to people.
 */
final class MemberImportDeduper
{
    private const FILL_FIELDS = [
        'email', 'phone', 'address_raw', 'address_line1', 'city', 'region', 'postal_code',
        'country', 'member_type', 'ministry', 'middle_name', 'preferred_name',
        'confirmed',
    ];

    public function __construct(private MemberWorkbookParser $parser = new MemberWorkbookParser())
    {
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   removed:list<array{name:string,kept:string,reason:string}>,
     *   warnings:list<string>
     * }
     */
    public function dedupe(array $rows): array
    {
        $groups = [];
        $order = [];
        foreach ($rows as $i => $row) {
            $key = $this->identityKey($row, $i);
            if (!isset($groups[$key])) {
                $groups[$key] = [];
                $order[] = $key;
            }
            $groups[$key][] = $i;
        }

        [$groups, $order] = $this->mergeAbbreviatedNames($rows, $groups, $order);

        $kept = [];
        $removed = [];
        foreach ($order as $key) {
            $indexes = $groups[$key];
            if (count($indexes) === 1) {
                $kept[] = $rows[$indexes[0]];
                continue;
            }
            $cluster = array_map(static fn (int $i) => $rows[$i], $indexes);
            $winnerIdx = $this->winnerIndex($cluster);
            $winner = $cluster[$winnerIdx];
            $winnerName = $this->label($winner);
            foreach ($cluster as $j => $dup) {
                if ($j === $winnerIdx) {
                    continue;
                }
                $winner = $this->fillFrom($winner, $dup);
                $removed[] = [
                    'name' => $this->label($dup),
                    'kept' => $winnerName,
                    'reason' => $this->reason($dup, $winner, count($cluster)),
                ];
            }
            $winner['notes'] = trim((string) ($winner['notes'] ?? ''));
            $kept[] = $winner;
        }

        $warnings = [];
        if ($removed !== []) {
            $warnings[] = count($removed) . ' duplicate worksheet '
                . (count($removed) === 1 ? 'row was' : 'rows were')
                . ' removed before any member record update. The kept row is the one with more filled fields (email, phone, address).';
            foreach ($removed as $item) {
                $warnings[] = 'Removed duplicate: ' . $item['name'] . ' (kept ' . $item['kept'] . ').';
            }
        }

        return ['rows' => $kept, 'removed' => $removed, 'warnings' => $warnings];
    }

    /** @param array<string,mixed> $row */
    /**
     * Fold "Santos, A" into "Santos, Ana".
     *
     * Grouping by name alone splits one person entered once in full and once
     * with an initial. Two signals together make that safe: the same email
     * address AND the same surname AND one first name being a prefix of the
     * other. An initial is an abbreviation; a different name is not.
     *
     * This is deliberately narrower than the old email-only rule, which merged
     * anyone sharing a household inbox. Checked against the two real pairs that
     * were wrongly merged: different surnames fail the surname test, and
     * "Elizer" against "Editho" fails the prefix test — they share only a first
     * letter.
     *
     * @param list<array<string,mixed>>  $rows
     * @param array<string,list<int>>    $groups
     * @param list<string>               $order
     * @return array{0:array<string,list<int>>,1:list<string>}
     */
    private function mergeAbbreviatedNames(array $rows, array $groups, array $order): array
    {
        $norm = static fn (mixed $v): string => (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) $v)));

        // Describe each group once by its first row: surname, first name, email.
        $meta = [];
        foreach ($groups as $key => $indexes) {
            $first = $rows[$indexes[0]];
            $meta[$key] = [
                'last' => $norm($first['last_name'] ?? ''),
                'first' => $norm(explode(' ', trim((string) ($first['first_name'] ?? '')))[0] ?? ''),
                'email' => strtolower(trim((string) ($first['email'] ?? ''))),
            ];
        }

        $absorbed = [];
        foreach ($order as $a) {
            if (isset($absorbed[$a]) || !isset($meta[$a])) {
                continue;
            }
            foreach ($order as $b) {
                if ($a === $b || isset($absorbed[$b]) || !isset($meta[$b])) {
                    continue;
                }
                $x = $meta[$a];
                $y = $meta[$b];
                if ($x['email'] === '' || $x['email'] !== $y['email']) {
                    continue;
                }
                if ($x['last'] === '' || $x['last'] !== $y['last']) {
                    continue;
                }
                if ($x['first'] === '' || $y['first'] === '' || $x['first'] === $y['first']) {
                    continue;
                }
                $shorter = strlen($x['first']) <= strlen($y['first']) ? $x['first'] : $y['first'];
                $longer = $shorter === $x['first'] ? $y['first'] : $x['first'];
                if (!str_starts_with($longer, $shorter)) {
                    continue;
                }
                // Keep the group whose name is spelled out.
                $keep = $longer === $x['first'] ? $a : $b;
                $drop = $keep === $a ? $b : $a;
                $groups[$keep] = array_merge($groups[$keep], $groups[$drop]);
                sort($groups[$keep]);
                unset($groups[$drop]);
                $absorbed[$drop] = true;
            }
        }

        $order = array_values(array_filter($order, static fn (string $k): bool => isset($groups[$k])));

        return [$groups, $order];
    }

    public function identityKey(array $row, int $index = 0): string
    {
        // Identity is the NAME, not the email address.
        //
        // Email used to take priority over everything, so any two people
        // sharing an address were treated as the same person and merged. In a
        // church directory a household routinely shares one email — a parent's
        // address on a child's record, a couple using one inbox — so this
        // collapsed distinct members into each other. Two real examples from a
        // North York import: a mother and daughter with different first names
        // and different surnames, and two siblings sharing a surname.
        //
        // The merge is not merely a dropped row: fillFrom() copies the removed
        // person's email, phone and address onto the kept record, so the wrong
        // person's contact details end up on someone else's entry.
        //
        // Matching on the name misses the rarer case of one person entered
        // under two spellings with the same email. That direction is safe: a
        // missed merge stays visible as two staged rows an administrator can
        // reconcile, whereas a wrong merge silently deletes a member.
        $key = $this->parser->nameKey((string) ($row['last_name'] ?? ''), (string) ($row['first_name'] ?? ''));
        if ($key !== '|') {
            return 'n:' . $key;
        }

        // No usable name. Fall back to the email, then the raw name cell, and
        // finally the row position so a nameless row is never merged with
        // another nameless row.
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '') {
            return 'e:' . $email;
        }
        $raw = strtolower(trim((string) ($row['name_raw'] ?? '')));

        return $raw !== '' ? 'r:' . $raw : 'i:' . $index;
    }

    /**
     * @param list<array<string,mixed>> $cluster
     */
    private function winnerIndex(array $cluster): int
    {
        $best = 0;
        $bestScore = -1;
        foreach ($cluster as $i => $row) {
            $score = $this->score($row);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
        }
        return $best;
    }

    /** @param array<string,mixed> $row */
    private function score(array $row): int
    {
        $score = 0;
        foreach (['email', 'phone', 'address_raw', 'address_line1'] as $f) {
            if (trim((string) ($row[$f] ?? '')) !== '') {
                $score += 4;
            }
        }
        if (trim((string) ($row['member_type'] ?? '')) !== '') {
            $score += 2;
        }
        if (trim((string) ($row['confirmed'] ?? '')) !== '' && (string) $row['confirmed'] !== '0') {
            $score += 2;
        }
        if (($row['birth_month'] ?? null) || (is_array($row['birthday'] ?? null) && ($row['birthday']['month'] ?? null))) {
            $score += 2;
        }
        if (($row['source'] ?? '') === 'hub' || ($row['source'] ?? '') === 'merged') {
            $score += 1;
        }
        return $score;
    }

    /**
     * @param array<string,mixed> $winner
     * @param array<string,mixed> $dup
     * @return array<string,mixed>
     */
    private function fillFrom(array $winner, array $dup): array
    {
        foreach (self::FILL_FIELDS as $field) {
            if (trim((string) ($winner[$field] ?? '')) === '' && trim((string) ($dup[$field] ?? '')) !== '') {
                $winner[$field] = $dup[$field];
            }
        }
        // Prefer the fuller spelling of a name. The winner is chosen by how many
        // fields it fills, so a row carrying an initial plus a phone number can
        // outscore the row that spells the name out — and an import must never
        // shorten a member to "I". Applies to a genuine expansion only: the
        // kept name must be a prefix of the other.
        foreach (['first_name', 'last_name'] as $nameField) {
            $have = trim((string) ($winner[$nameField] ?? ''));
            $other = trim((string) ($dup[$nameField] ?? ''));
            if ($have === '' || $other === '' || $have === $other) {
                continue;
            }
            // Lowercase BEFORE stripping: the character class is a-z, so an
            // uppercase initial like "I" is otherwise deleted outright and the
            // comparison silently comes up empty.
            $a = (string) preg_replace('/[^a-z0-9]+/', '', strtolower($have));
            $b = (string) preg_replace('/[^a-z0-9]+/', '', strtolower($other));
            if ($a !== '' && $b !== '' && strlen($b) > strlen($a) && str_starts_with($b, $a)) {
                $winner[$nameField] = $other;
            }
        }

        if (($winner['birth_month'] ?? null) === null || (int) $winner['birth_month'] === 0) {
            if ((int) ($dup['birth_month'] ?? 0) > 0) {
                $winner['birth_year'] = $dup['birth_year'] ?? null;
                $winner['birth_month'] = $dup['birth_month'] ?? null;
                $winner['birth_day'] = $dup['birth_day'] ?? null;
            }
        }
        return $winner;
    }

    /** @param array<string,mixed> $row */
    private function label(array $row): string
    {
        $raw = trim((string) ($row['name_raw'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }
        $last = trim((string) ($row['last_name'] ?? ''));
        $first = trim((string) ($row['first_name'] ?? ''));
        $line = (int) ($row['line'] ?? 0);
        $name = $last . ($first !== '' ? ', ' . $first : '');
        return $line > 0 ? $name . ' (row ' . $line . ')' : $name;
    }

    /** @param array<string,mixed> $dup
     * @param array<string,mixed> $kept */
    private function reason(array $dup, array $kept, int $count): string
    {
        return 'Same person listed ' . $count . ' times on the worksheet; kept the row with more contact/address data.';
    }
}
