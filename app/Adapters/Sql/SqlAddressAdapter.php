<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\AddressRepository;
use PDO;

/**
 * Addresses as the member database stores them.
 *
 * The sweep geocodes households — their coordinates are what the profile map
 * shows — and corrects address text on both households and people.
 */
final class SqlAddressAdapter implements AddressRepository
{
    /** Only these columns may be written by an address correction. */
    private const ADDRESS_FIELDS = [
        'city' => 'city', 'state' => 'region',
        'zip' => 'postal_code', 'country' => 'country',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return list<array{
     *   family_id:int, street:string, address2:string, city:string,
     *   state:string, zip:string, country:string, lat:?float, lng:?float
     * }>
     */
    public function listFamilyAddresses(bool $onlyMissingCoordinates, ?int $limit = null, int $offset = 0): array
    {
        $sql = "SELECT id, address_line1, address_line2, city, region,
                       postal_code, country, latitude, longitude
                  FROM households
                 WHERE TRIM(COALESCE(address_line1, '')) <> ''";
        if ($onlyMissingCoordinates) {
            $sql .= ' AND (latitude IS NULL OR latitude = 0 OR longitude IS NULL OR longitude = 0)';
        }
        $sql .= ' ORDER BY id';
        if ($limit !== null && $limit > 0) {
            // An offset needs a limit in MySQL; a bare offset is not valid.
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        $out = [];
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'family_id' => (int) $r['id'],
                'street' => trim((string) $r['address_line1']),
                'address2' => trim((string) $r['address_line2']),
                'city' => trim((string) $r['city']),
                'state' => trim((string) $r['region']),
                'zip' => trim((string) $r['postal_code']),
                'country' => trim((string) $r['country']),
                'lat' => $r['latitude'] === null ? null : (float) $r['latitude'],
                'lng' => $r['longitude'] === null ? null : (float) $r['longitude'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{
     *   person_id:int, family_id:int, street:string, address2:string,
     *   city:string, state:string, zip:string, country:string
     * }>
     */
    public function listPersonAddresses(?int $limit = null): array
    {
        $sql = "SELECT id, COALESCE(household_id, 0) AS household_id, address_line1, address_line2,
                       city, region, postal_code, country
                  FROM people
                 WHERE TRIM(COALESCE(address_line1, '')) <> ''
                 ORDER BY id";
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $out = [];
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'person_id' => (int) $r['id'],
                'family_id' => (int) $r['household_id'],
                'street' => trim((string) $r['address_line1']),
                'address2' => trim((string) $r['address_line2']),
                'city' => trim((string) $r['city']),
                'state' => trim((string) $r['region']),
                'zip' => trim((string) $r['postal_code']),
                'country' => trim((string) $r['country']),
            ];
        }

        return $out;
    }

    public function saveFamilyCoordinates(int $familyId, float $lat, float $lng): void
    {
        $this->db->prepare(
            'UPDATE households
                SET latitude = :lat, longitude = :lng, updated_at = NOW()
              WHERE id = :id'
        )->execute([':lat' => $lat, ':lng' => $lng, ':id' => $familyId]);
    }

    /** @param array<string,string> $fields */
    public function saveFamilyAddressFields(int $familyId, array $fields): void
    {
        $this->writeFields('households', $familyId, $fields);
    }

    /** @param array<string,string> $fields */
    public function savePersonAddressFields(int $personId, array $fields): void
    {
        $this->writeFields('people', $personId, $fields);
    }

    public function personLabel(int $personId): string
    {
        $s = $this->db->prepare(
            "SELECT TRIM(CONCAT(COALESCE(last_name,''), ', ', COALESCE(first_name,'')))
               FROM people WHERE id = :id"
        );
        $s->execute([':id' => $personId]);

        return trim((string) ($s->fetchColumn() ?: ''), ' ,');
    }

    public function familyLabel(int $familyId): string
    {
        $s = $this->db->prepare('SELECT name FROM households WHERE id = :id');
        $s->execute([':id' => $familyId]);

        return (string) ($s->fetchColumn() ?: '');
    }

    /**
     * Whitelisted column writes.
     *
     * The column names come from a fixed map rather than from the caller's
     * keys, so a field name arriving from anywhere else cannot become part of
     * the statement.
     *
     * @param 'households'|'people' $table
     * @param array<string,string> $fields
     */
    private function writeFields(string $table, int $id, array $fields): void
    {
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $name => $value) {
            $column = self::ADDRESS_FIELDS[$name] ?? null;
            if ($column === null) {
                continue;
            }
            $sets[] = $column . ' = :' . $name;
            $params[':' . $name] = $value;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = NOW()';

        $this->db->prepare(
            'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = :id'
        )->execute($params);
    }
}
