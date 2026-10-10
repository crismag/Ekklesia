# Event creation and editing — findings before redesign

Written before implementation, as the brief asks. It records what the current
form actually does, what the database can actually represent, and which parts of
the proposed design are therefore buildable now and which need a schema change.

Measured on the dev instance, signed in as an admin.

## What the current form costs

| | Desktop 1440×900 | Phone 390×844 |
|---|---|---|
| Page height | 1759 px | 2346 px |
| Viewports to scroll | 1.95 | 2.78 |
| Fields visible on arrival | 17 | 18 |
| Permanent helper paragraphs | 12 | 12 |
| Section headings | 4 | 4 |

Seventeen controls are on screen before you have typed anything, and the primary
action is off the bottom of the screen on both. Four headings — *What is it?*,
*When?*, *What time?*, *Who is it for, and where?* — divide one act of
scheduling into four, and *When?* and *What time?* separate a date from its own
time.

## The finding that matters most: the recurrence rule is thrown away

`event_recurrence` exists with a complete schema:

```
event_id, recurrence_type enum('daily','weekly','biweekly','monthly','yearly'),
recurrence_interval, recurrence_days_of_week, recurrence_until,
recurrence_count, created_at, updated_at
```

It holds **2 rows**, against **400** rows in `event_occurrence`. Nothing in the
application writes it. `generateOccurrences()` expands a pattern into occurrence
rows and discards the pattern.

This answers the brief's question about why "Generate occurrences" is exposed to
ordinary administrators. Of the four possibilities offered:

- **Partly (1), required by the data architecture.** Occurrences genuinely must
  be materialised. The calendar, the agenda and the print system all read
  `event_occurrence`; there is no expander at read time.
- **Mostly (3), legacy UX.** The *manual* generator exists because the schedule
  is not stored anywhere. The application cannot say "every Sunday at 7:30
  until October 11" because it never kept that. It can only show the rows it
  produced, and offer to produce more.

So the fix is not to hide the generator. It is to **persist the rule**, then let
the UI say what the schedule *is* and regenerate from it internally:

```
Schedule
Every Sunday · 7:30 – 9:00 AM · until 11 October
Edit schedule
```

Until the rule is stored, any "Edit schedule" button would be lying about what
it knows.

## Cancelling an occurrence destroys it

`event_occurrence` carries `is_cancelled`, `is_modified`, `override_title` and
`override_desc`. **Zero rows have ever had `is_cancelled` set**, because
`cancelOccurrence()` issues a `DELETE`.

That matters beyond tidiness. A cancelled service is information the
congregation needs — *no service this Sunday* is not the same as a Sunday that
was never scheduled. The schema was built for this and the application ignores
it. Scenario 6 in the brief ("cancel one occurrence without deleting the event")
is currently satisfied only in the sense that the event survives; the fact of
the cancellation does not.

## Rooms and resources have no data behind them

The brief proposes a location list — *Sanctuary, Fellowship Hall, Prayer Room*.
ChurchCRM's `locations` table exists but is **empty**, and no adapter reads it.
`events_event.location_id` is set on **0** events; `custom_location_name` on 1.

Per the brief's own rule — *do not invent backend capabilities just to populate
the UI* — Location stays as what the schema supports:

```
Location   [ Same as campus ▾ ]
             Somewhere else…  → name + address
```

Seeding `locations` and wiring a room picker is a separate backend item, listed
below, not part of this redesign.

## Recurrence presets: what the model can and cannot say

Supported by `event_recurrence` as it stands:

- Doesn't repeat
- Every week
- Every 2 weeks
- Every month *(same date each month)*
- Selected dates
- Custom — interval + weekdays + ends never/on/after

**Not** representable: *First Sunday of every month*, *Last Sunday of every
month*, and the rest of that family. The enum has no week-of-month, and there is
no `BYSETPOS` equivalent. These are the church-specific presets the brief calls
"particularly valuable", and they need one added column
(`recurrence_week_of_month tinyint`) plus expander support.

The brief says to implement only what the model can correctly support, so the
week-of-month presets are held back to the schema item below rather than shipped
as options that silently do something else.

## Defaults are already available for free

`event_types` carries `type_defstarttime`, `type_defrecurtype` and
`type_defrecurDOW`, populated: *Church Service* defaults to 08:00 weekly,
*Sunday School* to 09:30 weekly. Choosing a type can prefill the time and the
repeat without inventing anything.

## Stack reality

`composer.json` and `package.json` declare Laravel, Inertia and React. None of
it is used: the portal is a front controller over plain PHP views with inline
CSS and JS per view. So "EventEditor component" means **one shared PHP partial
plus one shared JS module**, included by the calendar, `/events/new` and the
event page — not a framework component, and not a reason to activate the unused
React toolchain.

## What changes in UI only, and what needs the database

**UI only** — no schema change:

- Compact single-panel editor; title, date+time on one row, campus, ministry
- Progressive disclosure behind *Add details* and *More options*
- Recurrence as a dropdown rather than four cards
- Inline validation with `aria-describedby` and a focusable error summary
- Compact viewer → edit-in-place on the event page
- Quick-create from the calendar, prefilled from the clicked date
- Collapsing the occurrence list to *next few + View all*
- Type-driven default start time and repeat

**Needs the database** — listed separately, as the brief asks:

1. Write `event_recurrence` on create, so the schedule can be shown and edited
   as a rule rather than as its output. *(Table exists; nothing to migrate.)*
2. Honour `is_cancelled` instead of deleting, so a cancelled date can be shown
   as cancelled. *(Column exists; nothing to migrate.)*
3. `recurrence_week_of_month` for "First Sunday of every month". *(Migration.)*
4. Seed and read `locations` for a room picker. *(Data + adapter.)*

Items 1 and 2 need no migration at all — the columns are already there and
unused, which is the clearest sign that this was always the intended design.

---

# What was built, and what it measures

## Before and after

| | Before | After |
|---|---|---|
| `/events/new` height (1440×900) | 1759 px | **900 px** |
| Viewports to scroll (desktop) | 1.95 | **1.00** |
| `/events/new` height (390×844) | 2346 px | **987 px** |
| Viewports to scroll (phone) | 2.78 | **1.17** |
| Fields visible on arrival | 17 | **7** |
| Permanent helper paragraphs | 12 | **1** |
| Section headings | 4 | **0** |
| Primary action reachable without scrolling | no | **yes, both viewports** |
| Destructive buttons visible on an event page | 15 | **1** |
| Occurrence rows on screen by default | 14 | **0** (next three, then *View all*) |

Clicks for the common case are unchanged where they were already minimal —
optimising those was never the problem. What changed is how much has to be read
and understood before the first one.

## Phase A — one editor

`resources/views/_event-editor.php` holds the markup, styles and behaviour;
`/events/new`, the event page and the calendar all use it. `ee_html()` takes an
`omit` list so a caller can drop rows its save path cannot write — the event
page omits the schedule rows, because PATCH changes an event's properties and
its dates belong to the occurrences.

`app/Services/Events/RecurrenceRule.php` is the translation between a preset, a
stored rule and a sentence, with no database handle so it can be tested alone.

## Phase B — the compact editor

Title, one WHEN row combining date and both times, Repeats, Campus, Ministry,
then *Add details* and *More options*. Campus is chips carrying `aria-pressed`
rather than checkbox cards; "All campuses" is a chip of its own rather than the
meaning of an empty selection. Validation is inline with `aria-describedby`,
plus a focusable `role="alert"` summary linking to each invalid field.

Choosing a type fills in that type's default start time and repeat, from
`type_defstarttime` and `type_defrecurtype` — present in ChurchCRM since the
schema was created and never read until now.

## Phase C — the event page

Viewer first, editor on request, the same editor. The occurrence dump became a
schedule: the rule in words, the next three dates, then *View all N dates*.
*Generate occurrences* moved into a folded *Advanced schedule tools* section —
it stays, because an irregular church schedule sometimes needs it, but it is no
longer the way an event appears to be scheduled.

Ministry became editable. The create path wrote `ministry_id` and nothing could
change it, so an event filed under the wrong ministry stayed there.

## Phase D — calendar-native creation

Clicking empty space in a month cell opens the editor with that date. Clicking
an hour band in week or day view fills in the time as well. Both carry the
campus you were filtered to and a `return` so Cancel and Save come back to the
month and campus you were looking at. A toolbar *New event* button does the same
from the keyboard in one tab stop rather than forty-two.

## The schedule an event created before this has no rule

`RecurrenceRule::observe()` reads the cadence off the dates when nothing was
stored. It reports only an even spacing of one, seven or fourteen days across at
least three distinct dates, and refuses everything else. "Does not repeat" above
a list of fifty-two Fridays would have contradicted the page directly below it.

## Deliberately not built

- **The preview sidebar.** The brief asked for it to be evaluated rather than
  assumed. For a five-field common case it would restate what is already on
  screen a few centimetres to the left, and it would cost the single-panel
  shape that gets the whole editor into one viewport.
- **Week-of-month presets** (*First Sunday of every month*). `recurrence_type`
  cannot express them; offering them would mean quietly doing something else.
- **A room picker.** `locations` is empty and unread.
- **Drag-to-create.** Click-with-time already covers the case, and drag would be
  a second, mouse-only way to do it.
- **Natural-language quick add and templates.** Named as future work in the
  brief, and both want a conventional editor underneath first.

---

# Follow-up: the two schema items, done

## Cancelling a date now records the cancellation

`cancelOccurrence()` used to issue a `DELETE`. That erased the very thing a
congregation needs to know — a Sunday with no service is not the same as a
Sunday that was never scheduled, and somebody turns up to find out which. The
column for it had existed since the schema was created and was never once set.

The two acts are now separate:

| | means | destroys |
|---|---|---|
| **Cancel** — `POST /api/occurrences/{id}/cancel` | the meeting is off, the date stays | nothing |
| **Restore** — `POST /api/occurrences/{id}/restore` | it is back on | nothing |
| **Delete** — `DELETE /api/occurrences/{id}` | the date should not exist | the row |

Cancelling is not gated on assignments the way deleting is: it destroys nothing,
so the roster survives if the date is restored.

The calendar feed no longer filters cancelled rows out. It returns them with the
title prefixed `Cancelled — ` and a `cancelled` flag; the calendar strikes the
title through and dims the chip. The word is in the text, not only in the
styling — decoration alone is invisible to a screen reader and can be lost in
print. Nothing was ever cancelled under the old code, so this surfaces no
history; it makes the action mean something.

## First Sunday of every month

Migration `010-recurrence-week-of-month.sql` adds
`recurrence_week_of_month TINYINT NULL`. 1–4 select the nth of that weekday,
`-1` the last, and `NULL` keeps a monthly rule meaning what it always meant —
the same date each month — so every row already stored behaves exactly as it
did.

The editor now offers both, worded so they cannot be confused:

```
Every month, on the same date
Every month, on the same weekday
```

The position comes from the date you picked rather than a second control. The
6th of September 2026 is the first Sunday; the 27th is the last, and *last* wins
over *fourth* because somebody who picked a date in the final week of the month
meant the last one — and that keeps meaning it in months with only four.

Expansion walks months rather than adding days, since the date moves. A month
with no fifth of that weekday is skipped rather than spilled into the next.

Verified end to end: `monthly_nth` from 2026-09-06 produces
`2026-09-06, 10-04, 11-01, 12-06, 2027-01-03`, stores
`recurrence_week_of_month: 1`, and reads back as *"First Sunday of every month ·
10:00 AM – 12:00 PM · 5 times"*. The live preview in the editor says the same
sentence before anything is saved.

---

# Follow-up: the rest of the list

## Changing a schedule, not just its times

Retiming moves a series to a different clock time and keeps its dates.
`PUT /api/events/{id}/schedule` is the other half — weekly to fortnightly, a
different weekday, a new end date — which changes *which dates exist*.

Dates already past are never touched. They record what actually happened, and
rewriting them would falsify the church's own history, so the new rule applies
from today forward and the replacement cannot simply wipe and regenerate. A rule
producing no dates from today onward is refused rather than emptying the
calendar, and dates carrying assignments need confirmation, the same guard bulk
delete applies.

The panel sits beside the schedule sentence as *Edit schedule*, and previews the
new rule in the same words before anything is saved.

## This occurrence / this and following / entire series

Changing one date of a repeating event is ambiguous, so the choice is asked
rather than assumed — guessing is how a whole year gets moved by accident. Only
the three the model can carry out are offered, in a real `<dialog>`: it traps
focus, Escape dismisses it, and the three read side by side rather than as a
sequence of `confirm()` boxes.

`following` needs a date to start from and is refused without one, rather than
quietly meaning *all*.

## `/events` was 24.7 screens tall

Two problems, one of them older than this work.

`.ev-day` is `display:grid`, which outranks the UA's `[hidden]{display:none}` —
so **the search filter had never actually hidden a day**. Typing in the box
changed the count and nothing else. One line of CSS fixes it.

And the page rendered every day in the range: 145 of them. It now draws the
first 30 with *Show N more days*, keeping the rest in the document so searching
still reaches them and Ctrl+F still works.

**22 268 px → 6 410 px** at 1440×900; 24.7 viewports to 7.1.

## Target sizes

Month-view chips were 18–21 px tall, under the 24 px WCAG 2.5.8 asks of a
target. The spacing exception does not apply: chips stack 2 px apart, so a 24 px
circle around one reaches its neighbour. Centring the text in 24 px costs a few
pixels of cell height and still fits three chips per day.

Week view on a phone was worse: seven columns across 390 px left each event
block about **18 px wide** — unreadable as well as untappable. The grid now
keeps a usable column width and scrolls sideways inside its own frame. The page
itself still never scrolls horizontally.

Every interactive target across nine pages at both viewports now meets 24 px.

## Still open

- `/rosters` has two sub-24 px targets. Pre-existing and outside this work.
- Per-occurrence `override_title` / `override_desc` are stored and read by the
  calendar but cannot be set from the portal, so "rename just this week's
  service" is still not expressible.
- Seeding and reading `locations` for a room picker.
- Templates and natural-language quick add, both named as future work in the
  brief.
