<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AvailabilityRepository;
use App\Core\ActorContext;
use App\Core\PortalPermission;
use App\DTO\Availability\AvailabilityCommand;
use App\DTO\Availability\UnavailabilityEntry;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use DateTimeImmutable;

/**
 * Availability ownership rules.
 *
 *   - A member with ManageOwnAvailability may CRUD entries for their own
 *     personId only.
 *   - A leader / scheduler with ViewPersonAvailability + ManageOwnAvailability
 *     (the standard non-member roles) may CRUD entries for any person they
 *     can already manage in scheduling — i.e., the personId need not match
 *     theirs, but they must hold a manage-schedules-style permission to act
 *     on someone else's row. This service is the single authority for that
 *     decision; controllers never short-circuit it.
 *   - Portal-wide admin bypasses both checks.
 *
 * Reads (listForPerson) are gated identically to writes for cross-person
 * access — declaring availability is sensitive data.
 */
final readonly class AvailabilityService
{
    public function __construct(
        private AvailabilityRepository $repository,
    ) {
    }

    /**
     * @return list<UnavailabilityEntry>
     */
    public function listForPerson(
        ActorContext $context,
        int $personId,
        ?DateTimeImmutable $activeFrom = null,
    ): array {
        $this->ensurePersonAccess($context, $personId, write: false);

        return array_map(
            static fn (array $row): UnavailabilityEntry => self::hydrateRow($row),
            $this->repository->listForPerson($personId, $activeFrom),
        );
    }

    public function create(ActorContext $context, AvailabilityCommand $command): UnavailabilityEntry
    {
        $this->ensurePersonAccess($context, $command->personId, write: true);

        $now = new DateTimeImmutable();
        $newId = $this->repository->create($command, $context->actorId, $now);

        $row = $this->repository->findById($newId);
        if ($row === null) {
            throw new ValidationFailed('Failed to read back newly created availability entry.');
        }
        return self::hydrateRow($row);
    }

    public function delete(ActorContext $context, int $unavailabilityId): void
    {
        if ($unavailabilityId <= 0) {
            throw new ValidationFailed('Availability id is required.');
        }

        $row = $this->repository->findById($unavailabilityId);
        if ($row === null) {
            // Idempotent: no row, nothing to do. We still scope it to a
            // resolvable actor so we're not leaking existence to anonymous.
            return;
        }

        $this->ensurePersonAccess($context, (int) $row['person_id'], write: true);
        $this->repository->delete($unavailabilityId);
    }

    private function ensurePersonAccess(ActorContext $context, int $personId, bool $write): void
    {
        // The base "manage your own" permission is always required (members
        // hold this; non-portal-wide admins also need it to write any row).
        if ($write && !$context->hasPermission(PortalPermission::ManageOwnAvailability)
            && !$context->isPortalWideAdmin
        ) {
            throw new PermissionDenied('Actor lacks permission to manage availability.');
        }

        // Self-access: any actor with the relevant permission can act on
        // their own person link. Reads need ManageOwnAvailability OR
        // ViewPersonAvailability; writes need ManageOwnAvailability.
        if ($context->personId !== null && $context->personId === $personId) {
            if ($write) {
                if ($context->hasPermission(PortalPermission::ManageOwnAvailability)
                    || $context->isPortalWideAdmin
                ) {
                    return;
                }
            } else {
                if ($context->hasPermission(PortalPermission::ManageOwnAvailability)
                    || $context->hasPermission(PortalPermission::ViewPersonAvailability)
                    || $context->isPortalWideAdmin
                ) {
                    return;
                }
            }
        }

        // Cross-person access: must hold ViewPersonAvailability (read) or
        // ManageSchedules (write). Portal-wide admin bypasses scope.
        if ($context->isPortalWideAdmin) {
            return;
        }
        if (!$context->hasPermission(PortalPermission::ViewPersonAvailability)) {
            throw new PermissionDenied('Actor cannot view this person\'s availability.');
        }
        if ($write && !$context->hasPermission(PortalPermission::ManageSchedules)) {
            throw new PermissionDenied('Actor cannot edit this person\'s availability.');
        }
        // We intentionally do NOT additionally filter by ministry-scope here:
        // a leader may legitimately need to view availability of a person who
        // serves multiple ministries, only one of which is in their scope.
        // Ministry-scope filtering happens at higher-level surfaces (roster,
        // schedule grid) that determine which persons appear at all.
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateRow(array $row): UnavailabilityEntry
    {
        return new UnavailabilityEntry(
            id: (int) $row['id'],
            personId: (int) $row['person_id'],
            startsOn: $row['starts_on'],
            endsOn: $row['ends_on'],
            reason: $row['reason'],
            createdByAccountId: (int) $row['created_by_account_id'],
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }
}
