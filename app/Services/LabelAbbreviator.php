<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Short codes for a small set of labels, guaranteed distinct within the set.
 *
 * The people directory shows a classification and a member type on every row.
 * Spelled out — "Regular Attender", "Sunday School Class" — those two columns
 * take more width than the name does, and pushed the Edit button off the right
 * edge of the screen.
 *
 * Shortening them by hand is not an option: both lists are edited by
 * administrators, so any fixed table of abbreviations would go stale the moment
 * somebody adds a member type. The codes are therefore derived, and derived
 * from the whole set at once so that two labels can never end up sharing one.
 *
 * A code is never the only thing shown. Every badge carries the full label for
 * hover and for assistive technology, and the page prints a key. An
 * abbreviation nobody can expand is just a smaller way of hiding the data.
 */
final class LabelAbbreviator
{
    private const MAX_LENGTH = 3;

    /**
     * @param list<string> $labels
     * @return array<string,string> label => code, in the order given
     */
    public function map(array $labels): array
    {
        $unique = [];
        foreach ($labels as $label) {
            $label = trim($label);
            if ($label !== '') {
                $unique[$label] = true;
            }
        }

        $out = [];
        $taken = [];
        foreach (array_keys($unique) as $label) {
            $code = $this->code($label, $taken);
            $out[$label] = $code;
            $taken[strtoupper($code)] = true;
        }

        return $out;
    }

    /**
     * @param array<string,bool> $taken
     */
    private function code(string $label, array $taken): string
    {
        foreach ($this->candidates($label) as $candidate) {
            if (!isset($taken[strtoupper($candidate)])) {
                return $candidate;
            }
        }

        // Every shape collided. Fall back to numbering rather than returning a
        // duplicate, which would make two different things look identical.
        $base = $this->candidates($label)[0] ?? 'X';
        for ($n = 2; $n < 99; $n++) {
            $candidate = substr($base, 0, self::MAX_LENGTH - 1) . $n;
            if (!isset($taken[strtoupper($candidate)])) {
                return $candidate;
            }
        }

        return $base;
    }

    /**
     * Shapes to try, best first.
     *
     * @return list<string>
     */
    private function candidates(string $label): array
    {
        $words = $this->words($label);
        if ($words === []) {
            return ['?'];
        }

        $out = [];

        // A label already short enough to sit in the column keeps its own
        // spelling. "G&A" is what people call it; "GA" would be a worse name
        // for the same thing.
        $trimmed = trim($label);
        if (mb_strlen($trimmed) <= self::MAX_LENGTH) {
            $out[] = $trimmed;
        }

        if (count($words) === 1) {
            // One word: initials would give a single letter, and single letters
            // collide constantly — Member and Ministry are both "M". Two
            // letters reads better and separates them.
            $word = $words[0];
            $out[] = $this->titleCase(mb_substr($word, 0, 2));
            $out[] = $this->titleCase(mb_substr($word, 0, 3));
            $out[] = mb_strtoupper(mb_substr($word, 0, 1)) . mb_strtoupper(mb_substr($word, 2, 1));
        } else {
            $initials = '';
            foreach ($words as $word) {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            }
            $out[] = mb_substr($initials, 0, self::MAX_LENGTH);
            if (mb_strlen($initials) > self::MAX_LENGTH) {
                $out[] = mb_substr($initials, 0, self::MAX_LENGTH - 1) . mb_substr($initials, -1);
            }
            // Then widen the first word, which is usually the distinguishing one.
            $out[] = mb_strtoupper(mb_substr($words[0], 0, 2)) . mb_strtoupper(mb_substr($words[1], 0, 1));
        }

        $seen = [];
        $final = [];
        foreach ($out as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && !isset($seen[$candidate])) {
                $seen[$candidate] = true;
                $final[] = $candidate;
            }
        }

        return $final;
    }

    /**
     * Words that carry meaning.
     *
     * "and", "of" and "the" are dropped so "Gifts and Arrows" reads GA rather
     * than GAA — unless dropping them would leave nothing.
     *
     * The split happens on whitespace first, and only then on punctuation.
     * That distinction matters: splitting "G&A" on punctuation straight away
     * produces the token "A", which the stop list then removed, leaving the
     * ministry abbreviated to "G".
     *
     * @return list<string>
     */
    private function words(string $label): array
    {
        $spaced = preg_split('/\s+/u', trim($label), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        foreach ($spaced as $word) {
            $bare = preg_replace('/[^\p{L}\p{N}]+/u', '', $word) ?? $word;
            if ($bare !== '' && in_array(mb_strtolower($bare), ['and', 'of', 'the', 'a', 'for'], true)) {
                continue;
            }
            $kept[] = $word;
        }
        if ($kept === []) {
            $kept = $spaced;
        }

        // Now break the surviving words on punctuation: "G&A" is two initials.
        $parts = [];
        foreach ($kept as $word) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
                $parts[] = $piece;
            }
        }

        return $parts;
    }

    private function titleCase(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_strtolower(mb_substr($value, 1));
    }
}
