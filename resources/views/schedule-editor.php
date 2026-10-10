<?php
/**
 * @var string                       $basePath
 * @var ?array<string, mixed>        $actor
 * @var int                          $ministryId
 * @var string                       $ministryName
 * @var string                       $start
 * @var string                       $end
 * @var ?int                         $currentCampusId
 * @var list<int>                    $currentCampusIds
 * @var string                       $campusScopeNote
 * @var array<string, mixed>         $campusSelector
 * @var array<int, array<string,mixed>> $availableMinistries
 */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$safeMinistryName = htmlspecialchars($ministryName, ENT_QUOTES, 'UTF-8');
$currentCampusIdsCsv = implode(',', array_map('intval', $currentCampusIds ?? []));
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$defaultCampusId = $campusSelector['defaultCampusId'] ?? null;
foreach ($campuses as &$campusEntry) {
    $campusEntry['selected'] = $currentCampusId !== null
        ? ((int) ($campusEntry['id'] ?? 0) === (int) $currentCampusId)
        : (!empty($campusEntry['selected']));
}
unset($campusEntry);

// The campus this grid is scoped to, named rather than implied. A scheduler
// looking at an empty Sunday needs to know whose Sunday it is before they
// conclude anything from it.
$campusLabel = 'All campuses';
foreach ($campuses as $campusEntry) {
    if ($currentCampusId !== null && (int) ($campusEntry['id'] ?? 0) === (int) $currentCampusId) {
        $campusLabel = (string) ($campusEntry['name'] ?? $campusLabel);
        break;
    }
}
require_once __DIR__ . '/_portal-shell.php';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $safeMinistryName ?> Schedule</title>
    <style>
                *, *::before, *::after { box-sizing: border-box; }
        body { margin:0; min-height:100vh; padding:0; font: 13px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
               color: var(--ink);
               background: linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 110px,var(--bg) 111px); }
        .wrap { background: transparent; }
        h1 { font-size: 24px; margin: 0; line-height: 1.15; color: var(--ink); }
        .page-kicker { font-size:12px; color: var(--teal); font-weight: 800; text-transform: uppercase;
                       letter-spacing: 0.08em; margin-bottom: 4px; }
        .schedule-titleblock { display:flex; align-items:flex-end; justify-content:space-between; gap:14px; flex-wrap:wrap; margin: 4px 0 10px; }
        .schedule-titleblock .title-meta { color: var(--muted); font-size: 12px; }
        .schedule-titleblock .title-meta a { color: var(--teal); text-decoration: none; font-weight: 800; }
        .toolbar-readonly { min-width: 200px; }
        .toolbar-readonly .value { padding: 4px 0; font-size: 15px; font-weight: 700; color: var(--ink); }
        .toolbar-note { width: 100%; font-size: 12px; color: var(--muted); border-top: 1px solid var(--line); padding-top: 9px; }

        .event-picker { min-width: min(100%, 280px); flex: 1 1 280px; }
        .event-picker .event-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0 8px; min-height: 32px; align-items: center; }
        .event-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700;
                      background: var(--soft); border: 1px solid var(--line); border-radius: 999px;
                      padding: 4px 6px 4px 10px; color: var(--deep); max-width: 100%; }
        .event-chip .event-chip-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 220px; }
        .event-chip .event-chip-default { font-size: 10px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: var(--teal); }
        .event-chip button { font: inherit; line-height: 1; border: 0; background: none; cursor: pointer;
                             color: var(--muted); min-width: 24px; min-height: 24px; border-radius: 999px; }
        .event-chip button { min-width: 24px; min-height: 24px; display: inline-flex;
                             align-items: center; justify-content: center; }
        .event-chip button:hover { color: var(--rose, #b3261e); }
        .event-add { position: relative; }
        .event-add input[type=search] { width: 100%; min-width: 180px; padding: 6px 9px; border: 1px solid var(--line);
                                        border-radius: 6px; font-size: 13px; background: #fff; color: var(--ink); }
        .event-menu { position: absolute; z-index: 8; left: 0; right: 0; top: 100%; margin-top: 4px;
                      background: #fff; border: 1px solid var(--line); border-radius: 8px;
                      box-shadow: 0 12px 30px rgba(27,50,40,.12); max-height: 240px; overflow: auto; }
        .event-menu[hidden] { display: none; }
        .event-menu button { display: block; width: 100%; text-align: left; padding: 8px 10px; border: 0;
                             background: none; cursor: pointer; font: inherit; font-size: 13px; color: var(--ink); }
        .event-menu button:hover, .event-menu button[aria-selected=true] { background: var(--soft); }
        .event-menu .event-menu-empty { padding: 10px; font-size: 12px; color: var(--muted); line-height: 1.45; }
        .event-add input[type=search] { padding-right: 34px; }
        /* Scoped through .event-add: `.toolbar button` is the primary green
           action style and outranks a lone class, so the caret came out as a
           solid dark square sitting inside the search field. */
        .event-add .event-add-toggle {
            position: absolute; right: 3px; top: 50%; transform: translateY(-50%);
            min-width: 28px; min-height: 28px; padding: 0; border: 0; background: none;
            cursor: pointer; color: var(--muted); font-size: 12px; line-height: 1;
            border-radius: 6px; box-shadow: none; }
        .event-add .event-add-toggle:hover { color: var(--deep); background: var(--soft); }
        .event-add .event-add-toggle:focus-visible,
        .event-add input[type=search]:focus-visible {
            outline: 2px solid var(--teal); outline-offset: 1px; }
        /* An event already being scheduled stays in the list rather than
           vanishing from it. Hiding it made a one-event campus look like a
           campus with nothing set up at all. */
        .event-menu button[aria-selected=true] { font-weight: 700; color: var(--deep); }
        .event-menu .event-opt-state { float: right; font-size: 11px; font-weight: 700;
                                       letter-spacing: .03em; color: var(--muted); }
        .event-menu button[aria-selected=true] .event-opt-state { color: var(--teal); }
        .event-menu button.is-active { background: var(--soft); }

        /* toolbar */
        .toolbar { display: flex; gap: 12px; align-items: end; margin: 12px 0 14px; flex-wrap: wrap;
                   background: var(--paper); border: 1px solid var(--line); border-radius: 8px; padding: 12px 14px; box-shadow: 0 12px 30px rgba(27,50,40,.08); }
        .toolbar label { display: block; font-size:12px; color: var(--muted); font-weight: 800;
                         text-transform: uppercase; letter-spacing: 0.05em; }
        .toolbar input[type=number], .toolbar input[type=date] {
            padding: 6px 9px; border: 1px solid var(--line); border-radius: 6px; font-size: 13px; background: #fff; color: var(--ink); }
        .toolbar button { padding: 7px 14px; border: 0; background: var(--deep); color: #fff;
                          border-radius: 6px; font-weight: 800; cursor: pointer; font-size: 13px; }
        .toolbar button:hover { background: #0e3528; }
        .toolbar button.secondary { background: #fff; color: var(--deep); border: 1px solid var(--line); }
        .toolbar button.secondary:hover { background: var(--soft); }
        .toolbar button:disabled { opacity: 0.5; cursor: not-allowed; }
        .unfilled-hint { margin: 0 0 10px; font-size: 13px; font-weight: 700; color: var(--muted); }
        .unfilled-hint.is-open { color: #8c2f2f; }

        /* status */
        .status { padding: 8px 12px; border-radius: 8px; font-size: 13px; margin-top: 12px; display: none; }
        .status.ok  { background: #dff5e8; border: 1px solid #8dd2ad; color: #166534; }
        .status.err { background: #ffebe9; border: 1px solid #ffc1ba; color: #82071e; }

        /* ── workspace ──────────────────────────────────────────────
           Date → Role → Assignment. One surface; the editable one. */
        /* Its own surface rather than text laid over the shell's dark band.
           body paints a gradient down to 110px; the old titleblock put dark
           text straight onto it, which is why the heading and the meta line
           were hard to read at the top of the page. A card cannot be caught
           half on and half off a gradient stop. */
        .ws-head { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 12px 24px;
                   align-items: start; margin: 8px 0 14px; padding: 14px 16px;
                   background: var(--paper, #fff); border: 1px solid var(--line, #d0d7de);
                   border-radius: 10px; }
        .ws-id { min-width: 0; }
        /* The heading was clipped: a 24px face in a 27.6px line box inside a
           container that did not round up. Explicit leading and padding. */
        .ws-head h1 { margin: 2px 0 4px; font-size: 26px; line-height: 1.25;
                      padding-block: 1px; color: #1f2328; }
        .ws-context { margin: 0; font-size: 13px; color: #57606a; display: flex;
                      flex-wrap: wrap; gap: 6px 12px; }
        .ws-campus { font-weight: 700; color: #1f2328; }
        .ws-progress { min-width: 220px; text-align: right; }
        .ws-progress-line { margin: 0 0 6px; font-size: 13px; font-weight: 700; color: #1f2328; }
        .ws-meter { height: 8px; border-radius: 999px; background: #e7ecf0; overflow: hidden; }
        .ws-meter > i { display: block; height: 100%; width: 0;
                        background: var(--teal, #117b6d); transition: width .2s ease; }
        @media (prefers-reduced-motion: reduce) { .ws-meter > i { transition: none; } }
        .ws-unfilled { margin: 6px 0 0; font-size: 12.5px; color: #57606a; font-weight: 600; }
        .ws-unfilled.is-open { color: #8c2f2f; font-weight: 800; }
        /* These sat at 17px tall, well under the 24px minimum. */
        .ws-links { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 4px 16px; }
        .ws-links a { display: inline-flex; align-items: center; min-height: 32px;
                      font-size: 12.5px; font-weight: 700; color: var(--teal, #117b6d); }

        .ws-bar { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center;
                  justify-content: space-between; margin: 0 0 12px; }
        .ws-filters { display: flex; flex-wrap: wrap; gap: 6px; }
        .ws-chip { display: inline-flex; align-items: center; gap: 6px; min-height: 34px;
                   padding: 5px 12px; border: 1px solid var(--line, #d0d7de); border-radius: 999px;
                   background: #fff; font: inherit; font-size: 12.5px; font-weight: 700;
                   color: #1f2328; cursor: pointer; }
        .ws-chip:hover { border-color: var(--teal, #117b6d); }
        .ws-chip[aria-pressed="true"] { background: var(--deep, #0c5a45); border-color: var(--deep, #0c5a45); color: #fff; }
        .ws-chip:focus-visible { outline: 2px solid var(--teal, #117b6d); outline-offset: 2px; }
        .ws-cnt { font-variant-numeric: tabular-nums; opacity: .8; }
        .ws-save { display: flex; align-items: center; gap: 10px; }
        .ws-save button { min-height: 34px; padding: 7px 16px; border: 0; border-radius: 8px;
                          background: var(--deep, #0c5a45); color: #fff; font: inherit;
                          font-size: 13px; font-weight: 800; cursor: pointer; }
        .ws-save button:disabled { opacity: .5; cursor: default; }
        .ws-save button:focus-visible { outline: 2px solid var(--teal, #117b6d); outline-offset: 2px; }
        .save-note { font-size: 12.5px; color: #57606a; }
        .save-note.is-dirty { color: #8c2f2f; font-weight: 700; }

        .workspace { display: grid; gap: 10px; }
        .occ { border: 1px solid var(--line, #d0d7de); border-radius: 10px; background: var(--paper, #fff); }
        .occ[hidden] { display: none; }
        .occ.is-past { background: #fafbfc; }
        .occ-head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;
                    padding: 10px 14px; border-bottom: 1px solid #eef1f4; }
        .occ-title { margin: 0; font-size: 14.5px; font-weight: 800; color: #1f2328; }
        .occ-sub { font-size: 12.5px; color: #57606a; }
        .occ-count { margin-left: auto; font-size: 12px; font-weight: 800;
                     color: #8c2f2f; font-variant-numeric: tabular-nums; }
        .occ-count.is-done { color: var(--teal, #117b6d); }
        .occ-roles { list-style: none; margin: 0; padding: 4px 0; }

        .slot { display: grid; grid-template-columns: minmax(110px, 168px) minmax(0, 1fr);
                gap: 8px 14px; align-items: start; padding: 8px 14px; }
        .slot[hidden] { display: none; }
        .slot + .slot { border-top: 1px solid #f2f5f7; }
        .slot-role { font-size: 12px; font-weight: 800; text-transform: uppercase;
                     letter-spacing: .04em; color: #57606a; padding-top: 7px; }
        .slot-body { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-start; min-width: 0; }
        .slot-people { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; min-height: 30px; }
        /* Says what it is, rather than leaving a dash to be interpreted. */
        .slot-empty { font-size: 12.5px; font-style: italic; color: #8c2f2f; font-weight: 600; }
        .slot.is-unfilled .slot-role { color: #8c2f2f; }
        .slot-past { font-size: 13px; color: #57606a; }
        .slot-assign { min-height: 32px; padding: 5px 14px; border: 1px dashed var(--teal, #117b6d);
                       border-radius: 999px; background: #fff; font: inherit; font-size: 12.5px;
                       font-weight: 800; color: var(--teal, #117b6d); cursor: pointer; }
        .slot-assign:hover { background: #f0f7f5; border-style: solid; }
        .slot-assign:focus-visible { outline: 2px solid var(--teal, #117b6d); outline-offset: 2px; }

        .slot-panel { flex: 1 1 260px; min-width: 0; display: grid; gap: 6px;
                      padding: 8px; border: 1px solid #e3e9ee; border-radius: 8px; background: #fbfcfd; }
        .slot-panel[hidden] { display: none; }
        .sp-row { display: flex; gap: 6px; align-items: center; }
        /* `hidden` has to win. A display value on the same element beats the
           attribute's own rule, so the external-assignee row was set hidden and
           stayed on screen — the second disclosure step was not a step at all.
           Same trap as the calendar's date fields and the events search. */
        .slot-panel [hidden], .workspace [hidden] { display: none !important; }
        .sp-input { flex: 1 1 auto; min-width: 0; min-height: 34px; padding: 6px 9px;
                    border: 1px solid var(--line, #d0d7de); border-radius: 6px; font: inherit; font-size: 13px; }
        .sp-add { min-height: 34px; padding: 6px 12px; border: 0; border-radius: 6px;
                  background: var(--deep, #0c5a45); color: #fff; font: inherit; font-size: 12.5px;
                  font-weight: 800; cursor: pointer; }
        .sp-add-special { background: #1e40af; }
        .sp-add:focus-visible, .sp-input:focus-visible { outline: 2px solid var(--teal, #117b6d); outline-offset: 1px; }
        .sp-more { justify-self: start; min-height: 30px; padding: 4px 2px; border: 0; background: none;
                   font: inherit; font-size: 12.5px; font-weight: 700; color: var(--teal, #117b6d);
                   cursor: pointer; text-decoration: underline; }
        .sp-more:focus-visible { outline: 2px solid var(--teal, #117b6d); outline-offset: 2px; }
        .sp-err { margin: 0; font-size: 12px; color: #8c2f2f; font-weight: 700; }
        .ws-empty, .ws-none { margin: 12px 2px; font-size: 13px; color: #57606a; }

        /* Two ways to read the same schedule. Stacked reads one date at a time
           and is right for filling a single Sunday; side-by-side puts the dates
           in columns so a name can be followed across weeks, which is how you
           see that somebody is on four Sundays running. */
        .ws-views { display: flex; gap: 2px; padding: 2px; border-radius: 999px; background: #eef2f0; }
        .ws-view { min-height: 34px; padding: 6px 14px; border: 0; border-radius: 999px; background: transparent;
                   font: inherit; font-size: 12.5px; font-weight: 800; color: #57606a; cursor: pointer; }
        .ws-view[aria-pressed="true"] { background: var(--paper, #fff); color: #1f2328; box-shadow: 0 1px 3px rgba(28,48,39,.16); }
        .ws-view:focus-visible { outline: 2px solid var(--teal, #117b6d); outline-offset: 2px; }
        .ws-secondary { min-height: 34px; padding: 6px 13px; border: 1px solid var(--line, #d0d7de); border-radius: 8px;
                        background: var(--paper, #fff); font: inherit; font-size: 12.5px; font-weight: 800;
                        color: #1f2328; cursor: pointer; }
        .ws-secondary:hover { background: #f6f8fa; }

        /* The grid is wide by nature -- it scrolls inside its own box rather
           than making the page scroll sideways. */
        .ws-grid-wrap { overflow-x: auto; border: 1px solid var(--line, #d0d7de); border-radius: 10px; background: var(--paper, #fff); }
        .ws-grid { border-collapse: separate; border-spacing: 0; width: 100%; }
        .ws-grid th, .ws-grid td { border-bottom: 1px solid #f2f5f7; vertical-align: top; text-align: left; }
        .ws-grid tr:last-child th, .ws-grid tr:last-child td { border-bottom: 0; }
        .ws-grid thead th { position: sticky; top: 0; z-index: 3; background: #fbfdfc;
                            border-bottom: 1px solid var(--line, #d0d7de); padding: 9px 12px; }
        .ws-grid .gcorner { position: sticky; left: 0; z-index: 4; min-width: 150px; }
        .ws-grid .grole { position: sticky; left: 0; z-index: 2; background: var(--paper, #fff);
                          min-width: 150px; max-width: 190px; padding: 12px; font-size: 12px; font-weight: 800;
                          text-transform: uppercase; letter-spacing: .03em; color: #57606a;
                          border-right: 1px solid var(--line, #d0d7de); }
        .ws-grid tr.is-unfilled-row .grole { color: #8c2f2f; }
        .ws-grid .gdate { min-width: 210px; }
        .ws-grid .gdate-when { display: block; font-size: 13px; font-weight: 800; color: #1f2328; }
        .ws-grid .gdate-sub { display: block; font-size: 11.5px; font-weight: 600; color: #57606a; }
        .ws-grid .gdate-count { display: inline-block; margin-top: 3px; font-size: 11px; font-weight: 800; color: #57606a; }
        .ws-grid .gdate-count.is-done { color: var(--teal, #117b6d); }
        .ws-grid td.gcell { padding: 8px 10px; min-width: 210px; }
        .ws-grid td.gcell.is-past { background: #fafbfc; }
        /* In the grid the row header already says the role, so the slot drops
           its own label and becomes a single column of people. */
        .ws-grid .slot { display: block; padding: 0; border: 0; }
        .ws-grid .slot + .slot { border-top: 0; }
        .ws-grid .slot-role { display: none; }
        .ws-grid .slot-body { flex-direction: column; align-items: stretch; gap: 6px; }
        .ws-grid .slot-panel { flex: 1 1 auto; }
        .ws-grid tr[hidden] { display: none; }

        /* Copy a date. The control lives on the date it copies. */
        .occ-copy, .gdate-copy { min-height: 28px; padding: 3px 10px; border: 1px solid var(--line, #d0d7de);
                                 border-radius: 999px; background: var(--paper, #fff); font: inherit;
                                 font-size: 11.5px; font-weight: 800; color: #57606a; cursor: pointer; }
        .occ-copy:hover:not([disabled]), .gdate-copy:hover:not([disabled]) { background: #f0f7f5; color: var(--teal, #117b6d); border-color: var(--teal, #117b6d); }
        .occ-copy:focus-visible, .gdate-copy:focus-visible { outline: 2px solid var(--teal, #117b6d); outline-offset: 2px; }
        .occ-copy[disabled], .gdate-copy[disabled] { opacity: .45; cursor: not-allowed; }
        .occ-head .occ-copy { margin-left: 8px; }
        .gdate-copy { display: block; margin-top: 6px; }

        .copy-dialog { width: min(520px, calc(100vw - 32px)); padding: 0; border: 1px solid var(--line, #d0d7de);
                       border-radius: 12px; background: var(--paper, #fff); color: #1f2328;
                       box-shadow: 0 24px 60px rgba(16,32,26,.28); }
        .copy-dialog::backdrop { background: rgba(12,32,26,.42); }
        .copy-form { display: grid; gap: 14px; padding: 18px; margin: 0; }
        .copy-title { margin: 0; font-size: 16.5px; font-weight: 800; line-height: 1.25; }
        .copy-hint { margin: -8px 0 0; font-size: 12.5px; color: #57606a; }
        .copy-field { display: grid; gap: 6px; min-width: 0; }
        .copy-label { font-size: 11.5px; font-weight: 800; text-transform: uppercase;
                      letter-spacing: .03em; color: #57606a; }
        .copy-targets { max-height: 208px; overflow-y: auto; display: grid; gap: 2px;
                        padding: 6px; border: 1px solid var(--line, #d0d7de); border-radius: 8px; background: #fff; }
        .copy-target { display: flex; align-items: center; gap: 9px; min-height: 34px; padding: 5px 7px;
                       border-radius: 6px; font-size: 13px; cursor: pointer; }
        .copy-target:hover { background: #f0f7f5; }
        /* The label wraps the box, so the whole row is the target; the box is
           enlarged so it is also worth aiming at on its own. */
        .copy-target input, .copy-radio input { margin: 0; width: 18px; height: 18px; flex: 0 0 auto; }
        .copy-none { padding: 8px; font-size: 12.5px; color: #57606a; }
        .copy-modes { display: grid; gap: 4px; }
        .copy-radio { display: flex; align-items: center; gap: 9px; min-height: 34px; font-size: 13px; cursor: pointer; }
        .copy-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        #copyApply { min-height: 40px; padding: 9px 16px; border: 0; border-radius: 8px;
                     background: var(--deep, #0c5a45); color: #fff; font: inherit; font-size: 13px;
                     font-weight: 800; cursor: pointer; }
        #copyApply:hover { background: #0a4a39; }
        #copyApply:focus-visible, #copyCancel:focus-visible { outline: 2px solid var(--teal, #117b6d); outline-offset: 2px; }
        #copyCancel { min-height: 40px; }
        .copy-status { margin: 0; font-size: 12.5px; font-weight: 700; color: #57606a; }
        .copy-status:empty { display: none; }
        .copy-status.is-ok { color: var(--teal, #117b6d); }
        .copy-status.is-err { color: #8c2f2f; }

        @media (max-width: 900px) {
            .ws-view, .ws-secondary { min-height: 44px; }
            .copy-target, .copy-radio { min-height: 44px; }
            .occ-copy, .gdate-copy { min-height: 44px; }
            #copyApply, #copyCancel { min-height: 44px; }
        }

        @media (max-width: 720px) {
            /* Everything a thumb has to hit, at 44px. The card view used to
               carry this rule; the one surface carries it now. */
            /* .slot .pill .rm, not .pill .rm: the desktop 24px rule is declared
               further down the sheet and would otherwise win on order. */
            .ws-links a, .event-chip button,
            .event-add .event-add-toggle,
            .slot .pill .rm { min-width: 44px; min-height: 44px; }
            .ws-head { grid-template-columns: 1fr; }
            .ws-progress { text-align: left; min-width: 0; }
            .ws-bar { flex-direction: column; align-items: stretch; }
            .ws-save { justify-content: space-between; }
            .slot { grid-template-columns: 1fr; gap: 4px; }
            .slot-role { padding-top: 0; }
            /* The card view was already the mobile UI and its controls were
               sized for a thumb. The same rule now applies to the one surface. */
            .slot-assign, .sp-add, .sp-input, .sp-more,
            .ws-chip, .ws-save button { min-height: 44px; }
            .slot-body { flex-direction: column; align-items: stretch; }
            .slot-panel { flex: 1 1 auto; }
        }

        /* snapshot card */
        .snapshot { background: var(--paper); border: 1px solid var(--line); border-radius: 8px;
                    margin: 12px 0; display: none; box-shadow: 0 12px 30px rgba(27,50,40,.08); }
        .snapshot-header { display: flex; justify-content: space-between; align-items: center;
                           padding: 10px 14px; border-bottom: 1px solid var(--line); cursor: pointer; }
        .snapshot-header h2 { margin: 0; font-size: 13px; font-weight: 800; color: var(--ink); }
        .snapshot-header .toggle { font-size:12px; color: var(--teal); font-weight: 800; }
        .snapshot-body { padding: 10px 14px; }
        .snapshot-body.collapsed { display: none; }
        .snapshot-controls { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
                     margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #f1f3f5; }
        .snapshot-controls label { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; color: #57606a; font-weight: 600; }
        .snapshot-controls select { padding: 6px 8px; border: 1px solid #d0d7de; border-radius: 5px; font-size: 12px; background: #fff; color: #1f2328; }
        .snapshot-controls input[type=checkbox] { margin: 0; }
        .snapshot-hint { font-size:12px; color: #8c959f; }
        /* horizontal gallery */
        .snap-gallery { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 10px;
                        scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; }
        .snap-gallery::-webkit-scrollbar { height: 5px; }
        .snap-gallery::-webkit-scrollbar-track { background: #f1f3f5; border-radius: 3px; }
        .snap-gallery::-webkit-scrollbar-thumb { background: #c8d0da; border-radius: 3px; }
        .snap-card { flex: 0 0 clamp(240px, 28vw, 360px); min-width: 240px; max-width: 360px;
                 border: 1px solid #d0d7de; border-radius: 7px; padding: 10px 11px;
                 scroll-snap-align: start; background: #fff; font-size: 12px;
                 display: flex; flex-direction: column; }
        .snap-card.is-past { background: #fafafa; border-color: #e5e7ea; opacity: 0.82; }
        .snap-card.is-selected { border-color: #0969da; box-shadow: 0 0 0 2px rgba(9, 105, 218, 0.18); background: #f5faff; }
        .snap-card .sc-badge-past { display:inline-block; font-size:9px; font-weight:700;
                                    letter-spacing:0.05em; text-transform:uppercase;
                                    background:#f1f3f5; border-radius:10px; padding:1px 6px;
                                    color:#57606a; margin-bottom:5px; }
        .snap-card .sc-title { font-weight:600; white-space:nowrap; overflow:hidden;
                                text-overflow:ellipsis; margin-bottom:2px; }
        .snap-card .sc-when  { font-size:12px; color:#57606a; white-space:nowrap;
                                overflow:hidden; text-overflow:ellipsis; margin-bottom:8px; }
        .snap-card .sc-actions { margin-bottom: 8px; }
        .snap-card .sc-edit { padding: 4px 8px; border: 1px solid #1f6feb; background: #fff; color: #1f6feb;
                      border-radius: 999px; font-size:12px; font-weight: 600; cursor: pointer; }
        .snap-card .sc-edit:hover { background: #e7f3ff; }
        .snap-card .sc-role-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 8px;
            max-height: 280px;
            overflow: auto;
            padding-right: 2px;
            align-content: start;
        }
        .snap-card .sc-role-grid::-webkit-scrollbar { width: 6px; }
        .snap-card .sc-role-grid::-webkit-scrollbar-track { background: #f1f3f5; border-radius: 999px; }
        .snap-card .sc-role-grid::-webkit-scrollbar-thumb { background: #c8d0da; border-radius: 999px; }
        .snap-card .sc-role  { padding:6px 0 0; border-top:1px solid #f1f3f5; min-width: 0; }
        .snap-card .sc-role-name { font-size:12px; font-weight:700; text-transform:uppercase;
                                    letter-spacing:0.04em; color:#57606a; margin-bottom:2px; }
        .snap-card .sc-names { color:#1f2328; line-height:1.4; }
        .snap-card .sc-empty { color:#8c959f; font-style:italic; }
        .snap-card .snap-name { display: inline-flex; align-items: center; gap: 3px; border: 1px solid;
                    border-radius: 999px; padding: 1px 6px; margin: 2px 4px 2px 0; font-size:12px; }
        .snap-card .snap-sep { color: #8c959f; margin-right: 4px; }
        .snapshot-table-wrap {
            overflow: auto;
            max-width: 100%;
            max-height: min(58vh, 520px);
            border: 1px solid #eaeef2;
            border-radius: 8px;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            background: #fff;
        }
        .snapshot-table-wrap::-webkit-scrollbar { width: 8px; height: 8px; }
        .snapshot-table-wrap::-webkit-scrollbar-track { background: #f1f3f5; border-radius: 999px; }
        .snapshot-table-wrap::-webkit-scrollbar-thumb { background: #c8d0da; border-radius: 999px; }
        .snapshot-table { width: 100%; border-collapse: collapse; min-width: 720px; }
        .snapshot-table th, .snapshot-table td { padding: 9px 10px; border-bottom: 1px solid #eaeef2; border-right: 1px solid #f1f3f5; vertical-align: top; font-size: 12px; text-align: left; }
        .snapshot-table th { background: #f6f8fa; color: #57606a; font-size:12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
        .snapshot-table th:last-child, .snapshot-table td:last-child { border-right: none; }
        .snapshot-table tr.is-selected td { background: #f5faff; }
        .snapshot-table tr.is-past td { background: #fafafa; }
        .snapshot-table .st-date { white-space: nowrap; min-width: 148px; }
        .snapshot-table .st-schedule { min-width: 190px; }
        .snapshot-table .st-empty { color: #8c959f; font-style: italic; }
        .snapshot-table .st-role-list { display: grid; gap: 6px; }
        .snapshot-table .st-role-item { display: grid; grid-template-columns: minmax(110px, 150px) 1fr; gap: 8px; align-items: start; }
        .snapshot-table .st-role-name { font-size:12px; font-weight: 700; color: #57606a; text-transform: uppercase; letter-spacing: 0.04em; }
        .snapshot-table .st-names { line-height: 1.45; }
        .snapshot-table .st-chip-list { display: flex; flex-wrap: wrap; gap: 4px; }
        .snapshot-table .st-edit-cell { white-space: nowrap; width: 1%; }
        .snapshot-empty { padding: 14px; color: #8c959f; font-style: italic; border: 1px dashed #d0d7de; border-radius: 8px; background: #fafbfc; }
        .fill-dot { display:inline-block; width:7px; height:7px; border-radius:50%; margin-right:3px; vertical-align:middle; }
        .fill-full  { background: #1a7f37; }
        .fill-empty { background: #cf222e; }

        /* desktop grid */
        .grid-wrap { background: var(--paper); border: 1px solid var(--line); border-radius: 8px 8px 0 0; overflow: auto; box-shadow: 0 12px 30px rgba(27,50,40,.08); }
        .grid-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px;
                padding: 11px 14px; background: var(--paper); border: 1px solid var(--line);
                border-top: none; border-radius: 0 0 8px 8px; flex-wrap: wrap; box-shadow: 0 12px 30px rgba(27,50,40,.08); }
        .grid-actions .save-note { font-size: 12px; color: var(--muted); }
        .grid-actions .save-note.is-dirty { color: var(--gold); font-weight: 800; }
        .grid-actions button { padding: 8px 14px; border: 0; background: var(--teal); color: var(--on-teal);
                       border-radius: 6px; font-weight: 800; cursor: pointer; font-size: 13px; }
        .grid-actions button:hover:not(:disabled) { background: #0d6359; }
        .grid-actions button.secondary { background: #fff; color: var(--deep); border: 1px solid var(--line); }
        .grid-actions button.secondary:hover { background: var(--soft); }
        .grid-actions button:disabled { opacity: 0.5; cursor: not-allowed; }
        table.grid { border-collapse: collapse; width: 100%; }
        table.grid th, table.grid td { border-right: 1px solid #eaeef2; border-bottom: 1px solid #eaeef2;
                       padding: 6px 8px; text-align: left; vertical-align: top; font-size: 12px; }
        table.grid th { background: var(--soft); position: sticky; top: 0; z-index: 1; color: var(--deep); }
        table.grid th.role { left: 0; z-index: 2; min-width: 130px; }
        table.grid td.role { background: var(--soft); font-weight: 700; position: sticky; left: 0; z-index: 1; min-width: 130px; color: var(--deep); }
        table.grid .occ-head { white-space: nowrap; min-width: 160px; }
        table.grid .occ-head .when { color: #57606a; font-weight: 400; }
        table.grid .occ-head.is-selected { background: #e7f3ff; box-shadow: inset 0 -2px 0 #0969da; }
        table.grid td.past-col { background: #fafafa; }
        table.grid td.occ-cell-selected { background: #f5faff; }

        /* assignee pill */
        /* assignee pill — default: neutral grey */
        .pill { display: inline-flex; align-items: center; gap: 3px; background: #f6f8fa;
                border: 1px solid #d0d7de; border-radius: 12px; padding: 2px 7px 2px 5px;
                font-size:12px; color: #1f2328; margin: 2px 2px 2px 0; }
        /* special (external/non-ministry person): light blue, #1e40af on #dbeafe — contrast ≈6.5:1 */
        .pill.p-special { background: #dbeafe; border-color: #93c5fd; color: #1e40af; }
        /* unresolved external/free-text: lilac-grey, #5b21b6 on #ede9fe — contrast ≈6.8:1 */
        .pill.p-unmatched { background: #ede9fe; border-color: #c4b5fd; color: #5b21b6; }
        /* multi-role (same occurrence): light amber, #78350f on #fef9c3 — contrast ≈8:1 */
        .pill.p-role    { background: #fef9c3; border-color: #f59e0b; color: #78350f; }
        /* time-busy (overlapping event): light red, #991b1b on #fee2e2 — contrast ≈5.8:1 */
        .pill.p-busy    { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }
        /* icon-only badge — no text; inherits pill foreground color */
        .pill .cb-icon  { font-size:12px; line-height: 1; cursor: default; flex-shrink: 0; }
        /* Was a 10x13 glyph — well under the 24px minimum, and the control
           that discards somebody's assignment. */
        .pill .rm { cursor: pointer; color: #6e7781; font-size: 13px; line-height: 1;
                    background: none; border: none; padding: 0; margin-left: 2px;
                    min-width: 24px; min-height: 24px; display: inline-flex;
                    align-items: center; justify-content: center; border-radius: 999px; }
        .pill .rm:focus-visible { outline: 2px solid var(--teal, #117b6d); outline-offset: 1px; }
        .pill .rm:hover { color: #cf222e; }

        /* searchable add-person row */
        .add-block { margin-top: 6px; }
        .add-label { font-size:12px; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase;
                 color: #57606a; margin: 0 0 3px; }
        .add-row { display: flex; gap: 4px; margin-top: 5px; flex-wrap: wrap; }
        .add-row input[type=text] { flex: 1 1 140px; min-width: 120px; padding: 4px 7px;
                                    border: 1px solid var(--line); border-radius: 5px; font-size: 12px; background: #fff; }
        .add-row button { padding: 4px 10px; font-size:12px; font-weight: 800; cursor: pointer;
                          border: 0; background: var(--deep); color: #fff; border-radius: 5px; }
        .add-row button:hover { background: #0e3528; }
        .add-row button.secondary { background: var(--soft); color: var(--deep); border: 1px solid var(--line); }
        .add-row button.secondary:hover { background: #dde8e2; }
        .add-row button.special-add { background: #dbeafe; border: 1px solid #93c5fd; color: #1e40af; }
        .add-row button.special-add:hover { background: #bfdbfe; }
        .add-warn { font-size:12px; color: #9a6700; background: #fff8c5; border: 1px solid #d4a72c;
                    border-radius: 4px; padding: 3px 7px; margin-top: 3px; display: none; width: 100%; }
        .add-warn.err { color: #82071e; background: #ffebe9; border-color: #ffc1ba; }

        /* conflict legend */
        .conflict-legend { display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
                           font-size:12px; color: #57606a; padding: 8px 14px;
                           background: #f6f8fa; border-bottom: 1px solid #eaeef2; }
        .conflict-legend .cl-item { display: flex; align-items: center; gap: 5px; }
        /* mini-pill demo in legend mimics the real pill look */
        .conflict-legend .cl-pill { font-size:12px; border-radius: 10px; padding: 2px 7px;
                                    border: 1px solid; display: inline-flex; align-items: center; gap: 3px; }

        .empty-cell { color: #8c959f; font-style: italic; }

        /* mobile cards */
        .cards-wrap { display: none; gap: 12px; }
        .card { background: var(--paper); border: 1px solid var(--line); border-radius: 8px; padding: 12px; box-shadow: 0 12px 30px rgba(27,50,40,.08); }
        .card.is-selected { border-color: #0969da; box-shadow: 0 0 0 2px rgba(9, 105, 218, 0.14); }
        .card h3 { margin: 0 0 10px; font-size: 14px; font-weight: 600; }
        .card .role-line { padding: 7px 0; border-top: 1px solid #f1f3f5; }
        .card .role-line-header { font-weight: 600; font-size: 12px; color: #57606a;
                                   text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
        @media (max-width: 720px) {
            .grid-wrap { display: none; }
            .cards-wrap { display: flex; flex-direction: column; }

            /* The card view IS the mobile UI (the dense grid is correctly hidden
               here), so its controls must be touch-sized. Presentation only —
               no behaviour, no handlers, no scheduling logic changed. */
            .snap-card button,
            .snap-card .button,
            .snap-card select,
            .snap-card input:not([type=checkbox]):not([type=radio]) { min-height: 44px; }
            .snap-card input[type=checkbox],
            .snap-card input[type=radio] { width: 20px; height: 20px; }
            .snap-card .sc-edit { min-height: 44px; padding: 0 12px; }
            /* 11px cell text was below the 12px design-system floor. */
            .snapshot-table th,
            .snapshot-table td { font-size: 12px; }

            /* The per-role "add person" rows are the most-used control in the
               mobile card view and sat well under the touch minimum. */
            .add-row input[type=text],
            .add-row button { min-height: 44px; }
            .add-row { gap: 8px; }
            .toolbar input[type=date],
            .toolbar button,
            .toolbar select,
            .event-add input[type=search] { min-height: 44px; }
            .snapshot-controls select { min-height: 44px; }
            .snapshot-controls input[type=checkbox] { width: 20px; height: 20px; }
            .grid-actions button { min-height: 44px; }
            .snapshot-controls { align-items: stretch; }
            .snapshot-controls label { width: 100%; justify-content: space-between; }
            .snapshot-controls select { width: 100%; max-width: 180px; }
            .snap-card {
                flex-basis: min(88vw, 320px);
                min-width: min(88vw, 320px);
            }
            .snap-card .sc-role-grid {
                grid-template-columns: 1fr;
                max-height: 240px;
            }
            .snapshot-table-wrap {
                max-height: min(52vh, 420px);
                border-radius: 10px;
            }
            .snapshot-table {
                min-width: 640px;
            }
            .snapshot-table th,
            .snapshot-table td {
                padding: 8px 9px;
                font-size:12px;
            }
        }

        .actor-line { color: var(--muted); font-size: 12px; margin-bottom: 10px; }
        .actor-line a { color: var(--teal); font-weight: 800; text-decoration: none; }
        
        
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace') ?> data-base="<?= $base ?>" data-current-campus-id="<?= $currentCampusId !== null ? (int) $currentCampusId : '' ?>" data-ministry-id="<?= (int) $ministryId ?>">
    <?= portal_header(
        $basePath,
        '',
        '',
        $campuses,
        $currentCampusId,
        $actor,
        [
            ['href' => $base . '/', 'label' => 'Dashboard', 'icon' => 'dashboard'],
            ['href' => $base . '/ministries', 'label' => 'Ministries', 'icon' => 'ministry'],
            ['href' => $base . '/calendar', 'label' => 'Calendar', 'icon' => 'calendar'],
            ['href' => $base . '/events', 'label' => 'Events', 'icon' => 'events'],
            ['href' => $base . '/people', 'label' => 'People', 'icon' => 'people'],
            ['href' => $base . '/availability', 'label' => 'Availability', 'icon' => 'availability'],
        ],
        [],
        [],
        'Sign in',
        $base . '/login'
    ) ?>
<main id="portal-main" tabindex="-1">


    <div class="wrap">
        <!-- Ministry, campus, range and progress in one block, because those
             are the four things a scheduler checks before touching anything.
             The actor id that used to sit here was developer text: nobody
             filling a rota needs to know they are actor #10. -->
        <header class="ws-head">
            <div class="ws-id">
                <p class="page-kicker">Serving grid</p>
                <h1><?= $safeMinistryName ?></h1>
                <p class="ws-context">
                    <span class="ws-campus"><?= htmlspecialchars($campusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="ws-range" id="wsRange"></span>
                </p>
            </div>
            <div class="ws-progress">
                <p class="ws-progress-line" id="wsProgressLine">Loading…</p>
                <div class="ws-meter" id="wsMeter" role="progressbar"
                     aria-valuemin="0" aria-valuenow="0" aria-valuemax="0"
                     aria-label="Roles filled"><i id="wsMeterFill"></i></div>
                <p class="ws-unfilled" id="wsUnfilled" aria-live="polite"></p>
            </div>
            <nav class="ws-links" aria-label="Related pages">
                <a href="<?= $base ?>/rosters?ministry_id=<?= (int) $ministryId ?>">Posted lists</a>
                <a href="<?= $base ?>/schedules/ministry-print">Print ministry schedule</a>
                <a href="<?= $base ?>/ministries/<?= (int) $ministryId ?>">Back to ministry</a>
            </nav>
        </header>

    <div class="toolbar">
        <input type="hidden" id="ministry_id" value="<?= (int) $ministryId ?>">
        <input type="hidden" id="current_campus_ids" value="<?= htmlspecialchars($currentCampusIdsCsv, ENT_QUOTES, 'UTF-8') ?>">
        <div class="toolbar-readonly">
            <label>Ministry</label>
            <div class="value"><?= $safeMinistryName ?></div>
        </div>
        <div>
            <label for="start">Start</label>
            <input type="date" id="start" value="<?= htmlspecialchars($start, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
            <label for="end">End</label>
            <input type="date" id="end" value="<?= htmlspecialchars($end, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="event-picker" id="eventPicker">
            <label for="eventSearch">Events being scheduled</label>
            <div class="event-chips" id="eventChips" aria-live="polite"></div>
            <!-- A search box on its own looked like a place to type a name and
                 gave no sign that a list existed behind it. The caret makes it a
                 selector you can open and browse, which is what it always was. -->
            <div class="event-add">
                <input type="search" id="eventSearch" placeholder="Choose or search an event…"
                       autocomplete="off" role="combobox" aria-autocomplete="list"
                       aria-controls="eventMenu" aria-expanded="false">
                <button type="button" class="event-add-toggle" id="eventMenuToggle"
                        aria-controls="eventMenu" aria-expanded="false"
                        aria-label="Show events available for scheduling">▾</button>
                <div class="event-menu" id="eventMenu" hidden role="listbox"
                     aria-label="Events available for scheduling"></div>
            </div>
        </div>
        <div>
            <button id="loadBtn" class="secondary" type="button">Load</button>
        </div>
        <div class="toolbar-note"><?= htmlspecialchars($campusScopeNote, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div id="status" class="status"></div>

    <!-- Filters. Present from the start because the question "what is still
         unfilled" is the reason this page is open, and because a filter added
         later to a page with no room for one becomes a redesign. Bulk actions
         (copy last week, repeat, apply to selected dates) belong in this bar
         beside them. -->
    <div class="ws-bar">
        <div class="ws-filters" role="group" aria-label="Show which roles">
            <button type="button" class="ws-chip" data-filter="all" aria-pressed="true">All dates</button>
            <button type="button" class="ws-chip" data-filter="unfilled" aria-pressed="false">Unfilled <span class="ws-cnt" id="cntUnfilled">0</span></button>
            <button type="button" class="ws-chip" data-filter="assigned" aria-pressed="false">Assigned <span class="ws-cnt" id="cntAssigned">0</span></button>
            <button type="button" class="ws-chip" data-filter="conflicts" id="chipConflicts" aria-pressed="false" hidden>Clashes <span class="ws-cnt" id="cntConflicts">0</span></button>
        </div>
        <div class="ws-views" role="group" aria-label="How to lay the dates out">
            <button type="button" class="ws-view" data-view="stacked" aria-pressed="true">By date</button>
            <button type="button" class="ws-view" data-view="grid" aria-pressed="false">Side by side</button>
        </div>
        <div class="ws-save">
            <span class="save-note" id="saveNote">No unsaved changes.</span>
            <button id="saveBtn" type="button" disabled>Save assignments</button>
        </div>
    </div>

    <!-- Copying starts from a date: its own Copy button says which schedule is
         being copied, so this only has to ask where it goes. -->
    <dialog class="copy-dialog" id="copyDialog" aria-labelledby="copyTitle">
        <form method="dialog" class="copy-form">
            <h2 class="copy-title" id="copyTitle">Copy this date</h2>
            <p class="copy-hint" id="copyFromHint"></p>

            <div class="copy-field">
                <span class="copy-label" id="copyToLabel">On to these dates</span>
                <div class="copy-targets" id="copyTargets" role="group" aria-labelledby="copyToLabel"></div>
            </div>

            <div class="copy-field">
                <span class="copy-label" id="copyModeLabel">When a role already has somebody</span>
                <div class="copy-modes" role="radiogroup" aria-labelledby="copyModeLabel">
                    <label class="copy-radio"><input type="radio" name="copyMode" value="fill" checked> Leave them, fill only empty roles</label>
                    <label class="copy-radio"><input type="radio" name="copyMode" value="replace"> Replace them</label>
                </div>
            </div>

            <p class="copy-status" id="copyStatus" role="status"></p>
            <div class="copy-actions">
                <button type="button" id="copyApply">Copy the people over</button>
                <button type="button" class="ws-secondary" id="copyCancel">Cancel</button>
            </div>
        </form>
    </dialog>

    <div class="conflict-legend">
        <strong style="color:#1f2328">Legend:</strong>
        <span class="cl-item">
            <span class="cl-pill" style="background:#fee2e2;border-color:#fca5a5;color:#991b1b">Name <span>⚡</span></span>
            Busy — scheduled at an overlapping event
        </span>
        <span class="cl-item">
            <span class="cl-pill" style="background:#fef9c3;border-color:#fde047;color:#854d0e">Name <span>≡</span></span>
            Multi-role — already in another role this occurrence
        </span>
        <span class="cl-item">
            <span class="cl-pill" style="background:#dbeafe;border-color:#93c5fd;color:#1e40af">Name <span>★</span></span>
            Special — external or non-ministry assignee
        </span>
    </div>

    <div id="workspace" class="workspace"></div>
    <p class="ws-empty" id="wsEmpty" hidden></p>

    </div><!-- /wrap -->

    </main>
<?= portal_footer('Ekklesia', 'Schedule editor') ?>
</div><!-- /shell -->

<script>
// Wire the dashboard's campus selector (rendered by portal_header) to reload
// the schedule editor with the chosen campus filter applied.
(function () {
    var sel = document.getElementById('campusSelect');
    if (!sel) return;
    sel.addEventListener('change', function () {
        var url = new URL(window.location.href);
        if (sel.value) {
            url.searchParams.set('current_campus_id', sel.value);
        } else {
            url.searchParams.delete('current_campus_id');
        }
        window.location.assign(url.toString());
    });
})();
</script>

<script>
(function () {
    'use strict';

    const BASE = <?= json_encode(rtrim($basePath, '/')) ?>;
    const statusEl   = document.getElementById('status');
    const saveBtn    = document.getElementById('saveBtn');
    const saveNote   = document.getElementById('saveNote');
    const loadBtn    = document.getElementById('loadBtn');

    let baseline = null;
    /* Map<"occId:roleId", [{occurrenceId,roleId,personId,id,label,isSpecial}]> */
    let cells = new Map();
    /* Stacked or side-by-side. Remembered per browser: a scheduler who thinks
       in columns should not have to say so on every visit. */
    const VIEW_KEY = 'church_portal_scheduler_view_v1';
    let viewMode = 'stacked';
    let lastCtx = null;
    try { const v = localStorage.getItem(VIEW_KEY); if (v === 'grid' || v === 'stacked') viewMode = v; } catch (e) { /* private mode */ }
    let hasUnsavedChanges = false;
    let canSaveAssignments = false;
    /* null until the first grid response: omit event_ids so the server
       applies the campus default. After that, always send the current set. */
    let selectedEventIds = null;
    let eligibleEvents = [];

    const eventChipsEl = document.getElementById('eventChips');
    const eventSearch = document.getElementById('eventSearch');
    const eventMenu = document.getElementById('eventMenu');
    const eventMenuToggle = document.getElementById('eventMenuToggle');

    /* ── helpers ───────────────────────────────────────────────── */

    function esc(s) {
        return String(s).replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function showStatus(kind, msg) {
        statusEl.className = 'status ' + kind;
        statusEl.textContent = msg;
        statusEl.style.display = 'block';
    }
    function clearStatus() { statusEl.style.display = 'none'; }

    function eventIdsQuery() {
        // null means "we have not chosen yet, use the campus default".
        if (selectedEventIds === null) return '';
        // An empty choice has to look different from no choice at all. Mapping
        // an empty array joined to an empty string, which sent no event_ids
        // parameter — indistinguishable from null — so removing the last event
        // silently brought the campus default straight back and the click
        // looked like it had done nothing. The server already tells the two
        // apart; the browser just never said which it meant.
        if (selectedEventIds.length === 0) return '&event_ids[]=';
        return selectedEventIds.map(function (id) { return '&event_ids[]=' + encodeURIComponent(id); }).join('');
    }

    function renderEventPicker() {
        if (!eventChipsEl) return;
        const selected = selectedEventIds || [];
        const byId = new Map((eligibleEvents || []).map(function (e) { return [e.id, e]; }));
        eventChipsEl.innerHTML = selected.map(function (id) {
            const ev = byId.get(id) || { id: id, title: 'Event #' + id, isDefault: false };
            const def = ev.isDefault ? '<span class="event-chip-default">default</span>' : '';
            return '<span class="event-chip">' +
                '<span class="event-chip-label">' + esc(ev.title) + '</span>' + def +
                '<button type="button" data-remove-event="' + ev.id + '" aria-label="Remove ' + esc(ev.title) + '">×</button>' +
                '</span>';
        }).join('') || '<span class="snapshot-hint">No events selected — add one to schedule.</span>';
        eventChipsEl.querySelectorAll('[data-remove-event]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirmDiscardChanges()) return;
                const id = +btn.getAttribute('data-remove-event');
                selectedEventIds = (selectedEventIds || []).filter(function (x) { return x !== id; });
                load();
            });
        });
    }

    /* Every activity with an occurrence in the loaded window on this campus,
       matched against whatever has been typed. Events already being scheduled
       stay in the list and are marked, rather than being filtered out of it.

       The campus default (usually Sunday Service) is selected when the page
       opens. Allow ministry assignments only nominates that default; it does
       not decide what this menu may offer. */
    function menuOptions() {
        const selected = new Set(selectedEventIds || []);
        const q = (eventSearch?.value || '').trim().toLowerCase();
        return (eligibleEvents || [])
            .filter(function (e) { return !q || String(e.title || '').toLowerCase().includes(q); })
            .map(function (e) { return { ev: e, selected: selected.has(e.id) }; });
    }

    let menuActiveIndex = -1;

    function setMenuOpen(open) {
        if (!eventMenu || !eventSearch) return;
        eventMenu.hidden = !open;
        eventSearch.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (eventMenuToggle) eventMenuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (!open) menuActiveIndex = -1;
    }

    function toggleEventSelection(id) {
        if (!confirmDiscardChanges()) return;
        const current = selectedEventIds || [];
        selectedEventIds = current.indexOf(id) === -1
            ? current.concat([id])
            : current.filter(function (x) { return x !== id; });
        eventSearch.value = '';
        setMenuOpen(false);
        load();
    }

    function renderEventMenu() {
        if (!eventMenu || !eventSearch) return;
        const options = menuOptions();

        if (options.length === 0) {
            // Two different nothings: nothing happens in this window on this
            // campus, vs a search that missed. Neither is fixed by ticking
            // Allow ministry assignments.
            const q = (eventSearch.value || '').trim();
            eventMenu.innerHTML = '<div class="event-menu-empty">' + (
                (eligibleEvents || []).length === 0
                    ? 'No activities happen on this campus in these dates. Change the date range, or create an event that occurs in it.'
                    : 'No activity in these dates matches “' + esc(q) + '”.'
            ) + '</div>';
        } else {
            eventMenu.innerHTML = options.map(function (o, i) {
                const state = o.selected ? 'Scheduling' : (o.ev.isDefault ? 'Default' : 'Add');
                return '<button type="button" role="option" data-toggle-event="' + o.ev.id + '"' +
                    ' aria-selected="' + (o.selected ? 'true' : 'false') + '" data-index="' + i + '">' +
                    '<span class="event-opt-state">' + state + '</span>' +
                    esc(o.ev.title) + '</button>';
            }).join('');
            eventMenu.querySelectorAll('[data-toggle-event]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    toggleEventSelection(+btn.getAttribute('data-toggle-event'));
                });
            });
        }
        setMenuOpen(true);
        highlightMenuOption();
    }

    /* Arrow-key navigation. The menu was a role=listbox that only answered to a
       mouse and to Escape, so a keyboard user could open it and not reach a
       single option in it. */
    function menuButtons() {
        return eventMenu ? Array.prototype.slice.call(eventMenu.querySelectorAll('[data-toggle-event]')) : [];
    }

    function highlightMenuOption() {
        const buttons = menuButtons();
        buttons.forEach(function (b, i) { b.classList.toggle('is-active', i === menuActiveIndex); });
        if (menuActiveIndex >= 0 && buttons[menuActiveIndex]) {
            buttons[menuActiveIndex].scrollIntoView({ block: 'nearest' });
            eventSearch.setAttribute('aria-activedescendant', 'event-opt-' + menuActiveIndex);
            buttons[menuActiveIndex].id = 'event-opt-' + menuActiveIndex;
        } else {
            eventSearch.removeAttribute('aria-activedescendant');
        }
    }

    function moveMenuActive(step) {
        const buttons = menuButtons();
        if (buttons.length === 0) return;
        menuActiveIndex = menuActiveIndex === -1
            ? (step > 0 ? 0 : buttons.length - 1)
            : (menuActiveIndex + step + buttons.length) % buttons.length;
        highlightMenuOption();
    }
    function keyOf(occId, roleId) { return occId + ':' + roleId; }
    function updateSaveUi() {
        saveBtn.disabled = !canSaveAssignments || !hasUnsavedChanges;
        saveNote.textContent = hasUnsavedChanges
            ? 'You have unsaved assignment changes.'
            : 'No unsaved changes.';
        saveNote.className = 'save-note' + (hasUnsavedChanges ? ' is-dirty' : '');
    }
    function markDirty() {
        hasUnsavedChanges = true;
        updateSaveUi();
        // Progress is recomputed by the slot that changed, via refresh().
    }
    function resetDirty(editable) {
        hasUnsavedChanges = false;
        canSaveAssignments = editable;
        updateSaveUi();
    }
    function confirmDiscardChanges() {
        if (!hasUnsavedChanges) return true;
        return window.confirm('You have unsaved assignment changes. Discard them?');
    }
    function fmtOcc(o) {
        const d = new Date(o.startsOn);
        return {
            label: o.eventTitle || ('event ' + o.eventId),
            when:  d.toLocaleString([], { weekday:'short', month:'short', day:'numeric',
                                          hour:'numeric', minute:'2-digit' }),
        };
    }
    /* Resolve typed text to a personId.
       Returns {personId, label, isSpecial}
       - personId > 0  → ministry member
       - personId === 0, label set → external / special assignee */
    function resolveInput(text, nameToId) {
        const trimmed = text.trim();
        if (!trimmed) return null;
        const pid = nameToId[trimmed.toLowerCase()];
        if (pid) return { personId: pid, label: '', isSpecial: false };
        // free-text → treat as external assignee
        return { personId: 0, label: trimmed, isSpecial: true };
    }
    /* Visible name for an assignment row */
    function displayName(a, peopleById) {
        if (a.displayName) return a.displayName;
        if (a.personId > 0) return peopleById[a.personId] || ('person ' + a.personId);
        if (a.label) return a.label;
        return 'external ?';
    }

    /* Build conflict lookup: Map<"personId:occurrenceId", string[]> */
    function buildConflicts(raw) {
        const map = new Map();
        for (const c of (raw || [])) {
            const k = c.person_id + ':' + c.grid_occurrence_id;
            if (!map.has(k)) map.set(k, []);
            map.get(k).push(c.conflict_label);
        }
        return map;
    }
    let personConflicts = new Map(); // rebuilt after each load

    function getRoleConflicts(personId, occId, roleId) {
        const roleConflicts = [];
        if (personId <= 0) return roleConflicts;
        for (const [ck2, carr] of cells) {
            const [cOcc, cRole] = ck2.split(':').map(Number);
            if (cOcc === occId && cRole !== roleId && carr.some(x => x.personId === personId)) {
                const roleName = (baseline.roles || []).find(r => r.id === cRole)?.name || 'another role';
                roleConflicts.push(roleName);
            }
        }
        return roleConflicts;
    }

    function assignmentVisualState(a, occId, roleId, ministryPersonIds) {
        const timeConflicts = a.personId > 0 ? (personConflicts.get(a.personId + ':' + occId) || []) : [];
        const roleConflicts = getRoleConflicts(a.personId, occId, roleId);
        const isUnmatchedSpecial = a.personId === 0;
        const isSpecialAssignee = a.isSpecial || (a.personId > 0 && !ministryPersonIds[a.personId]);
        let pillClass = 'pill';
        if (timeConflicts.length > 0) pillClass += ' p-busy';
        else if (roleConflicts.length > 0) pillClass += ' p-role';
        else if (isUnmatchedSpecial) pillClass += ' p-unmatched';
        else if (isSpecialAssignee) pillClass += ' p-special';
        return { timeConflicts, roleConflicts, isUnmatchedSpecial, isSpecialAssignee, pillClass };
    }
    /* ── load ──────────────────────────────────────────────────── */

    /* ── the workspace ─────────────────────────────────────────────
     *
     * One surface, read as Date → Role → Assignment.
     *
     * There used to be two: a read-only "snapshot" in three view modes and,
     * below it, an editable grid showing the same occurrences again. A
     * scheduler scrolled past a copy of the answer to reach the place they
     * could change it, and the two could disagree while a save was pending.
     * This is the only representation now, and it is the editable one.
     *
     * A slot that is empty offers one control — Assign. Everything else
     * (member search, external assignee, remove) appears when it is asked
     * for. The previous cell held two labelled text inputs and two buttons
     * whether or not anybody intended to use them: for this ministry's three
     * roles across four Sundays that was 122 controls for 12 decisions.
     */
    let filterMode = 'all';

    function slotState(arr, occId, roleId, ministryPersonIds) {
        if (!arr || arr.length === 0) return 'unfilled';
        for (const a of arr) {
            const st = assignmentVisualState(a, occId, roleId, ministryPersonIds);
            if (st.timeConflicts.length > 0 || st.roleConflicts.length > 0) return 'conflict';
        }
        return 'assigned';
    }

    function slotMatchesFilter(state) {
        if (filterMode === 'all') return true;
        // A date that has already happened is history, not work. It stays under
        // All and leaves the other filters alone — otherwise the chip counting
        // twelve unfilled roles reveals fifteen when you press it.
        if (state === 'past') return false;
        if (filterMode === 'unfilled') return state === 'unfilled';
        if (filterMode === 'assigned') return state === 'assigned' || state === 'conflict';
        if (filterMode === 'conflicts') return state === 'conflict';
        return true;
    }

    /* Counts for the header. Derived from the same cells map the grid renders,
       so the number at the top and the rows beneath it cannot disagree. */
    function scheduleTotals() {
        const occs = (baseline && baseline.occurrences) ? baseline.occurrences : [];
        const roles = (baseline && baseline.roles) ? baseline.roles : [];
        const ministryPersonIds = {};
        for (const p of ((baseline && baseline.people) || [])) ministryPersonIds[p.id] = true;
        const today = new Date(); today.setHours(0, 0, 0, 0);
        let total = 0, filled = 0, conflicts = 0, unfilled = 0;
        for (const occ of occs) {
            if (new Date(occ.startsOn) < today) continue;   // past dates are not work
            for (const role of roles) {
                const st = slotState(cells.get(keyOf(occ.id, role.id)), occ.id, role.id, ministryPersonIds);
                total++;
                if (st === 'unfilled') unfilled++; else filled++;
                if (st === 'conflict') conflicts++;
            }
        }
        return { total, filled, unfilled, conflicts };
    }

    function updateProgress() {
        const t = scheduleTotals();
        const pct = t.total === 0 ? 0 : Math.round((t.filled / t.total) * 100);
        const line = document.getElementById('wsProgressLine');
        const meter = document.getElementById('wsMeterFill');
        const meterBox = document.getElementById('wsMeter');
        const unfilled = document.getElementById('wsUnfilled');
        if (line) line.textContent = t.total === 0
            ? 'Nothing to schedule in this range.'
            : t.filled + ' of ' + t.total + ' roles filled';
        if (meter) meter.style.width = pct + '%';
        if (meterBox) {
            meterBox.setAttribute('aria-valuenow', String(t.filled));
            meterBox.setAttribute('aria-valuemax', String(t.total));
            meterBox.setAttribute('aria-valuetext', t.filled + ' of ' + t.total + ' roles filled');
        }
        if (unfilled) {
            unfilled.textContent = t.unfilled === 0
                ? (t.total === 0 ? '' : 'Every role is filled.')
                : (t.unfilled === 1 ? '1 role still needs somebody' : t.unfilled + ' roles still need somebody');
            unfilled.classList.toggle('is-open', t.unfilled > 0);
        }
        // Counts on the filter chips, so the reader can see where the work is
        // without switching to find out.
        const set = (id, n) => { const e = document.getElementById(id); if (e) e.textContent = String(n); };
        set('cntUnfilled', t.unfilled);
        updateOccCounts();
        set('cntAssigned', t.filled); set('cntConflicts', t.conflicts);
        const cc = document.getElementById('chipConflicts');
        if (cc) cc.hidden = t.conflicts === 0;   // no conflicts, no chip to wonder about
    }

    /* The tally in each date heading. Rendered once at first draw, it went on
       saying 0/3 while a name sat in the row beneath it. */
    function updateOccCounts() {
        const wrap = document.getElementById('workspace');
        if (!wrap || !baseline) return;
        const roles = baseline.roles || [];
        const ministryPersonIds = {};
        for (const p of (baseline.people || [])) ministryPersonIds[p.id] = true;
        // Stacked puts the count in the date's heading, the grid puts it in the
        // column header. Both carry data-occ-id and an .occ-count, so one loop
        // over the counters serves either layout.
        for (const el of wrap.querySelectorAll('.occ-count')) {
            const host = el.closest('[data-occ-id]');
            if (!host) continue;
            const occId = Number(host.dataset.occId);
            let filled = 0;
            for (const role of roles) {
                if (slotState(cells.get(keyOf(occId, role.id)), occId, role.id, ministryPersonIds) !== 'unfilled') filled++;
            }
            el.textContent = filled + '/' + roles.length;
            el.classList.toggle('is-done', roles.length > 0 && filled === roles.length);
        }
        refreshCopyButtons();
    }

    /* One role on one date. */
    function makeSlot(occ, role, isPast, ctx, tag) {
        const k = keyOf(occ.id, role.id);
        // A list item inside a <td> is not a list; the grid asks for a div.
        const li = document.createElement(tag || 'li');
        li.className = 'slot';
        li.dataset.key = k;

        const name = document.createElement('span');
        name.className = 'slot-role';
        name.textContent = role.name;
        li.appendChild(name);

        const body = document.createElement('div');
        body.className = 'slot-body';
        li.appendChild(body);

        const people = document.createElement('div');
        people.className = 'slot-people';
        body.appendChild(people);

        // The disclosure: built once, shown on request.
        let panel = null;

        function refresh() {
            const arr = cells.get(k) || [];
            const state = isPast ? 'past' : slotState(arr, occ.id, role.id, ctx.ministryPersonIds);
            li.dataset.state = state;
            li.classList.toggle('is-unfilled', state === 'unfilled');
            people.innerHTML = '';

            if (arr.length === 0) {
                const em = document.createElement('span');
                em.className = 'slot-empty';
                em.textContent = isPast ? 'Nobody was assigned' : 'Unfilled';
                people.appendChild(em);
            } else if (isPast) {
                const line = document.createElement('span');
                line.className = 'slot-past';
                line.textContent = arr.map(a => displayName(a, ctx.peopleById)).join(', ');
                people.appendChild(line);
            } else {
                for (const a of arr.slice()) {
                    people.appendChild(makePill(a, occ, role, ctx, refresh));
                }
            }
            updateProgress();
            applyFilter();
        }

        if (!isPast && ctx.editable) {
            const assign = document.createElement('button');
            assign.type = 'button';
            assign.className = 'slot-assign';
            assign.setAttribute('aria-expanded', 'false');
            assign.textContent = 'Assign';
            assign.setAttribute('aria-label', 'Assign somebody to ' + role.name + ' on ' + ctx.occLabel(occ));
            assign.addEventListener('click', function () {
                if (!panel) { panel = buildPanel(occ, role, ctx, refresh); body.appendChild(panel.el); }
                const open = panel.el.hidden;
                panel.el.hidden = !open;
                assign.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) panel.focus();
            });
            body.appendChild(assign);
        }

        refresh();
        return li;
    }

    /* A name, its state icons, and the control to take it off. */
    function makePill(a, occ, role, ctx, refresh) {
        const state = assignmentVisualState(a, occ.id, role.id, ctx.ministryPersonIds);
        const pill = document.createElement('span');
        pill.className = state.pillClass;
        pill.appendChild(document.createTextNode(displayName(a, ctx.peopleById)));

        const icon = (glyph, label, title) => {
            const i = document.createElement('span');
            i.className = 'cb-icon'; i.textContent = glyph;
            i.setAttribute('aria-label', label); i.title = title;
            pill.appendChild(i);
        };
        if (state.timeConflicts.length > 0) {
            icon('⚡', 'Busy: scheduled at overlapping event', state.timeConflicts.join(' | '));
        }
        if (state.roleConflicts.length > 0) {
            icon('≡', 'Multi-role: also assigned as ' + state.roleConflicts.join(', '),
                 'Also in this occurrence as: ' + state.roleConflicts.join(', '));
        }
        if (state.isUnmatchedSpecial && state.timeConflicts.length === 0 && state.roleConflicts.length === 0) {
            icon('?', 'Unmatched external assignee',
                 'Not matched to a person record, so availability and conflict checks are unavailable');
        }
        if (state.isSpecialAssignee && !state.isUnmatchedSpecial
            && state.timeConflicts.length === 0 && state.roleConflicts.length === 0) {
            icon('★', 'External / non-ministry assignee', 'External or non-ministry assignee');
        }

        if (ctx.editable) {
            const rm = document.createElement('button');
            rm.type = 'button'; rm.className = 'rm'; rm.textContent = '×';
            rm.title = 'Remove';
            rm.setAttribute('aria-label', 'Remove ' + displayName(a, ctx.peopleById)
                + ' from ' + role.name + ' on ' + ctx.occLabel(occ));
            rm.addEventListener('click', function () {
                const k = keyOf(occ.id, role.id);
                cells.set(k, (cells.get(k) || []).filter(x => x !== a));
                markDirty();
                refresh();
            });
            pill.appendChild(rm);
        }
        return pill;
    }

    /* The disclosure panel: member search first, external assignee behind a
       second step. Both were permanently on screen before, twice per slot. */
    function buildPanel(occ, role, ctx, refresh) {
        const k = keyOf(occ.id, role.id);
        const el = document.createElement('div');
        el.className = 'slot-panel';
        el.hidden = true;

        const listId = 'dl-' + k;
        const row = document.createElement('div');
        row.className = 'sp-row';
        const input = document.createElement('input');
        input.type = 'text'; input.setAttribute('list', listId);
        input.className = 'sp-input';
        input.placeholder = 'Search ministry member…';
        input.autocomplete = 'off';
        input.setAttribute('aria-label', 'Ministry member for ' + role.name + ' on ' + ctx.occLabel(occ));
        const dl = document.createElement('datalist'); dl.id = listId;
        for (const p of ctx.people) {
            const o = document.createElement('option'); o.value = p.displayName; dl.appendChild(o);
        }
        const add = document.createElement('button');
        add.type = 'button'; add.className = 'sp-add'; add.textContent = 'Add';
        row.appendChild(input); row.appendChild(dl); row.appendChild(add);
        el.appendChild(row);

        const err = document.createElement('p');
        err.className = 'sp-err'; err.hidden = true; err.setAttribute('role', 'alert');
        el.appendChild(err);

        function commit(text, nameToId, forceSpecial) {
            const resolved = resolveInput(text, nameToId);
            if (!resolved) { return false; }
            if (forceSpecial) { resolved.personId = 0; resolved.isSpecial = true; resolved.label = text.trim(); }
            const arr = cells.get(k) || [];
            const dup = arr.some(x => resolved.personId
                ? x.personId === resolved.personId
                : (x.label || '').toLowerCase() === (resolved.label || '').toLowerCase());
            if (dup) { err.textContent = 'That person is already in this role on this date.'; err.hidden = false; return false; }
            err.hidden = true;
            arr.push({
                occurrenceId: occ.id, roleId: role.id, id: null,
                personId: resolved.personId || null,
                label: resolved.label || '', displayName: '',
                isSpecial: !!resolved.isSpecial,
            });
            cells.set(k, arr);
            markDirty();
            refresh();
            return true;
        }

        add.addEventListener('click', function () {
            if (commit(input.value, ctx.nameToId, false)) { input.value = ''; input.focus(); }
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); add.click(); }
        });

        /* Second step, not a second permanent form. */
        const more = document.createElement('button');
        more.type = 'button'; more.className = 'sp-more';
        more.setAttribute('aria-expanded', 'false');
        more.textContent = 'Someone outside the ministry…';
        el.appendChild(more);

        const special = document.createElement('div');
        special.className = 'sp-row sp-special'; special.hidden = true;
        const sInput = document.createElement('input');
        sInput.type = 'text'; sInput.className = 'sp-input';
        sInput.placeholder = 'External or non-ministry assignee name';
        sInput.autocomplete = 'off';
        const sListId = 'dls-' + k;
        sInput.setAttribute('list', sListId);
        sInput.setAttribute('aria-label', 'External assignee for ' + role.name + ' on ' + ctx.occLabel(occ));
        const sDl = document.createElement('datalist'); sDl.id = sListId;
        for (const p of ctx.specialCandidates) {
            const o = document.createElement('option'); o.value = p.displayName; sDl.appendChild(o);
        }
        const sAdd = document.createElement('button');
        sAdd.type = 'button'; sAdd.className = 'sp-add sp-add-special'; sAdd.textContent = 'Add ★';
        special.appendChild(sInput); special.appendChild(sDl); special.appendChild(sAdd);
        el.appendChild(special);

        more.addEventListener('click', function () {
            const open = special.hidden;
            special.hidden = !open;
            more.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) sInput.focus();
        });
        sAdd.addEventListener('click', function () {
            if (commit(sInput.value, ctx.specialNameToId, true)) { sInput.value = ''; sInput.focus(); }
        });
        sInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); sAdd.click(); }
        });

        return { el: el, focus: () => input.focus() };
    }

    function applyFilter() {
        const wrap = document.getElementById('workspace');
        if (!wrap) return;
        let shownSlots = 0;
        if (viewMode === 'grid') {
            // Hiding single cells would tear holes in the grid and defeat the
            // reason to be in it, so the filter works by the row: a role stays
            // if any of its dates match, and its whole line stays readable.
            for (const tr of wrap.querySelectorAll('.ws-grid tbody tr')) {
                let visible = 0, unfilled = 0;
                for (const slot of tr.querySelectorAll('.slot')) {
                    const state = slot.dataset.state || 'unfilled';
                    if (slotMatchesFilter(state)) visible++;
                    if (state === 'unfilled') unfilled++;
                }
                tr.hidden = visible === 0;
                tr.classList.toggle('is-unfilled-row', unfilled > 0);
                shownSlots += visible;
            }
        } else {
            for (const sec of wrap.querySelectorAll('.occ')) {
                let visible = 0;
                for (const slot of sec.querySelectorAll('.slot')) {
                    const ok = slotMatchesFilter(slot.dataset.state || 'unfilled');
                    slot.hidden = !ok;
                    if (ok) visible++;
                }
                // A date with nothing matching is not a date worth a heading.
                sec.hidden = visible === 0;
                shownSlots += visible;
            }
        }
        const none = document.getElementById('wsEmpty');
        if (none) {
            none.hidden = shownSlots > 0;
            none.textContent = filterMode === 'conflicts'
                ? 'No clashes in this range.'
                : (filterMode === 'unfilled' ? 'Nothing unfilled — every role has somebody.'
                                             : 'Nothing to show for this filter.');
        }
    }

    /* One date at a time: a heading, then every role beneath it. Right for
       filling a single Sunday. */
    function renderStackedLayout(wrap, occs, roles, ctx, today) {
        for (const occ of occs) {
            const isPast = new Date(occ.startsOn) < today;
            const f = fmtOcc(occ);
            const sec = document.createElement('section');
            sec.className = 'occ' + (isPast ? ' is-past' : '');
            sec.dataset.occId = String(occ.id);

            const head = document.createElement('div');
            head.className = 'occ-head';
            const h = document.createElement('h3');
            h.className = 'occ-title';
            h.textContent = f.when;
            const sub = document.createElement('span');
            sub.className = 'occ-sub';
            sub.textContent = f.label + (isPast ? ' \u00b7 past' : '');
            const count = document.createElement('span');
            count.className = 'occ-count';
            head.appendChild(h); head.appendChild(sub); head.appendChild(count);
            if (ctx.editable) head.appendChild(makeCopyButton(occ, 'occ-copy'));
            sec.appendChild(head);

            const list = document.createElement('ul');
            list.className = 'occ-roles';
            for (const role of roles) list.appendChild(makeSlot(occ, role, isPast, ctx));
            sec.appendChild(list);
            wrap.appendChild(sec);
        }
    }

    /* Dates across, roles down. A real table: the dates are column headers and
       the roles are row headers, which is what lets a screen reader say "Server,
       Sun 6 Sep" for a cell, and what lets everybody else compare a person's
       weeks by reading along one line. */
    function renderGridLayout(wrap, occs, roles, ctx, today) {
        const box = document.createElement('div');
        box.className = 'ws-grid-wrap';
        const table = document.createElement('table');
        table.className = 'ws-grid';

        const thead = document.createElement('thead');
        const hrow = document.createElement('tr');
        const corner = document.createElement('th');
        corner.scope = 'col';
        corner.className = 'gcorner';
        corner.textContent = 'Role';
        hrow.appendChild(corner);
        for (const occ of occs) {
            const isPast = new Date(occ.startsOn) < today;
            const f = fmtOcc(occ);
            const th = document.createElement('th');
            th.scope = 'col';
            th.className = 'gdate';
            th.dataset.occId = String(occ.id);
            const when = document.createElement('span');
            when.className = 'gdate-when';
            when.textContent = f.when;
            const sub = document.createElement('span');
            sub.className = 'gdate-sub';
            sub.textContent = f.label + (isPast ? ' \u00b7 past' : '');
            const cnt = document.createElement('span');
            cnt.className = 'gdate-count occ-count';
            th.appendChild(when); th.appendChild(sub); th.appendChild(cnt);
            if (ctx.editable) th.appendChild(makeCopyButton(occ, 'gdate-copy'));
            hrow.appendChild(th);
        }
        thead.appendChild(hrow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        for (const role of roles) {
            const tr = document.createElement('tr');
            tr.dataset.roleId = String(role.id);
            const rh = document.createElement('th');
            rh.scope = 'row';
            rh.className = 'grole';
            rh.textContent = role.name;
            tr.appendChild(rh);
            for (const occ of occs) {
                const isPast = new Date(occ.startsOn) < today;
                const td = document.createElement('td');
                td.className = 'gcell' + (isPast ? ' is-past' : '');
                td.dataset.occId = String(occ.id);
                td.appendChild(makeSlot(occ, role, isPast, ctx, 'div'));
                tr.appendChild(td);
            }
            tbody.appendChild(tr);
        }
        table.appendChild(tbody);
        box.appendChild(table);
        wrap.appendChild(box);
    }

    function renderWorkspace() {
        const wrap = document.getElementById('workspace');
        if (!wrap) return false;
        wrap.innerHTML = '';

        const occs = (baseline && baseline.occurrences) ? baseline.occurrences : [];
        const roles = (baseline && baseline.roles) ? baseline.roles : [];
        const people = (baseline && baseline.people) ? baseline.people : [];
        const specialCandidates = (baseline && baseline.specialCandidates) ? baseline.specialCandidates : [];
        people.sort((a, b) => a.displayName.localeCompare(b.displayName));
        specialCandidates.sort((a, b) => a.displayName.localeCompare(b.displayName));

        const peopleById = {}, nameToId = {}, ministryPersonIds = {}, specialNameToId = {};
        for (const p of people) {
            peopleById[p.id] = p.displayName;
            nameToId[p.displayName.toLowerCase()] = p.id;
            ministryPersonIds[p.id] = true;
        }
        for (const p of specialCandidates) {
            peopleById[p.id] = peopleById[p.id] || p.displayName;
            specialNameToId[p.displayName.toLowerCase()] = p.id;
        }

        const today = new Date(); today.setHours(0, 0, 0, 0);
        const editable = occs.some(o => new Date(o.startsOn) >= today);

        const ctx = {
            peopleById, nameToId, specialNameToId, people, specialCandidates,
            ministryPersonIds, editable,
            occLabel: (o) => { const f = fmtOcc(o); return f.label + ', ' + f.when; },
        };

        if (occs.length === 0) {
            const p = document.createElement('p');
            p.className = 'ws-none';
            p.textContent = 'No dates in this range. Choose an event above, or widen the dates.';
            wrap.appendChild(p);
            updateProgress();
            return false;
        }

        lastCtx = ctx;
        if (viewMode === 'grid') renderGridLayout(wrap, occs, roles, ctx, today);
        else renderStackedLayout(wrap, occs, roles, ctx, today);

        updateProgress();
        applyFilter();
        return editable;
    }

    async function load() {
        clearStatus();
        const ministryId = +document.getElementById('ministry_id').value;
        const currentCampusIds = document.getElementById('current_campus_ids').value;
        const start      = document.getElementById('start').value;
        const end        = document.getElementById('end').value;
        if (!ministryId || !start || !end) { showStatus('err', 'ministry_id, start, and end are required.'); return; }

        const url = BASE + '/api/schedules/grid?ministry_id=' + ministryId
                  + '&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end)
              + (currentCampusIds ? '&current_campus_ids=' + encodeURIComponent(currentCampusIds) : '')
              + eventIdsQuery();
        let res;
        try { res = await fetch(url, { credentials: 'same-origin' }); }
        catch (e) { showStatus('err', 'Network error: ' + e.message); return; }

        if (res.status === 401) {
            window.location.href = BASE + '/login?next=' + encodeURIComponent(location.pathname + location.search);
            return;
        }
        let data; try { data = await res.json(); } catch (e) { data = {}; }
        if (!res.ok) { showStatus('err', data.error || ('Load failed: HTTP ' + res.status)); return; }

        baseline = data;
        eligibleEvents = data.schedulingEvents || [];
        selectedEventIds = Array.isArray(data.selectedEventIds) ? data.selectedEventIds.map(Number) : [];
        renderEventPicker();
        cells    = new Map();
        for (const a of (data.assignments || [])) {
            const k = keyOf(a.occurrenceId, a.roleId);
            if (!cells.has(k)) cells.set(k, []);
            cells.get(k).push({
                occurrenceId: a.occurrenceId, roleId: a.roleId,
                personId: a.personId, id: a.id ?? null,
                label: a.label || '', displayName: a.displayName || '', isSpecial: !a.personId && !!a.label,
            });
        }

        personConflicts = buildConflicts(data.conflicts);

        const editable = renderWorkspace();
        resetDirty(editable);
        refreshCopyButtons();
        renderRange();
        if (!editable) showStatus('ok', 'All occurrences in this window are in the past — view-only.');
    }

    /* ── save ──────────────────────────────────────────────────── */

    async function save() {
        clearStatus();
        const ministryId = +document.getElementById('ministry_id').value;
        const currentCampusIds = document.getElementById('current_campus_ids').value;
        const start      = document.getElementById('start').value;
        const end        = document.getElementById('end').value;

        const assignments = [];
        for (const arr of cells.values()) {
            for (const a of arr) {
                assignments.push({ id: a.id ?? null, occurrenceId: a.occurrenceId,
                                   roleId: a.roleId, personId: a.personId,
                                   label: a.label || '' });
            }
        }
        saveBtn.disabled = true;
        let res;
        try {
            res = await fetch(BASE + '/api/schedules/assignments', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ ministryId, start, end, current_campus_ids: currentCampusIds ? currentCampusIds.split(',') : [], eventIds: selectedEventIds || [], assignments }),
            });
        } catch (e) { showStatus('err', 'Network error: ' + e.message); saveBtn.disabled = false; return; }

        let data; try { data = await res.json(); } catch (e) { data = {}; }
        if (!res.ok) { showStatus('err', data.error || ('Save failed: HTTP ' + res.status)); saveBtn.disabled = false; return; }

        showStatus('ok', 'Saved ' + (data.assignmentCount ?? 0) + ' assignment(s). Reloading…');
        await load();
    }

    /* ── wire up ───────────────────────────────────────────────── */

    /* The date range, said once in the header rather than only inside two date
       inputs a reader has to interpret. */
    function renderRange() {
        const el = document.getElementById('wsRange');
        if (!el) return;
        const s = document.getElementById('start').value;
        const e = document.getElementById('end').value;
        if (!s || !e) { el.textContent = ''; return; }
        const f = (v) => new Date(v + 'T00:00:00')
            .toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
        el.textContent = f(s) + ' – ' + f(e);
    }

    /* Filters change what is shown, never what is stored. Switching to
       Unfilled and back must not disturb an unsaved edit, so this only
       toggles visibility. */
    for (const chip of document.querySelectorAll('.ws-chip')) {
        chip.addEventListener('click', function () {
            filterMode = chip.dataset.filter || 'all';
            for (const c of document.querySelectorAll('.ws-chip')) {
                c.setAttribute('aria-pressed', c === chip ? 'true' : 'false');
            }
            applyFilter();
        });
    }

    /* ── copy a date ───────────────────────────────────────────────
     *
     * The control lives on the date it copies: its own Copy button names the
     * source, so the dialog only has to ask the question the click has not
     * already answered -- which dates does it go on to?
     *
     * It copies people, never assignment rows: every copied entry is created
     * fresh (id: null) so the source date keeps its own assignments. The copy
     * lands unsaved, to be checked against the clash markers before saving.
     */
    let copySourceId = 0;

    function copySetStatus(msg, kind) {
        const el = document.getElementById('copyStatus');
        if (!el) return;
        el.textContent = msg || '';
        el.className = 'copy-status' + (kind ? ' is-' + kind : '');
    }

    function filledRoleCount(occId) {
        const roles = (baseline && baseline.roles) ? baseline.roles : [];
        let filled = 0;
        for (const role of roles) {
            if ((cells.get(keyOf(occId, role.id)) || []).length > 0) filled++;
        }
        return filled;
    }

    function futureOccurrences() {
        const occs = (baseline && baseline.occurrences) ? baseline.occurrences : [];
        const today = new Date(); today.setHours(0, 0, 0, 0);
        return occs.filter(o => new Date(o.startsOn) >= today);
    }

    /* A date can be copied when it has somebody on it and there is somewhere
       still to come to put them. Refreshed with the counts, so the button stops
       being offered the moment its date is emptied. */
    function copyButtonState(occId) {
        const targets = futureOccurrences().filter(o => Number(o.id) !== Number(occId));
        if (!canSaveAssignments) return { enabled: false, why: 'These dates have all passed.' };
        if (filledRoleCount(occId) === 0) return { enabled: false, why: 'Nobody is on this date yet.' };
        if (targets.length === 0) return { enabled: false, why: 'There is no other date still to come in this range.' };
        return { enabled: true, why: '' };
    }

    function makeCopyButton(occ, cls) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = cls;
        b.dataset.copyFor = String(occ.id);
        b.textContent = 'Copy';
        const f = fmtOcc(occ);
        b.setAttribute('aria-label', 'Copy the people from ' + f.when + ', ' + f.label + ' on to other dates');
        b.addEventListener('click', function () { openCopyDialog(occ.id); });
        return b;
    }

    function refreshCopyButtons() {
        for (const b of document.querySelectorAll('[data-copy-for]')) {
            const st = copyButtonState(Number(b.dataset.copyFor));
            b.disabled = !st.enabled;
            b.title = st.why;
        }
    }

    function openCopyDialog(occId) {
        const dlg = document.getElementById('copyDialog');
        const targets = document.getElementById('copyTargets');
        if (!dlg || !targets || !baseline) return;

        copySourceId = Number(occId);
        const occ = (baseline.occurrences || []).find(o => Number(o.id) === copySourceId);
        if (!occ) return;
        const f = fmtOcc(occ);
        const roles = baseline.roles || [];
        const filled = filledRoleCount(copySourceId);

        document.getElementById('copyTitle').textContent = 'Copy ' + f.when;
        document.getElementById('copyFromHint').textContent =
            f.label + ' · ' + filled + ' of ' + roles.length + ' roles have somebody.';

        targets.innerHTML = '';
        const list = futureOccurrences().filter(o => Number(o.id) !== copySourceId);
        if (list.length === 0) {
            const none = document.createElement('p');
            none.className = 'copy-none';
            none.textContent = 'There is no other date still to come in this range. Widen the dates to copy further ahead.';
            targets.appendChild(none);
        }
        for (const o of list) {
            const g = fmtOcc(o);
            const row = document.createElement('label');
            row.className = 'copy-target';
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.value = String(o.id);
            box.dataset.copyTarget = '1';
            const text = document.createElement('span');
            text.textContent = g.when + ' · ' + g.label + ' (' + filledRoleCount(o.id) + '/' + roles.length + ' filled)';
            row.appendChild(box); row.appendChild(text);
            targets.appendChild(row);
        }

        copySetStatus('');
        const mode = document.querySelector('input[name="copyMode"][value="fill"]');
        if (mode) mode.checked = true;
        if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
        const firstBox = targets.querySelector('[data-copy-target]');
        if (firstBox) firstBox.focus();
    }

    function closeCopyDialog() {
        const dlg = document.getElementById('copyDialog');
        if (!dlg) return;
        if (typeof dlg.close === 'function' && dlg.open) dlg.close(); else dlg.removeAttribute('open');
        const back = document.querySelector('[data-copy-for="' + copySourceId + '"]');
        if (back) back.focus();
    }

    function applyCopy() {
        if (!baseline || !copySourceId) return;
        const fromId = copySourceId;
        const targetIds = Array.from(document.querySelectorAll('[data-copy-target]'))
            .filter(b => b.checked).map(b => Number(b.value));
        const modeEl = document.querySelector('input[name="copyMode"]:checked');
        const mode = modeEl ? modeEl.value : 'fill';
        const roles = baseline.roles || [];

        if (targetIds.length === 0) { copySetStatus('Choose at least one date to copy on to.', 'err'); return; }

        let copied = 0, left = 0;
        for (const toId of targetIds) {
            for (const role of roles) {
                const src = cells.get(keyOf(fromId, role.id)) || [];
                if (src.length === 0) continue;
                const dstKey = keyOf(toId, role.id);
                const dst = cells.get(dstKey) || [];
                if (dst.length > 0 && mode === 'fill') { left++; continue; }
                cells.set(dstKey, src.map(a => ({
                    occurrenceId: toId, roleId: role.id,
                    personId: a.personId, id: null,
                    label: a.label || '', displayName: a.displayName || '',
                    isSpecial: !!a.isSpecial,
                })));
                copied += src.length;
            }
        }

        if (copied === 0) {
            copySetStatus('Every role on those dates already has somebody. Choose Replace to overwrite.', 'err');
            return;
        }

        markDirty();
        renderWorkspace();
        closeCopyDialog();
        const dates = targetIds.length === 1 ? 'one date' : targetIds.length + ' dates';
        showStatus('ok', 'Copied ' + copied + ' assignment' + (copied === 1 ? '' : 's') + ' on to ' + dates
            + (left > 0 ? ', leaving ' + left + ' role' + (left === 1 ? '' : 's') + ' that already had somebody' : '')
            + '. Nothing is saved yet — check the clash markers, then Save assignments.');
    }

    document.getElementById('copyApply')?.addEventListener('click', applyCopy);
    document.getElementById('copyCancel')?.addEventListener('click', closeCopyDialog);
    document.getElementById('copyDialog')?.addEventListener('cancel', function (e) {
        e.preventDefault();
        closeCopyDialog();
    });

    /* Layout, like the filters, changes only how the same unsaved state is
       drawn -- re-rendering reads `cells`, so switching mid-edit keeps every
       pending change. */
    function syncViewButtons() {
        for (const b of document.querySelectorAll('.ws-view')) {
            b.setAttribute('aria-pressed', b.dataset.view === viewMode ? 'true' : 'false');
        }
    }
    for (const btn of document.querySelectorAll('.ws-view')) {
        btn.addEventListener('click', function () {
            const next = btn.dataset.view === 'grid' ? 'grid' : 'stacked';
            if (next === viewMode) return;
            viewMode = next;
            try { localStorage.setItem(VIEW_KEY, viewMode); } catch (e) { /* private mode */ }
            syncViewButtons();
            if (baseline) renderWorkspace();
        });
    }
    syncViewButtons();

    loadBtn.addEventListener('click', function () {
        if (!confirmDiscardChanges()) return;
        load();
    });
    saveBtn.addEventListener('click', save);
    if (eventSearch) {
        eventSearch.addEventListener('focus', renderEventMenu);
        eventSearch.addEventListener('input', function () { menuActiveIndex = -1; renderEventMenu(); });
        eventSearch.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { setMenuOpen(false); return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (eventMenu.hidden) { renderEventMenu(); return; }
                moveMenuActive(e.key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (e.key === 'Home' || e.key === 'End') {
                if (eventMenu.hidden) return;
                e.preventDefault();
                const buttons = menuButtons();
                if (buttons.length === 0) return;
                menuActiveIndex = e.key === 'Home' ? 0 : buttons.length - 1;
                highlightMenuOption();
                return;
            }
            if (e.key === 'Enter') {
                const buttons = menuButtons();
                if (!eventMenu.hidden && menuActiveIndex >= 0 && buttons[menuActiveIndex]) {
                    // Enter picks the highlighted option rather than submitting
                    // the toolbar, which would reload the grid and lose it.
                    e.preventDefault();
                    buttons[menuActiveIndex].click();
                }
            }
        });
        if (eventMenuToggle) {
            eventMenuToggle.addEventListener('click', function () {
                if (eventMenu.hidden) { eventSearch.focus(); renderEventMenu(); }
                else { setMenuOpen(false); }
            });
        }
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.event-add')) setMenuOpen(false);
        });
    }
    window.addEventListener('beforeunload', function (e) {
        if (!hasUnsavedChanges) return;
        e.preventDefault();
        e.returnValue = '';
    });
    document.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (link.target === '_blank') return;
            if (!confirmDiscardChanges()) {
                e.preventDefault();
            }
        });
    });
    load();

})();
</script>
</body>
</html>
