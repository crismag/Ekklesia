<?php
/**
 * People Sign-Up — promote registrations to members.
 * Token-gated, CSRF-protected. Accepts one or many ids.
 *
 * Rules:
 *  - Skips rows already promoted, rejected or duplicate.
 *  - When the row's Member # (matched_person_id) is one of the people it
 *    matches in the member database, the row is promoted as matched to that
 *    person (outcome matched_existing); no person is created.
 *  - Otherwise refuses a row that confidently matches an existing member (so we
 *    never create duplicates) unless force=1 is passed, and inserts a person
 *    into the member database (outcome created).
 *  - Then, in the visitors database, marks the registration 'promoted' with
 *    matched_person_id and inserts visitor_promotions. Pastoral-context fields
 *    stay on the registration for reference (people has no column for them).
 *
 * The member database (MySQL) and the visitors database (SQLite) cannot share a
 * transaction: the member row is written first, then the visitors rows. If the
 * visitors write fails the person still exists; the result says so, and the
 * registration can be promoted again as matched to that person.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
sg_admin_gate();

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sg_json(['ok' => false, 'error' => 'POST required'], 405);
}
if (!sg_admin_csrf_ok()) {
    sg_json(['ok' => false, 'error' => 'Session expired — reload the page.'], 419);
}

$force = (string) ($_POST['force'] ?? '') === '1';

// Accept ids=1,2,3 or id=1
$idsRaw = (string) ($_POST['ids'] ?? ($_POST['id'] ?? ''));
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), static fn ($n) => $n > 0)));
if (!$ids) {
    sg_json(['ok' => false, 'error' => 'No rows selected.'], 400);
}

$db = sg_db();
$members = sg_members_db();
$results = [];

// Membership status from the member database's own list; an unknown id falls
// back to the first status in its order (Member).
$statusIds = array_map('intval', $members->query('SELECT id FROM membership_statuses ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN));
$membershipStatusId = (int) ($_POST['membership_status_id'] ?? 0);
if (!in_array($membershipStatusId, $statusIds, true)) { $membershipStatusId = $statusIds[0] ?? null; }

$insertPerson = $members->prepare(
    'INSERT INTO people
        (first_name, middle_name, last_name, preferred_name,
         address_line1, address_line2, city, region, postal_code, country, latitude, longitude,
         mobile_phone, email, birth_month, birth_day, birth_year, gender,
         member_type_id, membership_status_id, campus_id, created_at, updated_at)
     VALUES
        (:first_name, :middle_name, :last_name, :preferred_name,
         :address_line1, :address_line2, :city, :region, :postal_code, :country, :latitude, :longitude,
         :mobile_phone, :email, :birth_month, :birth_day, :birth_year, :gender,
         :member_type_id, :membership_status_id, NULL, NOW(), NOW())'
);
$memberTypeId = $members->prepare('SELECT id FROM member_types WHERE name = :name LIMIT 1');

$markPromoted = $db->prepare(
    "UPDATE visitor_registrations
        SET status = 'promoted', matched_person_id = :person_id,
            reviewed_at = :now, updated_at = :now
      WHERE id = :id"
);
$recordPromotion = $db->prepare(
    "INSERT INTO visitor_promotions
        (visitor_registration_id, person_id, outcome, promoted_by_account_id, promoted_at, notes)
     VALUES (:id, :person_id, :outcome, NULL, :now, :notes)"
);

$nullIfBlank = static fn ($v) => ($v === null || trim((string) $v) === '') ? null : $v;

foreach ($ids as $id) {
    $row = $db->prepare('SELECT * FROM visitor_registrations WHERE id = :id');
    $row->execute([':id' => $id]);
    $r = $row->fetch();

    if (!$r) { $results[] = ['id' => $id, 'ok' => false, 'error' => 'Not found']; continue; }
    if ($r['status'] === 'promoted') {
        $results[] = ['id' => $id, 'ok' => false, 'error' => 'Already promoted to member', 'person_id' => (int) $r['matched_person_id']];
        continue;
    }
    // Rejected / duplicate rows are excluded from promotion (matches the UI).
    if (in_array($r['status'], ['rejected', 'duplicate'], true)) {
        $results[] = ['id' => $id, 'ok' => false, 'error' => ucfirst($r['status']) . ' rows cannot be promoted'];
        continue;
    }

    $linkTo = null;
    $notes = null;
    try {
        $m = sg_match_members($members, [
            'first_name' => $r['first_name'], 'last_name' => $r['last_name'],
            'email' => $r['email'], 'phone' => $r['phone'],
            'birth_month' => $r['birth_month'], 'birth_year' => $r['birth_year'],
        ]);
        $exactIds = array_map(static fn ($c) => (int) $c['id'], $m['exact']);
        $matchIds = array_merge($exactIds, array_map(static fn ($c) => (int) $c['id'], $m['possible']));
        $chosen = $r['matched_person_id'] !== null ? (int) $r['matched_person_id'] : 0;

        // Linked to a member it matches: record the link, create nothing.
        if (!$force && $chosen > 0 && in_array($chosen, $matchIds, true)) {
            $linkTo = $chosen;
        } elseif (!$force && $exactIds) {
            $results[] = ['id' => $id, 'ok' => false, 'needs_review' => true,
                'error' => 'Matches existing member #' . $exactIds[0] . ' — set Member # to link it, or force.',
                'person_id' => $exactIds[0]];
            continue;
        } elseif ($force && $exactIds) {
            $notes = 'Created although it matched member #' . implode(', #', $exactIds);
        }
    } catch (Throwable $ex) {
        error_log('[people_signup promote] match id=' . $id . ' ' . $ex->getMessage());
        $results[] = ['id' => $id, 'ok' => false, 'error' => 'Promotion failed'];
        continue;
    }

    if ($linkTo === null) {
        // Last name is the only hard requirement; optional fields are only
        // written when valid. A missing value is NULL.
        if (trim((string) $r['last_name']) === '') {
            $results[] = ['id' => $id, 'ok' => false, 'error' => 'Missing last name (required for a member record)'];
            continue;
        }
        $email = ($r['email'] && filter_var($r['email'], FILTER_VALIDATE_EMAIL)) ? $r['email'] : null;
        $birthMonth = (int) $r['birth_month'];
        $birthDay   = (int) $r['birth_day'];
        $birthYear  = (int) $r['birth_year'];

        try {
            $typeId = null;
            if ($nullIfBlank($r['member_type_name']) !== null) {
                $memberTypeId->execute([':name' => $r['member_type_name']]);
                $found = $memberTypeId->fetchColumn();
                $typeId = $found !== false ? (int) $found : null;
            }
            $insertPerson->execute([
                ':first_name'     => (string) $r['first_name'],
                ':middle_name'    => $nullIfBlank($r['middle_name']),
                ':last_name'      => $r['last_name'],
                ':preferred_name' => $nullIfBlank($r['preferred_name']),
                ':address_line1'  => $nullIfBlank($r['address_line1']),
                ':address_line2'  => $nullIfBlank($r['address_line2']),
                ':city'           => $nullIfBlank($r['city']),
                ':region'         => $nullIfBlank($r['region']),
                ':postal_code'    => $nullIfBlank($r['postal_code']),
                ':country'        => $nullIfBlank($r['country']),
                ':latitude'       => $r['latitude'] !== null ? (string) $r['latitude'] : null,
                ':longitude'      => $r['longitude'] !== null ? (string) $r['longitude'] : null,
                ':mobile_phone'   => $nullIfBlank($r['phone']),
                ':email'          => $email,
                ':birth_month'    => $birthMonth >= 1 && $birthMonth <= 12 ? $birthMonth : null,
                ':birth_day'      => $birthDay >= 1 && $birthDay <= 31 ? $birthDay : null,
                ':birth_year'     => $birthYear > 0 ? $birthYear : null,
                ':gender'         => in_array($r['gender'], ['male', 'female'], true) ? $r['gender'] : null,
                ':member_type_id' => $typeId,
                ':membership_status_id' => $membershipStatusId,
            ]);
            $personId = (int) $members->lastInsertId();
        } catch (Throwable $ex) {
            error_log('[people_signup promote] person id=' . $id . ' ' . $ex->getMessage());
            $results[] = ['id' => $id, 'ok' => false, 'error' => 'Promotion failed'];
            continue;
        }
        $outcome = 'created';
    } else {
        $personId = $linkTo;
        $outcome = 'matched_existing';
    }

    try {
        $db->beginTransaction();
        $now = signup_now();
        $markPromoted->execute([':person_id' => $personId, ':now' => $now, ':id' => $id]);
        $recordPromotion->execute([':id' => $id, ':person_id' => $personId, ':outcome' => $outcome, ':now' => $now, ':notes' => $notes]);
        $db->commit();
        $results[] = ['id' => $id, 'ok' => true, 'person_id' => $personId, 'outcome' => $outcome];
    } catch (Throwable $ex) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('[people_signup promote] registration id=' . $id . ' person=' . $personId . ' ' . $ex->getMessage());
        $results[] = ['id' => $id, 'ok' => false, 'person_id' => $personId, 'error' => $outcome === 'created'
            ? 'Person #' . $personId . ' was added to the member records, but this registration could not be marked promoted. Set Member # to ' . $personId . ' and promote again to record it.'
            : 'This registration could not be marked promoted. Please try again.'];
    }
}

$okCount = count(array_filter($results, static fn ($x) => $x['ok']));
sg_json(['ok' => true, 'promoted' => $okCount, 'total' => count($results), 'results' => $results]);
