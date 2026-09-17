<?php
/**
 * Events RSVP — submission handler (standalone).
 *
 * Flow: reload the event server-side from the member database (authoritative),
 * validate, match against members. A strong (exact) member match is recorded
 * as a member RSVP (person_id). Anyone else is saved as a visitor registration
 * (source=rsvp) and the RSVP points at it (visitor_registration_id) — never
 * auto-inserted into the member database. RSVPs live in the visitors database.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/validation.php';

rv_session();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php');
    exit;
}

$eventId = (int) ($_POST['event_id'] ?? 0);

$in = [
    'first_name'  => rv_str($_POST['first_name'] ?? ''),
    'last_name'   => rv_str($_POST['last_name'] ?? ''),
    'email'       => rv_str($_POST['email'] ?? ''),
    'phone'       => rv_str($_POST['phone'] ?? ''),
    'city'        => rv_str($_POST['city'] ?? ''),
    'birth_month' => rv_intn($_POST['birth_month'] ?? null),
    'birth_year'  => rv_intn($_POST['birth_year'] ?? null),
    'response'    => rv_str($_POST['response'] ?? 'yes'),
    'party_size'  => (int) ($_POST['party_size'] ?? 1),
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
    $members = rv_members_db();
    $event = rv_load_event($members, $eventId);
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

if ($in['party_size'] < 1) { $in['party_size'] = 1; }
if ($in['party_size'] > 50) { $in['party_size'] = 50; }

try {
    $match = rv_match_members($members, $in);

    $personId       = null;
    $registrationId = null;

    if (!empty($match['exact'])) {
        // Confident match → record against the existing member.
        $personId = (int) $match['exact'][0]['id'];
    } else {
        // Not a confident member. If this same person already RSVP'd to this event
        // (registered earlier), reuse that registration instead of piling up duplicates.
        $selfDupes = rv_find_signup_dupes($db, $in, $eventId);
        if (!empty($selfDupes['exact'])) {
            $registrationId = (int) $selfDupes['exact'][0]['id'];
        } else {
            // A fresh registration for review.
            $status        = !empty($match['possible']) ? 'duplicate' : 'new';
            $possibleMatch = !empty($match['possible']) ? (int) $match['possible'][0]['id'] : null;
            $st = $db->prepare(
                'INSERT INTO visitor_registrations
                    (first_name, last_name, city, email, phone, birth_year, birth_month,
                     source, source_event_id, status, matched_person_id, created_at, updated_at)
                 VALUES
                    (:first_name, :last_name, :city, :email, :phone, :birth_year, :birth_month,
                     :source, :source_event_id, :status, :matched_person_id, :now, :now)'
            );
            $st->execute([
                ':first_name'        => $in['first_name'],
                ':last_name'         => $in['last_name'],
                ':city'              => $in['city'] !== '' ? $in['city'] : null,
                ':email'             => $in['email'] !== '' ? $in['email'] : null,
                ':phone'             => $in['phone'] !== '' ? $in['phone'] : null,
                ':birth_year'        => $in['birth_year'],
                ':birth_month'       => $in['birth_month'],
                ':source'            => 'rsvp',
                ':source_event_id'   => $eventId,
                ':status'            => $status,
                ':matched_person_id' => $possibleMatch,
                ':now'               => rsvp_now(),
            ]);
            $registrationId = (int) $db->lastInsertId();
        }
    }

    $st = $db->prepare(
        'INSERT INTO visitor_rsvps
            (event_id, occurrence_id, visitor_registration_id, person_id,
             first_name, last_name, city, email, phone,
             response, party_size, notes, created_at, updated_at)
         VALUES
            (:event_id, :occurrence_id, :visitor_registration_id, :person_id,
             :first_name, :last_name, :city, :email, :phone,
             :response, :party_size, :notes, :now, :now)'
    );
    $st->execute([
        ':event_id'                => (int) $event['id'],
        ':occurrence_id'           => $event['occurrence_id'],
        ':visitor_registration_id' => $registrationId,
        ':person_id'               => $personId,
        ':first_name'              => $in['first_name'],
        ':last_name'               => $in['last_name'],
        ':city'                    => $in['city'] !== '' ? $in['city'] : null,
        ':email'                   => $in['email'] !== '' ? $in['email'] : null,
        ':phone'                   => $in['phone'] !== '' ? $in['phone'] : null,
        ':response'                => $in['response'],
        ':party_size'              => $in['party_size'],
        ':notes'                   => $in['notes'] !== '' ? $in['notes'] : null,
        ':now'                     => rsvp_now(),
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
    'status'      => $in['response'],
    'party'       => $in['party_size'],
];
rv_csrf_rotate();

header('Location: confirmation.php');
exit;
