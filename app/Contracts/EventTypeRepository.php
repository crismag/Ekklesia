<?php

declare(strict_types=1);

namespace App\Contracts;

/** Source-agnostic mirror of EventTypeAdapter. See that contract for the notes. */
interface EventTypeRepository
{
    /** @return list<array<string,mixed>> */
    public function listAll(): array;

    /** @return array<string,mixed>|null */
    public function find(int $typeId): ?array;

    /** @param array<string,mixed> $data */
    public function insert(array $data): int;

    /** @param array<string,mixed> $data */
    public function update(int $typeId, array $data): bool;

    public function delete(int $typeId): bool;

    public function countEventsOfType(int $typeId): int;

    public function slugExists(string $slug, ?int $exceptTypeId = null): bool;

    public function setDefault(int $typeId): bool;

    /** @return list<array{event_id:int,event_title:string,event_type:int}> */
    public function listOrphanedEvents(): array;
}
