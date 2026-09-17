<?php
/**
 * Admin · People — dashboard + searchable/filterable people list.
 * Data via PersonAdminService (shared DB, direct-PDO). No ChurchCRM dependency.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array<string,mixed> $stats
 * @var list<array<string,mixed>> $people
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var array<string,mixed> $filters
 * @var list<array{id:int,name:string}> $classifications
 * @var list<array{campus_id:int,campus_name:string}> $campuses
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$pages = (int) max(1, ceil($total / max(1, $perPage)));
$q = (string) $filters['search'];

$noticeMap = ['saved' => ['ok', 'Person saved.'], 'deleted' => ['ok', 'Person deleted.'], 'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.']];

// Campus is deliberately absent from these links. It comes from the header
// selector, and pinning it into the URL would freeze page 2 onto whichever
// campus was chosen when page 1 was rendered.
$pageUrl = static function (int $p) use ($base, $q, $filters): string {
    $qs = http_build_query(array_filter([
        'q' => $q, 'cls' => (int) $filters['classification'] ?: null,
        'type' => (int) $filters['member_type'] ?: null,
        'page' => $p > 1 ? $p : null,
    ]));
    return $base . '/admin/people' . ($qs ? '?' . $qs : '');
};

// Short codes for the two narrowest-value columns. Derived from the values on
// this page rather than from a fixed table, because both lists are edited by
// administrators and a hardcoded map would go stale the moment one changes.
$abbr = new \App\Services\LabelAbbreviator();
$clsCodes = $abbr->map(array_values(array_filter(array_map(
    static fn (array $row): string => (string) ($row['classification'] ?? ''),
    $people,
))));
$typeCodes = $abbr->map(array_values(array_filter(array_map(
    static fn (array $row): string => (string) ($row['member_type'] ?? ''),
    $people,
))));

$typeIcons = \App\Services\MemberTypeIcons::fromFile(dirname(__DIR__, 2) . '/config/member-type-icons.json');
$clsStatus = \App\Services\ClassificationStatus::fromFile(dirname(__DIR__, 2) . '/config/classification-status.json');

/**
 * A code that can always be expanded: title for hover, and the full label for
 * assistive technology. A short form nobody can read back is not a saving.
 */
$codeCell = static function (string $value, array $codes, string $class = '') use ($h): string {
    if (trim($value) === '') {
        return '<span class="muted">—</span>';
    }
    $code = $codes[$value] ?? mb_substr($value, 0, 2);

    return '<span class="pp-code ' . $class . '" title="' . $h($value) . '">'
        . '<span aria-hidden="true">' . $h($code) . '</span>'
        . '<span class="sr-only">' . $h($value) . '</span></span>';
};

/**
 * A classification as a coloured circle carrying its short code.
 *
 * Green for attending, orange for not, neutral for a guest or for a
 * classification nobody has mapped. The code inside is what keeps this from
 * depending on colour: the circle is still readable in greyscale, in print,
 * and to anyone who does not distinguish the two hues.
 */
$clsDot = static function (string $value, array $codes) use ($h, $clsStatus): string {
    if (trim($value) === '') {
        return '<span class="muted">—</span>';
    }
    $status = $clsStatus->statusFor($value);
    $code = $codes[$value] ?? mb_substr($value, 0, 2);

    return '<span class="pp-dot pp-dot-' . $h($status) . '" title="' . $h($value)
        . ' — ' . $h($clsStatus->statusLabel($status)) . '">'
        . '<span aria-hidden="true">' . $h($code) . '</span>'
        . '<span class="sr-only">' . $h($value) . '</span></span>';
};

/**
 * The member type as its assigned symbol.
 *
 * Falls back to the text code for a type nobody has mapped, so adding a member
 * type degrades to the previous behaviour instead of leaving a blank cell.
 */
$typeCell = static function (string $value, array $codes) use ($h, $typeIcons, $codeCell): string {
    if (trim($value) === '') {
        return '<span class="muted">—</span>';
    }
    $symbol = $typeIcons->symbolFor($value);
    if ($symbol === null) {
        return $codeCell($value, $codes, 'is-type');
    }

    return '<span class="pp-sym pp-sym-' . $h($symbol) . '" title="' . $h($value) . '">'
        . $typeIcons->svg($symbol)
        . '<span class="sr-only">' . $h($value) . '</span></span>';
};

ob_start();
?>
<style>
  .pp-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .pp-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}.pp-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  .pp-stats{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 14px}
  .pp-stat{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;padding:12px 16px;min-width:120px}
  .pp-stat b{display:block;font-size:22px}.pp-stat span{color:var(--muted,#5c6b63);font-size:12px}
  .pp-cols{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:0 0 16px}
  @media (max-width:720px){.pp-cols{grid-template-columns:1fr}}
  .pp-breakdown{background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px;padding:12px 14px}
  .pp-breakdown h4{margin:0 0 8px;font-size:13px}
  .pp-bar{display:flex;align-items:center;gap:8px;font-size:12.5px;margin:3px 0}
  .pp-bar .track{flex:1;height:8px;background:#eef2f5;border-radius:999px;overflow:hidden}
  /* display:block matters: .fill is a span, and the track is not a flex
     container, so the inline width was being ignored outright. These bars
     have been drawing an empty track the whole time — visible now only
     because the fills carry distinct colours. */
  .pp-bar .fill{display:block;height:100%;background:#137a5f;border-radius:999px}
  .pp-bar .n{width:40px;text-align:right;font-variant-numeric:tabular-nums;color:var(--muted)}
  .pp-actions{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}
  .pp-btn{font:inherit;font-size:13px;font-weight:700;border:1px solid var(--line,#c7d4cd);background:#fff;border-radius:8px;padding:8px 14px;cursor:pointer;text-decoration:none;color:var(--ink,#1b2a24)}
  .pp-btn:hover{border-color:#137a5f}.pp-btn.primary{background:#0c5a45;border-color:#0c5a45;color:#fff}
  form.pp-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:0 0 12px}
  form.pp-filters input,form.pp-filters select{font:inherit;padding:8px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff}
  .pp-scroll{overflow-x:auto;position:relative}
  /* border-collapse:separate, not collapse: Chromium ignores position:sticky
     on table cells in a collapsed-border table, which is what kept the
     pinned Edit column below from working. border-spacing:0 keeps the
     same appearance, and overflow:hidden is dropped because it would clip
     the sticky column out of existence. */
  table.pp{width:100%;border-collapse:separate;border-spacing:0;font-size:13.5px;background:var(--surface,#fff);border:1px solid var(--line,#dbe4ec);border-radius:10px}
  table.pp th,table.pp td{padding:9px 11px;text-align:left;border-bottom:1px solid var(--line,#eef2f5);white-space:nowrap}
  table.pp th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted,#5c6b63);background:var(--surface-2,#f8fafb)}
  /* The person's name is the way into their record — fifty of them per page,
     each previously an 18px-tall inline link carrying its colour in a style
     attribute. Padded to a real target (WCAG 2.5.8) without changing row
     density: the cell padding above already reserves the height. */
  /* Edit stays reachable. Even compressed, the table is wider than a phone,
     and the reported problem was the Edit button sitting off the right edge
     with no sign it was there. Pinning the last column to the edge of the
     scroll container means it is always on screen, whatever the width. */
  table.pp th:last-child,table.pp td:last-child{position:sticky;right:0;z-index:1;
    background:var(--surface,#fff);box-shadow:-6px 0 6px -6px rgba(0,0,0,.22)}
  table.pp thead th:last-child{background:var(--surface-2,#f8fafb);z-index:2}
  /* Two fixed narrow columns. The Edit button was being pushed off the right
     edge of the screen by Classification and Member type spelled out, which
     between them took more width than the person's name. */
  table.pp th.col-code,table.pp td.col-code{width:1%;white-space:nowrap;text-align:center;padding-left:6px;padding-right:6px}
  .pp-code{display:inline-block;min-width:2.4em;padding:2px 6px;border-radius:999px;
    font-size:11px;font-weight:800;letter-spacing:.02em;
    background:var(--soft,#eef4f0);color:var(--deep,#0c5a45);cursor:help}
  .pp-code.is-type{background:#e8eef6;color:#254d74}
  /* Member type symbols. Each type gets a distinct shape; the colour is a
     second cue, never the only one, and the name is on hover and in the key. */
  .pp-sym{display:inline-flex;align-items:center;justify-content:center;
    width:26px;height:26px;border-radius:999px;cursor:help;
    background:var(--soft,#eef4f0);color:var(--deep,#0c5a45)}
  /* Classification circles. The colour says attending or not; the code inside
     says which classification, so the mark still works in greyscale, in print,
     and for anyone who does not separate green from orange. */
  .pp-dot{display:inline-flex;align-items:center;justify-content:center;
    width:26px;height:26px;border-radius:50%;font-size:10.5px;font-weight:800;
    letter-spacing:.01em;cursor:help;border:1.5px solid transparent}
  .pp-dot-active{background:#e3f5ea;color:#12633f;border-color:#9bd4b4}
  .pp-dot-prospective{background:#e8eef6;color:#22497a;border-color:#a9c2e0}
  .pp-dot-inactive{background:#fdeadd;color:#9a4a10;border-color:#f0b98c}
  .pp-dot-unknown{background:#eef1f3;color:#5a6976;border-color:#ccd5db}
  /* The two breakdown cards double as the key, so their rows pair each mark
     with the name it stands for. */
  .pp-legendkey{display:inline-flex;align-items:center;gap:8px;width:190px;flex:0 0 190px}
  .pp-legendname{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .pp-legendnote{font-weight:400;color:var(--muted,#5c6b63);font-size:12px}
  .pp-bar .fill-active{background:#2f9367}
  .pp-bar .fill-inactive{background:#d97a2b}
  .pp-bar .fill-prospective{background:#3f6fae}
  .pp-bar .fill-unknown{background:#9aa7b0}
  .pp-sym-arrow{background:#e8eef6;color:#254d74}
  .pp-sym-flag{background:#fdefe0;color:#8a4b12}
  .pp-sym-torch{background:#fdefe0;color:#8a4b12}
  .pp-sym-seedling{background:#e7f4ea;color:#1f6b3c}
    font-size:13px;font-weight:700;color:var(--muted,#5c6b63)}
  .pp-key-body{display:flex;flex-wrap:wrap;gap:6px 18px;padding:0 12px 12px;font-size:13px}
  .pp-key-body span{display:inline-flex;align-items:center;gap:6px}
  .pp-name{display:inline-block;padding:3px 2px;min-height:24px;line-height:18px;
    color:var(--blue-ink,#0f4e97);text-decoration:none;border-radius:4px}
  .pp-name.is-last{font-weight:700}
  .pp-name:hover,.pp-name:focus-visible{text-decoration:underline}
  table.pp tr:last-child td{border-bottom:0}
  .pp-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700;background:var(--soft,#eef4f0);color:#0c5a45}
  .pp-pager{display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px}
  .muted{color:var(--muted,#5c6b63)}
  .pp-colvis{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:0 4px;font-size:13px;font-weight:600;cursor:pointer;user-select:none;min-height:44px;display:inline-flex;align-items:center;gap:8px}
  .pp-colvis input{width:16px;height:16px}
  table.pp:not(.show-campus) .col-campus{display:none}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="pp-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<div class="pp-stats">
  <div class="pp-stat"><b><?= (int) $stats['people'] ?></b><span>People</span></div>
  <div class="pp-stat"><b><?= (int) $stats['activeFamilies'] ?></b><span>Families</span></div>
  <div class="pp-stat"><b><?= array_sum(array_map(static fn ($m) => $m['count'], $stats['memberTypes'])) ?></b><span>Typed members</span></div>
</div>

<div class="pp-actions">
  <?php if ($isAdmin): ?><a class="pp-btn primary" href="<?= $base ?>/admin/people/edit">&#43; Add person</a><?php endif; ?>
  <?php if ($isAdmin): ?><a class="pp-btn" href="<?= $base ?>/admin/maintenance">Maintenance</a><?php endif; ?>
  <a class="pp-btn" href="<?= $base ?>/admin/campuses">Campuses</a>
  <a class="pp-btn" href="<?= $base ?>/people" target="_blank">Public directory &#8599;</a>
</div>

<?php
  // These two cards are the key. Every mark used in the table appears here
  // beside the name it stands for and the number of people it covers, so the
  // page teaches its own shorthand instead of asking anyone to remember it.
  // The abbreviations are built from the full lists rather than from the
  // current page, so the key stays complete when a filter narrows the table.
  $allClsCodes = $abbr->map(array_map(static fn (array $c): string => (string) $c['name'], $stats['classifications']));
  $allTypeCodes = $abbr->map(array_map(static fn (array $m): string => (string) $m['name'], $stats['memberTypes']));
?>
<div class="pp-cols">
  <div class="pp-breakdown"><h4>By classification <span class="pp-legendnote">— what the circles mean</span></h4>
    <?php foreach ($stats['classifications'] as $c):
      $pct = $stats['people'] > 0 ? round($c['count'] / $stats['people'] * 100) : 0;
      $name = (string) $c['name'];
      $status = $clsStatus->statusFor($name);
    ?>
      <div class="pp-bar">
        <span class="pp-legendkey">
          <span class="pp-dot pp-dot-<?= $h($status) ?>" aria-hidden="true"><span><?= $h($allClsCodes[$name] ?? mb_substr($name, 0, 2)) ?></span></span>
          <span class="pp-legendname"><?= $h($name) ?></span>
        </span>
        <span class="track"><?php if ((int) $c['count'] > 0): ?><span class="fill fill-<?= $h($status) ?>" style="width:<?= max(2, $pct) ?>%"></span><?php endif; ?></span><span class="n"><?= (int) $c['count'] ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="pp-breakdown"><h4>By member type <span class="pp-legendnote">— what the symbols mean</span></h4>
    <?php foreach ($stats['memberTypes'] as $m):
      $pct = $stats['people'] > 0 ? round($m['count'] / $stats['people'] * 100) : 0;
      $name = (string) $m['name'];
      $sym = $typeIcons->symbolFor($name);
    ?>
      <div class="pp-bar">
        <span class="pp-legendkey">
          <?php if ($sym !== null): ?>
            <span class="pp-sym pp-sym-<?= $h($sym) ?>" aria-hidden="true"><?= $typeIcons->svg($sym) ?></span>
          <?php else: ?>
            <span class="pp-code is-type" aria-hidden="true"><?= $h($allTypeCodes[$name] ?? mb_substr($name, 0, 2)) ?></span>
          <?php endif; ?>
          <span class="pp-legendname"><?= $h($name) ?></span>
        </span>
        <span class="track"><?php if ((int) $m['count'] > 0): ?><span class="fill" style="width:<?= max(2, $pct) ?>%"></span><?php endif; ?></span><span class="n"><?= (int) $m['count'] ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<article class="admin-card">
  <div class="admin-card-head"><div><h2>People</h2><p><?= (int) $total ?> match<?= $total === 1 ? '' : 'es' ?>.</p></div></div>
  <div class="admin-card-body">
    <form class="pp-filters" method="get" action="<?= $base ?>/admin/people">
      <div><label class="muted" for="pp_q" style="display:block;font-size:11px">Search</label><input id="pp_q" type="search" name="q" value="<?= $h($q) ?>" placeholder="name or email"></div>
      <div><label class="muted" for="pp_cls" style="display:block;font-size:11px">Classification</label>
        <select id="pp_cls" name="cls"><option value="">All</option>
          <?php foreach ($classifications as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $filters['classification'] === (int) $c['id'] ? ' selected' : '' ?>><?= $h($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="muted" for="pp_type" style="display:block;font-size:11px">Member type</label>
        <select id="pp_type" name="type"><option value="">All</option>
          <option value="-1"<?= (int) $filters['member_type'] === -1 ? ' selected' : '' ?>>Not set</option>
          <?php foreach ($memberTypes as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $filters['member_type'] === (int) $t['id'] ? ' selected' : '' ?>><?= $h($t['name']) ?></option><?php endforeach; ?>
        </select></div>
      <button class="pp-btn primary" type="submit">Filter</button>
      <?php if ($q !== '' || $filters['classification'] || $filters['member_type']): ?><a class="pp-btn" href="<?= $base ?>/admin/people">Clear</a><?php endif; ?>
      <label class="pp-colvis"><input type="checkbox" id="campusCol" checked><span>Show campus</span></label>
    </form>

    <div class="pp-scroll">
      <table class="pp">
        <caption class="sr-only">People in the directory, <?= (int) $total ?> matching the current filters</caption>
        <thead><tr>
          <th scope="col">Last name</th>
          <th scope="col">First name</th>
          <!-- Two narrow columns. The visible heading is as short as the codes
               beneath it; the full word is still announced. -->
          <th scope="col" class="col-code"><span aria-hidden="true" title="Classification">Cls</span><span class="sr-only">Classification</span></th>
          <th scope="col" class="col-code"><span aria-hidden="true" title="Member type">Type</span><span class="sr-only">Member type</span></th>
          <th scope="col" class="col-campus">Campus</th>
          <th scope="col">Birthday</th>
          <th scope="col">Contact</th>
          <?php if ($isAdmin): ?><th scope="col"><span class="sr-only">Actions</span></th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if (!$people): ?><tr><td colspan="<?= $isAdmin ? 8 : 7 ?>" class="muted" style="text-align:center;padding:20px">No people match.</td></tr><?php endif; ?>
        <?php foreach ($people as $p):
          $viewHref = $base . '/admin/people/view?id=' . (int) $p['id'];
          $last = trim((string) ($p['last_name'] ?? ''));
          $first = trim((string) ($p['first_name'] ?? ''));
        ?>
          <tr>
            <td><a class="pp-name is-last" href="<?= $viewHref ?>"><?= $last !== '' ? $h($last) : '<span class="muted">—</span>' ?></a></td>
            <td><a class="pp-name" href="<?= $viewHref ?>"><?= $first !== '' ? $h($first) : '<span class="muted">—</span>' ?></a></td>
            <td class="col-code"><?= $clsDot((string) ($p['classification'] ?? ''), $clsCodes) ?></td>
            <td class="col-code"><?= $typeCell((string) ($p['member_type'] ?? ''), $typeCodes) ?></td>
            <td class="col-campus"><?= $p['campus'] ? $h($p['campus']) : '<span class="muted">—</span>' ?></td>
            <td><?= (int) $p['bm'] > 0 ? $h($months[(int) $p['bm']] . ' ' . (int) $p['bd']) : '<span class="muted">—</span>' ?></td>
            <td class="muted"><?= $h($p['email'] ?: $p['cell'] ?: '') ?></td>
            <?php if ($isAdmin): ?><td><a class="pp-btn" href="<?= $base ?>/admin/people/edit?id=<?= (int) $p['id'] ?>">Edit</a></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>


    <?php if ($pages > 1): ?>
      <div class="pp-pager">
        <?php if ($page > 1): ?><a class="pp-btn" href="<?= $h($pageUrl($page - 1)) ?>">&larr; Prev</a><?php endif; ?>
        <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="pp-btn" href="<?= $h($pageUrl($page + 1)) ?>">Next &rarr;</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</article>
<script>
(function(){
  var KEY='church_portal_admin_people_campus_col_v1';
  var box=document.getElementById('campusCol');
  var table=document.querySelector('table.pp');
  if(!box||!table) return;
  function apply(){ table.classList.toggle('show-campus', box.checked); }
  try{
    var stored=localStorage.getItem(KEY);
    box.checked = stored===null ? true : stored==='1';
  }catch(e){}
  apply();
  box.addEventListener('change', function(){
    try{ localStorage.setItem(KEY, box.checked?'1':'0'); }catch(e){}
    apply();
  });
})();
</script>
<?php
$content = ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'people',
    'pageTitle' => 'Member records · Admin', 'pageSubtitle' => 'Add and edit person records.',
    'sectionTitle' => 'Member records',
    'sectionDescription' => 'Add, find, and edit person records. The People tab in the top bar is the lookup directory — it does not create people.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
