<?php
/**
 * Admin · Church Info — portal-owned identity/contact/location settings.
 * Data from ChurchInfoService (config/church-info.json).
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,string> $info
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$val = static fn (string $k) => htmlspecialchars((string) ($info[$k] ?? ''), ENT_QUOTES, 'UTF-8');

$noticeMap = [
    'saved' => ['ok', 'Church info saved.'],
    'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.'],
];

ob_start();
?>
<style>
  .ci-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .ci-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}
  .ci-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  form.ci-form .ci-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media (max-width:640px){form.ci-form .ci-grid{grid-template-columns:1fr}}
  .ci-field label{display:block;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63);margin-bottom:4px}
  .ci-field input{width:100%;font:inherit;padding:9px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff;color:var(--ink,#1b2a24)}
  .ci-field.full{grid-column:1 / -1}
  .ci-sub{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--muted,#5c6b63);margin:16px 0 8px}
  .ci-req{color:#b3261e}
  .ci-btn{font:inherit;font-size:13px;font-weight:800;border:0;border-radius:8px;padding:10px 16px;cursor:pointer;background:#0c5a45;color:#fff;margin-top:14px}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="ci-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<article class="admin-card">
  <div class="admin-card-head"><div><h2>Church information</h2><p>Name, contact details, and location. Used across the portal.</p></div></div>
  <div class="admin-card-body">
    <?php if (!$isAdmin): ?>
      <dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:6px 16px">
        <dt style="color:var(--muted)">Name</dt><dd style="margin:0"><?= $val('name') ?></dd>
        <dt style="color:var(--muted)">Website</dt><dd style="margin:0"><?= $val('website') ?></dd>
        <dt style="color:var(--muted)">Phone</dt><dd style="margin:0"><?= $val('phone') ?></dd>
        <dt style="color:var(--muted)">Email</dt><dd style="margin:0"><?= $val('email') ?></dd>
        <dt style="color:var(--muted)">Address</dt><dd style="margin:0"><?= $h(trim($info['address'] . ' ' . $info['city'] . ' ' . $info['state'] . ' ' . $info['zip'] . ' ' . $info['country'])) ?></dd>
      </dl>
      <p style="color:var(--muted);margin-top:12px">Only a portal-wide admin can edit church info.</p>
    <?php else: ?>
    <form class="ci-form" method="post" action="<?= $base ?>/admin/church-info">
      <div class="ci-sub">Identity</div>
      <div class="ci-grid">
        <div class="ci-field"><label for="ci_name">Church name <span class="ci-req">*</span></label><input id="ci_name" type="text" name="name" maxlength="150" required value="<?= $val('name') ?>"></div>
        <div class="ci-field"><label for="ci_website">Website</label><input id="ci_website" type="text" name="website" maxlength="200" value="<?= $val('website') ?>"></div>
      </div>

      <div class="ci-sub">Contact</div>
      <div class="ci-grid">
        <div class="ci-field"><label for="ci_phone">Phone</label><input id="ci_phone" type="text" name="phone" maxlength="50" value="<?= $val('phone') ?>"></div>
        <div class="ci-field"><label for="ci_email">Email</label><input id="ci_email" type="email" name="email" maxlength="120" value="<?= $val('email') ?>"></div>
      </div>

      <div class="ci-sub">Location</div>
      <div class="ci-grid">
        <div class="ci-field full"><label for="ci_address">Address</label><input id="ci_address" type="text" name="address" maxlength="150" value="<?= $val('address') ?>"></div>
        <div class="ci-field"><label for="ci_city">City</label><input id="ci_city" type="text" name="city" maxlength="100" value="<?= $val('city') ?>"></div>
        <div class="ci-field"><label for="ci_state">Province / State</label><input id="ci_state" type="text" name="state" maxlength="60" value="<?= $val('state') ?>"></div>
        <div class="ci-field"><label for="ci_zip">Postal code</label><input id="ci_zip" type="text" name="zip" maxlength="20" value="<?= $val('zip') ?>"></div>
        <div class="ci-field"><label for="ci_country">Country</label><input id="ci_country" type="text" name="country" maxlength="100" value="<?= $val('country') ?>"></div>
        <div class="ci-field"><label for="ci_timeZone">Time zone</label><input id="ci_timeZone" type="text" name="timeZone" maxlength="100" value="<?= $val('timeZone') ?>"></div>
      </div>

      <button class="ci-btn" type="submit">Save church info</button>
    </form>
    <?php endif; ?>
  </div>
</article>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'church-info',
    'pageTitle' => 'Church Info · Admin', 'pageSubtitle' => 'Identity, contact, and location.',
    'sectionTitle' => 'Church Info',
    'sectionDescription' => 'Church name, contact details, and location used across the portal.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
