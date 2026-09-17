<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * People administration — read/write over `people` (+ `households`,
 * `membership_statuses`, `household_roles`, `member_types`, `campuses`) in the
 * member database.
 *
 * Direct-PDO and self-contained. Provides the dashboard stats, option loaders,
 * the filtered/paged people list, the person editor, photos and the lookups the
 * member import matches against.
 */
final readonly class PersonAdminService
{
    public function __construct(private PDO $db)
    {
    }

    // ---- Option loaders -----------------------------------------------------

    /** @return list<array{id:int,name:string}> Membership statuses. */
    public function classifications(): array
    {
        return $this->listOptions('membership_statuses');
    }

    /** @return list<array{id:int,name:string}> Member types. */
    public function memberTypes(): array
    {
        return $this->listOptions('member_types');
    }

    /** @return list<array{id:int,name:string}> Household roles. */
    public function familyRoles(): array
    {
        return $this->listOptions('household_roles');
    }

    /**
     * @param 'membership_statuses'|'household_roles'|'member_types' $table
     * @return list<array{id:int,name:string}>
     */
    private function listOptions(string $table): array
    {
        $rows = $this->db->query("SELECT id, name FROM `$table` ORDER BY sort_order, name")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $rows
        );
    }

    /** @return list<array{id:int,name:string}> */
    public function campuses(): array
    {
        $rows = $this->db->query('SELECT id, name FROM campuses WHERE is_active = 1 ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    // ---- Dashboard stats ----------------------------------------------------

    /** @return array{people:int,families:int,activeFamilies:int,classifications:list<array<string,mixed>>,memberTypes:list<array<string,mixed>>} */
    public function stats(): array
    {
        $people = (int) $this->db->query('SELECT COUNT(*) FROM people')->fetchColumn();
        $families = (int) $this->db->query('SELECT COUNT(*) FROM households')->fetchColumn();
        $activeFamilies = (int) $this->db->query('SELECT COUNT(*) FROM households WHERE deactivated_on IS NULL')->fetchColumn();

        $clsCounts = [];
        foreach ($this->db->query('SELECT membership_status_id AS id, COUNT(*) c FROM people WHERE membership_status_id IS NOT NULL GROUP BY membership_status_id') as $r) {
            $clsCounts[(int) $r['id']] = (int) $r['c'];
        }
        $classifications = [];
        foreach ($this->classifications() as $opt) {
            $classifications[] = ['id' => $opt['id'], 'name' => $opt['name'], 'count' => $clsCounts[$opt['id']] ?? 0];
        }
        // Unclassified (no membership status)
        $classified = array_sum(array_map(static fn ($c) => $c['count'], $classifications));
        if ($people - $classified > 0) {
            $classifications[] = ['id' => 0, 'name' => 'Unclassified', 'count' => $people - $classified];
        }

        $mtCounts = [];
        foreach ($this->db->query('SELECT member_type_id AS id, COUNT(*) c FROM people WHERE member_type_id IS NOT NULL GROUP BY member_type_id') as $r) {
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
     * @param array{search?:string,classification?:int,campus?:int,member_type?:int} $filters
     * @return array{where:string,params:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = 'CONCAT(p.first_name, " ", p.last_name, " ", COALESCE(p.email, "")) LIKE :s';
            $params[':s'] = '%' . $search . '%';
        }
        $cls = (int) ($filters['classification'] ?? 0);
        if ($cls > 0) {
            $where[] = 'p.membership_status_id = :cls';
            $params[':cls'] = $cls;
        }
        $campus = (int) ($filters['campus'] ?? 0);
        if ($campus > 0) {
            $where[] = 'p.campus_id = :campus';
            $params[':campus'] = $campus;
        }
        // -1 means "has none", which is the one an administrator filling gaps
        // actually wants; there is no member type with that id.
        $memberType = (int) ($filters['member_type'] ?? 0);
        if ($memberType > 0) {
            $where[] = 'p.member_type_id = :mt';
            $params[':mt'] = $memberType;
        } elseif ($memberType === -1) {
            $where[] = 'p.member_type_id IS NULL';
        }
        return ['where' => $where === [] ? '' : (' WHERE ' . implode(' AND ', $where)), 'params' => $params];
    }

    public function count(array $filters): int
    {
        ['where' => $where, 'params' => $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM people p' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{search?:string,classification?:int,campus?:int,member_type?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters, int $page = 1, int $perPage = 25): array
    {
        ['where' => $where, 'params' => $params] = $this->buildWhere($filters);
        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT p.id, p.first_name, p.last_name, p.email, p.mobile_phone,
                       p.birth_month, p.birth_day, p.birth_year,
                       p.membership_status_id, ms.name AS classification,
                       p.member_type_id, mt.name AS member_type,
                       p.household_id, h.name AS household, h.deactivated_on AS household_deactivated_on,
                       p.campus_id, c.name AS campus
                  FROM people p
                  LEFT JOIN households h ON h.id = p.household_id
                  LEFT JOIN membership_statuses ms ON ms.id = p.membership_status_id
                  LEFT JOIN member_types mt ON mt.id = p.member_type_id
                  LEFT JOIN campuses c ON c.id = p.campus_id'
             . $where
             . ' ORDER BY p.last_name ASC, p.first_name ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null The full `people` row (campus_id and member_type_id included). */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM people WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        return $p ?: null;
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
        $householdId = (int) ($p['household_id'] ?? 0);
        $family = null;
        $members = [];
        if ($householdId > 0) {
            $fs = $this->db->prepare('SELECT * FROM households WHERE id = :id');
            $fs->execute([':id' => $householdId]);
            $family = $fs->fetch(PDO::FETCH_ASSOC) ?: null;

            $ms = $this->db->prepare(
                'SELECT p.id, p.first_name, p.last_name, p.email, hr.name AS family_role
                   FROM people p
                   LEFT JOIN household_roles hr ON hr.id = p.household_role_id
                  WHERE p.household_id = :household AND p.id <> :self
                  ORDER BY hr.sort_order IS NULL, hr.sort_order ASC, p.last_name ASC, p.first_name ASC'
            );
            $ms->execute([':household' => $householdId, ':self' => $id]);
            $members = $ms->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Resolved mailing address: person's own, else the family's.
        $addressOf = static fn (array $r): array => array_filter([
            'address_line1' => $r['address_line1'] ?? '', 'address_line2' => $r['address_line2'] ?? '',
            'city' => $r['city'] ?? '', 'region' => $r['region'] ?? '',
            'postal_code' => $r['postal_code'] ?? '', 'country' => $r['country'] ?? '',
        ], static fn ($v) => trim((string) $v) !== '');
        $addr = $addressOf($p);
        $lat = null; $lng = null;
        if ($addr === [] && $family !== null) {
            $addr = $addressOf($family);
        }
        if ($family !== null) {
            $lat = ($family['latitude'] ?? null) !== null && (float) $family['latitude'] !== 0.0 ? (float) $family['latitude'] : null;
            $lng = ($family['longitude'] ?? null) !== null && (float) $family['longitude'] !== 0.0 ? (float) $family['longitude'] : null;
        }
        $addressLine = implode(', ', $addr);

        // Membership status / member type / household role labels.
        $labels = [
            'classification' => $this->optionName('membership_statuses', (int) ($p['membership_status_id'] ?? 0)),
            'member_type' => $this->optionName('member_types', (int) ($p['member_type_id'] ?? 0)),
            'family_role' => $this->optionName('household_roles', (int) ($p['household_role_id'] ?? 0)),
        ];

        // Campus: one per person now, shown as the primary affiliation.
        $affiliations = [];
        if ((int) ($p['campus_id'] ?? 0) > 0) {
            $ca = $this->db->prepare('SELECT id, name FROM campuses WHERE id = :id');
            $ca->execute([':id' => (int) $p['campus_id']]);
            if ($row = $ca->fetch(PDO::FETCH_ASSOC)) {
                $affiliations[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'is_primary' => 1];
            }
        }

        return [
            'person' => $p, 'family' => $family, 'members' => $members,
            'labels' => $labels, 'affiliations' => $affiliations,
            'address_line' => $addressLine, 'lat' => $lat, 'lng' => $lng,
        ];
    }

    /** @param 'membership_statuses'|'household_roles'|'member_types' $table */
    private function optionName(string $table, int $optionId): string
    {
        if ($optionId <= 0) {
            return '';
        }
        $stmt = $this->db->prepare("SELECT name FROM `$table` WHERE id = :o LIMIT 1");
        $stmt->execute([':o' => $optionId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /**
     * Refresh a household's coordinates from its address via OpenStreetMap
     * Nominatim (free, no API key), storing latitude/longitude.
     *
     * @return array{success:bool,lat?:float,lng?:float,error?:string}
     */
    public function geocodeFamily(int $famId): array
    {
        if ($famId <= 0) {
            return ['success' => false, 'error' => 'No family to geocode.'];
        }
        $fs = $this->db->prepare('SELECT address_line1, city, region, postal_code, country FROM households WHERE id = :id');
        $fs->execute([':id' => $famId]);
        $f = $fs->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            return ['success' => false, 'error' => 'Family not found.'];
        }
        // Drop apartment/unit tokens (#1008, Unit 5, Apt 3…) — they defeat geocoders.
        $street = trim((string) preg_replace('/\s*(#|unit|apt\.?|suite|ste\.?)\s*\S+/i', '', (string) $f['address_line1']));
        $city = trim((string) $f['city']);
        $state = trim((string) $f['region']);
        $zip = trim((string) $f['postal_code']);
        $country = trim((string) $f['country']);
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
        $this->db->prepare('UPDATE households SET latitude = :lat, longitude = :lng, updated_at = NOW() WHERE id = :id')
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

    /** Portal-owned photo directory. */
    private function photoDir(): string
    {
        return dirname(__DIR__, 2) . '/Images/Person/';
    }

    public function personFamilyId(int $id): int
    {
        $s = $this->db->prepare('SELECT household_id FROM people WHERE id = :id');
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

    /** Remove the photo for a person. */
    public function deletePhoto(int $id): void
    {
        $dir = $this->photoDir();
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            if (is_file($dir . $id . '.' . $ext)) { @unlink($dir . $id . '.' . $ext); }
        }
    }

    /** Set (or clear when null/0) a person's campus. */
    public function setPrimaryCampus(int $personId, ?int $campusId, int $actorId): void
    {
        if ($this->find($personId) === null) {
            throw new \InvalidArgumentException('Unknown person.');
        }
        $this->db->prepare('UPDATE people SET campus_id = :c, updated_at = NOW() WHERE id = :id')
            ->execute([':c' => $campusId !== null && $campusId > 0 ? $campusId : null, ':id' => $personId]);
        $this->audit($actorId, 'person.campus_changed', $personId);
    }

    /**
     * Delete a person. The deletion is recorded against the person's id, with
     * their name kept in the entry so the history still reads once the record
     * is gone.
     */
    public function delete(int $id, int $actorId = 0): void
    {
        $person = $this->find($id);
        if ($person === null) {
            throw new \InvalidArgumentException('Unknown person.');
        }
        // Ministry memberships are kept, not cascaded: removing someone from a
        // ministry is its own decision.
        $mm = $this->db->prepare('SELECT COUNT(*) FROM ministry_members WHERE person_id = :id');
        $mm->execute([':id' => $id]);
        $memberships = (int) $mm->fetchColumn();
        if ($memberships > 0) {
            throw new RuntimeException("This person still belongs to $memberships ministr" . ($memberships === 1 ? 'y' : 'ies') . '. Remove them from it first.');
        }
        $name = trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? ''));
        $name = $name !== '' ? $name : 'Person #' . $id;
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $this->audit($actorId, 'person.deleted', $id, 'Deleted ' . $name, ['name' => $name]);
            $this->db->prepare('DELETE FROM people WHERE id = :id')->execute([':id' => $id]);
            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array{id:int,name:string}> active families for the family dropdown. */
    public function families(): array
    {
        $rows = $this->db->query('SELECT id, name FROM households WHERE deactivated_on IS NULL ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /** Blank person for the "new" form. */
    public function blank(): array
    {
        return [
            'id' => 0, 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'preferred_name' => '',
            'suffix' => '', 'gender' => null, 'birth_month' => null, 'birth_day' => null, 'birth_year' => null,
            'email' => '', 'mobile_phone' => '', 'home_phone' => '',
            'address_line1' => '', 'address_line2' => '', 'city' => '', 'region' => 'Ontario', 'postal_code' => '',
            'country' => 'CA',
            'membership_status_id' => null, 'household_id' => null, 'household_role_id' => null, 'member_since' => null,
            'member_type_id' => null, 'campus_id' => null,
        ];
    }

    // ---- Person write (editor) ----------------------------------------------

    private const STR_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'preferred_name', 'suffix',
        'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country',
        'home_phone', 'mobile_phone', 'email',
    ];

    /** Option and household references: 0 or blank means none. */
    private const REF_FIELDS = ['membership_status_id', 'household_id', 'household_role_id', 'member_type_id'];

    /** 'male' / 'female', anything else is not recorded. */
    private static function gender(mixed $value): ?string
    {
        $g = strtolower(trim((string) $value));
        return in_array($g, ['male', 'female'], true) ? $g : null;
    }

    /**
     * Create or update a person. Last name required; birth month & day must be
     * given together; emails valid. Returns the person id.
     *
     * @param array<string,mixed> $in
     */
    public function save(array $in, int $actorId): int
    {
        $last = trim((string) ($in['last_name'] ?? ''));
        if ($last === '') {
            throw new \InvalidArgumentException('Last name is required.');
        }
        $bm = (int) ($in['birth_month'] ?? 0);
        $bd = (int) ($in['birth_day'] ?? 0);
        if (($bm > 0) !== ($bd > 0)) {
            throw new \InvalidArgumentException('Birth date needs both a month and a day (or leave both blank).');
        }
        $e = trim((string) ($in['email'] ?? ''));
        if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('An email address looks invalid.');
        }

        $values = [];
        foreach (self::STR_FIELDS as $f) {
            $v = trim((string) ($in[$f] ?? ''));
            $values[$f] = $v === '' ? null : $v;
        }
        $values['last_name'] = $last;
        // first_name is NOT NULL in the schema; blank is how "not given" is kept.
        $values['first_name'] = $values['first_name'] ?? '';
        foreach (self::REF_FIELDS as $f) {
            $n = (int) ($in[$f] ?? 0);
            $values[$f] = $n > 0 ? $n : null;
        }
        $values['gender'] = self::gender($in['gender'] ?? null);
        $values['birth_month'] = $bm > 0 ? $bm : null;
        $values['birth_day'] = $bd > 0 ? $bd : null;
        $by = trim((string) ($in['birth_year'] ?? ''));
        $values['birth_year'] = ($by !== '' && (int) $by >= 1900 && (int) $by <= (int) date('Y')) ? (int) $by : null;
        $md = trim((string) ($in['member_since'] ?? ''));
        $values['member_since'] = $md !== '' && strtotime($md) ? date('Y-m-d', (int) strtotime($md)) : null;
        if (array_key_exists('campus_id', $in)) {
            $campus = (int) $in['campus_id'];
            $values['campus_id'] = $campus > 0 ? $campus : null;
        }

        $id = (int) ($in['id'] ?? 0);
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $params = [];
            foreach ($values as $k => $v) { $params[":$k"] = $v; }
            if ($id > 0) {
                $set = implode(', ', array_map(static fn ($c) => "`$c` = :$c", array_keys($values)));
                $params[':id'] = $id;
                $this->db->prepare("UPDATE people SET $set, updated_at = NOW() WHERE id = :id")->execute($params);
                $this->audit($actorId, 'person.updated', $id);
            } else {
                $cols = array_keys($values);
                $ph = array_map(static fn ($c) => ":$c", $cols);
                $sql = 'INSERT INTO people (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $ph) . ')';
                $this->db->prepare($sql)->execute($params);
                $id = (int) $this->db->lastInsertId();
                $this->audit($actorId, 'person.created', $id);
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
     * Columns a person may change about themselves.
     *
     * Deliberately narrower than save(): it is how they are reached and how
     * they describe themselves, and nothing about their standing in the church.
     * Membership status, member type, member since, household and household
     * role are records the church keeps ABOUT a person, not fields the person
     * authors -- and household_id in particular is an access decision, because
     * a household's shared address and member list come with it. Those stay
     * with the admin editor.
     */
    private const OWN_STR_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'preferred_name', 'suffix',
        'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country',
        'home_phone', 'mobile_phone', 'email',
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

        if (array_key_exists('last_name', $values) && $values['last_name'] === null) {
            throw new \InvalidArgumentException('Last name is required.');
        }
        if (array_key_exists('first_name', $values) && $values['first_name'] === null) {
            $values['first_name'] = '';
        }
        if (($values['email'] ?? null) !== null && !filter_var((string) $values['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('That email address looks invalid.');
        }

        // Birthday: month and day travel together, exactly as save() requires.
        $hasBm = array_key_exists('birth_month', $in);
        $hasBd = array_key_exists('birth_day', $in);
        if ($hasBm || $hasBd) {
            $bm = (int) ($in['birth_month'] ?? $current['birth_month'] ?? 0);
            $bd = (int) ($in['birth_day'] ?? $current['birth_day'] ?? 0);
            if (($bm > 0) !== ($bd > 0)) {
                throw new \InvalidArgumentException('A birthday needs both a month and a day, or neither.');
            }
            if ($bm < 0 || $bm > 12 || $bd < 0 || $bd > 31) {
                throw new \InvalidArgumentException('That birthday is not a real date.');
            }
            $values['birth_month'] = $bm > 0 ? $bm : null;
            $values['birth_day'] = $bd > 0 ? $bd : null;
        }
        if (array_key_exists('birth_year', $in)) {
            $by = trim((string) $in['birth_year']);
            $values['birth_year'] = ($by !== '' && (int) $by >= 1900 && (int) $by <= (int) date('Y'))
                ? (int) $by
                : null;
        }
        if (array_key_exists('gender', $in)) {
            $values['gender'] = self::gender($in['gender']);
        }

        if ($values === []) {
            return;
        }

        $set = implode(', ', array_map(static fn (string $c): string => "`$c` = :$c", array_keys($values)));
        $params = [];
        foreach ($values as $k => $v) {
            $params[":$k"] = $v;
        }
        $params[':id'] = $personId;
        $this->db
            ->prepare("UPDATE people SET $set, updated_at = NOW() WHERE id = :id")
            ->execute($params);
        $this->audit($actorId, 'person.profile_updated', $personId);
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
        // What a member does inside a ministry lives in ministry_member_positions;
        // leading is the membership's role.
        $stmt = $this->db->prepare(
            'SELECT m.id AS ministry_id,
                    m.name,
                    COALESCE(pos.name, "") AS role_name,
                    mm.role = "leader" AS is_leader
               FROM ministry_members mm
               INNER JOIN ministries m ON m.id = mm.ministry_id
               LEFT JOIN ministry_member_positions pos ON pos.ministry_member_id = mm.id
              WHERE mm.person_id = :id
                AND mm.status <> "ended"
              ORDER BY m.name ASC'
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

    /**
     * Compact directory used to match an import against existing people.
     *
     * @return list<array{id:int,first_name:string,last_name:string,email:string,campus_id:?int}>
     */
    public function matchIndex(): array
    {
        $sql = 'SELECT p.id, p.first_name, p.last_name,
                       LOWER(TRIM(COALESCE(p.email, ""))) AS email,
                       p.campus_id
                  FROM people p
              ORDER BY p.last_name, p.first_name';
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
     * People whose campus is $campusId, with fields needed for CSV export.
     *
     * @return list<array<string,mixed>>
     */
    public function exportCampus(int $campusId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.first_name, p.middle_name, p.last_name, p.email, p.mobile_phone,
                    p.address_line1, p.city, p.region, p.postal_code,
                    p.birth_month, p.birth_day, p.birth_year,
                    p.member_since,
                    mt.name AS member_type
               FROM people p
               LEFT JOIN member_types mt ON mt.id = p.member_type_id
              WHERE p.campus_id = :c
              ORDER BY p.last_name ASC, p.first_name ASC'
        );
        $stmt->execute([':c' => $campusId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Record who changed a person. The actor id callers pass is a person id; a
     * value that is not one (a login with no person) is kept as no one rather
     * than breaking the write.
     */
    /** @param array<string,mixed>|null $details */
    private function audit(int $actorId, string $action, int $personId, ?string $summary = null, ?array $details = null): void
    {
        $this->db->prepare(
            'INSERT INTO audit_log (account_id, person_id, action, target_type, target_id, summary, details)
             VALUES ((SELECT id FROM user_accounts WHERE id = :actor), (SELECT person_id FROM user_accounts WHERE id = :actor2), :action, "person", :target, :summary, :details)'
        )->execute([
            ':actor' => $actorId, ':actor2' => $actorId, ':action' => $action, ':target' => (string) $personId,
            ':summary' => $summary, ':details' => $details === null ? null : json_encode($details),
        ]);
    }
}
