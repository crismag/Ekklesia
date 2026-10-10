<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The "who is acting and what may they touch" context, passed into every
 * service call. Immutable; refinements (e.g. switching the current campus)
 * produce a new ActorContext via withCurrentCampusId().
 *
 * Scope semantics:
 *   - isPortalWideAdmin = true   → bypasses ministry/campus scope checks.
 *                                  Only set for an 'admin' role row with
 *                                  scope_campus_id IS NULL AND scope_ministry_id IS NULL.
 *   - ministryScopeIds[]         → ministries the actor may touch.
 *   - campusScopeIds[]           → campuses the actor may touch.
 *   - currentCampusId            → singular campus context when exactly one campus
 *                                  filter is active; null otherwise.
 *   - currentCampusIds[]         → active campus filter list for this request.
 *                                  Empty list = no specific campus filter.
 */
final readonly class ActorContext
{
    /**
     * @param list<PortalPermission> $permissions
     * @param list<int>              $ministryScopeIds
     * @param list<int>              $campusScopeIds
     */
    public function __construct(
        public int $actorId,
        public ?int $personId,
        public ?string $displayName,
        public array $permissions,
        public array $ministryScopeIds,
        public bool $isPortalWideAdmin = false,
        public array $campusScopeIds = [],
        public ?int $currentCampusId = null,
        public array $currentCampusIds = [],
    ) {
    }

    public function hasPermission(PortalPermission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function canAccessMinistry(int $ministryId): bool
    {
        if ($this->isPortalWideAdmin) {
            return true;
        }
        return in_array($ministryId, $this->ministryScopeIds, true);
    }

    public function canAccessCampus(int $campusId): bool
    {
        if ($this->isPortalWideAdmin) {
            return true;
        }
        // Empty campus scope = no campus restriction asserted by role rows.
        // Services that require a campus filter should consult currentCampusId
        // separately rather than treating empty scope as "all campuses".
        if ($this->campusScopeIds === []) {
            return true;
        }
        return in_array($campusId, $this->campusScopeIds, true);
    }

    public function withCurrentCampusId(?int $campusId): self
    {
        return new self(
            actorId: $this->actorId,
            personId: $this->personId,
            displayName: $this->displayName,
            permissions: $this->permissions,
            ministryScopeIds: $this->ministryScopeIds,
            isPortalWideAdmin: $this->isPortalWideAdmin,
            campusScopeIds: $this->campusScopeIds,
            currentCampusId: $campusId,
            currentCampusIds: $campusId === null ? [] : [$campusId],
        );
    }

    /**
     * @param list<int> $campusIds
     */
    public function withCurrentCampusIds(array $campusIds): self
    {
        $normalized = array_values(array_unique(array_map(
            static fn (int|string $campusId): int => (int) $campusId,
            array_filter(
                $campusIds,
                static fn (mixed $campusId): bool => (int) $campusId > 0,
            ),
        )));

        return new self(
            actorId: $this->actorId,
            personId: $this->personId,
            displayName: $this->displayName,
            permissions: $this->permissions,
            ministryScopeIds: $this->ministryScopeIds,
            isPortalWideAdmin: $this->isPortalWideAdmin,
            campusScopeIds: $this->campusScopeIds,
            currentCampusId: count($normalized) === 1 ? $normalized[0] : null,
            currentCampusIds: $normalized,
        );
    }
}
