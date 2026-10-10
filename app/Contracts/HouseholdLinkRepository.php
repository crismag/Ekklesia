<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Admin-confirmed links between two households (household_links).
 *
 * Storage only: which side is the parents' family and how a pair is ordered is
 * decided by RelatedFamiliesService before anything reaches here.
 */
interface HouseholdLinkRepository
{
    /** @return list<array{household_id:int,related_household_id:int,relationship:string}> */
    public function all(): array;

    /** @return list<array{household_id:int,related_household_id:int,relationship:string}> links on either side */
    public function forHousehold(int $householdId): array;

    /** Store a link, replacing any existing link between the two (either way round). */
    public function replace(int $householdId, int $relatedHouseholdId, string $relationship): void;

    /** Remove the link between two households, whichever way round it was stored. */
    public function remove(int $x, int $y): void;

    /** Remove every link that touches a household. */
    public function removeHousehold(int $householdId): void;
}
