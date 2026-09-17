<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * People reachable through a contact detail (an email address or a phone
 * number), for sign-in.
 *
 * A contact detail is not a person: a household shares one, so a lookup returns
 * everyone who has it and never picks one of them.
 */
interface PersonContactDirectory
{
    /**
     * Everyone whose email, mobile or home phone (or household phone) is this
     * identifier, oldest record first.
     *
     * @return list<array{personId:int,firstName:string,lastName:string}>
     */
    public function peopleWithContact(string $identifier): array;

    /**
     * What sign-in needs to know about one person.
     *
     * @return array{
     *   personId:int, firstName:string, lastName:string, email:?string, phone:?string,
     *   defaultPassword:string, isPortalAdmin:bool, canManageGroups:bool,
     *   leaderMinistryIds:list<int>, campusId:?int
     * }|null
     */
    public function identityFor(int $personId): ?array;
}
