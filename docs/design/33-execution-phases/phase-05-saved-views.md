---
id: phase-05
depends_on: [phase-01]
status: shipped
parallel_with: [phase-03]
note: May run beside 3a. Must not edit calendar.php in the same wave as 3b or 4a.
principle: One SavedViewService; screen and print share configuration, not a second table.
tracks:
  - id: 5a-printconfig-apply
    agent: cursor-or-claude
    files:
      - app/Services/Calendar/SavedViewService.php
      - app/Services/Calendar/PrintConfig.php
      - app/Http/Controllers/Api/SavedViewController.php
      - routes/api.php
    forbidden:
      - resources/views/calendar.php
      - resources/views/calendar-print.php
  - id: 5b-screen-views
    agent: cursor
    depends_on_tracks: [5a-printconfig-apply]
    files:
      - resources/views/calendar.php
    forbidden:
      - app/Services/Calendar/PrintComposer.php
---

# Phase 5 — Saved and shared views (screen)

> **Shipped in #27.** A saved view restores screen calendar state and prints from the
> same rows; PrintConfig applies it. No second table was added.

## Outcome

A saved view can restore **screen** calendar state (layers, campus, month/week/day/agenda, left-rail open/collapsed) using the existing private/shared `SavedViewService`. Print continues to use the same rows.

## User-visible before / after

**Before:** Layer chips live in `localStorage` (`church_portal_calendar_sources_v2`). Print setup has named shared views. They do not talk to each other.

**After:** Calendar can “Open view…” / “Save view…” (reuse print visibility rules: consumers copy, they do not overwrite someone else’s shared view). Opening a print-oriented view on screen applies layers + campus even if paper fields are ignored.

## Tracks

### 5a-printconfig-apply (Cursor or Claude)

Extend [`PrintConfig`](../../../app/Services/Calendar/PrintConfig.php) (or a thin sibling on the same JSON) with **optional** screen keys, defaulted so old print views still load:

- `sources` / layers (already present for print)
- `view`: `month|week|day|agenda` (optional)
- `left`: `open|collapsed` (optional; matches `portal_shell_mods`)

Do not create a second views table. Do not change print HTML templates.

**Tests:** [`tests/Regression/saved-views.php`](../../../tests/Regression/saved-views.php), [`tests/Regression/print-config.php`](../../../tests/Regression/print-config.php).

### 5b-screen-views (Cursor)

[`calendar.php`](../../../resources/views/calendar.php) only, **after** 5a, and **not** in the same wave as 3b or 4a.

- Load `/api/calendar/views`; apply filters to existing `FILTER_KEY` state.
- Save current filters via existing POST/PUT. Shared overwrite rules stay in `SavedViewService::mayEdit`.
- Custom calendars remain local until a later decision (I-custom-cals); do not pretend they are shared.

## Out of scope

Rebuilding print composer (superseded). Server-side custom calendars. Kibana-like field pickers. New permissions vocabulary.

## Handoff

```text
Track: 5a-printconfig-apply first (no calendar.php).
Track 5b-screen-views only when calendar.php is free (not 3b/4a).
```
