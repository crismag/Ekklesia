<?php

declare(strict_types=1);

namespace App\Services;

/**
 * How much a group of family records actually looks like one family.
 *
 * The duplicates page grouped families by surname and offered every collision
 * as a possible duplicate. Twenty-seven surnames on this roster belong to more
 * than one family, covering 62 of 157 — a page that opens with 27 candidates
 * and no way to tell them apart is how the real one gets missed.
 *
 * Sharing an address is not evidence either, and in the opposite direction:
 * seventeen addresses here hold more than one family, and adult children, a
 * lodger and a hosted parent are all ordinary. Two families at one address is
 * usually correct.
 *
 * What counts is agreement between things that would not agree by chance. And
 * whatever put the families in the group never counts: families grouped by
 * address share an address by construction, so saying so would rank every
 * group as though something had agreed.
 *
 * Pure, and separate from the query that feeds it, so the judgement can be
 * tested without a database.
 */
final class FamilyDuplicateEvidence
{
    /** The same people, near enough to merge on sight. */
    public const LIKELY = 2;

    /** One other thing agrees; worth opening. */
    public const WORTH_A_LOOK = 1;

    /** They share only what they were grouped by, which is not a signal. */
    public const NO_SIGNAL = 0;

    /** Edits between two surnames that still read as one name typed twice. */
    private const MAX_TYPO_EDITS = 2;

    /** Below this length, two edits is a different name rather than a slip. */
    private const MIN_NAME_LENGTH_FOR_TYPO = 6;

    /**
     * @param list<array<string,mixed>> $families each with addr, email, name
     * @param 'surname'|'address' $groupedBy
     * @param list<string> $sharedMemberNames people appearing in every family
     * @return array{rank:int,evidence:list<string>}
     */
    public function assess(array $families, string $groupedBy, array $sharedMemberNames = []): array
    {
        if (count($families) < 2) {
            return ['rank' => self::NO_SIGNAL, 'evidence' => []];
        }

        $evidence = [];
        if ($groupedBy !== 'address' && $this->allShare($families, 'addr')) {
            $evidence[] = 'the same address';
        }
        if ($this->allShare($families, 'email')) {
            $evidence[] = 'the same email address';
        }
        if ($groupedBy !== 'surname' && $this->allShare($families, 'name')) {
            $evidence[] = 'the same surname';
        } elseif ($groupedBy !== 'surname' && ($typo = $this->nearIdenticalSurnames($families)) !== null) {
            // One household was split in two because a daughter's surname was
            // typed without an "n". Both records sat at the same address with
            // the same postcode, and the page filed them under "share an
            // address and nothing else" — because a typo is not "the same
            // surname", which is exactly when a person most needs telling.
            $evidence[] = 'surnames a typo apart (' . $typo . ')';
        }
        if ($sharedMemberNames !== []) {
            $evidence[] = count($sharedMemberNames) === 1
                ? 'a member of the same name in each (' . $sharedMemberNames[0] . ')'
                : count($sharedMemberNames) . ' members of the same name in each';
        }

        // The same person in both, plus something else agreeing, is as close to
        // certain as this gets without opening the records.
        $rank = self::NO_SIGNAL;
        if ($sharedMemberNames !== [] && count($evidence) > 1) {
            $rank = self::LIKELY;
        } elseif ($evidence !== []) {
            $rank = self::WORTH_A_LOOK;
        }

        return ['rank' => $rank, 'evidence' => $evidence];
    }

    /**
     * Surnames that differ by little enough to be one name typed twice.
     *
     * Two edits at most, and only on names long enough for that to mean
     * something: at four letters, two edits is a different name.
     *
     * @param list<array<string,mixed>> $families
     */
    private function nearIdenticalSurnames(array $families): ?string
    {
        $names = [];
        foreach ($families as $family) {
            $name = strtolower((string) preg_replace('/[^a-z]/i', '', (string) ($family['name'] ?? '')));
            if ($name === '') {
                return null;
            }
            $names[$name] = (string) $family['name'];
        }
        if (count($names) !== 2) {
            return null;   // a pair is the only shape a typo takes
        }

        $keys = array_keys($names);
        $distance = levenshtein($keys[0], $keys[1]);
        $longest = max(strlen($keys[0]), strlen($keys[1]));
        if ($distance === 0 || $distance > self::MAX_TYPO_EDITS || $longest < self::MIN_NAME_LENGTH_FOR_TYPO) {
            return null;
        }

        return implode(' / ', array_values($names));
    }

    /**
     * True when every family has this field and they all agree.
     *
     * "Every" matters: two of three families sharing an address says nothing
     * about the third, and treating it as agreement would merge a record that
     * has nothing to do with the others.
     *
     * @param list<array<string,mixed>> $families
     */
    private function allShare(array $families, string $field): bool
    {
        $values = [];
        foreach ($families as $family) {
            $value = (string) preg_replace('/[^a-z0-9]/i', '', (string) ($family[$field] ?? ''));
            if (trim($value) === '') {
                return false;
            }
            $values[strtolower($value)] = true;
        }

        return count($values) === 1;
    }
}
