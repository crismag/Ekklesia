---
id: phase-04
depends_on: [phase-03]
status: shipped
principle: Staff a date through existing assignment APIs; do not invent a second editor.
tracks:
  - id: 4a-staff-from-inspector
    agent: cursor
    files:
      - resources/views/calendar.php
      - app/Http/Controllers/Api/ScheduleController.php
      - routes/api.php
    forbidden:
      - resources/views/schedule-editor.php
  - id: 4b-serving-copy
    agent: claude
    files:
      - resources/views/ministry-dashboard.php
      - resources/views/docs/sections/02-schedule-editor.md
      - resources/views/docs/sections/01-viewing-schedules.md
    forbidden:
      - resources/views/calendar.php
      - app/Http/Controllers/Api/ScheduleController.php
---

# Phase 4 — Scheduling workspace

> **Shipped.** 4a in #25, 4b in #24. Roles can be staffed from the inspector, and a
> ministry offers "Serving grid" and "Posted lists" rather than two names for one thing.

## Outcome

From the day inspector, a scheduler can fill or change a role using **existing** assignment save endpoints. Rosters remain a layer/tab (“posted list”), not a competing product named “Schedule.”

## User-visible before / after

**Before:** Staffing requires Ministries → dashboard → Schedule editor. Roster editor is a sibling with a similar name. Calendar cannot write assignments.

**After:** Inspector shows unfilled roles and a people picker for users with `manage_schedules` + ministry scope. Deep link still opens the full grid for batch weeks. Dashboard buttons say “Serving grid” / “Posted lists” (copy track).

## Tracks

### 4a-staff-from-inspector (Cursor)

Depends on Phase 3 JSON (`roles` on items).

- Writes go through existing [`ScheduleController`](../../../app/Http/Controllers/Api/ScheduleController.php) / assignment batch APIs. Do not add a parallel POST.
- Honour `PortalPermission::ManageSchedules` and `canAccessMinistry`. Members see read-only.
- Reuse conflict/availability signals if the grid API already returns them; do not silently ignore conflicts.
- **Do not rewrite** [`schedule-editor.php`](../../../resources/views/schedule-editor.php) in this track. Optional: a small “Open full grid” link with `ministry_id` + date query.
- Exclusive: `calendar.php` (no Phase 5/6 on that file in the same wave).

**Tests:** existing schedule regression tests; add a contract that the calendar does not call a new unsanctioned path.

### 4b-serving-copy (Claude)

[`ministry-dashboard.php`](../../../resources/views/ministry-dashboard.php) button labels (`scheduleButton`, `rosterButton`) and docs. Do not implement Communications/Links/Documents (planned). Do not change assignment JS.

## Out of scope

Needed-position email, substitutions, auto-schedule, PCO-style request/accept, roster schema merge, drag-and-drop people.

## Handoff

```text
Track: 4a-staff-from-inspector (needs Phase 3a/3b) or 4b-serving-copy (can start after 3a if copy-only).
Keep schedule-editor.php as the batch tool.
```
