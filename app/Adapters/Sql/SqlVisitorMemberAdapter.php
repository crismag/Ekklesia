<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\VisitorMemberAdapter;
use PDO;
use Throwable;

/** Member database reads (and the one write) for the Visitors workspace. */
final class SqlVisitorMemberAdapter implements VisitorMemberAdapter
{
    public function __construct(private readonly PDO $db) {}

    public function personCandidates(string $lastName, string $email): array
    {
        // Same candidate pull as the modules' sg_match_members(): same last
        // name, or the same email.
        $conds = [];
        $params = [];
        if ($lastName !== '') {
            $conds[] = 'LOWER(last_name) = :last';
            $params[':last'] = $lastName;
        }
        if ($email !== '') {
            $conds[] = 'LOWER(email) = :email';
            $params[':email'] = $email;
        }
        if ($conds === []) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT id, first_name, last_name, email, mobile_phone, home_phone, birth_month, birth_year, city
               FROM people
              WHERE ' . implode(' OR ', $conds) . ' LIMIT 200'
        );
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'first_name' => (string) $r['first_name'],
            'last_name' => (string) $r['last_name'],
            'birth_month' => (int) $r['birth_month'],
            'birth_year' => (int) $r['birth_year'],
            'city' => (string) ($r['city'] ?? ''),
            'emails' => [(string) ($r['email'] ?? '')],
            'phones' => [(string) ($r['mobile_phone'] ?? ''), (string) ($r['home_phone'] ?? '')],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function peopleByIds(array $personIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $personIds), static fn (int $n): bool => $n > 0)));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, first_name, last_name, preferred_name, city FROM people WHERE id IN ($ph)");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'name' => trim((string) $r['first_name'] . ' ' . (string) $r['last_name']),
                'city' => (string) ($r['city'] ?? ''),
            ];
        }

        return $out;
    }

    public function membershipStatuses(): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $this->db->query('SELECT id, name FROM membership_statuses ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function campuses(): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $this->db->query('SELECT id, name FROM campuses WHERE is_active = 1 ORDER BY is_main DESC, name')->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function memberTypeIdByName(string $name): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM member_types WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function eventsByIds(array $eventIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $eventIds), static fn (int $n): bool => $n > 0)));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, title, starts_on, start_time, is_active, archived_at FROM events WHERE id IN ($ph)"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'starts_on' => $r['starts_on'] !== null ? (string) $r['starts_on'] : null,
                'start_time' => $r['start_time'] !== null ? substr((string) $r['start_time'], 0, 5) : null,
                'is_active' => (int) $r['is_active'] === 1 && $r['archived_at'] === null,
            ];
        }

        return $out;
    }

    public function occurrencesByIds(array $occurrenceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $occurrenceIds), static fn (int $n): bool => $n > 0)));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, event_id, starts_at, status, title_override FROM event_occurrences WHERE id IN ($ph)");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'event_id' => (int) $r['event_id'],
                'starts_at' => (string) $r['starts_at'],
                'status' => (string) $r['status'],
                'title' => $r['title_override'] !== null ? (string) $r['title_override'] : null,
            ];
        }

        return $out;
    }

    public function upcomingEvents(string $fromDate, string $toDate): array
    {
        // Mirrors which date the RSVP module files a response under
        // (rv_load_event): the soonest scheduled occurrence from today.
        $stmt = $this->db->prepare(
            "SELECT e.id, e.title, MIN(o.starts_at) AS next_starts_at
               FROM events e
               JOIN event_occurrences o ON o.event_id = e.id AND o.status = 'scheduled'
              WHERE e.is_active = 1 AND e.archived_at IS NULL
                AND o.starts_at >= :from AND o.starts_at < :to
           GROUP BY e.id, e.title
           ORDER BY next_starts_at, e.title
              LIMIT 60"
        );
        $stmt->execute([':from' => $fromDate, ':to' => $toDate]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'next_starts_at' => (string) $r['next_starts_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function transaction(callable $work): mixed
    {
        if ($this->db->inTransaction()) {
            return $work();
        }
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function completePromotedPerson(int $personId, ?int $birthMonth, ?int $birthDay, ?float $latitude, ?float $longitude): void
    {
        $this->db->prepare(
            'UPDATE people
                SET birth_month = :bm, birth_day = :bd, latitude = :lat, longitude = :lng
              WHERE id = :id'
        )->execute([
            ':bm' => $birthMonth, ':bd' => $birthDay,
            ':lat' => $latitude !== null ? (string) $latitude : null,
            ':lng' => $longitude !== null ? (string) $longitude : null,
            ':id' => $personId,
        ]);
    }
}
