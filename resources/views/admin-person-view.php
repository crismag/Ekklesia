<?php
/**
 * Admin · Person Profile — read view with photo, quick info, contact (call/text/
 * copy), address map, campus, family members, and an actions menu.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,mixed>|null $data
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

ob_start();

if ($data === null) {
    echo '<article class="admin-card"><div class="admin-card-body">Person not found. <a href="' . $base . '/admin/people">Back to list</a></div></article>';
} else {
    $p = $data['person'];
    $pid = (int) $p['id'];
    $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    $initials = strtoupper(substr((string) ($p['first_name'] ?? ''), 0, 1) . substr((string) ($p['last_name'] ?? ''), 0, 1));
    $gender = ($p['gender'] ?? null) === 'male' ? 'Male' : (($p['gender'] ?? null) === 'female' ? 'Female' : '');
    $L = $data['labels'];
    $addr = (string) $data['address_line'];
    $lat = $data['lat']; $lng = $data['lng'];
    $mapsQ = rawurlencode($addr);
    $noticeMap = ['saved' => ['ok', 'Person saved.'], 'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.']];
    $membershipSince = !empty($p['member_since']) && strtotime((string) $p['member_since'])
        ? date('M j, Y', (int) strtotime((string) $p['member_since'])) : '';
    ?>
    <style>
      .pv-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
      .pv-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}.pv-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
      .pv-top{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 14px}
      .pv-btn{font:inherit;font-size:13px;font-weight:700;border:1px solid var(--line,#c7d4cd);background:#fff;border-radius:8px;padding:8px 14px;cursor:pointer;text-decoration:none;color:var(--ink,#1b2a24)}
      .pv-btn:hover{border-color:#137a5f}.pv-btn.primary{background:#0c5a45;border-color:#0c5a45;color:#fff}.pv-btn.danger{color:#b3261e;border-color:#f0c3bd}
      .pv-menu{position:relative;display:inline-block}
      .pv-menu>summary{list-style:none;cursor:pointer}.pv-menu>summary::-webkit-details-marker{display:none}
      .pv-menu[open]>.pv-drop{display:block}
      .pv-drop{display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:20;background:#fff;border:1px solid var(--line,#dbe4ec);border-radius:10px;box-shadow:0 12px 30px rgba(15,40,30,.14);min-width:190px;padding:6px}
      .pv-drop a,.pv-drop button{display:flex;width:100%;gap:8px;align-items:center;padding:8px 10px;border:0;background:none;font:inherit;font-size:13px;text-align:left;color:var(--ink,#1b2a24);text-decoration:none;border-radius:7px;cursor:pointer}
      .pv-drop a:hover,.pv-drop button:hover{background:#f2f7f4}.pv-drop .danger{color:#b3261e}
      .pv-grid{display:grid;grid-template-columns:320px 1fr;gap:14px}
      @media (max-width:760px){.pv-grid{grid-template-columns:1fr}}
      .pv-card{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:12px;overflow:hidden}
      .pv-namecard{display:flex}
      .pv-photo{width:120px;height:120px;flex:0 0 120px;background:var(--soft,#eef4f0);display:flex;align-items:center;justify-content:center;position:relative}
      .pv-photo img{width:100%;height:100%;object-fit:cover}
      .pv-photo .ini{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:38px;font-weight:800;color:#0c5a45}
      .pv-nameinfo{padding:12px 14px;min-width:0}
      .pv-nameinfo h2{margin:0 0 8px;font-size:19px}
      .pv-info{list-style:none;margin:0;padding:0;font-size:13px}
      .pv-info li{display:flex;gap:8px;margin:3px 0;color:var(--ink,#1b2a24)}
      .pv-info .k{color:var(--muted,#5c6b63);min-width:96px}
      .pv-sec{padding:12px 14px}.pv-sec+.pv-sec{border-top:1px solid var(--line,#eef2f5)}
      .pv-sec h4{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63)}
      .pv-line{display:flex;align-items:center;gap:8px;font-size:13.5px;margin:5px 0;flex-wrap:wrap}
      .pv-line a{color:var(--blue-ink,#0f4e97);text-decoration:none}.pv-line a:hover{text-decoration:underline}
      .pv-mini{border:1px solid var(--line,#c7d4cd);background:#fff;border-radius:6px;padding:2px 7px;font-size:12px;cursor:pointer;color:var(--muted)}
      .pv-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700;background:var(--soft,#eef4f0);color:#0c5a45}
      .pv-badge.pri{background:#0c5a45;color:#fff}
      .pv-map{width:100%;height:220px;border:0;display:block}
      table.pv-fam{width:100%;border-collapse:collapse;font-size:13px}
      table.pv-fam td{padding:6px 8px;border-bottom:1px solid var(--line,#eef2f5)}
      table.pv-fam tr:last-child td{border-bottom:0}
      .muted{color:var(--muted,#5c6b63)}
    </style>

    <?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
      <div class="pv-alert <?= $cls ?>"><?= $h($msg) ?></div>
    <?php endif; ?>

    <div class="pv-top">
      <a class="pv-btn" href="<?= $base ?>/admin/people">&larr; Member records</a>
      <span style="flex:1"></span>
      <?php if ($isAdmin): ?>
        <a class="pv-btn primary" href="<?= $base ?>/admin/people/edit?id=<?= $pid ?>">Edit</a>
        <details class="pv-menu">
          <summary class="pv-btn">Actions &#9662;</summary>
          <div class="pv-drop">
            <a href="<?= $base ?>/admin/people/edit?id=<?= $pid ?>">&#9998; Edit person</a>
            <a href="<?= $base ?>/people/<?= $pid ?>" target="_blank">&#128065; View public profile</a>
            <?php if ((int) ($p['household_id'] ?? 0) > 0): ?>
              <a href="<?= $base ?>/admin/people/edit?id=<?= $pid ?>">&#128106; Change family / role</a>
            <?php endif; ?>
            <a href="<?= $base ?>/admin/people/photo?id=<?= $pid ?>" target="_blank">&#128247; View photo</a>
            <form method="post" action="<?= $base ?>/admin/people/delete" onsubmit="return confirm('Delete <?= $h($name) ?>? This removes the person, their member type and campus links. This cannot be undone.');">
              <input type="hidden" name="id" value="<?= $pid ?>">
              <button type="submit" class="danger">&#128465; Delete person</button>
            </form>
          </div>
        </details>
      <?php endif; ?>
    </div>

    <div class="pv-grid">
      <!-- Left column: name card + quick info -->
      <div>
        <div class="pv-card pv-namecard">
          <div class="pv-photo">
            <span class="ini"><?= $h($initials) ?></span>
            <img src="<?= $base ?>/admin/people/photo?id=<?= $pid ?>" alt="" onerror="this.style.display='none'">
          </div>
          <div class="pv-nameinfo">
            <h2><?= $h($name) ?></h2>
            <ul class="pv-info">
              <?php if ($gender !== ''): ?><li><span class="k">Gender</span><span><?= $h($gender) ?></span></li><?php endif; ?>
              <?php if ($L['classification'] !== ''): ?><li><span class="k">Classification</span><span><?= $h($L['classification']) ?></span></li><?php endif; ?>
              <?php if ($L['member_type'] !== ''): ?><li><span class="k">Member type</span><span class="pv-badge"><?= $h($L['member_type']) ?></span></li><?php endif; ?>
              <?php if ($L['family_role'] !== ''): ?><li><span class="k">Family role</span><span><?= $h($L['family_role']) ?></span></li><?php endif; ?>
              <?php if ($membershipSince !== ''): ?><li><span class="k">Member since</span><span><?= $h($membershipSince) ?></span></li><?php endif; ?>
              <?php if ((int) ($p['birth_month'] ?? 0) > 0): ?><li><span class="k">Birthday</span><span><?= $h(date('M j', mktime(0, 0, 0, (int) $p['birth_month'], (int) $p['birth_day'] ?: 1))) ?><?= (int) ($p['birth_year'] ?? 0) > 0 ? ', ' . (int) $p['birth_year'] : '' ?></span></li><?php endif; ?>
            </ul>
          </div>
        </div>

        <!-- Campus affiliations -->
        <?php if ($data['affiliations']): ?>
          <div class="pv-card" style="margin-top:12px"><div class="pv-sec">
            <h4>Campus</h4>
            <?php foreach ($data['affiliations'] as $a): ?>
              <span class="pv-badge <?= (int) $a['is_primary'] === 1 ? 'pri' : '' ?>"><?= $h($a['name']) ?><?= (int) $a['is_primary'] === 1 ? ' ★' : '' ?></span>
            <?php endforeach; ?>
          </div></div>
        <?php endif; ?>
      </div>

      <!-- Right column: contact, address/map, family -->
      <div>
        <div class="pv-card">
          <div class="pv-sec">
            <h4>Contact</h4>
            <?php
            $phones = array_filter([
                'Mobile' => $p['mobile_phone'] ?? '', 'Home' => $p['home_phone'] ?? '',
            ], static fn ($v) => trim((string) $v) !== '');
            $emails = array_filter(['' => $p['email'] ?? ''], static fn ($v) => trim((string) $v) !== '');
            ?>
            <?php if (!$phones && !$emails): ?><span class="muted">No contact details.</span><?php endif; ?>
            <?php foreach ($phones as $label => $num): $digits = preg_replace('/[^0-9+]/', '', (string) $num); ?>
              <div class="pv-line">
                <a href="tel:<?= $h($digits) ?>"><?= $h($num) ?></a>
                <?php if ($label === 'Mobile'): ?><a href="sms:<?= $h($digits) ?>" class="muted" title="Text">&#128172;</a><?php endif; ?>
                <button class="pv-mini" type="button" data-copy="<?= $h($num) ?>">copy</button>
                <span class="muted">(<?= $h($label) ?>)</span>
              </div>
            <?php endforeach; ?>
            <?php foreach ($emails as $label => $em): ?>
              <div class="pv-line">
                <a href="mailto:<?= $h($em) ?>"><?= $h($em) ?></a>
                <button class="pv-mini" type="button" data-copy="<?= $h($em) ?>">copy</button>
                <?php if ($label !== ''): ?><span class="muted">(<?= $h($label) ?>)</span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($addr !== ''): ?>
          <div class="pv-sec">
            <h4>Address</h4>
            <div class="pv-line"><?= $h($addr) ?></div>
            <div class="pv-line">
              <a href="https://www.google.com/maps/search/?api=1&query=<?= $mapsQ ?>" target="_blank" rel="noopener">Open in Google Maps</a>
              <a href="https://www.openstreetmap.org/search?query=<?= $mapsQ ?>" target="_blank" rel="noopener">OpenStreetMap</a>
              <?php if ($isAdmin && (int) ($p['household_id'] ?? 0) > 0): ?>
                <button type="button" class="pv-mini" id="refreshCoordsBtn" data-family-id="<?= (int) $p['household_id'] ?>"
                        title="Look up latitude/longitude from this address">&#10227; Refresh coordinates</button>
                <span id="refreshCoordsMsg" class="muted"></span>
              <?php endif; ?>
            </div>
            <?php if ($lat !== null && $lng !== null): ?>
              <iframe class="pv-map" loading="lazy" title="Map"
                src="https://www.openstreetmap.org/export/embed.html?bbox=<?= ($lng - 0.008) ?>%2C<?= ($lat - 0.006) ?>%2C<?= ($lng + 0.008) ?>%2C<?= ($lat + 0.006) ?>&layer=mapnik&marker=<?= $lat ?>%2C<?= $lng ?>"></iframe>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($data['family']): ?>
          <div class="pv-card" style="margin-top:12px"><div class="pv-sec">
            <h4>Family — <?= $h($data['family']['name']) ?></h4>
            <?php if ($data['members']): ?>
              <table class="pv-fam"><tbody>
                <?php foreach ($data['members'] as $m): ?>
                  <tr>
                    <td><a href="<?= $base ?>/admin/people/view?id=<?= (int) $m['id'] ?>"><?= $h(trim($m['first_name'] . ' ' . $m['last_name'])) ?></a></td>
                    <td class="muted"><?= $h($m['family_role'] ?? '') ?></td>
                    <td class="muted"><?= $m['email'] ? '<a href="mailto:' . $h($m['email']) . '">' . $h($m['email']) . '</a>' : '' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table>
            <?php else: ?><span class="muted">No other family members.</span><?php endif; ?>

            <?php if (!empty($relatedLinks)): ?>
              <div style="margin-top:12px"><b style="font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted)">Related families</b>
                <?php foreach ($relatedLinks as $l): ?>
                  <div class="pv-line"><a href="<?= $base ?>/admin/families/edit?id=<?= (int) $l['other'] ?>"><?= $h($l['name']) ?></a> <span class="muted">— <?= $h($l['label']) ?></span></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($residenceMates)): ?>
              <div style="margin-top:12px"><b style="font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted)">Same residence <span style="font-weight:400;text-transform:none">(suggested)</span></b>
                <?php foreach ($residenceMates as $m): ?>
                  <div class="pv-line"><a href="<?= $base ?>/admin/families/edit?id=<?= (int) $m['id'] ?>"><?= $h($m['name']) ?></a> <span class="muted">— <?= (int) $m['members'] ?> member<?= (int) $m['members'] === 1 ? '' : 's' ?></span></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div></div>
        <?php endif; ?>
      </div>
    </div>

    <script>
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-copy]');
      if (b) { navigator.clipboard && navigator.clipboard.writeText(b.getAttribute('data-copy')); var t = b.textContent; b.textContent = 'copied'; setTimeout(function () { b.textContent = t; }, 1200); return; }
      var m = document.querySelector('details.pv-menu[open]');
      if (m && !e.target.closest('details.pv-menu')) m.removeAttribute('open');
    });

    // Refresh coordinates: geocode the family address and reload to show the map.
    (function () {
      var btn = document.getElementById('refreshCoordsBtn');
      if (!btn) return;
      var msg = document.getElementById('refreshCoordsMsg');
      var base = <?= json_encode($base) ?>;
      btn.addEventListener('click', function () {
        var famId = btn.getAttribute('data-family-id');
        btn.disabled = true;
        var label = btn.innerHTML;
        btn.textContent = 'Refreshing…';
        if (msg) { msg.style.color = ''; msg.textContent = ''; }
        var body = new URLSearchParams(); body.set('family_id', famId);
        fetch(base + '/admin/people/geocode-family', {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (res.ok && res.j && res.j.success) {
              btn.textContent = '✓ Updated';
              if (msg) { msg.style.color = '#1a7a3a'; msg.textContent = 'Coordinates updated.'; }
              setTimeout(function () { location.reload(); }, 900);
            } else {
              btn.disabled = false; btn.innerHTML = label;
              if (msg) { msg.style.color = '#b3261e'; msg.textContent = (res.j && res.j.error) || 'Could not geocode.'; }
            }
          }).catch(function () {
            btn.disabled = false; btn.innerHTML = label;
            if (msg) { msg.style.color = '#b3261e'; msg.textContent = 'Network error.'; }
          });
      });
    })();
    </script>
    <?php
}
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'people',
    'pageTitle' => 'Person · Admin', 'pageSubtitle' => 'Profile.',
    'sectionTitle' => $data !== null ? trim(($data['person']['first_name'] ?? '') . ' ' . ($data['person']['last_name'] ?? '')) : 'Person',
    'sectionDescription' => 'Profile, contact, campus, and family.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
