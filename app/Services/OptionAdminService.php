<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Option manager — edit the small named lists used across the portal
 * (membership statuses, household roles, member types). Direct-PDO,
 * self-contained.
 *
 * Only a whitelisted set of tables is manageable, and each knows where its
 * options are referenced so a delete can be blocked when an option is in use.
 */
final readonly class OptionAdminService
{
    /** table => label, description, and the people column that references it. */
    private const LISTS = [
        'membership_statuses' => ['label' => 'Classifications', 'desc' => "A person's status (Member, Guest, …).", 'col' => 'membership_status_id'],
        'household_roles'     => ['label' => 'Family roles',    'desc' => 'How each person relates to their household: Husband, Wife, Child. Deliberately not "head of household" — one household can hold more than one family, and nobody needs to be nominated as its head.', 'col' => 'household_role_id'],
        'member_types'        => ['label' => 'Member types',    'desc' => 'Ministry grouping (Radical, Trailblazer, G&A).', 'col' => 'member_type_id'],
    ];

    public function __construct(private PDO $db)
    {
    }

    /** @return array{label:string,desc:string,col:string} */
    private function assertList(string $list): array
    {
        if (!isset(self::LISTS[$list])) {
            throw new InvalidArgumentException('That option list cannot be edited here.');
        }
        return self::LISTS[$list];
    }

    /** @return array<string,array{label:string,desc:string,options:list<array<string,mixed>>}> */
    public function all(): array
    {
        $out = [];
        foreach (self::LISTS as $list => $meta) {
            $out[$list] = [
                'label' => $meta['label'],
                'desc' => $meta['desc'],
                'options' => $this->options($list),
            ];
        }
        return $out;
    }

    /** @return list<array{id:int,name:string,sort_order:int,usage:int}> */
    public function options(string $list): array
    {
        $this->assertList($list);
        $rows = $this->db->query("SELECT id, name, sort_order FROM `$list` ORDER BY sort_order, name")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'name' => (string) $r['name'], 'sort_order' => (int) $r['sort_order'],
                'usage' => $this->usage($list, (int) $r['id']),
            ];
        }
        return $out;
    }

    /** How many people currently use this option. */
    public function usage(string $list, int $optionId): int
    {
        $meta = $this->assertList($list);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM people WHERE `{$meta['col']}` = :o");
        $stmt->execute([':o' => $optionId]);
        return (int) $stmt->fetchColumn();
    }

    public function add(string $list, string $name): void
    {
        $this->assertList($list);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Option name is required.');
        }
        if (mb_strlen($name) > 50) {
            $name = mb_substr($name, 0, 50);
        }
        $this->assertNameFree($list, $name, 0);
        $nextSeq = (int) $this->db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `$list`")->fetchColumn();
        $this->db->prepare("INSERT INTO `$list` (name, sort_order) VALUES (:n, :s)")
            ->execute([':n' => $name, ':s' => $nextSeq]);
    }

    public function rename(string $list, int $optionId, string $name): void
    {
        $this->assertList($list);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Option name is required.');
        }
        $name = mb_substr($name, 0, 50);
        $this->assertNameFree($list, $name, $optionId);
        $this->db->prepare("UPDATE `$list` SET name = :n WHERE id = :o")
            ->execute([':n' => $name, ':o' => $optionId]);
    }

    /** Move an option up or down by swapping sort order with its neighbour. */
    public function move(string $list, int $optionId, string $dir): void
    {
        $this->assertList($list);
        $opts = $this->options($list);
        $idx = null;
        foreach ($opts as $i => $o) {
            if ($o['id'] === $optionId) { $idx = $i; break; }
        }
        if ($idx === null) {
            return;
        }
        $swapIdx = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($swapIdx < 0 || $swapIdx >= count($opts)) {
            return;
        }
        $a = $opts[$idx];
        $b = $opts[$swapIdx];
        // Equal sort orders would swap to nothing; fall back to list positions.
        [$seqA, $seqB] = $a['sort_order'] === $b['sort_order'] ? [$swapIdx + 1, $idx + 1] : [$b['sort_order'], $a['sort_order']];
        $upd = $this->db->prepare("UPDATE `$list` SET sort_order = :s WHERE id = :o");
        $this->db->beginTransaction();
        try {
            $upd->execute([':s' => $seqA, ':o' => $a['id']]);
            $upd->execute([':s' => $seqB, ':o' => $b['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Delete an option. Refuses when it's still assigned to people. */
    public function delete(string $list, int $optionId): void
    {
        $this->assertList($list);
        $used = $this->usage($list, $optionId);
        if ($used > 0) {
            throw new RuntimeException("This option is used by $used record(s). Reassign them first.");
        }
        $this->db->prepare("DELETE FROM `$list` WHERE id = :o")->execute([':o' => $optionId]);
    }

    /** Names are unique per list; say so rather than surfacing a key violation. */
    private function assertNameFree(string $list, string $name, int $exceptId): void
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `$list` WHERE name = :n AND id <> :id");
        $stmt->execute([':n' => $name, ':id' => $exceptId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('An option with that name already exists.');
        }
    }
}
