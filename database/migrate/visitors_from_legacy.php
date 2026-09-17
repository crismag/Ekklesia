<?php
/**
 * Build the visitors SQLite database from the legacy sign-up and RSVP tables.
 *
 *   php database/migrate/visitors_from_legacy.php [output.sqlite]
 *
 * Reads the legacy database named by LEGACY_DB (default u471078694_churchcrm_v0)
 * through LEGACY_DSN / LEGACY_USER / LEGACY_PASSWORD, and the member database
 * (MEMBERS_DB, default christlikeness_members) on the same server to name member
 * types. Writes a fresh file; an existing file is replaced only with --replace.
 *
 * RSVPs recorded against the module's own rsvp_events list cannot be placed on
 * the church calendar and are reported, not copied.
 */
declare(strict_types=1);

$args = array_slice($argv, 1);
$replace = in_array('--replace', $args, true);
$args = array_values(array_filter($args, fn ($a) => $a !== '--replace'));
$root = dirname(__DIR__, 2);
$out = $args[0] ?? $root . '/storage/private/database/visitors.sqlite';

$legacyDb = getenv('LEGACY_DB') ?: 'u471078694_churchcrm_v0';
$membersDb = getenv('MEMBERS_DB') ?: 'christlikeness_members';
foreach ([$legacyDb, $membersDb] as $name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        fwrite(STDERR, "Invalid database name: $name\n");
        exit(1);
    }
}

if (file_exists($out)) {
    if (!$replace) {
        fwrite(STDERR, "$out exists. Pass --replace to rebuild it.\n");
        exit(1);
    }
    unlink($out);
}
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0770, true);
}

$mysql = new PDO(
    getenv('LEGACY_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4',
    getenv('LEGACY_USER') ?: 'root',
    getenv('LEGACY_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$sqlite = new PDO('sqlite:' . $out, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sqlite->exec(file_get_contents($root . '/database/visitors/001_schema.sql'));
$sqlite->beginTransaction();

/** Empty strings and zeroes mean "not recorded" in the legacy tables. */
$text = fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
$num = fn ($v) => ($v === null || (int) $v === 0) ? null : (int) $v;

$memberTypes = [];
foreach ($mysql->query("SELECT id, name FROM `$membersDb`.member_types") as $row) {
    $memberTypes[(int) $row['id']] = $row['name'];
}

// ------------------------------------------------------------ registrations
$insert = $sqlite->prepare(
    'INSERT INTO visitor_registrations (id, first_name, last_name, middle_name, preferred_name, gender, email, phone,
       birth_year, birth_month, birth_day, address_line1, address_line2, city, region, postal_code, country,
       latitude, longitude, facebook, linkedin, twitter, is_married, member_type_name, reason_for_visit, invited_by,
       church_background, visit_notes, source, source_event_id, status, matched_person_id, reviewer_notes,
       created_at, updated_at)
     VALUES (:id, :first_name, :last_name, :middle_name, :preferred_name, :gender, :email, :phone,
       :birth_year, :birth_month, :birth_day, :address_line1, :address_line2, :city, :region, :postal_code, :country,
       :latitude, :longitude, :facebook, :linkedin, :twitter, :is_married, :member_type_name, :reason_for_visit, :invited_by,
       :church_background, :visit_notes, :source, :source_event_id, :status, :matched_person_id, :reviewer_notes,
       :created_at, :updated_at)'
);
$registrations = 0;
foreach ($mysql->query("SELECT * FROM `$legacyDb`.people_signup_temp ORDER BY id") as $r) {
    $source = in_array($r['source'], ['signup', 'rsvp', 'greeter', 'import'], true) ? $r['source'] : 'signup';
    $insert->execute([
        ':id' => (int) $r['id'],
        ':first_name' => $text($r['first_name']) ?? '(no first name)',
        ':last_name' => $text($r['last_name']) ?? '(no last name)',
        ':middle_name' => $text($r['middle_name']),
        ':preferred_name' => $text($r['nick_name']),
        ':gender' => match ((int) $r['gender']) { 1 => 'male', 2 => 'female', default => null },
        ':email' => $text($r['email']),
        ':phone' => $text($r['phone']),
        ':birth_year' => $num($r['birth_year']),
        ':birth_month' => $num($r['birth_month']),
        ':birth_day' => $num($r['birth_day']),
        ':address_line1' => $text($r['address']),
        ':address_line2' => $text($r['address2']),
        ':city' => $text($r['city']),
        ':region' => $text($r['state']),
        ':postal_code' => $text($r['zip']),
        ':country' => $text($r['country']),
        ':latitude' => $r['latitude'] === null || (float) $r['latitude'] === 0.0 ? null : (float) $r['latitude'],
        ':longitude' => $r['longitude'] === null || (float) $r['longitude'] === 0.0 ? null : (float) $r['longitude'],
        ':facebook' => $text($r['facebook']),
        ':linkedin' => $text($r['linkedin']),
        ':twitter' => $text($r['twitter']),
        ':is_married' => $r['is_married'] === null ? null : ((int) $r['is_married'] ? 1 : 0),
        ':member_type_name' => $memberTypes[(int) $r['member_type']] ?? null,
        ':reason_for_visit' => $text($r['reason_for_visit']),
        ':invited_by' => $text($r['invited_by']),
        ':church_background' => $text($r['church_background']),
        ':visit_notes' => $text($r['visit_notes']),
        ':source' => $source,
        ':source_event_id' => $num($r['source_event_id']),
        // The legacy word for promotion was "migrated".
        ':status' => $r['migration_status'] === 'migrated' ? 'promoted' : $r['migration_status'],
        ':matched_person_id' => $num($r['matched_member_id']),
        ':reviewer_notes' => $text($r['admin_notes']),
        ':created_at' => $r['created_at'],
        ':updated_at' => $r['updated_at'] ?? $r['created_at'],
    ]);
    $registrations++;
}

// ------------------------------------------------------------------- RSVPs
$insert = $sqlite->prepare(
    'INSERT INTO visitor_rsvps (id, event_id, visitor_registration_id, person_id, first_name, last_name, email, phone,
       city, response, attendance, party_size, notes, created_at, updated_at)
     VALUES (:id, :event_id, :registration, :person_id, :first_name, :last_name, :email, :phone,
       :city, :response, :attendance, :party_size, :notes, :created_at, :updated_at)'
);
$rsvps = 0;
$skipped = [];
foreach ($mysql->query("SELECT * FROM `$legacyDb`.rsvp_attendance ORDER BY id") as $r) {
    if ($r['event_source'] !== 'churchcrm') {
        $skipped[] = (int) $r['id'];
        continue;
    }
    $insert->execute([
        ':id' => (int) $r['id'],
        ':event_id' => (int) $r['event_id'],
        ':registration' => $num($r['signup_id']),
        ':person_id' => $r['person_type'] === 'member' ? $num($r['member_id']) : null,
        ':first_name' => $text($r['first_name_snapshot']),
        ':last_name' => $text($r['last_name_snapshot']),
        ':email' => $text($r['email_snapshot']),
        ':phone' => $text($r['phone_snapshot']),
        ':city' => $text($r['city_snapshot']),
        ':response' => $r['rsvp_status'] ?? 'yes',
        ':attendance' => $r['attendance_status'] ?? 'registered',
        ':party_size' => max(1, (int) $r['party_count']),
        ':notes' => $text($r['notes']),
        ':created_at' => $r['created_at'],
        ':updated_at' => $r['updated_at'] ?? $r['created_at'],
    ]);
    $rsvps++;
}

// ------------------------------------------------------ admin access codes
$insert = $sqlite->prepare(
    'INSERT INTO visitor_admin_access_codes (id, module, code, issued_at, expires_at, note)
     VALUES (:id, :module, :code, :issued_at, :expires_at, :note)'
);
$codes = 0;
foreach ($mysql->query("SELECT * FROM `$legacyDb`.signup_admin_access ORDER BY id") as $r) {
    $insert->execute([
        ':id' => (int) $r['id'],
        ':module' => $r['module'],
        ':code' => $r['word'],
        ':issued_at' => $r['issued_at'],
        ':expires_at' => $r['expires_at'],
        ':note' => $text($r['note']),
    ]);
    $codes++;
}

$sqlite->commit();

$legacy = fn (string $table) => (int) $mysql->query("SELECT COUNT(*) FROM `$legacyDb`.$table")->fetchColumn();
$fmt = "%-28s %8s %8s  %s\n";
printf($fmt, 'area', 'legacy', 'copied', 'result');
foreach ([
    ['registrations', $legacy('people_signup_temp'), $registrations],
    ['RSVPs', $legacy('rsvp_attendance') - count($skipped), $rsvps],
    ['admin access codes', $legacy('signup_admin_access'), $codes],
] as [$area, $was, $now]) {
    printf($fmt, $area, $was, $now, $was === $now ? 'ok' : 'CHECK');
}
if ($skipped) {
    printf("RSVPs on the module's own event list, not copied: %s\n", implode(', ', $skipped));
}
$moduleEvents = $legacy('rsvp_events');
if ($moduleEvents > 0) {
    printf("The module's own event list has %d events; add them to the church calendar before promoting their RSVPs.\n", $moduleEvents);
}
echo "Wrote $out\n";
