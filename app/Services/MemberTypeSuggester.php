<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Suggests a member type from a person's age, and only ever suggests.
 *
 * Age carries real signal here — every one of the 62 people aged 40 or over
 * holds the same member type, and 44 of the 53 under 13 hold another — but it
 * is signal, not fact. Trailblazer runs from 3 to 75 on this roster and Radical
 * from 0 to 35, and the types also turn on marital status and other
 * affiliations that a birth year knows nothing about.
 *
 * Two rules follow, and both are enforced here rather than left to callers:
 *
 *  1. A suggestion is only ever offered where the member type is empty. An
 *     existing value is somebody's decision and is never second-guessed.
 *  2. Every suggestion carries the evidence behind it — how many people of
 *     that age actually hold the type — so a reviewer can see the difference
 *     between "all 62 of them" and "64 out of 85".
 *
 * Bands that are not strong enough to be worth showing return nothing at all,
 * which is more useful than a suggestion somebody has to second-guess.
 */
final class MemberTypeSuggester
{
    /** @var list<array{from:int,to:int,type:string,matching:int,total:int}> */
    private array $bands;

    private float $minimumConfidence;

    /**
     * @param list<array{from:int,to:int,type:string,matching:int,total:int}> $bands
     */
    public function __construct(array $bands = [], float $minimumConfidence = 0.7)
    {
        // Validate here rather than in fromFile(), so a band with no type is
        // dropped however the object was built. A nameless band would other-
        // wise suggest the empty string, which reads as a member type of "".
        $this->bands = array_values(array_filter(
            $bands,
            static fn (array $band): bool => trim((string) ($band['type'] ?? '')) !== '',
        ));
        $this->minimumConfidence = $minimumConfidence;
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return new self();
        }

        $bands = [];
        foreach ($decoded['bands'] ?? [] as $band) {
            if (!is_array($band)) {
                continue;
            }
            $bands[] = [
                'from' => (int) ($band['from'] ?? 0),
                'to' => (int) ($band['to'] ?? 0),
                'type' => (string) $band['type'],
                'matching' => (int) ($band['observed']['matching'] ?? 0),
                'total' => (int) ($band['observed']['total'] ?? 0),
            ];
        }

        return new self($bands, (float) ($decoded['_minimum_confidence'] ?? 0.7));
    }

    /**
     * A suggestion for someone of this age, or null when there is no useful one.
     *
     * @return array{type:string,confidence:float,matching:int,total:int,band:string}|null
     */
    public function forAge(?int $age): ?array
    {
        if ($age === null || $age < 0 || $age > 130) {
            return null;
        }

        foreach ($this->bands as $band) {
            if ($age < $band['from'] || $age > $band['to']) {
                continue;
            }
            if ($band['total'] <= 0) {
                return null;
            }
            $confidence = $band['matching'] / $band['total'];
            if ($confidence < $this->minimumConfidence) {
                // Real overlap. Saying nothing is better than sending somebody
                // to check a guess that is wrong a quarter of the time.
                return null;
            }

            return [
                'type' => $band['type'],
                'confidence' => round($confidence, 3),
                'matching' => $band['matching'],
                'total' => $band['total'],
                'band' => $band['to'] >= 130 ? $band['from'] . '+' : $band['from'] . '–' . $band['to'],
            ];
        }

        return null;
    }

    /**
     * The same, from a birth year.
     *
     * @return array{type:string,confidence:float,matching:int,total:int,band:string}|null
     */
    public function forBirthYear(?int $birthYear, ?int $today = null): ?array
    {
        // A year outside living memory is bad data, not a very old person.
        if ($birthYear === null || $birthYear < 1900) {
            return null;
        }
        $thisYear = $today ?? (int) date('Y');

        return $this->forAge($thisYear - $birthYear);
    }

    /**
     * Suggest only into a gap.
     *
     * The guard lives here so that no caller can accidentally overwrite a
     * member type somebody chose.
     *
     * @return array{type:string,confidence:float,matching:int,total:int,band:string}|null
     */
    public function forPerson(string $existingMemberType, ?int $birthYear, ?int $today = null): ?array
    {
        if (trim($existingMemberType) !== '') {
            return null;
        }

        return $this->forBirthYear($birthYear, $today);
    }
}
