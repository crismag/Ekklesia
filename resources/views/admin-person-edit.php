<?php
/**
 * Admin · Person Editor — add/edit a person. Mirrors ChurchCRM PersonEditor.php
 * (last name required, birth month+day together, valid emails). Posts to
 * /admin/people/save. Data via PersonAdminService. No ChurchCRM dependency.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,mixed> $person
 * @var list<array{id:int,name:string}> $classifications
 * @var list<array{id:int,name:string}> $memberTypes
 * @var list<array{id:int,name:string}> $familyRoles
 * @var list<array{campus_id:int,campus_name:string}> $campuses
 * @var list<array{id:int,name:string}> $families
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$p = $person;
$v = static fn (string $k) => htmlspecialchars((string) ($p[$k] ?? ''), ENT_QUOTES, 'UTF-8');
$pid = (int) ($p['per_ID'] ?? 0);
$editing = $pid > 0;
$sel = static fn ($a, $b): string => (string) $a === (string) $b && (string) $a !== '' ? ' selected' : '';
$months = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$thisYear = (int) date('Y');
$noticeMap = ['saved' => ['ok', 'Person saved.'], 'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.']];

ob_start();
?>
<style>
  .pe-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .pe-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}.pe-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  .pe-sub{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--muted,#5c6b63);margin:18px 0 8px}
  .pe-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
  @media (max-width:720px){.pe-grid{grid-template-columns:1fr 1fr}}
  @media (max-width:480px){.pe-grid{grid-template-columns:1fr}}
  .pe-field label{display:block;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63);margin-bottom:4px}
  .pe-field input,.pe-field select{width:100%;font:inherit;padding:9px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff;color:var(--ink,#1b2a24)}
  .pe-field.full{grid-column:1 / -1}.pe-field.two{grid-column:span 2}
  .pe-req{color:#b3261e}
  .pe-btn{font:inherit;font-size:13px;font-weight:800;border:0;border-radius:8px;padding:10px 18px;cursor:pointer;background:#0c5a45;color:#fff;text-decoration:none;display:inline-block}
  .pe-btn.sec{background:#fff;color:var(--ink,#1b2a24);border:1px solid var(--line,#c7d4cd)}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="pe-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <article class="admin-card"><div class="admin-card-body" style="color:var(--muted)">Only a portal-wide admin can add or edit people.</div></article>
<?php else: ?>
<article class="admin-card">
  <div class="admin-card-head"><div><h2><?= $editing ? 'Edit person' : 'Add person' ?></h2>
    <p><?= $editing ? $h(trim(($p['per_FirstName'] ?? '') . ' ' . ($p['per_LastName'] ?? ''))) . ' · #' . $pid : 'Only last name is required.' ?></p></div></div>
  <div class="admin-card-body">
    <form method="post" action="<?= $base ?>/admin/people/save">
      <input type="hidden" name="per_ID" value="<?= $pid ?>">

      <div class="pe-sub">Name &amp; identity</div>
      <div class="pe-grid">
        <div class="pe-field"><label>Title</label><input type="text" name="per_Title" maxlength="50" value="<?= $v('per_Title') ?>" placeholder="Mr., Mrs., Dr."></div>
        <div class="pe-field"><label>First name</label><input type="text" name="per_FirstName" maxlength="50" value="<?= $v('per_FirstName') ?>"></div>
        <div class="pe-field"><label>Middle</label><input type="text" name="per_MiddleName" maxlength="50" value="<?= $v('per_MiddleName') ?>"></div>
        <div class="pe-field"><label>Last name <span class="pe-req">*</span></label><input type="text" name="per_LastName" maxlength="50" required value="<?= $v('per_LastName') ?>"></div>
        <div class="pe-field"><label>Suffix</label><input type="text" name="per_Suffix" maxlength="50" value="<?= $v('per_Suffix') ?>" placeholder="Jr., Sr."></div>
        <div class="pe-field"><label>Gender</label><select name="per_Gender">
          <option value="0">—</option>
          <option value="1"<?= (int) ($p['per_Gender'] ?? 0) === 1 ? ' selected' : '' ?>>Male</option>
          <option value="2"<?= (int) ($p['per_Gender'] ?? 0) === 2 ? ' selected' : '' ?>>Female</option>
        </select></div>
      </div>

      <div class="pe-sub">Birth date <span style="text-transform:none;font-weight:400">(month &amp; day together, or leave blank)</span></div>
      <div class="pe-grid">
        <div class="pe-field"><label>Month</label><select name="per_BirthMonth"><option value="0">—</option>
          <?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"<?= (int) ($p['per_BirthMonth'] ?? 0) === $i ? ' selected' : '' ?>><?= $months[$i] ?></option><?php endfor; ?>
        </select></div>
        <div class="pe-field"><label>Day</label><input type="number" name="per_BirthDay" min="1" max="31" value="<?= (int) ($p['per_BirthDay'] ?? 0) ?: '' ?>"></div>
        <div class="pe-field"><label>Year</label><input type="number" name="per_BirthYear" min="1900" max="<?= $thisYear ?>" value="<?= $p['per_BirthYear'] !== null && (int) $p['per_BirthYear'] > 0 ? (int) $p['per_BirthYear'] : '' ?>"></div>
      </div>

      <div class="pe-sub">Membership</div>
      <div class="pe-grid">
        <div class="pe-field"><label>Classification</label><select name="per_cls_ID"><option value="0">—</option>
          <?php foreach ($classifications as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) ($p['per_cls_ID'] ?? 0) === (int) $c['id'] ? ' selected' : '' ?>><?= $h($c['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="pe-field"><label>Member type</label><select name="member_type_id"><option value="">—</option>
          <?php foreach ($memberTypes as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (int) ($p['member_type_id'] ?? 0) === (int) $m['id'] ? ' selected' : '' ?>><?= $h($m['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="pe-field"><label>Membership date</label><input type="date" name="per_MembershipDate" value="<?= $p['per_MembershipDate'] ? $h(substr((string) $p['per_MembershipDate'], 0, 10)) : '' ?>"></div>
      </div>

      <div class="pe-sub">Family &amp; campus</div>
      <div class="pe-grid">
        <div class="pe-field"><label>Family</label><select name="per_fam_ID"><option value="0">— none —</option>
          <?php foreach ($families as $f): ?><option value="<?= (int) $f['id'] ?>"<?= (int) ($p['per_fam_ID'] ?? 0) === (int) $f['id'] ? ' selected' : '' ?>><?= $h($f['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="pe-field"><label>Family role</label><select name="per_fmr_ID"><option value="0">—</option>
          <?php foreach ($familyRoles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) ($p['per_fmr_ID'] ?? 0) === (int) $r['id'] ? ' selected' : '' ?>><?= $h($r['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="pe-field"><label>Primary campus</label><select name="primary_campus_id"><option value="0">— none —</option>
          <?php foreach ($campuses as $c): ?><option value="<?= (int) $c['campus_id'] ?>"<?= (int) ($p['primary_campus_id'] ?? 0) === (int) $c['campus_id'] ? ' selected' : '' ?>><?= $h($c['campus_name']) ?></option><?php endforeach; ?>
        </select></div>
      </div>

      <div class="pe-sub">Contact</div>
      <div class="pe-grid">
        <div class="pe-field"><label>Email</label><input type="email" name="per_Email" maxlength="50" value="<?= $v('per_Email') ?>"></div>
        <div class="pe-field"><label>Work email</label><input type="email" name="per_WorkEmail" maxlength="50" value="<?= $v('per_WorkEmail') ?>"></div>
        <div class="pe-field"><label>Cell phone</label><input type="tel" name="per_CellPhone" maxlength="30" value="<?= $v('per_CellPhone') ?>"></div>
        <div class="pe-field"><label>Home phone</label><input type="tel" name="per_HomePhone" maxlength="30" value="<?= $v('per_HomePhone') ?>"></div>
        <div class="pe-field"><label>Work phone</label><input type="tel" name="per_WorkPhone" maxlength="30" value="<?= $v('per_WorkPhone') ?>"></div>
      </div>

      <div class="pe-sub">Location</div>
      <div class="pe-grid">
        <div class="pe-field two"><label>Address line 1</label><input type="text" name="per_Address1" maxlength="50" value="<?= $v('per_Address1') ?>"></div>
        <div class="pe-field"><label>Address line 2</label><input type="text" name="per_Address2" maxlength="50" value="<?= $v('per_Address2') ?>"></div>
        <div class="pe-field"><label>City</label><input type="text" name="per_City" maxlength="50" value="<?= $v('per_City') ?>"></div>
        <div class="pe-field"><label>Province / State</label><input type="text" name="per_State" maxlength="50" value="<?= $v('per_State') ?>"></div>
        <div class="pe-field"><label>Postal code</label><input type="text" name="per_Zip" maxlength="50" value="<?= $v('per_Zip') ?>"></div>
        <div class="pe-field"><label>Country</label><input type="text" name="per_Country" maxlength="50" value="<?= $v('per_Country') ?>"></div>
      </div>

      <div class="pe-sub">Social</div>
      <div class="pe-grid">
        <div class="pe-field"><label>Facebook</label><input type="text" name="per_Facebook" maxlength="50" value="<?= $v('per_Facebook') ?>"></div>
        <div class="pe-field"><label>LinkedIn</label><input type="text" name="per_LinkedIn" maxlength="50" value="<?= $v('per_LinkedIn') ?>"></div>
        <div class="pe-field"><label>X (Twitter)</label><input type="text" name="per_Twitter" maxlength="50" value="<?= $v('per_Twitter') ?>"></div>
      </div>

      <div style="margin-top:18px;display:flex;gap:10px">
        <button class="pe-btn" type="submit"><?= $editing ? 'Save changes' : 'Create person' ?></button>
        <a class="pe-btn sec" href="<?= $base ?>/admin/people">Back to list</a>
      </div>
    </form>
  </div>
</article>
<?php endif; ?>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'people',
    'pageTitle' => ($editing ? 'Edit person' : 'Add person') . ' · Admin', 'pageSubtitle' => 'Member records.',
    'sectionTitle' => $editing ? 'Edit person' : 'Add person',
    'sectionDescription' => 'Identity, contact, family, campus, and membership details.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
