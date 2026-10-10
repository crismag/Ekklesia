<?php
/**
 * Admin · Portal appearance & notices · Portal notices.
 *
 * Short operational messages for the people who use the portal, each with an
 * audience and optional dates. The list is edited in the browser and saved
 * whole through POST /api/announcements (portal-wide admins only; the
 * controller enforces it). Storage and rules: AnnouncementSettingsService.
 *
 * The retired home banner's messages can be copied in as drafts
 * (POST /admin/announcements/banner-drafts).
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var array{items:list<array<string,mixed>>} $announcementConfig
 * @var list<array{kicker:string,title:string,lead:string}> $bannerSlides
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';
require_once __DIR__ . '/_admin-kit.php';

$base = admin_e($basePath);
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$h = static fn ($v): string => admin_e($v);
$items = is_array($announcementConfig['items'] ?? null) ? $announcementConfig['items'] : [];
// Script context: JSON_HEX_* keeps <, >, &, ' and " from closing the element.
$itemsJson = json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$noticeTitles = array_map(static fn (array $i): string => mb_strtolower(trim((string) $i['title'])), $items);
$uncopied = array_values(array_filter(
    $bannerSlides,
    static fn (array $s): bool => trim((string) $s['title']) !== '' && !in_array(mb_strtolower(trim((string) $s['title'])), $noticeTitles, true),
));

ob_start();
?>
<style>
  .pn-list{display:grid;gap:var(--sp-3,12px)}
  .pn-item{border:1px solid var(--line);border-radius:var(--radius-lg,12px);background:var(--paper);display:grid;gap:var(--sp-3,12px);padding:var(--sp-4,16px)}
  .pn-item-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:var(--sp-2,8px)}
  .pn-state{font-size:13px;color:var(--muted)}
  .pn-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:var(--sp-3,12px)}
  @media (max-width:760px){.pn-row{grid-template-columns:minmax(0,1fr)}}
  .pn-check{display:inline-flex;align-items:center;gap:8px;min-height:44px;font-weight:600}
  .pn-check input{width:18px;height:18px}
  .pn-bar{display:flex;flex-wrap:wrap;align-items:center;gap:var(--sp-3,12px);padding:var(--sp-3,12px) var(--sp-5,20px);border-top:1px solid var(--line);background:var(--soft)}
  .pn-toast{font-size:13px;color:var(--muted)}
  .pn-toast.is-error{color:var(--rose-ink,#b84957)}
  .pn-toast.is-ok{color:var(--teal-ink,#117b6d)}
  .pn-banner{margin:0;padding-left:18px;display:grid;gap:4px}
</style>

<?= admin_notice($notice, [
    'drafts' => ['ok', $flash !== '' ? $flash : 'Draft notices added.'],
    'error' => ['error', $flash !== '' ? $flash : 'Something went wrong.'],
]) ?>

<?php if (!$isAdmin): ?>
  <div class="ek-card"><div class="ek-card-body">Only a portal-wide admin can change portal notices.</div></div>
<?php else: ?>

<section class="ek-card" id="pnEditor" aria-labelledby="pnHeading">
  <div class="ek-card-head">
    <div>
      <h2 id="pnHeading">Portal notices</h2>
      <p>Operational messages shown in the portal: planned downtime, a change to how something works, a deadline. Church news belongs on the church website.</p>
    </div>
    <button class="ek-btn" type="button" id="pnAdd">Add a notice</button>
  </div>
  <div class="ek-card-body">
    <div class="pn-list" id="pnList" aria-live="polite"></div>
  </div>
  <div class="pn-bar">
    <button class="ek-btn ek-btn-primary" type="button" id="pnSave">Save notices</button>
    <button class="ek-btn ek-btn-quiet" type="button" id="pnReset">Undo unsaved changes</button>
    <span class="pn-toast" id="pnToast" role="status"></span>
  </div>
</section>

<section class="ek-card" aria-labelledby="pnWhoHeading">
  <div class="ek-card-head"><div><h2 id="pnWhoHeading">Who sees a notice</h2></div></div>
  <div class="ek-card-body">
    <dl style="margin:0;display:grid;gap:8px">
      <div><dt style="font-weight:650">Everyone signed in</dt><dd style="margin:0;color:var(--muted)">Members, leaders and administrators, on the portal's home page once they have signed in.</dd></div>
      <div><dt style="font-weight:650">Administrators</dt><dd style="margin:0;color:var(--muted)">Portal-wide administrators only — for things the people running the portal need to know.</dd></div>
      <div><dt style="font-weight:650">Everyone, including the sign-in page</dt><dd style="margin:0;color:var(--muted)">Also visitors who are not signed in, where the portal's home page offers sign-in, the calendar and RSVP.</dd></div>
    </dl>
    <p class="ek-hint" style="margin:12px 0 0">A notice shows only while Published is ticked and today is within its dates. Leave the dates empty to show it until you unpublish it.</p>
  </div>
</section>

<?php if ($uncopied !== []): ?>
<section class="ek-card" aria-labelledby="pnBannerHeading">
  <div class="ek-card-head"><div>
    <h2 id="pnBannerHeading">Messages from the old home banner</h2>
    <p>The rotating home page banner has been replaced by portal notices. These messages have not been carried over. Copy them in as unpublished drafts, then publish the ones still needed.</p>
  </div></div>
  <div class="ek-card-body">
    <ul class="pn-banner">
      <?php foreach ($uncopied as $slide): ?>
        <li><strong><?= $h($slide['title']) ?></strong> — <span style="color:var(--muted)"><?= $h($slide['lead']) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="<?= $base ?>/admin/announcements/banner-drafts" style="margin-top:12px">
      <button class="ek-btn" type="submit">Copy <?= count($uncopied) === 1 ? 'it' : 'them' ?> in as drafts</button>
    </form>
  </div>
</section>
<?php endif; ?>

<script type="application/json" id="pnBoot"><?= $itemsJson ?></script>
<script>
(function () {
  const root = document.getElementById('pnEditor');
  if (!root) return;
  const basePath = document.querySelector('.shell')?.dataset.base || '';
  const list = document.getElementById('pnList');
  const toast = document.getElementById('pnToast');
  const audiences = [
    ['signed-in', 'Everyone signed in'],
    ['admins', 'Administrators'],
    ['public', 'Everyone, including the sign-in page'],
  ];
  let items = JSON.parse(document.getElementById('pnBoot').textContent || '[]');
  let saved = JSON.stringify(items);
  let dirty = false;

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const today = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
  const say = (text, kind) => { toast.textContent = text; toast.className = 'pn-toast' + (kind ? ' is-' + kind : ''); };

  function state(item) {
    if (!item.published) return 'Draft — not shown';
    const t = today();
    if (item.startsOn && item.startsOn > t) return 'Scheduled — shows from ' + item.startsOn;
    if (item.endsOn && item.endsOn < t) return 'Ended on ' + item.endsOn;
    const who = (audiences.find((a) => a[0] === item.audience) || audiences[0])[1].toLowerCase();
    return 'Showing now to ' + who + (item.endsOn ? ', until ' + item.endsOn : '');
  }

  function render() {
    if (!items.length) {
      list.innerHTML = '<div class="ek-empty"><strong>No notices</strong><p>Add one when there is something portal users need to know.</p></div>';
      return;
    }
    list.innerHTML = items.map((item, i) => `
      <article class="pn-item" data-i="${i}" aria-label="Notice ${i + 1}">
        <div class="pn-item-head">
          <span class="pn-state" data-state="${i}">${esc(state(item))}</span>
          <span class="ek-toolbar">
            <button class="ek-btn ek-btn-quiet" type="button" data-move="-1" data-i="${i}" ${i === 0 ? 'disabled' : ''} aria-label="Move notice ${i + 1} up">Up</button>
            <button class="ek-btn ek-btn-quiet" type="button" data-move="1" data-i="${i}" ${i === items.length - 1 ? 'disabled' : ''} aria-label="Move notice ${i + 1} down">Down</button>
            <button class="ek-btn ek-btn-danger" type="button" data-remove="${i}" aria-label="Remove notice ${i + 1}">Remove</button>
          </span>
        </div>
        <div class="ek-field"><label for="pnTitle${i}">Title</label><input class="ek-input" id="pnTitle${i}" data-f="title" data-i="${i}" maxlength="140" value="${esc(item.title)}"></div>
        <div class="ek-field"><label for="pnBody${i}">Message</label><textarea class="ek-input" id="pnBody${i}" data-f="body" data-i="${i}" maxlength="800" rows="3">${esc(item.body)}</textarea></div>
        <div class="pn-row">
          <div class="ek-field"><label for="pnAud${i}">Who sees it</label><select class="ek-select" id="pnAud${i}" data-f="audience" data-i="${i}">${audiences.map(([v, l]) => `<option value="${v}"${item.audience === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
          <div class="ek-field"><label for="pnFrom${i}">Show from (optional)</label><input class="ek-input" type="date" id="pnFrom${i}" data-f="startsOn" data-i="${i}" value="${esc(item.startsOn)}"></div>
          <div class="ek-field"><label for="pnTo${i}">Show until (optional)</label><input class="ek-input" type="date" id="pnTo${i}" data-f="endsOn" data-i="${i}" value="${esc(item.endsOn)}"></div>
        </div>
        <label class="pn-check"><input type="checkbox" data-f="published" data-i="${i}" ${item.published ? 'checked' : ''}> Published</label>
      </article>`).join('');
  }

  function read(target) {
    const i = Number(target.dataset.i);
    const f = target.dataset.f;
    if (!f || !items[i]) return;
    items[i][f] = f === 'published' ? target.checked : target.value;
    dirty = JSON.stringify(items) !== saved;
    const label = list.querySelector(`[data-state="${i}"]`);
    if (label) label.textContent = state(items[i]);
    if (dirty) say('Unsaved changes.');
  }

  list.addEventListener('input', (e) => read(e.target));
  list.addEventListener('change', (e) => read(e.target));
  list.addEventListener('click', (e) => {
    const remove = e.target.closest('[data-remove]');
    const move = e.target.closest('[data-move]');
    if (remove) {
      items.splice(Number(remove.dataset.remove), 1);
    } else if (move) {
      const i = Number(move.dataset.i);
      const j = i + Number(move.dataset.move);
      if (j < 0 || j >= items.length) return;
      [items[i], items[j]] = [items[j], items[i]];
    } else {
      return;
    }
    dirty = true;
    render();
    say('Unsaved changes.');
  });
  document.getElementById('pnAdd').addEventListener('click', () => {
    items.push({ id: '', title: '', body: '', tag: 'Church', audience: 'signed-in', published: true, startsOn: '', endsOn: '' });
    dirty = true;
    render();
    document.getElementById('pnTitle' + (items.length - 1))?.focus();
  });
  document.getElementById('pnReset').addEventListener('click', () => {
    items = JSON.parse(saved);
    dirty = false;
    render();
    say('Back to the last saved notices.');
  });
  document.getElementById('pnSave').addEventListener('click', async () => {
    const button = document.getElementById('pnSave');
    button.disabled = true;
    say('Saving…');
    try {
      const res = await fetch(basePath + '/api/announcements', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ items }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || data.message || ('Save failed (' + res.status + ')'));
      items = data.items || [];
      saved = JSON.stringify(items);
      dirty = false;
      render();
      say('Saved.', 'ok');
    } catch (err) {
      say(err.message || 'Save failed.', 'error');
    } finally {
      button.disabled = false;
    }
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  render();
})();
</script>

<?php endif; ?>
<?php
$content = (string) ob_get_clean();

echo admin_render_page([
    'basePath' => $basePath,
    'activeId' => 'announcements',
    'pageTitle' => 'Portal notices',
    'pageSubtitle' => 'Operational messages for portal users',
    'sectionTitle' => 'Portal notices',
    'sectionDescription' => 'Short messages for the people who use the portal, shown to the audience you choose between the dates you set.',
    'actor' => $actor,
    'campusSelector' => $campusSelector,
    'isAdmin' => $isAdmin,
], static fn (): string => $content);
