<?php
/**
 * People Sign-Up — inline field save endpoint (standalone, token-gated).
 * Accepts one {id, field, value} edit from admin_review.php, validates against a
 * strict whitelist, updates the single column, and returns JSON. Never touches
 * the main member tables.
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

$STATUSES = ['new', 'reviewed', 'duplicate', 'migrated', 'rejected'];
// Whitelist: field => ['type', extra]. Nothing outside this can be written.
$TEXT_REQUIRED = ['first_name' => 60, 'last_name' => 60, 'city' => 80];
$TEXT_OPTIONAL = [
    'middle_name' => 60, 'nick_name' => 60, 'email' => 120, 'phone' => 40,
    'address' => 160, 'address2' => 160, 'state' => 60, 'zip' => 20, 'country' => 60,
    'reason_for_visit' => 120, 'invited_by' => 120, 'church_background' => 255,
    'visit_notes' => 255, 'admin_notes' => 65535,
    'facebook' => 120, 'linkedin' => 120, 'twitter' => 120,
];
$INT_RANGE = [
    'birth_year'  => [1900, (int) date('Y')],
    'birth_month' => [1, 12],
    'birth_day'   => [1, 31],
    'matched_member_id' => [1, PHP_INT_MAX],
    'member_type' => [1, 3],
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
        $store = in_array($val, ['1', '2'], true) ? (int) $val : null;
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
    } elseif ($field === 'migration_status') {
        if (!in_array($val, $STATUSES, true)) {
            sg_json(['ok' => false, 'error' => 'Bad status.'], 422);
        }
        $store = $val;
    } else {
        sg_json(['ok' => false, 'error' => 'Field not editable.'], 400);
    }

    $db = sg_db();
    $stmt = $db->prepare("UPDATE people_signup_temp SET `$field` = :v WHERE id = :id");
    $stmt->execute([':v' => $store, ':id' => $id]);
} catch (Throwable $ex) {
    error_log('[people_signup admin_save] ' . $ex->getMessage());
    sg_json(['ok' => false, 'error' => 'Save failed.'], 500);
}

sg_json(['ok' => true, 'id' => $id, 'field' => $field, 'value' => $store]);
