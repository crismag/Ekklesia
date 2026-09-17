<?php

declare(strict_types=1);

namespace App\Adapters\Portal;

use App\Contracts\AvailabilityAdapter;
use App\DTO\Availability\AvailabilityCommand;
use DateTimeImmutable;
use PDO;

/**
 * Portal-DB adapter for portal_unavailability.
 *
 * Lives in the same DB family as PortalAuthAdapter (christlike_mdb). Read /
 * write paths are kept symmetric with the auth adapter: distinct named
 * placeholders only, integers bound as PDO::PARAM_INT, dates formatted in PHP.
 */
final class PortalAvailabilityAdapter implements AvailabilityAdapter
{
    public function __construct(
        private readonly ?PDO $connection = null,
    ) {
    }

    /**
     * @return list<array{
     *   id:int,
     *   person_id:int,
     *   starts_on:DateTimeImmutable,
     *   ends_on:DateTimeImmutable,
     *   reason:?string,
     *   created_by_portal_user_id:int,
     *   created_at:DateTimeImmutable,
     *   updated_at:DateTimeImmutable
     * }>
     */
    public function listForPerson(int $personId, ?DateTimeImmutable $activeFrom = null): array
    {
        if ($this->connection === null) {
            return [];
        }

        $sql = 'SELECT unavailability_id          AS id,
                       person_id                  AS person_id,
                       starts_on                  AS starts_on,
                       ends_on                    AS ends_on,
                       reason                     AS reason,
                       created_by_portal_user_id  AS created_by_portal_user_id,
                       created_at                 AS created_at,
                       updated_at                 AS updated_at
                  FROM portal_unavailability
                 WHERE person_id = :person_id';

        $params = [':person_id' => $personId];
        if ($activeFrom !== null) {
            $sql .= ' AND ends_on >= :active_from';
            $params[':active_from'] = $activeFrom->format('Y-m-d');
        }
        $sql .= ' ORDER BY starts_on ASC, ends_on ASC, unavailability_id ASC';

        $stmt = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $type = $key === ':person_id' ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = $this->shapeRow($row);
        }
        return $rows;
    }

    /**
     * @return array{
     *   id:int,
     *   person_id:int,
     *   starts_on:DateTimeImmutable,
     *   ends_on:DateTimeImmutable,
     *   reason:?string,
     *   created_by_portal_user_id:int,
     *   created_at:DateTimeImmutable,
     *   updated_at:DateTimeImmutable
     * }|null
     */
    public function findById(int $unavailabilityId): ?array
    {
        if ($this->connection === null) {
            return null;
        }

        $stmt = $this->connection->prepare(
            'SELECT unavailability_id          AS id,
                    person_id                  AS person_id,
                    starts_on                  AS starts_on,
                    ends_on                    AS ends_on,
                    reason                     AS reason,
                    created_by_portal_user_id  AS created_by_portal_user_id,
                    created_at                 AS created_at,
                    updated_at                 AS updated_at
               FROM portal_unavailability
              WHERE unavailability_id = :id
              LIMIT 1'
        );
        $stmt->bindValue(':id', $unavailabilityId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->shapeRow($row);
    }

    public function create(AvailabilityCommand $command, int $createdByPortalUserId, DateTimeImmutable $now): int
    {
        if ($this->connection === null) {
            return 0;
        }

        $stmt = $this->connection->prepare(
            'INSERT INTO portal_unavailability
                 (person_id, starts_on, ends_on, reason,
                  created_by_portal_user_id, created_at, updated_at)
             VALUES
                 (:person_id, :starts_on, :ends_on, :reason,
                  :created_by, :created_at, :updated_at)'
        );
        $stmt->bindValue(':person_id',  $command->personId,                  PDO::PARAM_INT);
        $stmt->bindValue(':starts_on',  $command->startsOn->format('Y-m-d'), PDO::PARAM_STR);
        $stmt->bindValue(':ends_on',    $command->endsOn->format('Y-m-d'),   PDO::PARAM_STR);
        $stmt->bindValue(':reason',     $command->reason,                    $command->reason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':created_by', $createdByPortalUserId,              PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now->format('Y-m-d H:i:s'),         PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', $now->format('Y-m-d H:i:s'),         PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->connection->lastInsertId();
    }

    public function delete(int $unavailabilityId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $stmt = $this->connection->prepare('DELETE FROM portal_unavailability WHERE unavailability_id = :id');
        $stmt->bindValue(':id', $unavailabilityId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id:int,
     *   person_id:int,
     *   starts_on:DateTimeImmutable,
     *   ends_on:DateTimeImmutable,
     *   reason:?string,
     *   created_by_portal_user_id:int,
     *   created_at:DateTimeImmutable,
     *   updated_at:DateTimeImmutable
     * }
     */
    private function shapeRow(array $row): array
    {
        return [
            'id'                        => (int) $row['id'],
            'person_id'                 => (int) $row['person_id'],
            'starts_on'                 => new DateTimeImmutable((string) $row['starts_on']),
            'ends_on'                   => new DateTimeImmutable((string) $row['ends_on']),
            'reason'                    => $row['reason'] === null ? null : (string) $row['reason'],
            'created_by_portal_user_id' => (int) $row['created_by_portal_user_id'],
            'created_at'                => new DateTimeImmutable((string) $row['created_at']),
            'updated_at'                => new DateTimeImmutable((string) $row['updated_at']),
        ];
    }
}
