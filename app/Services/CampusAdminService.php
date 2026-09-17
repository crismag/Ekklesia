<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Campus administration — CRUD over the `campuses` table.
 *
 * Direct-PDO (no adapter) and deliberately self-contained: it touches only the
 * `campuses` table and reference counts.
 */
final readonly class CampusAdminService
{
    /** Columns the form is allowed to write. */
    private const FIELDS = [
        'name', 'code', 'address_line1', 'address_line2', 'city', 'region',
        'postal_code', 'country', 'phone', 'email', 'website', 'time_zone', 'notes',
    ];

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $sql = 'SELECT * FROM campuses ORDER BY is_main DESC, is_active DESC, name ASC';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM campuses WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** A blank campus row for the "new" form. */
    public function blank(): array
    {
        return [
            'id' => 0, 'name' => '', 'code' => '', 'address_line1' => '',
            'address_line2' => '', 'city' => '', 'region' => 'Ontario', 'postal_code' => '', 'country' => 'CA',
            'phone' => '', 'email' => '', 'website' => '', 'time_zone' => 'America/Toronto',
            'is_active' => 1, 'is_main' => 0, 'notes' => '',
            'default_scheduling_event_id' => null,
        ];
    }

    /** @return array{count:int,main:?string} */
    public function stats(): array
    {
        $count = (int) $this->db->query('SELECT COUNT(*) FROM campuses')->fetchColumn();
        $main = $this->db->query('SELECT name FROM campuses WHERE is_main = 1 LIMIT 1')->fetchColumn();
        return ['count' => $count, 'main' => $main !== false ? (string) $main : null];
    }

    /**
     * Create or update a campus. Returns the campus id.
     *
     * @param array<string,mixed> $in
     */
    public function save(array $in, int $actorId): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Campus name is required.');
        }

        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email address looks invalid.');
        }

        $values = [];
        foreach (self::FIELDS as $f) {
            $v = trim((string) ($in[$f] ?? ''));
            $values[$f] = $v === '' ? null : $v;
        }
        $values['name'] = $name; // required, non-null
        // time_zone is NOT NULL; a blank field keeps the schema default.
        $values['time_zone'] = $values['time_zone'] ?? 'America/Toronto';
        $isActive = (int) (($in['is_active'] ?? 0)) === 1 ? 1 : 0;
        $wantMain = (int) (($in['is_main'] ?? 0)) === 1;
        $id = (int) ($in['id'] ?? 0);
        $defaultEventId = (int) ($in['default_scheduling_event_id'] ?? 0);
        $defaultEventId = $defaultEventId > 0 ? $defaultEventId : null;

        if ($id > 0) {
            $set = implode(', ', array_map(static fn ($f) => "`$f` = :$f", self::FIELDS));
            $sql = "UPDATE campuses SET $set, is_active = :is_active,
                        default_scheduling_event_id = :default_scheduling_event_id,
                        updated_at = NOW() WHERE id = :id";
            $params = array_merge(
                array_combine(array_map(static fn ($f) => ":$f", self::FIELDS), array_values($values)),
                [
                    ':is_active' => $isActive,
                    ':default_scheduling_event_id' => $defaultEventId,
                    ':id' => $id,
                ]
            );
            $this->db->prepare($sql)->execute($params);
            $this->audit($actorId, 'campus.updated', $id);
        } else {
            $cols = implode(', ', array_map(static fn ($f) => "`$f`", self::FIELDS));
            $ph   = implode(', ', array_map(static fn ($f) => ":$f", self::FIELDS));
            $sql = "INSERT INTO campuses ($cols, is_active, is_main, default_scheduling_event_id)
                    VALUES ($ph, :is_active, 0, :default_scheduling_event_id)";
            $params = array_merge(
                array_combine(array_map(static fn ($f) => ":$f", self::FIELDS), array_values($values)),
                [
                    ':is_active' => $isActive,
                    ':default_scheduling_event_id' => $defaultEventId,
                ]
            );
            $this->db->prepare($sql)->execute($params);
            $id = (int) $this->db->lastInsertId();
            $this->audit($actorId, 'campus.created', $id);
        }

        if ($wantMain) {
            $this->setMain($id);
        }

        return $id;
    }

    /** Make exactly one campus the main campus. */
    public function setMain(int $id): void
    {
        if ($id <= 0 || $this->find($id) === null) {
            throw new InvalidArgumentException('Unknown campus.');
        }
        $this->db->beginTransaction();
        try {
            $this->db->exec('UPDATE campuses SET is_main = 0 WHERE is_main = 1');
            $stmt = $this->db->prepare('UPDATE campuses SET is_main = 1, is_active = 1 WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Number of records that reference this campus (blocks hard delete). */
    public function references(int $id): int
    {
        $n = 0;
        foreach (['people', 'event_campuses'] as $tbl) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `$tbl` WHERE campus_id = :id");
            $stmt->execute([':id' => $id]);
            $n += (int) $stmt->fetchColumn();
        }
        return $n;
    }

    /** Hard-delete a campus. Refuses when it's the main campus or still referenced. */
    public function delete(int $id): void
    {
        $campus = $this->find($id);
        if ($campus === null) {
            throw new InvalidArgumentException('Unknown campus.');
        }
        if ((int) $campus['is_main'] === 1) {
            throw new RuntimeException('The main campus cannot be deleted. Set another campus as main first.');
        }
        if ($this->references($id) > 0) {
            throw new RuntimeException('This campus is still assigned to people or events. Deactivate it instead.');
        }
        $this->db->prepare('DELETE FROM campuses WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Events that may be chosen as this campus's default assignment event.
     *
     * @return list<array{id:int,title:string}>
     */
    public function eligibleAssignmentEvents(int $campusId): array
    {
        if ($campusId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT e.id, e.title
               FROM events e
              WHERE e.uses_serving_schedule = 1
                AND (
                        NOT EXISTS (
                            SELECT 1 FROM event_campuses x WHERE x.event_id = e.id
                        )
                        OR EXISTS (
                            SELECT 1 FROM event_campuses x
                             WHERE x.event_id = e.id AND x.campus_id = :campus_id
                        )
                    )
              ORDER BY e.title ASC, e.id ASC'
        );
        $stmt->execute([':campus_id' => $campusId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
            ];
        }

        return $rows;
    }

    /**
     * Record who changed a campus. The actor id callers pass is a person id; a
     * value that is not one is kept as no one rather than breaking the write.
     */
    private function audit(int $actorId, string $action, int $campusId): void
    {
        $this->db->prepare(
            'INSERT INTO audit_log (person_id, action, target_type, target_id)
             VALUES ((SELECT id FROM people WHERE id = :actor), :action, "campus", :target)'
        )->execute([':actor' => $actorId, ':action' => $action, ':target' => (string) $campusId]);
    }
}
