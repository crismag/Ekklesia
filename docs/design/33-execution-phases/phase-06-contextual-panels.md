---
id: phase-06
depends_on: [phase-03]
status: shipped
also_depends_soft: [phase-04, phase-05]
principle: Main workspace fills the column; left and right panels are optional and collapse to zero tracks.
tracks:
  - id: 6a-right-rail
    agent: cursor
    files:
      - resources/views/_portal-shell.php
      - resources/views/calendar.php
    forbidden:
      - app/Http/Controllers/Api/CalendarController.php
      - resources/css/app.css
---

# Phase 6 — Contextual panels

> **Shipped in #27**, alongside Phase 5 rather than after it. The inspector now lives in
> the shell right rail. Measured on a 1600px viewport: closed the workspace is
> `280px 1252px`; open it is `280px 920px 320px`; closing returns the width to the grid
> (898px back to 1230px).

## Outcome

The day inspector (Phase 3) and later serving/actions live in the shell’s **right** region (`data-right`), matching the left calendars rail. Collapsing it returns width to the month grid. No nested max-width on the page.

## User-visible before / after

**Before (historical):** `portal_shell_mods(..., ..., $right)` and `.portal-right` CSS existed in [`_portal-shell.php`](../../../resources/views/_portal-shell.php) but Calendar did not use a right panel, and the 3b inspector sat in the main column. Both are now false — see the note at the top of this file.

**After:** Calendar calls `portal_shell_mods('workspace', 'open', 'open')` (or collapsed from `church_portal_shell_v1`). Inspector markup is `#portalRight` / `.portal-right`. Mobile stacks; toggle hidden below 760px as left already is.

## Tracks

### 6a-right-rail (Cursor)

Single track (calendar.php + shell). Do not split.

- Move inspector DOM into the right region; keep day API client from 3b.
- Persist right panel like left (`church_portal_shell_v1`).
- Verify workspace width tests still hold: content grows when a rail collapses ([`tests/e2e/workspace-width.spec.ts`](../../../tests/e2e/workspace-width.spec.ts) if present on the branch).
- Do not add a speculative third app column of unrelated widgets.

**Tests:** source-contracts for `data-right` / `portal-right` if not already pinned; Playwright fixture from workspace-width if available.

## Out of scope

New APIs, print, drag-and-drop, notifications.

## Handoff

```text
Track: 6a-right-rail
Requires Phase 3 inspector behavior already in calendar.php.
Do not start Phase 7 in the same change.
```
