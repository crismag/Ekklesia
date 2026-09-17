<?php
/**
 * Admin · System Users — manage user_accounts and their role assignments.
 * Data + actions via SystemUserService (user_accounts, account_roles).
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var list<array<string,mixed>> $users
 * @var array<string,mixed>|null $editingUser
 * @var list<array<string,mixed>> $ministries
 * @var int $currentUserId
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$campusName = [];
foreach ($campuses as $c) { $campusName[(int) $c['id']] = (string) $c['name']; }
$ministryName = [];
foreach ($ministries as $m) { $ministryName[(int) $m['ministryId']] = (string) $m['name']; }

$roleLabel = static function (array $r) use ($campusName, $ministryName): string {
    $s = ucfirst($r['role']);
    if ($r['campus_id'] !== null) { $s .= ' @ ' . ($campusName[$r['campus_id']] ?? ('campus ' . $r['campus_id'])); }
    if ($r['ministry_id'] !== null) { $s .= ' · ' . ($ministryName[$r['ministry_id']] ?? ('ministry ' . $r['ministry_id'])); }
    if ($r['role'] === 'admin' && $r['campus_id'] === null && $r['ministry_id'] === null) { $s .= ' (portal-wide)'; }
    return $s;
};

$noticeMap = [
    'created' => ['ok', 'User created.'],
    'saved'   => ['ok', 'User updated.'],
    'pw'      => ['ok', 'Password reset.'],
    'role'    => ['ok', 'Roles updated.'],
    'deleted' => ['ok', 'User deleted.'],
    'error'   => ['err', $flash !== '' ? $flash : 'Something went wrong.'],
];

// Role <select> options helper.
$roleOptions = static function (string $sel = '') {
    $out = '<option value="">— role —</option>';
    foreach (\App\Services\SystemUserService::ROLES as $r) {
        $out .= '<option value="' . $r . '"' . ($sel === $r ? ' selected' : '') . '>' . ucfirst($r) . '</option>';
    }
    return $out;
};
$campusOptions = static function () use ($campuses, $h) {
    $out = '<option value="">All campuses</option>';
    foreach ($campuses as $c) { $out .= '<option value="' . (int) $c['id'] . '">' . $h($c['name']) . '</option>'; }
    return $out;
};
$ministryOptions = static function () use ($ministries, $h) {
    $out = '<option value="">Any ministry</option>';
    foreach ($ministries as $m) { $out .= '<option value="' . (int) $m['ministryId'] . '">' . $h($m['name']) . '</option>'; }
    return $out;
};

ob_start();
?>
<style>
  .us-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .us-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}
  .us-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}

  /* The list is narrow and fixed; the panel takes what is left. Below 900px
     they stack, list first, because on a phone you pick before you edit. */
  .us-layout{display:grid;grid-template-columns:minmax(260px,340px) minmax(0,1fr);
    gap:16px;align-items:start}
  /* minmax(0,1fr), not 1fr: a bare 1fr track is min-content sized, so a panel
     wider than the phone refused to shrink and pushed the page three pixels
     sideways instead of fitting. */
  @media (max-width:900px){.us-layout{grid-template-columns:minmax(0,1fr)}}

  .us-panel{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px}
  .us-panel-head{display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:12px 14px;border-bottom:1px solid var(--line,#eef2f5)}
  .us-panel-head h2{margin:0;font-size:15px}
  .us-panel-body{padding:14px}
  .us-count{display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;
    background:var(--soft,#eef4f0);color:var(--muted,#5c6b63);font-size:12px;font-weight:700}

  /* The list scrolls on its own so the panel beside it stays put rather than
     being pushed down the page by a long roster. */
  .us-items{list-style:none;margin:0;padding:6px;max-height:64vh;overflow-y:auto}
  @media (max-width:900px){.us-items{max-height:340px}}
  /* Two lines per account: who they are and whether the account works, then
     the address and what they can do. The email truncates rather than wrapping
     mid-word, which was turning every row into three lines. */
  .us-item{display:flex;flex-direction:column;gap:2px;padding:8px 10px;border-radius:8px;
    text-decoration:none;color:var(--ink,#1b2a24);border:1px solid transparent;min-height:44px}
  .us-item-top,.us-item-sub{display:flex;align-items:center;gap:8px;min-width:0}
  .us-item-top{justify-content:space-between}
  .us-item-sub{justify-content:space-between}
  .us-item:hover{background:var(--soft,#f2f7f4)}
  .us-item:focus-visible{outline:2px solid var(--deep,#0c5a45);outline-offset:-2px}
  .us-item.is-on{background:var(--soft,#eef4f0);border-color:#bcd8c9}
  .us-item-name{font-weight:700;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
  .us-item-mail{color:var(--muted,#5c6b63);font-size:11.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
  .us-you{margin-left:4px;font-size:11px;font-weight:700;color:var(--muted,#5c6b63)}
  /* Status reads as a word as well as a colour, so it survives greyscale and
     print and does not rely on telling green from grey. */
  .us-state{display:inline-block;padding:1px 7px;border-radius:999px;font-size:10.5px;font-weight:800;white-space:nowrap;flex:0 0 auto}
  .us-on{background:#e6f7ec;color:#1a7a3a}
  .us-off{background:#eceff3;color:#5a6b7b}
  .us-warn{background:#fdefe0;color:#8a4b12}
  .us-roles{font-size:11.5px;color:var(--muted,#5c6b63);white-space:nowrap;flex:0 0 auto}
  .us-norole{font-style:italic}
  .us-empty{padding:16px;color:var(--muted,#5c6b63);margin:0}

  .us-mailline{margin:0 0 14px;color:var(--muted,#5c6b63);font-size:13px;word-break:break-all}
  .us-sub{margin:20px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.03em;
    color:var(--muted,#5c6b63);padding-top:14px;border-top:1px solid var(--line,#eef2f5)}
  .us-note{color:var(--muted,#5c6b63);font-size:12px;margin:8px 0 0}
  .us-hint{font-weight:400;text-transform:none;letter-spacing:0;color:var(--muted,#5c6b63)}

  .us-chips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}
  .us-chip{display:inline-flex;align-items:center;gap:4px;background:var(--soft,#eef4f0);
    border:1px solid #d6e4dc;color:#0c5a45;border-radius:999px;font-size:12px;font-weight:700;padding:3px 6px 3px 10px}
  .us-chip.admin{background:#0c5a45;color:#fff;border-color:#0c5a45}
  /* Removing a role is a real action and gets a real target — 24px square,
     not the size of a multiplication sign. */
  .us-chip-x{border:0;background:none;cursor:pointer;color:inherit;font-weight:900;
    font-size:15px;line-height:1;padding:0;border-radius:50%;
    width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center}
  .us-chip-x:hover,.us-chip-x:focus-visible{background:rgba(0,0,0,.12)}

  .us-btn{font:inherit;font-size:12px;font-weight:700;border:1px solid var(--line,#c7d4cd);background:#fff;
    border-radius:7px;padding:8px 12px;min-height:36px;cursor:pointer;text-decoration:none;color:var(--ink,#1b2a24);
    display:inline-flex;align-items:center}
  .us-btn:hover{border-color:#137a5f}
  .us-btn.primary{background:#0c5a45;border-color:#0c5a45;color:#fff}
  .us-btn.danger{color:#b3261e;border-color:#f0c3bd}

  .us-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media (max-width:640px){.us-grid{grid-template-columns:1fr}}
  .us-field label{display:block;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63);margin-bottom:4px}
  .us-field input,.us-field select{width:100%;font:inherit;padding:9px 10px;min-height:40px;
    border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff;color:var(--ink,#1b2a24)}
  .us-field.full{grid-column:1 / -1}
  .us-checks{display:flex;flex-wrap:wrap;gap:6px 18px;align-items:center;margin:10px 0 14px}
  .us-check{display:inline-flex;align-items:center;gap:8px;min-height:44px;font-weight:700;font-size:13px}
  .us-check input[type=checkbox]{width:18px;height:18px;flex:0 0 auto}
  .us-req{color:#b3261e}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="us-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <article class="admin-card"><div class="admin-card-body" style="color:var(--muted)">
    Only a portal-wide admin can manage system users.
  </div></article>
<?php else: ?>

<?php
  // Two columns: who exists on the left, what you are doing to one of them on
  // the right. The list and the form used to be stacked, so managing a user
  // meant scrolling past every other user to reach the form and back up again
  // to see the result.
  $eu = $editingUser;
  $euid = $eu !== null ? (int) $eu['id'] : 0;
  $adding = $eu === null;
?>
<div class="us-layout">

  <section class="us-panel us-list" aria-labelledby="usListHeading">
    <div class="us-panel-head">
      <h2 id="usListHeading">Accounts <span class="us-count"><?= count($users) ?></span></h2>
      <?php if (!$adding): ?>
        <a class="us-btn" href="<?= $base ?>/admin/users">+ Add</a>
      <?php endif; ?>
    </div>
    <?php if (!$users): ?>
      <p class="us-empty">No accounts yet. Add the first one on the right.</p>
    <?php else: ?>
      <ul class="us-items">
        <?php foreach ($users as $u): $uid = (int) $u['id']; $on = $uid === $euid; ?>
          <li>
            <a class="us-item<?= $on ? ' is-on' : '' ?>" href="<?= $base ?>/admin/users?user_id=<?= $uid ?>"
               <?= $on ? 'aria-current="true"' : '' ?>>
              <?php
                // Status as a word, not only a colour: this is the difference
                // between an account that works and one that does not.
                $state = $u['is_active'] ? 'Active' : 'Inactive';
                if ($u['is_active'] && $u['must_change_password']) { $state = 'Set password'; }
                $roles = array_map($roleLabel, $u['roles']);
              ?>
              <span class="us-item-top">
                <span class="us-item-name">
                  <?= $h($u['display_name'] !== '' ? $u['display_name'] : $u['email']) ?>
                  <?php if ($uid === $currentUserId): ?><span class="us-you">you</span><?php endif; ?>
                </span>
                <span class="us-state <?= $u['is_active'] ? ($u['must_change_password'] ? 'us-warn' : 'us-on') : 'us-off' ?>"><?= $h($state) ?></span>
              </span>
              <span class="us-item-sub">
                <span class="us-item-mail" title="<?= $h($u['email']) ?>"><?= $h($u['email']) ?></span>
                <?php if ($roles !== []): ?>
                  <span class="us-roles" title="<?= $h(implode(', ', $roles)) ?>"><?= $h($roles[0]) ?><?= count($roles) > 1 ? ' +' . (count($roles) - 1) : '' ?></span>
                <?php else: ?>
                  <span class="us-roles us-norole">No role</span>
                <?php endif; ?>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="us-panel us-detail" aria-labelledby="usDetailHeading">
  <?php if ($adding): ?>
    <div class="us-panel-head"><h2 id="usDetailHeading">Add an account</h2></div>
    <div class="us-panel-body">
      <form method="post" action="<?= $base ?>/admin/users">
        <input type="hidden" name="action" value="create">
        <div class="us-grid">
          <div class="us-field"><label for="nu_email">Email <span class="us-req" aria-hidden="true">*</span><span class="sr-only">(required)</span></label><input id="nu_email" type="email" name="email" maxlength="190" required></div>
          <div class="us-field"><label for="nu_display_name">Display name</label><input id="nu_display_name" type="text" name="display_name" maxlength="100"></div>
          <div class="us-field full"><label for="nu_password">Password <span class="us-req" aria-hidden="true">*</span><span class="sr-only">(required)</span> <span class="us-hint">minimum 12 characters</span></label><input id="nu_password" type="text" name="password" minlength="12" required autocomplete="off"></div>
          <div class="us-field"><label for="nu_role">Role</label><select id="nu_role" name="role"><?= $roleOptions() ?></select></div>
          <div class="us-field"><label for="nu_campus">Campus</label><select id="nu_campus" name="campus_id"><?= $campusOptions() ?></select></div>
          <div class="us-field full"><label for="nu_ministry">Ministry</label><select id="nu_ministry" name="ministry_id"><?= $ministryOptions() ?></select></div>
        </div>
        <p class="us-note">Leave campus and ministry blank for church-wide. An admin with neither is a portal-wide admin.</p>
        <div class="us-checks">
          <label class="us-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked><span>Active</span></label>
          <label class="us-check"><input type="hidden" name="must_change_password" value="0"><input type="checkbox" name="must_change_password" value="1" checked><span>Must change password at next sign-in</span></label>
        </div>
        <button class="us-btn primary" type="submit">Create account</button>
      </form>
    </div>
  <?php else: ?>
    <div class="us-panel-head">
      <h2 id="usDetailHeading"><?= $h($eu['display_name'] !== '' ? $eu['display_name'] : $eu['email']) ?></h2>
      <a class="us-btn" href="<?= $base ?>/admin/users">Close</a>
    </div>
    <div class="us-panel-body">
      <p class="us-mailline"><?= $h($eu['email']) ?></p>

      <form method="post" action="<?= $base ?>/admin/users">
        <input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= $euid ?>">
        <div class="us-grid">
          <div class="us-field full"><label for="eu_display_name">Display name</label><input id="eu_display_name" type="text" name="display_name" maxlength="100" value="<?= $h($eu['display_name']) ?>"></div>
        </div>
        <div class="us-checks">
          <label class="us-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= $eu['is_active'] ? 'checked' : '' ?>><span>Active</span></label>
          <label class="us-check"><input type="hidden" name="must_change_password" value="0"><input type="checkbox" name="must_change_password" value="1" <?= $eu['must_change_password'] ? 'checked' : '' ?>><span>Must change password at next sign-in</span></label>
        </div>
        <button class="us-btn primary" type="submit">Save</button>
      </form>

      <h3 class="us-sub">Roles</h3>
      <div class="us-chips">
        <?php foreach ($eu['roles'] as $r): ?>
          <span class="us-chip <?= $r['role'] === 'admin' ? 'admin' : '' ?>"><?= $h($roleLabel($r)) ?>
            <form method="post" action="<?= $base ?>/admin/users" style="display:inline">
              <input type="hidden" name="action" value="removerole"><input type="hidden" name="account_role_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="user_id" value="<?= $euid ?>">
              <button type="submit" class="us-chip-x" aria-label="Remove role: <?= $h($roleLabel($r)) ?>">&times;</button>
            </form>
          </span>
        <?php endforeach; ?>
        <?php if (!$eu['roles']): ?><span class="us-note">No roles yet — this account can sign in but do nothing.</span><?php endif; ?>
      </div>
      <form method="post" action="<?= $base ?>/admin/users">
        <input type="hidden" name="action" value="addrole"><input type="hidden" name="user_id" value="<?= $euid ?>">
        <div class="us-grid">
          <div class="us-field"><label for="ar_role">Role</label><select id="ar_role" name="role" required><?= $roleOptions() ?></select></div>
          <div class="us-field"><label for="ar_campus">Campus</label><select id="ar_campus" name="campus_id"><?= $campusOptions() ?></select></div>
          <div class="us-field full"><label for="ar_ministry">Ministry</label><select id="ar_ministry" name="ministry_id"><?= $ministryOptions() ?></select></div>
        </div>
        <button class="us-btn" type="submit">Add role</button>
      </form>

      <h3 class="us-sub">Password</h3>
      <form method="post" action="<?= $base ?>/admin/users">
        <input type="hidden" name="action" value="setpw"><input type="hidden" name="user_id" value="<?= $euid ?>">
        <div class="us-grid">
          <div class="us-field full"><label for="eu_password">New password <span class="us-hint">minimum 12 characters</span></label><input id="eu_password" type="text" name="password" minlength="12" placeholder="Type a new password" autocomplete="off"></div>
        </div>
        <button class="us-btn" type="submit">Set password</button>
      </form>

      <?php if ($euid !== $currentUserId): ?>
        <h3 class="us-sub">Remove this account</h3>
        <form method="post" action="<?= $base ?>/admin/users"
              onsubmit="return confirm('Delete the account for <?= $h($eu['email']) ?>?\n\nThe account and all of its roles are removed and the person can no longer sign in. Their member record is not affected.\n\nThis cannot be undone.');">
          <input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= $euid ?>">
          <button class="us-btn danger" type="submit">Delete account</button>
        </form>
      <?php else: ?>
        <p class="us-note us-sub">This is your own account, so it cannot be deleted from here.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  </section>

</div>

<?php endif; ?>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'users',
    'pageTitle' => 'Users & access · Admin', 'pageSubtitle' => 'Who can sign in, and what they may do.',
    'sectionTitle' => 'Users & access',
    'sectionDescription' => 'Create accounts, reset passwords, and say what each person may do.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
