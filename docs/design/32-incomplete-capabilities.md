---
id: 32-incomplete-capabilities
title: Planned, incomplete, deferred, and blocked capabilities
date: 2026-08-27
status: inventory
audience: [claude, cursor, ux-analyzers, product]
related:
  - docs/design/30-blocked-and-deferred.md
  - docs/design/28-calendar-platform.md
  - docs/design/23-calendar-analysis.md
  - resources/views/admin-roadmap.php
  - docs/design/32-product-usability-analysis.md
---

# Incomplete and planned capabilities

Status values used below:

| Status | Meaning |
|---|---|
| `live` | Ships and is the intended product surface |
| `incomplete` | Partially built; users can hit a gap or a split |
| `planned` | Advertised in admin UI or roadmap; not built |
| `deferred` | Buildable; deliberately not started |
| `blocked` | Needs church/ops input or infrastructure before code |
| `superseded` | An older design asked for this; later work already delivered it |

Canonical lists this file **cites** rather than replaces:

- In-app: [`resources/views/admin-roadmap.php`](../../resources/views/admin-roadmap.php)
- Blocked/deferred: [`30-blocked-and-deferred.md`](30-blocked-and-deferred.md)

Agents must **not** rebuild `superseded` items (especially print).

---

## 1. Advertised in Admin / roadmap (`planned`)

Source: `admin-roadmap.php` plus leftover “Coming soon” cards.

| ID | Capability | Where advertised | Notes | Early phase? |
|---|---|---|---|---|
| P-home-tiles | Continue Working tiles (reorder/rename shortcuts) | Roadmap · Front page | Home is now a public hub + “For you”; only worth it if shortcuts differ per church | No |
| P-notes | Personal notes | Roadmap; [`admin-me.php`](../../resources/views/admin-me.php) | Needs storage + retention decision | No |
| P-photo | Profile photo upload | Roadmap; `admin-me.php` | Needs storage, size limits, moderation | No |
| P-links | External links manager | Roadmap; entire [`admin-links.php`](../../resources/views/admin-links.php) is a placeholder | Intended `config/links.json` like hero.json | 7c brief only; needs product yes |
| P-refdocs | Reference documents in-portal | Roadmap; `admin-links.php` | Overlaps `/docs`; do not start without a product decision | No |
| P-cal-default | Default calendar view for first visit | Roadmap | Today: per-browser last view in `localStorage` | Phase 5 related |
| P-ev-columns | Events list column editor | [`admin-events.php`](../../resources/views/admin-events.php) | Hard-coded in `events-list.php` | No |
| P-ev-range | Default events look-ahead window | `admin-events.php` | Copy still says API caps at 8; verify before building | No |
| P-min-vis | Default ministry public/private | Roadmap · Ministries | | No |
| P-min-panels | Which panels appear on a ministry workspace | Roadmap · Ministries | | No |
| P-min-comms | Ministry Communications | [`ministry-dashboard.php`](../../resources/views/ministry-dashboard.php) “Coming soon” | Announcements exist site-wide (`/admin/announcements`) | Phase 7 |
| P-min-links | Managed links per ministry | ministry-dashboard | Same family as P-links | Phase 7 |
| P-min-docs | Ministry documents / files | ministry-dashboard | Needs storage decision | No |

**Phase 1 leftovers (closed):** `/admin/events` New event goes to `/events/new` (#18). `/admin/me` redirects to `/account`. Links remains planned (`P-links`). People vs Member records and Members & leaders vs Ministry list closed in #35/#36.

---

## 2. Incomplete or split (`incomplete`)

These exist in code but are not one product story. Not all appear on `/admin/roadmap`.

| ID | Capability | What exists | Gap | Phase |
|---|---|---|---|---|
| I-serving-triple | Putting people on dates | **Names closed in #24** — a ministry offers “Serving grid” and “Posted lists”, and the guide says a posted list is not a second schedule. The three engines still exist. | 1 ✅, 4 (workspace) |
| I-sched-nav | Schedule editor | Full editor at `/schedules`; **Serving** in overflow opens Ministries | Not a primary tab; reached from the ministry whose work it is | 1, 2 (overflow shipped) |
| ~~I-screen-vs-print-views~~ | Saved views | **Closed in #27.** One `SavedViewService` restores screen state and prints from the same rows; no second table. Custom calendars stay in the browser (`I-custom-cals`). | — | 5 ✅ |
| I-custom-cals | Custom (“Others”) calendars | Browser `localStorage` only; note in calendar.php | Never reach the server or print | 5 or later |
| I-search | Ctrl+K search | People, activities, ministries, pages | **Deep link closed in #21** — a hit opens `/events/{id}`. Still no assignments or dates in the index. | 2 (partly ✅) |
| ~~I-inspector~~ | Day as operations surface | **Closed in #20, #23 and #27.** A date opens a read-only inspector in the shell right rail, listing activities, roles and unfilled slots. | — | 3, 6 ✅ |
| I-rsvp-split | Event RSVP | Standalone `events_rsvp/` (token auth) | Not an option on portal events; `EventCreateCommand` deliberately excludes RSVP | 7c — brief only |
| I-signup-split | Guest registration | Standalone `people_signup/` | Linked from Admin → Sign-ups & RSVP | 7c — brief only |
| I-home-ops | Operational landing | **This-week closed in #21.** Unfilled counts exist on the serving grid and inspector (#27); Home still does not surface conflicts. | 2 (partly ✅) |
| I-admin-stubs | Admin Events / Links / Me | **Events and Me closed in #18** — the broken `/events/occurrence/new` link is fixed and `/admin/me` redirects to `/account`. Links is still planned copy (`P-links`). | 1 (partly ✅) |
| I-conflicts | Scheduling conflicts | Schedule editor chips; inspector shows the same conflict labels | Calendar does not drag-reschedule around them | 3 read, 4 write (labels shipped) |
| I-drag | Drag create/move | None | Deferred in [23-calendar-analysis.md](23-calendar-analysis.md); needs write endpoints + WCAG 2.5.7 alternative | 7c — brief only |
| I-needed | Unfilled / needed roles | Inspector and serving grid count empty cells | No alerts, no request email | 7b count shipped; 7c mail still deferred |
| I-confirm | Assignment confirmation | `status` is `assigned` / `open` | UI is not a request/accept loop | 7c — brief only |
| ~~I-people-two~~ | People directory vs CRM | **Closed in #35/#36.** Top-bar **People** is look-up (`/people`); Administration → **Member records** is add/edit (`/admin/people`). Guides match. | — | 1 ✅ |
| ~~I-min-admin-two~~ | Two ministry admin screens | **Closed in #35/#36.** **Members & leaders** creates the team; **Ministry list** is public visibility; top-bar **Ministries** is the chooser. | — | 1 ✅ |
| I-print-doors | Print | Printables hub + Calendar **Print this view** + ministry print | Three doors on purpose; do not merge composers | 1, 7a (wall calendar from the view shipped) |
| I-occ-new | Generate occurrences page | `events-occurrence-new.php` | Recurrence rule now lives on the shared editor | 2 (do not promote) |

---

### Corrections worth knowing

| What | Correction |
|---|---|
| Scheduling event pool | Migration 013's `LIKE '%sunday service%'` bootstrap sets which event a campus **opens with**. It is not the picker's filter: since #30 the picker offers every activity with an occurrence in the selected date range on that campus. Do not re-add an `assignment_scheduling_enabled` filter to the picker. |
| Birthdays and holidays | Not events. They reach the calendar from person records and the cached holiday calendars, never from `events_event`, so they cannot appear in the scheduling picker. An event somebody *entered* named “Civic Holiday” is an event and does appear. |
| Migration 013 | Applied in production and checksummed. Do not edit it; see [34-migration-013-caveat.md](34-migration-013-caveat.md). |

## 3. Deferred (`deferred`)

From [30-blocked-and-deferred.md](30-blocked-and-deferred.md). Buildable; do not start in Phases 1–6.

| ID | Capability | Why waiting |
|---|---|---|
| D-templates | Event templates | Editor + occurrence model now stable; template must not carry ids, dates, or historical assignments |
| D-nl-add | Natural-language quick add | Parser → shared editor only; never a second create path |
| D-approvals | Approval workflow | [28-calendar-platform.md](28-calendar-platform.md): not until somebody asks |
| D-fullcalendar | Calendar library / React | Explicitly rejected; hand-written views stay |

---

## 4. Blocked (`blocked`)

Do not implement. Unblockers are in 30.

| ID | Capability | Unblocker |
|---|---|---|
| B-rooms | Rooms/resources + location conflict | Church supplies bookable places (name + campus) and shareable vs exclusive. `locations` is empty; `location_id` unused |
| B-anon-db | Dev anonymisation run | DBA creates `christlike_dev` and grants; tool already exists |
| B-hub-xlsx | Member reconciliation from Hub | Workbook on a machine that can run import; staging UI exists |
| B-rel-graph | Person-to-person relationship graph | Own design + migration; 15 households / 4 split pairs need human review first |

---

## 5. Superseded — do not rebuild

[28-calendar-platform.md](28-calendar-platform.md) (written before print landed) listed gaps that later work closed. Agents reading 28 in isolation will over-build.

| 28 asked for | Status now (2026-08) |
|---|---|
| Move view model to server | `CalendarViewModel`, `PrintComposer`, `PrintConfig` exist |
| Print composer (range, sources, template, preview) | `/calendar/print-setup`, `/calendar/print` |
| Themes / layouts | `CalendarTheme` (Classic, Editorial, Planner, …); monthly/weekly/sunday/annual |
| Tags | Event tags exist (`TagName`, editor datalist) |
| Shared print views | `SavedViewService` private/shared |

Still true from 28/23: rooms unused; screen filters local; drag not built; custom calendars client-only.

---

## 6. Live strengths (do not “plan” replacements)

- Compact shared event editor (`_event-editor.php`)
- Recurrence as stored rule (`RecurrenceRule`) including monthly nth weekday
- Occurrence vs series on event detail
- Audience-filtered layers in SQL
- Campus cookie as global context
- Role-gated admin sidebar
- Fluid application shell + workspace default
- Print as a first-class capability

---

## 7. How analyzers should use this file

1. If a request maps to `blocked` or `deferred`, refuse to implement and cite the row.
2. If it maps to `superseded`, point at the live files instead of new architecture.
3. If it maps to `planned` but is marked “Early phase? No”, do not sneak it into Phases 1–4. Phase 7c is a brief, not a yes on `P-links` / `P-photo` / `P-notes`.
4. Prefer closing `incomplete` splits (I-\*) over adding new planned features (P-\*).
