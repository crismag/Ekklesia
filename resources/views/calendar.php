<?php
/** @var array<string,mixed> $campusSelector */
/** @var string $basePath */
/** @var ?array<string,mixed> $actor */
/** @var array<int,array<string,mixed>> $availableMinistries */
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$permissions = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
$canViewSelf = $actor !== null && in_array('view_own_assignments', $permissions, true) && ($actor['personId'] ?? null) !== null;
$canStaffRoles = $actor !== null && in_array('manage_schedules', $permissions, true);
$canSaveViews = $actor !== null && (int) ($actor['actorId'] ?? 0) > 0;
$canManageCalendarSettings = $actor !== null && (
    in_array('manage_events', $permissions, true)
    || in_array('manage_schedules', $permissions, true)
    || (bool) ($actor['isPortalWideAdmin'] ?? false)
);
$campusSelector = is_array($campusSelector ?? null) ? $campusSelector : ['campuses' => [], 'defaultCampusId' => null];
$campuses = is_array($campusSelector['campuses'] ?? null) ? $campusSelector['campuses'] : [];
$ministries = is_array($availableMinistries ?? null) ? $availableMinistries : [];
// On the calendar page we want a multi-campus default, which surfaces every
// event regardless of campus.
// "All campuses" is selected unless the URL pins a specific campus_id.
$rawCampusFilter = $_GET['current_campus_id'] ?? null;
$pinnedCampusId = (is_string($rawCampusFilter) && $rawCampusFilter !== '' && ctype_digit($rawCampusFilter))
    ? (int) $rawCampusFilter
    : null;
$showAllCampusesSelected = $pinnedCampusId === null;
$campusesJson = htmlspecialchars(json_encode($campuses, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
$ministriesJson = htmlspecialchars(json_encode($ministries, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/_portal-shell.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Calendar - Church Portal</title>
    <style>
        /* Topbar / drawer / search styles are injected by _portal-shell.php
           via portal_header(). Page-specific layout follows below. */
                *{box-sizing:border-box}
        body{margin:0;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
        a{color:inherit}
        /* Width lives on the shared .shell (fluid) + data-layout=workspace. */
        .calendar-titleblock{margin:0 0 18px;color:#f8fffb}
        .calendar-titleblock h1{margin:0;font-size:clamp(28px,3.6vw,40px);line-height:1.04}
        .calendar-titleblock .sub{color:rgba(248,255,251,.78);font-size:13px}
        .dropdown-menu{display:none;position:absolute;right:0;top:40px;min-width:188px;background:#fff;color:var(--ink);border:1px solid var(--line);border-radius:8px;box-shadow:0 14px 34px rgba(28,48,39,.16);padding:6px;z-index:10}
        .dropdown-menu a{padding:9px 10px;border-radius:6px;text-decoration:none}
        .dropdown-menu a:hover{background:var(--soft)}
        .button,button.button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 10px;border-radius:8px;background:#fff;color:var(--deep);font:inherit;font-weight:900;text-decoration:none;border:0;cursor:pointer;box-shadow:0 8px 18px rgba(3,20,13,.12)}
        .button.secondary{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.14);box-shadow:none}
        .button[aria-disabled="true"]{opacity:.48;pointer-events:none}
        .toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin:12px 0}
        .segmented{display:inline-flex;padding:3px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:10px;gap:4px}
        .segmented button{min-width:56px;border-radius:8px;background:transparent;color:#f8fffb;box-shadow:none;border:0;padding:6px 8px;font-weight:800;font-size:13px}
        .segmented button.active{background:#fff;color:var(--deep)}
        .segmented button.active{background:#fff;color:var(--deep)}
        .range{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .range strong{color:#f8fffb;font-size:16px}
          /* Filters are hidden by default to keep the calendar as the primary focus.
            A compact 'Filters' toggle is provided in the toolbar to reveal them. */
          /* Base is hidden and the "Filters" button reveals them. A max-width:1080px
           rule used to force display:flex, which meant seven source checkboxes
           filled the first mobile screen ahead of any calendar content (audit H2)
           AND the toggle button appeared to do nothing. */
        .filters{display:none;gap:8px;flex-wrap:wrap;margin-bottom:14px}
        .filters.is-open{display:flex}
        .filter-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 11px;border-radius:999px;background:#fff;border:1px solid var(--line);box-shadow:0 10px 22px rgba(27,50,40,.06);cursor:pointer;font-weight:800}
        .filter-chip input{margin:0}
        .panel{background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 16px 42px rgba(27,50,40,.1);overflow:hidden}
        .panel-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line)}
        .panel-head h2{margin:0;font-size:16px}
        .panel-head a{font-size:12px;color:var(--teal);font-weight:900;text-decoration:none}
        .calendar-frame{padding:10px}
        .month-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}
        .dow{font-size:12px;text-transform:uppercase;font-weight:900;color:var(--muted);padding:1px 3px}
        .month-day{min-height:92px;border:1px solid var(--line,#d9e4dd);border-radius:6px;padding:4px 5px;background:var(--paper,#fff);display:grid;gap:3px;min-width:0}
        .month-day.out{opacity:.45;background:var(--bg,#f7faf8)}
        /* Still looks like a date; behaves like the control it now is. */
        .day-num{font-weight:900;font-size:12px;line-height:1;background:none;border:0;padding:0;
          margin:0;font-family:inherit;color:inherit;cursor:pointer;text-align:left;
          min-width:24px;min-height:24px;display:inline-flex;align-items:center}
        .day-num:hover{color:var(--teal,#117b6d)}
        .day-num:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px;border-radius:4px}

        /* The inspector lives in the shell right rail (Phase 6). Closed, the
           aside is [hidden] so it takes no grid track. */
        .day-inspector{background:transparent;border:0;border-radius:0;min-width:0}
        .day-inspector[hidden]{display:none}
        .di-head{display:flex;align-items:center;justify-content:space-between;gap:8px;
          padding:10px 12px;border-bottom:1px solid var(--line,#eef2f0)}
        .di-head h2{margin:0;font-size:14px;font-weight:800;color:var(--ink,#17211b)}
        .di-head h2:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:3px;border-radius:4px}
        .di-close{min-width:32px;min-height:32px;border:1px solid var(--line,#d9e4dd);border-radius:8px;
          background:#fff;cursor:pointer;font:inherit;color:var(--muted,#5c6b63);line-height:1}
        .di-close:hover{background:var(--soft,#eef4f0)}
        .di-close:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
        .di-body{padding:10px 12px 12px;display:grid;gap:10px}
        .di-item{display:grid;gap:4px;padding:8px 10px;border:1px solid var(--line,#e6ece8);
          border-radius:8px;border-left-width:3px}
        .di-item-title{font-weight:800;font-size:13px;line-height:1.25;color:var(--ink,#17211b)}
        .di-item-title a{color:inherit;text-decoration:none}
        .di-item-title a:hover{text-decoration:underline}
        .di-item-meta{font-size:11.5px;color:var(--muted,#5c6b63)}
        .di-roles{list-style:none;margin:4px 0 0;padding:0;display:grid;gap:2px}
        .di-role{display:grid;gap:4px;font-size:12px;line-height:1.35}
        .di-role-head{display:flex;justify-content:space-between;gap:8px;align-items:baseline}
        .di-role-name{color:var(--muted,#5c6b63)}
        .di-role-person{font-weight:700;color:var(--ink,#17211b);text-align:right}
        /* Unfilled is the reason to open this panel, so it is stated in words
           and not left as an empty space the reader has to interpret. */
        .di-role.is-open .di-role-person{color:#8c2f2f;font-style:italic;font-weight:700}
        .di-staff{display:grid;gap:4px}
        .di-person{width:100%;min-height:36px;font:inherit;border:1px solid var(--line,#d9e4dd);
          border-radius:6px;padding:4px 8px;background:#fff;color:var(--ink,#17211b)}
        .di-person:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
        .di-conflict{margin:0;font-size:11.5px;color:#8c2f2f}
        .di-grid-links{display:grid;gap:4px;margin-top:6px}
        .di-grid-link{font-size:12px;font-weight:700;color:var(--teal,#117b6d);text-decoration:none}
        .di-grid-link:hover{text-decoration:underline}
        .di-grid-link:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
        .di-status{margin:0;font-size:12px;color:var(--muted,#5c6b63)}
        .di-status.is-err{color:#8c2f2f}
        .di-empty{font-size:12.5px;color:var(--muted,#5c6b63);margin:0}
        .di-actions{margin-top:2px}
        .di-new{display:inline-flex;align-items:center;min-height:36px;padding:8px 12px;
          border-radius:8px;background:var(--deep,#0c5a45);color:#fff;text-decoration:none;
          font-size:12.5px;font-weight:800}
        .di-new:focus-visible{outline:2px solid var(--focus-ring,var(--teal,#117b6d));outline-offset:2px}
        .di-unfilled{margin:0;font-size:12px;font-weight:700;color:#8c2f2f}
        .cal-view-dialog{border:1px solid var(--line,#d9e4dd);border-radius:10px;padding:0;
          width:min(420px,calc(100vw - 32px));box-shadow:0 24px 64px rgba(9,25,20,.24)}
        .cal-view-dialog::backdrop{background:rgba(9,25,20,.32)}
        .cal-view-dialog h2{margin:0;padding:12px 14px;font-size:15px;border-bottom:1px solid var(--line,#eef2f0)}
        .cal-view-list{display:grid;max-height:min(360px,50vh);overflow:auto}
        .cal-view-list button{display:grid;gap:2px;text-align:left;padding:10px 14px;border:0;border-bottom:1px solid var(--line,#eef2f0);
          background:#fff;font:inherit;cursor:pointer}
        .cal-view-list button:hover,.cal-view-list button:focus-visible{background:var(--soft,#eef4f0)}
        .cal-view-name{font-weight:800;font-size:13px}
        .cal-view-meta{font-size:11.5px;color:var(--muted,#5c6b63)}
        .cal-view-empty,.cal-view-status{margin:0;padding:12px 14px;font-size:13px;color:var(--muted,#5c6b63)}
        .cal-view-dialog menu{display:flex;justify-content:flex-end;gap:8px;margin:0;padding:10px 14px}
        @media print{.day-inspector,.portal-right{display:none!important}}
        /* default item layout: title above meta — used by week/day views */
        .month-items,.day-items{display:grid;gap:2px;min-width:0}
        .item{display:grid;gap:1px;padding:3px 6px;border-radius:4px;color:var(--on-teal,#fff);text-decoration:none;line-height:1.2;font-size:12px;min-width:0}
        .item .title{font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .item .meta{font-size:12px;opacity:.85;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        /* month view: single-line items (time chip + title) so 3 fit per cell */
        /* A month chip is a link, so it is a target, and WCAG 2.5.8 asks for
           24px. The spacing exception does not apply: chips are stacked 2px
           apart, so a 24px circle around one reaches its neighbour. Centring
           the text in 24px costs a few pixels of cell height and keeps three
           chips per day; the "+N more" button already handles the overflow. */
        .month-items .item{display:flex;align-items:center;gap:5px;padding:0 5px;
          min-height:24px;white-space:nowrap;overflow:hidden}
        .month-items .item .meta{flex-shrink:0;font-weight:600}
        .month-items .item .title{flex:1;min-width:0}
        .item-more{font:inherit;font-size:12px;color:var(--muted);padding:1px 4px;font-weight:600;background:none;border:0;text-align:left;cursor:pointer;min-height:24px;border-radius:4px}
        .item-more:hover{background:var(--soft,#eef4f0);color:var(--ink,#17211b)}
        .item.event{background:var(--blue);color:var(--on-blue,#fff)}
        .item.assignment{background:var(--teal);color:var(--on-teal,#fff)}
        .item.schedule{background:var(--gold);color:var(--on-gold,#2b1d08)}
        .item.schedule .meta{opacity:.78}
        .item.custom{background:#5b6d8a}
        .item.birth{background:#8a5d9d}
        .item.anniv{background:#8b6a35}
        .item.roster{background:#7b2445}
        .week-grid,.day-grid{display:grid;gap:10px}
        .week-row,.day-row{border:1px solid var(--line,#d9e4dd);border-radius:8px;overflow:hidden;background:var(--paper,#fff)}
        .week-head,.day-head{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px 12px;background:var(--soft,#eef4f0);border-bottom:1px solid var(--line,#d9e4dd)}
        .week-head strong,.day-head strong{font-size:14px}
        .day-head .muted{font-size:12px}
        .week-body,.day-body{padding:10px 12px}
        .week-columns{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}
        .week-column{min-height:280px;border:1px solid var(--line,#d9e4dd);border-radius:8px;padding:8px;background:var(--paper,#fff);display:grid;gap:6px}
        .week-column .day-num{font-size:14px}
        .week-column.today,.month-day.today,.day-row.today{outline:2px solid color-mix(in srgb, var(--teal,#117b6d) 22%, transparent);border-color:color-mix(in srgb, var(--teal,#117b6d) 45%, transparent)}
        /* Time grid — Week and Day.
           Both views used to be flat lists of chips: the same information the
           Agenda already gives, grouped differently. A scheduling product needs
           to answer "when is this, and what else is happening then", which a
           list cannot do. Hour rows with events positioned by start time make
           clustering and clashes visible at a glance.
           Blocks use the real ends_at the adapter already returned; items
           without one fall back to a 45-minute stub so they stay clickable. */
        .tg{display:grid;grid-template-columns:52px minmax(0,1fr);border:1px solid var(--line,#d9e4dd);
          border-radius:8px;background:var(--paper,#fff);overflow:hidden}
        .tg-corner{border-right:1px solid var(--line,#d9e4dd);border-bottom:1px solid var(--line,#d9e4dd)}
        .tg-days{display:grid;border-bottom:1px solid var(--line,#d9e4dd)}
        .tg-dayhead{padding:6px 8px;text-align:center;border-left:1px solid var(--line,#d9e4dd);min-width:0}
        .tg-dayhead:first-child{border-left:0}
        .tg-dow{font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:700;color:var(--muted,#627169)}
        .tg-date{font-size:16px;font-weight:800;line-height:1.1}
        .tg-dayhead.is-today .tg-date{background:var(--teal,#117b6d);color:var(--on-teal,#fff);
          border-radius:999px;display:inline-block;min-width:24px;padding:1px 6px}
        /* All-day band: birthdays and anniversaries have no time, so placing
           them on an hour line would be a lie. */
        .tg-allday{display:grid;border-bottom:1px solid var(--line,#d9e4dd);background:var(--soft,#eef4f0)}
        .tg-allday-cell{border-left:1px solid var(--line,#d9e4dd);padding:3px;display:grid;gap:2px;min-width:0}
        /* All-day chips are links, so they need a real target (WCAG 2.5.8). */
        .tg-allday-cell .item{min-height:24px;display:flex;align-items:center}
        .tg-allday-cell:first-child{border-left:0}
        .tg-gutter{border-right:1px solid var(--line,#d9e4dd)}
        .tg-hour{height:44px;position:relative;font-size:11px;color:var(--muted,#627169);
          text-align:right;padding:0 6px}
        .tg-hour span{position:relative;top:-6px;background:var(--paper,#fff);padding:0 2px}
        .tg-cols{display:grid;position:relative}
        .tg-col{position:relative;border-left:1px solid var(--line,#d9e4dd);min-width:0}
        .tg-col:first-child{border-left:0}
        .tg-col.is-today{background:color-mix(in srgb, var(--teal,#117b6d) 5%, transparent)}
        .tg-slot{position:absolute;left:0;right:0;z-index:0}
        .tg-slot:hover{background:color-mix(in srgb, var(--teal,#117b6d) 7%, transparent)}
        /* A cancelled date stays on the calendar and says so. The word is in
           the title from the feed; this is reinforcement, never the only
           signal — decoration alone is invisible to a screen reader and can be
           lost in print. */
        .item.is-cancelled .title{text-decoration:line-through}
        .item.is-cancelled{opacity:.72}
        .tg-line{position:absolute;left:0;right:0;border-top:1px solid var(--line,#d9e4dd);pointer-events:none}
        .tg-line.half{border-top-style:dotted;opacity:.6}
        .tg-event{position:absolute;left:2px;right:2px;border-radius:4px;padding:1px 5px;
          font-size:11px;line-height:1.25;color:var(--on-teal,#fff);text-decoration:none;
          overflow:hidden;min-height:22px;box-shadow:0 1px 2px rgba(0,0,0,.14)}
        .tg-event .t{font-weight:800;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .tg-event .c{opacity:.9;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .tg-event:focus-visible{outline:2px solid var(--focus-ring-inverse,#fff);outline-offset:-2px}
        /* The current time, the one thing that makes a day view feel live. */
        .tg-now{position:absolute;left:0;right:0;height:0;border-top:2px solid var(--rose,#b84957);z-index:3;pointer-events:none}
        .tg-now::before{content:'';position:absolute;left:-4px;top:-4px;width:7px;height:7px;
          border-radius:50%;background:var(--rose,#b84957)}
        @media(max-width:760px){
          /* Seven columns across a phone leaves each event block about 18px
             wide — narrower than the 24px WCAG 2.5.8 asks of a target, and too
             narrow to read besides. The grid keeps a usable column width and
             scrolls sideways within its own frame instead; the page itself
             still does not scroll horizontally. */
          .tg{grid-template-columns:40px minmax(0,1fr);overflow-x:auto;
            -webkit-overflow-scrolling:touch;scroll-snap-type:x proximity}
          .tg-days,.tg-allday,.tg-cols{min-width:max(100%, calc(var(--tg-days,7) * 92px))}
          .tg-event{scroll-snap-align:start}
          .tg-hour{height:40px;font-size:10px}
          .tg-date{font-size:14px}
          .tg-event{font-size:10px}
        }
        @media print{
          .tg{break-inside:avoid}
        }
        .empty{padding:18px 16px;color:var(--muted)}
        .side{display:grid;gap:14px}
        .summary{padding:14px;display:grid;gap:10px}
        .stat{border-radius:8px;background:var(--soft);padding:12px}
        .stat strong{display:block;font-size:24px;line-height:1}
        .stat span{display:block;color:var(--muted);font-size:12px;text-transform:uppercase;font-weight:900;margin-top:4px}
        .source-list{display:grid;gap:8px;padding:0}
        .cal-side-head{padding:4px 6px 8px;border:0}
        .cal-side-head h2{margin:0;font-size:14px}
        .source-line{display:flex;justify-content:space-between;gap:8px;align-items:center;padding:10px 11px;border:1px solid var(--line,#d9e4dd);border-radius:8px;background:var(--soft,#eef4f0)}
        .source-line b{font-size:13px}
        .source-line span{color:var(--muted);font-size:12px}
        .note{padding:12px 14px;color:var(--muted);font-size:12px;border-top:1px solid var(--line,#d9e4dd);background:var(--soft,#eef4f0)}
        .range-label{font-weight:900;color:#f8fffb}
        @media(max-width:1080px){.side{grid-template-columns:1fr}}
        @media(min-width:1100px){.month-day{min-height:108px}}
/* Third occurrence of the seam defect: a fixed-height body gradient meant
           the toolbar (Today / range / Filters) rendered light-on-light where the
           band ended. The band now belongs to the header block and sizes to it. */
        .calhead{position:relative;isolation:isolate}
        .calhead::before{content:"";position:absolute;top:-18px;bottom:0;left:calc(-1 * var(--portal-gutter,1.5rem));right:calc(-1 * var(--portal-gutter,1.5rem));
          width:auto;background:linear-gradient(180deg,var(--gradient-top,#0c2f28) 0,var(--gradient-mid,#123b31) 100%);z-index:-1}

        /* Agenda — the approved small-screen transformation. A 7-column month grid
           squeezed to 1fr produced a bare list of day numbers with no events and no
           day names (audit H4); this is a real dated list instead. */
        /* Agenda — a compact, spreadsheet-style table.
           The previous layout was a card per day with 52px rows, 12px gaps and
           an 18px date heading: roughly eight rows per screen, and a printed
           page that was mostly borders. This is one dense table instead, so a
           month fits on a page and reads like a worksheet. Same data, same
           links, same grouping — only the presentation changed. */
        .agenda-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
        .agenda-table{width:100%;border-collapse:collapse;font-size:12px;line-height:1.35;
          /* lines numerals up column-wise, which is most of what makes a table
             of dates and times scannable */
          font-variant-numeric:tabular-nums}
        .agenda-table th,.agenda-table td{border:1px solid var(--line,#d9e4dd);
          padding:2px 8px;text-align:left;vertical-align:top}
        .agenda-table thead th{position:sticky;top:0;z-index:1;
          background:var(--soft,#eef4f0);font-size:11px;font-weight:700;
          text-transform:uppercase;letter-spacing:.04em;color:var(--muted,#627169);
          white-space:nowrap}
        /* Merged date cell — the spreadsheet look, done with rowspan so screen
           readers still associate every row with its date. */
        .ag-date{white-space:nowrap;font-weight:700;width:1%;
          background:var(--paper,#fff);vertical-align:top}
        .ag-day:nth-of-type(even) td,.ag-day:nth-of-type(even) .ag-date{background:var(--soft,#eef4f0)}
        .ag-day.is-today .ag-date{box-shadow:inset 3px 0 0 var(--teal,#117b6d)}
        .ag-day.is-today td{font-weight:600}
        .ag-time{white-space:nowrap;color:var(--muted,#627169);width:1%}
        .ag-kind{white-space:nowrap;color:var(--teal-ink,#117b6d);font-size:11px;
          text-transform:uppercase;letter-spacing:.03em;width:1%}
        /* 24px keeps the row a valid touch target (WCAG 2.5.8) while still
           being about the height of a spreadsheet row. */
        .ag-title a{display:block;min-height:24px;line-height:24px;
          color:var(--ink,#17211b);text-decoration:none;overflow-wrap:anywhere}
        .ag-title a:hover{text-decoration:underline}
        @media(max-width:520px){
          .agenda-table{font-size:11px}
          .agenda-table th,.agenda-table td{padding:2px 6px}
          .ag-kind{font-size:10px}
        }
        /* Printing was never styled here, so the browser printed the whole
           application shell. Drop the chrome and let the table use the page. */
        @media print{
          .topbar,.side-drawer,.portal-footer,.skip-link,.calhead,.toolbar,
          .filters,.source-list,.pc-btn,.search-overlay{display:none !important}
          .calendar-frame{border:0;padding:0}
          .agenda-wrap{overflow:visible}
          .agenda-table{font-size:9pt}
          .agenda-table thead{display:table-header-group}
          .agenda-table tr{break-inside:avoid;page-break-inside:avoid}
          .agenda-table th,.agenda-table td{border-color:#999;padding:1px 5px}
          .ag-day:nth-of-type(even) td,.ag-day:nth-of-type(even) .ag-date{background:transparent}
          .ag-title a{color:#000;min-height:0;line-height:1.3}
        }
        /* The seven calendar-source checkboxes filled the first mobile screen
           before any calendar content (audit H2). Collapsed by default below
           760px; the sources themselves and their state are unchanged. */
        .side-summary{display:none}
        @media(max-width:760px){
          .side-disclosure{margin:0 0 12px}
          .side-summary{display:flex;align-items:center;gap:8px;min-height:48px;padding:0 14px;
            background:var(--soft,#eef4f0);border:1px solid var(--line,#d9e4dd);border-radius:var(--radius,8px);
            font-size:14px;font-weight:700;color:var(--ink,#17211b);cursor:pointer}
          .side-disclosure[open] .side-summary{margin-bottom:10px}
        }
        @media(max-width:820px){
          .segmented button{min-height:44px}
          .range .button{min-height:44px;min-width:44px}
          .panel-head a{min-height:44px;display:inline-flex;align-items:center}
        }
        @media(max-width:760px){.toolbar, .filters{flex-direction:column;align-items:stretch}.segmented{width:100%;justify-content:space-between}.segmented button{flex:1}.side{grid-template-columns:1fr}.month-grid,.week-columns{grid-template-columns:1fr}.dow{display:none}.month-day{min-height:auto}.week-column{min-height:auto}}
    
        @media (min-width:821px){
          .panel-head a,.panel-head button{min-height:24px;display:inline-flex;align-items:center}
        }
    </style>
</head>
<body>
<div class="shell" <?= portal_shell_mods('workspace', 'open', 'collapsed') ?> data-base="<?= $base ?>" data-can-view-self="<?= $canViewSelf ? '1' : '0' ?>" data-can-manage-settings="<?= $canManageCalendarSettings ? '1' : '0' ?>" data-ministries="<?= $ministriesJson ?>" data-campuses="<?= $campusesJson ?>">
    <?= portal_header(
        $basePath,
        '',
        '',
        $campuses,
        $pinnedCampusId,
        $actor,
        [
            ['href' => $base . '/',             'label' => 'Dashboard',    'icon' => 'dashboard'],
            ['href' => $base . '/ministries',   'label' => 'Ministries',   'icon' => 'ministry'],
            ['href' => $base . '/calendar',     'label' => 'Calendar',     'icon' => 'calendar'],
            ['href' => $base . '/events',       'label' => 'Events',       'icon' => 'events'],
            ['href' => $base . '/people',       'label' => 'People',       'icon' => 'people'],
        ],
        [],
        [],
        'Sign in',
        $base . '/login',
        true   // include the "All campuses" option
    ) ?>

    <main id="portal-main" tabindex="-1">
<div class="calhead">
    <div class="calendar-titleblock">
        <h1>Calendar</h1>
        <div class="sub">View assignments, ministry schedules, events, birthdays, and custom calendar sources in one place.</div>
    </div>

    <div class="toolbar toolbar-compact" role="toolbar" aria-label="Calendar toolbar">
        <div class="segmented" aria-label="Calendar view">
            <button class="active" type="button" data-view="month">Month</button>
            <button type="button" data-view="agenda">Agenda</button>
            <button type="button" data-view="week">Week</button>
            <button type="button" data-view="day">Day</button>
        </div>
      <div class="range">
        <button class="button secondary" id="prevBtn" type="button" aria-label="Previous period"><span aria-hidden="true">&lsaquo;</span></button>
        <button class="button secondary" id="todayBtn" type="button">Today</button>
        <button class="button secondary" id="nextBtn" type="button" aria-label="Next period"><span aria-hidden="true">&rsaquo;</span></button>
        <strong class="range-label" id="rangeLabel">Loading…</strong>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <div class="dropdown" id="calendarDropdown" style="position:relative;">
          <button id="pageMenuBtn" class="icon-btn" type="button" aria-label="More" title="More"><?= portal_icon('menu') ?></button>
          <div class="dropdown-menu" aria-hidden="true" style="display:none">
            <a href="<?= $base ?>/calendar/settings">Settings</a>
          </div>
        </div>
        <button class="button secondary" id="toggleFiltersBtn" type="button" aria-expanded="false" title="Show filters">Filters</button>
        <?php if (!empty($canSaveViews)): ?>
        <button class="button secondary" id="openViewBtn" type="button">Open view…</button>
        <button class="button secondary" id="saveViewBtn" type="button">Save view…</button>
        <?php endif; ?>
        <!-- Print is its own document, composed from the same data. It is not
             this page with print styles over it. Print this view carries the
             layers and dates currently on screen. -->
        <a class="button secondary" id="printBtn" href="<?= $base ?>/calendar/print-setup">Print this view</a>
        <?php if (!empty($canManageEvents)): ?>
        <!-- Creating from the calendar is the point: the date you are looking
             at is the date you mean. Clicking a day does the same thing with a
             mouse; this is the keyboard route, and it is one tab stop rather
             than forty-two. -->
        <button class="button" id="calNewBtn" type="button">New event</button>
        <?php endif; ?>
      </div>
    </div>
    </div><!-- /.calhead -->

    <!-- Chips come from /api/calendar/layers, which is audience-filtered: a
         member is never sent a leader-only layer, not even its label. -->
    <div class="filters" id="filtersBar"></div>
    <style id="layerColors"></style>
    <dialog id="calViewDialog" class="cal-view-dialog" aria-labelledby="calViewDialogTitle">
      <h2 id="calViewDialogTitle">Open view</h2>
      <div class="cal-view-list" id="calViewList"></div>
      <p class="cal-view-empty" id="calViewEmpty" hidden>No saved views yet. Save the layers and view you are looking at.</p>
      <p class="cal-view-status" id="calViewStatus" hidden></p>
      <menu>
        <button type="button" id="calViewClose" class="button secondary">Close</button>
      </menu>
    </dialog>

    <section class="portal-body" aria-label="Calendar workspace">
        <aside class="portal-left" id="portalLeft">
            <div class="portal-left-head">
                <?= portal_left_toggle('Calendars') ?>
            </div>
            <div class="portal-left-body">
                <div class="side" id="calSide">
                    <div class="panel-head cal-side-head"><h2>Sources</h2><a href="<?= $base ?>/events">Events</a></div>
                    <div class="source-list" id="sourceList"></div>
                    <div class="note">Birthdays and anniversaries now come from the people and household records. Custom calendars are still stored in the browser until a shared settings backend is added.</div>
                </div>
            </div>
        </aside>
        <div class="portal-main-slot layout-workspace">
        <article class="panel">
            <div class="panel-head">
                <h2 id="mainTitle">Month view</h2>
                <a href="<?= $base ?>/calendar/settings">Configure calendar sources</a>
            </div>
            <div class="calendar-frame" id="calendarRoot"><div class="empty">Loading calendar…</div></div>
        </article>
        </div>
        <aside class="portal-right" id="portalRight" hidden>
            <div class="portal-right-head">
                <?= portal_right_toggle('Day') ?>
            </div>
            <div class="portal-right-body">
        <!-- The day inspector. In the shell right rail so collapsing it
             returns width to the month. Phase 6's region; serving actions
             from 4a stay inside this panel. -->
        <div class="day-inspector" id="dayInspector" hidden aria-labelledby="dayInspectorTitle">
            <div class="di-head">
                <h2 id="dayInspectorTitle" tabindex="-1">Day</h2>
                <p class="di-unfilled" id="dayUnfilledCount" hidden></p>
                <button type="button" class="di-close" id="dayInspectorClose" aria-label="Close day details">✕</button>
            </div>
            <div class="di-body" id="dayInspectorBody"></div>
        </div>
            </div>
        </aside>
        </section>

    </main>
<footer class="portal-footer"><span>Church Portal</span><span>Calendar views and filters</span></footer>
</div>
<script>
const shell = document.querySelector('.shell');
const basePath = shell.dataset.base || '';
const campusSelect = document.getElementById('campusSelect');
const canViewSelf = shell.dataset.canViewSelf === '1';
const canManageSettings = shell.dataset.canManageSettings === '1';
const ministrySelect = null;
const filtersBar = document.getElementById('filtersBar');
const calendarRoot = document.getElementById('calendarRoot');
const sourceList = document.getElementById('sourceList');
const mainTitle = document.getElementById('mainTitle');
const rangeLabel = document.getElementById('rangeLabel');
// visibleCount/rangeCount/customCount were the "Visible sources" stat tiles —
// removed because they duplicated info already visible in the source list and
// took up sidebar height on mobile. Lookups stay null-safe via the helpers below.
const setText = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = String(value); };
const viewButtons = [...document.querySelectorAll('[data-view]')];
const STORAGE_KEY = 'church_portal_calendar_rules_v1';
const VIEW_KEY = 'church_portal_calendar_view_v1';

const state = {
  view: new URLSearchParams(location.search).get('view') || (function(){
    // Agenda is the default on small screens — a 7-column month grid is not a
    // usable phone experience. A stored preference always wins, so a returning
    // user keeps whatever they last chose.
    var stored = localStorage.getItem(VIEW_KEY);
    if (stored) return stored;
    return window.matchMedia('(max-width:760px)').matches ? 'agenda' : 'month';
  })(),
  cursor: (function(){
    // A shared link should land on the period it was shared from, not on today.
    var d = new URLSearchParams(location.search).get('date');
    var parsed = d ? new Date(d + 'T00:00:00') : null;
    return (parsed && !Number.isNaN(parsed.getTime())) ? parsed : new Date();
  })(),
  filters: loadFilters(),
  data: [],
  sources: [],
};
state.cursor.setHours(0,0,0,0);

function escapeHtml(value){return String(value ?? '').replace(/[&<>"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));}
// When a campus selector is present, always send the param. "All campuses"
// (empty option) is sent explicitly as 0, which the backend resolves to "no
// campus filter" (all campuses) — otherwise scoped users silently fall back to
// a single campus and feeds without a church-wide fallback (e.g. birthdays)
// show nothing.
function campusQuery(name='current_campus_id'){if(!campusSelect)return '';const id=campusSelect.value||'';return `${name}=${encodeURIComponent(id||'0')}`;}
function withCampus(url,name='current_campus_id'){const q=campusQuery(name);return q?url+(url.includes('?')?'&':'?')+q:url;}
const FILTER_KEY = 'church_portal_calendar_sources_v2';
const FILTER_KEY_V1 = 'church_portal_calendar_sources_v1';
/* Absent means shown. v1 stored an explicit true for each of seven fixed
   sources, and buildItems() also required membership of that list, so any
   source added later was invisible to everyone who had ever loaded the page.
   Event layers are now one per type, so that would have shipped this feature
   dark. Recording only the OFF switches makes a new layer appear by default,
   which is the behaviour that survives the next layer too. */
function loadFilters(){
  try{
    const v2 = JSON.parse(localStorage.getItem(FILTER_KEY) || 'null');
    if(v2 && typeof v2 === 'object') return v2;
  }catch{}
  /* One-time carry-over. The single 'events' switch fanned out into a layer
     per type, so someone who had turned events off should stay off across all
     of them; the sentinel is expanded once the layer list arrives. v1 is left
     in place so a rollback does not strip preferences. */
  let out = {};
  try{
    const v1 = JSON.parse(localStorage.getItem(FILTER_KEY_V1) || 'null');
    if(v1 && typeof v1 === 'object'){
      for(const [k,v] of Object.entries(v1)) if(v === false) out[k] = false;
      if(v1.events === false) out.__eventsOffFromV1 = true;
      delete out.events;
    }
  }catch{}
  try{ localStorage.setItem(FILTER_KEY, JSON.stringify(out)); }catch{}
  return out;
}
function applyV1EventsOff(layers){
  if(!state.filters.__eventsOffFromV1) return;
  delete state.filters.__eventsOffFromV1;
  layers.filter(l => l.group === 'events').forEach(l => { state.filters[l.source] = false; });
  saveFilters();
}
function saveFilters(){ try{ localStorage.setItem(FILTER_KEY, JSON.stringify(state.filters)); }catch{} }
function cssSlug(source){ return String(source).replace(/[^a-z0-9-]/g, '-'); }
/* A layer's colour is chosen by an admin, so it cannot live in a static
   stylesheet. Slug and colour are re-validated here even though the service
   rejects malformed values on write: this string is interpolated straight into
   a CSS selector and a declaration. */
function renderLayerColors(layers){
  const el = document.getElementById('layerColors');
  if(!el) return;
  el.textContent = layers
    .filter(l => /^#[0-9a-fA-F]{6}$/.test(l.color || '') && /^[a-z0-9:-]+$/.test(l.source || ''))
    .map(l => `.item.src-${cssSlug(l.source)}{background:${l.color};color:#fff}`)
    .join('\n');
}
function renderFilterChips(layers){
  filtersBar.innerHTML = layers.map(l => {
    const on = state.filters[l.source] !== false;
    return `<label class="filter-chip"><input type="checkbox" value="${escapeHtml(l.source)}"${on ? ' checked' : ''}> ${escapeHtml(l.label)}</label>`;
  }).join('');
}
async function loadLayers(){
  try{
    const data = await json(withCampus(`${basePath}/api/calendar/layers`));
    return Array.isArray(data.layers) ? data.layers : [];
  }catch{ return []; }
}
function saveView(){localStorage.setItem(VIEW_KEY, state.view);}
/* The URL is the shareable record of what you are looking at. ?view= was read
   on load but never written, so switching view or paging forward left the
   address bar describing a different screen — a shared or bookmarked link
   reopened the wrong one, and Back did not step through the calendar at all.
   replaceState during normal render, pushState only on a deliberate move, so
   Back walks the periods a user actually visited rather than every re-render. */
function syncUrl(push){
  try{
    const params = new URLSearchParams(location.search);
    params.set('view', state.view);
    params.set('date', dayKey(state.cursor));
    const url = location.pathname + '?' + params.toString();
    if(url === location.pathname + location.search) return;
    history[push ? 'pushState' : 'replaceState']({view:state.view, date:dayKey(state.cursor)}, '', url);
  }catch(_){ /* history is unavailable in some embedded contexts; not fatal */ }
}
function loadCustomRules(){try{const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); return Array.isArray(raw) ? raw : [];}catch{return [];}}
function startOfMonth(date){return new Date(date.getFullYear(), date.getMonth(), 1);}
function endOfMonth(date){return new Date(date.getFullYear(), date.getMonth()+1, 0);}
function startOfWeek(date){const d = new Date(date); d.setDate(d.getDate() - d.getDay()); return d;}
function endOfWeek(date){const d = startOfWeek(date); d.setDate(d.getDate() + 6); return d;}
function startOfDay(date){const d = new Date(date); d.setHours(0,0,0,0); return d;}
function endOfDay(date){const d = startOfDay(date); d.setDate(d.getDate()+1); return d;}
function rangeForView(view, cursor){if(view==='agenda') return [startOfMonth(cursor), (()=>{const d=startOfMonth(cursor); d.setMonth(d.getMonth()+1); return d;})()]; if(view==='day') return [startOfDay(cursor), endOfDay(cursor)]; if(view==='week') return [startOfWeek(cursor), (()=>{const d = endOfWeek(cursor); d.setDate(d.getDate()+1); return d;})()]; const start = startOfMonth(cursor); const end = new Date(start.getFullYear(), start.getMonth()+1, 1); return [start, end];}
function dayKey(date){return date.toISOString().slice(0,10);}
function inRange(date, start, end){return date >= start && date < end;}
function fmtDay(date){return date.toLocaleDateString([], { weekday:'short', month:'short', day:'numeric' });}
function fmtClock(value){const d = new Date(value); return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString([], { hour:'numeric', minute:'2-digit' });}
function fmtRange(start, end){if(state.view==='agenda') return start.toLocaleDateString([], {month:'long', year:'numeric'}); if(state.view==='day') return fmtDay(start); if(state.view==='week') return `${fmtDay(start)} - ${fmtDay(new Date(end.getTime()-86400000))}`; return start.toLocaleDateString([], { month:'long', year:'numeric' });}
function itemHref(item){if(item.href) return item.href; if(item.kind==='assignment') return `${basePath}/my-schedule`; if(item.kind==='event') return `${basePath}/events/${item.eventId || ''}`; return '#';}
function colorClass(kind){return {event:'event',assignment:'assignment',schedule:'schedule',custom:'custom',birth:'birth',anniv:'anniv',roster:'roster'}[kind] || 'custom';}
/* Kind keeps the built-in palette as a fallback; the source adds the
   admin-chosen layer colour on top when one is configured. */
function itemClasses(item){
  return colorClass(item.kind) + ' src-' + cssSlug(item.source || '') + (item.cancelled ? ' is-cancelled' : '');
}

async function json(url){const res = await fetch(url, { credentials:'same-origin' }); if(!res.ok) throw new Error(String(res.status)); return await res.json();}

// loadEvents: intentionally omitted. Event occurrences are now sourced from
// /api/calendar/sources (see loadSystemCalendar below) which expands every
// event_occurrences row in the window — including recurring series, with
// per-occurrence cancellations and title overrides honored. The previous
// /api/events?limit=120 path returned only one date per event (next_occurrence_at)
// which silently dropped the rest of any recurring series.

async function loadAssignments(start, end){if(!canViewSelf) return []; try{const data = await json(withCampus(`${basePath}/api/my-schedule?start=${start.toISOString().slice(0,10)}&end=${end.toISOString().slice(0,10)}`)); return (data.assignments || []).flatMap(a => {
  const date = new Date(a.startsOn);
  if(!inRange(date, start, end)) return [];
  return [{ kind:'assignment', source:'assignments', sourceLabel:'Role assignments', date, title:a.eventTitle || a.ministryName || 'Assignment', meta:`${a.roleName || 'Role'} · ${a.ministryName || 'Ministry'}`, href:`${basePath}/my-schedule` }];
}); } catch { return []; }}

async function loadMinistrySchedules(start, end){try{const data = await json(withCampus(`${basePath}/api/ministry-dashboard?since=${start.toISOString().slice(0,10)}&until=${end.toISOString().slice(0,10)}`)); return (data.cards || []).flatMap(card => {
  const rows = Array.isArray(card.upcomingSchedule) ? card.upcomingSchedule : [];
  return rows.flatMap(row => {
    const date = new Date(row.startsOn);
    if(!inRange(date, start, end)) return [];
    return [{ kind:'schedule', source:'schedules', sourceLabel:'Ministry schedules', date, title:row.eventTitle || card.name || 'Ministry schedule', meta:`${card.name || 'Ministry'} · ${row.assignmentCount ?? 0} assignments`, href:`${basePath}/ministries/${encodeURIComponent(card.ministryId)}` }];
  });
}); } catch { return []; }}

async function loadSystemCalendar(start, end){try{const data = await json(withCampus(`${basePath}/api/calendar/sources?start=${start.toISOString().slice(0,10)}&end=${end.toISOString().slice(0,10)}`)); return (data.items || []).flatMap(item => {
  // Prefer the precise occurrence start (carried as starts_at) when the
  // adapter supplied one — gives us correct day/week placement. Fall back
  // to "date" (Y-m-d) for items without a specific time (birthdays, etc.).
  const dateRaw = item.starts_at || item.date;
  const date = new Date(dateRaw);
  if(!inRange(date, start, end)) return [];
  return [{
    kind: item.kind || 'custom',
    source: item.source || 'custom',
    sourceLabel: item.source_label || 'Custom calendars',
    color: item.color || null,
    id: item.id || null,
    date,
    title: item.title || 'Calendar item',
    // The adapter has always returned ends_at; the frontend simply dropped it,
    // so every block had to be drawn as a fixed stub. With it, the time grid
    // shows how long something actually runs.
    endsAt: item.ends_at ? new Date(item.ends_at) : null,
    // A cancelled date stays on the calendar and says so, both in the title
    // the feed supplies and in the styling.
    cancelled: !!item.cancelled,
    meta: item.meta || '',
    href: item.href ? `${basePath}${item.href}` : '#',
  }];
}); } catch { return []; }}

// Custom rule "duplication" was the cause of every item appearing twice
// (once as the system source, once as "Custom calendar"). Custom rules now
// act as filters only — their counts still appear in the source list, but
// they no longer push copies into the rendered calendar. If a future iteration
// wants to add tagging/highlighting, layer it on top of the existing items
// (don't duplicate).
function buildItems(start, end, loaded){
  /* Only the OFF switches are consulted. There used to be a second pass
     requiring membership of activeSources(), so a source the browser had never
     seen was filtered out rather than shown. */
  const filtered = loaded.filter(item => state.filters[item.source] !== false);
  // Belt-and-suspenders dedupe by (date · title · source) — guards against
  // double-fetches from overlapping loaders (e.g. an event that's also a
  // ministry schedule).
  const seen = new Set();
  const deduped = [];
  for (const item of filtered) {
    /* Prefer the server's stable id. date|title|source dropped two genuinely
       different events that happened to share a day and a name. */
    const key = item.id || (item.date.toISOString() + '|' + (item.title || '') + '|' + (item.source || ''));
    if (seen.has(key)) continue;
    seen.add(key);
    deduped.push(item);
  }
  return deduped.sort((a,b)=>a.date - b.date || String(a.title).localeCompare(String(b.title)));
}

function renderSourceList(items){
  const counts = items.reduce((acc, item) => { acc[item.source] = (acc[item.source] || 0) + 1; return acc; }, {});
  const customRules = loadCustomRules();
  setText('customCount', customRules.filter(r => r && r.enabled !== false).length);
  /* Driven by the served layer list rather than a hardcoded array, which had
     already drifted out of step with the filter chips. */
  const rows = (state.layers || []).map(l =>
    `<div class="source-line"><div><b>${escapeHtml(l.label)}</b><div><span>${escapeHtml(counts[l.source] || 0)} items</span></div></div>`
    + `<span>${state.filters[l.source] === false ? 'Hidden' : 'Shown'}</span></div>`).join('');
  sourceList.innerHTML = rows + (customRules.length
    ? customRules.map(rule => `<div class="source-line"><div><b>${escapeHtml(rule.name || 'Custom calendar')}</b><div><span>${escapeHtml(rule.query || 'No query')}</span></div></div><span>${rule.enabled === false ? 'Off' : 'On'}</span></div>`).join('')
    : '<div class="empty">No custom calendars defined yet.</div>');
}

// Month/week/day item HTML. Month view leads with the start time as a tiny
// prefix and truncates the title (single line per item, ~3 fit per cell);
// week/day views still show the full meta line under the title.
function fmtClockShort(date){
  const d = new Date(date);
  if (Number.isNaN(d.getTime())) return '';
  const h = d.getHours();
  const m = d.getMinutes();
  const am = h < 12;
  const hh = ((h % 12) || 12);
  return m === 0 ? `${hh}${am ? 'a' : 'p'}` : `${hh}:${String(m).padStart(2,'0')}${am ? 'a' : 'p'}`;
}
/* Birthdays and anniversaries genuinely have no time; everything else does.
   The agenda previously read item.startsAt, a field buildItems never sets, so
   fmtClock() got undefined and every single row rendered as "All day". */
function agendaTime(item){
  if(item.kind === 'birth' || item.kind === 'anniv') return 'All day';
  return fmtClock(item.date) || 'All day';
}
function monthItemHtml(item){
  const time = (item.kind === 'birth' || item.kind === 'anniv') ? '' : fmtClockShort(item.date);
  const meta = time ? `<span class="meta">${escapeHtml(time)}</span>` : '';
  return `<a class="item ${itemClasses(item)}" href="${escapeHtml(item.href)}" title="${escapeHtml(item.title)}${item.meta ? ' — ' + escapeHtml(item.meta) : ''}">${meta}<span class="title">${escapeHtml(item.title)}</span></a>`;
}
function weekItemHtml(item){
  const meta = `${escapeHtml(fmtClock(item.date))}${item.meta ? ' · ' + escapeHtml(item.meta) : ''}`;
  return `<a class="item ${itemClasses(item)}" href="${escapeHtml(item.href)}" title="${escapeHtml(item.title)}"><span class="title">${escapeHtml(item.title)}</span>${meta ? `<span class="meta">${meta}</span>` : ''}</a>`;
}

function renderMonth(start, end, items){
  mainTitle.textContent = 'Month view';
  const first = startOfMonth(state.cursor);
  const gridStart = startOfWeek(first);
  const cells = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(d => `<div class="dow">${d}</div>`);
  for(let i=0;i<42;i++){
    const day = new Date(gridStart); day.setDate(gridStart.getDate()+i);
    const key = dayKey(day);
    const allForDay = items.filter(item => dayKey(item.date) === key);
    const visible = allForDay.slice(0,3);
    const more = allForDay.length - visible.length;
    const dayLabel = day.toLocaleDateString(undefined,{weekday:'long',day:'numeric',month:'long'});
    /* The date is a button, not a div with a click handler. A calendar you can
       only open with a pointer is a calendar half the church cannot use, and
       WCAG 2.5.7 asks for exactly this: every selection reachable without a
       drag or a hover. It is also why the button is the day number rather than
       the whole cell — a cell already contains links, and a control containing
       links is not a control. */
    cells.push(`<div class="month-day ${dayKey(day)===dayKey(new Date()) ? 'today' : ''} ${day.getMonth()===state.cursor.getMonth() ? '' : 'out'}" data-new-on="${key}"><button type="button" class="day-num" data-open-inspector="${key}" aria-label="Show what is on ${dayLabel}">${day.getDate()}</button><div class="month-items">${visible.map(monthItemHtml).join('')}${more>0 ? `<button type="button" class="item-more" data-open-day="${key}">+${more} more</button>` : ''}</div></div>`);
  }
  calendarRoot.innerHTML = `<div class="month-grid">${cells.join('')}</div>`;
  rangeLabel.textContent = fmtRange(start, end);
}

/* ---------------------------------------------------------------- time grid */

const TG_SLOT_PX = 44;          // one hour row
const TG_MIN_BLOCK = 22;        // a block stays legible even with no duration

function isAllDayItem(item){
  return item.kind === 'birth' || item.kind === 'anniv';
}

/* The visible hour window is derived from the data rather than fixed at 0-23.
   A church calendar clusters in the evening; rendering midnight to 6am every
   time would push the actual content off screen. */
function tgHourRange(items){
  const timed = items.filter(i => !isAllDayItem(i));
  if(!timed.length) return [8, 21];
  let lo = 24, hi = 0;
  timed.forEach(i => {
    const h = i.date.getHours();
    if(h < lo) lo = h;
    const endH = (i.endsAt instanceof Date && !Number.isNaN(i.endsAt.getTime())
      && dayKey(i.endsAt) === dayKey(i.date)) ? i.endsAt.getHours() : h;
    if(endH > hi) hi = endH;
    if(h > hi) hi = h;
  });
  return [Math.max(0, lo - 1), Math.min(23, Math.max(hi + 1, lo + 4))];
}

/* Events that overlap are placed side by side, which is the whole point of a
   time grid: a double-booking should look like one. */
function tgLayout(dayItems){
  const sorted = dayItems.slice().sort((a,b)=> a.date - b.date);
  const columns = [];
  const placed = sorted.map(item => {
    const startMin = item.date.getHours()*60 + item.date.getMinutes();
    const endMin = tgEndMin(item, startMin);
    let col = columns.findIndex(lastEnd => lastEnd <= startMin);
    if(col === -1){ col = columns.length; columns.push(endMin); }
    else columns[col] = endMin;
    return { item, startMin, endMin, col };
  });
  return { placed, columnCount: Math.max(1, columns.length) };
}

/* A real end time when the source gave one, otherwise a 45-minute stub so the
   block is still visible and clickable. */
function tgEndMin(item, startMin){
  if(item.endsAt instanceof Date && !Number.isNaN(item.endsAt.getTime())){
    const sameDay = dayKey(item.endsAt) === dayKey(item.date);
    const end = sameDay ? item.endsAt.getHours()*60 + item.endsAt.getMinutes() : 24*60;
    if(end > startMin) return end;
  }
  return startMin + 45;
}

function tgEventHtml(entry, rangeStart, columnCount){
  const { item, startMin, endMin, col } = entry;
  const top = ((startMin - rangeStart*60) / 60) * TG_SLOT_PX;
  const height = Math.max(TG_MIN_BLOCK, ((endMin - startMin) / 60) * TG_SLOT_PX - 2);
  const width = 100 / columnCount;
  return `<a class="tg-event item ${itemClasses(item)}" href="${escapeHtml(item.href)}"`
    + ` style="top:${Math.max(0, top)}px;height:${height}px;`
    + `left:calc(${col*width}% + 2px);width:calc(${width}% - 4px)"`
    + ` title="${escapeHtml(fmtClock(item.date))} ${escapeHtml(item.title)}${item.meta ? ' — ' + escapeHtml(item.meta) : ''}">`
    + `<span class="t">${escapeHtml(item.title)}</span>`
    + `<span class="c">${escapeHtml(fmtClock(item.date))}</span></a>`;
}

function renderTimeGrid(days, items){
  const [lo, hi] = tgHourRange(items);
  const hours = [];
  for(let h = lo; h <= hi; h++){
    const label = new Date(2000,0,1,h).toLocaleTimeString([], {hour:'numeric'});
    hours.push(`<div class="tg-hour"><span>${escapeHtml(label)}</span></div>`);
  }
  const todayKey = dayKey(new Date());
  const cols = [];
  const heads = [];
  const allday = [];
  days.forEach(day => {
    const key = dayKey(day);
    const isToday = key === todayKey;
    const dayItems = items.filter(i => dayKey(i.date) === key);
    const timed = dayItems.filter(i => !isAllDayItem(i));
    const untimed = dayItems.filter(isAllDayItem);
    const { placed, columnCount } = tgLayout(timed);

    heads.push(`<div class="tg-dayhead${isToday ? ' is-today' : ''}">`
      + `<div class="tg-dow">${day.toLocaleDateString([], {weekday:'short'})}</div>`
      + `<div class="tg-date">${day.getDate()}</div></div>`);

    allday.push(`<div class="tg-allday-cell">`
      + untimed.map(i => `<a class="item ${itemClasses(i)}" href="${escapeHtml(i.href)}"`
          + ` title="${escapeHtml(i.title)}"><span class="title">${escapeHtml(i.title)}</span></a>`).join('')
      + `</div>`);

    const lines = [];
    const colKey = dayKey(day);
    for(let h = lo; h <= hi; h++){
      const t = (h - lo) * TG_SLOT_PX;
      lines.push(`<div class="tg-line" style="top:${t}px"></div>`);
      lines.push(`<div class="tg-line half" style="top:${t + TG_SLOT_PX/2}px"></div>`);
      // An invisible band per hour, purely so a click lands on something that
      // knows what hour it was. It sits under the events, which are positioned
      // above it, so clicking an event still opens the event.
      lines.push(`<div class="tg-slot" data-slot-date="${colKey}" data-slot-time="`
        + String(h).padStart(2,'0') + `:00" style="top:${t}px;height:${TG_SLOT_PX}px"></div>`);
    }
    let now = '';
    if(isToday){
      const n = new Date();
      const mins = n.getHours()*60 + n.getMinutes();
      if(n.getHours() >= lo && n.getHours() <= hi){
        now = `<div class="tg-now" style="top:${((mins - lo*60)/60)*TG_SLOT_PX}px"></div>`;
      }
    }
    cols.push(`<div class="tg-col${isToday ? ' is-today' : ''}">${lines.join('')}${now}`
      + placed.map(e => tgEventHtml(e, lo, columnCount)).join('') + `</div>`);
  });

  const n = days.length;
  const gridCols = `grid-template-columns:repeat(${n},minmax(0,1fr))`;
  // Published as a custom property so the narrow-screen rules can reserve a
  // readable width per day rather than dividing the phone by seven.
  calendarRoot.style.setProperty('--tg-days', String(n));
  const bodyHeight = (hi - lo + 1) * TG_SLOT_PX;
  const hasAllDay = allday.some(c => c !== '<div class="tg-allday-cell"></div>');

  return `<div class="tg">`
    + `<div class="tg-corner"></div><div class="tg-days" style="${gridCols}">${heads.join('')}</div>`
    + (hasAllDay
        ? `<div class="tg-corner" aria-hidden="true"></div><div class="tg-allday" style="${gridCols}">${allday.join('')}</div>`
        : '')
    + `<div style="display:contents">`
    + `<div class="tg-gutter">${hours.join('')}</div>`
    + `<div class="tg-cols" style="${gridCols};height:${bodyHeight}px">${cols.join('')}</div>`
    + `</div></div>`;
}

function renderWeek(start, end, items){
  mainTitle.textContent = 'Week view';
  const days = [];
  for(let i=0;i<7;i++){ const d = new Date(start); d.setDate(start.getDate()+i); days.push(d); }
  calendarRoot.innerHTML = items.length
    ? renderTimeGrid(days, items)
    : `<div class="pc-empty" role="status"><p class="pc-empty-title">Nothing scheduled</p>`
      + `<p class="pc-empty-body">No events, assignments or birthdays fall in this week for the current filters.</p></div>`;
  rangeLabel.textContent = fmtRange(start, end);
}

function renderDay(start, end, items){
  mainTitle.textContent = 'Day view';
  const dayItems = items.filter(item => dayKey(item.date) === dayKey(start));
  calendarRoot.innerHTML = dayItems.length
    ? renderTimeGrid([new Date(start)], dayItems)
    : `<div class="pc-empty" role="status"><p class="pc-empty-title">Nothing scheduled</p>`
      + `<p class="pc-empty-body">No events, assignments or birthdays fall on this day for the current filters.</p></div>`;
  rangeLabel.textContent = fmtRange(start, end);
}

function renderAgenda(start, end, items){
  mainTitle.textContent = 'Agenda';
  const sorted = items.slice().sort((a,b)=> a.date - b.date);
  if(!sorted.length){
    calendarRoot.innerHTML = `<div class="pc-empty" role="status">`
      + `<p class="pc-empty-title">Nothing scheduled</p>`
      + `<p class="pc-empty-body">No events, assignments or birthdays fall in this month for the current filters.</p></div>`;
    rangeLabel.textContent = fmtRange(start, end);
    return;
  }
  const byDay = new Map();
  sorted.forEach(item => {
    const k = dayKey(item.date);
    if(!byDay.has(k)) byDay.set(k, []);
    byDay.get(k).push(item);
  });
  const todayKey = dayKey(new Date());
  const groups = [];
  byDay.forEach((dayItems, k) => {
    const d = new Date(k + 'T00:00:00');
    const dow = d.toLocaleDateString([], {weekday:'short'});
    const dm = d.toLocaleDateString([], {day:'numeric', month:'short'});
    // One <tbody> per day, with the date cell spanning that day's rows. This
    // is the merged-cell look of a spreadsheet, but rowspan keeps every row
    // associated with its date for assistive technology.
    const rows = dayItems.map((item, i) =>
      `<tr>`
      + (i === 0 ? `<th scope="rowgroup" rowspan="${dayItems.length}" class="ag-date">${escapeHtml(dow)} ${escapeHtml(dm)}</th>` : '')
      + `<td class="ag-time">${agendaTime(item)}</td>`
      + `<td class="ag-title"><a href="${itemHref(item)}">${escapeHtml(item.title || '')}</a></td>`
      + `<td class="ag-kind">${item.kind ? escapeHtml(item.kind) : ''}</td>`
      + `</tr>`).join('');
    groups.push(`<tbody class="ag-day${k===todayKey ? ' is-today' : ''}">${rows}</tbody>`);
  });
  calendarRoot.innerHTML = `<div class="agenda-wrap"><table class="agenda-table">`
    + `<thead><tr><th scope="col">Date</th><th scope="col">Time</th>`
    + `<th scope="col">Event</th><th scope="col">Type</th></tr></thead>`
    + groups.join('') + `</table></div>`;

  rangeLabel.textContent = fmtRange(start, end);
}

async function loadCalendar(push){const [start, end] = rangeForView(state.view, state.cursor); saveView(); syncUrl(push); viewButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.view === state.view));
  if(!state.layers){ state.layers = await loadLayers(); applyV1EventsOff(state.layers); renderLayerColors(state.layers); renderFilterChips(state.layers); } const [assignments, schedules, systemItems] = await Promise.all([loadAssignments(start, end), loadMinistrySchedules(start, end), loadSystemCalendar(start, end)]); const loaded = [...assignments, ...schedules, ...systemItems]; state.data = buildItems(start, end, loaded); state.sources = loaded; setText('visibleCount', state.data.length); setText('rangeCount', loaded.length); renderSourceList(loaded); if(state.view === 'month') renderMonth(start, end, state.data); else if(state.view === 'week') renderWeek(start, end, state.data); else if(state.view === 'agenda') renderAgenda(start, end, state.data); else renderDay(start, end, state.data); }

document.getElementById('pageMenuBtn')?.addEventListener('click',()=>{const d=document.getElementById('calendarDropdown'); if(!d) return; d.classList.toggle('open'); d.classList.contains('open') ? d.querySelector('.dropdown-menu').style.display='grid' : d.querySelector('.dropdown-menu').style.display='none';});
/* Buttons and keyboard share one path, so a shortcut can never drift from what
   the button does. Each is a deliberate move, so it pushes history. */
function step(dir){
  if(state.view==='day') state.cursor.setDate(state.cursor.getDate()+dir);
  else if(state.view==='week') state.cursor.setDate(state.cursor.getDate()+7*dir);
  else state.cursor.setMonth(state.cursor.getMonth()+dir);
  loadCalendar(true);
}
function goToday(){ state.cursor = startOfDay(new Date()); loadCalendar(true); }
function setView(view, date){
  state.view = view;
  if(date) state.cursor = startOfDay(date);
  loadCalendar(true);
}
document.getElementById('prevBtn').addEventListener('click',()=>step(-1));

/* "+N more" hid events behind a plain <div>: not clickable, not focusable, no
   way to reach what it was counting. It now opens that day, which is what the
   count implies and what every other calendar does. */
calendarRoot.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-open-day]');
  if(!btn) return;
  const d = new Date(btn.dataset.openDay + 'T00:00:00');
  if(!Number.isNaN(d.getTime())) setView('day', d);
});

/* Click a day, get an editor with that date already filled in — the calendar
   is where you decide when something happens, so it is where creating should
   start. Only empty space in the cell counts: clicking an event still opens
   the event, and the "+N more" button still opens the day. */
const CAN_CREATE = <?= !empty($canManageEvents) ? 'true' : 'false' ?>;
const CAN_STAFF = <?= !empty($canStaffRoles) ? 'true' : 'false' ?>;
const CAN_SAVE_VIEWS = <?= !empty($canSaveViews) ? 'true' : 'false' ?>;

/* The day inspector.
 *
 * It answers "what is on this date, and who is on it" — the one question the
 * month grid could not answer without opening a ministry grid somewhere else
 * to find the empty roles. Members see that read model only.
 *
 * Schedulers (manage_schedules) can fill or change a role here. Writes go
 * through POST /api/schedules/assignments — the same batch the serving grid
 * already uses. Ids come from GET /api/schedules/staffing because the day
 * JSON names roles without carrying ministry/role/assignment identity.
 */
const inspector = document.getElementById('dayInspector');
const inspectorTitle = document.getElementById('dayInspectorTitle');
const inspectorBody = document.getElementById('dayInspectorBody');
let inspectorReturnFocus = null;
let inspectorToken = 0;
let inspectorStaffing = {date:'', canStaff:false, ministries:[]};
let inspectorYmd = '';

function esc(v){
  return String(v == null ? '' : v).replace(/[&<>"']/g, c => (
    {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]
  ));
}

function campusParams(params){
  const campus = campusSelect?.value;
  if(campus && campus !== '0' && campus !== '') params.set('current_campus_id', campus);
  return params;
}

function addDaysYmd(ymd, n){
  const parts = String(ymd).split('-').map(Number);
  if(parts.length !== 3 || parts.some(value => !Number.isFinite(value))) return ymd;
  const d = new Date(parts[0], parts[1] - 1, parts[2] + n);
  const pad = value => String(value).padStart(2, '0');
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

function occurrenceIdOf(item){
  const m = /^occ:(\d+)$/.exec(String(item && item.id || ''));
  return m ? Number(m[1]) : 0;
}

function findStaffHit(staffing, occId, roleName, ministryName, personName){
  if(!staffing || !occId) return null;
  const wantedMinistry = String(ministryName || '').trim().toLowerCase();
  const wantedRole = String(roleName || '').trim().toLowerCase();
  const wantedPerson = String(personName || '').trim().toLowerCase();
  let fallback = null;
  for(const min of (staffing.ministries || [])){
    const role = (min.roles || []).find(r => String(r.name || '').trim().toLowerCase() === wantedRole);
    if(!role) continue;
    const rows = (min.assignments || []).filter(a => Number(a.occurrenceId) === occId && Number(a.roleId) === Number(role.id));
    let assignment = null;
    if(wantedPerson){
      assignment = rows.find(a => String(a.displayName || '').trim().toLowerCase() === wantedPerson) || null;
    }
    if(!assignment){
      assignment = rows.find(a => !Number(a.personId)) || rows[0] || {
        id: null,
        occurrenceId: occId,
        roleId: role.id,
        personId: 0,
        displayName: '',
        label: ''
      };
    }
    const hit = {ministry: min, role, assignment};
    if(!wantedMinistry || String(min.ministryName || '').trim().toLowerCase() === wantedMinistry){
      return hit;
    }
    fallback = fallback || hit;
  }
  return fallback;
}

function conflictLabels(ministry, personId, occId){
  if(!ministry || !personId || !occId) return [];
  const labels = [];
  for(const c of (ministry.conflicts || [])){
    if(Number(c.person_id) === Number(personId) && Number(c.grid_occurrence_id) === Number(occId)){
      labels.push(String(c.conflict_label || 'Busy at the same time'));
    }
  }
  return labels;
}

function servingGridHref(ministryId, ymd){
  const params = new URLSearchParams({
    ministry_id: String(ministryId),
    start: ymd,
    end: addDaysYmd(ymd, 28)
  });
  const campus = campusSelect?.value;
  if(campus && campus !== '0' && campus !== '') params.set('current_campus_id', campus);
  return basePath + '/schedules?' + params.toString();
}

function staffPickerHtml(hit, ymd, roleLine){
  const open = !roleLine.person;
  if(!hit || !hit.ministry.canWrite){
    return `<span class="di-role-person">${open ? 'Unfilled' : esc(roleLine.person)}</span>`;
  }
  const people = hit.ministry.people || [];
  const currentId = Number(hit.assignment.personId || 0);
  const currentName = hit.assignment.displayName || roleLine.person || '';
  const known = people.some(p => Number(p.id) === currentId);
  let options = `<option value="0"${currentId ? '' : ' selected'}>Unfilled</option>`;
  if(currentId && !known){
    options += `<option value="${currentId}" selected>${esc(currentName || 'Assigned')}</option>`;
  }
  for(const person of people){
    const id = Number(person.id);
    options += `<option value="${id}"${id === currentId ? ' selected' : ''}>${esc(person.displayName)}</option>`;
  }
  const payload = {
    ministryId: hit.ministry.ministryId,
    occurrenceId: Number(hit.assignment.occurrenceId),
    roleId: Number(hit.role.id),
    assignmentId: hit.assignment.id == null ? null : Number(hit.assignment.id),
    ymd
  };
  return `<div class="di-staff">`
    + `<select class="di-person" data-staff="${esc(JSON.stringify(payload))}" data-previous="${currentId}" aria-label="Who serves as ${esc(hit.role.name)}">${options}</select>`
    + `<p class="di-conflict" hidden></p>`
    + `</div>`;
}

function ministryGridLinks(hits, ymd){
  const seen = new Set();
  const links = [];
  for(const hit of hits){
    if(!hit || !hit.ministry.canWrite || seen.has(hit.ministry.ministryId)) continue;
    seen.add(hit.ministry.ministryId);
    const label = hit.ministry.ministryName
      ? `Open serving grid — ${hit.ministry.ministryName}`
      : 'Open serving grid';
    links.push(`<a class="di-grid-link" href="${esc(servingGridHref(hit.ministry.ministryId, ymd))}">${esc(label)}</a>`);
  }
  return links.length ? `<div class="di-grid-links">${links.join('')}</div>` : '';
}

function closeInspector(){
  if(!inspector || inspector.hidden) return;
  inspector.hidden = true;
  const rail = document.getElementById('portalRight');
  if(rail) rail.hidden = true;
  const s = document.querySelector('.shell');
  if(s && s.getAttribute('data-right') !== 'none'){
    s.setAttribute('data-right', 'collapsed');
    try{
      const o = JSON.parse(localStorage.getItem('church_portal_shell_v1') || '{}') || {};
      o.right = 'collapsed';
      localStorage.setItem('church_portal_shell_v1', JSON.stringify(o));
    }catch(e){}
  }
  // Send focus back where it came from, so keyboard use does not dump the
  // reader at the top of the document every time they close a day.
  if(inspectorReturnFocus && document.contains(inspectorReturnFocus)) inspectorReturnFocus.focus();
  inspectorReturnFocus = null;
}

function renderInspector(payload, staffing, ymd){
  inspectorStaffing = staffing || inspectorStaffing;
  inspectorYmd = ymd;
  const items = (payload && payload.items) || [];
  const unfilled = items.reduce((n, item) => {
    if(!Array.isArray(item.roles)) return n;
    return n + item.roles.filter(r => !r.person).length;
  }, 0);
  const unfilledEl = document.getElementById('dayUnfilledCount');
  if(unfilledEl){
    unfilledEl.hidden = unfilled === 0;
    unfilledEl.textContent = unfilled === 0
      ? ''
      : (unfilled === 1 ? '1 unfilled role' : unfilled + ' unfilled roles');
  }
  if(items.length === 0){
    inspectorBody.innerHTML = '<p class="di-empty">Nothing is on this day.</p>' + inspectorActions(ymd);
    return;
  }
  inspectorBody.innerHTML = items.map(item => {
    const colour = item.color || 'var(--line,#d9e4dd)';
    const title = item.href
      ? `<a href="${esc(basePath + item.href)}">${esc(item.title)}</a>`
      : esc(item.title);
    const meta = [item.meta, item.source_label].filter(Boolean).map(esc).join(' · ');
    const occId = occurrenceIdOf(item);
    const hits = [];
    const roles = Array.isArray(item.roles) && item.roles.length
      ? `<ul class="di-roles">` + item.roles.map(r => {
          const open = !r.person;
          const hit = findStaffHit(staffing, occId, r.name, r.ministry, r.person);
          if(hit) hits.push(hit);
          return `<li class="di-role${open ? ' is-open' : ''}">`
            + `<div class="di-role-head"><span class="di-role-name">${esc(r.name)}</span>`
            + (hit && hit.ministry.canWrite ? '' : `<span class="di-role-person">${open ? 'Unfilled' : esc(r.person)}</span>`)
            + `</div>`
            + (hit && hit.ministry.canWrite ? staffPickerHtml(hit, ymd, r) : '')
            + `</li>`;
        }).join('') + `</ul>`
      : '';
    return `<div class="di-item" style="border-left-color:${esc(colour)}">`
      + `<div class="di-item-title">${title}</div>`
      + (meta ? `<div class="di-item-meta">${meta}</div>` : '')
      + roles
      + ministryGridLinks(hits, ymd)
      + `</div>`;
  }).join('') + inspectorActions(ymd);
}

/* Create stays one click away rather than disappearing. Clicking a day used to
   go straight to the event form; now it answers the question first and offers
   the form second, which is the right order for a day that already has four
   things on it. */
function inspectorActions(ymd){
  if(!CAN_CREATE) return '';
  const params = new URLSearchParams({date: ymd});
  const campus = campusSelect?.value;
  if(campus && campus !== '0' && campus !== '') params.set('campus', campus);
  params.set('return', location.pathname + location.search);
  return `<div class="di-actions"><a class="di-new" href="${esc(basePath + '/events/new?' + params.toString())}">New event on this day</a></div>`;
}

async function openInspector(ymd, trigger){
  if(!inspector) return;
  inspectorReturnFocus = trigger || null;
  inspectorYmd = ymd;
  const when = new Date(ymd + 'T00:00:00');
  inspectorTitle.textContent = Number.isNaN(when.getTime())
    ? ymd
    : when.toLocaleDateString(undefined,{weekday:'long', day:'numeric', month:'long'});
  inspectorBody.innerHTML = '<p class="di-empty">Loading…</p>';
  inspector.hidden = false;
  const rail = document.getElementById('portalRight');
  if(rail) rail.hidden = false;
  const s = document.querySelector('.shell');
  if(s && s.getAttribute('data-right') !== 'none'){
    s.setAttribute('data-right', 'open');
    try{
      const o = JSON.parse(localStorage.getItem('church_portal_shell_v1') || '{}') || {};
      o.right = 'open';
      localStorage.setItem('church_portal_shell_v1', JSON.stringify(o));
    }catch(e){}
  }
  inspectorTitle.focus();

  // Only the newest request may paint. Clicking along a week faster than the
  // network answers would otherwise leave whichever reply arrived last on
  // screen, which is not necessarily the day now highlighted.
  const token = ++inspectorToken;
  try {
    const dayParams = campusParams(new URLSearchParams({date: ymd}));
    const dayReq = fetch(basePath + '/api/calendar/day?' + dayParams.toString(), {credentials:'same-origin'});
    const staffReq = CAN_STAFF
      ? fetch(basePath + '/api/schedules/staffing?' + campusParams(new URLSearchParams({date: ymd})).toString(), {credentials:'same-origin'})
      : Promise.resolve(null);
    const [dayRes, staffRes] = await Promise.all([dayReq, staffReq]);
    if(!dayRes.ok) throw new Error('day request failed');
    const payload = await dayRes.json();
    let staffing = {date: ymd, canStaff: false, ministries: []};
    if(staffRes && staffRes.ok){
      staffing = await staffRes.json();
    }
    if(token !== inspectorToken) return;
    renderInspector(payload, staffing, ymd);
  } catch (err) {
    if(token !== inspectorToken) return;
    inspectorBody.innerHTML = '<p class="di-empty">That day could not be loaded.</p>' + inspectorActions(ymd);
  }
}

function showStaffStatus(select, message, isError){
  const box = select.closest('.di-staff');
  if(!box) return;
  let status = box.querySelector('.di-status');
  if(!status){
    status = document.createElement('p');
    status.className = 'di-status';
    box.appendChild(status);
  }
  status.textContent = message;
  status.classList.toggle('is-err', !!isError);
}

function showStaffConflict(select, labels){
  const note = select.closest('.di-staff')?.querySelector('.di-conflict');
  if(!note) return;
  if(!labels.length){
    note.hidden = true;
    note.textContent = '';
    return;
  }
  note.hidden = false;
  note.textContent = 'Busy at the same time: ' + labels.join(' · ');
}

async function saveInspectorAssignment(select){
  let spec;
  try { spec = JSON.parse(select.dataset.staff || '{}'); }
  catch (err) { return; }
  const personId = Number(select.value || 0);
  const previous = Number(select.dataset.previous || 0);
  const ministry = (inspectorStaffing.ministries || []).find(m => Number(m.ministryId) === Number(spec.ministryId));
  const labels = conflictLabels(ministry, personId, spec.occurrenceId);
  showStaffConflict(select, labels);
  if(labels.length && !confirm('This person is busy at the same time: ' + labels.join(' · ') + '\n\nAssign anyway?')){
    select.value = String(previous);
    showStaffConflict(select, conflictLabels(ministry, previous, spec.occurrenceId));
    return;
  }
  select.disabled = true;
  showStaffStatus(select, 'Saving…', false);
  const assignment = {
    occurrenceId: Number(spec.occurrenceId),
    roleId: Number(spec.roleId),
    personId,
    label: ''
  };
  if(spec.assignmentId) assignment.id = Number(spec.assignmentId);
  try {
    // 4a: POST body is ministryId + one assignment. No start/end — those
    // trigger a window diff-delete on the existing batch endpoint.
    const res = await fetch(basePath + '/api/schedules/assignments', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: JSON.stringify({ministryId: Number(spec.ministryId), assignments: [assignment]})
    });
    const data = await res.json().catch(() => ({}));
    if(!res.ok){
      select.value = String(previous);
      showStaffStatus(select, data.error || 'That assignment could not be saved.', true);
      select.disabled = false;
      return;
    }
    select.dataset.previous = String(personId);
    await openInspector(spec.ymd || inspectorYmd, inspectorReturnFocus);
  } catch (err) {
    select.value = String(previous);
    showStaffStatus(select, 'That assignment could not be saved.', true);
    select.disabled = false;
  }
}

calendarRoot.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-open-inspector]');
  if(!btn) return;
  e.preventDefault();
  openInspector(btn.dataset.openInspector, btn);
});
document.getElementById('dayInspectorClose')?.addEventListener('click', closeInspector);
document.addEventListener('keydown', (e)=>{
  if(e.key !== 'Escape') return;
  if(document.querySelector('dialog[open]')) return;
  closeInspector();
});
inspectorBody?.addEventListener('change', (e)=>{
  const select = e.target.closest && e.target.closest('select.di-person');
  if(!select || !inspectorBody.contains(select)) return;
  saveInspectorAssignment(select);
});
function newEventAt(ymd, hhmm){
  const params = new URLSearchParams();
  if(ymd) params.set('date', ymd);
  if(hhmm) params.set('time', hhmm);
  const campus = campusSelect?.value;
  if(campus && campus !== '0' && campus !== '') params.set('campus', campus);
  // Come back to the month and campus you were looking at, not to a reset
  // calendar — you were mid-thought when you started.
  params.set('return', location.pathname + location.search);
  location.href = basePath + '/events/new?' + params.toString();
}
/* Empty space in a month cell opens the day, for everyone — reading what is on
   a date is not an editing permission. Create is offered inside the panel to
   those who may. Week and day views keep their create-on-click, because a click
   there carries an hour and "an event at this time" is a different intent. */
calendarRoot.addEventListener('click', (e)=>{
  if(e.target.closest('a.item, .item-more, [data-open-day], button')) return;
  const cell = e.target.closest('[data-new-on]');
  if(!cell) return;
  openInspector(cell.dataset.newOn, null);
});
if(CAN_CREATE){
  /* Week and day views know the hour as well as the date, so a click there
     means "an event at this time". */
  calendarRoot.addEventListener('click', (e)=>{
    if(e.target.closest('a.item, .item-more, button')) return;
    const slot = e.target.closest('[data-slot-date]');
    if(!slot) return;
    newEventAt(slot.dataset.slotDate, slot.dataset.slotTime || null);
  });
  document.getElementById('calNewBtn')?.addEventListener('click', ()=>{
    newEventAt(dayKey(state.cursor), null);
  });
}
document.getElementById('nextBtn').addEventListener('click',()=>step(1));
document.getElementById('todayBtn').addEventListener('click',goToday);
viewButtons.forEach(btn => btn.addEventListener('click',()=>setView(btn.dataset.view || 'month')));

function ymdLocal(d){
  const pad = n => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}
function visibleSources(){
  const layers = state.layers || [];
  return layers.filter(l => state.filters[l.source] !== false).map(l => l.source);
}
function shareableSources(){
  // Custom calendars stay in the browser (I-custom-cals). A saved view that
  // listed them would look shared and then silently miss them on another machine.
  return visibleSources().filter(source => source !== 'custom');
}
function currentScreenConfig(){
  const left = document.querySelector('.shell')?.getAttribute('data-left') || '';
  return {
    content: {sources: shareableSources()},
    screen: {
      view: state.view,
      left: (left === 'open' || left === 'collapsed') ? left : ''
    }
  };
}
function printThisViewHref(){
  const params = new URLSearchParams();
  const sources = shareableSources();
  if(sources.length) params.set('sources', sources.join(','));
  const [start, end] = rangeForView(state.view, state.cursor);
  params.set('dateMode', 'custom');
  params.set('start', ymdLocal(start));
  const last = state.view === 'day' ? start : new Date(end.getTime() - 1);
  params.set('end', ymdLocal(last));
  const campus = campusSelect?.value;
  if(campus && campus !== '0' && campus !== '') params.set('current_campus_id', campus);
  return basePath + '/calendar/print-setup?' + params.toString();
}
document.getElementById('printBtn')?.addEventListener('click', (e)=>{
  e.preventDefault();
  location.href = printThisViewHref();
});

async function applySavedView(id){
  const res = await fetch(basePath + '/api/calendar/views/' + id, {credentials:'same-origin'});
  if(!res.ok) throw new Error('view missing');
  const data = await res.json();
  const view = data.view || {};
  const cfg = view.config || {};
  const sources = (cfg.content && Array.isArray(cfg.content.sources)) ? cfg.content.sources : [];
  const layers = state.layers || [];
  if(sources.length && layers.length){
    const want = new Set(sources);
    const customOff = state.filters.custom === false;
    state.filters = {};
    layers.forEach(l => {
      if(l.source === 'custom'){
        if(customOff) state.filters.custom = false;
        return;
      }
      if(!want.has(l.source)) state.filters[l.source] = false;
    });
    saveFilters();
    renderFilterChips(layers);
    renderSourceList(state.sources || []);
  }
  const screen = view.screen || {};
  if(screen.view && ['month','week','day','agenda'].includes(screen.view)){
    state.view = screen.view;
    saveView();
  }
  if(screen.left === 'open' || screen.left === 'collapsed'){
    const s = document.querySelector('.shell');
    if(s && s.getAttribute('data-left') !== 'none'){
      s.setAttribute('data-left', screen.left);
      try{
        const o = JSON.parse(localStorage.getItem('church_portal_shell_v1') || '{}') || {};
        o.left = screen.left;
        localStorage.setItem('church_portal_shell_v1', JSON.stringify(o));
      }catch(e){}
    }
  }
  loadCalendar(true);
}
async function listSavedViews(){
  const res = await fetch(basePath + '/api/calendar/views', {credentials:'same-origin'});
  if(!res.ok) throw new Error('views failed');
  const data = await res.json();
  return Array.isArray(data.views) ? data.views : [];
}
const viewDialog = document.getElementById('calViewDialog');
document.getElementById('openViewBtn')?.addEventListener('click', async ()=>{
  const list = document.getElementById('calViewList');
  const empty = document.getElementById('calViewEmpty');
  const status = document.getElementById('calViewStatus');
  if(status) { status.hidden = true; status.textContent = ''; }
  if(list) list.innerHTML = '';
  try{
    const views = await listSavedViews();
    if(!views.length){
      if(empty) empty.hidden = false;
    } else {
      if(empty) empty.hidden = true;
      if(list){
        list.innerHTML = views.map(v => {
          const meta = (v.visibility === 'shared' ? 'Shared' : 'Private')
            + (v.mine ? ' · yours' : '')
            + (v.canEdit ? '' : ' · copy to change');
          return `<button type="button" data-view-id="${esc(v.id)}"><span class="cal-view-name">${esc(v.name)}</span><span class="cal-view-meta">${esc(meta)}</span></button>`;
        }).join('');
      }
    }
    viewDialog?.showModal();
  } catch (err) {
    if(status){ status.hidden = false; status.textContent = 'Saved views could not be loaded.'; }
    viewDialog?.showModal();
  }
});
document.getElementById('calViewClose')?.addEventListener('click', ()=> viewDialog?.close());
document.getElementById('calViewList')?.addEventListener('click', async (e)=>{
  const btn = e.target.closest('[data-view-id]');
  if(!btn) return;
  try{
    await applySavedView(btn.dataset.viewId);
    viewDialog?.close();
  } catch (err) {
    const status = document.getElementById('calViewStatus');
    if(status){ status.hidden = false; status.textContent = 'That view could not be opened.'; }
  }
});
document.getElementById('saveViewBtn')?.addEventListener('click', async ()=>{
  if(!CAN_SAVE_VIEWS) return;
  const name = window.prompt('Name this view', 'My calendar');
  if(!name || !name.trim()) return;
  try{
    const res = await fetch(basePath + '/api/calendar/views', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify({name: name.trim(), visibility: 'private', config: currentScreenConfig()})
    });
    const data = await res.json().catch(()=>({}));
    if(!res.ok) throw new Error(data.error || 'save failed');
  } catch (err) {
    window.alert(err.message || 'That view could not be saved.');
  }
});

/* Keyboard shortcuts, the set every calendar application shares: arrows page
   the period, T returns to today, and M/W/D/A switch view. Suppressed while a
   field or the command palette has focus, so typing "day" into search does not
   navigate the calendar. */
document.addEventListener('keydown', (e)=>{
  if(e.metaKey || e.ctrlKey || e.altKey) return;
  const el = document.activeElement;
  if(el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName))) return;
  if(document.querySelector('.search-overlay.open, dialog[open]')) return;
  const views = {m:'month', w:'week', d:'day', a:'agenda'};
  const key = e.key.toLowerCase();
  if(e.key==='ArrowLeft'){ step(-1); }
  else if(e.key==='ArrowRight'){ step(1); }
  else if(key==='t'){ goToday(); }
  else if(views[key]){ setView(views[key]); }
  else return;
  e.preventDefault();
});

/* Back and Forward now walk the calendar instead of leaving the page. */
window.addEventListener('popstate', (e)=>{
  const st = e.state || {};
  const params = new URLSearchParams(location.search);
  state.view = st.view || params.get('view') || state.view;
  const d = st.date || params.get('date');
  const parsed = d ? new Date(d + 'T00:00:00') : null;
  if(parsed && !Number.isNaN(parsed.getTime())) state.cursor = parsed;
  loadCalendar();
});
filtersBar.addEventListener('change', (event) => {
  const input = event.target;
  if (input && input.matches('input[type="checkbox"]')) {
    state.filters[input.value] = input.checked;
    saveFilters();
    loadCalendar();
  }
});
campusSelect?.addEventListener('change', loadCalendar);
calendarRoot.addEventListener('click', (event) => {
  const link = event.target.closest('a.item');
  if (!link) return;
});

// Filters toggle: show/hide the filters bar to keep calendar focused
const toggleFiltersBtn = document.getElementById('toggleFiltersBtn');
if(toggleFiltersBtn){
  toggleFiltersBtn.addEventListener('click', ()=>{
    const expanded = toggleFiltersBtn.getAttribute('aria-expanded') === 'true';
    toggleFiltersBtn.setAttribute('aria-expanded', String(!expanded));
    toggleFiltersBtn.textContent = expanded ? 'Filters' : 'Hide filters';
    if(expanded){
      filtersBar.style.display = 'none';
    } else {
      filtersBar.style.display = 'flex';
    }
  });
}
// Deleting an event and pressing Back used to leave the deleted entry on the
// calendar: the page came back from the browser's back/forward cache, which is
// a snapshot of the DOM and runs no fetch at all. Nothing served from the API
// can fix that, so the page reloads its own data when it is restored, and again
// when the tab is returned to after being away.
let lastLoadedAt = Date.now();
const STALE_MS = 30000;
window.addEventListener('pageshow', function (e) { if (e.persisted) { lastLoadedAt = Date.now(); loadCalendar(); } });
document.addEventListener('visibilitychange', function () {
  // Returning to the tab refetches only if the view has had time to go stale.
  // Every alt-tab is not a reason to re-query the calendar.
  if (document.visibilityState !== 'visible') return;
  if (Date.now() - lastLoadedAt < STALE_MS) return;
  lastLoadedAt = Date.now();
  loadCalendar();
});

loadCalendar();
</script>
<script>
(function(){
  // Calendar sources collapse on small screens so the calendar itself is the
  // first content. Presentation only — no source state changes.
  var side=document.getElementById('calSide'); if(!side) return;
  var mq=window.matchMedia('(max-width:760px)');
  var det=document.createElement('details'); det.className='side-disclosure';
  var sum=document.createElement('summary'); sum.className='side-summary'; sum.textContent='Calendar sources';
  det.appendChild(sum);
  side.parentNode.insertBefore(det,side); det.appendChild(side);
  function apply(){ det.open = !mq.matches; }
  apply(); mq.addEventListener ? mq.addEventListener('change',apply) : mq.addListener(apply);
})();
</script>
</body>
</html>
