<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Suggests household roles — husband, wife, child — for the households where
 * the answer is not in doubt.
 *
 * Kinship cannot be derived in general; that is the finding in
 * docs/design/27-family-model.md, and it is why 292 of 306 people have no
 * family role recorded. But a subset is unambiguous, and clearing that subset
 * is what makes entering the rest bearable:
 *
 *   one address, one surname, exactly two adults of different sex
 *     → those two are a couple
 *     → anyone else in the household who is a child by member type is theirs
 *
 * Every condition is a guard rather than a preference. Three adults could be a
 * couple plus a lodger or three siblings; two adults of the same sex could be
 * siblings or flatmates; a second surname could be a partner who kept her name
 * or a boarder. Where the household does not answer plainly, this says nothing,
 * which is the only honest thing it can do.
 *
 * Suggestions, never writes. The caller decides whether to apply them, and an
 * existing role is never contradicted.
 */
final class HouseholdRoleSuggester
{
    public const MALE = 1;
    public const FEMALE = 2;

    public const HUSBAND = 'Husband';
    public const WIFE = 'Wife';
    public const CHILD = 'Child';

    /**
     * Years between one generation and the next, for rule D.
     *
     * Not an age test on anybody — it is the distance between people living
     * together, which is the one thing a birth year can establish about a
     * household.
     *
     * Fifteen rather than eighteen because eighteen missed a real family by a
     * year: parents born 1988 and 1989 with a son born 2006. A parent of
     * seventeen is unusual; a threshold that quietly drops the household is
     * worse, because nothing then says anything at all.
     */
    private const GENERATION_YEARS = 15;

    /**
     * The most the two oldest may differ and still read as a couple.
     *
     * This is what keeps the threshold above honest. Two people fifteen years
     * apart with someone fifteen years below them is not a generational break,
     * it is a continuum — three siblings, or a parent and two children. A
     * couple is normally close in age, and requiring that makes the gap beneath
     * them mean something.
     */
    private const COUPLE_AGE_SPREAD = 15;

    /**
     * @param list<string> $childMemberTypes member types that mean "a child",
     *        supplied rather than hardcoded because they are administrator-
     *        edited. On this roster that is G&A.
     */
    public function __construct(private readonly array $childMemberTypes = ['G&A'])
    {
    }

    /** The household says so plainly; apply without asking. */
    public const CERTAIN = 'certain';

    /** The likeliest reading, but a person should confirm it. */
    public const LIKELY = 'likely';

    /**
     * Roles this household implies, strongest reading first.
     *
     * Four rules, tried in order, each narrower than the household it needs:
     *
     *   A  one surname, two adults of different sex        → couple, certain
     *   B  surnames differ, two adults of different sex,
     *      children present                                → couple, likely
     *   C  any adult present, someone recorded as a child  → child, certain
     *   D  three or more adults, the two oldest are of
     *      different sex with a generation between them
     *      and the rest share their surname                → couple + adult
     *                                                        children, likely
     *
     * Rule C is separate on purpose. Being a child *of this household* does not
     * depend on knowing which two adults are the parents, so it still applies
     * where the couple cannot be worked out at all.
     *
     * Rule B is the case reported as a problem: a wife who kept her name. Two
     * adults of different sex at one address could be flatmates, so it asks for
     * children in the household before saying anything — which is why it is
     * likely and not certain.
     *
     * @param list<array{person_id:int,last_name:string,gender:int,member_type:string,role:string,birth_year?:int}> $members
     * @return array{suggestions:list<array{person_id:int,role:string,because:string,confidence:string,rule:string}>,skipped:?string}
     */
    public function forHousehold(array $members): array
    {
        $none = static fn (string $why): array => ['suggestions' => [], 'skipped' => $why];

        if (count($members) < 2) {
            return $none('only one person lives here');
        }

        $children = [];
        $adults = [];
        foreach ($members as $m) {
            if ($this->isChildType((string) ($m['member_type'] ?? ''))) {
                $children[] = $m;
            } else {
                $adults[] = $m;
            }
        }

        $suggestions = [];
        $oneSurname = count($this->surnames($members)) === 1;
        $couple = $this->couple($adults);

        if ($couple !== null && $oneSurname) {
            // A
            $this->proposeCouple($suggestions, $couple, self::CERTAIN, 'A',
                'the only two adults at this address, sharing a surname');
        } elseif ($couple !== null && $children !== []) {
            // B — surnames differ. Children in the household are what separates
            // a couple from two people sharing a flat.
            $this->proposeCouple($suggestions, $couple, self::LIKELY, 'B',
                'the only two adults at this address, with children here, though their surnames differ');
        } elseif (count($adults) >= 3) {
            // D
            $elders = $this->eldestCoupleWithGap($adults);
            if ($elders !== null) {
                $this->proposeCouple($suggestions, $elders['couple'], self::LIKELY, 'D',
                    'the two oldest adults here, a generation above the others, who share their surname');
                foreach ($elders['younger'] as $young) {
                    $this->propose($suggestions, $young, self::CHILD, self::LIKELY, 'D',
                        'a generation younger than them and sharing their surname');
                }
            }
        }

        // C — always, whatever happened above.
        if ($adults !== []) {
            foreach ($children as $child) {
                $this->propose($suggestions, $child, self::CHILD, self::CERTAIN, 'C',
                    'lives with adults here and is recorded as ' . trim((string) $child['member_type']));
            }
        }

        if ($suggestions !== []) {
            return ['suggestions' => $suggestions, 'skipped' => null];
        }

        return $none($this->whyNothing($members, $adults, $children, $couple, $oneSurname));
    }

    /**
     * The two adults, when there are exactly two and they are a man and a woman.
     *
     * @param list<array<string,mixed>> $adults
     * @return array{0:array<string,mixed>,1:array<string,mixed>}|null husband, wife
     */
    private function couple(array $adults): ?array
    {
        if (count($adults) !== 2) {
            return null;
        }
        $byGender = [];
        foreach ($adults as $adult) {
            $byGender[(int) ($adult['gender'] ?? 0)][] = $adult;
        }
        if (count($byGender[self::MALE] ?? []) !== 1 || count($byGender[self::FEMALE] ?? []) !== 1) {
            return null;
        }

        return [$byGender[self::MALE][0], $byGender[self::FEMALE][0]];
    }

    /**
     * The oldest two adults, when a generation separates them from the rest.
     *
     * Age is used only for the distance between people in one household, which
     * a birth year does establish. It says nothing about which member type
     * anyone holds — those overlap on age and are not a proxy for it.
     *
     * @param list<array<string,mixed>> $adults
     * @return array{couple:array{0:array<string,mixed>,1:array<string,mixed>},younger:list<array<string,mixed>>}|null
     */
    private function eldestCoupleWithGap(array $adults): ?array
    {
        foreach ($adults as $adult) {
            if ((int) ($adult['birth_year'] ?? 0) <= 1900) {
                return null;   // one unknown age and the ordering means nothing
            }
        }
        usort($adults, static fn (array $a, array $b): int => (int) $a['birth_year'] <=> (int) $b['birth_year']);

        $elders = [$adults[0], $adults[1]];
        $younger = array_slice($adults, 2);
        if ($younger === []) {
            return null;
        }
        // The two oldest must look like one generation...
        if ((int) $elders[1]['birth_year'] - (int) $elders[0]['birth_year'] > self::COUPLE_AGE_SPREAD) {
            return null;
        }
        // ...and the next person must be clearly below it.
        if ((int) $younger[0]['birth_year'] - (int) $elders[1]['birth_year'] < self::GENERATION_YEARS) {
            return null;
        }

        $couple = $this->couple($elders);
        if ($couple === null) {
            return null;
        }

        // Only those carrying the couple's surname are their children. A lodger
        // of the right age is not.
        $elderNames = $this->surnames($elders);
        $kin = [];
        foreach ($younger as $person) {
            if (array_intersect($this->surnames([$person]), $elderNames) !== []) {
                $kin[] = $person;
            }
        }

        return ['couple' => $couple, 'younger' => $kin];
    }

    /** @param list<array<string,mixed>> $people @return list<string> */
    private function surnames(array $people): array
    {
        $out = [];
        foreach ($people as $person) {
            $key = strtolower((string) preg_replace('/[^a-z]/i', '', (string) ($person['last_name'] ?? '')));
            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param list<array<string,mixed>> $into
     * @param array{0:array<string,mixed>,1:array<string,mixed>} $couple
     */
    private function proposeCouple(array &$into, array $couple, string $confidence, string $rule, string $because): void
    {
        $this->propose($into, $couple[0], self::HUSBAND, $confidence, $rule, $because);
        $this->propose($into, $couple[1], self::WIFE, $confidence, $rule, $because);
    }

    /**
     * @param list<array<string,mixed>> $members
     * @param list<array<string,mixed>> $adults
     * @param list<array<string,mixed>> $children
     * @param array{0:array<string,mixed>,1:array<string,mixed>}|null $couple
     */
    private function whyNothing(array $members, array $adults, array $children, ?array $couple, bool $oneSurname): string
    {
        $unroled = static fn (array $people): int => count(array_filter(
            $people,
            static fn (array $m): bool => trim((string) ($m['role'] ?? '')) === '',
        ));
        if ($unroled($members) === 0) {
            return 'every role here is already recorded';
        }
        if ($adults === []) {
            return 'nobody here is an adult';
        }
        if ($couple === null && count($adults) === 2) {
            return 'the two adults are not recorded as one man and one woman';
        }
        if ($couple === null && count($adults) > 2) {
            return count($adults) . ' adults here, and no clear generation between them';
        }
        if ($couple !== null && !$oneSurname && $children === []) {
            return 'two adults with different surnames and no children here — could be a couple, could be flatmates';
        }

        return 'nothing here settles who is who';
    }

    /**
     * Add a suggestion unless the person already has a role.
     *
     * A role somebody entered is a decision, and this has far less information
     * than they did.
     *
     * @param list<array{person_id:int,role:string,because:string}> $into
     * @param array<string,mixed> $member
     */
    private function propose(
        array &$into,
        array $member,
        string $role,
        string $confidence,
        string $rule,
        string $because,
    ): void {
        if (trim((string) ($member['role'] ?? '')) !== '') {
            return;
        }
        foreach ($into as $existing) {
            if ($existing['person_id'] === (int) $member['person_id']) {
                return;   // an earlier, stronger rule already spoke for them
            }
        }
        $into[] = [
            'person_id' => (int) $member['person_id'],
            'role' => $role,
            'confidence' => $confidence,
            'rule' => $rule,
            'because' => $because,
        ];
    }

    private function isChildType(string $memberType): bool
    {
        $key = static fn (string $v): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $v));
        foreach ($this->childMemberTypes as $childType) {
            if ($key($memberType) !== '' && $key($memberType) === $key($childType)) {
                return true;
            }
        }

        return false;
    }
}
