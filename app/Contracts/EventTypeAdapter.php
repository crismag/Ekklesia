<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Persistence for event types (ChurchCRM's event_types table, extended with
 * portal_* columns by migration 009).
 *
 * ChurchCRM's own EditEventTypes.php writes this table too, so rows can appear
 * or vanish without the portal's involvement. Implementations must tolerate a
 * row that carries no portal_* values at all — that is a ChurchCRM-only type,
 * not corruption.
 */
interface EventTypeAdapter
{
    /** @return list<array<string,mixed>> every row, portal-managed or not */
    public function listAll(): array;

    /** @return array<string,mixed>|null */
    public function find(int $typeId): ?array;

    /** @param array<string,mixed> $data @return int the new type_id */
    public function insert(array $data): int;

    /** @param array<string,mixed> $data */
    public function update(int $typeId, array $data): bool;

    public function delete(int $typeId): bool;

    /** How many events reference this type. Guards the delete. */
    public function countEventsOfType(int $typeId): int;

    public function slugExists(string $slug, ?int $exceptTypeId = null): bool;

    /**
     * Clear portal_is_default everywhere, then set it on one row, atomically.
     * Two defaults would make the create form's preselection arbitrary.
     */
    public function setDefault(int $typeId): bool;

    /**
     * Events whose event_type matches no row.
     *
     * ChurchCRM can delete a type the portal created; its events then fall back
     * to the 'members' audience, so a leaders-only event would quietly become
     * visible. This is what lets the admin screen say so out loud.
     *
     * @return list<array{event_id:int,event_title:string,event_type:int}>
     */
    public function listOrphanedEvents(): array;
}
