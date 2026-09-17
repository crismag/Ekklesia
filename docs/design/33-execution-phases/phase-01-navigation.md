---
id: phase-01
depends_on: []
status: shipped
principle: Calendar → Activities → Ministries → People → Serving → Print are one week, not new apps.
tracks:
  - id: 1a-chrome-nav
    agent: cursor
    files:
      - config/chrome.json
      - resources/views/_portal-shell.php
    forbidden:
      - resources/views/calendar.php
      - resources/views/events-detail.php
  - id: 1b-admin-stubs
    agent: cursor
    files:
      - resources/views/admin-events.php
      - resources/views/admin-me.php
      - resources/views/_admin-shell.php
      - resources/views/admin.php
      - tests/Regression/admin-navigation.php
      - tests/Regression/source-contracts.mjs
    forbidden:
      - config/chrome.json
  - id: 1c-docs-labels
    agent: claude
    files:
      - resources/views/docs/sections/03-site-navigation.md
      - resources/views/docs/sections/01-viewing-schedules.md
      - resources/views/docs/sections/02-schedule-editor.md
      - resources/views/docs/sections/04-creating-events.md
    forbidden:
      - resources/views/_portal-shell.php
      - config/chrome.json
---

# Phase 1 — Navigation and terminology

> **Shipped.** 1a and 1b in #18; 1c in #19 and #24. Primary nav is Home, Calendar,
> Ministries, People, Events, with My Schedule, Availability, Serving, Printables,
> Account, Docs and Administration in the overflow. `/admin/me` now redirects to
> `/account` and is gone from the sidebar. The in-app guide describes that nav.

## Outcome

Operators see one story: Calendar is the week; Events/Activities are calendar items; Serving is reached from Calendar or Ministries; Print is one door; Admin is records/site, not My Pages.

## User-visible before / after

**Before:** Primary tabs Home, Ministries, Calendar, People, Events, Docs. Overflow repeats Events. Printables and schedule editor are hidden. `/admin/events` has a broken “New event” link. `/admin/me` duplicates Account.

**After:** Primary tabs suitable for daily work (Calendar, Ministries, People; Home; Events as “Activities” or kept once only). Docs in overflow unless chrome admin pinned it. Printables in overflow once (not competing with Events). Admin Events links `/events` and `/events/new`. Admin Me gone from sidebar or reduced to a redirect note. Overview does not lead with env vars.

## Tracks

### 1a-chrome-nav (Cursor)

Edit [`config/chrome.json`](../../../config/chrome.json) `primaryNav` and [`portal_header()` secondary nav](../../../resources/views/_portal-shell.php) (~lines 1021–1033).

- Do not list Events in both primary and `$secondaryNav`.
- Add Printables once in overflow (already there); do not add a second Print tab.
- Optional: add a single “Serving” overflow item pointing at ministries (not a new page).
- Keep campus selector and search. Do not change shell width CSS.

**Tests:** `node tests/Regression/source-contracts.mjs` (home/nav contracts if they pin labels — update contracts if they assert old duplicate Events).

### 1b-admin-stubs (Cursor)

- [`admin-events.php`](../../../resources/views/admin-events.php): “New event” → `/events/new`. “Events board” stays `/events`. Remove or clearly mark planned column/range cards as roadmap-only (or link `/admin/roadmap`).
- Hide **My Pages** from [`admin_sections()`](../../../resources/views/_admin-shell.php) if present; if `admin-me.php` must remain reachable, make it a one-line redirect to `/account`.
- [`admin.php`](../../../resources/views/admin.php) tiles: drop or un-badge Site Links as the hero of Overview; do not dump `PORTAL_*` env on the default card (System information already exists).
- Update [`tests/Regression/admin-navigation.php`](../../../tests/Regression/admin-navigation.php) if it pins section ids.

### 1c-docs-labels (Claude)

Update in-app guide so it matches 1a/1b. Prefer “activity” in user-facing sentences where it means a calendar item; keep URL `/events`. Call the schedule editor “Serving” / “ministry serving grid” once, then the existing name. Do not invent RSVP integration.

## Out of scope

Calendar inspector, saved views, schema, RSVP apps, rooms, home week strip (Phase 2).

## Handoff

```text
Track: 1a-chrome-nav | 1b-admin-stubs | 1c-docs-labels (pick one)
Read docs/design/33-execution-phases/README.md and this file.
Edit only files listed for that track.
```
