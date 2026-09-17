<?php
/**
 * People Sign-Up — migrate staged guests into ChurchCRM (person_per).
 * Token-gated, CSRF-protected, transactional. Accepts one or many ids.
 *
 * Rules:
 *  - Skips rows already migrated.
 *  - Refuses a row that confidently matches an existing member (link instead,
 *    so we never create duplicates) unless force=1 is passed.
 *  - On success: inserts a person_per row, then marks the staged row 'migrated'
 *    with matched_member_id = the new per_ID. Pastoral-context fields stay on the
 *    staged row for reference (person_per has no column for them).
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

$ALLOWED_CLS = [1, 2, 3, 4, 5];               // list_lst lst_ID=1 option ids
$cls   = (int) ($_POST['cls'] ?? 1);
if (!in_array($cls, $ALLOWED_CLS, true)) { $cls = 1; } // default: Member
$force = (string) ($_POST['force'] ?? '') === '1';

// Accept ?ids=1,2,3 or id=1
$idsRaw = (string) ($_POST['ids'] ?? ($_POST['id'] ?? ''));
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), static fn ($n) => $n > 0)));
if (!$ids) {
    sg_json(['ok' => false, 'error' => 'No rows selected.'], 400);
}

$db = sg_db();
$results = [];

$insert = $db->prepare(
    'INSERT INTO person_per
        (per_FirstName, per_MiddleName, per_LastName, per_Address1, per_Address2, per_City, per_State, per_Zip, per_Country,
         per_CellPhone, per_Email, per_BirthMonth, per_BirthDay, per_BirthYear, per_Gender,
         per_Facebook, per_LinkedIn, per_Twitter,
         per_cls_ID, per_fam_ID, per_fmr_ID, per_Flags, per_EnteredBy,
         per_DateEntered, per_DateLastEdited)
     VALUES
        (:fn, :mn, :ln, :addr, :addr2, :city, :state, :zip, :country,
         :cell, :email, :bmon, :bday, :byear, :gender,
         :fb, :li, :tw,
         :cls, 0, 0, 0, 0, NOW(), NOW())'
);
// Member Type → person_custom.c1 (1=Radical, 2=Trailblazer, 3=G&A).
$customIns = $db->prepare(
    'INSERT INTO person_custom (per_ID, c1) VALUES (:pid, :mt)
     ON DUPLICATE KEY UPDATE c1 = VALUES(c1)'
);

foreach ($ids as $id) {
    $row = $db->prepare('SELECT * FROM people_signup_temp WHERE id = :id');
    $row->execute([':id' => $id]);
    $r = $row->fetch();

    if (!$r) { $results[] = ['id' => $id, 'ok' => false, 'error' => 'Not found']; continue; }
    if ($r['migration_status'] === 'migrated') {
        $results[] = ['id' => $id, 'ok' => false, 'error' => 'Already migrated', 'member_id' => (int) $r['matched_member_id']];
        continue;
    }
    // Rejected / duplicate rows are excluded from import (matches the UI).
    if (in_array($r['migration_status'], ['rejected', 'duplicate'], true)) {
        $results[] = ['id' => $id, 'ok' => false, 'error' => ucfirst($r['migration_status']) . ' rows are not importable'];
        continue;
    }

    // Duplicate guard: refuse a confident existing-member match unless forced.
    if (!$force) {
        $m = sg_match_members($db, [
            'first_name' => $r['first_name'], 'last_name' => $r['last_name'],
            'email' => $r['email'], 'phone' => $r['phone'],
            'birth_month' => $r['birth_month'], 'birth_year' => $r['birth_year'],
        ]);
        if (!empty($m['exact'])) {
            $results[] = ['id' => $id, 'ok' => false, 'needs_review' => true,
                'error' => 'Matches existing member #' . (int) $m['exact'][0]['id'] . ' — link instead, or force.',
                'member_id' => (int) $m['exact'][0]['id']];
            continue;
        }
    }

    // Mirror ChurchCRM PersonEditor rules: last name is the only hard requirement;
    // optional fields are only written when valid/consistent.
    if (trim((string) $r['last_name']) === '') {
        $results[] = ['id' => $id, 'ok' => false, 'error' => 'Missing last name (ChurchCRM requires it)'];
        continue;
    }
    // Email only if it passes format validation (ChurchCRM rejects bad emails).
    $email = ($r['email'] && filter_var($r['email'], FILTER_VALIDATE_EMAIL)) ? $r['email'] : null;
    // Birthday month & day must travel together; keep the year regardless.
    $bmon = (int) $r['birth_month'];
    $bday = (int) $r['birth_day'];
    if ($bmon === 0 || $bday === 0) { $bmon = 0; $bday = 0; } // avoid an invalid partial date

    try {
        $db->beginTransaction();
        $insert->execute([
            ':fn'      => $r['first_name'] ?: null,
            ':mn'      => $r['middle_name'] ?: null,
            ':ln'      => $r['last_name'],
            ':addr'    => $r['address'] ?: null,
            ':addr2'   => $r['address2'] ?: null,
            ':city'    => $r['city'] ?: null,
            ':state'   => $r['state'] ?: null,
            ':zip'     => $r['zip'] ?: null,
            ':country' => $r['country'] ?: null,
            ':cell'    => $r['phone'] ?: null,
            ':email'   => $email,
            ':bmon'    => $bmon,   // NOT NULL, 0 when unknown/partial
            ':bday'    => $bday,   // NOT NULL, 0 when unknown/partial
            ':byear'   => $r['birth_year'] ?: null,
            ':gender'  => in_array((int) $r['gender'], [1, 2], true) ? (int) $r['gender'] : 0,
            ':fb'      => $r['facebook'] ?: null,
            ':li'      => $r['linkedin'] ?: null,
            ':tw'      => $r['twitter'] ?: null,
            ':cls'     => $cls,
        ]);
        $newId = (int) $db->lastInsertId();

        // Member Type custom field (only when set on the staged row).
        if ($r['member_type'] !== null && in_array((int) $r['member_type'], [1, 2, 3], true)) {
            $customIns->execute([':pid' => $newId, ':mt' => (int) $r['member_type']]);
        }

        $upd = $db->prepare('UPDATE people_signup_temp SET migration_status = :s, matched_member_id = :m WHERE id = :id');
        $upd->execute([':s' => 'migrated', ':m' => $newId, ':id' => $id]);

        $db->commit();
        $results[] = ['id' => $id, 'ok' => true, 'member_id' => $newId];
    } catch (Throwable $ex) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('[people_signup migrate] id=' . $id . ' ' . $ex->getMessage());
        $results[] = ['id' => $id, 'ok' => false, 'error' => 'Migration failed'];
    }
}

$okCount = count(array_filter($results, static fn ($x) => $x['ok']));
sg_json(['ok' => true, 'migrated' => $okCount, 'total' => count($results), 'results' => $results]);
