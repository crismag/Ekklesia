---
id: phase-07
depends_on: [phase-05, phase-06]
status: 7a_7b_shipped
principle: Differentiate on print and a unified week, not on cloning PCO communication or RSVP.
tracks:
  - id: 7a-publish-from-view
    agent: cursor
    status: shipped
    files:
      - resources/views/calendar.php
      - resources/views/calendar-print.php
    forbidden:
      - events_rsvp/
      - people_signup/
      - app/Services/Calendar/PrintComposer.php
  - id: 7b-unfilled-hint
    agent: cursor
    status: shipped
    files:
      - resources/views/calendar.php
      - resources/views/schedule-editor.php
    forbidden:
      - events_rsvp/
  - id: 7c-later-integrations
    agent: none
    files: []
    note: Brief only until a named row is unblocked. See phase-07c-later-integrations.md.
---

# Phase 7 — Differentiating capabilities

## Outcome

Publishing a month (print/PDF) starts from the **same view** the operator is looking at. Unfilled roles are visible without PCO-style email (optional later). RSVP and guest signup stay standalone until a dedicated integration brief.

## User-visible before / after

**Before:** Print is `/calendar/print-setup` and `/printables/*`. Calendar view state is not the print state.

**After:** Calendar has “Print this view” that opens print-setup with current layers/campus/date mode (saved view or query string already supported by print). Schedule editor / inspector can show a count of unfilled roles for the visible range.

## Tracks

### 7a-publish-from-view (Cursor) — **shipped in #27**

Calendar **Print this view** opens print-setup with the layers and dates on screen.
Printables points wall calendars at that same door. **Do not rewrite** `PrintComposer`
or themes. Do not merge the composers.

### 7b-unfilled-hint (Cursor) — **shipped in #27**

Landed early, alongside Phase 5. The schedule editor shows a count of empty role
cells for the loaded window, and the day inspector marks unfilled roles. Display
only, as specified: no email and no needed-positions schema.

Count empty role cells from data the grid/day API already returns. Display only. No email, no “needed positions” schema.

If 7a still owns `calendar.php`, put the hint only on [`schedule-editor.php`](../../../resources/views/schedule-editor.php) first.

### 7c-later-integrations (planning — do not implement here)

The inventory, blockers, and future packets live in [phase-07c-later-integrations.md](phase-07c-later-integrations.md). **Nothing in that table is ready to code.** Inspector + unfilled exist; that was a dependency for serving-request email, not a product yes. Rooms, RSVP fold, templates, NL-add, photos/notes, links, and drag still need church, ops, or a named unblock.

Requires a new brief and often 32-incomplete blockers:

| Item | Status |
|---|---|
| RSVP as optional flag on public activities | incomplete I-rsvp-split; fold `events_rsvp` later |
| Serving request email / accept | unfilled count shipped; mail and request/accept still deferred |
| Event templates | deferred D-templates |
| Natural-language add | deferred D-nl-add |
| Rooms/resources | blocked B-rooms |
| Profile photo / personal notes | planned; storage decision |
| External links manager | planned P-links; needs product yes; do not start P-refdocs |
| Drag-to-move | incomplete I-drag; Phase 6 shipped; still needs write API + WCAG 2.5.7 |

Phase 1 copy (`I-people-two`, `I-min-admin-two`) closed in #35/#36. Unused `docs/leader.php` (and the sibling member/shortcuts PHP stubs) are gone; `/docs/leader` still aliases to the serving-grid guide. If the next merge should be code, pick a remaining incomplete split — not 7c.

## Out of scope for this phase’s code tracks

New mail infrastructure, ChurchCRM `locations` booking, relationship graph, React/FullCalendar, cloning PCO matrix.

## Handoff

```text
Track: 7a-publish-from-view or 7b-unfilled-hint (shipped).
For later integrations: read phase-07c-later-integrations.md.
Read 32-incomplete-capabilities.md §4–5 before adding features.
Do not implement 7c.
```
