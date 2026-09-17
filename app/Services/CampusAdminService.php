<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Campus administration — CRUD over the shared `church_campus` table.
 *
 * Direct-PDO (no adapter) and deliberately self-contained: it touches only the
 * `church_campus` table and reference counts, with NO dependency on any ChurchCRM
 * PHP so the portal keeps working after ChurchCRM is decommissioned.
 */
final readonly class CampusAdminService
{
    /** Columns the form is allowed to write. */
    private const FIELDS = [
        'campus_name', 'campus_code', 'address1', 'address2', 'city', 'state',
        'zip', 'country', 'phone', 'email', 'website', 'time_zone', 'notes',
    ];

    public function __construct(private PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $sql = 'SELECT * FROM church_campus ORDER BY is_main DESC, is_active DESC, campus_name ASC';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM church_campus WHERE campus_id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** A blank campus row for the "new" form. */
    public function blank(): array
    {
        return [
            'campus_id' => 0, 'campus_name' => '', 'campus_code' => '', 'address1' => '',
            'address2' => '', 'city' => '', 'state' => 'Ontario', 'zip' => '', 'country' => 'CA',
            'phone' => '', 'email' => '', 'website' => '', 'time_zone' => 'America/Toronto',
            'is_active' => 1, 'is_main' => 0, 'notes' => '',
            'default_assignment_event_id' => null,
        ];
    }

    /** @return array{count:int,main:?string} */
    public function stats(): array
    {
        $count = (int) $this->db->query('SELECT COUNT(*) FROM church_campus')->fetchColumn();
        $main = $this->db->query('SELECT campus_name FROM church_campus WHERE is_main = 1 LIMIT 1')->fetchColumn();
        return ['count' => $count, 'main' => $main !== false ? (string) $main : null];
    }

    /**
     * Create or update a campus. Returns the campus id.
     *
     * @param array<string,mixed> $in
     */
    public function save(array $in, int $actorId): int
    {
        $name = trim((string) ($in['campus_name'] ?? ''));
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
        $values['campus_name'] = $name; // required, non-null
        $isActive = (int) (($in['is_active'] ?? 0)) === 1 ? 1 : 0;
        $wantMain = (int) (($in['is_main'] ?? 0)) === 1;
        $id = (int) ($in['campus_id'] ?? 0);
        $defaultEventId = (int) ($in['default_assignment_event_id'] ?? 0);
        $defaultEventId = $defaultEventId > 0 ? $defaultEventId : null;

        if ($id > 0) {
            $set = implode(', ', array_map(static fn ($f) => "$f = :$f", self::FIELDS));
            $sql = "UPDATE church_campus SET $set, is_active = :is_active,
                        default_assignment_event_id = :default_assignment_event_id,
                        date_last_edited = NOW(), edited_by = :actor WHERE campus_id = :id";
            $params = array_merge(
                array_combine(array_map(static fn ($f) => ":$f", self::FIELDS), array_values($values)),
                [
                    ':is_active' => $isActive,
                    ':default_assignment_event_id' => $defaultEventId,
                    ':actor' => $actorId,
                    ':id' => $id,
                ]
            );
            $this->db->prepare($sql)->execute($params);
        } else {
            $cols = implode(', ', self::FIELDS);
            $ph   = implode(', ', array_map(static fn ($f) => ":$f", self::FIELDS));
            $sql = "INSERT INTO church_campus ($cols, is_active, is_main, default_assignment_event_id, date_entered, entered_by)
                    VALUES ($ph, :is_active, 0, :default_assignment_event_id, NOW(), :actor)";
            $params = array_merge(
                array_combine(array_map(static fn ($f) => ":$f", self::FIELDS), array_values($values)),
                [
                    ':is_active' => $isActive,
                    ':default_assignment_event_id' => $defaultEventId,
                    ':actor' => $actorId,
                ]
            );
            $this->db->prepare($sql)->execute($params);
            $id = (int) $this->db->lastInsertId();
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
            $this->db->exec('UPDATE church_campus SET is_main = 0 WHERE is_main = 1');
            $stmt = $this->db->prepare('UPDATE church_campus SET is_main = 1, is_active = 1 WHERE campus_id = :id');
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
        foreach (['person_campus_affiliation', 'events_event_campus'] as $tbl) {
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
        $this->db->prepare('DELETE FROM church_campus WHERE campus_id = :id')->execute([':id' => $id]);
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
            'SELECT e.event_id AS id, e.event_title AS title
               FROM events_event e
              WHERE COALESCE(e.assignment_scheduling_enabled, 0) = 1
                AND (
                        NOT EXISTS (
                            SELECT 1 FROM events_event_campus x WHERE x.event_id = e.event_id
                        )
                        OR EXISTS (
                            SELECT 1 FROM events_event_campus x
                             WHERE x.event_id = e.event_id AND x.campus_id = :campus_id
                        )
                    )
              ORDER BY e.event_title ASC, e.event_id ASC'
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
}
