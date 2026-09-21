<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\MemberImportAdapter;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Staging persistence for the campus member import.
 *
 * The staging tables live in the member database beside `people` (see
 * database/members/001_schema.sql), on the same connection as the other SQL
 * adapters.
 */
final class SqlMemberImportAdapter implements MemberImportAdapter
{
    private const BATCH_TABLE = 'member_import_batches';
    private const ROW_TABLE = 'member_import_rows';

    public function __construct(private readonly PDO $db)
    {
    }

    public function assertSchemaReady(): void
    {
        foreach ([self::BATCH_TABLE, self::ROW_TABLE] as $table) {
            $stmt = $this->db->query('SHOW TABLES LIKE ' . $this->db->quote($table));
            if ($stmt === false || $stmt->fetchColumn() === false) {
                // Name the database as well as the schema: this failed in
                // production precisely because the schema had been applied
                // somewhere else, and naming the file alone does not say where.
                $database = 'unknown';
                try {
                    $database = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
                } catch (\Throwable) {
                    $database = 'unknown';
                }

                throw new RuntimeException(
                    "The member import staging table `{$table}` does not exist in database "
                    . "`{$database}`.\n\n"
                    . "Apply the member database schema to this deployment:\n"
                    . "    php tools/migrate.php --status\n"
                    . "    php tools/migrate.php --apply\n\n"
                    . 'database/members/001_schema.sql creates it. '
                    . 'Application code does not create schema: that is owned by migrations, '
                    . 'so imports stay refused until the database is migrated.'
                );
            }
        }
        $col = $this->db->query('SHOW COLUMNS FROM ' . self::ROW_TABLE . " LIKE 'duplicate_group'");
        if ($col === false || $col->fetchColumn() === false) {
            throw new RuntimeException(
                "The member import staging tables predate duplicate decisions.\n\n"
                . "Apply database/members/migrations/002-import-duplicate-decisions.sql:\n"
                . "    php tools/migrate.php --apply"
            );
        }
    }

    public function createBatch(array $batch, array $rows): int
    {
        $this->assertSchemaReady();
        $this->db->beginTransaction();
        try {
            $ins = $this->db->prepare(
                'INSERT INTO ' . self::BATCH_TABLE . '
                    (campus_id, status, source_label, hub_sheet, ny_sheet, hub_updated, ny_updated, warnings, duplicate_report, match_rules, created_by_account_id)
                 VALUES (:c, "staging", :label, :hub, :ny, :hu, :nu, :w, :dup, :rules, :by)'
            );
            $ins->execute([
                ':c' => $batch['campus_id'],
                ':label' => $batch['source_label'],
                ':hub' => $batch['hub_sheet'],
                ':ny' => $batch['ny_sheet'],
                ':hu' => $batch['hub_updated'],
                ':nu' => $batch['ny_updated'],
                // A JSON list in storage; a batch with nothing to say stores NULL.
                ':w' => ($batch['warnings'] ?? []) !== [] ? json_encode(array_values((array) $batch['warnings']), JSON_UNESCAPED_UNICODE) : null,
                ':dup' => $batch['duplicate_report'],
                ':rules' => ($batch['match_rules'] ?? []) !== [] ? json_encode(array_values((array) $batch['match_rules'])) : null,
                // An account id; a CLI run has none, and 0 is not an account.
                ':by' => (int) ($batch['created_by_account_id'] ?? 0) > 0 ? (int) $batch['created_by_account_id'] : null,
            ]);
            $batchId = (int) $this->db->lastInsertId();

            $rowIns = $this->db->prepare(
                'INSERT INTO ' . self::ROW_TABLE . '
                    (batch_id, last_name, first_name, middle_name, preferred_name, email, phone,
                     address_raw, address_line1, city, region, postal_code, country,
                     birth_year, birth_month, birth_day, member_since, member_type, ministry, confirmed,
                     source, filled_from, status, matched_person_id, notes, duplicate_group)
                 VALUES
                    (:batch, :last_name, :first_name, :middle_name, :preferred_name, :email, :phone,
                     :address_raw, :address_line1, :city, :region, :postal_code, :country,
                     :birth_year, :birth_month, :birth_day, :member_since, :member_type, :ministry, :confirmed,
                     :source, :filled_from, :status, :matched, :notes, :dup_group)'
            );
            foreach ($rows as $row) {
                $rowIns->execute([
                    ':batch' => $batchId,
                    ':last_name' => $row['last_name'],
                    ':first_name' => $row['first_name'],
                    ':middle_name' => $row['middle_name'],
                    ':preferred_name' => $row['preferred_name'],
                    ':email' => $row['email'],
                    ':phone' => $row['phone'],
                    ':address_raw' => $row['address_raw'],
                    ':address_line1' => $row['address_line1'],
                    ':city' => $row['city'],
                    ':region' => $row['region'],
                    ':postal_code' => $row['postal_code'],
                    ':country' => $row['country'],
                    ':birth_year' => $row['birth_year'],
                    ':birth_month' => $row['birth_month'],
                    ':birth_day' => $row['birth_day'],
                    ':member_since' => $row['member_since'],
                    ':member_type' => $row['member_type'],
                    ':ministry' => $row['ministry'],
                    ':confirmed' => $row['confirmed'],
                    ':source' => $row['source'],
                    // filled_from is stored as JSON: a storage-format concern,
                    // so the encoding lives with the SQL rather than upstream.
                    ':filled_from' => json_encode($row['filled_from'] ?? [], JSON_UNESCAPED_UNICODE),
                    ':status' => $row['status'],
                    ':matched' => $row['matched_person_id'],
                    ':notes' => $row['notes'],
                    ':dup_group' => $row['duplicate_group'] ?? null,
                ]);
            }
            $this->db->commit();

            return $batchId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function saveDuplicateReport(int $batchId, array $report): void
    {
        $st = $this->db->prepare('UPDATE ' . self::BATCH_TABLE . ' SET duplicate_report = :r WHERE id = :id');
        $st->execute([
            ':r' => $report === [] ? null : json_encode($report, JSON_UNESCAPED_UNICODE),
            ':id' => $batchId,
        ]);
    }

    public function listBatches(int $limit): array
    {
        $this->assertSchemaReady();
        $limit = max(1, min(500, $limit));

        return $this->db->query(
            'SELECT b.*,
                    (SELECT COUNT(*) FROM ' . self::ROW_TABLE . ' r WHERE r.batch_id = b.id) AS row_count
               FROM ' . self::BATCH_TABLE . ' b
           ORDER BY b.id DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findBatch(int $id): ?array
    {
        $this->assertSchemaReady();
        $st = $this->db->prepare('SELECT * FROM ' . self::BATCH_TABLE . ' WHERE id = :id');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['duplicate_report'] = json_decode((string) ($row['duplicate_report'] ?? ''), true) ?: [];
        // Handed back one warning per line, as the staging page reads it.
        $warnings = json_decode((string) ($row['warnings'] ?? ''), true);
        $row['warnings'] = is_array($warnings) ? implode("\n", array_map('strval', $warnings)) : null;

        return $row;
    }

    public function listRows(int $batchId, string $status = ''): array
    {
        $sql = 'SELECT * FROM ' . self::ROW_TABLE . ' WHERE batch_id = :b';
        $params = [':b' => $batchId];
        if ($status !== '' && in_array($status, ['draft', 'ready', 'skip', 'applied'], true)) {
            $sql .= ' AND status = :s';
            $params[':s'] = $status;
        }
        $sql .= ' ORDER BY last_name, first_name, id';
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['filled_from'] = json_decode((string) ($r['filled_from'] ?? ''), true) ?: [];
        }
        unset($r);

        return $rows;
    }

    public function rowCounts(int $batchId): array
    {
        $st = $this->db->prepare(
            'SELECT status, COUNT(*) c FROM ' . self::ROW_TABLE . ' WHERE batch_id = :b GROUP BY status'
        );
        $st->execute([':b' => $batchId]);
        $out = ['draft' => 0, 'ready' => 0, 'skip' => 0, 'applied' => 0, 'total' => 0];
        foreach ($st as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
            $out['total'] += (int) $r['c'];
        }

        return $out;
    }

    public function findRowWithBatchStatus(int $rowId): ?array
    {
        $st = $this->db->prepare(
            'SELECT r.*, b.status AS batch_status
               FROM ' . self::ROW_TABLE . ' r
               JOIN ' . self::BATCH_TABLE . ' b ON b.id = r.batch_id
              WHERE r.id = :id'
        );
        $st->execute([':id' => $rowId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateRowField(int $rowId, string $field, mixed $value, array $allowedFields): void
    {
        // A column name cannot be bound as a parameter, so it is checked here
        // as well as in the service. Two independent checks, because the cost
        // of the identifier reaching SQL unvalidated is injection.
        if (!in_array($field, $allowedFields, true) || preg_match('/^[a-z][a-z0-9_]*$/', $field) !== 1) {
            throw new InvalidArgumentException('That field cannot be edited.');
        }
        $upd = $this->db->prepare('UPDATE ' . self::ROW_TABLE . ' SET `' . $field . '` = :v WHERE id = :id');
        $upd->execute([':v' => $value, ':id' => $rowId]);
    }

    public function markReadyMissing(int $batchId): int
    {
        $st = $this->db->prepare(
            'UPDATE ' . self::ROW_TABLE . " SET status = 'ready'
              WHERE batch_id = :b AND status = 'draft' AND last_name <> '' AND notes IS NULL"
        );
        $st->execute([':b' => $batchId]);

        return $st->rowCount();
    }

    public function markBatchApplied(int $batchId): void
    {
        $this->db->prepare(
            'UPDATE ' . self::ROW_TABLE . " SET status = 'applied' WHERE batch_id = :b AND status = 'ready'"
        )->execute([':b' => $batchId]);
        $this->db->prepare(
            'UPDATE ' . self::BATCH_TABLE . " SET status = 'applied', applied_at = NOW() WHERE id = :id"
        )->execute([':id' => $batchId]);
    }

    public function deleteBatch(int $batchId): void
    {
        $this->db->prepare('DELETE FROM ' . self::ROW_TABLE . ' WHERE batch_id = :id')->execute([':id' => $batchId]);
        $this->db->prepare('DELETE FROM ' . self::BATCH_TABLE . ' WHERE id = :id')->execute([':id' => $batchId]);
    }

    public function findCampus(int $campusId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name FROM campuses WHERE id = :id');
        $stmt->execute([':id' => $campusId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
