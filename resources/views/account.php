<?php
/**
 * Account — the signed-in person's own profile.
 *
 * Two things live on one page because they are one idea to the person reading
 * it: the church's record of who they are (photo, contact, household,
 * ministries) and the login that reaches it (display name, password, sessions).
 * The church record is edited through /account/profile, which derives the
 * person from the session and refuses to take an id from the request.
 *
 * @var string $basePath
 * @var ?array<string,mixed> $actor
 * @var array<int,array<string,mixed>> $availableMinistries
 * @var array<string,mixed> $campusSelector
 * @var int $accountPersonId
 * @var ?array<string,mixed> $profile
 * @var list<array<string,mixed>> $profileMinistries
 * @var list<array<string,mixed>> $profileCampuses
 */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$ministriesJson = htmlspecialchars(json_encode($availableMinistries, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
$campusesJson = htmlspecialchars(json_encode($campusSelector['campuses'] ?? [], JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/_portal-shell.php';

$accountPersonId = (int) ($accountPersonId ?? 0);
$profile = is_array($profile ?? null) ? $profile : null;
$profileMinistries = is_array($profileMinistries ?? null) ? $profileMinistries : [];
$profileCampuses = is_array($profileCampuses ?? null) ? $profileCampuses : [];
$person = is_array($profile['person'] ?? null) ? $profile['person'] : [];
$family = is_array($profile['family'] ?? null) ? $profile['family'] : null;
$household = is_array($profile['members'] ?? null) ? $profile['members'] : [];
$labels = is_array($profile['labels'] ?? null) ? $profile['labels'] : [];
$affiliations = is_array($profile['affiliations'] ?? null) ? $profile['affiliations'] : [];

$e = static fn (mixed $v): string => htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
$field = static fn (string $key): string => trim((string) ($person[$key] ?? ''));

$fullName = trim(implode(' ', array_filter([
    $field('per_Title'), $field('per_FirstName'), $field('per_MiddleName'),
    $field('per_LastName'), $field('per_Suffix'),
])));
if ($fullName === '') {
    $fullName = (string) ($actor['displayName'] ?? 'Portal user');
}
$initials = '';
foreach (preg_split('/\s+/', $fullName) ?: [] as $part) {
    if ($part !== '' && preg_match('/\p{L}/u', $part)) {
        $initials .= mb_substr($part, 0, 1);
    }
}
$initials = mb_strtoupper(mb_substr($initials, 0, 2)) ?: '?';

$primaryCampusName = '';
foreach ($affiliations as $aff) {
    if ((int) ($aff['is_primary'] ?? 0) === 1) {
        $primaryCampusName = (string) ($aff['campus_name'] ?? '');
    }
}
$primaryCampusId = (int) ($person['primary_campus_id'] ?? 0);

// A birthday is printed without the year: the year is on the edit form for the
// person who owns it, and a directory does not need to announce anybody's age.
$birthMonth = (int) ($person['per_BirthMonth'] ?? 0);
$birthDay = (int) ($person['per_BirthDay'] ?? 0);
$birthYear = (int) ($person['per_BirthYear'] ?? 0);
$birthdayLabel = ($birthMonth > 0 && $birthDay > 0)
    ? date('F j', (int) mktime(0, 0, 0, $birthMonth, $birthDay, 2000))
    : '';

$addressLine = (string) ($profile['address_line'] ?? '');
$addressIsFamily = $addressLine !== '' && $field('per_Address1') === '' && $field('per_City') === '';
$hasCoords = ($profile['lat'] ?? null) !== null && ($profile['lng'] ?? null) !== null;

$socials = array_filter([
    'Facebook' => $field('per_Facebook'),
    'Twitter' => $field('per_Twitter'),
    'LinkedIn' => $field('per_LinkedIn'),
], static fn (string $v): bool => $v !== '');

$monthNames = [1 => 'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>My profile - Church Portal</title>
    <style>
        * { box-sizing: border-box; }
        /* A display rule on a class beats the [hidden] attribute, and this
           codebase has now shipped that bug four times -- most recently here,
           where .panel-body{display:grid} kept the edit form on screen while
           the script believed it had hidden it. Settle it once for the page. */
        [hidden] { display:none !important; }
        body { margin:0; min-height:100vh; font:14px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color:var(--ink); background:linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 245px,var(--bg) 246px); }
        a { color:inherit; }
        .muted { color:var(--muted); }
        .mini { color:var(--muted); font-size:12px; }
        .panel { background:var(--paper); border:1px solid var(--line); border-radius:8px; box-shadow:0 14px 34px rgba(28,48,39,.08); overflow:hidden; }
        .panel-head { padding:14px 16px; border-bottom:1px solid var(--line); background:#fbfdfb; }
        .panel-head h2 { margin:0; font-size:16px; }
        .panel-head p { margin:4px 0 0; color:var(--muted); font-size:12px; }
        .panel-body { padding:16px; display:grid; gap:12px; }
        .stack { display:grid; gap:14px; }

        /* The identity card sits across the shell's own green band rather than
           under it. The gradient is already there on every page; using it as
           the cover is what turns a settings form into a profile, and it costs
           no new colour. */
        .profile-card { margin-top:-6px; }
        .profile-cover { height:88px; background:
            radial-gradient(120% 160% at 82% -30%, rgba(142,240,198,.20) 0, rgba(142,240,198,0) 60%),
            linear-gradient(120deg,#0e352c 0,#174a3c 58%,#1d5a48 100%); }
        .profile-body { padding:0 20px 18px; display:grid; grid-template-columns:132px minmax(0,1fr); gap:0 20px; align-items:end; }
        .avatar-wrap { margin-top:-56px; display:grid; gap:8px; justify-items:center; }
        .avatar { width:132px; aspect-ratio:1; border-radius:14px; overflow:hidden; background:var(--soft);
                  border:4px solid var(--paper); box-shadow:0 10px 26px rgba(12,47,40,.28);
                  display:grid; place-items:center; color:var(--deep); font-size:40px; font-weight:900; }
        .avatar img { width:100%; height:100%; object-fit:cover; display:block; }
        .avatar.is-empty img { display:none; }
        .identity { padding:14px 0 0; display:grid; gap:9px; align-content:end; }
        .identity h1 { margin:0; font-size:clamp(24px,3.2vw,32px); line-height:1.1; letter-spacing:-.015em; font-weight:800; }
        .identity-sub { margin:0; color:var(--muted); font-size:13px; }
        .chip-row { display:flex; flex-wrap:wrap; gap:6px; }
        .chip { display:inline-flex; align-items:center; min-height:26px; padding:4px 10px; border-radius:999px; background:var(--soft); color:var(--deep); font-size:12px; font-weight:800; }
        .chip.warn { background:#fef3c7; color:#92400e; }
        .chip.lead { background:#e8eef6; color:#254d74; }

        /* Facts, not a dashboard: three counts in a row that reads as one line
           of type rather than three cards competing with the name above them. */
        .facts { grid-column:1 / -1; display:flex; flex-wrap:wrap; gap:0 28px; margin-top:16px; padding-top:14px; border-top:1px solid var(--line); }
        .fact { display:grid; gap:1px; padding:4px 0; }
        .fact strong { font-size:20px; font-weight:850; line-height:1.15; }
        .fact span { font-size:11.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:var(--muted); }

        .tabs { display:flex; gap:4px; margin:18px 0 14px; flex-wrap:wrap; }
        .tab { display:inline-flex; align-items:center; min-height:40px; padding:8px 15px; border:1px solid transparent;
               border-radius:999px; background:transparent; color:var(--muted); font:inherit; font-weight:800; cursor:pointer; }
        .tab:hover { color:var(--ink); background:rgba(255,255,255,.6); }
        .tab[aria-selected="true"] { background:var(--paper); border-color:var(--line); color:var(--deep); box-shadow:0 6px 16px rgba(28,48,39,.08); }
        .tab-panel[hidden] { display:none; }

        .two-col { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(280px,.85fr); gap:14px; align-items:start; }
        label { display:block; font-size:11px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; color:var(--muted); margin-bottom:4px; }
        input, select { width:100%; border:1px solid var(--line); border-radius:8px; padding:10px 11px; font:inherit; color:var(--ink); background:#fff; min-height:42px; }
        input:focus-visible, select:focus-visible, .tab:focus-visible, button:focus-visible, a:focus-visible { outline:2px solid var(--teal,#117b6d); outline-offset:2px; }
        .field-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .field-row.thirds { grid-template-columns:repeat(3,1fr); }
        button, .button { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:42px; padding:9px 15px; border-radius:8px; border:0; background:var(--deep); color:#fff; font:inherit; font-weight:850; cursor:pointer; text-decoration:none; }
        button.secondary, .button.secondary { background:#fff; color:var(--deep); border:1px solid var(--line); }
        button.danger { background:#fff; color:#a8352b; border:1px solid #e4c4c0; }
        button[disabled] { opacity:.55; cursor:not-allowed; }
        .btn-row { display:flex; flex-wrap:wrap; gap:8px; }
        .status { min-height:20px; font-size:12px; font-weight:800; color:var(--muted); }
        .status.ok { color:var(--teal); }
        .status.err { color:var(--red,#a8352b); }

        .detail-list { display:grid; }
        .detail-row { display:grid; grid-template-columns:150px minmax(0,1fr); gap:12px; padding:10px 0; border-bottom:1px solid #edf2ef; }
        .detail-row:last-child { border-bottom:0; }
        .detail-row dt { font-size:11px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; color:var(--muted); margin:0; padding-top:2px; }
        .detail-row dd { margin:0; overflow-wrap:anywhere; }
        .detail-row dd a { display:inline-flex; align-items:center; min-height:24px; color:var(--teal); font-weight:800; text-decoration:none; }
        .detail-row dd a:hover { text-decoration:underline; }

        .person-row { display:flex; align-items:center; gap:11px; padding:11px 0; border-bottom:1px solid #edf2ef; text-decoration:none; }
        .person-row:last-child { border-bottom:0; }
        .person-av { width:38px; height:38px; flex:0 0 auto; border-radius:9px; background:var(--soft); color:var(--deep); display:grid; place-items:center; font-weight:900; font-size:13px; overflow:hidden; }
        .person-av img { width:100%; height:100%; object-fit:cover; }
        .person-name { font-weight:850; }
        .person-row:hover .person-name { color:var(--teal); }

        .ministry-list { display:grid; gap:0; }
        .ministry-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:13px 0; border-bottom:1px solid #edf2ef; text-decoration:none; }
        .ministry-row:last-child { border-bottom:0; }
        .ministry-row strong { font-size:14.5px; }
        .ministry-row:hover strong { color:var(--teal); }
        .empty-note { padding:14px 0; color:var(--muted); }

        .access-card { border:1px solid var(--line); border-radius:8px; padding:11px; display:grid; gap:8px; background:#fff; }
        .access-top { display:flex; justify-content:space-between; gap:10px; align-items:start; }
        .access-top strong { font-size:15px; }
        .role-line { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
        .list { display:grid; gap:8px; }
        .hidden { display:none !important; }
        .note { padding:10px 12px; border-radius:8px; background:var(--soft); color:var(--deep); font-size:12.5px; line-height:1.45; }

        /* The file input keeps its own focus and keyboard behaviour -- it is
           moved out of sight, not replaced by a div pretending to be one. */
        .file-field { position:relative; display:grid; gap:6px; }
        .file-input { position:absolute; width:1px; height:1px; opacity:0; }
        .file-button { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:44px;
                       padding:10px 15px; border:1px dashed var(--line); border-radius:8px; background:#fff;
                       color:var(--deep); font-weight:850; cursor:pointer; text-transform:none; letter-spacing:0; font-size:13px; }
        .file-button:hover { background:var(--soft); border-style:solid; }
        .file-input:focus-visible + .file-button { outline:2px solid var(--teal,#117b6d); outline-offset:2px; }
        .file-name { font-size:12px; color:var(--muted); overflow-wrap:anywhere; }

        @media (max-width:860px) {
            .two-col, .field-row, .field-row.thirds, .detail-row { grid-template-columns:1fr; }
            .profile-body { grid-template-columns:1fr; gap:0; padding:0 16px 16px; }
            .avatar-wrap { justify-items:start; }
            .identity { padding-top:12px; }
            .detail-row dt { padding-top:0; }
            .tab, button, .button, input, select { min-height:44px; }
            .detail-row dd a, .person-row, .ministry-row { min-height:44px; }
        }
        @media (prefers-reduced-motion:reduce) { * { transition:none !important; } }
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('wide') ?> data-base="<?= $base ?>" data-ministries="<?= $ministriesJson ?>" data-campuses="<?= $campusesJson ?>" data-person-id="<?= $accountPersonId ?>">
    <?= portal_header(
        $basePath,
        'My profile',
        'Your details, household, ministries and sign-in.',
        [],
        null,
        $actor,
        [
            ['label' => 'Home', 'href' => $base . '/', 'icon' => 'dashboard'],
            ['label' => 'People', 'items' => [
                ['label' => 'Directory', 'href' => $base . '/people', 'icon' => 'people'],
                ['label' => 'My schedule', 'href' => $base . '/my-schedule', 'icon' => 'calendar'],
            ]],
            ['label' => 'Ministries', 'items' => [
                ['label' => 'Schedule board', 'href' => $base . '/ministries', 'icon' => 'ministry'],
                ['label' => 'Calendar', 'href' => $base . '/calendar', 'icon' => 'calendar'],
            ]],
        ],
        [],
        [],
        'Sign in',
        $base . '/login'
    ) ?>
<main id="portal-main" tabindex="-1">

    <?php if ($actor === null): ?>
        <section class="panel" style="margin-top:20px">
            <div class="panel-body">
                <p class="muted">Sign in to see your profile.</p>
                <div><a class="button" href="<?= $base ?>/login?next=<?= rawurlencode($base . '/account') ?>">Sign in</a></div>
            </div>
        </section>
    <?php else: ?>

    <section class="panel profile-card" aria-label="Your identity">
        <div class="profile-cover"></div>
        <div class="profile-body">
            <div class="avatar-wrap">
                <div class="avatar<?= $accountPersonId > 0 ? '' : ' is-empty' ?>" id="avatar">
                    <?php if ($accountPersonId > 0): ?>
                    <img id="avatarImg" src="<?= $base ?>/people/photo?id=<?= $accountPersonId ?>" alt="Your profile photo">
                    <?php endif; ?>
                    <span id="avatarInitials"<?= $accountPersonId > 0 ? ' hidden' : '' ?>><?= $e($initials) ?></span>
                </div>
            </div>
            <div class="identity">
                <h1><?= $e($fullName) ?></h1>
                <p class="identity-sub"><?= $e((string) ($actor['email'] ?? '')) ?></p>
                <div class="chip-row">
                    <?php if ($primaryCampusName !== ''): ?><span class="chip"><?= $e($primaryCampusName) ?></span><?php endif; ?>
                    <?php if (($labels['member_type'] ?? '') !== ''): ?><span class="chip lead"><?= $e($labels['member_type']) ?></span><?php endif; ?>
                    <?php if (($labels['classification'] ?? '') !== ''): ?><span class="chip"><?= $e($labels['classification']) ?></span><?php endif; ?>
                    <?php if ($birthdayLabel !== ''): ?><span class="chip">Birthday <?= $e($birthdayLabel) ?></span><?php endif; ?>
                    <?php if ($accountPersonId === 0): ?><span class="chip warn">No linked person record</span><?php endif; ?>
                </div>
            </div>
            <div class="facts">
                <div class="fact"><strong><?= count($profileMinistries) ?></strong><span>Ministries</span></div>
                <div class="fact"><strong><?= count($household) + ($family !== null ? 1 : 0) ?></strong><span>Household</span></div>
                <div class="fact"><strong><?= count($affiliations) ?></strong><span>Campus<?= count($affiliations) === 1 ? '' : 'es' ?></span></div>
                <?php if ($accountPersonId > 0): ?>
                <div class="fact" style="margin-left:auto;align-self:center">
                    <a class="button secondary" href="<?= $base ?>/people/<?= $accountPersonId ?>">View public profile</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="tabs" role="tablist" aria-label="Profile sections">
        <button class="tab" role="tab" id="tab-profile" aria-controls="panel-profile" aria-selected="true">Profile</button>
        <button class="tab" role="tab" id="tab-household" aria-controls="panel-household" aria-selected="false" tabindex="-1">Household</button>
        <button class="tab" role="tab" id="tab-ministries" aria-controls="panel-ministries" aria-selected="false" tabindex="-1">Ministries</button>
        <button class="tab" role="tab" id="tab-account" aria-controls="panel-account" aria-selected="false" tabindex="-1">Sign-in</button>
        <button class="tab hidden" role="tab" id="tab-access" aria-controls="panel-access" aria-selected="false" tabindex="-1">Portal access</button>
    </div>

    <!-- ---------------------------------------------------------------- Profile -->
    <section class="tab-panel" role="tabpanel" id="panel-profile" aria-labelledby="tab-profile" tabindex="0">
        <div class="two-col">
            <div class="stack">
                <section class="panel">
                    <div class="panel-head">
                        <h2>Your details</h2>
                        <p>How the church reaches you, and how you appear in the directory.</p>
                    </div>
                    <?php if ($accountPersonId === 0): ?>
                    <div class="panel-body">
                        <p class="note">This login is not linked to a person in the church directory yet, so there is nothing to edit here. An administrator can link it from Portal access.</p>
                    </div>
                    <?php else: ?>
                    <div class="panel-body" id="detailsRead">
                        <dl class="detail-list">
                            <div class="detail-row"><dt>Name</dt><dd><?= $e($fullName) ?></dd></div>
                            <div class="detail-row"><dt>Email</dt><dd><?= $field('per_Email') !== '' ? '<a href="mailto:' . $e($field('per_Email')) . '">' . $e($field('per_Email')) . '</a>' : '<span class="muted">Not listed</span>' ?></dd></div>
                            <div class="detail-row"><dt>Mobile</dt><dd><?= $field('per_CellPhone') !== '' ? '<a href="tel:' . $e($field('per_CellPhone')) . '">' . $e($field('per_CellPhone')) . '</a>' : '<span class="muted">Not listed</span>' ?></dd></div>
                            <div class="detail-row"><dt>Home phone</dt><dd><?= $field('per_HomePhone') !== '' ? '<a href="tel:' . $e($field('per_HomePhone')) . '">' . $e($field('per_HomePhone')) . '</a>' : '<span class="muted">Not listed</span>' ?></dd></div>
                            <div class="detail-row"><dt>Address</dt><dd><?= $addressLine !== '' ? $e($addressLine) . ($addressIsFamily ? ' <span class="mini">(from your household)</span>' : '') : '<span class="muted">Not listed</span>' ?></dd></div>
                            <div class="detail-row"><dt>Birthday</dt><dd><?= $birthdayLabel !== '' ? $e($birthdayLabel) . ($birthYear > 0 ? ' <span class="mini">' . $birthYear . '</span>' : '') : '<span class="muted">Not listed</span>' ?></dd></div>
                            <div class="detail-row"><dt>Links</dt><dd><?php
                                if ($socials === []) { echo '<span class="muted">None added</span>'; }
                                else { foreach ($socials as $nameLabel => $url) {
                                    echo '<div><a href="' . $e($url) . '" rel="noopener noreferrer" target="_blank">' . $e($nameLabel) . '</a></div>';
                                } }
                            ?></dd></div>
                        </dl>
                        <div class="btn-row"><button type="button" id="editProfileOpen">Edit your details</button></div>
                    </div>

                    <form class="panel-body" id="detailsForm" hidden>
                        <div class="field-row thirds">
                            <div><label for="per_FirstName">First name</label><input id="per_FirstName" name="per_FirstName" maxlength="50" value="<?= $e($field('per_FirstName')) ?>"></div>
                            <div><label for="per_MiddleName">Middle name</label><input id="per_MiddleName" name="per_MiddleName" maxlength="50" value="<?= $e($field('per_MiddleName')) ?>"></div>
                            <div><label for="per_LastName">Last name</label><input id="per_LastName" name="per_LastName" maxlength="50" required value="<?= $e($field('per_LastName')) ?>"></div>
                        </div>
                        <div class="field-row">
                            <div><label for="per_Email">Email</label><input id="per_Email" name="per_Email" type="email" autocomplete="email" value="<?= $e($field('per_Email')) ?>"></div>
                            <div><label for="per_CellPhone">Mobile</label><input id="per_CellPhone" name="per_CellPhone" type="tel" autocomplete="tel" value="<?= $e($field('per_CellPhone')) ?>"></div>
                        </div>
                        <div class="field-row">
                            <div><label for="per_HomePhone">Home phone</label><input id="per_HomePhone" name="per_HomePhone" type="tel" value="<?= $e($field('per_HomePhone')) ?>"></div>
                            <div><label for="per_WorkPhone">Work phone</label><input id="per_WorkPhone" name="per_WorkPhone" type="tel" value="<?= $e($field('per_WorkPhone')) ?>"></div>
                        </div>
                        <div class="field-row thirds">
                            <div><label for="per_BirthMonth">Birth month</label>
                                <select id="per_BirthMonth" name="per_BirthMonth">
                                    <option value="0">—</option>
                                    <?php foreach ($monthNames as $num => $mn): ?>
                                    <option value="<?= $num ?>"<?= $birthMonth === $num ? ' selected' : '' ?>><?= $mn ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                            <div><label for="per_BirthDay">Birth day</label><input id="per_BirthDay" name="per_BirthDay" type="number" min="0" max="31" value="<?= $birthDay > 0 ? $birthDay : '' ?>"></div>
                            <div><label for="per_BirthYear">Birth year</label><input id="per_BirthYear" name="per_BirthYear" type="number" min="1900" max="<?= date('Y') ?>" value="<?= $birthYear > 0 ? $birthYear : '' ?>"></div>
                        </div>
                        <div class="field-row">
                            <div><label for="per_Address1">Address</label><input id="per_Address1" name="per_Address1" maxlength="120" value="<?= $e($field('per_Address1')) ?>"></div>
                            <div><label for="per_Address2">Apartment, unit</label><input id="per_Address2" name="per_Address2" maxlength="120" value="<?= $e($field('per_Address2')) ?>"></div>
                        </div>
                        <div class="field-row thirds">
                            <div><label for="per_City">City</label><input id="per_City" name="per_City" maxlength="60" value="<?= $e($field('per_City')) ?>"></div>
                            <div><label for="per_State">Province or state</label><input id="per_State" name="per_State" maxlength="40" value="<?= $e($field('per_State')) ?>"></div>
                            <div><label for="per_Zip">Postal code</label><input id="per_Zip" name="per_Zip" maxlength="20" value="<?= $e($field('per_Zip')) ?>"></div>
                        </div>
                        <div class="field-row thirds">
                            <div><label for="per_Facebook">Facebook</label><input id="per_Facebook" name="per_Facebook" maxlength="180" value="<?= $e($field('per_Facebook')) ?>"></div>
                            <div><label for="per_Twitter">X or Twitter</label><input id="per_Twitter" name="per_Twitter" maxlength="180" value="<?= $e($field('per_Twitter')) ?>"></div>
                            <div><label for="per_LinkedIn">LinkedIn</label><input id="per_LinkedIn" name="per_LinkedIn" maxlength="180" value="<?= $e($field('per_LinkedIn')) ?>"></div>
                        </div>
                        <div class="btn-row">
                            <button type="submit" id="detailsSave">Save your details</button>
                            <button type="button" class="secondary" id="editProfileCancel">Cancel</button>
                        </div>
                        <div id="detailsStatus" class="status" role="status"></div>
                    </form>
                    <?php endif; ?>
                </section>
            </div>

            <div class="stack">
                <?php if ($accountPersonId > 0): ?>
                <section class="panel">
                    <div class="panel-head"><h2>Photo</h2><p>Shown beside your name across the portal.</p></div>
                    <div class="panel-body">
                        <div class="file-field">
                            <input class="file-input" type="file" id="photoFile" accept="image/png,image/jpeg,image/webp">
                            <label class="file-button" for="photoFile">Choose an image</label>
                            <span class="file-name" id="photoName">PNG, JPEG or WebP, up to 6 MB.</span>
                        </div>
                        <div class="btn-row">
                            <button type="button" id="photoUpload">Save photo</button>
                            <button type="button" class="danger" id="photoRemove">Remove</button>
                        </div>
                        <div id="photoStatus" class="status" role="status"></div>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-head"><h2>Your campus</h2><p>The campus your schedule and directory default to.</p></div>
                    <div class="panel-body">
                        <div><label for="campusChoice">Primary campus</label>
                            <select id="campusChoice">
                                <option value="0">Not set</option>
                                <?php foreach ($profileCampuses as $c): ?>
                                <option value="<?= (int) $c['campus_id'] ?>"<?= $primaryCampusId === (int) $c['campus_id'] ? ' selected' : '' ?>><?= $e($c['campus_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="btn-row"><button type="button" id="campusSave">Save campus</button></div>
                        <div id="campusStatus" class="status" role="status"></div>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- -------------------------------------------------------------- Household -->
    <section class="tab-panel" role="tabpanel" id="panel-household" aria-labelledby="tab-household" tabindex="0" hidden>
        <div class="two-col">
            <section class="panel">
                <div class="panel-head">
                    <h2><?= $family !== null && trim((string) ($family['fam_Name'] ?? '')) !== '' ? $e($family['fam_Name']) . ' household' : 'Your household' ?></h2>
                    <p>The people the church has recorded at your address.</p>
                </div>
                <div class="panel-body">
                    <?php if ($family === null): ?>
                        <p class="empty-note">You are not recorded in a household yet. An administrator can add you to one.</p>
                    <?php elseif ($household === []): ?>
                        <p class="empty-note">You are the only person recorded in this household.</p>
                    <?php else: ?>
                        <div>
                            <?php foreach ($household as $m): ?>
                            <a class="person-row" href="<?= $base ?>/people/<?= (int) $m['id'] ?>">
                                <span class="person-av"><img src="<?= $base ?>/people/photo?id=<?= (int) $m['id'] ?>" alt="" onerror="this.remove()"><?= $e(mb_strtoupper(mb_substr((string) $m['first_name'], 0, 1) . mb_substr((string) $m['last_name'], 0, 1))) ?></span>
                                <span>
                                    <span class="person-name"><?= $e(trim((string) $m['first_name'] . ' ' . (string) $m['last_name'])) ?></span>
                                    <?php if (trim((string) ($m['family_role'] ?? '')) !== ''): ?>
                                    <span class="mini"> · <?= $e($m['family_role']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <p class="note">Who belongs to a household is a church record, so it is changed by an administrator rather than here — a household carries a shared address and contact details with it. Ask the office if someone is missing or has moved out.</p>
                </div>
            </section>

            <div class="stack">
                <section class="panel">
                    <div class="panel-head"><h2>Household address</h2><p>Used for the church's member map.</p></div>
                    <div class="panel-body">
                        <p style="margin:0"><?= $addressLine !== '' ? $e($addressLine) : '<span class="muted">No address on file.</span>' ?></p>
                        <p class="mini" style="margin:0"><?= $hasCoords ? 'Map location is set.' : 'No map location yet.' ?></p>
                        <?php if ($accountPersonId > 0 && $family !== null): ?>
                        <div class="btn-row"><button type="button" class="secondary" id="geoRefresh">Refresh map location</button></div>
                        <div id="geoStatus" class="status" role="status"></div>
                        <?php endif; ?>
                        <?php if ($addressIsFamily): ?>
                        <p class="mini" style="margin:0">This comes from your household. Setting your own address on the Profile tab replaces it for you.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if (($labels['family_role'] ?? '') !== ''): ?>
                <section class="panel">
                    <div class="panel-head"><h2>Your role at home</h2></div>
                    <div class="panel-body"><div class="chip-row"><span class="chip"><?= $e($labels['family_role']) ?></span></div></div>
                </section>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ------------------------------------------------------------- Ministries -->
    <section class="tab-panel" role="tabpanel" id="panel-ministries" aria-labelledby="tab-ministries" tabindex="0" hidden>
        <div class="two-col">
            <section class="panel">
                <div class="panel-head">
                    <h2>Ministries you serve in</h2>
                    <p>Open a ministry to see its team, schedule and roster.</p>
                </div>
                <div class="panel-body">
                    <?php if ($profileMinistries === []): ?>
                        <p class="empty-note">You are not on a ministry team yet. Ministry leaders add people to their teams.</p>
                        <div class="btn-row"><a class="button secondary" href="<?= $base ?>/ministries">Browse ministries</a></div>
                    <?php else: ?>
                        <div class="ministry-list">
                            <?php foreach ($profileMinistries as $m): ?>
                            <a class="ministry-row" href="<?= $base ?>/ministries/<?= (int) $m['ministry_id'] ?>">
                                <span>
                                    <strong><?= $e($m['name']) ?></strong>
                                    <?php if (trim((string) $m['roles']) !== ''): ?>
                                    <div class="mini"><?= $e($m['roles']) ?></div>
                                    <?php endif; ?>
                                </span>
                                <?php if (!empty($m['is_leader'])): ?><span class="chip lead">Leader</span><?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="stack">
                <section class="panel">
                    <div class="panel-head"><h2>Serving</h2><p>Your own schedule and availability.</p></div>
                    <div class="panel-body">
                        <div class="btn-row">
                            <a class="button secondary" href="<?= $base ?>/my-schedule">My schedule</a>
                            <a class="button secondary" href="<?= $base ?>/availability">Availability</a>
                        </div>
                        <p class="mini" style="margin:0">Availability tells schedulers when not to place you. It does not remove you from a team.</p>
                    </div>
                </section>
            </div>
        </div>
    </section>

    <!-- ---------------------------------------------------------------- Sign-in -->
    <section class="tab-panel" role="tabpanel" id="panel-account" aria-labelledby="tab-account" tabindex="0" hidden>
        <div class="two-col">
            <div class="stack">
                <section class="panel">
                    <div class="panel-head"><h2>Display name</h2><p>The name the portal greets you by. It does not change the directory.</p></div>
                    <form id="profileForm" class="panel-body">
                        <div><label for="displayName">Display name</label><input id="displayName" name="displayName" maxlength="100"></div>
                        <div class="btn-row"><button type="submit">Save display name</button></div>
                        <div id="profileStatus" class="status" role="status"></div>
                    </form>
                </section>

                <section class="panel">
                    <div class="panel-head"><h2>Password</h2><p>Changing it signs out your other devices.</p></div>
                    <form id="passwordForm" class="panel-body">
                        <div class="field-row">
                            <div><label for="currentPassword">Current password</label><input id="currentPassword" name="currentPassword" type="password" autocomplete="current-password"></div>
                            <div><label for="newPassword">New password</label><input id="newPassword" name="newPassword" type="password" autocomplete="new-password" minlength="12"></div>
                        </div>
                        <p class="mini" style="margin:0">At least 12 characters.</p>
                        <div class="btn-row"><button type="submit">Update password</button></div>
                        <div id="passwordStatus" class="status" role="status"></div>
                    </form>
                </section>
            </div>

            <div class="stack">
                <section class="panel">
                    <div class="panel-head"><h2>This account</h2><p>What this login can do in the portal.</p></div>
                    <div class="panel-body">
                        <div><strong id="accountName"><?= $e((string) ($actor['displayName'] ?? 'Portal user')) ?></strong>
                            <div id="accountEmail" class="mini">Loading account…</div></div>
                        <div id="roleChips" class="chip-row"></div>
                        <div id="scopeChips" class="chip-row"></div>
                        <div class="btn-row"><button type="button" id="logoutButton" class="secondary">Sign out of this device</button></div>
                        <div id="logoutStatus" class="status" role="status"></div>
                    </div>
                </section>
            </div>
        </div>
    </section>

    <!-- --------------------------------------------------------- Portal access -->
    <section class="tab-panel" role="tabpanel" id="panel-access" aria-labelledby="tab-access" tabindex="0" hidden>
        <section class="panel" id="accessPanel">
            <div class="panel-head">
                <h2>Ministry leadership access</h2>
                <p>Assign portal access for leaders and schedulers. Leaders can assign people inside their managed ministries by default.</p>
            </div>
            <div class="panel-body">
                <form id="accessForm" class="stack">
                    <div class="field-row">
                        <div>
                            <label for="personSearch">Directory person</label>
                            <input id="personSearch" list="peopleOptions" placeholder="Search by name, ministry, or member type">
                            <datalist id="peopleOptions"></datalist>
                            <input id="personId" type="hidden">
                        </div>
                        <div><label for="email">Portal email</label><input id="email" name="email" type="email" autocomplete="off"></div>
                    </div>
                    <div class="field-row">
                        <div><label for="accessRole">Portal role</label>
                            <select id="accessRole">
                                <option value="leader">Leader</option>
                                <option value="scheduler">Scheduler</option>
                                <option value="member">Member</option>
                            </select></div>
                        <div><label for="scopeMinistry">Managed ministry</label><select id="scopeMinistry"></select></div>
                    </div>
                    <div class="field-row">
                        <div><label for="scopeCampus">Campus scope</label><select id="scopeCampus"><option value="">All allowed campuses</option></select></div>
                        <div><label for="temporaryPassword">Temporary password for new account</label><input id="temporaryPassword" type="password" minlength="12" placeholder="Leave blank for generated password"></div>
                    </div>
                    <div class="btn-row"><button type="submit">Save access</button></div>
                    <div id="accessStatus" class="status" role="status"></div>
                </form>
                <div>
                    <h3 style="margin:14px 0 8px;font-size:15px">Current portal access</h3>
                    <div id="accessList" class="list"><div class="mini">Loading access list…</div></div>
                </div>
            </div>
        </section>
    </section>

    <?php endif; ?>
</main>
<?= portal_footer('Church Portal', 'Your profile and sign-in') ?>
</div>

<?php if ($actor !== null): ?>
<script>
(function () {
    const shell = document.querySelector('.shell');
    const basePath = shell.dataset.base || '';
    const personId = Number(shell.dataset.personId || 0);
    const ministries = JSON.parse(shell.dataset.ministries || '[]');
    const campuses = JSON.parse(shell.dataset.campuses || '[]');
    let account = null;
    let people = [];

    const $ = (id) => document.getElementById(id);
    const escapeHtml = (v) => String(v ?? '').replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));

    function setStatus(id, message, kind) {
        const node = $(id);
        if (!node) return;
        node.textContent = message || '';
        node.className = 'status' + (kind ? ' ' + kind : '');
    }

    async function requestJson(url, options = {}) {
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', ...(options.body ? { 'Content-Type': 'application/json' } : {}) },
            ...options,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || `Request failed ${res.status}`);
        return data;
    }

    function postForm(url, fd) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then((r) => r.json().catch(() => ({})).then((j) => ({ ok: r.ok, j })));
    }

    /* ---- Tabs. Panels are rendered visible and hidden here, so the page is
       whole without JavaScript rather than five empty sections. ------------- */
    const tabs = Array.from(document.querySelectorAll('[role="tab"]'));
    function selectTab(tab, focus) {
        tabs.forEach((t) => {
            const on = t === tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            const panel = $(t.getAttribute('aria-controls'));
            if (panel) panel.hidden = !on;
        });
        if (focus) tab.focus();
        try { history.replaceState(null, '', '#' + tab.id.replace('tab-', '')); } catch (_e) { /* file:// and privacy modes */ }
    }
    tabs.forEach((tab) => {
        tab.addEventListener('click', () => selectTab(tab, false));
        tab.addEventListener('keydown', (event) => {
            const visible = tabs.filter((t) => !t.classList.contains('hidden'));
            const i = visible.indexOf(tab);
            let next = null;
            if (event.key === 'ArrowRight') next = visible[(i + 1) % visible.length];
            if (event.key === 'ArrowLeft') next = visible[(i - 1 + visible.length) % visible.length];
            if (event.key === 'Home') next = visible[0];
            if (event.key === 'End') next = visible[visible.length - 1];
            if (next) { event.preventDefault(); selectTab(next, true); }
        });
    });
    tabs.forEach((t) => { const p = $(t.getAttribute('aria-controls')); if (p) p.hidden = t.getAttribute('aria-selected') !== 'true'; });
    const fromHash = $('tab-' + (location.hash || '').replace('#', ''));
    if (fromHash && !fromHash.classList.contains('hidden')) selectTab(fromHash, false);

    /* ---- Photo ----------------------------------------------------------- */
    const avatarImg = $('avatarImg');
    avatarImg?.addEventListener('error', () => {
        $('avatar')?.classList.add('is-empty');
        $('avatarInitials')?.removeAttribute('hidden');
    });

    $('photoFile')?.addEventListener('change', (event) => {
        // Show the chosen image straight away: waiting until after the upload to
        // find out it was the wrong file is a round trip for nothing.
        const file = event.target.files && event.target.files[0];
        if (!file || !avatarImg) return;
        const reader = new FileReader();
        reader.onload = () => {
            avatarImg.src = String(reader.result);
            $('avatar')?.classList.remove('is-empty');
            $('avatarInitials')?.setAttribute('hidden', '');
        };
        reader.readAsDataURL(file);
        const name = $('photoName');
        if (name) name.textContent = file.name;
        setStatus('photoStatus', 'Preview only — choose Save photo to keep it.', '');
    });

    $('photoUpload')?.addEventListener('click', () => {
        const input = $('photoFile');
        const file = input && input.files[0];
        if (!file) { setStatus('photoStatus', 'Choose an image first.', 'err'); return; }
        const button = $('photoUpload');
        const fd = new FormData();
        fd.append('photo', file);
        fd.append('id', String(personId));
        button.disabled = true;
        setStatus('photoStatus', 'Saving…', '');
        postForm(`${basePath}/people/photo`, fd).then((res) => {
            button.disabled = false;
            if (res.ok && res.j.success) {
                setStatus('photoStatus', 'Photo saved.', 'ok');
                if (avatarImg) avatarImg.src = `${basePath}/people/photo?id=${personId}&t=${Date.now()}`;
                input.value = '';
            } else {
                setStatus('photoStatus', (res.j && res.j.error) || 'That image could not be saved.', 'err');
            }
        }).catch(() => { button.disabled = false; setStatus('photoStatus', 'That image could not be saved.', 'err'); });
    });

    $('photoRemove')?.addEventListener('click', () => {
        if (!window.confirm('Remove your profile photo? Your initials will show instead.')) return;
        const fd = new FormData();
        fd.append('id', String(personId));
        setStatus('photoStatus', 'Removing…', '');
        postForm(`${basePath}/people/photo-delete`, fd).then((res) => {
            if (res.ok && res.j.success) {
                setStatus('photoStatus', 'Photo removed.', 'ok');
                $('avatar')?.classList.add('is-empty');
                $('avatarInitials')?.removeAttribute('hidden');
            } else {
                setStatus('photoStatus', 'The photo could not be removed.', 'err');
            }
        });
    });

    /* ---- Your details ---------------------------------------------------- */
    const detailsRead = $('detailsRead');
    const detailsForm = $('detailsForm');
    function showForm(on) {
        if (!detailsRead || !detailsForm) return;
        detailsRead.hidden = on;
        detailsForm.hidden = !on;
        if (on) $('per_FirstName')?.focus();
        else $('editProfileOpen')?.focus();
    }
    $('editProfileOpen')?.addEventListener('click', () => showForm(true));
    $('editProfileCancel')?.addEventListener('click', () => { detailsForm.reset(); setStatus('detailsStatus', '', ''); showForm(false); });

    detailsForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const button = $('detailsSave');
        const body = {};
        new FormData(detailsForm).forEach((value, key) => { body[key] = value; });
        button.disabled = true;
        setStatus('detailsStatus', 'Saving…', '');
        requestJson(`${basePath}/account/profile`, { method: 'POST', body: JSON.stringify(body) })
            .then(() => {
                setStatus('detailsStatus', 'Saved. Reloading your profile…', 'ok');
                window.location.reload();
            })
            .catch((error) => { button.disabled = false; setStatus('detailsStatus', error.message, 'err'); });
    });

    /* ---- Campus and map location ----------------------------------------- */
    $('campusSave')?.addEventListener('click', () => {
        const button = $('campusSave');
        const fd = new FormData();
        fd.append('id', String(personId));
        fd.append('campus_id', $('campusChoice').value);
        button.disabled = true;
        setStatus('campusStatus', 'Saving…', '');
        postForm(`${basePath}/people/campus`, fd).then((res) => {
            button.disabled = false;
            if (res.ok && res.j.success) setStatus('campusStatus', 'Campus saved.', 'ok');
            else setStatus('campusStatus', (res.j && res.j.error) || 'That campus could not be saved.', 'err');
        });
    });

    $('geoRefresh')?.addEventListener('click', () => {
        const button = $('geoRefresh');
        const fd = new FormData();
        fd.append('id', String(personId));
        button.disabled = true;
        setStatus('geoStatus', 'Looking up your address…', '');
        postForm(`${basePath}/people/geocode`, fd).then((res) => {
            button.disabled = false;
            if (res.ok && res.j.success) setStatus('geoStatus', 'Map location updated.', 'ok');
            else setStatus('geoStatus', (res.j && res.j.error) || 'That address could not be located.', 'err');
        });
    });

    /* ---- Login account, password, portal access -------------------------- */
    function ministryName(id) {
        const m = ministries.find((item) => Number(item.ministryId) === Number(id));
        return m ? m.name : `Ministry #${id}`;
    }
    function campusName(id) {
        const c = campuses.find((item) => Number(item.id) === Number(id));
        return c ? c.name : `Campus #${id}`;
    }

    function renderAccount() {
        $('accountName').textContent = account.displayName || account.email || 'Portal user';
        $('accountEmail').textContent = account.email || '';
        $('displayName').value = account.displayName || '';
        $('roleChips').innerHTML = (account.roles || []).map((r) => `<span class="chip">${escapeHtml(r.role)}</span>`).join('')
            || '<span class="chip warn">No role assigned</span>';
        const scopes = [];
        (account.roles || []).forEach((role) => {
            if (role.ministry_id) scopes.push(`Leads ${ministryName(role.ministry_id)}`);
            if (role.campus_id) scopes.push(campusName(role.campus_id));
        });
        if (account.isPortalWideAdmin) scopes.push('Portal-wide access');
        $('scopeChips').innerHTML = scopes.map((s) => `<span class="chip">${escapeHtml(s)}</span>`).join('')
            || '<span class="chip">Member scope</span>';
        $('tab-access')?.classList.toggle('hidden', !account.isPortalWideAdmin);
    }

    function renderAccessList(users) {
        const list = $('accessList');
        if (!users.length) { list.innerHTML = '<div class="mini">No portal accounts yet.</div>'; return; }
        list.innerHTML = users.map((user) => {
            const roles = (user.roles || []).map((role) => {
                const pieces = [role.role];
                if (role.ministry_id) pieces.push(ministryName(role.ministry_id));
                if (role.campus_id) pieces.push(campusName(role.campus_id));
                return `<span class="chip">${escapeHtml(pieces.join(' - '))}</span>`;
            }).join('') || '<span class="chip warn">No role</span>';
            return `<article class="access-card">
                <div class="access-top">
                    <div><strong>${escapeHtml(user.displayName || user.email)}</strong><div class="mini">${escapeHtml(user.email)}${user.personId ? ` - person #${escapeHtml(user.personId)}` : ''}</div></div>
                    ${user.canAssignPeopleToManagedMinistries ? '<span class="chip">Can assign people</span>' : ''}
                </div>
                <div class="role-line">${roles}</div>
            </article>`;
        }).join('');
    }

    async function loadAccess() { renderAccessList((await requestJson(`${basePath}/api/portal-access`)).users || []); }

    async function loadPeople() {
        const data = await requestJson(`${basePath}/api/people-directory?since=2026-01-01`);
        people = data.people || [];
        $('peopleOptions').innerHTML = people.map((person) => {
            const label = `${person.displayName || ''} | ${person.memberTypeName || ''} | ${person.ministries || ''}`.replace(/\s+\|/g, ' |');
            return `<option value="${escapeHtml(label)}" data-id="${escapeHtml(person.personId)}"></option>`;
        }).join('');
    }

    async function loadAccount() {
        account = (await requestJson(`${basePath}/api/account`)).account || {};
        renderAccount();
        if (account.isPortalWideAdmin) await Promise.all([loadAccess(), loadPeople()]);
    }

    ministries.forEach((m) => $('scopeMinistry')?.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(m.ministryId)}">${escapeHtml(m.name)}</option>`));
    campuses.forEach((c) => $('scopeCampus')?.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(c.id)}">${escapeHtml(c.name)}</option>`));

    $('accessRole')?.addEventListener('change', (event) => {
        const isMember = event.target.value === 'member';
        $('scopeMinistry').disabled = isMember;
        $('scopeCampus').disabled = isMember;
    });

    $('personSearch')?.addEventListener('input', () => {
        const search = $('personSearch');
        const selected = people.find((person) => {
            const label = `${person.displayName || ''} | ${person.memberTypeName || ''} | ${person.ministries || ''}`.replace(/\s+\|/g, ' |');
            return label === search.value;
        });
        $('personId').value = selected ? selected.personId : '';
        if (selected && !$('email').value && selected.email) $('email').value = selected.email;
    });

    $('profileForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        setStatus('profileStatus', 'Saving…', '');
        try {
            const data = await requestJson(`${basePath}/api/account/profile`, {
                method: 'POST',
                body: JSON.stringify({ displayName: $('displayName').value }),
            });
            account = data.account || account;
            renderAccount();
            setStatus('profileStatus', 'Display name saved.', 'ok');
        } catch (error) { setStatus('profileStatus', error.message, 'err'); }
    });

    $('passwordForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        setStatus('passwordStatus', 'Updating…', '');
        try {
            const result = await requestJson(`${basePath}/api/account/password`, {
                method: 'POST',
                body: JSON.stringify({
                    currentPassword: $('currentPassword').value,
                    newPassword: $('newPassword').value,
                }),
            });
            event.currentTarget.reset();
            const kicked = Number(result.otherSessionsRevoked || 0);
            const tail = kicked > 0 ? ` ${kicked} other session${kicked === 1 ? ' was' : 's were'} signed out.` : '';
            setStatus('passwordStatus', `Password updated.${tail}`, 'ok');
        } catch (error) { setStatus('passwordStatus', error.message, 'err'); }
    });

    $('logoutButton')?.addEventListener('click', async () => {
        setStatus('logoutStatus', 'Signing out…', '');
        try { await requestJson(`${basePath}/api/logout`, { method: 'POST', body: JSON.stringify({}) }); }
        catch (_error) { /* Land on login even if the revoke failed; a stale signed-in view is worse. */ }
        window.location.assign(`${basePath}/login`);
    });

    $('accessForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        setStatus('accessStatus', 'Saving access…', '');
        try {
            const selectedPerson = people.find((p) => Number(p.personId) === Number($('personId').value));
            await requestJson(`${basePath}/api/portal-access`, {
                method: 'POST',
                body: JSON.stringify({
                    email: $('email').value,
                    displayName: selectedPerson ? selectedPerson.displayName : '',
                    temporaryPassword: $('temporaryPassword').value,
                    personId: Number($('personId').value || 0),
                    role: $('accessRole').value,
                    scopeMinistryId: $('accessRole').value === 'member' ? 0 : Number($('scopeMinistry').value || 0),
                    scopeCampusId: $('accessRole').value === 'member' ? 0 : Number($('scopeCampus').value || 0),
                }),
            });
            $('temporaryPassword').value = '';
            setStatus('accessStatus', 'Access saved.', 'ok');
            await loadAccess();
        } catch (error) { setStatus('accessStatus', error.message, 'err'); }
    });

    loadAccount().catch((error) => { $('accountEmail').textContent = error.message; });
})();
</script>
<?php endif; ?>
</body>
</html>
