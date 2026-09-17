<?php
/**
 * Events RSVP — submission handler (standalone).
 *
 * Flow: reload the event server-side (authoritative), validate, match against
 * ChurchCRM members. A strong (exact) member match is recorded as a member
 * attendance row. Anyone else is saved to the flat people_signup_temp review
 * table (source=rsvp) and recorded as a new_signup attendance row — never
 * auto-inserted into the main member tables.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/validation.php';

rv_session();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php');
    exit;
}

$eventId     = (int) ($_POST['event_id'] ?? 0);
$eventSource = rv_str($_POST['event_source'] ?? 'churchcrm');

$in = [
    'first_name'  => rv_str($_POST['first_name'] ?? ''),
    'last_name'   => rv_str($_POST['last_name'] ?? ''),
    'email'       => rv_str($_POST['email'] ?? ''),
    'phone'       => rv_str($_POST['phone'] ?? ''),
    'city'        => rv_str($_POST['city'] ?? ''),
    'birth_month' => rv_intn($_POST['birth_month'] ?? null),
    'birth_year'  => rv_intn($_POST['birth_year'] ?? null),
    'rsvp_status' => rv_str($_POST['rsvp_status'] ?? 'yes'),
    'party_count' => (int) ($_POST['party_count'] ?? 1),
    'notes'       => rv_str($_POST['notes'] ?? ''),
];

/** Bounce back to the event form preserving input. */
$fail = static function (array $errors) use ($in, $eventId): void {
    $_SESSION['rv_errors'] = $errors;
    $_SESSION['rv_old']    = $in;
    header('Location: event.php?event_id=' . $eventId);
    exit;
};

if (!rv_csrf_ok($_POST['csrf'] ?? null)) {
    $fail(['Your session expired. Please try again.']);
}

try {
    $db = rv_db();
    $event = rv_load_event($db, $eventId);
} catch (Throwable $ex) {
    error_log('[events_rsvp] submit event load failed: ' . $ex->getMessage());
    $fail(['We could not load this event. Please try again.']);
}
if ($event === null) {
    $fail(['This event is no longer available for RSVP.']);
}

$errors = rv_validate($in);
if ($errors) {
    $fail($errors);
}

if ($in['party_count'] < 1) { $in['party_count'] = 1; }
if ($in['party_count'] > 50) { $in['party_count'] = 50; }

try {
    $match = rv_match_members($db, $in);

    $personType = 'new_signup';
    $memberId   = null;
    $signupId   = null;

    if (!empty($match['exact'])) {
        // Confident match → record against the existing member.
        $personType = 'member';
        $memberId   = (int) $match['exact'][0]['id'];
    } else {
        // Not a confident member. If this same person already RSVP'd to this event
        // (staged earlier), reuse that signup row instead of piling up duplicates.
        $selfDupes = rv_find_signup_dupes($db, $in, $eventId);
        if (!empty($selfDupes['exact'])) {
            $signupId = (int) $selfDupes['exact'][0]['id'];
            $personType = 'new_signup';
        } else {
            // Stage a fresh review-table row.
            $signupStatus  = !empty($match['possible']) ? 'duplicate' : 'new';
            $possibleMatch = !empty($match['possible']) ? (int) $match['possible'][0]['id'] : null;
            $st = $db->prepare(
                'INSERT INTO people_signup_temp
                    (first_name, last_name, city, email, phone, birth_year, birth_month,
                     source, source_event_id, migration_status, matched_member_id)
                 VALUES
                    (:fn, :ln, :city, :email, :phone, :by, :bm,
                     :source, :ev, :status, :matched)'
            );
            $st->execute([
                ':fn'      => $in['first_name'],
                ':ln'      => $in['last_name'],
                ':city'    => $in['city'] !== '' ? $in['city'] : null,
                ':email'   => $in['email'] !== '' ? $in['email'] : null,
                ':phone'   => $in['phone'] !== '' ? $in['phone'] : null,
                ':by'      => $in['birth_year'],
                ':bm'      => $in['birth_month'],
                ':source'  => 'rsvp',
                ':ev'      => $eventId,
                ':status'  => $signupStatus,
                ':matched' => $possibleMatch,
            ]);
            $signupId = (int) $db->lastInsertId();
        }
    }

    $st = $db->prepare(
        'INSERT INTO rsvp_attendance
            (event_source, event_id, person_type, member_id, signup_id,
             first_name_snapshot, last_name_snapshot, city_snapshot, email_snapshot, phone_snapshot,
             rsvp_status, party_count, notes)
         VALUES
            (:src, :ev, :ptype, :member, :signup,
             :fn, :ln, :city, :email, :phone,
             :status, :party, :notes)'
    );
    $st->execute([
        ':src'    => $event['source'],
        ':ev'     => (int) $event['id'],
        ':ptype'  => $personType,
        ':member' => $memberId,
        ':signup' => $signupId,
        ':fn'     => $in['first_name'],
        ':ln'     => $in['last_name'],
        ':city'   => $in['city'] !== '' ? $in['city'] : null,
        ':email'  => $in['email'] !== '' ? $in['email'] : null,
        ':phone'  => $in['phone'] !== '' ? $in['phone'] : null,
        ':status' => $in['rsvp_status'],
        ':party'  => $in['party_count'],
        ':notes'  => $in['notes'] !== '' ? $in['notes'] : null,
    ]);
} catch (Throwable $ex) {
    error_log('[events_rsvp] submit failed: ' . $ex->getMessage());
    $fail(['Something went wrong saving your RSVP. Please try again.']);
}

// Stash a small confirmation payload; confirmation.php reads it once.
$_SESSION['rv_last'] = [
    'event_title' => $event['title'],
    'event_date'  => $event['date'],
    'event_time'  => $event['time'],
    'location'    => $event['location'],
    'name'        => trim($in['first_name'] . ' ' . $in['last_name']),
    'status'      => $in['rsvp_status'],
    'party'       => $in['party_count'],
];
rv_csrf_rotate();

header('Location: confirmation.php');
exit;
