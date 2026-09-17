<?php

declare(strict_types=1);

namespace App\Adapters\ChurchCRM;

use App\Contracts\AddressRepository;
use PDO;

/**
 * Addresses as ChurchCRM stores them.
 *
 * Coordinates live on the family, not the person — family_fam carries
 * fam_Latitude and fam_Longitude and person_per has no equivalent — so a
 * person's location is their family's. That is why the sweep geocodes families
 * and corrects address text on both.
 */
final class ChurchCrmAddressAdapter implements AddressRepository
{
    /** Only these columns may be written by an address correction. */
    private const FAMILY_FIELDS = [
        'city' => 'fam_City', 'state' => 'fam_State',
        'zip' => 'fam_Zip', 'country' => 'fam_Country',
    ];

    private const PERSON_FIELDS = [
        'city' => 'per_City', 'state' => 'per_State',
        'zip' => 'per_Zip', 'country' => 'per_Country',
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
        $sql = "SELECT fam_ID, fam_Address1, fam_Address2, fam_City, fam_State,
                       fam_Zip, fam_Country, fam_Latitude, fam_Longitude
                  FROM family_fam
                 WHERE TRIM(COALESCE(fam_Address1, '')) <> ''";
        if ($onlyMissingCoordinates) {
            $sql .= ' AND (fam_Latitude IS NULL OR fam_Latitude = 0 OR fam_Longitude IS NULL OR fam_Longitude = 0)';
        }
        $sql .= ' ORDER BY fam_ID';
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
                'family_id' => (int) $r['fam_ID'],
                'street' => trim((string) $r['fam_Address1']),
                'address2' => trim((string) $r['fam_Address2']),
                'city' => trim((string) $r['fam_City']),
                'state' => trim((string) $r['fam_State']),
                'zip' => trim((string) $r['fam_Zip']),
                'country' => trim((string) $r['fam_Country']),
                'lat' => $r['fam_Latitude'] === null ? null : (float) $r['fam_Latitude'],
                'lng' => $r['fam_Longitude'] === null ? null : (float) $r['fam_Longitude'],
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
        $sql = "SELECT per_ID, COALESCE(per_fam_ID, 0) AS fam_id, per_Address1, per_Address2,
                       per_City, per_State, per_Zip, per_Country
                  FROM person_per
                 WHERE TRIM(COALESCE(per_Address1, '')) <> ''
                 ORDER BY per_ID";
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $out = [];
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'person_id' => (int) $r['per_ID'],
                'family_id' => (int) $r['fam_id'],
                'street' => trim((string) $r['per_Address1']),
                'address2' => trim((string) $r['per_Address2']),
                'city' => trim((string) $r['per_City']),
                'state' => trim((string) $r['per_State']),
                'zip' => trim((string) $r['per_Zip']),
                'country' => trim((string) $r['per_Country']),
            ];
        }

        return $out;
    }

    public function saveFamilyCoordinates(int $familyId, float $lat, float $lng): void
    {
        $this->db->prepare(
            'UPDATE family_fam
                SET fam_Latitude = :lat, fam_Longitude = :lng, fam_DateLastEdited = NOW()
              WHERE fam_ID = :id'
        )->execute([':lat' => $lat, ':lng' => $lng, ':id' => $familyId]);
    }

    /** @param array<string,string> $fields */
    public function saveFamilyAddressFields(int $familyId, array $fields): void
    {
        $this->writeFields('family_fam', 'fam_ID', 'fam_DateLastEdited', self::FAMILY_FIELDS, $familyId, $fields);
    }

    /** @param array<string,string> $fields */
    public function savePersonAddressFields(int $personId, array $fields): void
    {
        $this->writeFields('person_per', 'per_ID', 'per_DateLastEdited', self::PERSON_FIELDS, $personId, $fields);
    }

    public function personLabel(int $personId): string
    {
        $s = $this->db->prepare(
            "SELECT TRIM(CONCAT(COALESCE(per_LastName,''), ', ', COALESCE(per_FirstName,'')))
               FROM person_per WHERE per_ID = :id"
        );
        $s->execute([':id' => $personId]);

        return trim((string) ($s->fetchColumn() ?: ''), ' ,');
    }

    public function familyLabel(int $familyId): string
    {
        $s = $this->db->prepare('SELECT fam_Name FROM family_fam WHERE fam_ID = :id');
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
     * @param array<string,string> $allowed
     * @param array<string,string> $fields
     */
    private function writeFields(
        string $table,
        string $idColumn,
        string $editedColumn,
        array $allowed,
        int $id,
        array $fields,
    ): void {
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $name => $value) {
            $column = $allowed[$name] ?? null;
            if ($column === null) {
                continue;
            }
            $sets[] = $column . ' = :' . $name;
            $params[':' . $name] = $value;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = $editedColumn . ' = NOW()';

        $this->db->prepare(
            'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $idColumn . ' = :id'
        )->execute($params);
    }
}
