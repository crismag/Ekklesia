<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\ActivityHistoryRepository;
use App\Core\Database\MembersConnection;
use PDO;

/**
 * audit_log, read for Activity history.
 *
 * Knows nothing about who may read it; ActivityHistoryService decides that.
 * Names are joined in here (the acting account, the actor's person, and a label
 * for the record the entry is about) so the page never runs a query per row.
 */
final class SqlActivityHistoryAdapter implements ActivityHistoryRepository
{
    private PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? MembersConnection::get();
    }

    public function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filters);
        $sql = "SELECT a.id, a.occurred_at, a.account_id, a.person_id, a.action, a.target_type, a.target_id,
                       a.summary, a.details, a.ip_address, a.user_agent,
                       u.email AS account_email, u.display_name AS account_name,
                       COALESCE(a.person_id, u.person_id) AS actor_person_id,
                       TRIM(CONCAT(COALESCE(ap.first_name, ''), ' ', COALESCE(ap.last_name, ''))) AS actor_person_name,
                       CASE a.target_type
                         WHEN 'person' THEN TRIM(CONCAT(COALESCE(tp.first_name, ''), ' ', COALESCE(tp.last_name, '')))
                         WHEN 'household' THEN th.name
                         WHEN 'campus' THEN tc.name
                         WHEN 'user_account' THEN COALESCE(tu.display_name, tu.email)
                         ELSE NULL
                       END AS target_label
                  FROM audit_log a
                  LEFT JOIN user_accounts u ON u.id = a.account_id
                  LEFT JOIN people ap ON ap.id = COALESCE(a.person_id, u.person_id)
                  LEFT JOIN people tp ON a.target_type = 'person' AND tp.id = a.target_id
                  LEFT JOIN households th ON a.target_type = 'household' AND th.id = a.target_id
                  LEFT JOIN campuses tc ON a.target_type = 'campus' AND tc.id = a.target_id
                  LEFT JOIN user_accounts tu ON a.target_type = 'user_account' AND tu.id = a.target_id"
            . $where
            . ' ORDER BY a.occurred_at DESC, a.id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $details = null;
            if ($row['details'] !== null && $row['details'] !== '') {
                $decoded = json_decode((string) $row['details'], true);
                $details = is_array($decoded) ? $decoded : null;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'occurredAt' => (string) $row['occurred_at'],
                'accountId' => $row['account_id'] !== null ? (int) $row['account_id'] : null,
                'accountEmail' => $row['account_email'] !== null ? (string) $row['account_email'] : null,
                'accountName' => $row['account_name'] !== null ? (string) $row['account_name'] : null,
                'actorPersonId' => $row['actor_person_id'] !== null ? (int) $row['actor_person_id'] : null,
                'actorPersonName' => trim((string) ($row['actor_person_name'] ?? '')) !== '' ? trim((string) $row['actor_person_name']) : null,
                'action' => (string) $row['action'],
                'targetType' => $row['target_type'] !== null ? (string) $row['target_type'] : null,
                'targetId' => $row['target_id'] !== null ? (string) $row['target_id'] : null,
                'targetLabel' => trim((string) ($row['target_label'] ?? '')) !== '' ? trim((string) $row['target_label']) : null,
                'summary' => $row['summary'] !== null ? (string) $row['summary'] : null,
                'details' => $details,
                'ipAddress' => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
                'userAgent' => $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
            ];
        }

        return $out;
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->where($filters);
        $stmt = $this->connection->prepare(
            'SELECT COUNT(*) FROM audit_log a LEFT JOIN user_accounts u ON u.id = a.account_id' . $where
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function facets(): array
    {
        $actions = array_map('strval', $this->connection
            ->query('SELECT DISTINCT action FROM audit_log ORDER BY action')
            ->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $types = array_map('strval', $this->connection
            ->query('SELECT DISTINCT target_type FROM audit_log WHERE target_type IS NOT NULL ORDER BY target_type')
            ->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $accounts = [];
        $rows = $this->connection->query(
            'SELECT u.id, u.email, u.display_name
               FROM user_accounts u
              WHERE EXISTS (SELECT 1 FROM audit_log a WHERE a.account_id = u.id)
              ORDER BY COALESCE(u.display_name, u.email)'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['display_name'] ?? ''));
            $accounts[] = [
                'id' => (int) $row['id'],
                'label' => $name !== '' ? $name . ' (' . $row['email'] . ')' : (string) $row['email'],
            ];
        }

        return ['actions' => $actions, 'targetTypes' => $types, 'accounts' => $accounts];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,int|string>}
     */
    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['accountId'] ?? 0) > 0) {
            $where[] = 'a.account_id = :account';
            $params[':account'] = (int) $filters['accountId'];
        }
        if (($filters['personId'] ?? 0) > 0) {
            // By this person (directly, or through their login), or about their record.
            $where[] = "(a.person_id = :p1 OR u.person_id = :p2 OR (a.target_type = 'person' AND a.target_id = :p3))";
            $params[':p1'] = (int) $filters['personId'];
            $params[':p2'] = (int) $filters['personId'];
            $params[':p3'] = (string) (int) $filters['personId'];
        }
        if (($filters['action'] ?? '') !== '') {
            $where[] = 'a.action = :action';
            $params[':action'] = (string) $filters['action'];
        }
        if (($filters['targetType'] ?? '') !== '') {
            $where[] = 'a.target_type = :ttype';
            $params[':ttype'] = (string) $filters['targetType'];
        }
        if (($filters['targetId'] ?? '') !== '') {
            $where[] = 'a.target_id = :tid';
            $params[':tid'] = (string) $filters['targetId'];
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'a.occurred_at >= :from';
            $params[':from'] = $filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? '') !== '') {
            // Inclusive of the whole "to" day.
            $where[] = 'a.occurred_at < DATE_ADD(:to, INTERVAL 1 DAY)';
            $params[':to'] = (string) $filters['to'];
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }
}
