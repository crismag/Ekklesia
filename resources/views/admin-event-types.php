<?php
/**
 * Admin · Event types — the categories that drive calendar layers and decide
 * who may read an event. Data via EventTypeService.
 *
 * @var string $basePath
 * @var array<string,mixed>|null $actor
 * @var array<string,mixed> $campusSelector
 * @var list<array<string,mixed>> $types
 * @var list<array{event_id:int,event_title:string,event_type:int}> $orphans
 * @var string $notice
 * @var string $flash
 */
require_once __DIR__ . '/_admin-shell.php';

$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$h = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$noticeMap = ['saved' => ['ok', 'Event types updated.'], 'error' => ['err', $flash !== '' ? $flash : 'Something went wrong.']];

$audiences = [
    'public'  => 'Public — visible without signing in',
    'members' => 'Members — anyone signed in',
    'leaders' => 'Leaders only — hidden from members everywhere',
];

$portalTypes = array_values(array_filter($types, static fn (array $t): bool => ($t['portal_slug'] ?? null) !== null));
$crmOnly     = array_values(array_filter($types, static fn (array $t): bool => ($t['portal_slug'] ?? null) === null));

ob_start();
?>
<style>
  .et-alert{padding:10px 14px;border-radius:8px;margin:0 0 14px;font-size:14px}
  .et-alert.ok{background:#e6f7ec;border:1px solid #b7e3c6;color:#1a7a3a}
  .et-alert.err{background:#fdecea;border:1px solid #f3c0bb;color:#b3261e}
  .et-alert.warn{background:#fff6e5;border:1px solid #f0dcb0;color:#8a5d1c}
  .et-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 0;border-bottom:1px solid var(--line,#eef2f5)}
  .et-row:last-of-type{border-bottom:0}
  .et-row form{display:flex;align-items:center;gap:6px;margin:0;flex-wrap:wrap}
  .et-row input[type=text]{font:inherit;padding:7px 9px;border:1px solid var(--line,#c7d4cd);border-radius:7px;min-width:150px}
  .et-row input[type=number]{font:inherit;padding:7px 9px;border:1px solid var(--line,#c7d4cd);border-radius:7px;width:70px}
  .et-row input[type=color]{width:38px;height:34px;padding:2px;border:1px solid var(--line,#c7d4cd);border-radius:7px;background:#fff;cursor:pointer}
  .et-row select{font:inherit;padding:7px 9px;border:1px solid var(--line,#c7d4cd);border-radius:7px}
  .et-btn{font:inherit;font-size:12px;font-weight:700;border:1px solid var(--line,#c7d4cd);background:#fff;border-radius:6px;padding:6px 9px;cursor:pointer;color:var(--ink,#1b2a24)}
  .et-btn:hover{border-color:#137a5f}
  .et-btn.primary{background:#0c5a45;border-color:#0c5a45;color:#fff}
  .et-btn.danger{color:#b3261e;border-color:#f0c3bd}
  .et-btn[disabled]{opacity:.4;cursor:default}
  .et-swatch{display:inline-block;width:14px;height:14px;border-radius:4px;vertical-align:-2px;margin-right:6px;border:1px solid rgba(0,0,0,.15)}
  .et-tag{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;padding:2px 7px;border-radius:999px;background:var(--soft,#eef4f0);color:var(--muted,#5c6b63)}
  .et-tag.leaders{background:#f6e7ec;color:#7b2445}
  .et-usage{font-size:12px;color:var(--muted,#5c6b63);min-width:70px}
  .et-add{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;align-items:center}
  .et-add input[type=text]{font:inherit;padding:8px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;min-width:180px}
  .et-add input[type=number]{font:inherit;padding:8px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px;width:80px}
  .et-add select{font:inherit;padding:8px 10px;border:1px solid var(--line,#c7d4cd);border-radius:8px}
  .et-add input[type=color]{width:40px;height:38px;padding:2px;border:1px solid var(--line,#c7d4cd);border-radius:8px;background:#fff}
  .et-slug{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:var(--muted,#5c6b63)}
  /* Quiet disclosure for the layer key: available on the row, not shouting
     from it. 44px so it is usable on a phone. */
  .et-tech{display:inline-block}
  .et-tech>summary{cursor:pointer;font-size:12px;color:var(--muted,#5c6b63);list-style:none;
    padding:10px 8px;min-height:44px;display:inline-flex;align-items:center;border-radius:6px}
  .et-tech>summary::-webkit-details-marker{display:none}
  .et-tech>summary:hover,.et-tech>summary:focus-visible{background:var(--soft,#eef4f0)}
  .muted{color:var(--muted,#5c6b63)}
  .et-note{font-size:13px;color:var(--muted,#5c6b63);margin:0 0 14px;line-height:1.55}
</style>

<?php if ($notice !== '' && isset($noticeMap[$notice])): [$cls, $msg] = $noticeMap[$notice]; ?>
  <div class="et-alert <?= $cls ?>"><?= $h($msg) ?></div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
  <article class="admin-card">Only a portal-wide admin can manage event types.</article>
<?php else: ?>

  <p class="et-note">
    An event's <strong>type</strong> decides two things: which calendar layer it appears in,
    and who may read it. A type marked <strong>Leaders only</strong> is hidden from members
    everywhere — the events list, the calendar, and a direct link all behave as though the
    event does not exist.
  </p>

  <?php if ($orphans !== []): ?>
    <div class="et-alert warn">
      <strong><?= count($orphans) ?> event<?= count($orphans) === 1 ? '' : 's' ?></strong>
      point at an event type that no longer exists, so <?= count($orphans) === 1 ? 'it is' : 'they are' ?>
      treated as <em>Members</em>. This happens when a type is deleted in ChurchCRM.
      Re-type <?= count($orphans) === 1 ? 'it' : 'them' ?> to restore the intended audience:
      <?= $h(implode(', ', array_map(static fn (array $o): string => $o['event_title'], array_slice($orphans, 0, 8)))) ?><?= count($orphans) > 8 ? ', …' : '' ?>
    </div>
  <?php endif; ?>

  <article class="admin-card">
    <h3>Portal event types</h3>
    <?php foreach ($portalTypes as $t): ?>
      <div class="et-row">
        <form method="post" action="<?= $base ?>/admin/event-types">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="type_id" value="<?= (int) $t['type_id'] ?>">
          <span class="et-swatch" style="background:<?= $h($t['portal_color'] ?? '#2c6ea5') ?>" aria-hidden="true"></span>
          <?php $etName = $t['portal_label'] ?? $t['type_name']; ?>
          <!-- Every control names the category it belongs to. The row repeats
               per category, so a bare "Name" would leave a screen reader
               announcing four identical fields with no way to tell them apart. -->
          <input type="text" name="label" value="<?= $h($etName) ?>" maxlength="64" required
                 aria-label="Name of the &quot;<?= $h($etName) ?>&quot; category">
          <select name="audience" aria-label="Who can see &quot;<?= $h($etName) ?>&quot; events">
            <?php foreach ($audiences as $value => $text): ?>
              <option value="<?= $h($value) ?>"<?= ($t['portal_audience'] ?? 'members') === $value ? ' selected' : '' ?>><?= $h($text) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="color" name="color" value="<?= $h($t['portal_color'] ?? '#2c6ea5') ?>"
                 aria-label="Colour for &quot;<?= $h($etName) ?>&quot;" title="Colour">
          <input type="number" name="sort" value="<?= (int) ($t['portal_sort'] ?? 0) ?>" min="0" max="999"
                 aria-label="Order of &quot;<?= $h($etName) ?>&quot; in lists" title="Order in lists">
          <button class="et-btn primary" type="submit">Save</button>
        </form>

        <!-- The layer key identifies the category to the calendar's saved
             layer choices. An administrator needs it only when diagnosing why
             a choice did not stick, so it sits behind a disclosure rather than
             on every row. -->
        <details class="et-tech"><summary aria-label="Technical details for &quot;<?= $h($t['portal_label'] ?? $t['type_name']) ?>&quot;">Details</summary>
          <span class="et-slug">Layer key: events:<?= $h($t['portal_slug']) ?></span>
        </details>
        <?php if (($t['portal_audience'] ?? '') === 'leaders'): ?><span class="et-tag leaders">Leaders only</span><?php endif; ?>
        <?php if ($t['portal_is_default']): ?><span class="et-tag">Default</span><?php endif; ?>
        <span class="et-usage"><?= (int) $t['usage_count'] ?> event<?= (int) $t['usage_count'] === 1 ? '' : 's' ?></span>

        <?php if (!$t['portal_is_default']): ?>
          <form method="post" action="<?= $base ?>/admin/event-types">
            <input type="hidden" name="action" value="set-default">
            <input type="hidden" name="type_id" value="<?= (int) $t['type_id'] ?>">
            <button class="et-btn" type="submit" title="New events get this type when none is chosen">Make default</button>
          </form>
        <?php endif; ?>

        <form method="post" action="<?= $base ?>/admin/event-types"
              onsubmit="return confirm('Delete event type &quot;<?= $h($t['portal_label'] ?? $t['type_name']) ?>&quot;? This cannot be undone.');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="type_id" value="<?= (int) $t['type_id'] ?>">
          <button class="et-btn danger" type="submit"
                  <?= ((int) $t['usage_count'] > 0 || $t['portal_is_default']) ? 'disabled title="In use or default"' : '' ?>>Delete</button>
        </form>
      </div>
    <?php endforeach; ?>

    <form class="et-add" method="post" action="<?= $base ?>/admin/event-types">
      <input type="hidden" name="action" value="add">
      <input type="text" name="label" placeholder="New category name" maxlength="64" required
             aria-label="Name for the new category">
      <select name="audience" aria-label="Who can see events in the new category">
        <?php foreach ($audiences as $value => $text): ?>
          <option value="<?= $h($value) ?>"<?= $value === 'members' ? ' selected' : '' ?>><?= $h($text) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="color" name="color" value="#2c6ea5" aria-label="Colour for the new category" title="Colour">
      <input type="number" name="sort" value="50" min="0" max="999" aria-label="Order in lists" title="Order in lists">
      <button class="et-btn primary" type="submit">Add type</button>
    </form>
    <p class="et-note" style="margin-top:10px">
      The layer key is built from the name once, when the type is created, and never changes —
      it is what remembers each person's calendar layer choices. Renaming a type later changes
      the label everywhere but keeps those choices intact.
    </p>
  </article>

  <?php if ($crmOnly !== []): ?>
    <article class="admin-card">
      <h3>ChurchCRM event types</h3>
      <p class="et-note">
        These exist in ChurchCRM but the portal has never claimed them, so events using them
        fall back to the <em>Members</em> audience and appear in no calendar layer of their own.
        Adopt one to give it a layer, a colour and an audience.
      </p>
      <?php foreach ($crmOnly as $t): ?>
        <div class="et-row">
          <form method="post" action="<?= $base ?>/admin/event-types">
            <input type="hidden" name="action" value="adopt">
            <input type="hidden" name="type_id" value="<?= (int) $t['type_id'] ?>">
            <strong><?= $h($t['type_name']) ?></strong>
            <span class="muted">ChurchCRM name</span>
            <select name="audience">
              <?php foreach ($audiences as $value => $text): ?>
                <option value="<?= $h($value) ?>"<?= $value === 'members' ? ' selected' : '' ?>><?= $h($text) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="color" name="color" value="#5b6d8a" title="Layer colour">
            <input type="number" name="sort" value="60" min="0" max="999" title="Sort order">
            <span class="et-usage"><?= (int) $t['usage_count'] ?> event<?= (int) $t['usage_count'] === 1 ? '' : 's' ?></span>
            <button class="et-btn" type="submit">Adopt into portal</button>
          </form>
        </div>
      <?php endforeach; ?>
    </article>
  <?php endif; ?>

<?php endif; ?>
<?php $content = ob_get_clean();

echo admin_render_page([
    'basePath'           => $basePath,
    'activeId'           => 'event-types',
    'pageTitle'          => 'Event Types · Admin',
    'pageSubtitle'       => 'Categories that drive calendar layers and event visibility.',
    'sectionTitle'       => 'Event types',
    'sectionDescription' => 'Add, rename, recolour and remove the types an event can be filed under.',
    'actor'              => $actor,
    'campusSelector'     => $campusSelector,
    'isAdmin'            => $isAdmin,
], static fn (): string => $content);
