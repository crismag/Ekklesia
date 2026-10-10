<?php
/**
 * People & Records · Person record — one person, in sections: identity,
 * contact & address, household, membership, ministries (read-only), logins
 * (read-only) and recent history. Editing is its own page.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,mixed>|null $data
 * @var list<array{ministry_id:int,name:string,roles:string,is_leader:bool}> $ministries
 * @var list<array<string,mixed>> $logins
 * @var array{entries:list<array<string,mixed>>,total:int} $recentHistory
 * @var list<array<string,mixed>> $relatedLinks
 * @var list<array<string,mixed>> $residenceMates
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_records-kit.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = records_h($basePath);
$h = 'records_h';
$ministries = $ministries ?? [];
$logins = $logins ?? [];
$recentHistory = $recentHistory ?? ['entries' => [], 'total' => 0];
$headerActions = '';

ob_start();
echo records_styles();

if (!$isAdmin) { ?>
  <section class="ek-empty">
    <strong>Member records are for portal administrators</strong>
    <p>You can look people up in the directory, which shows what your account is allowed to see.</p>
    <a class="ek-btn" href="<?= $base ?>/people">Open the directory</a>
  </section>
<?php } elseif ($data === null) {
    http_response_code(404); ?>
  <section class="ek-empty">
    <strong>This person is not on record</strong>
    <p>The record may have been deleted or merged, or the link is incomplete.</p>
    <a class="ek-btn" href="<?= $base ?>/admin/people">Back to member records</a>
  </section>
<?php } else {
    $p = $data['person'];
    $pid = (int) $p['id'];
    $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    $name = $name !== '' ? $name : 'Person #' . $pid;
    $initials = strtoupper(substr((string) ($p['first_name'] ?? ''), 0, 1) . substr((string) ($p['last_name'] ?? ''), 0, 1));
    $gender = ($p['gender'] ?? null) === 'male' ? 'Male' : (($p['gender'] ?? null) === 'female' ? 'Female' : '');
    $L = $data['labels'];
    $addr = (string) $data['address_line'];
    $lat = $data['lat']; $lng = $data['lng'];
    $mapsQ = rawurlencode($addr);
    $householdId = (int) ($p['household_id'] ?? 0);
    $membershipSince = !empty($p['member_since']) && strtotime((string) $p['member_since'])
        ? date('j M Y', (int) strtotime((string) $p['member_since'])) : '';
    $birthday = (int) ($p['birth_month'] ?? 0) > 0
        ? date('j M', mktime(0, 0, 0, (int) $p['birth_month'], (int) $p['birth_day'] ?: 1)) . ((int) ($p['birth_year'] ?? 0) > 0 ? ' ' . (int) $p['birth_year'] : '')
        : '';
    $otherNames = array_filter([
        'Preferred' => trim((string) ($p['preferred_name'] ?? '')),
        'Middle' => trim((string) ($p['middle_name'] ?? '')),
        'Suffix' => trim((string) ($p['suffix'] ?? '')),
    ], static fn (string $v): bool => $v !== '');
    $noticeMap = ['saved' => ['ok', 'Changes saved.'], 'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.']];
    $headerActions = '<a class="ek-btn ek-btn-primary" href="' . $base . '/admin/people/edit?id=' . $pid . '">Edit record</a>'
        . '<a class="ek-btn" href="' . $base . '/people/' . $pid . '">Directory profile</a>';
    $phones = array_filter([
        'Mobile' => (string) ($p['mobile_phone'] ?? ''), 'Home' => (string) ($p['home_phone'] ?? ''),
    ], static fn (string $v): bool => trim($v) !== '');
    $email = trim((string) ($p['email'] ?? ''));
    ?>
    <style>
      .pv-copy{min-height:28px;padding:0 8px;font-size:12px}
      .pv-line{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px}
      .pv-map{width:100%;height:220px;border:0;display:block;border-radius:var(--radius,8px);margin-top:var(--sp-3,12px)}
      .pv-roles{font-size:13px;color:var(--muted,#627169)}
      .pv-danger{border-color:var(--line,#d9e4dd)}
      .pv-danger .ek-card-head h2{color:var(--rose-ink,#b84957)}
    </style>

    <?= records_notice_for($notice, $noticeMap) ?>

    <div class="rec-layout">
      <div class="rec-main">
        <section class="ek-card" aria-labelledby="pv-identity">
          <div class="ek-card-head"><h2 id="pv-identity">Identity</h2></div>
          <div class="ek-card-body">
            <div class="rec-identity">
              <div class="rec-avatar"><span aria-hidden="true"><?= $h($initials ?: '?') ?></span>
                <img src="<?= $base ?>/admin/people/photo?id=<?= $pid ?>" alt="Photo of <?= $h($name) ?>" onerror="this.remove()"></div>
              <div style="min-width:0">
                <h2><?= $h($name) ?></h2>
                <p class="rec-muted rec-small" style="margin:2px 0 0">Record #<?= $pid ?></p>
              </div>
            </div>
            <dl class="rec-dl" style="margin-top:var(--sp-4,16px)">
              <?php foreach ($otherNames as $label => $value): ?><dt><?= $h($label) ?> name</dt><dd><?= $h($value) ?></dd><?php endforeach; ?>
              <dt>Gender</dt><dd><?= $gender !== '' ? $h($gender) : '<span class="rec-muted">Not recorded</span>' ?></dd>
              <dt>Birthday</dt><dd><?= $birthday !== '' ? $h($birthday) : '<span class="rec-muted">Not recorded</span>' ?></dd>
            </dl>
          </div>
        </section>

        <section class="ek-card" aria-labelledby="pv-contact">
          <div class="ek-card-head"><h2 id="pv-contact">Contact &amp; address</h2></div>
          <div class="ek-card-body">
            <dl class="rec-dl">
              <?php foreach ($phones as $label => $num): $digits = preg_replace('/[^0-9+]/', '', $num); ?>
                <dt><?= $h($label) ?> phone</dt>
                <dd class="pv-line"><a class="rec-link" href="tel:<?= $h($digits) ?>"><?= $h($num) ?></a>
                  <?php if ($label === 'Mobile'): ?><a class="rec-link rec-small" href="sms:<?= $h($digits) ?>">Text</a><?php endif; ?>
                  <button class="ek-btn pv-copy" type="button" data-copy="<?= $h($num) ?>">Copy<span class="sr-only"> <?= $h(strtolower($label)) ?> phone</span></button></dd>
              <?php endforeach; ?>
              <dt>Email</dt>
              <dd class="pv-line"><?php if ($email !== ''): ?><a class="rec-link" href="mailto:<?= $h($email) ?>"><?= $h($email) ?></a>
                <button class="ek-btn pv-copy" type="button" data-copy="<?= $h($email) ?>">Copy<span class="sr-only"> email</span></button>
                <?php else: ?><span class="rec-muted">Not recorded</span><?php endif; ?></dd>
              <?php if ($phones === []): ?><dt>Phone</dt><dd><span class="rec-muted">Not recorded</span></dd><?php endif; ?>
              <dt>Address</dt>
              <dd><?php if ($addr !== ''): ?>
                <?= $h($addr) ?>
                <?php if (trim((string) ($p['address_line1'] ?? '')) === '' && $householdId > 0): ?><span class="rec-muted rec-small"> (household address)</span><?php endif; ?>
                <div class="pv-line rec-small" style="margin-top:4px">
                  <a class="rec-link" href="https://www.google.com/maps/search/?api=1&amp;query=<?= $mapsQ ?>" target="_blank" rel="noopener">Google Maps<span class="sr-only"> (opens in a new tab)</span></a>
                  <a class="rec-link" href="https://www.openstreetmap.org/search?query=<?= $mapsQ ?>" target="_blank" rel="noopener">OpenStreetMap<span class="sr-only"> (opens in a new tab)</span></a>
                  <?php if ($householdId > 0): ?>
                    <button type="button" class="ek-btn pv-copy" id="refreshCoordsBtn" data-family-id="<?= $householdId ?>">Refresh map location</button>
                    <span id="refreshCoordsMsg" class="rec-muted" role="status"></span>
                  <?php endif; ?>
                </div>
              <?php else: ?><span class="rec-muted">Not recorded</span><?php endif; ?></dd>
            </dl>
            <?php if ($addr !== '' && $lat !== null && $lng !== null): ?>
              <iframe class="pv-map" loading="lazy" title="Map of <?= $h($addr) ?>"
                src="https://www.openstreetmap.org/export/embed.html?bbox=<?= ($lng - 0.008) ?>%2C<?= ($lat - 0.006) ?>%2C<?= ($lng + 0.008) ?>%2C<?= ($lat + 0.006) ?>&amp;layer=mapnik&amp;marker=<?= $lat ?>%2C<?= $lng ?>"></iframe>
            <?php endif; ?>
          </div>
        </section>

        <section class="ek-card" aria-labelledby="pv-household">
          <div class="ek-card-head">
            <div><h2 id="pv-household">Household</h2>
              <p><?= $data['family'] ? $h($data['family']['name']) . ($L['family_role'] !== '' ? ' · ' . $h($L['family_role']) : '') : 'Not part of a household record.' ?></p></div>
            <?php if ($data['family']): ?><a class="ek-btn" href="<?= $base ?>/admin/families/edit?id=<?= $householdId ?>">Open household</a><?php endif; ?>
          </div>
          <div class="ek-card-body">
            <?php if (!$data['family']): ?>
              <p class="rec-muted" style="margin:0">Choose a household when you <a class="rec-link" href="<?= $base ?>/admin/people/edit?id=<?= $pid ?>">edit this record</a>, or <a class="rec-link" href="<?= $base ?>/admin/families/edit">add a household</a> first.</p>
            <?php else: ?>
              <?php if ($data['members']): ?>
                <ul class="rec-history">
                  <?php foreach ($data['members'] as $m): ?>
                    <li><span><a class="rec-link" href="<?= $base ?>/admin/people/view?id=<?= (int) $m['id'] ?>"><?= $h(trim($m['first_name'] . ' ' . $m['last_name'])) ?></a>
                      <?php if (($m['family_role'] ?? '') !== ''): ?><span class="rec-muted"> · <?= $h($m['family_role']) ?></span><?php endif; ?></span></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="rec-muted" style="margin:0">Nobody else is in this household.</p>
              <?php endif; ?>
              <?php if (!empty($relatedLinks)): ?>
                <h3 class="rec-small rec-muted" style="margin:var(--sp-4,16px) 0 4px">Related households</h3>
                <ul class="rec-history">
                  <?php foreach ($relatedLinks as $l): ?>
                    <li><span><a class="rec-link" href="<?= $base ?>/admin/families/edit?id=<?= (int) $l['other'] ?>"><?= $h($l['name']) ?></a> <span class="rec-muted">· <?= $h($l['label']) ?></span></span></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <?php if (!empty($residenceMates)): ?>
                <h3 class="rec-small rec-muted" style="margin:var(--sp-4,16px) 0 4px">Same address (a suggestion, not a link)</h3>
                <ul class="rec-history">
                  <?php foreach ($residenceMates as $m): ?>
                    <li><span><a class="rec-link" href="<?= $base ?>/admin/families/edit?id=<?= (int) $m['id'] ?>"><?= $h($m['name']) ?></a> <span class="rec-muted">· <?= (int) $m['members'] ?> <?= (int) $m['members'] === 1 ? 'person' : 'people' ?></span></span></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </section>

        <section class="ek-card" aria-labelledby="pv-ministries">
          <div class="ek-card-head">
            <div><h2 id="pv-ministries">Ministries &amp; positions</h2>
              <p>Where <?= $h($p['first_name'] ?: $name) ?> serves. Membership is managed in Ministries.</p></div>
            <a class="ek-btn" href="<?= $base ?>/ministries/members-and-leaders">Manage members &amp; leaders</a>
          </div>
          <div class="ek-card-body">
            <?php if ($ministries === []): ?>
              <p class="rec-muted" style="margin:0">Not a member of any ministry.</p>
            <?php else: ?>
              <ul class="rec-history">
                <?php foreach ($ministries as $m): ?>
                  <li><span><a class="rec-link" href="<?= $base ?>/ministries/<?= (int) $m['ministry_id'] ?>"><?= $h($m['name']) ?></a>
                    <?php if ($m['is_leader']): ?> <span class="ek-badge is-ok">Leader</span><?php endif; ?></span>
                    <?php if ($m['roles'] !== ''): ?><span class="pv-roles"><?= $h($m['roles']) ?></span><?php endif; ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </section>

        <section class="ek-card" aria-labelledby="pv-history">
          <div class="ek-card-head">
            <div><h2 id="pv-history">Recent history</h2>
              <p><?= (int) $recentHistory['total'] === 0 ? 'No recorded changes.' : 'The latest ' . min(5, (int) $recentHistory['total']) . ' of ' . (int) $recentHistory['total'] . ' recorded ' . ((int) $recentHistory['total'] === 1 ? 'change' : 'changes') . '.' ?></p></div>
            <?php if ((int) $recentHistory['total'] > 0): ?><a class="ek-btn" href="<?= $base ?>/people/history?person=<?= $pid ?>">All history</a><?php endif; ?>
          </div>
          <div class="ek-card-body">
            <?= records_history_list($recentHistory['entries'], 'Changes made in Member records will be listed here.') ?>
          </div>
        </section>
      </div>

      <aside class="rec-aside" aria-label="Membership and access">
        <section class="ek-card" aria-labelledby="pv-membership">
          <div class="ek-card-head"><h2 id="pv-membership">Membership</h2></div>
          <div class="ek-card-body">
            <dl class="rec-dl">
              <dt>Status</dt><dd><?= $L['classification'] !== '' ? $h($L['classification']) : '<span class="rec-muted">Not set</span>' ?></dd>
              <dt>Member type</dt><dd><?= $L['member_type'] !== '' ? '<span class="ek-badge">' . $h($L['member_type']) . '</span>' : '<span class="rec-muted">Not set</span>' ?></dd>
              <dt>Campus</dt><dd><?= $data['affiliations'] ? $h($data['affiliations'][0]['name']) : '<span class="rec-muted">Not set</span>' ?></dd>
              <dt>Member since</dt><dd><?= $membershipSince !== '' ? $h($membershipSince) : '<span class="rec-muted">Not recorded</span>' ?></dd>
            </dl>
          </div>
        </section>

        <section class="ek-card" aria-labelledby="pv-logins">
          <div class="ek-card-head"><div><h2 id="pv-logins">Portal logins</h2><p>Accounts linked to this person. Managed in Users &amp; access.</p></div></div>
          <div class="ek-card-body">
            <?php if ($logins === []): ?>
              <p class="rec-muted" style="margin:0 0 8px">No login is linked to this person.</p>
              <a class="rec-link rec-small" href="<?= $base ?>/admin/users">Open Users &amp; access</a>
            <?php else: ?>
              <ul class="rec-history">
                <?php foreach ($logins as $u): $roles = array_values(array_unique(array_map(static fn (array $r): string => ucfirst((string) $r['role']), $u['roles']))); ?>
                  <li><span><a class="rec-link" href="<?= $base ?>/admin/users?user_id=<?= (int) $u['id'] ?>"><?= $h($u['email']) ?></a>
                    <?php if (!$u['is_active']): ?> <span class="ek-badge is-warn">Inactive</span><?php endif; ?></span>
                    <span class="rec-muted rec-small"><?= $roles !== [] ? $h(implode(', ', $roles)) : 'No role' ?> ·
                      <?= $u['last_login_at'] ? 'last signed in ' . $h(records_when((string) $u['last_login_at'], false)) : 'never signed in' ?></span></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </section>

        <section class="ek-card pv-danger" aria-labelledby="pv-delete">
          <div class="ek-card-head"><div><h2 id="pv-delete">Delete record</h2><p>Removes the person, their member type and campus. It cannot be undone.</p></div></div>
          <div class="ek-card-body">
            <form method="post" action="<?= $base ?>/admin/people/delete" onsubmit="return confirm('Delete <?= $h(addslashes($name)) ?>? This removes the person, their member type and campus links. This cannot be undone.');">
              <input type="hidden" name="id" value="<?= $pid ?>">
              <button type="submit" class="ek-btn ek-btn-danger">Delete <?= $h($name) ?></button>
            </form>
          </div>
        </section>
      </aside>
    </div>

    <script>
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-copy]');
      if (!b) return;
      if (navigator.clipboard) navigator.clipboard.writeText(b.getAttribute('data-copy'));
      var t = b.innerHTML; b.textContent = 'Copied'; setTimeout(function () { b.innerHTML = t; }, 1200);
    });

    // Refresh the household's map location from its address, then reload to show the map.
    (function () {
      var btn = document.getElementById('refreshCoordsBtn');
      if (!btn) return;
      var msg = document.getElementById('refreshCoordsMsg');
      var base = <?= json_encode($basePath) ?>;
      btn.addEventListener('click', function () {
        btn.disabled = true;
        var label = btn.innerHTML;
        btn.textContent = 'Looking up…';
        if (msg) { msg.textContent = ''; }
        var body = new URLSearchParams(); body.set('family_id', btn.getAttribute('data-family-id'));
        fetch(base + '/admin/people/geocode-family', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (res.ok && res.j && res.j.success) {
              if (msg) { msg.textContent = 'Location updated.'; }
              setTimeout(function () { location.reload(); }, 900);
            } else {
              btn.disabled = false; btn.innerHTML = label;
              if (msg) { msg.textContent = (res.j && res.j.error) || 'Could not find that address on the map.'; }
            }
          }).catch(function () {
            btn.disabled = false; btn.innerHTML = label;
            if (msg) { msg.textContent = 'Network error. Try again.'; }
          });
      });
    })();
    </script>
    <?php
}
$content = (string) ob_get_clean();

$title = $isAdmin && $data !== null
    ? (trim(($data['person']['first_name'] ?? '') . ' ' . ($data['person']['last_name'] ?? '')) ?: 'Person #' . (int) $data['person']['id'])
    : 'Person record';
echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'people',
    'pageTitle' => $title . ' · Member records', 'pageSubtitle' => 'Person record.',
    'sectionTitle' => $title,
    'sectionDescription' => $isAdmin && $data !== null ? 'Member record: identity, contact, household, membership, ministries and history.' : '',
    'headerActions' => $headerActions,
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
