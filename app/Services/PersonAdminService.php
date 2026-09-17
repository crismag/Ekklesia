<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * People administration — read/write over person_per (+ family_fam, person_custom,
 * list_lst, person_campus_affiliation) in the shared database.
 *
 * Direct-PDO, self-contained: NO dependency on any ChurchCRM PHP, so it keeps
 * working after ChurchCRM is decommissioned. Phase 1 provides the dashboard
 * stats, option loaders, and the filtered/paged people list; later phases add
 * the person editor, photos, and family management on the same connection.
 */
final readonly class PersonAdminService
{
    public function __construct(private PDO $db)
    {
    }

    // ---- Option loaders (list_lst) ------------------------------------------

    /** @return list<array{id:int,name:string}> */
    public function classifications(): array
    {
        return $this->listOptions(1);
    }

    /** @return list<array{id:int,name:string}> Member Type (person_custom.c1). */
    public function memberTypes(): array
    {
        return $this->listOptions(13);
    }

    /** @return list<array{id:int,name:string}> Family roles. */
    public function familyRoles(): array
    {
        return $this->listOptions(2);
    }

    /** @return list<array{id:int,name:string}> */
    private function listOptions(int $listId): array
    {
        $stmt = $this->db->prepare(
            'SELECT lst_OptionID AS id, lst_OptionName AS name
               FROM list_lst WHERE lst_ID = :lid ORDER BY lst_OptionSequence, lst_OptionName'
        );
        $stmt->execute([':lid' => $listId]);
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /** @return list<array{campus_id:int,campus_name:string}> */
    public function campuses(): array
    {
        $rows = $this->db->query('SELECT campus_id, campus_name FROM church_campus WHERE is_active = 1 ORDER BY campus_name')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => ['campus_id' => (int) $r['campus_id'], 'campus_name' => (string) $r['campus_name']], $rows);
    }

    // ---- Dashboard stats ----------------------------------------------------

    /** @return array{people:int,families:int,activeFamilies:int,classifications:list<array<string,mixed>>,memberTypes:list<array<string,mixed>>} */
    public function stats(): array
    {
        $people = (int) $this->db->query('SELECT COUNT(*) FROM person_per')->fetchColumn();
        $families = (int) $this->db->query('SELECT COUNT(*) FROM family_fam')->fetchColumn();
        $activeFamilies = (int) $this->db->query('SELECT COUNT(*) FROM family_fam WHERE fam_DateDeactivated IS NULL')->fetchColumn();

        $clsCounts = [];
        foreach ($this->db->query('SELECT per_cls_ID AS id, COUNT(*) c FROM person_per GROUP BY per_cls_ID') as $r) {
            $clsCounts[(int) $r['id']] = (int) $r['c'];
        }
        $classifications = [];
        foreach ($this->classifications() as $opt) {
            $classifications[] = ['id' => $opt['id'], 'name' => $opt['name'], 'count' => $clsCounts[$opt['id']] ?? 0];
        }
        // Unclassified (per_cls_ID = 0 / not in list)
        $classified = array_sum(array_map(static fn ($c) => $c['count'], $classifications));
        if ($people - $classified > 0) {
            $classifications[] = ['id' => 0, 'name' => 'Unclassified', 'count' => $people - $classified];
        }

        $mtCounts = [];
        foreach ($this->db->query('SELECT c1 AS id, COUNT(*) c FROM person_custom WHERE c1 IS NOT NULL GROUP BY c1') as $r) {
            $mtCounts[(int) $r['id']] = (int) $r['c'];
        }
        $memberTypes = [];
        foreach ($this->memberTypes() as $opt) {
            $memberTypes[] = ['id' => $opt['id'], 'name' => $opt['name'], 'count' => $mtCounts[$opt['id']] ?? 0];
        }

        return [
            'people' => $people, 'families' => $families, 'activeFamilies' => $activeFamilies,
            'classifications' => $classifications, 'memberTypes' => $memberTypes,
        ];
    }

    // ---- People list --------------------------------------------------------

    /**
     * @param array{search?:string,classification?:int,campus?:int} $filters
     * @return array{where:string,params:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = 'CONCAT(p.per_FirstName, " ", p.per_LastName, " ", COALESCE(p.per_Email, "")) LIKE :s';
            $params[':s'] = '%' . $search . '%';
        }
        $cls = (int) ($filters['classification'] ?? 0);
        if ($cls > 0) {
            $where[] = 'p.per_cls_ID = :cls';
            $params[':cls'] = $cls;
        }
        $campus = (int) ($filters['campus'] ?? 0);
        if ($campus > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM person_campus_affiliation pca2 WHERE pca2.person_id = p.per_ID AND pca2.campus_id = :campus AND pca2.is_primary = 1)';
            $params[':campus'] = $campus;
        }
        // Member type lives in person_custom.c1, the same column the directory
        // reads it from. -1 means "has none", which is the one an administrator
        // filling gaps actually wants; there is no member type with that id.
        $memberType = (int) ($filters['member_type'] ?? 0);
        if ($memberType > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM person_custom pcf WHERE pcf.per_ID = p.per_ID AND pcf.c1 = :mt)';
            $params[':mt'] = $memberType;
        } elseif ($memberType === -1) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM person_custom pcf WHERE pcf.per_ID = p.per_ID AND COALESCE(pcf.c1, 0) > 0)';
        }
        return ['where' => $where === [] ? '' : (' WHERE ' . implode(' AND ', $where)), 'params' => $params];
    }

    public function count(array $filters): int
    {
        ['where' => $where, 'params' => $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM person_per p' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{search?:string,classification?:int,campus?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters, int $page = 1, int $perPage = 25): array
    {
        ['where' => $where, 'params' => $params] = $this->buildWhere($filters);
        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT p.per_ID AS id, p.per_FirstName AS first_name, p.per_LastName AS last_name,
                       p.per_Email AS email, p.per_CellPhone AS cell,
                       p.per_BirthMonth AS bm, p.per_BirthDay AS bd, p.per_BirthYear AS by2,
                       p.per_cls_ID AS cls_id, cls.lst_OptionName AS classification,
                       pc.c1 AS member_type_id, mt.lst_OptionName AS member_type,
                       p.per_fam_ID AS fam_id, f.fam_Name AS family, f.fam_DateDeactivated AS fam_deactivated,
                       cc.campus_id AS campus_id, cc.campus_name AS campus
                  FROM person_per p
                  LEFT JOIN family_fam f ON f.fam_ID = p.per_fam_ID
                  LEFT JOIN list_lst cls ON cls.lst_ID = 1 AND cls.lst_OptionID = p.per_cls_ID
                  LEFT JOIN person_custom pc ON pc.per_ID = p.per_ID
                  LEFT JOIN list_lst mt ON mt.lst_ID = 13 AND mt.lst_OptionID = pc.c1
                  LEFT JOIN person_campus_affiliation pca ON pca.person_id = p.per_ID AND pca.is_primary = 1
                  LEFT JOIN church_campus cc ON cc.campus_id = pca.campus_id'
             . $where
             . ' ORDER BY p.per_LastName ASC, p.per_FirstName ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null Full person row + member_type_id + primary campus. */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM person_per WHERE per_ID = :id');
        $stmt->execute([':id' => $id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            return null;
        }
        $c = $this->db->prepare('SELECT c1 FROM person_custom WHERE per_ID = :id');
        $c->execute([':id' => $id]);
        $p['member_type_id'] = ($row = $c->fetch(PDO::FETCH_ASSOC)) ? $row['c1'] : null;
        $ca = $this->db->prepare('SELECT campus_id FROM person_campus_affiliation WHERE person_id = :id AND is_primary = 1 LIMIT 1');
        $ca->execute([':id' => $id]);
        $p['primary_campus_id'] = ($row = $ca->fetch(PDO::FETCH_ASSOC)) ? (int) $row['campus_id'] : null;
        return $p;
    }

    /**
     * Assemble everything the profile view needs: the person, resolved mailing
     * address + coordinates (family address wins when the person has a family),
     * campus affiliations, and family members. Returns null if not found.
     *
     * @return array<string,mixed>|null
     */
    public function viewData(int $id): ?array
    {
        $p = $this->find($id);
        if ($p === null) {
            return null;
        }
        $famId = (int) ($p['per_fam_ID'] ?? 0);
        $family = null;
        $members = [];
        if ($famId > 0) {
            $fs = $this->db->prepare('SELECT * FROM family_fam WHERE fam_ID = :id');
            $fs->execute([':id' => $famId]);
            $family = $fs->fetch(PDO::FETCH_ASSOC) ?: null;

            $ms = $this->db->prepare(
                'SELECT p.per_ID AS id, p.per_FirstName AS first_name, p.per_LastName AS last_name,
                        p.per_Email AS email, fmr.lst_OptionName AS family_role
                   FROM person_per p
                   LEFT JOIN list_lst fmr ON fmr.lst_ID = 2 AND fmr.lst_OptionID = p.per_fmr_ID
                  WHERE p.per_fam_ID = :fam AND p.per_ID <> :self
                  ORDER BY p.per_fmr_ID ASC, p.per_LastName ASC, p.per_FirstName ASC'
            );
            $ms->execute([':fam' => $famId, ':self' => $id]);
            $members = $ms->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Resolved mailing address: person's own, else the family's.
        $addr = array_filter([
            'address1' => $p['per_Address1'] ?? '', 'address2' => $p['per_Address2'] ?? '',
            'city' => $p['per_City'] ?? '', 'state' => $p['per_State'] ?? '',
            'zip' => $p['per_Zip'] ?? '', 'country' => $p['per_Country'] ?? '',
        ], static fn ($v) => trim((string) $v) !== '');
        $lat = null; $lng = null;
        if ($addr === [] && $family !== null) {
            $addr = array_filter([
                'address1' => $family['fam_Address1'] ?? '', 'address2' => $family['fam_Address2'] ?? '',
                'city' => $family['fam_City'] ?? '', 'state' => $family['fam_State'] ?? '',
                'zip' => $family['fam_Zip'] ?? '', 'country' => $family['fam_Country'] ?? '',
            ], static fn ($v) => trim((string) $v) !== '');
        }
        if ($family !== null) {
            $lat = ($family['fam_Latitude'] ?? null) !== null && (float) $family['fam_Latitude'] !== 0.0 ? (float) $family['fam_Latitude'] : null;
            $lng = ($family['fam_Longitude'] ?? null) !== null && (float) $family['fam_Longitude'] !== 0.0 ? (float) $family['fam_Longitude'] : null;
        }
        $addressLine = implode(', ', $addr);

        // Classification / member type / family role labels.
        $labels = [
            'classification' => $this->optionName(1, (int) ($p['per_cls_ID'] ?? 0)),
            'member_type' => $this->optionName(13, (int) ($p['member_type_id'] ?? 0)),
            'family_role' => $this->optionName(2, (int) ($p['per_fmr_ID'] ?? 0)),
        ];

        // Campus affiliations.
        $ca = $this->db->prepare(
            'SELECT cc.campus_id, cc.campus_name, pca.is_primary
               FROM person_campus_affiliation pca
               JOIN church_campus cc ON cc.campus_id = pca.campus_id
              WHERE pca.person_id = :id ORDER BY pca.is_primary DESC, cc.campus_name ASC'
        );
        $ca->execute([':id' => $id]);
        $affiliations = $ca->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'person' => $p, 'family' => $family, 'members' => $members,
            'labels' => $labels, 'affiliations' => $affiliations,
            'address_line' => $addressLine, 'lat' => $lat, 'lng' => $lng,
        ];
    }

    private function optionName(int $listId, int $optionId): string
    {
        if ($optionId <= 0) {
            return '';
        }
        $stmt = $this->db->prepare('SELECT lst_OptionName FROM list_lst WHERE lst_ID = :l AND lst_OptionID = :o LIMIT 1');
        $stmt->execute([':l' => $listId, ':o' => $optionId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /**
     * Refresh a family's coordinates from its address via OpenStreetMap Nominatim
     * (free, no API key), storing fam_Latitude/fam_Longitude. Mirrors ChurchCRM's
     * "refresh coordinates" action, portal-native.
     *
     * @return array{success:bool,lat?:float,lng?:float,error?:string}
     */
    public function geocodeFamily(int $famId): array
    {
        if ($famId <= 0) {
            return ['success' => false, 'error' => 'No family to geocode.'];
        }
        $fs = $this->db->prepare('SELECT fam_Address1, fam_City, fam_State, fam_Zip, fam_Country FROM family_fam WHERE fam_ID = :id');
        $fs->execute([':id' => $famId]);
        $f = $fs->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return ['success' => false, 'error' => 'Family not found.'];
        }
        // Drop apartment/unit tokens (#1008, Unit 5, Apt 3…) — they defeat geocoders.
        $street = trim((string) preg_replace('/\s*(#|unit|apt\.?|suite|ste\.?)\s*\S+/i', '', (string) $f['fam_Address1']));
        $city = trim((string) $f['fam_City']);
        $state = trim((string) $f['fam_State']);
        $zip = trim((string) $f['fam_Zip']);
        $country = trim((string) $f['fam_Country']);
        if ($street === '' && $zip === '' && $city === '') {
            return ['success' => false, 'error' => 'This family has no address to look up.'];
        }

        // Try a structured query first, then a free-text query as a fallback.
        $structured = array_filter([
            'street' => $street, 'city' => $city, 'state' => $state,
            'postalcode' => $zip, 'country' => $country,
            'format' => 'jsonv2', 'limit' => '1',
        ], static fn ($v) => $v !== '');
        $freeText = ['q' => implode(', ', array_filter([$street, $city, $state, $zip, $country], static fn ($v) => $v !== '')), 'format' => 'jsonv2', 'limit' => '1'];

        $hit = $this->nominatim($structured) ?? $this->nominatim($freeText);
        if ($hit === null) {
            return ['success' => false, 'error' => 'No match found for this address.'];
        }
        $lat = (float) $hit['lat'];
        $lng = (float) $hit['lon'];
        $this->db->prepare('UPDATE family_fam SET fam_Latitude = :lat, fam_Longitude = :lng, fam_DateLastEdited = NOW() WHERE fam_ID = :id')
            ->execute([':lat' => $lat, ':lng' => $lng, ':id' => $famId]);
        return ['success' => true, 'lat' => $lat, 'lng' => $lng];
    }

    /**
     * Query OpenStreetMap Nominatim; return the first hit (with lat/lon) or null.
     * @param array<string,string> $params
     * @return array<string,mixed>|null
     */
    private function nominatim(array $params): ?array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "User-Agent: ChristlikenessChurchPortal/1.0 (csmagala@gmail.com)\r\nAccept: application/json\r\n",
            'timeout' => 6,
        ]]);
        $raw = @file_get_contents('https://nominatim.openstreetmap.org/search?' . http_build_query($params), false, $ctx);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) && isset($data[0]['lat'], $data[0]['lon']) ? $data[0] : null;
    }

    // ---- Photos & self-service ---------------------------------------------

    /** Portal-owned photo directory (separate folder, ChurchCRM-style). */
    private function photoDir(): string
    {
        return dirname(__DIR__, 2) . '/Images/Person/';
    }

    public function personFamilyId(int $id): int
    {
        $s = $this->db->prepare('SELECT per_fam_ID FROM person_per WHERE per_ID = :id');
        $s->execute([':id' => $id]);
        return (int) ($s->fetchColumn() ?: 0);
    }

    /**
     * Validate + store a profile photo: re-encoded via GD (strips EXIF/embedded
     * code), centre-cropped to a 512px square PNG at Images/Person/{id}.png.
     */
    public function savePhoto(int $id, string $tmpFile): void
    {
        if ($id <= 0 || $this->find($id) === null) {
            throw new \InvalidArgumentException('Unknown person.');
        }
        $info = @getimagesize($tmpFile);
        if ($info === false) {
            throw new \InvalidArgumentException('That file is not a valid image.');
        }
        [$w, $h, $type] = $info;
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpFile),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmpFile),
            IMAGETYPE_GIF  => @imagecreatefromgif($tmpFile),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpFile) : false,
            default        => false,
        };
        if (!$src) {
            throw new \InvalidArgumentException('Unsupported image type. Please use JPG, PNG, or WEBP.');
        }
        $size = 512;
        $side = min($w, $h);
        $sx = (int) (($w - $side) / 2);
        $sy = (int) (($h - $side) / 2);
        $dst = imagecreatetruecolor($size, $size);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);

        $dir = $this->photoDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($src);
            imagedestroy($dst);
            throw new RuntimeException('Photo folder is not available.');
        }
        $ok = imagepng($dst, $dir . $id . '.png');
        imagedestroy($src);
        imagedestroy($dst);
        // Drop any stale jpg for the same person so the png wins.
        foreach (['jpg', 'jpeg'] as $ext) {
            if (is_file($dir . $id . '.' . $ext)) { @unlink($dir . $id . '.' . $ext); }
        }
        if (!$ok) {
            throw new RuntimeException('Could not save the photo.');
        }
    }

    /** Remove the portal-owned photo for a person (leaves any legacy copy alone). */
    public function deletePhoto(int $id): void
    {
        $dir = $this->photoDir();
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            if (is_file($dir . $id . '.' . $ext)) { @unlink($dir . $id . '.' . $ext); }
        }
    }

    /** Set (or clear when null/0) a person's single primary campus affiliation. */
    public function setPrimaryCampus(int $personId, ?int $campusId, int $actorId): void
    {
        if ($this->find($personId) === null) {
            throw new \InvalidArgumentException('Unknown person.');
        }
        $this->db->prepare('DELETE FROM person_campus_affiliation WHERE person_id = :id')->execute([':id' => $personId]);
        if ($campusId !== null && $campusId > 0) {
            $this->db->prepare(
                'INSERT INTO person_campus_affiliation (person_id, campus_id, is_primary, date_entered, entered_by)
                 VALUES (:id, :c, 1, NOW(), :a)'
            )->execute([':id' => $personId, ':c' => $campusId, ':a' => $actorId]);
        }
    }

    /** Delete a person and their custom/affiliation rows. */
    public function delete(int $id): void
    {
        if ($this->find($id) === null) {
            throw new \InvalidArgumentException('Unknown person.');
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM person_custom WHERE per_ID = :id')->execute([':id' => $id]);
            $this->db->prepare('DELETE FROM person_campus_affiliation WHERE person_id = :id')->execute([':id' => $id]);
            $this->db->prepare('DELETE FROM person_per WHERE per_ID = :id')->execute([':id' => $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @return list<array{id:int,name:string}> active families for the family dropdown. */
    public function families(): array
    {
        $rows = $this->db->query('SELECT fam_ID AS id, fam_Name AS name FROM family_fam WHERE fam_DateDeactivated IS NULL ORDER BY fam_Name')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /** Blank person for the "new" form. */
    public function blank(): array
    {
        return [
            'per_ID' => 0, 'per_Title' => '', 'per_FirstName' => '', 'per_MiddleName' => '', 'per_LastName' => '',
            'per_Suffix' => '', 'per_Gender' => 0, 'per_BirthMonth' => 0, 'per_BirthDay' => 0, 'per_BirthYear' => null,
            'per_Email' => '', 'per_WorkEmail' => '', 'per_CellPhone' => '', 'per_HomePhone' => '', 'per_WorkPhone' => '',
            'per_Address1' => '', 'per_Address2' => '', 'per_City' => '', 'per_State' => 'Ontario', 'per_Zip' => '',
            'per_Country' => 'CA', 'per_Facebook' => '', 'per_Twitter' => '', 'per_LinkedIn' => '',
            'per_cls_ID' => 0, 'per_fam_ID' => 0, 'per_fmr_ID' => 0, 'per_MembershipDate' => null,
            'member_type_id' => null, 'primary_campus_id' => null,
        ];
    }

    // ---- Person write (editor) ----------------------------------------------

    private const STR_FIELDS = [
        'per_Title', 'per_FirstName', 'per_MiddleName', 'per_LastName', 'per_Suffix',
        'per_Address1', 'per_Address2', 'per_City', 'per_State', 'per_Zip', 'per_Country',
        'per_HomePhone', 'per_WorkPhone', 'per_CellPhone', 'per_Email', 'per_WorkEmail',
        'per_Facebook', 'per_Twitter', 'per_LinkedIn',
    ];
    private const INT_FIELDS = ['per_Gender', 'per_BirthMonth', 'per_BirthDay', 'per_cls_ID', 'per_fam_ID', 'per_fmr_ID'];

    /**
     * Create or update a person. Mirrors ChurchCRM PersonEditor validation:
     * last name required; birth month & day must be given together; emails valid.
     * Returns the person id.
     *
     * @param array<string,mixed> $in
     */
    public function save(array $in, int $actorId): int
    {
        $last = trim((string) ($in['per_LastName'] ?? ''));
        if ($last === '') {
            throw new \InvalidArgumentException('Last name is required.');
        }
        $bm = (int) ($in['per_BirthMonth'] ?? 0);
        $bd = (int) ($in['per_BirthDay'] ?? 0);
        if (($bm > 0) !== ($bd > 0)) {
            throw new \InvalidArgumentException('Birth date needs both a month and a day (or leave both blank).');
        }
        foreach (['per_Email', 'per_WorkEmail'] as $ef) {
            $e = trim((string) ($in[$ef] ?? ''));
            if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('An email address looks invalid.');
            }
        }

        $values = [];
        foreach (self::STR_FIELDS as $f) {
            $v = trim((string) ($in[$f] ?? ''));
            $values[$f] = $v === '' ? null : $v;
        }
        $values['per_LastName'] = $last;
        foreach (self::INT_FIELDS as $f) {
            $values[$f] = (int) ($in[$f] ?? 0);
        }
        if (!in_array($values['per_Gender'], [1, 2], true)) { $values['per_Gender'] = 0; }
        $by = trim((string) ($in['per_BirthYear'] ?? ''));
        $values['per_BirthYear'] = ($by !== '' && (int) $by >= 1900 && (int) $by <= (int) date('Y')) ? (int) $by : null;
        $md = trim((string) ($in['per_MembershipDate'] ?? ''));
        $values['per_MembershipDate'] = $md !== '' && strtotime($md) ? date('Y-m-d', (int) strtotime($md)) : null;

        $id = (int) ($in['per_ID'] ?? 0);
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            if ($id > 0) {
                $set = implode(', ', array_map(static fn ($c) => "`$c` = :$c", array_keys($values)));
                $sql = "UPDATE person_per SET $set, per_DateLastEdited = NOW(), per_EditedBy = :actor WHERE per_ID = :id";
                $params = [];
                foreach ($values as $k => $v) { $params[":$k"] = $v; }
                $params[':actor'] = $actorId;
                $params[':id'] = $id;
                $this->db->prepare($sql)->execute($params);
            } else {
                $cols = array_keys($values);
                $ph = array_map(static fn ($c) => ":$c", $cols);
                $sql = 'INSERT INTO person_per (' . implode(', ', $cols)
                    . ', per_DateEntered, per_EnteredBy, per_DateLastEdited, per_EditedBy, per_Flags)'
                    . ' VALUES (' . implode(', ', $ph) . ', NOW(), :actor, NOW(), :actor2, 0)';
                $params = [];
                foreach ($values as $k => $v) { $params[":$k"] = $v; }
                $params[':actor'] = $actorId;
                $params[':actor2'] = $actorId;
                $this->db->prepare($sql)->execute($params);
                $id = (int) $this->db->lastInsertId();
            }

            // Member Type custom field.
            $mt = trim((string) ($in['member_type_id'] ?? ''));
            $mtVal = ($mt !== '' && (int) $mt > 0) ? (int) $mt : null;
            $this->db->prepare('INSERT INTO person_custom (per_ID, c1) VALUES (:id, :mt) ON DUPLICATE KEY UPDATE c1 = VALUES(c1)')
                ->execute([':id' => $id, ':mt' => $mtVal]);

            // Primary campus affiliation.
            if (array_key_exists('primary_campus_id', $in)) {
                $campus = (int) $in['primary_campus_id'];
                $this->db->prepare('DELETE FROM person_campus_affiliation WHERE person_id = :id')->execute([':id' => $id]);
                if ($campus > 0) {
                    $this->db->prepare(
                        'INSERT INTO person_campus_affiliation (person_id, campus_id, is_primary, date_entered, entered_by)
                         VALUES (:id, :c, 1, NOW(), :actor)'
                    )->execute([':id' => $id, ':c' => $campus, ':actor' => $actorId]);
                }
            }

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return $id;
    }

    /**
     * Compact directory used to match an import against existing people.
     *
     * @return list<array{id:int,first_name:string,last_name:string,email:string,campus_id:?int}>
     */
    /**
     * Columns a person may change about themselves.
     *
     * Deliberately narrower than save(): it is how they are reached and how
     * they describe themselves, and nothing about their standing in the church.
     * Classification, member type, membership date, family membership and
     * family role are records the church keeps ABOUT a person, not fields the
     * person authors -- and per_fam_ID in particular is an access decision,
     * because a family's shared address and household list come with it. Those
     * stay with the admin editor.
     */
    private const OWN_STR_FIELDS = [
        'per_Title', 'per_FirstName', 'per_MiddleName', 'per_LastName', 'per_Suffix',
        'per_Address1', 'per_Address2', 'per_City', 'per_State', 'per_Zip', 'per_Country',
        'per_HomePhone', 'per_WorkPhone', 'per_CellPhone', 'per_Email', 'per_WorkEmail',
        'per_Facebook', 'per_Twitter', 'per_LinkedIn',
    ];

    /**
     * Update the fields a person is allowed to change about themselves.
     *
     * save() writes every column it knows from the input array, so a partial
     * form posted through it blanks whatever it left out. This one reads the
     * existing row first and only overwrites keys the caller actually sent,
     * which is what a profile form posts.
     *
     * @param array<string,mixed> $in
     */
    public function saveOwnProfile(int $personId, array $in, int $actorId): void
    {
        if ($personId <= 0) {
            throw new \InvalidArgumentException('Unknown person.');
        }
        $current = $this->find($personId);
        if ($current === null) {
            throw new \InvalidArgumentException('Unknown person.');
        }

        $values = [];
        foreach (self::OWN_STR_FIELDS as $f) {
            if (!array_key_exists($f, $in)) {
                continue;
            }
            $v = trim((string) $in[$f]);
            $values[$f] = $v === '' ? null : $v;
        }

        if (array_key_exists('per_LastName', $values) && $values['per_LastName'] === null) {
            throw new \InvalidArgumentException('Last name is required.');
        }
        foreach (['per_Email', 'per_WorkEmail'] as $ef) {
            if (($values[$ef] ?? null) !== null && !filter_var((string) $values[$ef], FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('That email address looks invalid.');
            }
        }

        // Birthday: month and day travel together, exactly as save() requires.
        $hasBm = array_key_exists('per_BirthMonth', $in);
        $hasBd = array_key_exists('per_BirthDay', $in);
        if ($hasBm || $hasBd) {
            $bm = (int) ($in['per_BirthMonth'] ?? $current['per_BirthMonth'] ?? 0);
            $bd = (int) ($in['per_BirthDay'] ?? $current['per_BirthDay'] ?? 0);
            if (($bm > 0) !== ($bd > 0)) {
                throw new \InvalidArgumentException('A birthday needs both a month and a day, or neither.');
            }
            if ($bm < 0 || $bm > 12 || $bd < 0 || $bd > 31) {
                throw new \InvalidArgumentException('That birthday is not a real date.');
            }
            $values['per_BirthMonth'] = $bm;
            $values['per_BirthDay'] = $bd;
        }
        if (array_key_exists('per_BirthYear', $in)) {
            $by = trim((string) $in['per_BirthYear']);
            $values['per_BirthYear'] = ($by !== '' && (int) $by >= 1900 && (int) $by <= (int) date('Y'))
                ? (int) $by
                : null;
        }
        if (array_key_exists('per_Gender', $in)) {
            $g = (int) $in['per_Gender'];
            $values['per_Gender'] = in_array($g, [1, 2], true) ? $g : 0;
        }

        if ($values === []) {
            return;
        }

        $set = implode(', ', array_map(static fn (string $c): string => "`$c` = :$c", array_keys($values)));
        $params = [];
        foreach ($values as $k => $v) {
            $params[":$k"] = $v;
        }
        $params[':actor'] = $actorId;
        $params[':id'] = $personId;
        $this->db
            ->prepare("UPDATE person_per SET $set, per_DateLastEdited = NOW(), per_EditedBy = :actor WHERE per_ID = :id")
            ->execute($params);
    }

    /**
     * The ministries a person belongs to, with ids so the profile can link to
     * each one. The directory API returns these as a joined string, which is
     * enough to print and not enough to navigate.
     *
     * @return list<array{ministry_id:int,name:string,roles:string,is_leader:bool}>
     */
    public function ministriesFor(int $personId): array
    {
        if ($personId <= 0) {
            return [];
        }
        // Role names have two homes: the portal's own `roles` table and the
        // ChurchCRM option list the group points at with grp_RoleListID. The
        // ministry adapter coalesces both, and a query that reads only one of
        // them returns a person's ministries with every role blank.
        $stmt = $this->db->prepare(
            'SELECT g.grp_ID AS ministry_id,
                    g.grp_Name AS name,
                    COALESCE(lst.lst_OptionName, r.role_name, "") AS role_name,
                    ml.person_id IS NOT NULL AS is_leader
               FROM person2group2role_p2g2r p2g
               INNER JOIN group_grp g ON g.grp_ID = p2g.p2g2r_grp_ID
               LEFT JOIN roles r
                      ON r.role_id = p2g.p2g2r_rle_ID
                     AND r.ministry_group_id = g.grp_ID
               LEFT JOIN list_lst lst
                      ON lst.lst_ID = g.grp_RoleListID
                     AND lst.lst_OptionID = p2g.p2g2r_rle_ID
               LEFT JOIN ministry_leaders ml
                      ON ml.ministry_group_id = g.grp_ID
                     AND ml.person_id = p2g.p2g2r_per_ID
              WHERE p2g.p2g2r_per_ID = :id
              ORDER BY g.grp_Name ASC'
        );
        $stmt->execute([':id' => $personId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['ministry_id'];
            $role = trim((string) ($row['role_name'] ?? ''));
            if (!isset($out[$id])) {
                $out[$id] = [
                    'ministry_id' => $id,
                    'name' => (string) $row['name'],
                    'roles' => [],
                    'is_leader' => (bool) $row['is_leader'],
                ];
            }
            if ($role !== '' && !in_array($role, $out[$id]['roles'], true)) {
                $out[$id]['roles'][] = $role;
            }
            $out[$id]['is_leader'] = $out[$id]['is_leader'] || (bool) $row['is_leader'];
        }

        return array_values(array_map(static function (array $m): array {
            sort($m['roles']);
            $m['roles'] = implode(', ', $m['roles']);
            return $m;
        }, $out));
    }

    public function matchIndex(): array
    {
        $sql = 'SELECT p.per_ID AS id, p.per_FirstName AS first_name, p.per_LastName AS last_name,
                       LOWER(TRIM(COALESCE(p.per_Email, ""))) AS email,
                       pca.campus_id AS campus_id
                  FROM person_per p
                  LEFT JOIN person_campus_affiliation pca ON pca.person_id = p.per_ID AND pca.is_primary = 1
              ORDER BY p.per_LastName, p.per_FirstName';
        $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'first_name' => (string) $r['first_name'],
            'last_name' => (string) $r['last_name'],
            'email' => (string) $r['email'],
            'campus_id' => $r['campus_id'] !== null && $r['campus_id'] !== '' ? (int) $r['campus_id'] : null,
        ], $rows);
    }

    /**
     * People whose primary campus is $campusId, with fields needed for CSV export.
     *
     * @return list<array<string,mixed>>
     */
    public function exportCampus(int $campusId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.per_ID AS id, p.per_FirstName AS first_name, p.per_MiddleName AS middle_name,
                    p.per_LastName AS last_name, p.per_Email AS email, p.per_CellPhone AS cell,
                    p.per_Address1 AS address1, p.per_City AS city, p.per_State AS state, p.per_Zip AS zip,
                    p.per_BirthMonth AS bm, p.per_BirthDay AS bd, p.per_BirthYear AS by2,
                    p.per_MembershipDate AS member_since,
                    mt.lst_OptionName AS member_type
               FROM person_per p
               INNER JOIN person_campus_affiliation pca ON pca.person_id = p.per_ID AND pca.is_primary = 1
               LEFT JOIN person_custom pc ON pc.per_ID = p.per_ID
               LEFT JOIN list_lst mt ON mt.lst_ID = 13 AND mt.lst_OptionID = pc.c1
              WHERE pca.campus_id = :c
              ORDER BY p.per_LastName ASC, p.per_FirstName ASC'
        );
        $stmt->execute([':c' => $campusId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
