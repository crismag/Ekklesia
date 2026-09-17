<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\EventTypeAdapter;
use PDO;

/**
 * event_types: the categories that drive calendar layers and decide who may
 * read an event. Every row has a slug, so every row is a calendar layer.
 */
final class SqlEventTypeAdapter implements EventTypeAdapter
{
    public function __construct(private readonly ?PDO $connection = null) {}

    public function listAll(): array
    {
        if ($this->connection === null) {
            return [];
        }
        $stmt = $this->connection->query(
            'SELECT t.id, t.slug, t.name, t.audience, t.color, t.sort_order, t.is_default, t.is_active,
                    (SELECT COUNT(*) FROM events e WHERE e.event_type_id = t.id) AS usage_count
               FROM event_types t
           ORDER BY t.sort_order ASC, t.name ASC'
        );

        return array_map(
            static fn (array $r): array => self::hydrate($r),
            $stmt?->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function find(int $typeId): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        $stmt = $this->connection->prepare(
            'SELECT t.id, t.slug, t.name, t.audience, t.color, t.sort_order, t.is_default, t.is_active,
                    (SELECT COUNT(*) FROM events e WHERE e.event_type_id = t.id) AS usage_count
               FROM event_types t
              WHERE t.id = :id
              LIMIT 1'
        );
        $stmt->bindValue(':id', $typeId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function insert(array $data): int
    {
        if ($this->connection === null) {
            return 0;
        }
        $stmt = $this->connection->prepare(
            'INSERT INTO event_types (slug, name, audience, color, sort_order, is_default, is_active)
             VALUES (:slug, :name, :audience, :color, :sort_order, 0, 1)'
        );
        $stmt->bindValue(':slug', (string) $data['slug'], PDO::PARAM_STR);
        $stmt->bindValue(':name', (string) $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':audience', (string) $data['audience'], PDO::PARAM_STR);
        $stmt->bindValue(':color', (string) $data['color'], PDO::PARAM_STR);
        $stmt->bindValue(':sort_order', (int) $data['sort_order'], PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $typeId, array $data): bool
    {
        if ($this->connection === null) {
            return false;
        }
        // slug is absent on purpose: it is the localStorage key and a CSS
        // class name, so renaming it would silently reset every user's calendar
        // layer preferences. The name carries the display label instead.
        $stmt = $this->connection->prepare(
            'UPDATE event_types
                SET name = :name, audience = :audience, color = :color, sort_order = :sort_order
              WHERE id = :id'
        );
        $stmt->bindValue(':name', (string) $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':audience', (string) $data['audience'], PDO::PARAM_STR);
        $stmt->bindValue(':color', (string) $data['color'], PDO::PARAM_STR);
        $stmt->bindValue(':sort_order', (int) $data['sort_order'], PDO::PARAM_INT);
        $stmt->bindValue(':id', $typeId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function delete(int $typeId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare('DELETE FROM event_types WHERE id = :id');
        $stmt->bindValue(':id', $typeId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function countEventsOfType(int $typeId): int
    {
        if ($this->connection === null) {
            return 0;
        }
        $stmt = $this->connection->prepare(
            'SELECT COUNT(*) AS n FROM events WHERE event_type_id = :id'
        );
        $stmt->bindValue(':id', $typeId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['n'] ?? 0);
    }

    public function slugExists(string $slug, ?int $exceptTypeId = null): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $sql = 'SELECT COUNT(*) AS n FROM event_types WHERE slug = :slug';
        if ($exceptTypeId !== null) {
            $sql .= ' AND id <> :except_id';
        }
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
        if ($exceptTypeId !== null) {
            $stmt->bindValue(':except_id', $exceptTypeId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['n'] ?? 0) > 0;
    }

    public function setDefault(int $typeId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        // Clear-then-set in one transaction. A crash between the two would
        // otherwise leave no default at all, and the create form would have
        // nothing to preselect.
        $this->connection->beginTransaction();
        try {
            $this->connection->exec('UPDATE event_types SET is_default = 0');
            $stmt = $this->connection->prepare('UPDATE event_types SET is_default = 1 WHERE id = :id');
            $stmt->bindValue(':id', $typeId, PDO::PARAM_INT);
            $stmt->execute();
            $this->connection->commit();

            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function listOrphanedEvents(): array
    {
        if ($this->connection === null) {
            return [];
        }
        // events.event_type_id is a foreign key, so this is empty on a healthy
        // database; it stays so the admin screen can still say so otherwise.
        $stmt = $this->connection->query(
            'SELECT e.id, e.title, e.event_type_id
               FROM events e
          LEFT JOIN event_types t ON t.id = e.event_type_id
              WHERE t.id IS NULL
           ORDER BY e.title ASC'
        );

        return array_map(
            static fn (array $r): array => [
                'event_id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'event_type_id' => (int) $r['event_type_id'],
            ],
            $stmt?->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function hydrate(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'name' => (string) $r['name'],
            'audience' => (string) $r['audience'],
            'color' => $r['color'] === null ? null : (string) $r['color'],
            'sort_order' => (int) $r['sort_order'],
            'is_default' => (int) ($r['is_default'] ?? 0) === 1,
            'is_active' => (int) ($r['is_active'] ?? 1) === 1,
            'usage_count' => (int) ($r['usage_count'] ?? 0),
        ];
    }
}
