<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Back up the member database (MySQL), the visitors database (SQLite), and JSON
 * snapshots of events / schedules / members into the private archive store.
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
     * Copy the visitors SQLite database into the archive.
     *
     * VACUUM INTO writes a consistent copy even while sign-ups are being saved,
     * which copying the file directly would not guarantee.
     *
     * @return array{relative:string,filename:string,bytes:int}
     */
    public function backupVisitors(PDO $visitors): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'visitors-backup-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary file for the visitors backup.');
        }
        @unlink($tmp);
        try {
            $visitors->exec('VACUUM INTO ' . $visitors->quote($tmp));
            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new \RuntimeException('Could not read the visitors backup copy.');
            }
            $slot = $this->store->write('sqlite', 'visitors', 'sqlite', $bytes);
        } finally {
            @unlink($tmp);
        }
        return [
            'relative' => $slot['relative'],
            'filename' => $slot['filename'],
            'bytes' => (int) ($slot['bytes'] ?? strlen($bytes)),
        ];
    }

    /**
     * @return list<array{relative:string,filename:string,bytes:int,kind:string}>
     */
    public function backupStates(PDO $members): array
    {
        $events = $this->snapshotTables($members, ['events', 'event_occurrences', 'event_campuses', 'event_tags']);
        $e = $this->store->write('state', 'events', 'json', $this->encode('events', $events));
        $m = $this->store->write('state', 'members', 'json', $this->encode('members', $this->snapshotMembers($members)));
        $schedules = $this->snapshotTables($members, ['assignments', 'rosters', 'roster_slots', 'roster_assignments']);
        $s = $this->store->write('state', 'schedules', 'json', $this->encode('schedules', $schedules));

        return [$this->meta($e, 'events'), $this->meta($m, 'members'), $this->meta($s, 'schedules')];
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
        // Views (the spreadsheet-shaped sheet_* views) after every table they read.
        // The DEFINER is dropped so the dump restores under whichever account runs it.
        $views = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM) ?: [];
        foreach ($views as $row) {
            $view = str_replace('`', '', (string) $row[0]);
            $create = $pdo->query('SHOW CREATE VIEW `' . $view . '`')->fetch(PDO::FETCH_ASSOC) ?: [];
            $ddl = (string) preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', (string) ($create['Create View'] ?? ''));
            if ($ddl !== '') {
                $buf .= 'DROP VIEW IF EXISTS `' . $view . "`;\n" . $ddl . ";\n\n";
            }
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
                'SELECT p.id, p.first_name, p.middle_name, p.last_name, p.email, p.mobile_phone,
                        p.address_line1, p.city, p.region, p.postal_code, p.country,
                        p.birth_month, p.birth_day, p.birth_year, p.member_since, p.membership_status_id,
                        p.campus_id, p.member_type_id, p.household_id
                   FROM people p
               ORDER BY p.last_name, p.first_name'
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
