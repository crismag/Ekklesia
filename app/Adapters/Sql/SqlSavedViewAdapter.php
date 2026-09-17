<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\SavedViewRepository;
use App\Core\Database\MembersConnection;
use PDO;

/**
 * Storage for saved calendar views.
 *
 * SQL lives in adapters; the service above decides who may see what. This class
 * deliberately knows nothing about permissions — a repository that also decides
 * authorisation ends up with two places to check and one place to forget.
 */
final class SqlSavedViewAdapter implements SavedViewRepository
{
    private ?PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        try {
            $this->connection = $connection ?? MembersConnection::get();
        } catch (\Throwable) {
            // A portal without its database still renders a calendar; it just
            // has no saved views.
            $this->connection = null;
        }
    }

    /**
     * Views this user may open: their own, plus everything shared.
     *
     * @return list<array<string,mixed>>
     */
    public function listFor(int $userId): array
    {
        if ($this->connection === null) {
            return [];
        }
        $stmt = $this->connection->prepare(
            "SELECT id, name, account_id, visibility, config_version, config, updated_at
               FROM calendar_views
              WHERE account_id = :uid OR visibility = 'shared'
           ORDER BY (account_id = :uid2) DESC, name ASC"
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function find(int $viewId): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        $stmt = $this->connection->prepare(
            'SELECT id, name, account_id, visibility, config_version, config, updated_at
               FROM calendar_views WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $viewId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $config @return int the new id */
    public function create(string $name, int $ownerId, string $visibility, int $version, array $config): int
    {
        if ($this->connection === null) {
            return 0;
        }
        $stmt = $this->connection->prepare(
            'INSERT INTO calendar_views
                (name, account_id, visibility, config_version, config, created_at, updated_at)
             VALUES (:name, :owner, :vis, :ver, :cfg, NOW(), NOW())'
        );
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':owner', $ownerId, PDO::PARAM_INT);
        $stmt->bindValue(':vis', $visibility, PDO::PARAM_STR);
        $stmt->bindValue(':ver', $version, PDO::PARAM_INT);
        $stmt->bindValue(':cfg', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->connection->lastInsertId();
    }

    /** @param array<string,mixed> $config */
    public function update(int $viewId, string $name, string $visibility, int $version, array $config): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare(
            'UPDATE calendar_views
                SET name = :name, visibility = :vis, config_version = :ver, config = :cfg, updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':vis', $visibility, PDO::PARAM_STR);
        $stmt->bindValue(':ver', $version, PDO::PARAM_INT);
        $stmt->bindValue(':cfg', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);
        $stmt->bindValue(':id', $viewId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function delete(int $viewId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare('DELETE FROM calendar_views WHERE id = :id');
        $stmt->bindValue(':id', $viewId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): array
    {
        $decoded = json_decode((string) $row['config'], true);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'ownerId' => (int) $row['account_id'],
            'visibility' => (string) $row['visibility'],
            'configVersion' => (int) $row['config_version'],
            // A row whose JSON has been corrupted opens as defaults rather than
            // taking the screen down with it.
            'config' => is_array($decoded) ? $decoded : [],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }
}
