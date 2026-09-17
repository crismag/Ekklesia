<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\RecordHistoryAdapter;
use PDO;

/**
 * audit_log, narrowed to person and household targets, with the names needed
 * to say who did what to which record.
 *
 * Who: the account's linked person, else the person the row itself names (the
 * earlier system recorded who entered a note that way), else the account.
 */
final class SqlRecordHistoryAdapter implements RecordHistoryAdapter
{
    private const TYPES = ['person', 'household'];

    public function __construct(private readonly PDO $db) {}

    public function count(array $criteria): int
    {
        [$where, $params] = $this->where($criteria);
        $st = $this->db->prepare('SELECT COUNT(*) FROM audit_log al WHERE ' . $where);
        $st->execute($params);

        return (int) $st->fetchColumn();
    }

    public function find(array $criteria, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($criteria);
        $st = $this->db->prepare(
            'SELECT al.id, al.occurred_at, al.action, al.target_type, al.target_id, al.summary, al.details,
                    al.account_id, ua.email AS account_email, ua.display_name AS account_name,
                    COALESCE(ua.person_id, al.person_id) AS actor_person_id,
                    ap.first_name AS actor_first_name, ap.last_name AS actor_last_name,
                    tp.first_name AS target_first_name, tp.last_name AS target_last_name,
                    th.name AS target_household_name
               FROM audit_log al
               LEFT JOIN user_accounts ua ON ua.id = al.account_id
               LEFT JOIN people ap ON ap.id = COALESCE(ua.person_id, al.person_id)
               LEFT JOIN people tp ON al.target_type = \'person\' AND tp.id = al.target_id
               LEFT JOIN households th ON al.target_type = \'household\' AND th.id = al.target_id
              WHERE ' . $where . '
              ORDER BY al.occurred_at DESC, al.id DESC
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function actions(): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT action FROM audit_log WHERE target_type IN ('person', 'household') ORDER BY action"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_map('strval', $rows);
    }

    public function peopleWithHistory(): array
    {
        $rows = $this->db->query(
            "SELECT p.id, p.first_name, p.last_name
               FROM people p
              WHERE p.id IN (SELECT DISTINCT CAST(target_id AS UNSIGNED) FROM audit_log WHERE target_type = 'person')
              ORDER BY p.last_name, p.first_name, p.id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'first_name' => (string) ($r['first_name'] ?? ''),
            'last_name' => (string) ($r['last_name'] ?? ''),
        ], $rows);
    }

    public function personName(int $personId): ?string
    {
        $st = $this->db->prepare('SELECT first_name, last_name FROM people WHERE id = :id');
        $st->execute([':id' => $personId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r ? trim((string) $r['first_name'] . ' ' . (string) $r['last_name']) : null;
    }

    public function householdName(int $householdId): ?string
    {
        $st = $this->db->prepare('SELECT name FROM households WHERE id = :id');
        $st->execute([':id' => $householdId]);
        $name = $st->fetchColumn();

        return $name === false ? null : (string) $name;
    }

    /**
     * @param array<string,mixed> $c
     * @return array{0:string,1:array<string,mixed>}
     */
    private function where(array $c): array
    {
        $types = array_values(array_intersect(self::TYPES, (array) ($c['types'] ?? self::TYPES)));
        if ($types === []) {
            $types = self::TYPES;
        }
        $params = [];
        $in = [];
        foreach ($types as $i => $type) {
            $in[] = ':type' . $i;
            $params[':type' . $i] = $type;
        }
        $parts = ['al.target_type IN (' . implode(', ', $in) . ')'];

        // Person and household narrow to their own rows; asking for both means
        // either record (a person's page and their household's page together).
        $targets = [];
        if (($c['personId'] ?? null) !== null) {
            $targets[] = "(al.target_type = 'person' AND al.target_id = :person)";
            $params[':person'] = (string) (int) $c['personId'];
        }
        if (($c['householdId'] ?? null) !== null) {
            $targets[] = "(al.target_type = 'household' AND al.target_id = :household)";
            $params[':household'] = (string) (int) $c['householdId'];
        }
        if ($targets !== []) {
            $parts[] = '(' . implode(' OR ', $targets) . ')';
        }
        if (($c['action'] ?? null) !== null) {
            $parts[] = 'al.action = :action';
            $params[':action'] = (string) $c['action'];
        }
        if (($c['from'] ?? null) !== null) {
            $parts[] = 'al.occurred_at >= :from';
            $params[':from'] = $c['from'] . ' 00:00:00';
        }
        if (($c['to'] ?? null) !== null) {
            $parts[] = 'al.occurred_at < DATE_ADD(:to, INTERVAL 1 DAY)';
            $params[':to'] = (string) $c['to'];
        }

        return [implode(' AND ', $parts), $params];
    }
}
