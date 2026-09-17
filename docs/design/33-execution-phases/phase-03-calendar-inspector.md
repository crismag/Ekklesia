---
id: phase-03
depends_on: [phase-01, phase-02]
status: shipped
principle: The calendar is the operations workspace; a day is a read model over existing sources.
tracks:
  - id: 3a-day-api
    agent: cursor-or-claude
    files:
      - app/Http/Controllers/Api/CalendarController.php
      - app/Services/CalendarService.php
      - routes/api.php
      - tests/Regression/calendar-view-model.php
    forbidden:
      - resources/views/calendar.php
      - resources/views/_portal-shell.php
  - id: 3b-inspector-ui
    agent: cursor
    depends_on_tracks: [3a-day-api]
    files:
      - resources/views/calendar.php
    forbidden:
      - app/Http/Controllers/Api/CalendarController.php
      - resources/views/_portal-shell.php
---

# Phase 3 — Calendar as operations workspace (read-only inspector)

> **Shipped.** 3a in #20, 3b in #23. `GET /api/calendar/day` composes the activity feed
> and the schedule board for one date; the calendar opens a read-only inspector from a
> date button. Roles join on occurrence id, and an unfilled role reads as "Unfilled".

## Outcome

Clicking a day (or an item) shows what is on that date: activities, serving assignments, roster slots, and empty roles — without opening the schedule editor. **Read-only.**

## User-visible before / after

**Before:** Day click / New event goes to `/events/new`. Assignments are only chips. Unfilled roles are invisible unless you already opened a ministry grid.

**After:** Selecting a date shows a panel or in-page inspector:

```text
Sunday 6 Sep
Morning Worship          [activity]
  Preacher     John
  Keyboard     — unfilled
Youth pickup             [posted list]
  Volunteer    Sarah
```

Create remains available (existing `/events/new?date=`).

## Contract freeze (3a must publish this)

Suggested `GET /api/calendar/day?date=YYYY-MM-DD` (campus from cookie/query like other calendar endpoints):

```json
{
  "date": "2026-09-06",
  "items": [
    {
      "kind": "event|assignment|roster|birth|holiday|…",
      "source": "events:…|assignments|rosters|…",
      "title": "",
      "href": "/events/123 or /schedules?…",
      "starts_at": "optional ISO",
      "roles": [{"name": "Keyboard", "person": null, "displayName": null}]
    }
  ]
}
```

Reuse [`CalendarController::sources`](../../../app/Http/Controllers/Api/CalendarController.php), [`CalendarService`](../../../app/Services/CalendarService.php), assignment/roster reads already used to build layers. Audience filters stay in SQL. Empty `roles` only when the item is a staffed occurrence with known ministry roles.

No new tables.

## Tracks

### 3a-day-api (Cursor or Claude)

Implement the endpoint + tests. Do **not** edit `calendar.php`. Document the JSON in this file if the shape drifts; UI waits on merge.

**Tests:** extend [`tests/Regression/calendar-view-model.php`](../../../tests/Regression/calendar-view-model.php) or add a focused PHP test with fakes. `source-contracts.mjs` if the route string must be pinned.

### 3b-inspector-ui (Cursor) — after 3a

[`calendar.php`](../../../resources/views/calendar.php) only.

- Fetch day payload; render inspector in the main column or a simple aside **inside** `#portal-main` (not the shell right rail yet — that is Phase 6).
- Month/week/day/agenda still work. Do not add drag.
- Keyboard: date selection must work without dragging (WCAG 2.5.7).
- **Exclusive lock on this file** — no Phase 5 screen-views until 3b merges.

## Out of scope

Assignment POST, `data-right` shell, saved views, print rewrite, drag-move.

## Handoff

```text
Track: 3a-day-api (do this first) or 3b-inspector-ui (only after 3a is on the branch).
Do not implement rooms, RSVP, or drag.
```
