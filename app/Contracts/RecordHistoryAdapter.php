<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Read access to the audit trail of people and household records (audit_log
 * rows whose target is a person or a household).
 *
 * Criteria, already validated by RecordHistoryService:
 *   types        list<'person'|'household'>  (never empty)
 *   personId     ?int     target person
 *   householdId  ?int     target household
 *   action       ?string  exact action
 *   from, to     ?string  inclusive dates, Y-m-d
 *
 * Rows carry: id, occurred_at, action, target_type, target_id, summary,
 * details (JSON text or null), account_id, account_email, account_name,
 * actor_person_id, actor_first_name, actor_last_name, target_first_name,
 * target_last_name, target_household_name. Names are null when the record no
 * longer exists.
 */
interface RecordHistoryAdapter
{
    /** @param array<string,mixed> $criteria */
    public function count(array $criteria): int;

    /**
     * Newest first.
     *
     * @param array<string,mixed> $criteria
     * @return list<array<string,mixed>>
     */
    public function find(array $criteria, int $limit, int $offset): array;

    /**
     * Distinct actions recorded against people or households.
     *
     * @return list<string>
     */
    public function actions(): array;

    /**
     * People who have at least one history entry, by last name.
     *
     * @return list<array{id:int,first_name:string,last_name:string}>
     */
    public function peopleWithHistory(): array;

    public function personName(int $personId): ?string;

    public function householdName(int $householdId): ?string;
}
