<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\EventTypeAdapter;
use App\Contracts\EventTypeRepository;

final class DefaultEventTypeRepository implements EventTypeRepository
{
    public function __construct(private readonly EventTypeAdapter $adapter) {}

    public function listAll(): array
    {
        return $this->adapter->listAll();
    }

    public function find(int $typeId): ?array
    {
        return $this->adapter->find($typeId);
    }

    public function insert(array $data): int
    {
        return $this->adapter->insert($data);
    }

    public function update(int $typeId, array $data): bool
    {
        return $this->adapter->update($typeId, $data);
    }

    public function delete(int $typeId): bool
    {
        return $this->adapter->delete($typeId);
    }

    public function countEventsOfType(int $typeId): int
    {
        return $this->adapter->countEventsOfType($typeId);
    }

    public function slugExists(string $slug, ?int $exceptTypeId = null): bool
    {
        return $this->adapter->slugExists($slug, $exceptTypeId);
    }

    public function setDefault(int $typeId): bool
    {
        return $this->adapter->setDefault($typeId);
    }

    public function listOrphanedEvents(): array
    {
        return $this->adapter->listOrphanedEvents();
    }
}
