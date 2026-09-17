<?php

declare(strict_types=1);

/**
 * The event editor, shared by every surface that schedules something.
 *
 * One editor, three callers: /events/new, the event page's edit mode, and the
 * calendar's quick-create. They were three separate implementations of the same
 * form, which is how they drifted into three different interaction models.
 *
 * The old form asked four questions under four headings — What is it? When?
 * What time? Who is it for, and where? — and put seventeen controls and twelve
 * helper paragraphs on screen before anything was typed, pushing the Create
 * button off the bottom of a 900px viewport. Scheduling a service is one act,
 * not four, so this is one panel: name it, say when, say who it is for. Location,
 * description and type are real capabilities and stay reachable, but they are
 * not what most events need, so they wait behind a disclosure.
 *
 * Every value here is a token. No raw hex, per _portal-components.php.
 */

require_once __DIR__ . '/_portal-components.php';

if (!function_exists('ee_styles')) {
    function ee_styles(): string
    {
        return <<<'CSS'
<style>
/* --- Event editor ------------------------------------------------------ */
.ee{display:grid;gap:var(--sp-3,12px);background:var(--paper,#fff);
  border:1px solid var(--line,#d9e4dd);border-radius:var(--radius-lg,11px);
  padding:var(--sp-4,16px);max-width:640px}
.ee-sr{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;
  overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;border:0}

/* The name is the one thing every event has, so it is the one thing that
   looks like a heading rather than a field. */
.ee-name{width:100%;font:inherit;font-size:20px;font-weight:800;color:var(--ink,#1b3228);
  border:0;border-bottom:2px solid var(--line,#d9e4dd);border-radius:0;
  padding:var(--sp-2,8px) 0;background:transparent}
.ee-name::placeholder{color:var(--muted,#627169);font-weight:600}
.ee-name:focus{outline:0;border-bottom-color:var(--teal,#117b6d)}

/* One label column, one control column: the eye runs down a single edge
   instead of hunting for where each group begins. */
.ee-row{display:grid;grid-template-columns:84px minmax(0,1fr);gap:var(--sp-3,12px);
  align-items:center;min-height:34px}
.ee-lab{font-size:12px;font-weight:700;color:var(--muted,#627169);
  text-transform:uppercase;letter-spacing:.04em}
.ee-ctl{display:flex;flex-wrap:wrap;align-items:center;gap:var(--sp-2,8px)}

.ee input[type=text],.ee input[type=date],.ee input[type=time],.ee input[type=number],
.ee select,.ee textarea{font:inherit;font-size:13.5px;color:var(--ink,#1b3228);
  background:var(--paper,#fff);border:1px solid var(--line,#d9e4dd);
  border-radius:var(--radius-sm,5px);padding:6px 8px;min-height:34px}
.ee select{padding-right:22px}
.ee textarea{width:100%;min-height:64px;resize:vertical;padding:8px}
.ee :is(input,select,textarea):focus-visible{outline:2px solid var(--focus-ring,#117b6d);
  outline-offset:1px;border-color:var(--focus-ring,#117b6d)}
.ee-grow{flex:1 1 150px;min-width:0}
.ee-dash{color:var(--muted,#627169)}

/* Date and time are one scheduling thought and share one row. */
.ee-when input[type=date]{flex:0 1 150px}
.ee-when input[type=time]{flex:0 1 106px}
.ee-allday{display:inline-flex;align-items:center;gap:6px;font-size:13px;
  color:var(--ink,#1b3228);cursor:pointer;min-height:34px;padding:0 4px}
.ee-allday input{width:16px;height:16px;accent-color:var(--teal,#117b6d);cursor:pointer}

/* Campus as chips rather than checkbox cards: real buttons carrying their own
   pressed state, so the selection is announced and not merely coloured in. */
.ee-chips{display:flex;flex-wrap:wrap;gap:6px}
.ee-chip{font:inherit;font-size:13px;font-weight:700;cursor:pointer;
  min-height:32px;padding:5px 12px;border-radius:var(--radius-full,999px);
  border:1px solid var(--line,#d9e4dd);background:var(--paper,#fff);color:var(--ink,#1b3228)}
.ee-chip:hover{border-color:var(--teal,#117b6d)}
.ee-chip[aria-pressed=true]{background:var(--teal,#117b6d);border-color:var(--teal,#117b6d);
  color:var(--on-teal,#fff)}
.ee-chip[aria-pressed=true]::before{content:"✓ ";font-weight:900}
.ee-chip:focus-visible{outline:2px solid var(--focus-ring,#117b6d);outline-offset:2px}

/* Disclosure. Complexity stays available; it stops being the first thing you
   see. */
.ee-more{font:inherit;font-size:13px;font-weight:700;color:var(--teal,#117b6d);
  background:none;border:0;padding:6px 2px;min-height:32px;cursor:pointer;
  text-align:left;justify-self:start;border-radius:var(--radius-sm,5px)}
.ee-more:hover{text-decoration:underline}
.ee-more:focus-visible{outline:2px solid var(--focus-ring,#117b6d);outline-offset:2px}
.ee-more::before{content:"+ "}
.ee-more[aria-expanded=true]::before{content:"− "}
.ee-panel{display:grid;gap:var(--sp-3,12px);padding-top:var(--sp-1,4px)}
.ee-panel[hidden]{display:none}

.ee-hint{font-size:12px;color:var(--muted,#627169);margin:0;line-height:1.45}
.ee-say{font-size:13px;color:var(--muted,#627169);margin:0;
  grid-column:2/-1;font-style:italic}

/* Errors sit with the field they belong to, and the summary is reachable. */
.ee-err{margin:0;font-size:12.5px;font-weight:700;color:var(--rose,#b3261e);grid-column:2/-1}
.ee-err[hidden]{display:none}
.ee :is(input,select)[aria-invalid=true]{border-color:var(--rose,#b3261e)}
.ee-summary{padding:var(--sp-3,12px);border-radius:var(--radius,8px);
  border:1px solid var(--rose,#b3261e);background:color-mix(in srgb,var(--rose,#b3261e) 8%,transparent)}
.ee-summary[hidden]{display:none}
.ee-summary h2{margin:0 0 6px;font-size:13px;font-weight:800;color:var(--rose,#b3261e)}
.ee-summary ul{margin:0;padding-left:18px;font-size:13px}
.ee-summary a{color:var(--rose,#b3261e);font-weight:700}

.ee-foot{display:flex;justify-content:flex-end;gap:var(--sp-2,8px);
  padding-top:var(--sp-3,12px);border-top:1px solid var(--line,#d9e4dd)}
.ee-foot .ee-spacer{margin-right:auto}

/* Chosen dates, shown only when the schedule is a list rather than a rule. */
.ee-dates{display:flex;flex-wrap:wrap;gap:6px;grid-column:2/-1}
.ee-date{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;
  background:var(--soft,#eef4f0);border:1px solid var(--line,#d9e4dd);
  border-radius:var(--radius-full,999px);padding:3px 6px 3px 10px}
.ee-date button{font:inherit;line-height:1;border:0;background:none;cursor:pointer;
  color:var(--muted,#627169);min-width:24px;min-height:24px;border-radius:var(--radius-full,999px)}
.ee-date button:hover{color:var(--rose,#b3261e)}
.ee-date button:focus-visible{outline:2px solid var(--focus-ring,#117b6d)}

/* Mobile keeps comfortable targets and drops the label column, which costs
   84px it cannot spare. */
@media(max-width:600px){
  .ee-row{grid-template-columns:1fr;gap:4px;align-items:stretch}
  .ee-say,.ee-err,.ee-dates{grid-column:1/-1}
  .ee input[type=text],.ee input[type=date],.ee input[type=time],
  .ee select,.ee textarea{min-height:42px;font-size:16px}
  .ee-when input[type=date],.ee-when input[type=time]{flex:1 1 120px}
  .ee-chip{min-height:38px}
  .ee-foot{position:sticky;bottom:0;background:var(--paper,#fff);
    padding-bottom:var(--sp-2,8px);margin:0 calc(var(--sp-4,16px) * -1);
    padding-left:var(--sp-4,16px);padding-right:var(--sp-4,16px)}
}
</style>
CSS;
    }
}

if (!function_exists('ee_html')) {
    /**
     * @param array{
     *   campuses?:array, ministries?:array, eventTypes?:array,
     *   values?:array<string,mixed>, submitLabel?:string, cancelHref?:string,
     *   omit?:list<string>
     * } $o
     *
     * `omit` drops rows the caller's save path cannot write. Editing an
     * existing event goes through PATCH, which changes the event's properties
     * but not its dates — those belong to the occurrences and are managed as a
     * schedule. Showing a date control that silently does nothing would be
     * worse than not showing one.
     */
    function ee_html(array $o): string
    {
        $v = is_array($o['values'] ?? null) ? $o['values'] : [];
        $campuses = is_array($o['campuses'] ?? null) ? $o['campuses'] : [];
        $ministries = is_array($o['ministries'] ?? null) ? $o['ministries'] : [];
        $types = is_array($o['eventTypes'] ?? null) ? $o['eventTypes'] : [];
        $submit = pc_attr((string) ($o['submitLabel'] ?? 'Create event'));
        $omit = array_flip((array) ($o['omit'] ?? []));
        $skipWhen = isset($omit['when']);
        $cancel = pc_attr((string) ($o['cancelHref'] ?? ''));

        $val = static fn (string $k, string $d = ''): string => pc_attr((string) ($v[$k] ?? $d));
        $selectedCampuses = array_map('intval', (array) ($v['campusIds'] ?? []));

        $presets = \App\Services\Events\RecurrenceRule::PRESETS;
        $repeatOptions = '';
        foreach ($presets as $key => $label) {
            $repeatOptions .= '<option value="' . pc_attr($key) . '"'
                . (($v['pattern'] ?? 'one_off') === $key ? ' selected' : '') . '>'
                . pc_attr($label) . '</option>';
        }

        $ministryOptions = '<option value="">Church-wide — no particular ministry</option>';
        foreach ($ministries as $m) {
            $id = (int) ($m['ministry_id'] ?? $m['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $ministryOptions .= '<option value="' . $id . '"'
                . ((int) ($v['ministryId'] ?? 0) === $id ? ' selected' : '') . '>'
                . pc_attr((string) ($m['name'] ?? '')) . '</option>';
        }

        $typeOptions = '';
        foreach ($types as $t) {
            $id = (int) ($t['typeId'] ?? $t['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $typeOptions .= '<option value="' . $id . '"'
                . ((int) ($v['eventTypeId'] ?? 0) === $id ? ' selected' : '') . '>'
                . pc_attr((string) ($t['label'] ?? $t['name'] ?? '')) . '</option>';
        }

        // "All campuses" is a chip of its own rather than the meaning of an
        // empty selection. A church-wide event should say so.
        $campusChips = '<button type="button" class="ee-chip" data-campus="0"'
            . ' aria-pressed="' . ($selectedCampuses === [] ? 'true' : 'false') . '">All campuses</button>';
        foreach ($campuses as $c) {
            $id = (int) ($c['id'] ?? $c['campus_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $campusChips .= '<button type="button" class="ee-chip" data-campus="' . $id . '"'
                . ' aria-pressed="' . (in_array($id, $selectedCampuses, true) ? 'true' : 'false') . '">'
                . pc_attr((string) ($c['name'] ?? $c['campus_name'] ?? 'Campus')) . '</button>';
        }

        $hostOptions = '<option value="">Same as the campus above</option>';
        foreach ($campuses as $c) {
            $id = (int) ($c['id'] ?? $c['campus_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $hostOptions .= '<option value="' . $id . '"'
                . ((int) ($v['hostCampusId'] ?? 0) === $id ? ' selected' : '') . '>'
                . pc_attr((string) ($c['name'] ?? $c['campus_name'] ?? 'Campus')) . '</option>';
        }

        // Existing tags as one comma-separated field, with every tag already in
        // use offered as a suggestion — a datalist rather than a widget,
        // because the browser's own is keyboard- and screen-reader-native and a
        // hand-rolled combobox rarely is.
        $tagValue = pc_attr(implode(', ', array_map(
            static fn (array $t): string => (string) ($t['label'] ?? ''),
            (array) ($v['tags'] ?? []),
        )));
        $tagOptions = '';
        foreach ((array) ($o['allTags'] ?? []) as $t) {
            $label = (string) ($t['label'] ?? '');
            if ($label !== '') {
                $tagOptions .= '<option value="' . pc_attr($label) . '"></option>';
            }
        }

        $desc = pc_attr((string) ($v['description'] ?? ''));
        $assignOn = !empty($v['usesServingSchedule']) || !empty($v['uses_serving_schedule']);
        $assignChecked = $assignOn ? ' checked' : '';
        // An event that already has a description must show it, not hide it
        // behind a disclosure the editor would have to be told to open.
        $detailsOpen = $desc !== '' || ($v['locationName'] ?? '') !== '' || ($v['hostCampusId'] ?? 0) > 0 || $assignOn;
        $optionsOpenAttr = $assignOn ? '1' : '0';
        $cancelBtn = $cancel !== ''
            ? '<a class="pc-btn pc-btn--ghost pc-btn--md" href="' . $cancel . '">Cancel</a>'
            : '<button type="button" class="pc-btn pc-btn--ghost pc-btn--md" id="eeCancel">Cancel</button>';

        $whenBlock = $skipWhen ? '' : <<<HTML
  <div class="ee-row ee-when">
    <span class="ee-lab" id="eeWhenLab">When</span>
    <div class="ee-ctl" role="group" aria-labelledby="eeWhenLab">
      <label class="ee-sr" for="eeDate">Date</label>
      <input type="date" id="eeDate" value="{$val('startDate')}" aria-describedby="eeWhenErr">
      <label class="ee-sr" for="eeStart">Start time</label>
      <input type="time" id="eeStart" value="{$val('startTime')}">
      <span class="ee-dash" aria-hidden="true">—</span>
      <label class="ee-sr" for="eeEnd">End time</label>
      <input type="time" id="eeEnd" value="{$val('endTime')}" aria-describedby="eeEndErr">
      <label class="ee-allday"><input type="checkbox" id="eeAllDay"> All day</label>
    </div>
    <p class="ee-err" id="eeWhenErr" hidden></p>
    <p class="ee-err" id="eeEndErr" hidden></p>
  </div>

  <div class="ee-row">
    <label class="ee-lab" for="eeRepeat">Repeats</label>
    <div class="ee-ctl">
      <select id="eeRepeat" class="ee-grow">{$repeatOptions}</select>
    </div>
    <p class="ee-say" id="eeSays"></p>
  </div>

  <div class="ee-row" id="eeUntilRow" hidden>
    <label class="ee-lab" for="eeUntil">Until</label>
    <div class="ee-ctl">
      <input type="date" id="eeUntil" value="{$val('untilOn')}">
      <span class="ee-hint">Leave blank to repeat for a year.</span>
    </div>
  </div>

  <div class="ee-row" id="eeDatesRow" hidden>
    <label class="ee-lab" for="eePickDate">Dates</label>
    <div class="ee-ctl">
      <input type="date" id="eePickDate">
      <button type="button" class="pc-btn pc-btn--ghost pc-btn--sm" id="eeAddDate">Add date</button>
    </div>
    <div class="ee-dates" id="eeDateList" role="list"></div>
  </div>
HTML;

        return <<<HTML
<form class="ee" id="eeForm" novalidate data-options-open="{$optionsOpenAttr}">
  <div class="ee-summary" id="eeSummary" role="alert" tabindex="-1" hidden>
    <h2>This event cannot be saved yet</h2>
    <ul id="eeSummaryList"></ul>
  </div>

  <div>
    <label class="ee-sr" for="eeTitle">Event name</label>
    <input class="ee-name" type="text" id="eeTitle" value="{$val('title')}"
           placeholder="What are we putting on the calendar?"
           aria-describedby="eeTitleErr" autocomplete="off">
    <p class="ee-err" id="eeTitleErr" hidden></p>
  </div>

{$whenBlock}

  <div class="ee-row">
    <span class="ee-lab" id="eeCampusLab">Campus</span>
    <div class="ee-chips" role="group" aria-labelledby="eeCampusLab" id="eeCampuses">{$campusChips}</div>
  </div>

  <div class="ee-row">
    <label class="ee-lab" for="eeMinistry">Ministry</label>
    <div class="ee-ctl">
      <select id="eeMinistry" class="ee-grow">{$ministryOptions}</select>
    </div>
  </div>

  <button type="button" class="ee-more" id="eeDetailsToggle"
          aria-expanded="false" aria-controls="eeDetails">Add details</button>

  <div class="ee-panel" id="eeDetails" hidden>
    <div class="ee-row">
      <label class="ee-lab" for="eeHost">Held at</label>
      <div class="ee-ctl">
        <select id="eeHost" class="ee-grow">{$hostOptions}</select>
        <select id="eeWhere" class="ee-grow">
          <option value="campus">At that campus</option>
          <option value="other">Somewhere else…</option>
        </select>
      </div>
      <p class="ee-hint" style="grid-column:2/-1">Campus is who the event is for. Held at is where it physically happens — a joint service can be for Scarborough and held at North York.</p>
    </div>

    <div class="ee-row" id="eeElsewhereRow" hidden>
      <label class="ee-lab" for="eeLocName">Venue</label>
      <div class="ee-ctl">
        <input type="text" id="eeLocName" class="ee-grow" value="{$val('locationName')}" placeholder="Confederation Park">
        <input type="text" id="eeLocAddress" class="ee-grow" value="{$val('locationAddress')}" placeholder="Address">
      </div>
    </div>

    <div>
      <label class="ee-lab" for="eeDesc">Description</label>
      <textarea id="eeDesc" placeholder="Anything worth knowing about this event.">{$desc}</textarea>
    </div>

    <button type="button" class="ee-more" id="eeOptionsToggle"
            aria-expanded="false" aria-controls="eeOptions">More options</button>

    <div class="ee-panel" id="eeOptions" hidden>
      <div class="ee-row">
        <label class="ee-lab" for="eeType">Type</label>
        <div class="ee-ctl">
          <select id="eeType" class="ee-grow">{$typeOptions}</select>
        </div>
        <p class="ee-hint" id="eeTypeHint" style="grid-column:2/-1" aria-live="polite"></p>
      </div>
      <div class="ee-row">
        <label class="ee-lab" for="eeTags">Tags</label>
        <div class="ee-ctl">
          <input type="text" id="eeTags" class="ee-grow" value="{$tagValue}"
                 list="eeTagList" autocomplete="off" placeholder="Christmas, family, music">
          <datalist id="eeTagList">{$tagOptions}</datalist>
        </div>
        <p class="ee-hint" style="grid-column:2/-1">Separate with commas. A type says what
          kind of event it is and who may see it; tags are labels for finding things later,
          and an event can carry several.</p>
      </div>
      <div class="ee-row">
        <span class="ee-lab" id="eeAssignLab">Assignments</span>
        <div class="ee-ctl">
          <label class="ee-allday" for="eeAssignmentScheduling">
            <input type="checkbox" id="eeAssignmentScheduling"
                   {$assignChecked}>
            Allow ministry assignments for this event
          </label>
        </div>
        <p class="ee-hint" style="grid-column:2/-1">Calendar events stay on the calendar either way.
          Tick this when this campus should open the serving grid on this event (usually Sunday Service).
          The serving grid already lists every activity that happens in the dates you loaded; this tick only nominates a default.</p>
      </div>
    </div>
  </div>

  <div class="ee-foot">
    {$cancelBtn}
    <button type="submit" class="pc-btn pc-btn--primary pc-btn--md" id="eeSave"><span>{$submit}</span></button>
  </div>
</form>
HTML;
    }
}

if (!function_exists('ee_script')) {
    /**
     * Behaviour for the editor above.
     *
     * Exposes window.EventEditor.init(), which returns { collect, validate,
     * setBusy, focus }. Saving is deliberately left to the caller: creating
     * POSTs, editing PATCHes, and the calendar closes a popover afterwards.
     * The form itself has no opinion about where the event goes.
     */
    function ee_script(): string
    {
        return <<<'JS'
<script>
(function () {
    'use strict';

    const DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    function id(x) { return document.getElementById(x); }
    function show(el, on) { if (el) el.hidden = !on; }

    // A 24-hour value as people say it. Mirrors RecurrenceRule::describeTime on
    // the server, which owns the same sentence for stored events; this copy
    // exists because the preview has to update while you type, before anything
    // has been saved to describe.
    function clock(v) {
        if (!v) return null;
        const [h, m] = v.split(':').map(Number);
        if (Number.isNaN(h) || Number.isNaN(m)) return null;
        const mer = h < 12 ? 'AM' : 'PM';
        const hr = h % 12 === 0 ? 12 : h % 12;
        return hr + ':' + String(m).padStart(2, '0') + ' ' + mer;
    }

    function timeRange(start, end, allDay) {
        if (allDay) return 'All day';
        const s = clock(start);
        if (!s) return null;
        const e = clock(end);
        if (!e || e === s) return s;
        return (s.slice(-2) === e.slice(-2) ? s.slice(0, -3) : s) + ' – ' + e;
    }

    window.EventEditor = {
        init: function (opts) {
            opts = opts || {};
            const form = id('eeForm');
            if (!form) return null;

            const title = id('eeTitle'), date = id('eeDate'), start = id('eeStart'),
                  end = id('eeEnd'), allDay = id('eeAllDay'), repeat = id('eeRepeat'),
                  until = id('eeUntil'), says = id('eeSays'), type = id('eeType');
            // The event page omits the schedule rows: editing an event changes
            // its properties, and its dates are managed as a schedule below.
            const hasWhen = !!(date && repeat && allDay);
            let dates = (opts.selectedDates || []).slice();

            // ---- selected dates ------------------------------------------
            function renderDates() {
                const list = id('eeDateList');
                if (!list) return;
                list.innerHTML = dates.map(function (d, i) {
                    const label = new Date(d + 'T00:00').toLocaleDateString(undefined,
                        { weekday: 'short', day: 'numeric', month: 'short' });
                    return '<span class="ee-date" role="listitem">' + label +
                        '<button type="button" data-i="' + i + '" aria-label="Remove ' + label + '">×</button></span>';
                }).join('');
            }
            id('eeAddDate')?.addEventListener('click', function () {
                const v = id('eePickDate').value;
                if (v && dates.indexOf(v) === -1) { dates.push(v); dates.sort(); renderDates(); }
                id('eePickDate').value = '';
                say();
            });
            id('eeDateList')?.addEventListener('click', function (e) {
                const b = e.target.closest('button[data-i]');
                if (!b) return;
                dates.splice(Number(b.dataset.i), 1);
                renderDates();
                say();
            });

            // ---- campus chips --------------------------------------------
            // "All campuses" and a named campus are mutually exclusive: an
            // event cannot be for everyone and for North York in particular.
            const chips = Array.from(document.querySelectorAll('#eeCampuses .ee-chip'));
            function campusIds() {
                return chips.filter(function (c) {
                    return c.dataset.campus !== '0' && c.getAttribute('aria-pressed') === 'true';
                }).map(function (c) { return Number(c.dataset.campus); });
            }
            function syncChips() {
                const any = campusIds().length > 0;
                const all = chips.find(function (c) { return c.dataset.campus === '0'; });
                if (all) all.setAttribute('aria-pressed', any ? 'false' : 'true');
            }
            chips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    if (chip.dataset.campus === '0') {
                        chips.forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
                    } else {
                        chip.setAttribute('aria-pressed',
                            chip.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
                        syncChips();
                    }
                    say();
                });
            });

            // ---- progressive disclosure -----------------------------------
            function disclose(btnId, panelId) {
                const btn = id(btnId), panel = id(panelId);
                btn?.addEventListener('click', function () {
                    const open = panel.hidden;
                    panel.hidden = !open;
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    if (open) panel.querySelector('input,select,textarea')?.focus();
                });
            }
            disclose('eeDetailsToggle', 'eeDetails');
            disclose('eeOptionsToggle', 'eeOptions');
            if (opts.detailsOpen || form.dataset.optionsOpen === '1') id('eeDetailsToggle')?.click();
            if (form.dataset.optionsOpen === '1') id('eeOptionsToggle')?.click();

            id('eeWhere')?.addEventListener('change', function () {
                show(id('eeElsewhereRow'), this.value === 'other');
                if (this.value === 'other') id('eeLocName')?.focus();
            });

            // ---- what the schedule currently says -------------------------
            function say() {
                if (!says || !hasWhen) return;
                const p = repeat.value;
                const when = timeRange(start.value, end.value, allDay.checked);
                let head;
                if (p === 'selected') {
                    head = dates.length === 0 ? 'No dates chosen yet'
                        : dates.length + ' date' + (dates.length === 1 ? '' : 's') + ' chosen';
                } else if (!date.value) {
                    head = 'Pick a date';
                } else {
                    const d = new Date(date.value + 'T00:00');
                    const day = DAYS[d.getDay()];
                    head = p === 'weekly' ? 'Every ' + day
                         : p === 'biweekly' ? 'Every other ' + day
                         : p === 'monthly' ? 'Every month on the ' + d.getDate() + ordinal(d.getDate())
                         // Mirrors RecurrenceRule::weekOfMonthFor: a date in the
                         // final seven days of its month is the last of that
                         // weekday, not merely the fourth.
                         : p === 'monthly_nth' ? nthLabel(d) + ' ' + day + ' of every month'
                         : d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
                }
                const bits = [head];
                if (when) bits.push(when);
                if (p !== 'one_off' && p !== 'selected') {
                    bits.push(until.value
                        ? 'until ' + new Date(until.value + 'T00:00').toLocaleDateString(undefined,
                            { day: 'numeric', month: 'long' })
                        : 'for a year');
                }
                const names = chips.filter(function (c) {
                    return c.getAttribute('aria-pressed') === 'true';
                }).map(function (c) { return c.textContent.replace(/^✓\s*/, ''); });
                if (names.length) bits.push(names.join(' and '));
                says.textContent = bits.join(' · ');
            }
            function nthLabel(d) {
                const dom = d.getDate();
                const lastOfMonth = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
                if (dom + 7 > lastOfMonth) return 'Last';
                return ['First', 'Second', 'Third', 'Fourth'][Math.ceil(dom / 7) - 1] || 'First';
            }
            function ordinal(n) {
                if (n > 3 && n < 21) return 'th';
                return ['th','st','nd','rd'][n % 10] || 'th';
            }

            function syncRepeat() {
                if (!hasWhen) return;
                const p = repeat.value;
                show(id('eeUntilRow'), p !== 'one_off' && p !== 'selected');
                show(id('eeDatesRow'), p === 'selected');
                // A list of chosen dates has its own dates; a single date row
                // would be a second, contradictory answer to the same question.
                show(date.closest('.ee-when'), true);
                date.disabled = p === 'selected';
                say();
            }
            repeat?.addEventListener('change', syncRepeat);

            allDay?.addEventListener('change', function () {
                start.disabled = end.disabled = this.checked;
                if (this.checked) { start.value = ''; end.value = ''; }
                say();
            });

            form.addEventListener('input', say);
            form.addEventListener('change', say);

            // ---- validation ------------------------------------------------
            function setError(input, errEl, message) {
                if (!errEl) return;
                errEl.textContent = message || '';
                errEl.hidden = !message;
                if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
            }

            function validate() {
                const problems = [];
                setError(title, id('eeTitleErr'), '');
                setError(date, id('eeWhenErr'), '');
                setError(end, id('eeEndErr'), '');

                if (!title.value.trim()) {
                    setError(title, id('eeTitleErr'), 'Give the event a name.');
                    problems.push({ id: 'eeTitle', text: 'Give the event a name.' });
                }
                if (!hasWhen) {
                    // Nothing else to check: this editor is not saving a schedule.
                } else if (repeat.value === 'selected') {
                    if (dates.length === 0) {
                        setError(date, id('eeWhenErr'), 'Add at least one date.');
                        problems.push({ id: 'eePickDate', text: 'Add at least one date.' });
                    }
                } else if (!date.value) {
                    setError(date, id('eeWhenErr'), 'Pick a date.');
                    problems.push({ id: 'eeDate', text: 'Pick a date.' });
                }
                // An end before the start is legitimate — it runs past midnight
                // — but an end on the same clock as the start is a typo, and
                // saving it silently produces a zero-length event.
                if (hasWhen && !allDay.checked && start.value && end.value && start.value === end.value) {
                    setError(end, id('eeEndErr'), 'The end time is the same as the start time.');
                    problems.push({ id: 'eeEnd', text: 'The end time is the same as the start time.' });
                }

                const summary = id('eeSummary'), list = id('eeSummaryList');
                if (problems.length && summary && list) {
                    list.innerHTML = problems.map(function (p) {
                        return '<li><a href="#' + p.id + '">' + p.text + '</a></li>';
                    }).join('');
                    summary.hidden = false;
                    summary.focus();
                } else if (summary) {
                    summary.hidden = true;
                }
                if (problems.length) {
                    // Focus the field, not only the summary, so a keyboard user
                    // lands where the correction has to be typed.
                    setTimeout(function () { id(problems[0].id)?.focus(); }, 0);
                }
                return problems.length === 0;
            }

            id('eeSummaryList')?.addEventListener('click', function (e) {
                const a = e.target.closest('a[href^="#"]');
                if (!a) return;
                e.preventDefault();
                id(a.getAttribute('href').slice(1))?.focus();
            });

            function collect() {
                const where = id('eeWhere');
                const elsewhere = where && where.value === 'other';
                const body = {
                    title: title.value.trim(),
                    description: id('eeDesc')?.value.trim() || null,
                    ministryId: id('eeMinistry')?.value || null,
                    eventTypeId: type ? (type.value || null) : null,
                    tags: (id('eeTags')?.value || '').split(',')
                        .map(function (t) { return t.trim(); })
                        .filter(function (t) { return t !== ''; }),
                    campusIds: campusIds(),
                    hostCampusId: id('eeHost')?.value || null,
                    locationName: elsewhere ? (id('eeLocName')?.value.trim() || null) : null,
                    locationAddress: elsewhere ? (id('eeLocAddress')?.value.trim() || null) : null,
                    usesServingSchedule: !!(id('eeAssignmentScheduling') && id('eeAssignmentScheduling').checked),
                };
                if (hasWhen) {
                    body.pattern = repeat.value;
                    body.startDate = repeat.value === 'selected' ? null : (date.value || null);
                    body.untilOn = until.value || null;
                    body.selectedDates = dates;
                    body.allDay = allDay.checked;
                    body.startTime = allDay.checked ? null : (start.value || null);
                    body.endTime = allDay.checked ? null : (end.value || null);
                }
                return body;
            }

            const save = id('eeSave');
            function setBusy(on, label) {
                if (!save) return;
                save.disabled = on;
                const span = save.querySelector('span');
                if (span && label) span.textContent = label;
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!validate()) return;
                opts.onSave?.(collect(), { setBusy: setBusy, form: form });
            });

            // Ctrl/Cmd+Enter saves from anywhere in the form, including the
            // description, where Enter has to keep meaning "new line".
            form.addEventListener('keydown', function (e) {
                if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                    e.preventDefault();
                    form.requestSubmit();
                }
                if (e.key === 'Escape' && opts.onCancel) {
                    e.preventDefault();
                    opts.onCancel();
                }
            });
            id('eeCancel')?.addEventListener('click', function () { opts.onCancel?.(); });

            if (hasWhen && allDay.checked) { start.disabled = end.disabled = true; }
            renderDates();
            syncRepeat();
            syncChips();
            say();

            return {
                collect: collect,
                validate: validate,
                setBusy: setBusy,
                focus: function () { title.focus(); },
            };
        },
    };
})();
</script>
JS;
    }
}
