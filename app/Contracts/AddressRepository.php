<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Reading and correcting the addresses already on file.
 *
 * Separate from the import: these are records that have been applied, so every
 * write here changes a member's stored address. The write methods are narrow
 * for that reason — coordinates and address fields are updated independently,
 * so a sweep that is only filling in coordinates cannot touch anything else.
 */
interface AddressRepository
{
    /**
     * Every family with something in the street line.
     *
     * @return list<array{
     *   family_id:int, street:string, address2:string, city:string,
     *   state:string, zip:string, country:string, lat:?float, lng:?float
     * }>
     */
    public function listFamilyAddresses(bool $onlyMissingCoordinates, ?int $limit = null, int $offset = 0): array;

    /**
     * People carrying their own address rather than the family's.
     *
     * @return list<array{
     *   person_id:int, family_id:int, street:string, address2:string,
     *   city:string, state:string, zip:string, country:string
     * }>
     */
    public function listPersonAddresses(?int $limit = null): array;

    public function saveFamilyCoordinates(int $familyId, float $lat, float $lng): void;

    /**
     * @param array<string,string> $fields subset of city, state, zip, country
     */
    public function saveFamilyAddressFields(int $familyId, array $fields): void;

    /**
     * @param array<string,string> $fields subset of city, state, zip, country
     */
    public function savePersonAddressFields(int $personId, array $fields): void;

    /** Display name for a person, so a report can say who needs attention. */
    public function personLabel(int $personId): string;

    /** Surname of a family, for the same reason. */
    public function familyLabel(int $familyId): string;
}
