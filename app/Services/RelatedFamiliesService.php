<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\HouseholdLinkRepository;
use InvalidArgumentException;

/**
 * Related families — an admin-confirmed, presentation-only link between two
 * household records (e.g. a parents' family and a married child's family, or
 * two households sharing a residence).
 *
 * Stored in `household_links`. It does not move anyone between households: it
 * shows cross-family relationships that a single people.household_id cannot
 * express (a person belongs to only one household).
 */
final class RelatedFamiliesService
{
    /** Relationship types. Directional ones store household_id = parents. */
    public const RELS = [
        'parent_child'   => 'Parent ↔ married child',
        'extended'       => 'Extended family',
        'same_residence' => 'Same household',
    ];

    public function __construct(private readonly HouseholdLinkRepository $links) {}

    /** @return list<array{household_id:int,related_household_id:int,relationship:string}> */
    public function links(): array
    {
        return array_values(array_filter(
            $this->links->all(),
            static fn (array $l): bool => isset(self::RELS[$l['relationship']])
        ));
    }

    /**
     * Links touching a family, from that family's point of view.
     * @return list<array{other:int,relationship:string,label:string}>
     */
    public function forFamily(int $famId): array
    {
        $out = [];
        foreach ($this->links->forHousehold($famId) as $l) {
            $isA = $l['household_id'] === $famId;
            $out[] = [
                'other' => $isA ? $l['related_household_id'] : $l['household_id'],
                'relationship' => $l['relationship'],
                'label' => $this->label($l['relationship'], $isA),
            ];
        }
        return $out;
    }

    private function label(string $rel, bool $isA): string
    {
        return match ($rel) {
            'parent_child'   => $isA ? "Married child's family" : "Parents' family",
            'same_residence' => 'Same household',
            default          => 'Extended family',
        };
    }

    /**
     * Create a link. For 'parent_child', $direction ('parent'|'child') says which
     * side $from is; symmetric types ignore direction.
     */
    public function link(int $from, int $to, string $rel, string $direction = 'parent'): void
    {
        if ($from <= 0 || $to <= 0 || $from === $to) {
            throw new InvalidArgumentException('Pick a different family to link.');
        }
        if (!isset(self::RELS[$rel])) {
            throw new InvalidArgumentException('Unknown relationship.');
        }
        if ($rel === 'parent_child') {
            [$a, $b] = $direction === 'child' ? [$to, $from] : [$from, $to];
        } else {
            [$a, $b] = $from < $to ? [$from, $to] : [$to, $from];
        }
        // One link per pair: an existing pair (either way round) is replaced.
        $this->links->replace($a, $b, $rel);
    }

    public function unlink(int $x, int $y): void
    {
        $this->links->remove($x, $y);
    }

    /** Drop every link that references a family (e.g. after it's merged away). */
    public function removeFamily(int $famId): void
    {
        $this->links->removeHousehold($famId);
    }
}
