<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\MemberImportAdapter;
use App\Contracts\MemberImportRepository;

final class DefaultMemberImportRepository implements MemberImportRepository
{
    public function __construct(private readonly MemberImportAdapter $adapter)
    {
    }

    public function assertSchemaReady(): void
    {
        $this->adapter->assertSchemaReady();
    }

    public function createBatch(array $batch, array $rows): int
    {
        return $this->adapter->createBatch($batch, $rows);
    }

    public function saveDuplicateReport(int $batchId, array $report): void
    {
        $this->adapter->saveDuplicateReport($batchId, $report);
    }

    public function listBatches(int $limit): array
    {
        return $this->adapter->listBatches($limit);
    }

    public function findBatch(int $id): ?array
    {
        return $this->adapter->findBatch($id);
    }

    public function listRows(int $batchId, string $status = ''): array
    {
        return $this->adapter->listRows($batchId, $status);
    }

    public function rowCounts(int $batchId): array
    {
        return $this->adapter->rowCounts($batchId);
    }

    public function findRowWithBatchStatus(int $rowId): ?array
    {
        return $this->adapter->findRowWithBatchStatus($rowId);
    }

    public function updateRowField(int $rowId, string $field, mixed $value, array $allowedFields): void
    {
        $this->adapter->updateRowField($rowId, $field, $value, $allowedFields);
    }

    public function markReadyMissing(int $batchId): int
    {
        return $this->adapter->markReadyMissing($batchId);
    }

    public function markBatchApplied(int $batchId): void
    {
        $this->adapter->markBatchApplied($batchId);
    }

    public function deleteBatch(int $batchId): void
    {
        $this->adapter->deleteBatch($batchId);
    }

    public function findCampus(int $campusId): ?array
    {
        return $this->adapter->findCampus($campusId);
    }
}
