# Calendar & Calendar Settings — Analysis and Improvements

Compared against the conventions of Google Calendar, Outlook, Fantastical,
Teamup and Planning Center. The `ui-ux-pro-max` database has no calendar-specific
patterns, so the comparative findings below are domain knowledge, not database
matches; the one rule it did return that applies — *Chip Collection Reflow*
(filter chips must wrap or offer an operable `+n` disclosure rather than clip) —
is what the `+N more` fix implements.

## What was wrong

Each finding was measured, not assumed.

### 1. Week and Day were not calendar views

They rendered the same chip list as Month, grouped differently — no time axis
anywhere in the product. Day view was **141px tall for a single item**. A list
cannot answer the question a scheduling product exists to answer: *when is this,
and what else is happening at the same time?* Every comparator renders week and
day as an hour grid for exactly that reason.

### 2. The URL described a different screen than the one you were looking at

`?view=` was read on load but never written. Switching to Week and paging
forward left the address bar saying `?view=month`. A shared or bookmarked link
reopened the wrong view, and Back did not step through the calendar at all.

### 3. No keyboard navigation

No arrow-key paging, no "today", no view switching. Every comparator ships this
set; for a leader checking a month, it is the difference between one keystroke
and a mouse trip to the toolbar.

### 4. `+N more` was a dead end

A plain `<div>`: not clickable, not focusable, no way to reach the events it was
counting. Month cells cap at three items, so anything beyond that was
unreachable from the month view.

### 5. Every agenda row said "All day"

`renderAgenda` read `item.startsAt`, a field `buildItems` never sets. `fmtClock`
received `undefined` and fell through to "All day" for **30 of 30 rows**. The
month and week renderers used the correct field, so the bug was confined to the
agenda — and the new table gave it a dedicated column, which is how it surfaced.

### 6. Durations were being thrown away

`ChurchCrmCalendarAdapter` has always returned `ends_at`. The frontend dropped
it on the floor, keeping only a formatted string in `meta`. So the data needed
to draw a real time grid was already there, unused — **no API change required**.

### 7. The calendar ignored the theme

Grid borders (`#edf2ef`), out-of-month cells (`#fcfdfc`), headers (`#fbfdfc`)
and the today outline (`rgba(17,123,109,…)`) were hardcoded forest values, so
the calendar kept a green cast under all eight other presets.

## What changed

**A real time grid for Week and Day.** Hour rows sized from the data rather than
a fixed 0–23 — a church calendar clusters in the evening, and rendering midnight
to 6am would push the actual content off screen. Events are positioned by start
time with heights from the real `ends_at`: an 8:00–12:00 service now draws as a
four-hour block (174px = 4 × 44 − 2), and a 12:00–19:00 one as seven (306px).
Overlapping events are placed **side by side**, which is the entire point — a
double-booking should look like one. All-day items (birthdays, anniversaries)
sit in a separate band, because putting them on an hour line would be a lie.
A current-time indicator draws on today.

**The URL is now the record of what you are looking at.** `?view=` and `?date=`
are both read and written. `replaceState` on ordinary render, `pushState` on a
deliberate move, so Back walks the periods actually visited rather than every
re-render.

**Keyboard shortcuts:** `←`/`→` page the period, `T` returns to today, `M`/`W`/
`D`/`A` switch view. Suppressed while a field, select or the command palette has
focus, so typing "day" into a search box does not navigate the calendar —
verified by focusing a real control and confirming no navigation.

**`+N more` opens that day** in Day view, as the count implies.

**Agenda times are real.** Birthdays and anniversaries still read "All day";
everything else shows its clock time.

**Theme-correct.** Grid chrome now uses `--line`, `--paper`, `--bg`, `--soft`,
and the today highlight is a `color-mix` of `--teal` rather than a pinned rgba.
Event chips use the paired `--on-*` foregrounds.

## Settings page

- **"Saved in this browser only"** is now stated on the page. These preferences
  live in `localStorage`; without saying so, a user who sets them up on a laptop
  and opens the portal on a phone concludes the feature is broken.
- **A real colour control** sits beside the hex field, kept in step in both
  directions — including from the swatches, which previously set `.value`
  directly and fired no `input` event. A half-typed hex is never rewritten under
  the user; the picker only follows a complete value.
- **"Query" now explains itself** with an example, wired via `aria-describedby`.

## Verified

- Four views × three viewports (1440/768/390): **no horizontal overflow, no page
  errors**, no sub-24px targets. An all-day chip measured 20px and was given a
  24px floor.
- Durations checked against the source times; keyboard, URL and Back verified by
  driving the page.
- Suite: contracts 29/29, PHP 105/105, privacy 18/18, contrast 135 pairs × 9
  presets 0 failures; acceptance audit reports 0 across every metric.

## Not done, and why

- **Drag-to-create and drag-to-move.** The standard gesture in every comparator,
  but it needs write endpoints the calendar does not have, and WCAG 2.5.7
  requires a non-dragging alternative for each. A real feature, not a styling
  pass.
- **Server-side calendar preferences.** Source visibility and custom calendars
  are per-browser. Making them follow the account needs a table and an endpoint;
  the honest interim is the disclosure now on the page.
- **Overlap layout is column-splitting, not the packed layout** Google uses
  (where a narrow event can overlay a wider one). Column-splitting is
  predictable and adequate at this event density.
- **Month cell cap stays at three** items plus `+N more`, now that the overflow
  is reachable. Raising it would push month height past a screen.
