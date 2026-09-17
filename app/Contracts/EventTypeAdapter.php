<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Persistence for event types (event_types). Rows are keyed id and carry
 * slug, name, audience, color, sort_order, is_default and is_active.
 */
interface EventTypeAdapter
{
    /** @return list<array<string,mixed>> every row */
    public function listAll(): array;

    /** @return array<string,mixed>|null */
    public function find(int $typeId): ?array;

    /** @param array<string,mixed> $data @return int the new id */
    public function insert(array $data): int;

    /** @param array<string,mixed> $data */
    public function update(int $typeId, array $data): bool;

    public function delete(int $typeId): bool;

    /** How many events reference this type. Guards the delete. */
    public function countEventsOfType(int $typeId): int;

    public function slugExists(string $slug, ?int $exceptTypeId = null): bool;

    /**
     * Clear is_default everywhere, then set it on one row, atomically.
     * Two defaults would make the create form's preselection arbitrary.
     */
    public function setDefault(int $typeId): bool;

    /**
     * Events whose event_type_id matches no row.
     *
     * Such events fall back to the 'members' audience, so a leaders-only event
     * would quietly become visible. This is what lets the admin screen say so
     * out loud.
     *
     * @return list<array{event_id:int,title:string,event_type_id:int}>
     */
    public function listOrphanedEvents(): array;
}
