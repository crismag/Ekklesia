# Calendar platform — what exists, what is missing, what to build

Written before changing anything, against the running application and its
schema.

## The headline

**Most of Phase 1 is already built.** The calendar already aggregates several
sources, already has four views, already filters by campus and by source, and
already models recurrence properly. The genuine gap is the one the brief calls
out as first-class: **print**, and the view-model split that makes good print
possible.

So the sequence in the brief is right in spirit but wrong in order for this
codebase. Rebuilding the month grid would be work with nothing to show for it.

## What already exists

### Recurrence is a real model, not duplicated rows

```
event_recurrence   2 rows   type, interval, days_of_week, until, count
event_occurrence 400 rows   per occurrence: is_cancelled, is_modified,
                            override_title, override_desc
events_event      13 rows
```

Thirteen events expand into four hundred occurrences, and a single occurrence
can be cancelled or retitled without touching its siblings. This is exactly what
§29 asks for, and it is already here. Nothing about recurrence needs building —
it needs *exposing*.

### Assignments already carry who does what

```
assignment  48 rows  occurrence_id, role_id, person_id, assignee_name,
                     status, notes
```

Template D — the Sunday preparation schedule with names filled in — is
achievable from real data today. That was the least certain item in the brief
and it turns out to be the best supported.

### Sources already aggregate

`/api/calendar/sources` returns one flat, typed feed — events, birthdays,
anniversaries, ministry schedules, rosters and (as of this week) holidays.
`/api/calendar/layers` returns the chips that filter it. Both are
audience-filtered in SQL, not in the browser.

The item contract is already close to what §3 asks for:

```
kind, source, source_label, title, meta, href, date, starts_at
```

### Categories, campuses and locations exist

`event_types` carries `portal_label`, `portal_color`, `portal_audience`,
`portal_sort`, `portal_is_default` — §11's *category* concept, with colour and
visibility. `events_event_campus` gives multi-campus. `locations` holds name and
address, so §7's rooms are half-present: they exist as records but nothing
schedules them.

### Four views already render

Month, Week, Day and Agenda, hand-written in `resources/views/calendar.php`.
There is **no FullCalendar and no calendar library**. React and Vite are in
`package.json` and unused, and are to stay that way.

## What is actually missing

### 1. The view model lives in the browser

This is the important one. `buildItems()` in `calendar.php` takes the raw feed
and produces what gets drawn — grouping, filtering, ordering, overflow. It runs
in JavaScript, in the page.

Which means print can only ever be CSS over that same DOM:

```
Calendar HTML  →  @media print  →  printable calendar
```

That is the arrangement §24 names as the wrong one, and the codebase currently
has exactly two `@media print` blocks doing it.

**Nothing else in the brief can be built well until this moves.** A print
template cannot lay out a month grid that only exists as browser DOM, and a PDF
renderer cannot reach it at all.

### 2. There is no print composer

`/printables/{birthdays,events,schedules}` exist and are fixed pages. There is
no range picker, no template choice, no branding, no preview.

### 3. Rooms are records, not resources

`locations` has rows; nothing books them, so there is no conflict to detect.
§7 is a schema gap, and a small one.

### 4. No tags

§11 separates calendar / category / tag. The first two exist. Tags do not, and
would need a table.

## What needs a migration, and what does not

| Capability | Migration? |
|---|---|
| Shared view model, print templates, composer, branding | **No** |
| Agenda, Monthly, Ministry Planner, Sunday Schedule, Annual templates | **No** |
| Category colours, campus and ministry filters, source manager | **No** |
| Display defaults and user preferences | **No** — a config file and a cookie |
| Room/resource booking, conflict detection | Yes, one table |
| Tags | Yes, one table |
| Approvals | Yes |

Most of the brief needs no schema change at all, because the schema is already
better than the screen using it.

## Proposed sequence

**1. Move the view model to the server.** One `CalendarViewModel` that takes a
range and filters and returns grouped, ordered, decorated entries. The
interactive page keeps its own rendering; print gets the same data. This is the
keystone and everything else waits on it.

**2. Print composer and template renderer.** A real workflow — range, campus,
sources, template, branding, preview — rendering server-side HTML built for
paper, not the screen page with print CSS bolted on.

**3. Templates, starting with two.** Classic Monthly and Agenda. Ministry
Planner and Sunday Schedule next, because the assignment data supports them.
Annual after.

**4. Admin → Calendar as a configuration centre**, in the card layout of §30,
replacing today's placeholders.

**5. Rooms, conflicts, tags** — the parts that need migrations, once the rest is
earning its keep.

## What this deliberately will not do

- No calendar library. The existing views work and a library would rewrite them
  to no visible benefit.
- No React. It is present and unused and stays that way.
- No approval workflow until somebody asks for one.
- No speculative schema. Rooms and tags get tables when they get features.

---

# Follow-up: three print defects, and a setup page that fought the reader

## A birthday on the calendar and missing from the printout

Reported as "printing birthday only for Scarborough shows only a few people
with one end-of-month celebrant missing". Reproduced exactly: the calendar
showed 21 August birthdays including the 31st; printing 1–31 August showed 20
and dropped the 31st.

Three sources, three different opinions about whether the end of a range is
inside it:

- **Events** — SQL `occurrence_start < :end_at`, an exclusive instant at
  midnight. A window ending on the 30th lost *every service on the 30th*, not
  just birthdays. Measured: five services on 30 August, zero returned.
- **Birthdays and anniversaries** — compared against
  `createFromFormat('Y-m-d', …)` **without `!`**, which fills the clock from
  *now*. So a birthday on the last day parsed as that afternoon, compared as
  later than a window ending at midnight, and vanished — at any hour except
  exactly 00:00:00.
- **Holidays** — read from a file, inclusive, and right all along.

The window is now normalised once, in the adapter, before any source reads it:
start pinned to midnight, end moved to the following midnight. Every source
sees the same range and the last day is whole.

While fixing the parse: `createFromFormat` also rolls **29 February** silently
to 1 March, so a leap-day birthday would have appeared in the wrong month with
nothing to show it. Recurring dates are now placed by a single helper that
clamps to the last day the month actually has, keeping a February birthday in
February. No such records exist today; the behaviour was decided rather than
left to chance.

## A wall calendar that printed on two sheets

Found while checking the first fix. One month, Letter landscape, measured
**12.24 inches on an 8.5 inch page** — two sheets for a document whose entire
purpose is one grid on a noticeboard.

Two causes:

- `.cal td { height: 1.02in }` is a *minimum* for a table cell; cells grow with
  their contents, and entries wrapped to two lines. The constrained box is now
  a `div` inside the cell, which can be held to a height.
- `$perDay = 5` was a fixed guess. It is now derived from the paper size and the
  number of week rows, so a six-row August shows fewer per day than a five-row
  September, and the constants come from measuring a rendered cell rather than
  estimating one.

The `+n more` line is reserved in advance, because it appears exactly when the
day is full — the first attempt forgot it and clipped seven cells, silently
losing entries its own count never included.

`PrintComposer` now resolves paper and orientation **before** rendering the
template. It did so afterwards, so a template that needs to lay content out to
fit the page had nothing to fit to.

Verified: August, February, September and December, Letter landscape, Letter
portrait and A4 — **one page each, no cell clipping its contents**.

## The setup page

| | before | after |
|---|---|---|
| Sidebar height | 1344 px | 1027 px |
| Page height | 1.71 viewports | 1.35 |
| Preview | landscape sheet cut off at the right | whole page, centred, scaled to fit |

The range became a segmented row (five stacked radios at 40 px each cost 200 px
for five short words). Sources became wrapping chips — the old list clipped
mid-item behind its own scrollbar, so the layer you wanted was often the one you
could not see. Layouts became compact tiles with only the *chosen* one's
description shown, instead of five blurbs stacked.

The preview renders the sheet at its true paper size and scales the whole frame
to fit, in **both** directions. It previously let the print stylesheet lay out
into whatever width the iframe had, so an eleven-inch landscape calendar was
simply cut off. A single page now always fits; several pages scroll vertically
only. Horizontal scrolling is gone, which was the complaint.

An unticked source chip hollows its colour dot rather than relying on the fill
alone, so the state is not carried by colour.
