<?php

declare(strict_types=1);

namespace App\DTO\Events;

final readonly class OccurrenceBatchResult
{
    /** @param int[] $insertedIds */
    public function __construct(public array $insertedIds) {}

    public function toArray(): array
    {
        return ['inserted_ids' => $this->insertedIds];
    }
}
