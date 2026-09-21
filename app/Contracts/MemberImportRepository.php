<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Persistence for the campus member import staging area.
 *
 * Source-agnostic: the service depends on this, never on a driver or a schema.
 * Worksheet parsing, merging, de-duplication and readiness rules stay in the
 * service; only persistence crosses this boundary.
 */
interface MemberImportRepository
{
    /**
     * Fail loudly when the staging tables are absent.
     *
     * The staging schema is owned by database/members/001_schema.sql.
     * Application code must not create it: a runtime CREATE TABLE is a second,
     * invisible migration system that silently diverges from the real one.
     *
     * @throws \RuntimeException when the schema has not been migrated
     */
    public function assertSchemaReady(): void;

    /**
     * Persist a staging batch and its rows atomically.
     *
     * @param array<string,mixed>       $batch
     * @param list<array<string,mixed>> $rows
     * @return int the new batch id
     */
    public function createBatch(array $batch, array $rows): int;

    /** @return list<array<string,mixed>> */
    /** @param array<string,mixed> $report */
    public function saveDuplicateReport(int $batchId, array $report): void;

    public function listBatches(int $limit): array;

    /** @return array<string,mixed>|null */
    public function findBatch(int $id): ?array;

    /** @return list<array<string,mixed>> */
    public function listRows(int $batchId, string $status = ''): array;

    /** @return array<string,int> */
    public function rowCounts(int $batchId): array;

    /** @return array<string,mixed>|null */
    public function findRowWithBatchStatus(int $rowId): ?array;

    /**
     * @param list<string> $allowedFields guarded again here: the column name is
     *                                    an SQL identifier and cannot be bound
     */
    public function updateRowField(int $rowId, string $field, mixed $value, array $allowedFields): void;

    public function markReadyMissing(int $batchId): int;

    public function markBatchApplied(int $batchId): void;

    public function deleteBatch(int $batchId): void;

    /** @return array<string,mixed>|null */
    public function findCampus(int $campusId): ?array;
}
