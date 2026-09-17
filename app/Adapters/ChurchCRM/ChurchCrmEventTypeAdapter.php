<?php

declare(strict_types=1);

namespace App\Adapters\ChurchCRM;

use App\Contracts\EventTypeAdapter;
use PDO;

/**
 * event_types, as extended by migration 009.
 *
 * The table predates the portal and ChurchCRM's EditEventTypes.php still writes
 * it, so every write here touches only the portal_* columns plus type_name —
 * never the type_def* recurrence defaults, which belong to ChurchCRM's own
 * event editor and would be silently destroyed by a blind UPDATE.
 */
final class ChurchCrmEventTypeAdapter implements EventTypeAdapter
{
    public function __construct(private readonly ?PDO $connection = null) {}

    public function listAll(): array
    {
        if ($this->connection === null) {
            return [];
        }
        // Portal-managed types sort first by their explicit order; ChurchCRM-only
        // rows (portal_sort IS NULL) fall to the bottom, where the screen offers
        // to adopt them rather than pretending they are already layers.
        $stmt = $this->connection->query(
            'SELECT t.type_id, t.type_name, t.type_active,
                    t.portal_slug, t.portal_label, t.portal_audience,
                    t.portal_color, t.portal_sort, t.portal_is_default,
                    t.type_defstarttime, t.type_defrecurtype,
                    (SELECT COUNT(*) FROM events_event e WHERE e.event_type = t.type_id) AS usage_count
               FROM event_types t
           ORDER BY t.portal_sort IS NULL, t.portal_sort ASC, t.type_name ASC'
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
            'SELECT t.type_id, t.type_name, t.type_active,
                    t.portal_slug, t.portal_label, t.portal_audience,
                    t.portal_color, t.portal_sort, t.portal_is_default,
                    (SELECT COUNT(*) FROM events_event e WHERE e.event_type = t.type_id) AS usage_count
               FROM event_types t
              WHERE t.type_id = :type_id
              LIMIT 1'
        );
        $stmt->bindValue(':type_id', $typeId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function insert(array $data): int
    {
        if ($this->connection === null) {
            return 0;
        }
        // type_defrecurtype has no default in ChurchCRM's schema, so it is set
        // explicitly; the rest of the type_def* columns keep their defaults.
        $stmt = $this->connection->prepare(
            'INSERT INTO event_types
                (type_name, type_defrecurtype, type_active,
                 portal_slug, portal_label, portal_audience, portal_color, portal_sort, portal_is_default)
             VALUES (:name, "none", 1, :slug, :label, :audience, :color, :sort, 0)'
        );
        $stmt->bindValue(':name', (string) $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':slug', (string) $data['slug'], PDO::PARAM_STR);
        $stmt->bindValue(':label', (string) $data['label'], PDO::PARAM_STR);
        $stmt->bindValue(':audience', (string) $data['audience'], PDO::PARAM_STR);
        $stmt->bindValue(':color', (string) $data['color'], PDO::PARAM_STR);
        $stmt->bindValue(':sort', (int) $data['sort'], PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $typeId, array $data): bool
    {
        if ($this->connection === null) {
            return false;
        }
        // portal_slug is absent on purpose: it is the localStorage key and a CSS
        // class name, so renaming it would silently reset every user's calendar
        // layer preferences. The label carries the display name instead.
        $stmt = $this->connection->prepare(
            'UPDATE event_types
                SET type_name = :name, portal_label = :label, portal_audience = :audience,
                    portal_color = :color, portal_sort = :sort
              WHERE type_id = :type_id'
        );
        $stmt->bindValue(':name', (string) $data['name'], PDO::PARAM_STR);
        $stmt->bindValue(':label', (string) $data['label'], PDO::PARAM_STR);
        $stmt->bindValue(':audience', (string) $data['audience'], PDO::PARAM_STR);
        $stmt->bindValue(':color', (string) $data['color'], PDO::PARAM_STR);
        $stmt->bindValue(':sort', (int) $data['sort'], PDO::PARAM_INT);
        $stmt->bindValue(':type_id', $typeId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function delete(int $typeId): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $stmt = $this->connection->prepare('DELETE FROM event_types WHERE type_id = :type_id');
        $stmt->bindValue(':type_id', $typeId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function countEventsOfType(int $typeId): int
    {
        if ($this->connection === null) {
            return 0;
        }
        $stmt = $this->connection->prepare(
            'SELECT COUNT(*) AS n FROM events_event WHERE event_type = :type_id'
        );
        $stmt->bindValue(':type_id', $typeId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['n'] ?? 0);
    }

    public function slugExists(string $slug, ?int $exceptTypeId = null): bool
    {
        if ($this->connection === null) {
            return false;
        }
        $sql = 'SELECT COUNT(*) AS n FROM event_types WHERE portal_slug = :slug';
        if ($exceptTypeId !== null) {
            $sql .= ' AND type_id <> :except_id';
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
            $this->connection->exec('UPDATE event_types SET portal_is_default = 0');
            $stmt = $this->connection->prepare(
                'UPDATE event_types SET portal_is_default = 1 WHERE type_id = :type_id AND portal_slug IS NOT NULL'
            );
            $stmt->bindValue(':type_id', $typeId, PDO::PARAM_INT);
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
        $stmt = $this->connection->query(
            'SELECT e.event_id, e.event_title, e.event_type
               FROM events_event e
          LEFT JOIN event_types t ON t.type_id = e.event_type
              WHERE t.type_id IS NULL
           ORDER BY e.event_title ASC'
        );

        return array_map(
            static fn (array $r): array => [
                'event_id' => (int) $r['event_id'],
                'event_title' => (string) $r['event_title'],
                'event_type' => (int) $r['event_type'],
            ],
            $stmt?->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function hydrate(array $r): array
    {
        return [
            'type_id' => (int) $r['type_id'],
            'type_name' => (string) $r['type_name'],
            'type_active' => (int) ($r['type_active'] ?? 1) === 1,
            'portal_slug' => $r['portal_slug'] === null ? null : (string) $r['portal_slug'],
            'portal_label' => $r['portal_label'] === null ? null : (string) $r['portal_label'],
            'portal_audience' => $r['portal_audience'] === null ? null : (string) $r['portal_audience'],
            'portal_color' => $r['portal_color'] === null ? null : (string) $r['portal_color'],
            'portal_sort' => $r['portal_sort'] === null ? null : (int) $r['portal_sort'],
            'portal_is_default' => (int) ($r['portal_is_default'] ?? 0) === 1,
            'usage_count' => (int) ($r['usage_count'] ?? 0),
            // ChurchCRM's own per-type defaults, present since the schema was
            // created and never read. A Church Service already knows it starts
            // at 08:00 and repeats weekly; the editor can offer that instead of
            // making somebody type it again.
            'default_start_time' => ($r['type_defstarttime'] ?? null) === null
                ? null
                : substr((string) $r['type_defstarttime'], 0, 5),
            'default_recurrence' => ($r['type_defrecurtype'] ?? null) === null
                ? null
                : (string) $r['type_defrecurtype'],
        ];
    }
}
