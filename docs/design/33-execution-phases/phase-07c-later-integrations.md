---
id: phase-07c
depends_on: [phase-07]
status: brief_only
principle: Differentiate on print and a unified week, not on cloning PCO. Do not implement blocked or deferred IDs. 7c is packets that wait on church, ops, or product — not a code dump because 7a/7b shipped.
tracks:
  - id: 7c-inventory
    agent: none
    files: []
    note: This file is the brief. No PHP until a row below is unblocked by name.
---

# Phase 7c — Later integrations (brief only)

7a (Print this view) and 7b (unfilled count, display only) have shipped. This file is the **planning track** named in [phase-07-differentiators.md](phase-07-differentiators.md). It is not permission to build the list.

**Verdict:** nothing in the original 7c table is ready to code. Inspector + unfilled exist, which was the *dependency* for serving-request email — not the product yes. Mail, RSVP fold, rooms, templates, NL-add, photos/notes, links, and drag each still have a blocker in [32-incomplete-capabilities.md](../32-incomplete-capabilities.md) or [30-blocked-and-deferred.md](../30-blocked-and-deferred.md).

```text
Do not start 7c-rsvp, 7c-mail, 7c-templates, 7c-nl-add, 7c-rooms,
7c-photo-notes, 7c-links, or 7c-drag from this brief. Unblock one row
by name, then cut a track with files and tests. Until then, refuse.
```

## What shipped (do not rebuild)

| Need 7c used to wait on | Where it lives now |
|---|---|
| Day inspector | Calendar right rail; `GET /api/calendar/day`; staff via `POST /api/schedules/assignments` (one assignment, no start/end) |
| Unfilled roles | Inspector `#dayUnfilledCount`; serving grid `#unfilledHint`. Count only. No email. |
| Print from the view on screen | Calendar **Print this view** → `/calendar/print-setup` |
| Screen saved views | **Open view…** / **Save view…** on the same `SavedViewService` rows as print (`screenView` / `screenLeft`) |
| Serving vs posted lists | Ministry buttons and docs. Grid is in-place cells, not a sliding form. |

Principle still holds: Calendar → Activities → Ministries → People → Serving → Print are views of one church week. Do not clone Planning Center. Do not add schema for rooms, RSVP merge, or relationship graphs. Do not rebuild print ([32-incomplete §5](../32-incomplete-capabilities.md)).

## Item by item

Each row is a **future** track. `Ready?` is whether an agent may write PHP today.

### RSVP as an optional flag on public activities — not ready

| | |
|---|---|
| IDs | `I-rsvp-split`, `I-signup-split` |
| Ready? | **No** |
| Why | Portal events are not RSVP. `EventCreateCommand` excludes it. `events_rsvp/` and `people_signup/` are separate apps (token / WORD-ID, not portal session). Folding them is a dedicated integration brief, not a checkbox on `_event-editor.php`. |
| Unblock | Product yes that **some** public activities may opt in; then a fold brief (auth, which events, what happens to the standalone apps). |
| Forbidden until then | RSVP on every activity. New schema that merges the two products in place. Touching `events_rsvp/` or `people_signup/` “while we are here.” |

When unblocked, the first packet is a **flag + deep link** to the existing RSVP app for that activity — not a rewrite of token auth.

### Serving request email / accept — not ready

| | |
|---|---|
| IDs | `I-needed` (alerts), `I-confirm` |
| Ready? | **No** |
| Why | 7b closed the count. Assignment `status` in ChurchCRM is `assigned` / `open`, not request / accept. There is no mail pipeline in this repo. Building “needed positions” email now is cloning PCO communication, which Phase 7 forbade. |
| Unblock | From-address, templates, unsubscribe, and an explicit yes that mail is wanted. Still do **not** invent an accept loop unless product asks for `I-confirm` by name. |
| Forbidden until then | SMTP, `mailto:` from the unfilled hint, new needed-positions tables, request/accept UI. |

A smaller later packet, if someone wants share-without-mail: copy the unfilled list as text. That is still not this brief.

### Event templates — not ready

| | |
|---|---|
| ID | `D-templates` |
| Ready? | **No** (deferred) |
| Why | Editor and occurrence model are stable enough *technically*. A template must not carry ids, dates, or historical assignments. Nobody has asked for “new Sunday like last Sunday’s shape.” |
| Unblock | A one-page product note: which fields copy, which never copy. |
| Forbidden until then | Duplicate-event that clones assignment rows. A second create path. |

### Natural-language add — not ready

| | |
|---|---|
| ID | `D-nl-add` |
| Ready? | **No** (deferred) |
| Why | Parser must open the **shared** editor with fields filled, never a second create path ([32](../32-incomplete-capabilities.md)). |
| Unblock | Product yes plus a parser that only prefills `_event-editor.php`. |
| Forbidden until then | Chat-to-save that bypasses the editor. |

### Rooms / resources — not ready

| | |
|---|---|
| ID | `B-rooms` |
| Ready? | **No** (blocked) |
| Why | `locations` is empty; `location_id` unused. A picker of invented *Room A* is fiction the church then has to unpick. Unblockers are in [30](../30-blocked-and-deferred.md): name + campus per place, shareable vs exclusive, whether anything other than a room is bookable. |
| Design already decided (do not re-litigate) | Booking is `(occurrence_id, location_id)`. Conflicts report at save; they do not refuse. Cancelled occurrences do not conflict. |
| Forbidden until then | Seeding fake rooms. Reading `locations` in the editor. Resource tables. |

### Profile photo / personal notes — not ready

| | |
|---|---|
| IDs | `P-photo`, `P-notes` |
| Ready? | **No** |
| Why | Storage, size limits, moderation, retention. `/admin/me` is the wrong door (hide/redirect; do not build from there). |
| Unblock | Storage decision (where files live, who may upload, how they are deleted). |
| Forbidden until then | Upload UI on `admin-me.php`. Notes that have nowhere durable to go. |

### External links manager — not ready

| | |
|---|---|
| IDs | `P-links`, `P-min-links`, `P-refdocs`, `P-min-comms`, `P-min-docs` |
| Ready? | **No** without a product yes |
| Why | [`admin-links.php`](../../../resources/views/admin-links.php) is a placeholder aimed at `config/links.json` like [`config/hero.json`](../../../config/hero.json). That is the smallest *planned* 7c item, but [32 §7.4](../32-incomplete-capabilities.md) prefers closing incomplete splits over new planned features. The same admin page also advertises in-portal reference docs, which overlap `/docs` (`P-refdocs` — do not start). Ministry dashboard “Coming soon” (Communications / Managed links / Documents) is the same family (`P-min-*`) and needs storage or a yes that site-wide announcements are enough. |
| Unblock | Product yes that **site-wide** footer/social/quick links are wanted, **without** reference-doc hosting and **without** per-ministry file storage in the same wave. |
| Forbidden until then | Building `P-refdocs`. Inventing dashboard tiles that compete with signed-in Home. Stub backends for ministry Communications. |

When unblocked, one writer: `admin-links.php` + `config/links.json` + the one consumer (footer **or** a named Home block). Not both ministry files and site-wide in the same wave.

### Drag-to-move — not ready

| | |
|---|---|
| ID | `I-drag` |
| Ready? | **No** |
| Why | Phase 6 (right rail) has shipped, which was the sequencing gate. [23-calendar-analysis.md](../23-calendar-analysis.md) still holds: drag needs **write endpoints the calendar does not have**, and WCAG 2.5.7 needs a **non-dragging alternative for each gesture**. The alternative for “move this occurrence” is already the event page (Fix times / occurrences). Drag-create would compete with the day panel’s **New event on this day**. |
| Unblock | A named occurrence move API (date/time), keyboard/form path that stays the event editor, and a product yes that drag is worth the second input mode. |
| Forbidden until then | Pointer-only move. Drag-create. Dragging people between serving-grid cells (that is a different product). React / FullCalendar (`D-fullcalendar` — rejected). |

## If the next merge should be code, it is still not 7c

Phase 1 copy (`I-people-two`, `I-min-admin-two`) closed in #35/#36. The unused PHP stubs `docs/leader.php`, `docs/member.php`, and `docs/shortcuts.php` are deleted; `/docs/{leader,member,shortcuts}` still alias through `docs.php` to the markdown catalog.

Remaining leftovers that are **not** 7c:

| ID | What | First files |
|---|---|---|
| `I-conflicts` | Conflict chips exist on the serving grid and inspector; Calendar itself does not show them. | `calendar.php` — exclusive with any other calendar.php track |
| `I-custom-cals` | “Others” calendars stay in this browser; they never print. | Do not pretend a saved view publishes them |
| `I-search` | Event hits open `/events/{id}`; still no assignments or dates in the index. | search index, not 7c |
| `I-home-ops` | This-week strip shipped; Home still does not surface conflicts. | Home overlay only |

`I-print-doors` is partly closed (Print this view). Printables remains the hub for roster sheets and birthdays on purpose. Do not merge the composers.

## Out of scope (still)

New mail infrastructure. ChurchCRM `locations` booking. Relationship graph (`B-rel-graph`). Hub XLSX (`B-hub-xlsx`). Dev anonymisation (`B-anon-db`). Approval workflow (`D-approvals`). Rebuilding `PrintComposer`. Cloning PCO matrix.

## Handoff

```text
Track: 7c-later-integrations.
Read 32-incomplete-capabilities.md §3–5 and 30-blocked-and-deferred.md.
Read docs/design/33-execution-phases/phase-07c-later-integrations.md.
Do not implement any 7c item. If you need code, pick a remaining incomplete
split from the “not 7c” table, as a separate branch.
```
