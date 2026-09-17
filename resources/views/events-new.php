<?php

declare(strict_types=1);

/**
 * Create an event.
 *
 * This page used to ask four questions under four headings — What is it? When?
 * What time? Who is it for, and where? — and put seventeen controls and twelve
 * helper paragraphs on screen before anything was typed. On a 900px desktop it
 * measured 1759px tall, so the Create button was off the bottom of the screen
 * before you started, and scheduling a Sunday service read like filling in a
 * database record rather than putting something on a calendar.
 *
 * The form now lives in _event-editor.php, shared with the event page and the
 * calendar's quick-create, so all three ask the same question the same way. The
 * page around it is only a heading, the editor, and the request that saves it.
 *
 * @var string                    $basePath
 * @var array<string,mixed>|null  $actor
 * @var list<array<string,mixed>> $campuses
 * @var list<array<string,mixed>> $ministries
 * @var list<array<string,mixed>> $eventTypes
 * @var bool                      $canManageEvents
 */

require_once __DIR__ . '/_portal-shell.php';
require_once __DIR__ . '/_portal-components.php';
require_once __DIR__ . '/_event-editor.php';

$base = $basePath;
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// The calendar hands over what was clicked, so a date chosen there is not
// asked for a second time here.
$prefill = [
    'startDate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) === 1 ? $_GET['date'] : '',
    'startTime' => preg_match('/^\d{2}:\d{2}$/', (string) ($_GET['time'] ?? '')) === 1 ? $_GET['time'] : '',
    'campusIds' => ($cid = (int) ($_GET['campus'] ?? 0)) > 0 ? [$cid] : [],
];
// Where Cancel goes, and where saving returns to, so creating an event from
// August in the North York calendar does not land you back in today's default.
$returnTo = (string) ($_GET['return'] ?? '');
$returnTo = str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')
    ? $returnTo
    : $base . '/events';
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>New event · Ekklesia</title>
<?= portal_theme_style_block() ?>
<style>
    body{margin:0;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
    .panel{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius,10px);padding:16px 18px;margin-bottom:14px}
    .panel > h2{margin:0 0 2px;font-size:15px}
    .panel > .hint{margin:0 0 12px;font-size:12px;color:var(--muted)}
    [hidden]{display:none !important}
    @media(max-width:560px){ .panel{padding:14px} }
</style>
<?= ee_styles() ?>
</head><body><div class="shell" <?= portal_shell_mods('workspace') ?>>
<?= portal_header($base, '', '', $campuses, null, $actor, [
    ['href' => $base . '/',           'label' => 'Dashboard',  'icon' => 'dashboard'],
    ['href' => $base . '/ministries', 'label' => 'Ministries', 'icon' => 'ministry'],
    ['href' => $base . '/calendar',   'label' => 'Calendar',   'icon' => 'calendar'],
    ['href' => $base . '/events',     'label' => 'Events',     'icon' => 'events'],
    ['href' => $base . '/people',     'label' => 'People',     'icon' => 'people'],
], [], [], 'Sign in', $base . '/login') ?>
<main id="portal-main" tabindex="-1">
<?= pc_page_header([
    'kicker' => 'Events',
    'title' => 'New event',
]) ?>

<?php if (!$canManageEvents): ?>
    <section class="panel">
        <h2>You cannot create events</h2>
        <p class="hint">Creating events needs the manage-events permission. Ask a portal administrator.</p>
        <a class="pc-btn pc-btn--secondary" href="<?= $e($base) ?>/events"><span>Back to events</span></a>
    </section>
<?php else: ?>
<?= ee_html([
    'campuses'    => $campuses,
    'ministries'  => $ministries,
    'eventTypes'  => $eventTypes,
    'allTags'     => $allTags ?? [],
    'values'      => $prefill,
    'submitLabel' => 'Create event',
    'cancelHref'  => $returnTo,
]) ?>
<?php endif; ?>

</main>
<?= portal_footer() ?>
</div>
<?= ee_script() ?>
<script>
(function () {
    'use strict';
    const base = <?= json_encode($base, JSON_UNESCAPED_SLASHES) ?>;
    const returnTo = <?= json_encode($returnTo, JSON_UNESCAPED_SLASHES) ?>;
    const editor = window.EventEditor.init({
        onSave: async function (body, ui) {
            ui.setBusy(true, 'Saving…');
            try {
                const res = await fetch(base + '/api/events', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(body),
                });
                const data = await res.json().catch(function () { return {}; });
                if (!res.ok) {
                    // Say what actually went wrong rather than "Failed to create event".
                    const summary = document.getElementById('eeSummary');
                    document.getElementById('eeSummaryList').innerHTML =
                        '<li>' + (data.error || 'Could not save (' + res.status + ').') + '</li>';
                    summary.hidden = false;
                    summary.focus();
                    ui.setBusy(false, 'Create event');
                    return;
                }
                // ?created=1 so the event page can say what just happened and
                // offer the next step. Landing silently inside an edit form is
                // how the event you just made gets edited instead of a new one
                // being created.
                const id = data.event_id ?? data.eventId ?? '';
                location.href = base + '/events/' + id + '?created=1&return=' + encodeURIComponent(returnTo);
            } catch (ex) {
                const summary = document.getElementById('eeSummary');
                document.getElementById('eeSummaryList').innerHTML =
                    '<li>Could not reach the server. Check your connection and try again.</li>';
                summary.hidden = false;
                summary.focus();
                ui.setBusy(false, 'Create event');
            }
        },
        onCancel: function () { location.href = returnTo; },
    });
    editor?.focus();
})();
</script>
<script>
// Restored from the browser's back/forward cache, this page is a snapshot.
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
</script>
</body></html>
