<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\HouseholdLinkRepository;
use PDO;

final class SqlHouseholdLinkAdapter implements HouseholdLinkRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array{household_id:int,related_household_id:int,relationship:string}> */
    public function all(): array
    {
        $rows = $this->db->query(
            'SELECT household_id, related_household_id, relationship
               FROM household_links ORDER BY created_at, household_id, related_household_id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map($this->row(...), $rows);
    }

    /** @return list<array{household_id:int,related_household_id:int,relationship:string}> */
    public function forHousehold(int $householdId): array
    {
        $st = $this->db->prepare(
            'SELECT household_id, related_household_id, relationship
               FROM household_links
              WHERE household_id = :a OR related_household_id = :b
              ORDER BY created_at, household_id, related_household_id'
        );
        $st->execute([':a' => $householdId, ':b' => $householdId]);

        return array_map($this->row(...), $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function replace(int $householdId, int $relatedHouseholdId, string $relationship): void
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $this->remove($householdId, $relatedHouseholdId);
            $this->db->prepare(
                'INSERT INTO household_links (household_id, related_household_id, relationship) VALUES (:a, :b, :rel)'
            )->execute([':a' => $householdId, ':b' => $relatedHouseholdId, ':rel' => $relationship]);
            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function remove(int $x, int $y): void
    {
        $this->db->prepare(
            'DELETE FROM household_links
              WHERE (household_id = :x1 AND related_household_id = :y1)
                 OR (household_id = :y2 AND related_household_id = :x2)'
        )->execute([':x1' => $x, ':y1' => $y, ':x2' => $x, ':y2' => $y]);
    }

    public function removeHousehold(int $householdId): void
    {
        $this->db->prepare('DELETE FROM household_links WHERE household_id = :a OR related_household_id = :b')
            ->execute([':a' => $householdId, ':b' => $householdId]);
    }

    /**
     * @param array<string,mixed> $r
     * @return array{household_id:int,related_household_id:int,relationship:string}
     */
    private function row(array $r): array
    {
        return [
            'household_id' => (int) $r['household_id'],
            'related_household_id' => (int) $r['related_household_id'],
            'relationship' => (string) $r['relationship'],
        ];
    }
}
