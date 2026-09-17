<?php

declare(strict_types=1);

namespace App\Contracts;

/** Source-agnostic mirror of VisitorMemberAdapter. See that contract for the notes. */
interface VisitorMemberRepository
{
    /**
     * People with this last name or this email (either may be ''), at most 200:
     * the candidates the matcher compares a visitor against. Each row has id,
     * first_name, last_name, birth_month, birth_year, city, and 'emails' /
     * 'phones' lists.
     *
     * @return list<array<string,mixed>>
     */
    public function personCandidates(string $lastName, string $email): array;

    /**
     * @param list<int> $personIds
     * @return array<int,array{id:int,name:string,city:string}>
     */
    /**
     * Who each account is, for "promoted by": the account's person's name, else
     * its display name, else its email.
     *
     * @param list<int> $accountIds
     * @return array<int,string>
     */
    public function accountNames(array $accountIds): array;

    public function peopleByIds(array $personIds): array;

    /** @return list<array{id:int,name:string}> membership_statuses in their order */
    public function membershipStatuses(): array;

    /** @return list<array{id:int,name:string}> */
    public function campuses(): array;

    public function memberTypeIdByName(string $name): ?int;

    /**
     * @param list<int> $eventIds
     * @return array<int,array{id:int,title:string,starts_on:?string,start_time:?string,is_active:bool}>
     */
    public function eventsByIds(array $eventIds): array;

    /**
     * @param list<int> $occurrenceIds
     * @return array<int,array{id:int,event_id:int,starts_at:string,status:string,title:?string}>
     */
    public function occurrencesByIds(array $occurrenceIds): array;

    /**
     * Active events with a scheduled date from $fromDate to $toDate, soonest first:
     * id, title, next_starts_at, next_occurrence_id.
     *
     * @return list<array<string,mixed>>
     */
    public function upcomingEvents(string $fromDate, string $toDate): array;

    /**
     * Run $work in one member database transaction (joining one already open).
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function transaction(callable $work): mixed;

    /**
     * Details of a promoted person the person editor does not write: a birth
     * month or day given without the other, and map coordinates.
     */
    public function completePromotedPerson(int $personId, ?int $birthMonth, ?int $birthDay, ?float $latitude, ?float $longitude): void;
}
