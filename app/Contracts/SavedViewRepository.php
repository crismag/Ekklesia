<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Storage for saved calendar views.
 *
 * An interface for the same reason every other repository here has one: the
 * rules about who may edit a shared view are worth testing, and they cannot be
 * tested against a class that opens a database connection in its constructor.
 */
interface SavedViewRepository
{
    /**
     * Views this user may open: their own, plus everything shared.
     *
     * @return list<array<string,mixed>>
     */
    public function listFor(int $userId): array;

    /** @return array<string,mixed>|null */
    public function find(int $viewId): ?array;

    /** @param array<string,mixed> $config @return int the new id */
    public function create(string $name, int $ownerId, string $visibility, int $version, array $config): int;

    /** @param array<string,mixed> $config */
    public function update(int $viewId, string $name, string $visibility, int $version, array $config): bool;

    public function delete(int $viewId): bool;
}
