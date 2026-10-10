<?php
/**
 * Admin · Users & access — every login, whose it is, what it may do, and
 * whether it works. Each row opens the login's record page
 * (admin-user-record.php), where access is changed.
 *
 * Data: SystemUserService::list() (fetched only for portal-wide admins),
 * filtered by LoginDirectory.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var list<array<string,mixed>> $users     after filtering
 * @var list<array<string,mixed>> $allUsers
 * @var list<array<string,mixed>> $ministries
 * @var array{q:string,role:string,state:string} $filters
 * @var int $currentUserId
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';
require_once __DIR__ . '/_admin-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = admin_e($basePath);
$h = static fn ($v): string => admin_e($v);

$campusName = [];
foreach ((is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : []) as $c) {
    $campusName[(int) $c['id']] = (string) $c['name'];
}
$ministryName = [];
foreach ($ministries as $m) {
    $ministryName[(int) ($m['ministryId'] ?? 0)] = (string) ($m['name'] ?? '');
}

$counts = \App\Services\LoginDirectory::counts($allUsers);
$filtered = $filters['q'] !== '' || $filters['role'] !== '' || $filters['state'] !== '';

$stateLink = static fn (string $query): string => $base . '/admin/users?' . $query;

ob_start();
?>
<style>
  .ua-filters{display:grid;grid-template-columns:minmax(0,2fr) repeat(2,minmax(0,1fr)) auto;gap:var(--sp-3,12px);align-items:end}
  @media (max-width:760px){.ua-filters{grid-template-columns:minmax(0,1fr)}}
  .ua-who{display:grid;gap:1px;min-width:0}
  .ua-who a{font-weight:650;text-decoration:none;overflow-wrap:anywhere}
  .ua-who a:hover{text-decoration:underline}
  .ua-sub{font-size:12px;color:var(--muted);overflow-wrap:anywhere}
  .ua-roles{display:flex;flex-wrap:wrap;gap:4px}
  .ua-none{color:var(--muted);font-style:italic}
  .ua-table td{vertical-align:middle}
  /* A phone gets one card per login: five columns do not fit 390px, and a
     login list is read a row at a time anyway. */
  @media (max-width:760px){
    .ua-table thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
    .ua-table,.ua-table tbody,.ua-table tr,.ua-table td{display:block;width:100%}
    .ua-table tr{padding:var(--sp-3,12px) var(--sp-4,16px);border-bottom:1px solid var(--line)}
    .ua-table td{border:0;padding:2px 0}
    .ua-table td[data-label]::before{content:attr(data-label) ": ";font-size:12px;color:var(--muted);font-weight:600}
  }
</style>

<?= admin_notice($notice, [
    'deleted' => ['ok', 'Login deleted. The person record it belonged to is unchanged.'],
    'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.'],
]) ?>

<?php if (!$isAdmin): ?>
  <div class="ek-card"><div class="ek-card-body">Only a portal-wide admin can manage logins.</div></div>
<?php else: ?>

<div class="ek-stats" aria-label="Logins at a glance">
  <a class="ek-stat" href="<?= $stateLink('state=active') ?>"><span class="ek-stat-label">Active logins</span><span class="ek-stat-value"><?= (int) $counts['active'] ?></span></a>
  <a class="ek-stat" href="<?= $stateLink('role=portal-admin') ?>"><span class="ek-stat-label">Portal-wide admins</span><span class="ek-stat-value"><?= (int) $counts['portalAdmins'] ?></span></a>
  <a class="ek-stat" href="<?= $stateLink('state=must-change') ?>"><span class="ek-stat-label">Must change password</span><span class="ek-stat-value"><?= (int) $counts['mustChange'] ?></span></a>
  <a class="ek-stat" href="<?= $stateLink('state=never') ?>"><span class="ek-stat-label">Never signed in</span><span class="ek-stat-value"><?= (int) $counts['never'] ?></span></a>
  <a class="ek-stat" href="<?= $stateLink('state=unlinked') ?>"><span class="ek-stat-label">Not linked to a person</span><span class="ek-stat-value"><?= (int) $counts['unlinked'] ?></span></a>
</div>

<section class="ek-card" aria-labelledby="uaListHeading">
  <div class="ek-card-head">
    <div>
      <h2 id="uaListHeading">Logins</h2>
      <p><?= $filtered
          ? $h(count($users) . ' of ' . $counts['total'] . ' match')
          : $h($counts['total'] . ' in total, ' . $counts['inactive'] . ' inactive') ?></p>
    </div>
  </div>
  <div class="ek-card-body">
    <form class="ua-filters" method="get" action="<?= $base ?>/admin/users" role="search">
      <div class="ek-field">
        <label for="uaQ">Search</label>
        <input class="ek-input" id="uaQ" type="search" name="q" value="<?= $h($filters['q']) ?>" placeholder="Email, display name or person">
      </div>
      <div class="ek-field">
        <label for="uaRole">Role</label>
        <select class="ek-select" id="uaRole" name="role">
          <option value="">Any role</option>
          <option value="portal-admin"<?= $filters['role'] === 'portal-admin' ? ' selected' : '' ?>>Portal-wide admin</option>
          <?php foreach (\App\Services\SystemUserService::ROLES as $r): ?>
            <option value="<?= $h($r) ?>"<?= $filters['role'] === $r ? ' selected' : '' ?>><?= $h(ucfirst($r)) ?>, any scope</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ek-field">
        <label for="uaState">Status</label>
        <select class="ek-select" id="uaState" name="state">
          <?php foreach (\App\Services\LoginDirectory::STATES as $value => $label): ?>
            <option value="<?= $h($value) ?>"<?= $filters['state'] === $value ? ' selected' : '' ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ek-toolbar">
        <button class="ek-btn" type="submit">Apply</button>
        <?php if ($filtered): ?><a class="ek-btn ek-btn-quiet" href="<?= $base ?>/admin/users">Clear</a><?php endif; ?>
      </div>
    </form>
  </div>

  <?php if ($users === []): ?>
    <div class="ek-empty">
      <?php if ($filtered): ?>
        <strong>No logins match</strong>
        <p>Try a shorter search, or clear the filters to see every login.</p>
        <a class="ek-btn" href="<?= $base ?>/admin/users">Clear filters</a>
      <?php else: ?>
        <strong>No logins yet</strong>
        <p>Create a login for the first person who needs to sign in.</p>
        <a class="ek-btn ek-btn-primary" href="<?= $base ?>/admin/users/new">Add a login</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
  <div class="ek-table-wrap">
    <table class="ek-table ua-table">
      <caption class="sr-only">Logins, the person each belongs to, roles, status and last sign-in</caption>
      <thead><tr>
        <th scope="col">Login</th>
        <th scope="col">Person</th>
        <th scope="col">Roles</th>
        <th scope="col">Status</th>
        <th scope="col">Last sign-in</th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u): $uid = (int) $u['id']; ?>
        <tr>
          <td>
            <div class="ua-who">
              <a href="<?= $base ?>/admin/users/<?= $uid ?>"><?= $h($u['display_name'] !== '' ? $u['display_name'] : $u['email']) ?></a>
              <?php if ($u['display_name'] !== ''): ?><span class="ua-sub"><?= $h($u['email']) ?></span><?php endif; ?>
              <?php if ($uid === $currentUserId): ?><span class="ua-sub">This is you</span><?php endif; ?>
            </div>
          </td>
          <td data-label="Person">
            <?php if (!empty($u['person_id'])): ?>
              <a href="<?= $base ?>/admin/people/view?id=<?= (int) $u['person_id'] ?>"><?= $h($u['person_name'] !== '' ? $u['person_name'] : 'Person #' . $u['person_id']) ?></a>
            <?php else: ?>
              <span class="ua-none">Not linked</span>
            <?php endif; ?>
          </td>
          <td data-label="Roles">
            <?php if ($u['roles'] === []): ?>
              <span class="ua-none">No role</span>
            <?php else: ?>
              <span class="ua-roles">
              <?php foreach ($u['roles'] as $r): ?>
                <span class="ek-badge"><?= $h(admin_role_label($r, $campusName, $ministryName)) ?></span>
              <?php endforeach; ?>
              </span>
            <?php endif; ?>
          </td>
          <td data-label="Status"><?= admin_login_state_badge($u) ?></td>
          <td data-label="Last sign-in"><?= $h(admin_when($u['last_login_at'] ?? null, 'Never')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php endif; ?>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'users',
    'pageTitle' => 'Users & access', 'pageSubtitle' => 'Who can sign in, and what they may do.',
    'sectionTitle' => 'Users & access',
    'sectionDescription' => 'Every login, the person it belongs to, and what it may do. Open a login to change its roles, reset its password or deactivate it.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
    'headerActions' => $isAdmin ? '<a class="ek-btn ek-btn-primary" href="' . $base . '/admin/users/new">Add a login</a>' : '',
], static fn (): string => $content);
