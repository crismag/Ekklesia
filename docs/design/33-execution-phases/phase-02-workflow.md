---
id: phase-02
depends_on: [phase-01]
status: shipped
principle: Frequent actions stay fast; complexity appears only when needed.
tracks:
  - id: 2a-search-deep-link
    agent: cursor
    files:
      - resources/views/_portal-shell.php
      - tests/Regression/source-contracts.mjs
    forbidden:
      - resources/views/calendar.php
      - resources/views/index.php
  - id: 2b-event-detail-ia
    agent: cursor
    files:
      - resources/views/events-detail.php
      - tests/Regression/source-contracts.mjs
    forbidden:
      - resources/views/_portal-shell.php
  - id: 2c-home-week
    agent: cursor
    files:
      - resources/views/index.php
      - routes/web.php
    forbidden:
      - resources/views/calendar.php
      - resources/views/_portal-shell.php
---

# Phase 2 — Core workflow simplification

> **Shipped in #21.** Ctrl+K opens the activity itself (`/events/{id}`) rather than the
> list, event detail separates series from occurrence, and Home carries a this-week
> section.

## Outcome

Finding an activity, editing series vs one date, and seeing “this week + my serving” do not require hunting through Admin or a ministry dashboard.

## User-visible before / after

**Before:** Ctrl+K event results go to `/events`. Event detail mixes series tools and occurrence tables on one long page. Home “For you” is assignments + shortcut chips only.

**After:** Search opens `/events/{id}` when an id is present. Event detail groups “This activity” vs “This date” (existing cancel/reschedule APIs unchanged). Signed-in Home shows the next 7 days of public/member events **and** my assignments, using existing APIs (`/api/public/events`, `/api/my-schedule`, `/api/calendar/sources` as appropriate).

## Tracks

### 2a-search-deep-link (Cursor)

In the search overlay JS inside [`_portal-shell.php`](../../../resources/views/_portal-shell.php) (`prefetch` / event mapping ~1484–1486):

- Prefer `href: basePath+'/events/'+id` using `event_id` / `eventId` from the events API.
- Do not add assignment search in this track (needs an index; Phase 3+).
- Keep 2-character minimum and keyboard behavior.

**Tests:** source-contracts if they pin search JS strings; otherwise a small assertion that `/events/` + id appears in the overlay mapper.

### 2b-event-detail-ia (Cursor)

[`events-detail.php`](../../../resources/views/events-detail.php): visual grouping only.

- Primary: title, when/repeats (shared editor), campus.
- Secondary disclosure or clearly headed section: occurrence table (cancel this date, retitle this date, reschedule from today).
- Do not restore `events-occurrence-new.php` as the happy path.
- Do not stretch the editor to full viewport; `.ee` stays compact.

### 2c-home-week (Cursor)

[`index.php`](../../../resources/views/index.php) signed-in “For you” block: add a compact “This week” list (existing event fetch patterns on the page). Do not turn Home into Admin. Guests keep the public hub.

If a new query param or small route helper is required, keep it in [`routes/web.php`](../../../routes/web.php) Home closure only.

## Out of scope

Day inspector API, staffing writes, saved views, drag-and-drop, RSVP.

## Handoff

```text
Track: 2a-search-deep-link | 2b-event-detail-ia | 2c-home-week (pick one)
Depends on Phase 1 merged so nav labels are stable.
Read 32-product-usability-analysis.md section D and I.
```
