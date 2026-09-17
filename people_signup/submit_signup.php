<?php
/**
 * People Sign-Up — submission handler (standalone).
 *
 * Single-record model: the first page INSERTs a staged row and remembers its id
 * in the session; later pages (advanced, extra) UPDATE that same row, touching
 * only the fields they submit. Confident existing members are turned away and
 * never staged. Nothing here writes to the main member tables.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/validation.php';

sg_session();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php');
    exit;
}

$mode = (string) ($_POST['mode'] ?? '');           // '' | advanced | extra
$backMap = ['advanced' => 'advanced.php', 'extra' => 'extra.php'];
$backTo  = $backMap[$mode] ?? 'index.php';

// Field groups. Only keys actually POSTed are considered (so each page updates
// just its own fields on an UPDATE).
$TEXT  = ['first_name', 'last_name', 'middle_name', 'nick_name', 'city', 'address', 'address2',
         'state', 'zip', 'country', 'email', 'phone', 'reason_for_visit', 'invited_by',
         'church_background', 'visit_notes', 'facebook', 'linkedin', 'twitter'];
$INT   = ['gender', 'birth_year', 'birth_month', 'birth_day', 'member_type', 'is_married'];
$FLOAT = ['latitude', 'longitude'];

$in = [];        // normalized values, for validation + display
$posted = [];    // column => bind value, only for keys present in this POST
foreach ($TEXT as $f) {
    $in[$f] = sg_str($_POST[$f] ?? '');
    if (array_key_exists($f, $_POST)) { $posted[$f] = $in[$f] === '' ? null : $in[$f]; }
}
foreach ($INT as $f) {
    $in[$f] = sg_intn($_POST[$f] ?? null);
    if (array_key_exists($f, $_POST)) { $posted[$f] = $in[$f]; }
}
foreach ($FLOAT as $f) {
    $v = sg_str($_POST[$f] ?? '');
    $in[$f] = ($v !== '' && is_numeric($v)) ? (float) $v : null;
    if (array_key_exists($f, $_POST)) { $posted[$f] = $in[$f]; }
}

/** Bounce back to the current form with errors + old input preserved. */
$fail = static function (array $errors) use ($in, $backTo): void {
    $_SESSION['sg_errors'] = $errors;
    $_SESSION['sg_old']    = $in;
    header('Location: ' . $backTo);
    exit;
};

if (!sg_csrf_ok($_POST['csrf'] ?? null)) {
    $fail(['Your session expired. Please try again.']);
}

// Is this a continuation of an existing staged row?
$rowId = (int) ($_SESSION['sg_row_id'] ?? 0);
try {
    $db = sg_db();
    $existing = null;
    if ($rowId > 0) {
        $q = $db->prepare('SELECT * FROM people_signup_temp WHERE id = :id AND source = "signup"');
        $q->execute([':id' => $rowId]);
        $existing = $q->fetch() ?: null;
    }
} catch (Throwable $ex) {
    error_log('[people_signup] load failed: ' . $ex->getMessage());
    $fail(['Something went wrong on our end. Please try again in a moment.']);
}

$isUpdate = $existing !== null;

// ---- Validation ----------------------------------------------------------
if (!$isUpdate) {
    // Fresh record: full required-field validation.
    $errors = sg_validate_simple($in);
} else {
    // Continuation: only validate the fields this page actually submitted.
    $errors = sg_validate_extra($posted);
}
if ($errors) {
    $fail($errors);
}

// ---- Member guard (only when creating a NEW record) ----------------------
if (!$isUpdate) {
    try {
        $match = sg_match_members($db, $in);
    } catch (Throwable $ex) {
        error_log('[people_signup] match failed: ' . $ex->getMessage());
        $fail(['Something went wrong on our end. Please try again in a moment.']);
    }
    if (!empty($match['exact'])) {
        sg_csrf_rotate();
        unset($_SESSION['sg_prefill'], $_SESSION['sg_row_id']);
        $church = (string) sg_cfg('app.church_name', '');
        $name   = trim($in['first_name']);
        ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Already registered · <?= e($church !== '' ? $church : 'Guest Sign-Up') ?></title>
<link rel="stylesheet" href="assets/signup.css"><?= theme_tokens_style_block() ?>
</head><body>
<div class="wrap">
  <div class="brand"><h1><?= e($church !== '' ? $church : 'Welcome back') ?></h1></div>
  <div class="card"><div class="success">
    <div class="mark">&#10003;</div>
    <h2>Welcome back<?= $name !== '' ? ', ' . e($name) : '' ?>!</h2>
    <p>Good news — you're already in our church records, so there's no need to sign up again.
       If any of your details have changed, please let a member of our team know.</p>
    <div class="actions"><a class="btn" href="index.php">Back to start</a></div>
  </div></div>
  <p class="foot">This sign-up form is for first-time and returning guests who aren't yet in our records.</p>
</div></body></html><?php
        exit;
    }
}

// ---- Write ---------------------------------------------------------------
$status = $isUpdate ? (string) $existing['migration_status'] : 'new';
$matched = $isUpdate ? ($existing['matched_member_id'] !== null ? (int) $existing['matched_member_id'] : null) : null;

try {
    if (!$isUpdate) {
        // Flag a weaker (possible) member match as a hint for the admin.
        if (!empty($match['possible'])) { $matched = (int) $match['possible'][0]['id']; }
        // Repeat signup by a non-member?
        $dupes = sg_find_signup_dupes($db, $in);
        if (!empty($dupes['exact'])) { $status = 'duplicate'; }

        // Auto-detect Member Type from birth year (+ married) when not supplied.
        if (!array_key_exists('member_type', $posted) || $posted['member_type'] === null) {
            $posted['member_type'] = sg_detect_member_type($in['birth_year'], (int) $in['is_married'] === 1);
        }

        $cols = array_keys($posted);
        $cols[] = 'source'; $cols[] = 'migration_status'; $cols[] = 'matched_member_id';
        $ph = array_map(static fn ($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO people_signup_temp (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')';
        $bind = [];
        foreach ($posted as $c => $v) { $bind[':' . $c] = $v; }
        $bind[':source'] = 'signup';
        $bind[':migration_status'] = $status;
        $bind[':matched_member_id'] = $matched;
        $db->prepare($sql)->execute($bind);
        $rowId = (int) $db->lastInsertId();
    } else {
        if ($posted) {
            $sets = implode(', ', array_map(static fn ($c) => "`$c` = :$c", array_keys($posted)));
            $bind = [];
            foreach ($posted as $c => $v) { $bind[':' . $c] = $v; }
            $bind[':id'] = $rowId;
            $db->prepare("UPDATE people_signup_temp SET $sets WHERE id = :id")->execute($bind);
        }
    }
} catch (Throwable $ex) {
    error_log('[people_signup] write failed: ' . $ex->getMessage());
    $fail(['Something went wrong on our end. Please try again in a moment.']);
}

$_SESSION['sg_row_id'] = $rowId;
sg_csrf_rotate();

// Reload the full row so the next page pre-fills completely.
try {
    $r = $db->prepare('SELECT * FROM people_signup_temp WHERE id = :id');
    $r->execute([':id' => $rowId]);
    $_SESSION['sg_prefill'] = $r->fetch() ?: [];
} catch (Throwable $ex) {
    $_SESSION['sg_prefill'] = $in;
}

// ---- Confirmation --------------------------------------------------------
$church     = (string) sg_cfg('app.church_name', '');
$advLink    = (bool) sg_cfg('features.advanced_link', true);
$extraPage  = (bool) sg_cfg('features.extra_page', true);
$name       = trim((string) ($_SESSION['sg_prefill']['first_name'] ?? $in['first_name']));

// Where can they go next?  simple → advanced → extra → done.
$next = null; $nextLabel = '';
if ($mode === '' && $advLink)          { $next = 'advanced.php'; $nextLabel = 'Add more details'; }
elseif ($mode === 'advanced' && $extraPage) { $next = 'extra.php'; $nextLabel = 'Add address & links'; }

if ($status === 'duplicate') {
    $successTitle = "You're all set!";
    $successBody  = "Looks like you've signed in with us before — thanks for coming back.";
} elseif ($isUpdate) {
    $successTitle = 'Details saved — thank you!';
    $successBody  = 'Your information has been updated.';
} else {
    $successTitle = (string) sg_cfg('messages.success_title', "Thanks — you're signed in!");
    $successBody  = (string) sg_cfg('messages.success_body', 'We are so glad you are here.');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Signed in · <?= e($church !== '' ? $church : 'Guest Sign-Up') ?></title>
<link rel="stylesheet" href="assets/signup.css"><?= theme_tokens_style_block() ?>
</head>
<body>
<div class="wrap">
  <div class="brand"><h1><?= e($church !== '' ? $church : 'Welcome') ?></h1></div>
  <div class="card">
    <div class="success">
      <div class="mark">&#10003;</div>
      <h2><?= e($successTitle) ?></h2>
      <p><?= e($name !== '' && !$isUpdate ? 'Welcome, ' . $name . '! ' : '') ?><?= e($successBody) ?></p>
      <div class="actions">
        <?php if ($next): ?>
          <a class="btn secondary" href="<?= e($next) ?>"><?= e($nextLabel) ?></a>
        <?php endif; ?>
        <a class="btn" href="index.php?new=1">Sign in another guest</a>
      </div>
    </div>
  </div>
  <p class="foot">You can close this page — your details are saved.</p>
</div>
</body>
</html>
