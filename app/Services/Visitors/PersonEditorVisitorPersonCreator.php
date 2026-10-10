<?php

declare(strict_types=1);

namespace App\Services\Visitors;

use App\Contracts\VisitorPersonCreator;
use App\Services\PersonAdminService;

/**
 * Promoted visitors become people through the member editor's own save, so
 * its validation and the person.created audit entry apply to them too.
 */
final class PersonEditorVisitorPersonCreator implements VisitorPersonCreator
{
    public function __construct(private readonly PersonAdminService $people) {}

    public function create(array $fields, int $accountId): int
    {
        unset($fields['id']);   // always a new person

        return $this->people->save($fields, $accountId);
    }
}
