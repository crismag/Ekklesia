<?php
/**
 * People Sign-Up — inline field save endpoint (standalone, token-gated).
 * Accepts one {id, field, value} edit from admin_review.php, validates against a
 * strict whitelist, updates the single column of visitor_registrations, and
 * returns JSON. Never touches the member database.
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

$id    = (int) ($_POST['id'] ?? 0);
$field = (string) ($_POST['field'] ?? '');
$raw   = (string) ($_POST['value'] ?? '');
$val   = sg_str($raw);

if ($id <= 0) {
    sg_json(['ok' => false, 'error' => 'Bad row id'], 400);
}

$STATUSES = ['new', 'reviewed', 'duplicate', 'promoted', 'rejected'];
// Whitelist: field => ['type', extra]. Nothing outside this can be written.
$TEXT_REQUIRED = ['first_name' => 60, 'last_name' => 60, 'city' => 80];
$TEXT_OPTIONAL = [
    'middle_name' => 60, 'preferred_name' => 60, 'email' => 120, 'phone' => 40,
    'address_line1' => 160, 'address_line2' => 160, 'region' => 60, 'postal_code' => 20, 'country' => 60,
    'reason_for_visit' => 120, 'invited_by' => 120, 'church_background' => 255,
    'visit_notes' => 255, 'reviewer_notes' => 65535,
    'facebook' => 120, 'linkedin' => 120, 'twitter' => 120,
];
$INT_RANGE = [
    'birth_year'  => [1900, (int) date('Y')],
    'birth_month' => [1, 12],
    'birth_day'   => [1, 31],
    'matched_person_id' => [1, PHP_INT_MAX],
    'is_married'  => [0, 1],
];
$FLOAT_RANGE = ['latitude' => [-90, 90], 'longitude' => [-180, 180]];

$store = null; // value to bind
try {
    if (isset($TEXT_REQUIRED[$field])) {
        if ($val === '') {
            sg_json(['ok' => false, 'error' => 'This field cannot be empty.'], 422);
        }
        if (mb_strlen($val) > $TEXT_REQUIRED[$field]) {
            sg_json(['ok' => false, 'error' => 'Too long.'], 422);
        }
        $store = $val;
    } elseif (isset($TEXT_OPTIONAL[$field])) {
        if (mb_strlen($val) > $TEXT_OPTIONAL[$field]) {
            sg_json(['ok' => false, 'error' => 'Too long.'], 422);
        }
        if ($field === 'email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
            sg_json(['ok' => false, 'error' => 'Invalid email.'], 422);
        }
        $store = $val === '' ? null : $val;
    } elseif ($field === 'gender') {
        $store = in_array($val, ['male', 'female'], true) ? $val : null;
    } elseif ($field === 'member_type_name') {
        if ($val !== '' && !in_array($val, sg_member_type_names(), true)) {
            sg_json(['ok' => false, 'error' => 'Unknown member type.'], 422);
        }
        $store = $val === '' ? null : $val;
    } elseif (isset($INT_RANGE[$field])) {
        if ($val === '') {
            $store = null;
        } else {
            $n = (int) $val;
            [$lo, $hi] = $INT_RANGE[$field];
            if ($n < $lo || $n > $hi) {
                sg_json(['ok' => false, 'error' => 'Out of range.'], 422);
            }
            $store = $n;
        }
    } elseif (isset($FLOAT_RANGE[$field])) {
        if ($val === '') {
            $store = null;
        } else {
            if (!is_numeric($val)) { sg_json(['ok' => false, 'error' => 'Not a number.'], 422); }
            $n = (float) $val;
            [$lo, $hi] = $FLOAT_RANGE[$field];
            if ($n < $lo || $n > $hi) { sg_json(['ok' => false, 'error' => 'Out of range.'], 422); }
            $store = $n;
        }
    } elseif ($field === 'status') {
        if (!in_array($val, $STATUSES, true)) {
            sg_json(['ok' => false, 'error' => 'Bad status.'], 422);
        }
        $store = $val;
    } else {
        sg_json(['ok' => false, 'error' => 'Field not editable.'], 400);
    }

    $db = sg_db();
    // $field is from the whitelist above, never from the request as-is.
    // A status change is a review decision: stamp reviewed_at.
    $reviewed = $field === 'status' && $store !== 'new' ? ", reviewed_at = datetime('now')" : '';
    $stmt = $db->prepare("UPDATE visitor_registrations SET \"$field\" = :v, updated_at = datetime('now')$reviewed WHERE id = :id");
    $stmt->execute([':v' => $store, ':id' => $id]);
} catch (Throwable $ex) {
    error_log('[people_signup admin_save] ' . $ex->getMessage());
    sg_json(['ok' => false, 'error' => 'Save failed.'], 500);
}

sg_json(['ok' => true, 'id' => $id, 'field' => $field, 'value' => $store]);
