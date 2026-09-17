<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Backup MySQL databases and JSON snapshots of events / schedules / members
 * into the private archive store.
 */
final class MaintenanceBackupService
{
    public function __construct(
        private PrivateArchiveStore $store = new PrivateArchiveStore(),
    ) {
    }

    public function store(): PrivateArchiveStore
    {
        return $this->store;
    }

    /**
     * @return array{relative:string,filename:string,bytes:int}
     */
    public function backupMysql(PDO $pdo, string $dbLabel, string $dbName): array
    {
        $sql = $this->dumpDatabase($pdo, $dbName);
        $slot = $this->store->write('mysql', $dbLabel, 'sql', $sql);
        return [
            'relative' => $slot['relative'],
            'filename' => $slot['filename'],
            'bytes' => (int) ($slot['bytes'] ?? strlen($sql)),
        ];
    }

    /**
     * @return list<array{relative:string,filename:string,bytes:int,kind:string}>
     */
    public function backupStates(?PDO $people, ?PDO $portal): array
    {
        $out = [];
        if ($people instanceof PDO) {
            $events = $this->snapshotTables($people, [
                'events_event', 'event_occurrence', 'events_event_campus',
            ]);
            $members = $this->snapshotMembers($people);
            $e = $this->store->write('state', 'events', 'json', $this->encode('events', $events));
            $m = $this->store->write('state', 'members', 'json', $this->encode('members', $members));
            $out[] = $this->meta($e, 'events');
            $out[] = $this->meta($m, 'members');
        }
        if ($portal instanceof PDO) {
            $schedules = $this->snapshotTables($portal, [
                'schedule_roster', 'schedule_roster_slot', 'schedule_roster_assignment',
            ]);
            $s = $this->store->write('state', 'schedules', 'json', $this->encode('schedules', $schedules));
            $out[] = $this->meta($s, 'schedules');
        }
        if ($out === []) {
            throw new \RuntimeException('No database connection was available for a state backup.');
        }
        return $out;
    }

    /**
     * @param list<array{campus:string,rows:list<array<string,mixed>>}> $sheets
     * @return array{relative:string,filename:string,bytes:int}
     */
    public function exportMemberWorkbook(array $sheets): array
    {
        $bytes = (new MemberRosterXlsxWriter())->build($sheets);
        $slot = $this->store->write('members', 'roster', 'xlsx', $bytes);
        return [
            'relative' => $slot['relative'],
            'filename' => $slot['filename'],
            'bytes' => (int) ($slot['bytes'] ?? strlen($bytes)),
        ];
    }

    private function dumpDatabase(PDO $pdo, string $dbName): string
    {
        $now = gmdate('c');
        $buf = "-- Christlikeness portal MySQL backup\n-- database: {$dbName}\n-- generated: {$now}\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) ?: [];
        foreach ($tables as $row) {
            $table = (string) $row[0];
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch(PDO::FETCH_ASSOC) ?: [];
            $ddl = (string) ($create['Create Table'] ?? '');
            $buf .= 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $ddl . ";\n\n";
            $sel = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`', PDO::FETCH_ASSOC);
            if ($sel === false) {
                continue;
            }
            $n = 0;
            foreach ($sel as $record) {
                if ($n === 0) {
                    $cols = array_map(static fn ($c) => '`' . str_replace('`', '', (string) $c) . '`', array_keys($record));
                    $buf .= 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ") VALUES\n";
                } else {
                    $buf .= ",\n";
                }
                $vals = [];
                foreach ($record as $v) {
                    $vals[] = $this->sqlLiteral($v);
                }
                $buf .= '(' . implode(',', $vals) . ')';
                $n++;
                if ($n >= 80) {
                    $buf .= ";\n";
                    $n = 0;
                }
            }
            if ($n > 0) {
                $buf .= ";\n";
            }
            $buf .= "\n";
        }
        $buf .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $buf;
    }

    /**
     * @param list<string> $tables
     * @return array<string,list<array<string,mixed>>>
     */
    public function snapshotTables(PDO $pdo, array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            try {
                $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $out[$table] = $rows;
            } catch (\Throwable) {
                $out[$table] = [];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function snapshotMembers(PDO $pdo): array
    {
        try {
            return $pdo->query(
                'SELECT p.per_ID, p.per_FirstName, p.per_MiddleName, p.per_LastName, p.per_Email,
                        p.per_CellPhone, p.per_Address1, p.per_City, p.per_State, p.per_Zip, p.per_Country,
                        p.per_BirthMonth, p.per_BirthDay, p.per_BirthYear, p.per_MembershipDate, p.per_cls_ID,
                        pca.campus_id, pc.c1 AS member_type_id
                   FROM person_per p
                   LEFT JOIN person_campus_affiliation pca ON pca.person_id = p.per_ID AND pca.is_primary = 1
                   LEFT JOIN person_custom pc ON pc.per_ID = p.per_ID
               ORDER BY p.per_LastName, p.per_FirstName'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function encode(string $kind, mixed $payload): string
    {
        return json_encode([
            'generated_at' => gmdate('c'),
            'kind' => $kind,
            'data' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    /** @param array{relative:string,filename:string,bytes?:int} $slot */
    private function meta(array $slot, string $kind): array
    {
        return [
            'relative' => $slot['relative'],
            'filename' => $slot['filename'],
            'bytes' => (int) ($slot['bytes'] ?? 0),
            'kind' => $kind,
        ];
    }

    private function sqlLiteral(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        $s = (string) $v;
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '0x' . bin2hex($s);
        }
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
    }
}
