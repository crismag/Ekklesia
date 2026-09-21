<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Core\Database\MembersConnection;
use PDO;

/**
 * Records of background pictures (the files live in storage/private).
 */
final class SqlPrintBackgroundAdapter
{
    private PDO $db;

    public function __construct(?PDO $connection = null)
    {
        $this->db = $connection ?? MembersConnection::get();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM print_backgrounds WHERE id = :id');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->shape($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function listOwnedBy(int $accountId): array
    {
        $st = $this->db->prepare('SELECT * FROM print_backgrounds WHERE account_id = :a ORDER BY id DESC');
        $st->execute([':a' => $accountId]);

        return array_map($this->shape(...), $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function listAll(): array
    {
        return array_map($this->shape(...), $this->db->query('SELECT * FROM print_backgrounds ORDER BY id DESC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array{count:int,bytes:int} */
    public function usageOf(int $accountId): array
    {
        $st = $this->db->prepare('SELECT COUNT(*) c, COALESCE(SUM(bytes), 0) b FROM print_backgrounds WHERE account_id = :a');
        $st->execute([':a' => $accountId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'b' => 0];

        return ['count' => (int) $r['c'], 'bytes' => (int) $r['b']];
    }

    public function insert(int $accountId, string $label, string $originalName, int $w, int $h, int $bytes, string $sha): int
    {
        $st = $this->db->prepare(
            'INSERT INTO print_backgrounds (account_id, label, original_name, width_px, height_px, bytes, sha256)
             VALUES (:a, :l, :o, :w, :h, :b, :s)'
        );
        $st->execute([':a' => $accountId, ':l' => $label, ':o' => $originalName, ':w' => $w, ':h' => $h, ':b' => $bytes, ':s' => $sha]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM print_backgrounds WHERE id = :id');
        $st->execute([':id' => $id]);
    }

    /**
     * Saved views whose design uses this picture.
     *
     * @return list<array{id:int,name:string,visibility:string,account_id:int}>
     */
    public function viewsUsing(int $id): array
    {
        $st = $this->db->prepare(
            "SELECT id, name, visibility, account_id FROM calendar_views
              WHERE JSON_UNQUOTE(JSON_EXTRACT(config, '$.background.mode')) = 'image'
                AND CAST(JSON_EXTRACT(config, '$.background.id') AS UNSIGNED) = :id"
        );
        $st->execute([':id' => $id]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'],
            'visibility' => (string) $r['visibility'], 'account_id' => (int) $r['account_id'],
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function shape(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'accountId' => (int) $r['account_id'],
            'label' => (string) $r['label'],
            'originalName' => (string) ($r['original_name'] ?? ''),
            'width' => (int) $r['width_px'],
            'height' => (int) $r['height_px'],
            'bytes' => (int) $r['bytes'],
            'sha256' => (string) $r['sha256'],
            'createdAt' => (string) $r['created_at'],
        ];
    }
}
