<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Creates a member record for a promoted visitor, through the same path the
 * member editor uses (PersonAdminService::save), so its rules and audit apply.
 */
interface VisitorPersonCreator
{
    /**
     * @param array<string,mixed> $fields person editor fields
     * @return int the new people.id
     */
    public function create(array $fields, int $accountId): int;
}
