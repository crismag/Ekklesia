<?php
/**
 * People & Records · Edit person — add or edit a person record (last name
 * required, birth month and day together, valid email). Posts to
 * /admin/people/save; the rules live in PersonAdminService.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,mixed> $person
 * @var bool $missing
 * @var list<array{id:int,name:string}> $classifications
 * @var list<array{id:int,name:string}> $memberTypes
 * @var list<array{id:int,name:string}> $familyRoles
 * @var list<array{id:int,name:string}> $campuses
 * @var list<array{id:int,name:string}> $families
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_records-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = records_h($basePath);
$h = 'records_h';
$p = $person;
$v = static fn (string $k): string => records_h($p[$k] ?? '');
$pid = (int) ($p['id'] ?? 0);
$editing = $pid > 0;
$missing = $missing ?? false;
$months = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$thisYear = (int) date('Y');
$noticeMap = ['saved' => ['ok', 'Changes saved.'], 'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.']];
$fullName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
$cancelHref = $editing ? $base . '/admin/people/view?id=' . $pid : $base . '/admin/people';

/** @param list<array{id:int,name:string}> $options */
$select = static function (string $name, string $id, array $options, int $current, string $none) use ($h): string {
    $out = '<select class="ek-select" id="' . $id . '" name="' . $name . '"><option value="0">' . $h($none) . '</option>';
    foreach ($options as $o) {
        $out .= '<option value="' . (int) $o['id'] . '"' . ((int) $o['id'] === $current ? ' selected' : '') . '>' . $h($o['name']) . '</option>';
    }

    return $out . '</select>';
};

ob_start();
echo records_styles();
?>
<?= records_notice_for($notice, $noticeMap) ?>

<?php if (!$isAdmin): ?>
  <section class="ek-empty">
    <strong>Member records are for portal administrators</strong>
    <p>Only a portal administrator can add or edit a person. You can update your own details from Account.</p>
    <a class="ek-btn" href="<?= $base ?>/account">Open Account</a>
  </section>
<?php elseif ($missing): http_response_code(404); ?>
  <section class="ek-empty">
    <strong>This person is not on record</strong>
    <p>The record may have been deleted or merged.</p>
    <span class="rec-actions"><a class="ek-btn" href="<?= $base ?>/admin/people">Back to member records</a>
    <a class="ek-btn ek-btn-primary" href="<?= $base ?>/admin/people/edit">Add a new person</a></span>
  </section>
<?php else: ?>
<form method="post" action="<?= $base ?>/admin/people/save" class="rec-main">
  <input type="hidden" name="id" value="<?= $pid ?>">

  <section class="ek-card" aria-labelledby="pe-identity">
    <div class="ek-card-head"><div><h2 id="pe-identity">Identity</h2><p>Only the last name is required.</p></div></div>
    <div class="ek-card-body rec-fields">
      <div class="ek-field"><label for="pe-first">First name</label><input class="ek-input" id="pe-first" type="text" name="first_name" maxlength="60" value="<?= $v('first_name') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-middle">Middle name</label><input class="ek-input" id="pe-middle" type="text" name="middle_name" maxlength="60" value="<?= $v('middle_name') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-last">Last name <span class="rec-req" aria-hidden="true">*</span><span class="sr-only">(required)</span></label><input class="ek-input" id="pe-last" type="text" name="last_name" maxlength="60" required value="<?= $v('last_name') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-preferred">Preferred name</label><input class="ek-input" id="pe-preferred" type="text" name="preferred_name" maxlength="60" value="<?= $v('preferred_name') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-suffix">Suffix</label><input class="ek-input" id="pe-suffix" type="text" name="suffix" maxlength="20" value="<?= $v('suffix') ?>" placeholder="Jr., Sr."></div>
      <div class="ek-field"><label for="pe-gender">Gender</label><select class="ek-select" id="pe-gender" name="gender">
        <option value="">Not recorded</option>
        <option value="male"<?= ($p['gender'] ?? null) === 'male' ? ' selected' : '' ?>>Male</option>
        <option value="female"<?= ($p['gender'] ?? null) === 'female' ? ' selected' : '' ?>>Female</option>
      </select></div>
    </div>
    <div class="ek-card-body rec-fields" style="padding-top:0" role="group" aria-labelledby="pe-birth-label">
      <p class="is-full ek-hint" id="pe-birth-label" style="margin:0"><strong>Birthday</strong> — month and day together, or leave both blank. The year is optional.</p>
      <div class="ek-field"><label for="pe-bmonth">Month</label><select class="ek-select" id="pe-bmonth" name="birth_month"><option value="0">Not recorded</option>
        <?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"<?= (int) ($p['birth_month'] ?? 0) === $i ? ' selected' : '' ?>><?= $months[$i] ?></option><?php endfor; ?>
      </select></div>
      <div class="ek-field"><label for="pe-bday">Day</label><input class="ek-input" id="pe-bday" type="number" name="birth_day" min="1" max="31" inputmode="numeric" value="<?= (int) ($p['birth_day'] ?? 0) ?: '' ?>"></div>
      <div class="ek-field"><label for="pe-byear">Year</label><input class="ek-input" id="pe-byear" type="number" name="birth_year" min="1900" max="<?= $thisYear ?>" inputmode="numeric" value="<?= ($p['birth_year'] ?? null) !== null && (int) $p['birth_year'] > 0 ? (int) $p['birth_year'] : '' ?>"></div>
    </div>
  </section>

  <section class="ek-card" aria-labelledby="pe-contact">
    <div class="ek-card-head"><div><h2 id="pe-contact">Contact &amp; address</h2><p>Leave the address blank to use the household's address.</p></div></div>
    <div class="ek-card-body rec-fields">
      <div class="ek-field"><label for="pe-email">Email</label><input class="ek-input" id="pe-email" type="email" name="email" maxlength="120" value="<?= $v('email') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-mobile">Mobile phone</label><input class="ek-input" id="pe-mobile" type="tel" name="mobile_phone" maxlength="40" value="<?= $v('mobile_phone') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-home">Home phone</label><input class="ek-input" id="pe-home" type="tel" name="home_phone" maxlength="40" value="<?= $v('home_phone') ?>" autocomplete="off"></div>
      <div class="ek-field is-wide"><label for="pe-addr1">Address line 1</label><input class="ek-input" id="pe-addr1" type="text" name="address_line1" maxlength="150" value="<?= $v('address_line1') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-addr2">Address line 2</label><input class="ek-input" id="pe-addr2" type="text" name="address_line2" maxlength="150" value="<?= $v('address_line2') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-city">City</label><input class="ek-input" id="pe-city" type="text" name="city" maxlength="100" value="<?= $v('city') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-region">Province / state</label><input class="ek-input" id="pe-region" type="text" name="region" maxlength="50" value="<?= $v('region') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-postal">Postal code</label><input class="ek-input" id="pe-postal" type="text" name="postal_code" maxlength="20" value="<?= $v('postal_code') ?>" autocomplete="off"></div>
      <div class="ek-field"><label for="pe-country">Country</label><input class="ek-input" id="pe-country" type="text" name="country" maxlength="60" value="<?= $v('country') ?>" autocomplete="off"></div>
    </div>
  </section>

  <section class="ek-card" aria-labelledby="pe-household">
    <div class="ek-card-head"><div><h2 id="pe-household">Household</h2><p>The household groups people who live together and share an address.</p></div></div>
    <div class="ek-card-body rec-fields">
      <div class="ek-field is-wide"><label for="pe-hh">Household</label><?= $select('household_id', 'pe-hh', $families, (int) ($p['household_id'] ?? 0), 'None') ?>
        <span class="ek-hint">Not listed? <a class="rec-link" href="<?= $base ?>/admin/families/edit">Add the household</a> first.</span></div>
      <div class="ek-field"><label for="pe-role">Role in household</label><?= $select('household_role_id', 'pe-role', $familyRoles, (int) ($p['household_role_id'] ?? 0), 'Not set') ?></div>
    </div>
  </section>

  <section class="ek-card" aria-labelledby="pe-membership">
    <div class="ek-card-head"><div><h2 id="pe-membership">Membership</h2><p>The status, type and campus lists are kept in <a class="rec-link" href="<?= $base ?>/admin/options">Record settings</a> and <a class="rec-link" href="<?= $base ?>/admin/campuses">Campuses</a>.</p></div></div>
    <div class="ek-card-body rec-fields">
      <div class="ek-field"><label for="pe-status">Membership status</label><?= $select('membership_status_id', 'pe-status', $classifications, (int) ($p['membership_status_id'] ?? 0), 'Not set') ?></div>
      <div class="ek-field"><label for="pe-type">Member type</label>
        <select class="ek-select" id="pe-type" name="member_type_id"><option value="">Not set</option>
          <?php foreach ($memberTypes as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (int) ($p['member_type_id'] ?? 0) === (int) $m['id'] ? ' selected' : '' ?>><?= $h($m['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="ek-field"><label for="pe-campus">Campus</label><?= $select('campus_id', 'pe-campus', $campuses, (int) ($p['campus_id'] ?? 0), 'None') ?></div>
      <div class="ek-field"><label for="pe-since">Member since</label><input class="ek-input" id="pe-since" type="date" name="member_since" value="<?= !empty($p['member_since']) ? $h(substr((string) $p['member_since'], 0, 10)) : '' ?>"></div>
    </div>
    <div class="rec-card-foot">
      <button class="ek-btn ek-btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Add person' ?></button>
      <a class="ek-btn ek-btn-quiet" href="<?= $cancelHref ?>">Cancel</a>
    </div>
  </section>
</form>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'people',
    'pageTitle' => ($editing ? 'Edit ' . ($fullName !== '' ? $fullName : 'person') : 'Add person') . ' · Member records', 'pageSubtitle' => 'Member records.',
    'sectionTitle' => $missing ? 'Person not found' : ($editing ? 'Edit ' . ($fullName !== '' ? $fullName : 'person #' . $pid) : 'Add person'),
    'sectionDescription' => $missing ? '' : ($editing ? 'Changes are saved to the member record when you choose Save changes.' : 'Add someone to the church roll. You can fill in the rest later.'),
    'headerActions' => $isAdmin && $editing ? '<a class="ek-btn" href="' . $cancelHref . '">Back to record</a>' : '',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
