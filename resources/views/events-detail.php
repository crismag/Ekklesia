<?php

declare(strict_types=1);

/** @var string $basePath */
/** @var ?array<string,mixed> $actor */
/** @var array<string,mixed>|null $event */

$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$defaultCampusId = $campusSelector['defaultCampusId'] ?? null;
$canManageEvents = $actor !== null && in_array('manage_events', $actor['permissions'] ?? [], true);

require_once __DIR__ . '/_portal-shell.php';
require_once __DIR__ . '/_event-editor.php';

$eventTitle   = htmlspecialchars((string) ($event['title'] ?? 'Event'), ENT_QUOTES, 'UTF-8');
$eventDesc    = htmlspecialchars((string) ($event['description'] ?? ''), ENT_QUOTES, 'UTF-8');
$eventId      = (int) ($event['event_id'] ?? 0);
$campusIds    = array_map('intval', (array) ($event['campus_ids'] ?? []));
$allCampuses  = empty($campusIds);
$occurrences  = is_array($event['occurrences'] ?? null) ? $event['occurrences'] : [];
$availCampuses = is_array($event['available_campuses'] ?? null) ? $event['available_campuses'] : [];
$schedule = is_array($schedule ?? null) ? $schedule : ['rule' => null, 'summary' => ''];
$whenLabel = trim((string) ($schedule['summary'] ?? ''));
if ($whenLabel === '' && $occurrences !== []) {
    $firstStart = (string) ($occurrences[0]['starts_at'] ?? '');
    if ($firstStart !== '') {
        try {
            $whenLabel = (new DateTimeImmutable($firstStart))->format('D, j M Y · H:i');
        } catch (\Exception) {
            $whenLabel = $firstStart;
        }
    }
}
if ($whenLabel === '') {
    $whenLabel = 'No dates on the calendar yet';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $event !== null ? $eventTitle . ' — ' : '' ?>Events — Church Portal</title>
    <style>
                *{box-sizing:border-box}
        body{margin:0;min-height:100vh;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 320px,var(--bg) 321px)}
        a{color:inherit;text-decoration:none}
        .page-header{padding:20px 0 28px;color:#f8fffb}
        .breadcrumb{display:flex;align-items:center;gap:6px;font-size:12px;color:rgba(248,255,251,.65);margin-bottom:10px}
        .breadcrumb a{color:rgba(248,255,251,.65);display:inline-flex;align-items:center;min-height:24px}.breadcrumb a:hover{color:#f8fffb}
        .breadcrumb-sep{opacity:.45}
        .page-title{font-size:clamp(24px,4vw,42px);font-weight:900;line-height:1.05;margin:0 0 6px}
        .page-sub{color:rgba(248,255,251,.72);font-size:14px;margin:0}
        .content-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:14px;align-items:start}
        .col-main{display:grid;gap:14px}
        .col-side{display:grid;gap:14px}
        .panel{background:var(--paper);border:1px solid var(--line);border-radius:10px;box-shadow:0 14px 38px rgba(27,50,40,.09);overflow:hidden}
        .panel-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid var(--line)}
        .panel-head h2{margin:0;font-size:14px;font-weight:900}
        .panel-body{padding:14px}
        .field{display:grid;gap:4px;margin-bottom:10px}.field:last-child{margin-bottom:0}
        .field label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        .field input[type=text],.field input[type=datetime-local],.field input[type=number],.field textarea,.field select{width:100%;border:1px solid var(--line);border-radius:8px;padding:7px 10px;font:inherit;color:var(--ink);background:#fbfdfc;transition:border-color .12s,outline .12s}
        .field input:focus,.field textarea:focus,.field select:focus{outline:2px solid var(--teal);outline-offset:0;border-color:var(--teal)}
        .field textarea{resize:vertical;min-height:60px}
        .field select{height:36px}
        .help{font-size:12px;color:var(--muted);line-height:1.4;margin:0 0 8px}
        .toggle-row{display:flex;align-items:center;gap:8px;padding:6px 0}
        .toggle-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--teal);cursor:pointer}
        .toggle-row label{font-size:13px;font-weight:600;cursor:pointer}
        .campus-checks{display:grid;gap:4px;padding:4px 0 8px}
        .campus-checks label{display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;padding:5px 8px;border-radius:6px;transition:background .1s}
        .campus-checks label:hover{background:var(--soft)}
        .campus-checks input{accent-color:var(--teal)}
        .btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:9px 18px;border:0;border-radius:8px;font:inherit;font-weight:900;font-size:13px;cursor:pointer;transition:opacity .12s}
        .btn-primary{background:var(--teal);color:var(--on-teal)}.btn-primary:hover{opacity:.87}
        .btn-danger{background:var(--rose);color:var(--on-rose)}.btn-danger:hover{opacity:.87}
        .btn-ghost{background:var(--soft);color:var(--deep);border:1px solid var(--line)}.btn-ghost:hover{background:#dce9e3}
        .btn-sm{min-height:30px;padding:5px 12px;font-size:12px}
        .btn:disabled{opacity:.55;cursor:not-allowed}
        .save-row{display:flex;justify-content:flex-end;gap:8px;padding-top:10px;border-top:1px solid var(--line);margin-top:12px}
        /* Fifty-two rows is a normal year of a weekly service, so this is set
           tight: a table, short dates, tabular figures, and no row taller than
           it needs to be. */
        .sched-rule{margin:0;padding:10px 14px;font-size:14px;font-weight:700;color:var(--ink);
          border-bottom:1px solid #e6ede9;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .sched-rule>span{flex:1 1 auto;min-width:0}
        .sched-next{padding:12px 14px}
        .sched-next h3{margin:0 0 6px;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;
          color:var(--muted);font-weight:800}
        .sched-next ul{list-style:none;margin:0;padding:0;display:grid;gap:3px}
        .sched-next li{display:flex;gap:14px;font-size:13px}
        .sched-day{min-width:96px;font-weight:700}
        .sched-time{color:var(--muted);font-variant-numeric:tabular-nums}
        .sched-count{margin:8px 0 0;font-size:12px;color:var(--muted)}
        .occ-all{border-top:1px solid #e6ede9}
        .occ-all>summary{cursor:pointer;padding:10px 14px;min-height:44px;display:flex;align-items:center;
          font-size:12.5px;font-weight:700;color:var(--teal)}
        .occ-wrap{padding:0}
        table.occ{width:100%;border-collapse:collapse;font-size:12.5px}
        table.occ th{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);
          font-weight:800;text-align:left;padding:6px 12px;background:var(--soft);border-bottom:1px solid #e6ede9}
        table.occ td{padding:4px 12px;border-bottom:1px solid #f1f5f3;vertical-align:middle}
        table.occ tr:last-child td{border-bottom:0}
        .occ-month th{background:#fff;color:var(--ink);font-size:11px;letter-spacing:.05em;
          padding:10px 12px 4px;border-bottom:1px solid #e6ede9;text-transform:uppercase}
        .occ-d{white-space:nowrap;font-weight:700;width:74px}
        .occ-t{white-space:nowrap;color:var(--muted);font-variant-numeric:tabular-nums;width:96px}
        .occ-s{color:#8a4b12;font-size:11.5px;font-weight:700}
        .occ-own{display:inline-block;margin-left:6px;font-weight:600;color:var(--ink);
          font-style:italic;max-width:22ch;overflow:hidden;text-overflow:ellipsis;
          white-space:nowrap;vertical-align:bottom}
        .occ-edit label.occ-f{display:flex;flex-direction:column;gap:2px;font-size:11px;
          font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
        .occ-edit .occ-f input,.occ-edit .occ-f textarea{font:inherit;font-size:12.5px;
          border:1px solid var(--line);border-radius:6px;padding:5px 8px;text-transform:none;
          letter-spacing:normal;color:var(--ink)}
        .occ-edit .occ-f textarea{min-height:44px;resize:vertical;width:min(38ch,100%)}
        .occ-edit .occ-note{font-size:11.5px;color:var(--muted);flex-basis:100%;margin:0}
        .occ-a{text-align:right;width:238px}
        tr.is-cancelled .occ-d,tr.is-cancelled .occ-t{text-decoration:line-through;opacity:.6}
        .occ-x{font:inherit;font-size:11.5px;font-weight:700;border:1px solid var(--line,#c7d4cd);
          background:#fff;border-radius:6px;padding:3px 9px;min-height:26px;cursor:pointer;color:#b3261e}
        .occ-x:hover{border-color:#b3261e}
        .occ-past{border-top:1px solid #e6ede9}
        .occ-past>summary{cursor:pointer;padding:10px 12px;min-height:44px;display:flex;align-items:center;
          font-size:12.5px;font-weight:700;color:var(--muted)}
        .head-tools{display:flex;gap:6px;flex-wrap:wrap}
        .scope-dialog{border:1px solid var(--line,#c7d4cd);border-radius:12px;padding:0;
          max-width:420px;box-shadow:0 24px 60px rgba(27,50,40,.25)}
        .scope-dialog::backdrop{background:rgba(15,32,26,.42)}
        .scope-dialog form{padding:18px;display:grid;gap:12px}
        .scope-dialog h2{margin:0;font-size:15px;font-weight:900}
        .scope-dialog fieldset{border:0;margin:0;padding:0;display:grid;gap:2px}
        .scope-dialog label{display:flex;align-items:center;gap:9px;font-size:13.5px;
          cursor:pointer;min-height:38px;padding:0 6px;border-radius:8px}
        .scope-dialog label:hover{background:var(--soft,#eef4f0)}
        .scope-dialog input[type=radio]{width:18px;height:18px;accent-color:var(--teal,#117b6d)}
        .scope-actions{display:flex;justify-content:flex-end;gap:8px}
        .tool-panel[hidden]{display:none}
        .tool-panel{padding:12px 14px;background:var(--soft);border-bottom:1px solid #e6ede9}
        .tool-form{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px}
        .tool-form .field{margin-bottom:0;min-width:150px}
        .tool-panel .help{margin:8px 0 0}
        .bulkbar{display:flex;align-items:center;gap:10px;padding:8px 14px;background:#fff6e8;
          border-bottom:1px solid #f0dcbd;font-size:12.5px;font-weight:700;color:#7a4a06}
        .bulkbar[hidden]{display:none}
        .bulkbar .btn{margin-left:0}
        .bulkbar #bulkDelete{margin-left:auto}
        .occ-c{width:36px;padding-left:6px!important;padding-right:0!important}
        /* The label is the target, so a 16px tick still offers the 24px hit
           area WCAG 2.5.8 asks for without making every row taller. */
        .occ-c label{display:flex;align-items:center;justify-content:center;width:26px;height:26px;cursor:pointer}
        .occ-c input{width:16px;height:16px;accent-color:var(--teal);cursor:pointer;margin:0}
        table.occ td.occ-c,table.occ th.occ-c{padding-top:0;padding-bottom:0}
        .occ-a{white-space:nowrap}
        .occ-a .occ-x+.occ-x{margin-left:5px}
        .occ-x.danger{color:#b3261e}
        .occ-x:not(.danger){color:var(--deep)}
        .occ-edit td{background:#f3f9f6}
        .occ-edit form{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:6px 0}
        .occ-edit input{border:1px solid var(--line);border-radius:6px;padding:5px 8px;font:inherit;font-size:12.5px}
        /* Read-only facts: what the event is, before anyone edits it. */
        .facts{margin:0;display:grid;gap:10px}
        .facts>div{display:grid;grid-template-columns:120px 1fr;gap:12px;align-items:baseline}
        .facts dt{font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:800}
        .facts dd{margin:0;font-size:14px}
        .tag-row{display:flex;flex-wrap:wrap;gap:5px}
        .tag{display:inline-flex;align-items:center;min-height:24px;padding:1px 9px;
          border-radius:999px;background:var(--soft);border:1px solid var(--line);
          font-size:12px;font-weight:700;color:var(--deep)}
        @media(max-width:540px){.facts>div{grid-template-columns:1fr;gap:2px}}
        /* What just happened, and what to do next. */
        .created{display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;padding:12px 16px;margin:0 0 16px;
          background:#e6f7ec;border:1px solid #b7e3c6;border-radius:10px;color:#14663d;font-size:14px}
        .created strong{font-weight:800}
        .created .btn:nth-of-type(1){margin-left:auto}
        .sr-only{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
          clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}
        .empty-occ{padding:18px;color:var(--muted);font-size:13px;text-align:center}
        .pill{display:inline-flex;border-radius:999px;padding:2px 9px;font-size:12px;font-weight:900;line-height:1.5}
        .pill-green{background:#d6f4e8;color:#0e5c34}
        .gen-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .meta-list{display:grid}
        .meta-row{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:11px 18px;border-bottom:1px solid #edf2ef;font-size:13px}
        .meta-row:last-child{border-bottom:0}
        .meta-key{color:var(--muted);font-weight:600;font-size:12px;flex-shrink:0}
        .meta-val{font-weight:700;text-align:right;color:var(--ink)}
        .alert{border-radius:8px;padding:12px 16px;font-size:13px;line-height:1.4;border:1px solid}
        .alert-info{background:#eef6ff;border-color:#b3d4f0;color:#1a4a77}
        .not-found{padding:60px 18px;text-align:center;color:var(--muted)}
        .not-found h2{margin:0 0 8px;font-size:22px;color:var(--ink)}
        .date-chip{min-height:44px;border-radius:8px;background:var(--soft);display:grid;place-items:center;align-content:center;font-weight:950;text-align:center;font-size:16px}
        .date-chip small{display:block;color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase}
        .upcoming-row{display:grid;grid-template-columns:46px minmax(0,1fr);gap:10px;padding:11px 16px;border-bottom:1px solid #edf2ef;align-items:center}
        .upcoming-row:last-child{border-bottom:0}
        
        .stop-tabs{display:flex;gap:4px;background:var(--soft);border-radius:8px;padding:3px;margin-bottom:2px}
        .stop-tab{flex:1;border:0;background:transparent;border-radius:6px;padding:6px 10px;font:inherit;font-size:12px;font-weight:700;color:var(--muted);cursor:pointer;transition:background .1s,color .1s}
        .stop-tab.stop-tab-active{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.12)}
        .dur-row{display:flex;gap:8px;align-items:stretch}
        .dur-row input{flex:1;width:auto}
        .dur-row select{width:110px;flex-shrink:0}
        @media(max-width:860px){.content-grid{grid-template-columns:1fr}}
        @media(max-width:540px){.gen-grid{grid-template-columns:1fr}}
    </style>
<?= ee_styles() ?>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?>>
    <?= portal_header(
        $basePath,
        '',
        '',
        $campuses,
        $defaultCampusId !== null ? (int) $defaultCampusId : null,
        $actor,
        [
            ['href' => $base . '/',            'label' => 'Dashboard',   'icon' => 'dashboard'],
            ['href' => $base . '/ministries',  'label' => 'Ministries',  'icon' => 'ministry'],
            ['href' => $base . '/calendar',    'label' => 'Calendar',    'icon' => 'calendar'],
            ['href' => $base . '/events',      'label' => 'Events',      'icon' => 'events'],
            ['href' => $base . '/people',      'label' => 'People',      'icon' => 'people'],
        ],
        [],
        [],
        'Sign in',
        $base . '/login'
    ) ?>
<main id="portal-main" tabindex="-1">


    <div class="page-header">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="<?= $base ?>/">Home</a>
            <span class="breadcrumb-sep">›</span>
            <a href="<?= $base ?>/events">Events</a>
            <span class="breadcrumb-sep">›</span>
            <span><?= $event !== null ? $eventTitle : 'Not found' ?></span>
        </nav>
        <h1 class="page-title"><?= $event !== null ? $eventTitle : 'Event not found' ?></h1>
        <?php if ($event !== null): ?>
        <p class="page-sub"><?= htmlspecialchars($whenLabel, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>

    <?php if ($event === null): ?>
        <div class="not-found">
            <h2>Event not found</h2>
            <p>This event may have been removed or you may not have access to it.</p>
            <a class="btn btn-ghost" href="<?= $base ?>/events">← Back to Events</a>
        </div>
    <?php else: ?>

    <?php if (isset($_GET['added'])): ?>
        <div class="created" role="status">
            <strong>Occurrences added.</strong>
            <a class="btn btn-ghost btn-sm" href="<?= $base ?>/calendar">See them on the calendar</a>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['renamed'])): ?>
        <div class="created" role="status"><strong><?= $_GET['renamed'] === '1'
            ? 'That date now has its own title.'
            : 'That date uses the event’s title again.' ?></strong>
            <span>Every other date is unchanged.</span></div>
    <?php endif; ?>
    <?php if (isset($_GET['rescheduled'])): ?>
        <div class="created" role="status"><strong>Schedule changed.</strong>
            <?= (int) $_GET['rescheduled'] ?> date<?= (int) $_GET['rescheduled'] === 1 ? '' : 's' ?> from today onward.</div>
    <?php endif; ?>
    <?php if (isset($_GET['moved'])): ?>
        <div class="created" role="status"><strong><?= (int) $_GET['moved'] ?></strong>
            occurrence<?= (int) $_GET['moved'] === 1 ? '' : 's' ?> moved to the new time.</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="created" role="status"><strong><?= (int) $_GET['deleted'] ?></strong>
            occurrence<?= (int) $_GET['deleted'] === 1 ? '' : 's' ?> deleted.</div>
    <?php endif; ?>
    <?php if (isset($_GET['saved'])): ?>
        <div class="created" role="status"><strong>Changes saved.</strong></div>
    <?php endif; ?>
    <?php if (isset($_GET['created'])): ?>
        <!-- Say what just happened and name the next step. Without this the page
             is indistinguishable from having opened an existing event to edit it. -->
        <div class="created" role="status">
            <strong>Event created.</strong>
            <span>What next?</span>
            <a class="btn btn-primary btn-sm" href="<?= $base ?>/events/new">Create another event</a>
            <a class="btn btn-ghost btn-sm" href="<?= $base ?>/calendar">See it on the calendar</a>
        </div>
    <?php endif; ?>

    <div class="content-grid">

        <!-- LEFT COLUMN -->
        <div class="col-main">

            <section class="panel" aria-label="This activity">
                <div class="panel-head">
                    <h2>This activity</h2>
                    <?php if ($canManageEvents): ?>
                    <button class="btn btn-ghost btn-sm" type="button" id="editToggle" aria-expanded="false"
                            aria-controls="event-editor">Edit</button>
                    <?php endif; ?>
                </div>
                <!-- Read-only until somebody asks to edit. The page used to open
                     as a live form, so arriving here straight after creating an
                     event put the cursor in the record you had just made. -->
                <div class="panel-body" id="event-readonly">
                    <dl class="facts">
                        <div><dt>Title</dt><dd><?= $eventTitle ?></dd></div>
                        <div><dt>When</dt><dd><?= htmlspecialchars($whenLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <?php if ($eventDesc !== ''): ?>
                            <div><dt>Description</dt><dd><?= $eventDesc ?></dd></div>
                        <?php endif; ?>
                        <?php if (($eventTags ?? []) !== []): ?>
                        <div><dt>Tags</dt><dd class="tag-row"><?php
                            foreach ($eventTags as $t) {
                                echo '<span class="tag">' . htmlspecialchars((string) $t['label'], ENT_QUOTES, 'UTF-8') . '</span>';
                            }
                        ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Campuses</dt><dd><?php
                            $names = [];
                            foreach ($availCampuses as $campus) {
                                if (in_array((int) $campus['id'], $campusIds, true)) {
                                    $names[] = htmlspecialchars((string) $campus['name'], ENT_QUOTES, 'UTF-8');
                                }
                            }
                            echo $allCampuses || $names === [] ? 'All campuses' : implode(', ', $names);
                        ?></dd></div>
                    </dl>
                </div>
                <?php if ($canManageEvents): ?>
                <!-- The same editor /events/new uses. Editing an event and
                     creating one are the same act on the same record, and were
                     two separate forms that had drifted apart. -->
                <div class="panel-body" id="event-editor" hidden>
                    <?= ee_html([
                        'campuses'   => $availCampuses,
                        'ministries' => $ministries ?? [],
                        'eventTypes' => $eventTypes ?? [],
                        'allTags'    => $allTags ?? [],
                        'submitLabel' => 'Save changes',
                        // Editing changes the event's properties. Its dates are
                        // occurrences and are managed as a schedule below, so a
                        // date control here would be a control that does nothing.
                        'omit' => ['when'],
                        'values' => [
                            'title' => $event['title'] ?? '',
                            'description' => $event['description'] ?? '',
                            'campusIds' => $campusIds,
                            'ministryId' => $event['ministry_id'] ?? $event['ministryId'] ?? 0,
                            'eventTypeId' => $event['event_type_id'] ?? $event['eventTypeId'] ?? 0,
                            'usesServingSchedule' => (bool) ($event['uses_serving_schedule'] ?? $event['usesServingSchedule'] ?? false),
                            'tags' => $eventTags ?? [],
                            // Times come from the first occurrence: the event row
                            // carries a start, but the occurrences are what the
                            // calendar actually reads.
                            'startDate' => substr((string) ($occurrences[0]['starts_at'] ?? ''), 0, 10),
                            'startTime' => substr((string) ($occurrences[0]['starts_at'] ?? ''), 11, 5),
                            'endTime' => substr((string) ($occurrences[0]['ends_at'] ?? ''), 11, 5),
                        ],
                    ]) ?>
                </div>
                <div class="sched-rule">
                    <span>The dates below follow this activity’s rule. Changing it does not rewrite dates already past.</span>
                    <button class="btn btn-ghost btn-sm" type="button" id="schedToggle"
                            aria-expanded="false" aria-controls="schedPanel">Change how it repeats</button>
                </div>
                <!-- Changing the rule, as opposed to moving the times. Dates
                     already past are never touched: they record what actually
                     happened, and rewriting them would falsify it. -->
                <div class="tool-panel" id="schedPanel" hidden>
                    <form id="sched-form" class="tool-form">
                        <div class="field">
                            <label for="schedRepeat">Repeats</label>
                            <select id="schedRepeat">
                                <?php foreach (\App\Services\Events\RecurrenceRule::PRESETS as $key => $label): ?>
                                    <?php if ($key === 'selected') { continue; } ?>
                                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="schedFrom">Starting</label>
                            <input type="date" id="schedFrom" required>
                        </div>
                        <div class="field">
                            <label for="schedStart">From</label>
                            <input type="time" id="schedStart">
                        </div>
                        <div class="field">
                            <label for="schedEnd">To</label>
                            <input type="time" id="schedEnd">
                        </div>
                        <div class="field">
                            <label for="schedUntil">Until</label>
                            <input type="date" id="schedUntil">
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit">Change the schedule</button>
                    </form>
                    <p class="help" id="schedSays" aria-live="polite"></p>
                    <p class="help">Dates from today onward are replaced. Dates already past are left as they are.</p>
                </div>
                <?php endif; ?>
            </section>

            <!-- Dates on the calendar: one row is one date of this activity. -->
            <section class="panel" aria-label="This date">
                <div class="panel-head">
                    <h2>This date <span style="font-weight:400;color:var(--muted);font-size:13px">(<?= count($occurrences) ?> date<?= count($occurrences) === 1 ? '' : 's' ?>)</span></h2>
                    <?php if ($canManageEvents && $occurrences !== []): ?>
                    <div class="head-tools">
                        <button class="btn btn-ghost btn-sm" type="button" id="retimeToggle"
                                aria-expanded="false" aria-controls="retimePanel">Fix times</button>
                        <button class="btn btn-ghost btn-sm" type="button" id="clearToggle"
                                aria-expanded="false" aria-controls="clearPanel">Delete many</button>
                    </div>
                    <?php endif; ?>
                </div>
                <p class="help" style="margin:0;padding:10px 14px 0">Cancel, rename, or move one date without changing the activity. The repeat rule lives above.</p>
                <?php if ($canManageEvents && $occurrences !== []): ?>
                <!-- A repeating event entered at the wrong time is wrong in every
                     week it was generated into. Correcting it row by row is not a
                     workflow, so the whole series can be moved at once. The dates
                     are kept: it is the clock time that was mistyped. -->
                <div class="tool-panel" id="retimePanel" hidden>
                    <form id="retime-form" class="tool-form">
                        <div class="field">
                            <label for="retimeTime">Correct start time</label>
                            <input type="time" id="retimeTime" required>
                        </div>
                        <div class="field">
                            <label for="retimeMins">Length (minutes)</label>
                            <input type="number" id="retimeMins" min="1" max="1440" placeholder="Keep current">
                        </div>
                        <div class="field">
                            <label for="retimeScope">Apply to</label>
                            <select id="retimeScope">
                                <option value="upcoming">Today and later</option>
                                <option value="all">Every occurrence, past included</option>
                            </select>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit">Move these occurrences</button>
                    </form>
                    <p class="help">Dates stay as they are — only the time of day changes.</p>
                </div>
                <div class="tool-panel" id="clearPanel" hidden>
                    <form id="clear-form" class="tool-form">
                        <div class="field">
                            <label for="clearScope">Delete</label>
                            <select id="clearScope">
                                <option value="upcoming">Occurrences from today onward</option>
                                <option value="all">Every occurrence of this event</option>
                            </select>
                        </div>
                        <button class="btn btn-danger btn-sm" type="submit">Delete them</button>
                    </form>
                    <p class="help">The event itself stays. Occurrences that already have assignments are refused unless you confirm.</p>
                </div>
                <!-- Changing one date of a series is ambiguous, so the choice
                     is asked rather than assumed. Only the three the recurrence
                     model can actually carry out are offered. -->
                <dialog id="scopeDialog" class="scope-dialog" aria-labelledby="scopeTitle">
                    <form method="dialog">
                        <h2 id="scopeTitle">This event repeats</h2>
                        <p class="help" id="scopeWhat"></p>
                        <fieldset>
                            <legend class="sr-only">Apply this change to</legend>
                            <label><input type="radio" name="scope" value="one" checked> Only this date</label>
                            <label><input type="radio" name="scope" value="following"> This date and all later ones</label>
                            <label><input type="radio" name="scope" value="all"> Every date, past included</label>
                        </fieldset>
                        <div class="scope-actions">
                            <button class="btn btn-ghost btn-sm" value="cancel">Cancel</button>
                            <button class="btn btn-primary btn-sm" value="ok">Continue</button>
                        </div>
                    </form>
                </dialog>
                <div class="bulkbar" id="bulkBar" hidden role="status">
                    <span id="bulkCount">0 selected</span>
                    <button class="btn btn-danger btn-sm" type="button" id="bulkDelete">Delete selected</button>
                    <button class="btn btn-ghost btn-sm" type="button" id="bulkClear">Clear selection</button>
                </div>
                <?php endif; ?>
                <?php if ($occurrences === []): ?>
                    <div class="empty-occ">No occurrences yet. Use the generator below to create some.</div>
                <?php else: ?>
                <?php
                    // Fifty-two rows is normal for a weekly service, so this is a
                    // table rather than a stack of cards, and the ones already past
                    // are folded away. Nobody scrolls a year of history to reach
                    // next Sunday.
                    $todayYmd = (new DateTimeImmutable('today'))->format('Y-m-d');
                    $upcoming = [];
                    $past = [];
                    foreach ($occurrences as $occ) {
                        $when = (string) ($occ['starts_at'] ?? '');
                        if (substr($when, 0, 10) >= $todayYmd) {
                            $upcoming[] = $occ;
                        } else {
                            $past[] = $occ;
                        }
                    }
                    $renderRows = static function (array $rows) use ($canManageEvents): void {
                        $cols = $canManageEvents ? 5 : 3;
                        $month = '';
                        foreach ($rows as $occ) {
                            $start = !empty($occ['starts_at']) ? new DateTimeImmutable((string) $occ['starts_at']) : null;
                            $end   = !empty($occ['ends_at'])   ? new DateTimeImmutable((string) $occ['ends_at'])   : null;
                            $occId = (int) $occ['occurrence_id'];
                            $cancelled = !empty($occ['is_cancelled']);
                            $mins = $start && $end ? max(1, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60)) : 90;
                            $thisMonth = $start ? $start->format('F Y') : '';
                            if ($thisMonth !== $month) {
                                $month = $thisMonth;
                                echo '<tr class="occ-month"><th colspan="' . $cols . '" scope="colgroup">'
                                    . htmlspecialchars($month, ENT_QUOTES, 'UTF-8') . '</th></tr>';
                            }
                            $label = $start ? $start->format('D j M Y, H:i') : 'this occurrence';
                            echo '<tr' . ($cancelled ? ' class="is-cancelled"' : '') . ' data-occ="' . $occId . '"'
                                . ' data-start="' . ($start ? $start->format('Y-m-d\TH:i') : '') . '"'
                                . ' data-start-raw="' . htmlspecialchars((string) ($occ['starts_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
                                . ' data-mins="' . $mins . '"'
                                . ' data-otitle="' . htmlspecialchars((string) ($occ['title_override'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
                                . ' data-odesc="' . htmlspecialchars((string) ($occ['details_override'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
                            if ($canManageEvents) {
                                echo '<td class="occ-c"><label><input type="checkbox" class="occ-sel" value="' . $occId . '"'
                                    . ' aria-label="Select ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"></label></td>';
                            }
                            echo '<td class="occ-d">' . ($start ? htmlspecialchars($start->format('D j'), ENT_QUOTES, 'UTF-8') : '—') . '</td>';
                            $ownTitle = (string) ($occ['title_override'] ?? '');
                            echo '<td class="occ-t">' . ($start ? htmlspecialchars($start->format('H:i'), ENT_QUOTES, 'UTF-8') : '—')
                                . ($end && $end > $start ? '–' . htmlspecialchars($end->format('H:i'), ENT_QUOTES, 'UTF-8') : '') . '</td>';
                            // A date that says something of its own says it here,
                            // rather than only revealing it when opened.
                            echo '<td class="occ-s">' . ($cancelled ? 'Cancelled' : '')
                                . ($ownTitle !== ''
                                    ? '<span class="occ-own" title="This date has its own title">'
                                        . htmlspecialchars($ownTitle, ENT_QUOTES, 'UTF-8') . '</span>'
                                    : '')
                                . '</td>';
                            if ($canManageEvents) {
                                // Cancelling and deleting are different acts and
                                // are offered as different buttons. "No service
                                // this week" is an announcement; a date typed by
                                // mistake is an erasure.
                                $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                                echo '<td class="occ-a">';
                                if ($cancelled) {
                                    echo '<button class="occ-x restore-occ" type="button" data-id="' . $occId . '"'
                                        . ' aria-label="Put ' . $safe . ' back on the calendar">Restore</button>';
                                } else {
                                    echo '<button class="occ-x edit-occ" type="button" data-id="' . $occId . '"'
                                        . ' aria-label="Change the time of ' . $safe . '">Time</button>';
                                    echo '<button class="occ-x name-occ" type="button" data-id="' . $occId . '"'
                                        . ' aria-label="Give ' . $safe . ' its own title and description">Name</button>';
                                    echo '<button class="occ-x call-off" type="button" data-id="' . $occId . '"'
                                        . ' aria-label="Cancel ' . $safe . ', keeping the date on the calendar">Cancel</button>';
                                }
                                echo '<button class="occ-x danger cancel-occ" type="button" data-id="' . $occId . '"'
                                    . ' aria-label="Delete ' . $safe . ' from the calendar entirely">Delete</button>';
                                echo '</td>';
                            }
                            echo '</tr>';
                        }
                    };
                ?>
                <?php if ($upcoming !== []): ?>
                <div class="sched-next">
                    <h3>Next</h3>
                    <ul>
                    <?php foreach (array_slice($upcoming, 0, 3) as $occ):
                        $ns = new DateTimeImmutable((string) $occ['starts_at']);
                        $ne = !empty($occ['ends_at']) ? new DateTimeImmutable((string) $occ['ends_at']) : null;
                    ?>
                        <li><span class="sched-day"><?= $ns->format('D, j M') ?></span>
                            <span class="sched-time"><?= $ns->format('H:i') ?><?= $ne && $ne > $ns ? ' – ' . $ne->format('H:i') : '' ?></span></li>
                    <?php endforeach; ?>
                    </ul>
                    <p class="sched-count"><?= count($upcoming) ?> upcoming date<?= count($upcoming) === 1 ? '' : 's' ?><?php
                        if ($past !== []) { echo ', ' . count($past) . ' already past'; } ?>.</p>
                </div>
                <?php endif; ?>

                <details class="occ-all">
                    <summary>View all <?= count($occurrences) ?> date<?= count($occurrences) === 1 ? '' : 's' ?></summary>
                <div class="occ-wrap">
                  <?php if ($upcoming !== []): ?>
                    <table class="occ">
                      <caption class="sr-only">Upcoming occurrences</caption>
                      <thead><tr>
                        <?php if ($canManageEvents): ?>
                        <th scope="col" class="occ-c"><label><input type="checkbox" id="occAll" aria-label="Select all upcoming occurrences"></label></th>
                        <?php endif; ?>
                        <th scope="col">Date</th><th scope="col">Time</th><th scope="col"><span class="sr-only">Status</span></th>
                        <?php if ($canManageEvents): ?><th scope="col"><span class="sr-only">Actions</span></th><?php endif; ?>
                      </tr></thead>
                      <tbody><?php $renderRows($upcoming); ?></tbody>
                    </table>
                  <?php endif; ?>
                  <?php if ($past !== []): ?>
                    <details class="occ-past">
                      <summary><?= count($past) ?> earlier occurrence<?= count($past) === 1 ? '' : 's' ?></summary>
                      <table class="occ">
                        <caption class="sr-only">Occurrences already past</caption>
                        <tbody><?php $renderRows($past); ?></tbody>
                      </table>
                    </details>
                  <?php endif; ?>
                </div>
                </details>
                <?php endif; ?>
            </section>

            <?php if ($canManageEvents): ?>
            <!-- Generating dates is a tool, not the way an event is normally
                 scheduled. It sat open on the page, exposing the fact that
                 occurrences are rows somebody has to produce — which is the
                 persistence model, not something an administrator should have
                 to think about. It stays, because an irregular church schedule
                 sometimes needs it, but it is folded away. -->
            <section class="panel" aria-label="Advanced schedule tools">
                <details>
                <summary class="panel-head" style="cursor:pointer;list-style:none"><h2>Advanced schedule tools</h2></summary>
                <div class="panel-body">
                    <form id="generate-form">
                        <input type="hidden" id="eventId" value="<?= $eventId ?>">

                        <!-- Date row -->
                        <div class="gen-grid">
                            <div class="field">
                                <label for="genStartDate">Start date</label>
                                <input type="date" id="genStartDate" required>
                            </div>
                            <div class="field">
                                <label for="genEndDate">End date</label>
                                <input type="date" id="genEndDate" required>
                            </div>
                        </div>

                        <!-- Time row -->
                        <div class="gen-grid">
                            <div class="field">
                                <label for="genStartTime">Start time</label>
                                <input type="time" id="genStartTime" required>
                            </div>
                            <div class="field">
                                <label for="genEndTime">End time</label>
                                <input type="time" id="genEndTime" required>
                            </div>
                        </div>

                        <!-- Duration helper -->
                        <div class="field">
                            <label for="genDuration">Duration <span style="font-weight:400;color:var(--muted)">(optional — auto-fills end date &amp; time)</span></label>
                            <div class="dur-row">
                                <input type="number" id="genDuration" min="1" step="any" placeholder="e.g. 1.5">
                                <select id="genDurationUnit">
                                    <option value="min">minutes</option>
                                    <option value="hour" selected>hours</option>
                                    <option value="day">days</option>
                                </select>
                            </div>
                        </div>

                        <!-- Repeat pattern -->
                        <div class="field">
                            <label for="genPattern">Repeat pattern</label>
                            <select id="genPattern">
                                <option value="one_off">One-off (no repeat)</option>
                                <option value="weekly">Weekly</option>
                                <option value="biweekly">Biweekly</option>
                            </select>
                        </div>

                        <!-- Stop condition — only shown when pattern != one_off -->
                        <div id="genStopSection" hidden>
                            <div class="stop-tabs" role="tablist" aria-label="Stop condition">
                                <button type="button" class="stop-tab stop-tab-active" data-mode="count" role="tab">Stop after count</button>
                                <button type="button" class="stop-tab" data-mode="until" role="tab">Stop on date</button>
                            </div>
                            <div id="genStopCount" class="field" style="margin-top:10px">
                                <label for="genCount">Number of occurrences</label>
                                <input type="number" id="genCount" min="1" placeholder="Leave blank for unlimited">
                            </div>
                            <div id="genStopUntil" class="field" style="margin-top:10px" hidden>
                                <label for="genUntilOn">Repeat until (inclusive)</label>
                                <input type="date" id="genUntilOn">
                            </div>
                        </div>

                        <?php if ($occurrences !== []): ?>
                        <!-- Generating always added to what was already there, so
                             a corrected second attempt left the wrong dates in
                             place beside the right ones. Say so, and offer the
                             replacement that was actually intended. -->
                        <div class="toggle-row">
                            <input type="checkbox" id="genReplace">
                            <label for="genReplace">Replace the <?= count($occurrences) ?> existing occurrence<?= count($occurrences) === 1 ? '' : 's' ?> instead of adding to them</label>
                        </div>
                        <p class="help">Leave this unchecked and the new occurrences are added alongside the current ones.</p>
                        <?php endif; ?>

                        <div class="save-row" style="margin-top:10px">
                            <button type="submit" class="btn btn-primary">Generate</button>
                        </div>
                    </form>
                </div>
                </details>
            </section>
            <?php endif; ?>

        </div><!-- /col-main -->

        <!-- RIGHT COLUMN -->
        <div class="col-side">

            <section class="panel" aria-label="Event information">
                <div class="panel-head"><h2>Information</h2></div>
                <div class="meta-list">
                    <div class="meta-row">
                        <span class="meta-key">Event ID</span>
                        <span class="meta-val">#<?= $eventId ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-key">Occurrences</span>
                        <span class="meta-val"><?= count($occurrences) ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-key">Campus scope</span>
                        <span class="meta-val">
                            <?php if ($allCampuses): ?>
                                <span class="pill pill-green">All campuses</span>
                            <?php else: ?>
                                <?= count($campusIds) ?> campus<?= count($campusIds) !== 1 ? 'es' : '' ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if (!$allCampuses && $availCampuses !== []): ?>
                    <div class="meta-row" style="flex-direction:column;align-items:flex-start;gap:6px">
                        <span class="meta-key">Campuses</span>
                        <div>
                        <?php foreach ($availCampuses as $c): ?>
                            <?php if (in_array((int) $c['id'], $campusIds, true)): ?>
                                <span class="pill pill-green" style="margin:2px 2px 0 0"><?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($occurrences !== []): ?>
            <?php
                $now = new DateTimeImmutable();
                $upcoming = array_filter($occurrences, static function (array $o) use ($now): bool {
                    if (empty($o['starts_at'])) return false;
                    return new DateTimeImmutable((string) $o['starts_at']) >= $now;
                });
                usort($upcoming, static fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);
                $upcoming = array_slice($upcoming, 0, 5);
            ?>
            <section class="panel" aria-label="Upcoming occurrences">
                <div class="panel-head"><h2>Upcoming</h2></div>
                <?php if ($upcoming === []): ?>
                    <div class="empty-occ">No upcoming occurrences.</div>
                <?php else: ?>
                <?php foreach ($upcoming as $occ): ?>
                <?php $dt = new DateTimeImmutable((string) $occ['starts_at']); ?>
                <div class="upcoming-row">
                    <div class="date-chip">
                        <small><?= htmlspecialchars($dt->format('M'), ENT_QUOTES, 'UTF-8') ?></small>
                        <?= htmlspecialchars($dt->format('j'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($dt->format('l'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div style="color:var(--muted);font-size:11px"><?= htmlspecialchars($dt->format('g:i A'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="panel">
                <div class="panel-head"><h2>Quick links</h2></div>
                <div style="padding:8px;display:grid;gap:6px">
                    <a class="btn btn-ghost" style="justify-content:flex-start" href="<?= $base ?>/events">← All events</a>
                    <a class="btn btn-ghost" style="justify-content:flex-start" href="<?= $base ?>/calendar">Calendar view</a>
                    <a class="btn btn-ghost" style="justify-content:flex-start" href="<?= $base ?>/">Dashboard</a>
                    <a class="btn btn-ghost" style="justify-content:flex-start" href="<?= $base ?>/events/new">New event</a>
                </div>
            </section>

            <?php if ($canManageEvents): ?>
            <!-- An event filed by mistake previously had no way out: the portal
                 could create events and never remove one. -->
            <section class="panel" aria-label="Delete this event">
                <div class="panel-head"><h2>Delete event</h2></div>
                <div class="panel-body">
                    <p class="help">Removes “<?= $eventTitle ?>” and its <?= count($occurrences) ?>
                        occurrence<?= count($occurrences) === 1 ? '' : 's' ?> from the calendar. This cannot be undone.</p>
                    <button class="btn btn-danger btn-sm" type="button" id="deleteEvent">Delete this event</button>
                </div>
            </section>
            <?php endif; ?>

        </div><!-- /col-side -->
    </div><!-- /content-grid -->

    <?php endif; ?>

    </main>
<footer class="portal-footer">
        <span>Church Portal</span>
        <span>Event #<?= $eventId ?></span>
    </footer>
</div><!-- /shell -->

<?= ee_script() ?>
<script>
(function () {
    'use strict';

    const API = '<?= $base ?>/api';
    const EVENT_ID = <?= $eventId ?>;

    // Viewer first, editor on request. The page used to open as a live form, so
    // arriving here straight after creating an event put the cursor in the
    // record you had just made.
    const readonly = document.getElementById('event-readonly');
    const panel = document.getElementById('event-editor');
    const toggle = document.getElementById('editToggle');

    function setEditing(on) {
        if (!readonly || !panel || !toggle) return;
        panel.hidden = !on;
        readonly.hidden = on;
        toggle.hidden = on;
        toggle.setAttribute('aria-expanded', on ? 'true' : 'false');
        if (on) editor?.focus(); else toggle.focus();
    }

    // The same editor /events/new uses, saving through PATCH instead of POST.
    const editor = window.EventEditor?.init({
        onSave: async function (body, ui) {
            ui.setBusy(true, 'Saving\u2026');
            const res = await fetch(API + '/events/' + EVENT_ID, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            if (res.ok) {
                // Reload so the summary shows the saved record rather than a
                // hand-patched copy of the form's own values.
                location.href = location.pathname + '?saved=1';
                return;
            }
            let msg = 'Save failed — check your permissions.';
            try { msg = (await res.json()).error || msg; } catch (e) {}
            const summary = document.getElementById('eeSummary');
            document.getElementById('eeSummaryList').innerHTML = '<li>' + msg + '</li>';
            summary.hidden = false;
            summary.focus();
            ui.setBusy(false, 'Save changes');
        },
        onCancel: function () {
            // Discard: the viewer still shows what is stored, so reload rather
            // than leave edited-but-unsaved values sitting in the inputs.
            location.reload();
        },
    });

    toggle?.addEventListener('click', function () { setEditing(true); });

    async function post(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body || {}),
        });
        let data = null;
        try { data = await res.json(); } catch (e) { /* non-JSON error page */ }
        return { ok: res.ok, data: data };
    }

    function disclose(btnId, panelId) {
        const btn = document.getElementById(btnId);
        const panel = document.getElementById(panelId);
        btn?.addEventListener('click', function () {
            const open = panel.hidden;
            panel.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) panel.querySelector('input,select')?.focus();
        });
    }
    disclose('schedToggle', 'schedPanel');
    disclose('retimeToggle', 'retimePanel');

    // Say what the chosen rule will mean, in the same words the page uses to
    // describe the schedule it already has.
    const DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    function nthLabel(d) {
        const dom = d.getDate();
        const last = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
        if (dom + 7 > last) return 'Last';
        return ['First','Second','Third','Fourth'][Math.ceil(dom / 7) - 1] || 'First';
    }
    function schedSay() {
        const out = document.getElementById('schedSays');
        if (!out) return;
        const from = document.getElementById('schedFrom').value;
        const p = document.getElementById('schedRepeat').value;
        if (!from) { out.textContent = 'Pick the date it starts from.'; return; }
        const d = new Date(from + 'T00:00');
        const day = DAYS[d.getDay()];
        const head = p === 'weekly' ? 'Every ' + day
                   : p === 'biweekly' ? 'Every other ' + day
                   : p === 'monthly' ? 'Every month on the ' + d.getDate()
                   : p === 'monthly_nth' ? nthLabel(d) + ' ' + day + ' of every month'
                   : d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
        const until = document.getElementById('schedUntil').value;
        out.textContent = head + (p === 'one_off' ? ''
            : until ? ', until ' + new Date(until + 'T00:00').toLocaleDateString(undefined,
                { day: 'numeric', month: 'long' })
            : ', for a year');
    }
    document.getElementById('sched-form')?.addEventListener('input', schedSay);
    document.getElementById('sched-form')?.addEventListener('change', schedSay);
    schedSay();

    document.getElementById('sched-form')?.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const body = {
            pattern: document.getElementById('schedRepeat').value,
            startDate: document.getElementById('schedFrom').value,
            startTime: document.getElementById('schedStart').value || null,
            endTime: document.getElementById('schedEnd').value || null,
            untilOn: document.getElementById('schedUntil').value || null,
        };
        if (!confirm('Change the schedule to: ' + document.getElementById('schedSays').textContent
            + '\n\nDates from today onward are replaced. Dates already past are left as they are.')) return;
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true;
        const send = async function (force) {
            const res = await fetch(API + '/events/' + EVENT_ID + '/schedule', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(force ? Object.assign({}, body, { force: true }) : body),
            });
            let data = null;
            try { data = await res.json(); } catch (e) {}
            return { ok: res.ok, data: data };
        };
        let r = await send(false);
        if (!r.ok && /assignment/i.test(r.data?.error || '')) {
            if (!confirm(r.data.error + '\n\nChange it anyway?')) { btn.disabled = false; return; }
            r = await send(true);
        }
        if (r.ok) { location.href = location.pathname + '?rescheduled=' + (r.data?.created ?? 0); }
        else { alert(r.data?.error || 'Could not change the schedule.'); btn.disabled = false; }
    });

    disclose('clearToggle', 'clearPanel');

    document.getElementById('retime-form')?.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const time = document.getElementById('retimeTime').value;
        const mins = document.getElementById('retimeMins').value;
        const scope = document.getElementById('retimeScope').value;
        const label = scope === 'all' ? 'every occurrence of this event' : 'every occurrence from today onward';
        if (!confirm('Move ' + label + ' to ' + time + '? The dates stay the same.')) return;
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true;
        const r = await post(API + '/events/' + EVENT_ID + '/occurrences/retime',
            { timeOfDay: time, durationMin: mins === '' ? null : parseInt(mins, 10), scope: scope });
        if (r.ok) { location.href = location.pathname + '?moved=' + (r.data?.moved ?? 0); }
        else { alert(r.data?.error || 'Could not move those occurrences.'); btn.disabled = false; }
    });

    // Bulk delete asks the server twice when assignments exist: once without
    // force so the count can be reported, then again only if the user says yes
    // knowing what they are discarding.
    async function deleteOccurrences(body, describe) {
        if (!confirm('Delete ' + describe + '? This cannot be undone.')) return false;
        let r = await post(API + '/events/' + EVENT_ID + '/occurrences/delete', body);
        if (!r.ok && /assignment/i.test(r.data?.error || '')) {
            if (!confirm(r.data.error + '\n\nDelete anyway?')) return false;
            r = await post(API + '/events/' + EVENT_ID + '/occurrences/delete',
                Object.assign({}, body, { force: true }));
        }
        if (r.ok) { location.href = location.pathname + '?deleted=' + (r.data?.deleted ?? 0); return true; }
        alert(r.data?.error || 'Could not delete those occurrences.');
        return false;
    }

    document.getElementById('clear-form')?.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const scope = document.getElementById('clearScope').value;
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true;
        const done = await deleteOccurrences({ scope: scope },
            scope === 'all' ? 'every occurrence of this event' : 'all occurrences from today onward');
        if (!done) btn.disabled = false;
    });

    // Row selection
    const bulkBar = document.getElementById('bulkBar');
    function selected() {
        return Array.from(document.querySelectorAll('.occ-sel:checked'))
            .map(function (el) { return parseInt(el.value, 10); });
    }
    function refreshBulk() {
        if (!bulkBar) return;
        const n = selected().length;
        bulkBar.hidden = n === 0;
        document.getElementById('bulkCount').textContent =
            n + ' occurrence' + (n === 1 ? '' : 's') + ' selected';
    }
    document.querySelectorAll('.occ-sel').forEach(function (cb) {
        cb.addEventListener('change', refreshBulk);
    });
    document.getElementById('occAll')?.addEventListener('change', function () {
        const on = this.checked;
        this.closest('table').querySelectorAll('.occ-sel').forEach(function (cb) { cb.checked = on; });
        refreshBulk();
    });
    document.getElementById('bulkClear')?.addEventListener('click', function () {
        document.querySelectorAll('.occ-sel').forEach(function (cb) { cb.checked = false; });
        const all = document.getElementById('occAll');
        if (all) all.checked = false;
        refreshBulk();
    });
    document.getElementById('bulkDelete')?.addEventListener('click', async function () {
        const ids = selected();
        if (ids.length === 0) return;
        this.disabled = true;
        const done = await deleteOccurrences({ scope: 'selected', occurrenceIds: ids },
            ids.length + ' selected occurrence' + (ids.length === 1 ? '' : 's'));
        if (!done) this.disabled = false;
    });

    async function flipCancelled(btn, action, describe) {
        if (!confirm(describe)) return;
        btn.disabled = true;
        const res = await fetch(API + '/occurrences/' + btn.dataset.id + '/' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: '{}',
        });
        if (res.ok) { location.reload(); return; }
        let msg = 'Could not update that date.';
        try { msg = (await res.json()).error || msg; } catch (e) {}
        alert(msg);
        btn.disabled = false;
    }

    document.querySelectorAll('.call-off').forEach(function (btn) {
        btn.addEventListener('click', function () {
            // Deliberately spells out the difference from Delete, because the
            // two buttons sit next to each other and mean opposite things.
            flipCancelled(this, 'cancel',
                'Cancel this date? It stays on the calendar marked "Cancelled", so people can see the meeting is off.');
        });
    });
    document.querySelectorAll('.restore-occ').forEach(function (btn) {
        btn.addEventListener('click', function () {
            flipCancelled(this, 'restore', 'Put this date back on the calendar?');
        });
    });

    const EVENT_TITLE_ATTR = <?= json_encode($eventTitle, JSON_UNESCAPED_SLASHES) ?>;
    const REPEATS = <?= ($schedule['rule'] ?? null) !== null ? 'true' : 'false' ?>;

    // Returns 'one' | 'following' | 'all', or null if dismissed. A <dialog>
    // rather than three confirm() calls: it traps focus, Escape closes it, and
    // the three choices are readable side by side instead of sequentially.
    function askScope(whenLabel) {
        const dlg = document.getElementById('scopeDialog');
        if (!dlg || typeof dlg.showModal !== 'function') return Promise.resolve('one');
        document.getElementById('scopeWhat').textContent =
            'Apply this change to which dates?';
        dlg.querySelector('input[value=one]').checked = true;
        return new Promise(function (resolve) {
            dlg.addEventListener('close', function handler() {
                dlg.removeEventListener('close', handler);
                if (dlg.returnValue !== 'ok') { resolve(null); return; }
                resolve(dlg.querySelector('input[name=scope]:checked')?.value ?? 'one');
            });
            dlg.showModal();
        });
    }

    // Per-row time correction. The row is edited where it sits rather than in a
    // dialog, so the surrounding dates stay visible while you retype one of them.
    document.querySelectorAll('.edit-occ').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = this.closest('tr');
            if (row.nextElementSibling?.classList.contains('occ-edit')) return;
            const cols = row.children.length;
            const editor = document.createElement('tr');
            editor.className = 'occ-edit';
            const td = document.createElement('td');
            td.colSpan = cols;
            const rid = 'occ-start-' + row.dataset.occ;
            const did = 'occ-mins-' + row.dataset.occ;
            td.innerHTML =
                '<form>' +
                '<label for="' + rid + '" class="sr-only">New start date and time</label>' +
                '<input type="datetime-local" id="' + rid + '" value="' + row.dataset.start + '" required>' +
                '<label for="' + did + '" class="sr-only">Length in minutes</label>' +
                '<input type="number" id="' + did + '" min="1" max="1440" value="' + row.dataset.mins + '">' +
                '<button class="btn btn-primary btn-sm" type="submit">Save time</button>' +
                '<button class="btn btn-ghost btn-sm" type="button" data-close="1">Cancel</button>' +
                '</form>';
            editor.appendChild(td);
            row.after(editor);
            td.querySelector('input').focus();
            td.querySelector('[data-close]').addEventListener('click', function () {
                editor.remove();
                btn.focus();
            });
            td.querySelector('form').addEventListener('submit', async function (ev) {
                ev.preventDefault();
                const save = this.querySelector('[type=submit]');
                save.disabled = true;
                const startsAt = document.getElementById(rid).value.replace('T', ' ');
                const mins = parseInt(document.getElementById(did).value, 10);

                // A one-off has nothing to ask about. A series does, and
                // guessing is how the whole year gets moved by accident.
                const scope = REPEATS ? await askScope(row.dataset.start) : 'one';
                if (scope === null) { save.disabled = false; return; }

                let res;
                if (scope === 'one') {
                    res = await fetch(API + '/occurrences/' + row.dataset.occ, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ startsAt: startsAt, durationMin: mins }),
                    });
                } else {
                    // Later dates keep their own dates and take the new time.
                    // Only the row you edited can also change day.
                    res = await fetch(API + '/events/' + EVENT_ID + '/occurrences/retime', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            timeOfDay: startsAt.slice(11, 16),
                            durationMin: mins,
                            scope: scope === 'all' ? 'all' : 'following',
                            from: scope === 'all' ? null : row.dataset.startRaw,
                        }),
                    });
                }
                if (res.ok) { location.href = location.pathname + '?moved=1'; }
                else {
                    let msg = 'Could not change that time.';
                    try { msg = (await res.json()).error || msg; } catch (e) {}
                    alert(msg);
                    save.disabled = false;
                }
            });
        });
    });

    // Give one date its own title and description. Scope is always this date
    // alone: "this and following" would mean splitting the series in two, which
    // the recurrence model holds one rule per event and cannot represent, so it
    // is not offered rather than offered and quietly doing something else.
    document.querySelectorAll('.name-occ').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = this.closest('tr');
            if (row.nextElementSibling?.classList.contains('occ-edit')) return;
            const editor = document.createElement('tr');
            editor.className = 'occ-edit';
            const td = document.createElement('td');
            td.colSpan = row.children.length;
            const tid = 'occ-title-' + row.dataset.occ;
            const did = 'occ-desc-' + row.dataset.occ;
            const had = (row.dataset.otitle || row.dataset.odesc) ? true : false;
            td.innerHTML =
                '<form>' +
                '<label class="occ-f" for="' + tid + '">Title for this date' +
                '<input type="text" id="' + tid + '" maxlength="255" size="34"' +
                ' placeholder="' + EVENT_TITLE_ATTR + '" value="' + (row.dataset.otitle || '') + '"></label>' +
                '<label class="occ-f" for="' + did + '">Description for this date' +
                '<textarea id="' + did + '" placeholder="Only for this date.">' +
                (row.dataset.odesc || '') + '</textarea></label>' +
                '<button class="btn btn-primary btn-sm" type="submit">Save</button>' +
                (had ? '<button class="btn btn-ghost btn-sm" type="button" data-reset="1">Use the series</button>' : '') +
                '<button class="btn btn-ghost btn-sm" type="button" data-close="1">Cancel</button>' +
                '<p class="occ-note">Applies to this date only. Every other date keeps the event\u2019s own title.</p>' +
                '</form>';
            editor.appendChild(td);
            row.after(editor);
            td.querySelector('input').focus();
            td.querySelector('[data-close]').addEventListener('click', function () {
                editor.remove();
                btn.focus();
            });
            td.querySelector('[data-reset]')?.addEventListener('click', async function () {
                if (!confirm('Use the event\u2019s own title and description for this date?')) return;
                this.disabled = true;
                const res = await fetch(API + '/occurrences/' + row.dataset.occ + '/reset', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: '{}',
                });
                if (res.ok) { location.href = location.pathname + '?renamed=0'; }
                else { alert('Could not reset that date.'); this.disabled = false; }
            });
            td.querySelector('form').addEventListener('submit', async function (ev) {
                ev.preventDefault();
                const save = this.querySelector('[type=submit]');
                save.disabled = true;
                const res = await fetch(API + '/occurrences/' + row.dataset.occ, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        title: document.getElementById(tid).value,
                        description: document.getElementById(did).value,
                    }),
                });
                if (res.ok) { location.href = location.pathname + '?renamed=1'; }
                else {
                    let msg = 'Could not save that.';
                    try { msg = (await res.json()).error || msg; } catch (e) {}
                    alert(msg);
                    save.disabled = false;
                }
            });
        });
    });

    document.querySelectorAll('.cancel-occ').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            this.disabled = true;
            const done = await deleteOccurrences(
                { scope: 'selected', occurrenceIds: [parseInt(this.dataset.id, 10)] },
                'this occurrence');
            if (!done) this.disabled = false;
        });
    });

    document.getElementById('deleteEvent')?.addEventListener('click', async function () {
        if (!confirm('Delete "<?= addslashes($eventTitle) ?>" and all of its occurrences? This cannot be undone.')) return;
        this.disabled = true;
        const del = async function (force) {
            const res = await fetch(API + '/events/' + EVENT_ID + (force ? '?force=1' : ''), {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(force ? { force: true } : {}),
            });
            let data = null;
            try { data = await res.json(); } catch (e) {}
            return { ok: res.ok, data: data };
        };
        let r = await del(false);
        if (!r.ok && /assignment/i.test(r.data?.error || '')) {
            if (!confirm(r.data.error + '\n\nDelete anyway?')) { this.disabled = false; return; }
            r = await del(true);
        }
        if (r.ok) { location.href = '<?= $base ?>/events?removed=1'; }
        else { alert(r.data?.error || 'Could not delete this event.'); this.disabled = false; }
    });

    document.getElementById('generate-form')?.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Generating\u2026';
        try {
            // This used to PATCH the event's campus scope from the edit form's
            // checkboxes before generating. Generating dates has nothing to do
            // with which campuses an event is for, and the campus controls now
            // live in the editor where they belong.
            const startDate   = document.getElementById('genStartDate').value;
            const startTime   = document.getElementById('genStartTime').value || '00:00';
            const endDate     = document.getElementById('genEndDate').value;
            const endTime     = document.getElementById('genEndTime').value || '00:00';
            const durationMin = Math.max(1, Math.round(
                (new Date(endDate + 'T' + endTime).getTime() - new Date(startDate + 'T' + startTime).getTime()) / 60000
            ));

            const body = {
                pattern:     document.getElementById('genPattern').value,
                startsAt:    startDate + 'T' + startTime,
                durationMin,
            };

            if (body.pattern !== 'one_off') {
                const stopMode = document.querySelector('.stop-tab.stop-tab-active')?.dataset.mode ?? 'count';
                if (stopMode === 'count') {
                    const countVal = document.getElementById('genCount').value;
                    if (countVal) body.count = parseInt(countVal, 10);
                } else {
                    const untilVal = document.getElementById('genUntilOn').value;
                    if (untilVal) body.untilOn = untilVal;
                }
            }

            if (document.getElementById('genReplace')?.checked) {
                if (!confirm('Delete the existing occurrences and generate these instead?')) {
                    btn.textContent = 'Generate';
                    btn.disabled = false;
                    return;
                }
                // Clear first, then generate: doing it the other way round would
                // delete the occurrences that were just created.
                let cleared = await post(API + '/events/' + EVENT_ID + '/occurrences/delete', { scope: 'all' });
                if (!cleared.ok && /assignment/i.test(cleared.data?.error || '')) {
                    if (!confirm(cleared.data.error + '\n\nReplace anyway?')) {
                        btn.textContent = 'Generate';
                        btn.disabled = false;
                        return;
                    }
                    cleared = await post(API + '/events/' + EVENT_ID + '/occurrences/delete', { scope: 'all', force: true });
                }
                if (!cleared.ok) {
                    alert(cleared.data?.error || 'Could not clear the existing occurrences.');
                    btn.textContent = 'Generate';
                    btn.disabled = false;
                    return;
                }
            }

            const res = await fetch('<?= $base ?>/api/events/<?= $eventId ?>/occurrences', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            if (res.ok) { location.reload(); }
            else { alert('Failed to generate occurrences.'); btn.textContent = 'Generate'; btn.disabled = false; }
        } catch (e) {
            alert('Network error. Please try again.');
            btn.textContent = 'Generate';
            btn.disabled = false;
        }
    });

    // ---- Generate form: date/time ↔ duration sync and UI wiring ----
    (function initGenForm() {
        const fStartDate = document.getElementById('genStartDate');
        const fEndDate   = document.getElementById('genEndDate');
        const fStartTime = document.getElementById('genStartTime');
        const fEndTime   = document.getElementById('genEndTime');
        const fDur       = document.getElementById('genDuration');
        const fDurUnit   = document.getElementById('genDurationUnit');
        const fPattern   = document.getElementById('genPattern');
        const stopSec    = document.getElementById('genStopSection');
        if (!fStartDate) return;

        // Defaults: today, 09:00–10:30 (1h30m)
        const today = new Date().toISOString().slice(0, 10);
        fStartDate.value = today;
        fEndDate.value   = today;
        fStartTime.value = '09:00';
        fEndTime.value   = '10:30';
        fDur.value       = '1.5';
        fDurUnit.value   = 'hour';

        function toMins(val, unit) {
            const n = parseFloat(val);
            if (!isFinite(n) || n <= 0) return null;
            if (unit === 'min')  return n;
            if (unit === 'hour') return n * 60;
            return n * 1440; // day
        }

        function minsToDisplay(mins, unit) {
            if (unit === 'min')  return String(Math.round(mins));
            if (unit === 'hour') return String(+(mins / 60).toFixed(2));
            return String(+(mins / 1440).toFixed(2));
        }

        function diffMins(sd, st, ed, et) {
            return Math.round(
                (new Date(ed + 'T' + (et || '00:00')).getTime() -
                 new Date(sd + 'T' + (st || '00:00')).getTime()) / 60000
            );
        }

        function applyDurationToEnd() {
            const mins = toMins(fDur.value, fDurUnit.value);
            if (!mins || !fStartDate.value) return;
            const dt = new Date(fStartDate.value + 'T' + (fStartTime.value || '00:00'));
            dt.setTime(dt.getTime() + mins * 60000);
            fEndDate.value = dt.toISOString().slice(0, 10);
            fEndTime.value = dt.toTimeString().slice(0, 5);
        }

        function syncDurFromEnd() {
            if (!fStartDate.value || !fEndDate.value) return;
            const mins = diffMins(fStartDate.value, fStartTime.value, fEndDate.value, fEndTime.value);
            if (mins > 0) fDur.value = minsToDisplay(mins, fDurUnit.value);
        }

        fStartDate.addEventListener('change', function () {
            if (fEndDate.value < fStartDate.value) fEndDate.value = fStartDate.value;
            applyDurationToEnd();
        });
        fStartTime.addEventListener('change', applyDurationToEnd);
        fDur.addEventListener('input', applyDurationToEnd);
        fDurUnit.addEventListener('change', function () {
            const mins = diffMins(fStartDate.value, fStartTime.value, fEndDate.value, fEndTime.value);
            if (mins > 0) fDur.value = minsToDisplay(mins, fDurUnit.value);
        });
        fEndDate.addEventListener('change', syncDurFromEnd);
        fEndTime.addEventListener('change', syncDurFromEnd);

        fPattern.addEventListener('change', function () {
            stopSec.hidden = (this.value === 'one_off');
        });

        document.querySelectorAll('.stop-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                document.querySelectorAll('.stop-tab').forEach(function (t) {
                    t.classList.remove('stop-tab-active');
                });
                this.classList.add('stop-tab-active');
                document.getElementById('genStopCount').hidden = (this.dataset.mode !== 'count');
                document.getElementById('genStopUntil').hidden = (this.dataset.mode !== 'until');
            });
        });
    }());
})();
</script>
<script>
// Restored from the browser's back/forward cache, this page is a snapshot: an
// event deleted since would still be listed here. Server-rendered pages cannot
// be fixed with a cache header, so the restore is what triggers the reload.
window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
</script>
</body>
</html>
