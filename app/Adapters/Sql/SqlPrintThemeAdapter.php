<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Core\Database\MembersConnection;
use PDO;

/**
 * Records of PowerPoint calendar themes and their versions (the files live in
 * storage/private).
 */
final class SqlPrintThemeAdapter
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? MembersConnection::get();
    }

    /** @return list<array<string,mixed>> active themes, newest first, with their current version */
    public function listActive(): array
    {
        $rows = $this->db->query(
            "SELECT t.*, v.paper, v.orientation, v.warnings, v.created_at AS version_at, a.display_name, a.email
               FROM print_themes t
               JOIN print_theme_versions v ON v.theme_id = t.id AND v.version = t.current_version
          LEFT JOIN user_accounts a ON a.id = t.account_id
              WHERE t.status = 'active'
           ORDER BY t.updated_at DESC, t.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map($this->shape(...), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->db->prepare(
            "SELECT t.*, v.paper, v.orientation, v.warnings, v.created_at AS version_at, a.display_name, a.email
               FROM print_themes t
               JOIN print_theme_versions v ON v.theme_id = t.id AND v.version = t.current_version
          LEFT JOIN user_accounts a ON a.id = t.account_id
              WHERE t.id = :id"
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->shape($row) : null;
    }

    /** @return array<string,mixed>|null one version, with its model decoded */
    public function version(int $themeId, int $version): ?array
    {
        $st = $this->db->prepare('SELECT * FROM print_theme_versions WHERE theme_id = :t AND version = :v');
        $st->execute([':t' => $themeId, ':v' => $version]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $model = json_decode((string) $row['model'], true);

        return [
            'themeId' => (int) $row['theme_id'],
            'version' => (int) $row['version'],
            'paper' => (string) $row['paper'],
            'orientation' => (string) $row['orientation'],
            'model' => is_array($model) ? $model : null,
            'warnings' => json_decode((string) ($row['warnings'] ?? '[]'), true) ?: [],
        ];
    }

    public function createTheme(int $accountId, string $name): int
    {
        $st = $this->db->prepare('INSERT INTO print_themes (account_id, name) VALUES (:a, :n)');
        $st->execute([':a' => $accountId, ':n' => $name]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $model @param list<string> $warnings */
    public function addVersion(int $themeId, int $version, int $accountId, string $originalName, int $bytes, string $sha,
        string $paper, string $orientation, array $model, array $warnings): void
    {
        $st = $this->db->prepare(
            'INSERT INTO print_theme_versions (theme_id, version, original_name, bytes, sha256, paper, orientation, model, warnings, created_by)
             VALUES (:t, :v, :o, :b, :s, :p, :or, :m, :w, :a)'
        );
        $st->execute([
            ':t' => $themeId, ':v' => $version, ':o' => $originalName, ':b' => $bytes, ':s' => $sha, ':p' => $paper,
            ':or' => $orientation, ':m' => json_encode($model, JSON_UNESCAPED_UNICODE), ':w' => json_encode(array_values($warnings), JSON_UNESCAPED_UNICODE),
            ':a' => $accountId > 0 ? $accountId : null,
        ]);
        $up = $this->db->prepare('UPDATE print_themes SET current_version = :v WHERE id = :t');
        $up->execute([':v' => $version, ':t' => $themeId]);
    }

    public function latestVersion(int $themeId): int
    {
        $st = $this->db->prepare('SELECT COALESCE(MAX(version), 0) FROM print_theme_versions WHERE theme_id = :t');
        $st->execute([':t' => $themeId]);

        return (int) $st->fetchColumn();
    }

    /** @param array{name?:string,scope?:string,status?:string} $fields */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $params = [':id' => $id];
        foreach (['name', 'scope', 'status'] as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = $col . ' = :' . $col;
                $params[':' . $col] = $fields[$col];
            }
        }
        if ($sets === []) {
            return;
        }
        $st = $this->db->prepare('UPDATE print_themes SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $st->execute($params);
    }

    public function deleteTheme(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM print_themes WHERE id = :id');
        $st->execute([':id' => $id]);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function shape(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'accountId' => (int) $r['account_id'],
            'name' => (string) $r['name'],
            'scope' => (string) $r['scope'],
            'status' => (string) $r['status'],
            'version' => (int) $r['current_version'],
            'paper' => (string) $r['paper'],
            'orientation' => (string) $r['orientation'],
            'warnings' => json_decode((string) ($r['warnings'] ?? '[]'), true) ?: [],
            'creator' => (string) (($r['display_name'] ?? '') !== '' ? $r['display_name'] : ($r['email'] ?? '')),
            'updatedAt' => (string) ($r['version_at'] ?? $r['updated_at']),
        ];
    }
}
