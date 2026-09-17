<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Option manager — edit the shared `list_lst` option lists used across the
 * portal (classifications, family roles, Member Type). Direct-PDO, self-contained,
 * NO dependency on any ChurchCRM PHP.
 *
 * Only a whitelisted set of list ids is manageable, and each knows where its
 * options are referenced so a delete can be blocked when an option is in use.
 */
final readonly class OptionAdminService
{
    /** listId => [label, usageTable, usageCol]. */
    private const LISTS = [
        1  => ['label' => 'Classifications', 'desc' => "A person's status (Member, Guest, …).", 'table' => 'person_per',    'col' => 'per_cls_ID'],
        2  => ['label' => 'Family roles',    'desc' => 'How each person relates to their household: Husband, Wife, Child. Deliberately not "head of household" — one household can hold more than one family, and nobody needs to be nominated as its head.', 'table' => 'person_per', 'col' => 'per_fmr_ID'],
        13 => ['label' => 'Member types',    'desc' => 'Ministry grouping (Radical, Trailblazer, G&A).', 'table' => 'person_custom', 'col' => 'c1'],
    ];

    public function __construct(private PDO $db)
    {
    }

    private function assertList(int $listId): array
    {
        if (!isset(self::LISTS[$listId])) {
            throw new InvalidArgumentException('That option list cannot be edited here.');
        }
        return self::LISTS[$listId];
    }

    /** @return array<int,array{label:string,desc:string,options:list<array<string,mixed>>}> */
    public function all(): array
    {
        $out = [];
        foreach (self::LISTS as $listId => $meta) {
            $out[$listId] = [
                'label' => $meta['label'],
                'desc' => $meta['desc'],
                'options' => $this->options($listId),
            ];
        }
        return $out;
    }

    /** @return list<array{id:int,name:string,sequence:int,usage:int}> */
    public function options(int $listId): array
    {
        $meta = $this->assertList($listId);
        $stmt = $this->db->prepare('SELECT lst_OptionID AS id, lst_OptionName AS name, lst_OptionSequence AS seq
                                      FROM list_lst WHERE lst_ID = :l ORDER BY lst_OptionSequence, lst_OptionName');
        $stmt->execute([':l' => $listId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'name' => (string) $r['name'], 'sequence' => (int) $r['seq'],
                'usage' => $this->usage($listId, (int) $r['id']),
            ];
        }
        return $out;
    }

    /** How many records currently use this option. */
    public function usage(int $listId, int $optionId): int
    {
        $meta = $this->assertList($listId);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$meta['table']}` WHERE `{$meta['col']}` = :o");
        $stmt->execute([':o' => $optionId]);
        return (int) $stmt->fetchColumn();
    }

    public function add(int $listId, string $name): void
    {
        $this->assertList($listId);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Option name is required.');
        }
        if (mb_strlen($name) > 50) {
            $name = mb_substr($name, 0, 50);
        }
        $nextId = (int) $this->scalar('SELECT COALESCE(MAX(lst_OptionID), 0) + 1 FROM list_lst WHERE lst_ID = :l', $listId);
        $nextSeq = (int) $this->scalar('SELECT COALESCE(MAX(lst_OptionSequence), 0) + 1 FROM list_lst WHERE lst_ID = :l', $listId);
        $this->db->prepare('INSERT INTO list_lst (lst_ID, lst_OptionID, lst_OptionSequence, lst_OptionName) VALUES (:l, :o, :s, :n)')
            ->execute([':l' => $listId, ':o' => $nextId, ':s' => $nextSeq, ':n' => $name]);
    }

    public function rename(int $listId, int $optionId, string $name): void
    {
        $this->assertList($listId);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Option name is required.');
        }
        $this->db->prepare('UPDATE list_lst SET lst_OptionName = :n WHERE lst_ID = :l AND lst_OptionID = :o')
            ->execute([':n' => mb_substr($name, 0, 50), ':l' => $listId, ':o' => $optionId]);
    }

    /** Move an option up or down by swapping sequence with its neighbour. */
    public function move(int $listId, int $optionId, string $dir): void
    {
        $this->assertList($listId);
        $opts = $this->options($listId);
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
        $upd = $this->db->prepare('UPDATE list_lst SET lst_OptionSequence = :s WHERE lst_ID = :l AND lst_OptionID = :o');
        $this->db->beginTransaction();
        try {
            $upd->execute([':s' => $b['sequence'], ':l' => $listId, ':o' => $a['id']]);
            $upd->execute([':s' => $a['sequence'], ':l' => $listId, ':o' => $b['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Delete an option. Refuses when it's still assigned to people. */
    public function delete(int $listId, int $optionId): void
    {
        $meta = $this->assertList($listId);
        $used = $this->usage($listId, $optionId);
        if ($used > 0) {
            throw new RuntimeException("This option is used by $used record(s). Reassign them first.");
        }
        $this->db->prepare('DELETE FROM list_lst WHERE lst_ID = :l AND lst_OptionID = :o')
            ->execute([':l' => $listId, ':o' => $optionId]);
    }

    private function scalar(string $sql, int $listId)
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':l' => $listId]);
        return $stmt->fetchColumn();
    }
}
