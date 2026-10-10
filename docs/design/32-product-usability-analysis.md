---
id: 32-product-usability-analysis
title: Church Portal — product, usability, and organization analysis
date: 2026-08-27
status: accepted-for-planning
audience: [claude, cursor, ux-analyzers, product]
related:
  - docs/design/23-calendar-analysis.md
  - docs/design/28-calendar-platform.md
  - docs/design/30-blocked-and-deferred.md
  - docs/design/32-incomplete-capabilities.md
  - docs/design/33-execution-phases/README.md
  - resources/views/admin-roadmap.php
principle: >
  Calendar → Activities → Ministries → People → Serving → Print/share
  are views of the same church week, not separate applications.
  Do not clone Planning Center.
---

# Church Portal — product, usability & organization analysis

Inspected against the running repository on 2026-08-27 (routes, views, permissions, DTOs, calendar/print services). Recommendations are not based on screenshots alone.

**Do not implement this document as a rewrite.** Execution lives in [33-execution-phases](33-execution-phases/README.md). Incomplete and planned work is inventoried in [32-incomplete-capabilities.md](32-incomplete-capabilities.md).

## Central product principle

Church Portal should not become “Planning Center, but homemade.”

Planning Center is a **plan factory** (create a service plan, fill teams, message them). This portal is already a **church week factory** (one calendar, many ministries, campus context, print that looks like this church).

The highest-value path is:

**Calendar (see) → Activity (what) → Ministry (whose work) → Person (who) → Serving (assignment) → Print/share (output)**

as views of the same week — not three more pages.

---

## A. Current product map

### Surfaces people actually use

**Primary nav** (`config/chrome.json`, enforced in `portal_header()` so page-local nav arrays are ignored): Home, Ministries, Calendar, People, Events, Docs.

**Overflow / drawer (signed-in):** My Schedule, Availability, Events (again), Printables, Account. Administration only if `isPortalWideAdmin` or an admin-capable permission.

**Not in global nav:** `/schedules` (ministry schedule editor), `/rosters`, `/schedule-board`, `/calendar/print-setup`. Those are reached from a ministry dashboard or by URL.

### Domain objects (from code)

| Concept | Where it lives | What it is |
|---|---|---|
| Person / family | ChurchCRM via adapters | Directory, privacy masking, admin CRM |
| Event + occurrences | ChurchCRM `events_event` / `event_occurrence` / `event_recurrence` | “Something on the church calendar” (`EventCreateCommand`) |
| Event type / audience | `event_types.portal_audience` | public / members / leaders |
| Ministry schedule assignment | Portal DB, `ScheduleGrid` | Role × person × **event occurrence** |
| Roster schedule | Portal `schedule_roster*` | Dated/DOW slots **without** an event |
| Availability | Portal | Unavailability windows |
| Saved view | Portal + `SavedViewService` | **Print** config (layers, theme, layout), private or shared |
| On-screen calendar filters | `localStorage` `church_portal_calendar_sources_v2` | Personal, not shared |
| RSVP / guest signup | Standalone `events_rsvp/`, `people_signup/` | Token/WORD-ID, not portal sessions |

Calendar layers (`CalendarController::layers`) already overlay: event types, role assignments, ministry schedules, rosters, birthdays, anniversaries, holidays, browser-only “Others”.

```text
VIEWPORT
  └── Application shell (fluid as of 2026-08)
        ├── Home          public hub + “For you” assignments
        ├── Calendar      month / week / day / agenda + left source rail
        ├── Events        list → detail (series + occurrences) → compact editor
        ├── Ministries    chooser → dashboard → Schedule editor | Rosters
        ├── People        directory (not admin CRM)
        ├── My Schedule / Availability / Account
        ├── Printables + Calendar print composer (themes, layouts, saved views)
        └── Admin         people/families, ministries, calendar/events config, site, data
```

### Permissions (small and real)

Roles in `PortalPermission::forRole`: **admin, leader, scheduler, member**.

- Members: dashboard, own assignments, own availability.
- Schedulers: schedules, events, **cancel occurrences**; not leader-audience events.
- Leaders: schedules, roles, events, **leader events**; no occurrence cancel.
- Portal-wide admin: site config, people CRM, users, backups.

Admin nav already hides groups you cannot use (`admin_visible_sections`). That is better than most church software.

### Two data worlds

1. **ChurchCRM** — people, families, events, occurrences, types, recurrence.
2. **Portal DB** — schedule assignments, roster schedules, saved views, users/roles.

### Three serving products

1. Event occurrences on the calendar.
2. Ministry schedule editor (roles × event occurrences).
3. Roster schedules (non-event dated/DOW slots).

That split is the core IA problem.

---

## B. Usability problems (ranked)

### Critical

1. **Three assignment systems, one church.** A volunteer can appear as an event, a ministry-grid assignment, and/or a roster slot. Calendar shows all three as separate layers. Users must guess which tool “owns” Sunday.

2. **Scheduling is not a first-class destination.** The schedule editor is the operational heart for leaders, but it is not in primary nav. Path: Ministries → pick ministry → dashboard → Schedule editor. Rosters is a second editor beside it.

3. **“Events” means two products.** Portal events are calendar activities (explicitly not RSVP). Admin “Sign-ups & RSVP” launches a **separate app** with its own login. Creating an event does not offer RSVP. Searching an event opens `/events`, not the event.

### High

4. **Home is a public brochure, not an operations desk.** Signed-in “For you” is assignments + shortcuts. There is no “this Sunday / unfilled / conflicts” view. `/admin` is a control panel (and still dumps env vars and permission strings on Overview).

5. **Calendar is a viewer with a create shortcut, not a workspace.** Day click → `/events/new`. No inspector, no inline assignment, no drag-reschedule, no “unfilled roles this day.” Saved views do not apply to the screen calendar.

6. **Admin still contains stubs and wrong doors.** `/admin/events` is mostly planned copy plus a tile to `/events/occurrence/new` (not `/events/new`). `/admin/me` duplicates Account. `/admin/links` is planned. Overview tiles still talk about “Beta.”

7. **Vocabulary collision.** Brand says “Scheduler”; nav says Calendar vs Events vs Ministries vs Schedule editor vs Roster schedules vs Printables vs Role assignments vs Ministry schedules.

### Medium

8. **Search cannot answer operational questions** (“who is serving Sunday?”, “what is unfilled?”, “where is John?”) because it never indexes assignments or dates.

9. **People is two apps:** `/people` (directory, privacy) vs `/admin/people` (CRM). Correct split, unclear naming.

10. **Two ministry admin screens:** “Members & leaders” vs “Ministry list.”

11. **Screen calendar filters are browser-local;** print views are server-saved. Users will think they saved a calendar and only saved a PDF recipe.

12. **Confirmations / substitutions / needed-position counts** are not first-class. Conflicts exist only inside one editor.

### Low

13. Event search result href is the list, not `/events/{id}`.
14. Printables vs Calendar → Print is a second print entry.
15. Docs is a primary tab equal to Calendar — good for onboarding, noisy for daily operators.
16. `events-occurrence-new.php` is a leftover generate-occurrences tool after recurrence became a rule.

---

## C. Information architecture problems

The product mixes **everyday operations**, **system administration**, and **personal** more than the recent admin sidebar work admits.

| User job | Where it actually lives | Problem |
|---|---|---|
| See what’s happening | Calendar, Home events, Events list, schedule-board | Four “what’s on” surfaces |
| Put something on the calendar | Calendar New event, `/events/new`, Admin Events (broken path) | Should be one |
| Fill Sunday roles | Ministry dashboard → schedule editor | Hidden; ministry-scoped |
| Fill a non-service rota (pickup, cleaning) | Rosters | Parallel product, similar words |
| My serving | Home “For you”, My Schedule, user menu | Fine; not in primary tabs |
| Print a wall calendar | Calendar print-setup **and** Printables | Split |
| Guest RSVP | Admin → Sign-ups & RSVP → other app | Not an event option |
| Theme the **site** | `/admin/theme` | Easy to confuse with **print** themes |

Admin IA is already closer to church work (People & families → Ministries → Calendar & events → Church setup → Portal → Data → Advanced). The **top bar** still treats Calendar and Events as siblings and hides Scheduling.

Personal items (`/admin/me`) should not sit in Administration. Docs already say that; the admin page still exists.

**Recommended UI names (schema can stay):**

- **Activity** = event (what’s on the calendar). Types may include Service, Meeting, Ministry activity, Holiday, Special, Reminder. Default create = Activity. “Service” is a type that **expects roles**.
- **Serving** = assignments on an activity date (existing `ScheduleGrid`).
- **Rota** / **Posted list** = roster (non-event). Keep the engine; stop calling it a second “schedule.”
- **Calendar** = the place you look and act.
- **Admin** = records, access, site, data. Not My Pages, not the events board.

---

## D. Workflow analysis

Approximate, from routes + UI (not timed user tests).

| Task | Rough path today | Friction |
|---|---|---|
| Create a one-off activity | Calendar → New event **or** Events → New → compact editor | Best workflow in the product. ~2 screens. |
| Weekly recurring activity | Same editor, Repeats = every week, optional Until | Good. Default “repeat for a year” if Until blank — easy to over-generate. |
| Sunday service as a thing | Create event + separately open schedule editor for that ministry | Service is not a first-class object. Two mental models. |
| Assign volunteers | Ministries → ministry → Schedule editor | Powerful, ministry-siloed, no drag-drop, large page. |
| Change an assignment | Same editor | Fast **if** you are already there. |
| Move an event | Event detail → occurrence tools / reschedule-from-today | Exists; not on the calendar. |
| Cancel one occurrence | Event detail → cancel on the row (`cancel_occurrences`) | Exists; calendar still needs a round trip. |
| Update the series | Event detail (header + rule) | Exists; easy to confuse with occurrence tools on the same long page. |
| Find someone’s schedule | People → person **or** search name **or** My Schedule (self) | No “John this month” command. |
| Find conflicts | Only inside schedule editor | Calendar will show double-booking as two chips. |
| Print ministry calendar | Printables/schedules **or** `/schedules/ministry-print` **or** calendar print with layers | Strength buried in three doors. |
| Themed calendar | `/calendar/print-setup` | Strong; not connected to on-screen calendar. |
| What’s happening this week | Calendar week/agenda **or** Events list Upcoming | Best current answer is Calendar. Home does not summarize the week. |

**Principle vs reality:** Frequent create is already progressive (`_event-editor.php`). Frequent **assign** is not. Frequent **see Sunday as a service with roles** is not.

---

## E. Existing strengths (keep and grow)

- **Campus as a global context**, not a per-page dropdown.
- **Audience-filtered event types** (leaders vs members vs public) done in SQL, not by hiding buttons.
- **Event editor** is already “name it, say when, say who” with details disclosed.
- **Occurrence vs series** is implemented; do not replace it with a rewrite.
- **Schedule editor** already has `occurrence → role → person` plus conflicts and availability.
- **Calendar architecture** already separates data, layers, cell presentation, print config, and theme (`CalendarTheme`).
- **Print** is a real product: composer, density, ink-friendly, shared views, Classic = “the sheet this church already prints.” [28-calendar-platform.md](28-calendar-platform.md) asked for a print composer; it is **largely built**. Do not rebuild it.
- **Privacy** on people (masked names, privileged contact).
- **Role-sensitive admin nav** (members no longer see 26 dead links).
- **Fluid workspace** (shell + fill default) is ready for LEFT | MAIN | RIGHT.
- **Church-specific ministries** (catalog, Guest Services roles, Gifts and Arrows).

---

## F. Competitive gap analysis

Ask of each competitor idea: do we have the problem, and can we solve it more simply?

| Competitor idea | Problem it solves | Do we have it? | Simpler Church Portal answer |
|---|---|---|---|
| PCO Services “Plan” | One Sunday is a container for times, teams, needed roles | We split container (event) and staffing (grid/roster) | Treat **an event occurrence as the plan**. Staff it in a right panel. Do not import songs/order-of-service unless asked. |
| PCO Matrix | Fill many weeks in one grid | Schedule editor is already a matrix **per ministry** | Add a **cross-ministry Sunday board** (read-model). Avoid a second editor. |
| PCO needed positions + request emails | Unfilled roles and confirmation | No needed-count, no request/accept loop | Show **unfilled** on the day first; email/confirm later. |
| PCO Calendar product | All-church calendar separate from Services | We already unify layers on one calendar | Keep one calendar; stop splitting Events vs Calendar in the user’s head. |
| Google/Outlook Calendar | Drag, invite, notifications | No drag; portal is not a personal PIM | Drag **occurrences** later; don’t chase Google invites. |
| Breeze / ChurchTrac | Simple all-in-one, weaker scheduling | We are already deeper on scheduling/print | Stay simpler than PCO, deeper than Breeze on print + multi-campus serving. |
| FullCalendar widgets | Familiar interactions | Custom grids already match print/ops | Don’t swap the calendar engine for cosmetics. |

PCO’s real advantage is **communication around a plan**. Church Portal’s advantage can be **seeing the whole church week without buying three PCO products**.

---

## G. Differentiation opportunities

1. **Calendar-first operations** — One feed, many overlays. Competitors split Calendar vs Services vs People. You already merge them technically; the UI still pretends they are apps.
2. **Print as the congregation-facing product** — Thematic calendars from the same data. Make “Publish this month” a first-class action from Calendar.
3. **Shared views without duplicating data** — Print saved views are the seed. Extend the same object to **screen**.
4. **Multi-campus in the chrome** — All campuses vs one campus is already a superpower if every workspace respects it.
5. **Church language** — Services, ministries, serving, “cannot serve,” not “plans,” “needed positions,” “blockouts.” Keep RecurrenceRule’s spoken sentences.
6. **Progressive power** — The event editor is the template. Apply it to event **detail** and to scheduling.
7. **Do not clone RSVP into every activity.** Keep RSVP as an **option on public events**, then fold `events_rsvp` behind the portal session later.

---

## H. Recommended product architecture

Keep the databases. Change the **user model**.

```text
                    CALENDAR  (workspace)
                         │
         overlays        │        actions
    ┌────────────┬───────┴────────┬─────────────┐
    ▼            ▼                ▼             ▼
 Activities   Serving          People       Print/Views
 (events +    (assignments +   (who)        (themes,
  types +      rosters as                    saved configs)
  holidays)    another layer)
                    │
                    ▼
              Ministries
           (who may staff what)
```

**Right inspector (future, architecture already allows `data-right`):**

```text
Sunday 6 Sep
Morning Worship          [Service]
  Preacher     John
  Keyboard     — unfilled
Youth                    [Activity]
  Leader       Sarah
```

That is a **read model** over occurrences + assignments + roster slots for that date. No new source of truth.

Avoid: new “Plan” table, cloning PCO songs, a second calendar product, or replacing ChurchCRM events until the UI tells one story.

---

## I. UX quick wins (low risk)

No schema required. Mapped to Phase 1–2 in [33-execution-phases](33-execution-phases/README.md).

1. Primary nav for operators: Calendar, Ministries, People; Events as “All activities” or calendar agenda. Docs to overflow. Print next to Calendar.
2. From a calendar item / search, open the activity (`/events/{id}`).
3. Fix `/admin/events` to link `/events` and `/events/new`.
4. Hide `/admin/me`; Account already exists.
5. Home signed-in strip: next 7 days + my assignments (data already on APIs).
6. One label for staffing: “Serving this day”; roster as “Posted list.”
7. Event detail: series vs this-date behind a clear switch.
8. Don’t show Events twice (primary + secondary).
9. Admin Overview: drop env/permission dump from the default card.
10. Unfilled hint in schedule editor header using data already on the grid.

---

## J. Strategic enhancements

1. **Day inspector** — GET a date-scoped assembly of layers. UI: right panel. Reuse calendar sources API + schedule grid.
2. **Screen saved views** — Same `SavedViewService`; apply to `/calendar`.
3. **Service staffing without a new editor** — Writes go to existing assignment APIs.
4. **Roster as a layer and a list**, not a twin of Schedules.
5. **Fold RSVP later** — Optional flag on public activities.
6. **Needed roles + notifications** — Only after the day inspector exists.
7. **Drag occurrences / drag people** — After inspector + permissions.

---

## K. Proposed roadmap

See [33-execution-phases](33-execution-phases/README.md) for implementable briefs. Sequence:

```text
Phase 1 — Navigation and terminology
Phase 2 — Core workflow simplification
Phase 3 — Calendar as operations workspace (read-only day inspector)
Phase 4 — Scheduling workspace (staff from inspector)
Phase 5 — Saved / shared views on screen
Phase 6 — Contextual panels (data-right)
Phase 7 — Differentiators (publish/print, then alerts / RSVP / email)
```

Phase 5 can start in parallel with Phase 3 UI because saved views already exist for print.

Blocked items (rooms list, Hub XLSX, relationship graph) stay out of Phases 1–6.
