<?php
/**
 * Admin · Users & access · one login (or a new one).
 *
 * Everything that can be done to a login, on its own page: who it belongs to,
 * whether it works, what it may do, its password, and what has been changed.
 * Every form posts to POST /admin/users, which checks isPortalWideAdmin and
 * hands the rules to SystemUserService (last admin, own account) and
 * AuthService::linkLoginToPerson (a login belongs to one person).
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,mixed>|null $editingUser   null on /admin/users/new, or when not found
 * @var list<array<string,mixed>> $ministries
 * @var string $personQuery
 * @var list<array<string,mixed>> $personResults
 * @var array<string,mixed> $recentActivity     ActivityHistoryService::page() for this login, or []
 * @var int $currentUserId
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';
require_once __DIR__ . '/_admin-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = admin_e($basePath);
$h = static fn ($v): string => admin_e($v);

$reqPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
$isNew = str_ends_with(rtrim($reqPath, '/'), '/admin/users/new');
$eu = $editingUser;
$euid = $eu !== null ? (int) $eu['id'] : 0;
$isSelf = $eu !== null && $euid === $currentUserId;

$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$campusName = [];
foreach ($campuses as $c) {
    $campusName[(int) $c['id']] = (string) $c['name'];
}
$ministryName = [];
foreach ($ministries as $m) {
    $ministryName[(int) ($m['ministryId'] ?? 0)] = (string) ($m['name'] ?? '');
}

$roleFields = static function (string $prefix, bool $required) use ($campuses, $ministries, $h): string {
    $roles = '<option value="">' . ($required ? 'Choose a role' : 'No role yet') . '</option>';
    foreach (\App\Services\SystemUserService::ROLES as $r) {
        $roles .= '<option value="' . $h($r) . '">' . $h(ucfirst($r)) . '</option>';
    }
    $campusOptions = '<option value="">All campuses</option>';
    foreach ($campuses as $c) {
        $campusOptions .= '<option value="' . (int) $c['id'] . '">' . $h($c['name']) . '</option>';
    }
    $ministryOptions = '<option value="">Any ministry</option>';
    foreach ($ministries as $m) {
        $ministryOptions .= '<option value="' . (int) ($m['ministryId'] ?? 0) . '">' . $h($m['name'] ?? '') . '</option>';
    }

    return '<div class="ur-row3">'
        . '<div class="ek-field"><label for="' . $prefix . 'Role">Role</label><select class="ek-select" id="' . $prefix . 'Role" name="role"' . ($required ? ' required' : '') . '>' . $roles . '</select></div>'
        . '<div class="ek-field"><label for="' . $prefix . 'Campus">Campus</label><select class="ek-select" id="' . $prefix . 'Campus" name="campus_id">' . $campusOptions . '</select></div>'
        . '<div class="ek-field"><label for="' . $prefix . 'Ministry">Ministry</label><select class="ek-select" id="' . $prefix . 'Ministry" name="ministry_id">' . $ministryOptions . '</select></div>'
        . '</div>'
        . '<p class="ek-hint">Admin: manages the whole portal when neither campus nor ministry is chosen. '
        . 'Leader and scheduler: choose the ministry they lead or schedule. Member: signs in to their own schedule and availability.</p>';
};

$errorMessage = $flash !== '' ? $flash : 'Something went wrong.';

ob_start();
?>
<style>
  .ur-layout{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:var(--sp-4,16px);align-items:start}
  @media (max-width:1100px){.ur-layout{grid-template-columns:minmax(0,1fr)}}
  .ur-col{display:grid;gap:var(--sp-4,16px);min-width:0}
  .ur-row2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--sp-3,12px)}
  .ur-row3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:var(--sp-3,12px)}
  @media (max-width:640px){.ur-row2,.ur-row3{grid-template-columns:minmax(0,1fr)}}
  .ur-checks{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px) var(--sp-5,20px)}
  .ur-check{display:inline-flex;align-items:center;gap:8px;min-height:44px;font-weight:600}
  .ur-check input{width:18px;height:18px}
  .ur-facts{display:grid;grid-template-columns:auto minmax(0,1fr);gap:6px 16px;margin:0}
  .ur-facts dt{color:var(--muted);font-size:13px}
  .ur-facts dd{margin:0;overflow-wrap:anywhere}
  .ur-roles{list-style:none;margin:0 0 var(--sp-4,16px);padding:0;display:grid;gap:6px}
  .ur-roles li{display:flex;align-items:center;justify-content:space-between;gap:var(--sp-3,12px);padding:6px 6px 6px 12px;border:1px solid var(--line);border-radius:var(--radius,8px)}
  .ur-roles form{margin:0}
  .ur-results{list-style:none;margin:var(--sp-3,12px) 0 0;padding:0;display:grid;gap:6px}
  .ur-results li{display:flex;align-items:center;justify-content:space-between;gap:var(--sp-3,12px);padding:6px 6px 6px 12px;border:1px solid var(--line);border-radius:var(--radius,8px)}
  .ur-results form{margin:0}
  .ur-muted{color:var(--muted)}
  .ur-activity{list-style:none;margin:0;padding:0;display:grid;gap:10px}
  .ur-activity li{display:grid;gap:1px;font-size:13px}
  .ur-activity time{font-size:12px;color:var(--muted)}
  .ur-danger{border-color:color-mix(in srgb,var(--rose,#b84957) 45%,var(--line))}
</style>

<?php if (!$isAdmin): ?>
  <div class="ek-card"><div class="ek-card-body">Only a portal-wide admin can manage logins.</div></div>
<?php elseif ($isNew): ?>
  <?= admin_notice($notice, ['error' => ['error', $errorMessage]]) ?>
  <section class="ek-card" aria-labelledby="urNewHeading">
    <div class="ek-card-head"><div>
      <h2 id="urNewHeading">New login</h2>
      <p>The person signs in with this email and password. After creating it you can link it to their person record.</p>
    </div></div>
    <div class="ek-card-body">
      <form class="ek-form" method="post" action="<?= $base ?>/admin/users">
        <input type="hidden" name="action" value="create">
        <div class="ur-row2">
          <div class="ek-field"><label for="nuEmail">Email (required)</label><input class="ek-input" id="nuEmail" type="email" name="email" maxlength="190" required autocomplete="off"></div>
          <div class="ek-field"><label for="nuName">Display name</label><input class="ek-input" id="nuName" type="text" name="display_name" maxlength="100"></div>
        </div>
        <div class="ek-field">
          <label for="nuPassword">Temporary password (required)</label>
          <input class="ek-input" id="nuPassword" type="text" name="password" minlength="12" required autocomplete="off" aria-describedby="nuPasswordHint">
          <span class="ek-hint" id="nuPasswordHint">At least 12 characters. Give it to the person yourself; the portal does not send it.</span>
        </div>
        <?= $roleFields('nu', false) ?>
        <div class="ur-checks">
          <label class="ur-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Active</label>
          <label class="ur-check"><input type="hidden" name="must_change_password" value="0"><input type="checkbox" name="must_change_password" value="1" checked> Must change password at next sign-in</label>
        </div>
        <div class="ek-toolbar">
          <button class="ek-btn ek-btn-primary" type="submit">Create login</button>
          <a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/users">Cancel</a>
        </div>
      </form>
    </div>
  </section>
<?php elseif ($eu === null): ?>
  <div class="ek-card"><div class="ek-empty">
    <strong>This login does not exist</strong>
    <p>It may have been deleted. The list shows every login there is.</p>
    <a class="ek-btn" href="<?= $base ?>/admin/users">Back to Users &amp; access</a>
  </div></div>
<?php else: ?>
  <?= admin_notice($notice, [
      'created' => ['ok', 'Login created. Link it to the person it belongs to below.'],
      'saved' => ['ok', 'Changes saved.'],
      'pw' => ['ok', 'Password set. Tell the person their new password; the portal does not send it.'],
      'role' => ['ok', 'Roles updated. They apply from the person\'s next page load.'],
      'linked' => ['ok', 'Login linked to the person.'],
      'error' => ['error', $errorMessage],
  ]) ?>

  <div class="ur-layout">
    <div class="ur-col">

      <section class="ek-card" aria-labelledby="urAccessHeading">
        <div class="ek-card-head"><div>
          <h2 id="urAccessHeading">Sign-in</h2>
          <p>Whether this login works, and what it is called in the portal.</p>
        </div><?= admin_login_state_badge($eu) ?></div>
        <div class="ek-card-body">
          <form class="ek-form" method="post" action="<?= $base ?>/admin/users">
            <input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= $euid ?>">
            <div class="ek-field"><label for="euName">Display name</label><input class="ek-input" id="euName" type="text" name="display_name" maxlength="100" value="<?= $h($eu['display_name']) ?>"></div>
            <div class="ur-checks">
              <?php if ($isSelf): ?>
                <input type="hidden" name="is_active" value="1">
                <span class="ur-check ur-muted">Active — you cannot deactivate your own login</span>
              <?php else: ?>
                <label class="ur-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= $eu['is_active'] ? 'checked' : '' ?>> Active (can sign in)</label>
              <?php endif; ?>
              <label class="ur-check"><input type="hidden" name="must_change_password" value="0"><input type="checkbox" name="must_change_password" value="1" <?= $eu['must_change_password'] ? 'checked' : '' ?>> Must change password at next sign-in</label>
            </div>
            <div class="ek-toolbar"><button class="ek-btn ek-btn-primary" type="submit">Save</button></div>
          </form>
        </div>
      </section>

      <section class="ek-card" aria-labelledby="urRolesHeading">
        <div class="ek-card-head"><div>
          <h2 id="urRolesHeading">Roles</h2>
          <p>What this login may do, and where.</p>
        </div></div>
        <div class="ek-card-body">
          <?php if ($eu['roles'] === []): ?>
            <p class="ur-muted" style="margin-top:0">No roles yet. This login can sign in but is offered nothing beyond Home.</p>
          <?php else: ?>
            <ul class="ur-roles">
              <?php foreach ($eu['roles'] as $r): $label = admin_role_label($r, $campusName, $ministryName); ?>
                <li>
                  <span><?= $h($label) ?></span>
                  <form method="post" action="<?= $base ?>/admin/users">
                    <input type="hidden" name="action" value="removerole"><input type="hidden" name="account_role_id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="user_id" value="<?= $euid ?>">
                    <button class="ek-btn ek-btn-quiet" type="submit" aria-label="Remove role: <?= $h($label) ?>">Remove</button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <form class="ek-form" method="post" action="<?= $base ?>/admin/users" aria-label="Add a role">
            <input type="hidden" name="action" value="addrole"><input type="hidden" name="user_id" value="<?= $euid ?>">
            <?= $roleFields('ar', true) ?>
            <div class="ek-toolbar"><button class="ek-btn" type="submit">Add role</button></div>
          </form>
        </div>
      </section>

      <section class="ek-card" aria-labelledby="urPwHeading">
        <div class="ek-card-head"><div>
          <h2 id="urPwHeading">Reset password</h2>
          <p>Sets a new password straight away. Tick "Must change password" above if the person should choose their own.</p>
        </div></div>
        <div class="ek-card-body">
          <form class="ek-form" method="post" action="<?= $base ?>/admin/users">
            <input type="hidden" name="action" value="setpw"><input type="hidden" name="user_id" value="<?= $euid ?>">
            <div class="ek-field"><label for="euPw">New password</label><input class="ek-input" id="euPw" type="text" name="password" minlength="12" required autocomplete="off" aria-describedby="euPwHint"><span class="ek-hint" id="euPwHint">At least 12 characters.</span></div>
            <div class="ek-toolbar"><button class="ek-btn" type="submit">Set password</button></div>
          </form>
        </div>
      </section>

    </div>
    <div class="ur-col">

      <section class="ek-card" aria-labelledby="urFactsHeading">
        <div class="ek-card-head"><div><h2 id="urFactsHeading">Login</h2></div></div>
        <div class="ek-card-body">
          <dl class="ur-facts">
            <dt>Email</dt><dd><?= $h($eu['email']) ?><?= $isSelf ? ' <span class="ur-muted">(you)</span>' : '' ?></dd>
            <dt>Last sign-in</dt><dd><?= $h(admin_when($eu['last_login_at'] ?? null, 'Never')) ?></dd>
            <dt>Created</dt><dd><?= $h(admin_when($eu['created_at'] ?? null)) ?></dd>
          </dl>
        </div>
      </section>

      <section class="ek-card" aria-labelledby="urPersonHeading">
        <div class="ek-card-head"><div>
          <h2 id="urPersonHeading">Person</h2>
          <p>The member record this login belongs to. A login belongs to one person.</p>
        </div></div>
        <div class="ek-card-body">
          <?php if (!empty($eu['person_id'])): ?>
            <p style="margin:0 0 8px"><a href="<?= $base ?>/admin/people/view?id=<?= (int) $eu['person_id'] ?>"><?= $h($eu['person_name'] !== '' ? $eu['person_name'] : 'Person #' . $eu['person_id']) ?></a></p>
            <p class="ek-hint" style="margin:0">To give this person a different login, create it and delete this one.</p>
          <?php else: ?>
            <p class="ur-muted" style="margin-top:0">Not linked. Their schedule and ministries cannot be shown until it is.</p>
            <form method="get" action="<?= $base ?>/admin/users/<?= $euid ?>" role="search" class="ek-toolbar">
              <label class="sr-only" for="urPersonQ">Find a person</label>
              <input class="ek-input" id="urPersonQ" type="search" name="person_q" value="<?= $h($personQuery) ?>" placeholder="Name or email" style="flex:1 1 180px;width:auto">
              <button class="ek-btn" type="submit">Find</button>
            </form>
            <?php if ($personQuery !== ''): ?>
              <?php if ($personResults === []): ?>
                <p class="ur-muted">No person matches “<?= $h($personQuery) ?>”. Check the spelling, or add them under Member records first.</p>
              <?php else: ?>
                <ul class="ur-results" aria-label="Matching people">
                  <?php foreach ($personResults as $p): $pname = trim((string) $p['first_name'] . ' ' . (string) $p['last_name']); ?>
                    <li>
                      <span><?= $h($pname) ?><?php if (!empty($p['email'])): ?><br><span class="ek-hint"><?= $h($p['email']) ?></span><?php endif; ?></span>
                      <form method="post" action="<?= $base ?>/admin/users">
                        <input type="hidden" name="action" value="link"><input type="hidden" name="user_id" value="<?= $euid ?>"><input type="hidden" name="person_id" value="<?= (int) $p['id'] ?>">
                        <button class="ek-btn" type="submit" aria-label="Link this login to <?= $h($pname) ?>">Link</button>
                      </form>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </section>

      <section class="ek-card" aria-labelledby="urActivityHeading">
        <div class="ek-card-head"><div>
          <h2 id="urActivityHeading">Recent changes</h2>
          <p>Changes made to this login.</p>
        </div></div>
        <div class="ek-card-body">
          <?php $entries = array_slice(is_array($recentActivity['entries'] ?? null) ? $recentActivity['entries'] : [], 0, 5); ?>
          <?php if ($entries === []): ?>
            <p class="ur-muted" style="margin:0">Nothing recorded yet. Changes made from this page are recorded from now on.</p>
          <?php else: ?>
            <ul class="ur-activity">
              <?php foreach ($entries as $entry): ?>
                <li>
                  <span><?= $h($entry['summary'] ?? \App\Services\ActivityHistoryService::describeAction((string) $entry['action'])) ?></span>
                  <time datetime="<?= $h($entry['occurredAt']) ?>"><?= $h(admin_when($entry['occurredAt'])) ?> · <?= $h($entry['accountName'] ?? $entry['accountEmail'] ?? 'Unknown login') ?></time>
                </li>
              <?php endforeach; ?>
            </ul>
            <p style="margin:12px 0 0"><a href="<?= $base ?>/admin/history?target_type=user_account&amp;target_id=<?= $euid ?>">All changes to this login</a></p>
          <?php endif; ?>
        </div>
      </section>

      <?php if (!$isSelf): ?>
      <section class="ek-card ur-danger" aria-labelledby="urDeleteHeading">
        <div class="ek-card-head"><div>
          <h2 id="urDeleteHeading">Delete login</h2>
          <p>To stop someone signing in for now, untick Active instead; deleting cannot be undone.</p>
        </div></div>
        <div class="ek-card-body">
          <form method="post" action="<?= $base ?>/admin/users"
                onsubmit="return confirm('Delete the login for <?= $h(addslashes((string) $eu['email'])) ?>?\n\nThe login and all of its roles are removed and it can no longer sign in. The person record is not affected.\n\nThis cannot be undone.');">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= $euid ?>">
            <button class="ek-btn ek-btn-danger" type="submit">Delete login</button>
          </form>
        </div>
      </section>
      <?php endif; ?>

    </div>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();

$title = $isNew ? 'New login' : ($eu !== null ? ($eu['display_name'] !== '' ? $eu['display_name'] : $eu['email']) : 'Login not found');

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'users',
    'pageTitle' => $title . ' · Users & access', 'pageSubtitle' => 'Users & access',
    'sectionTitle' => (string) $title,
    'sectionDescription' => $isNew || $eu === null ? '' : (string) $eu['email'],
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
    'headerActions' => $isAdmin ? '<a class="ek-btn ek-btn-quiet" href="' . $base . '/admin/users">All logins</a>' : '',
], static fn (): string => $content);
