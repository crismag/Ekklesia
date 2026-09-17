# Events RSVP (standalone)

A self-contained RSVP web page. It runs on the same server as the church portal
but has **no dependency** on the portal's PHP includes, styles, or runtime.

## What it does

- **`event.php?event_id=123`** — RSVP form for a single event (`events.id` in the
  member database). The event is always
  chosen by the URL and loaded server-side (`rv_load_event()`) — the guest never
  picks it from a list. Collects name, optional email/phone/city, optional birth
  month+year (for matching), Yes / Maybe / No, party size, and notes.
- **`submit_rsvp.php`** — reloads the event server-side (authoritative), validates,
  matches against members, and records an attendance row.
- **`confirmation.php`** — friendly summary of the submitted RSVP.
- **`admin_attendance.php`** — token-gated console: pick an event, see tallies
  (Yes / Maybe / No / expected heads) and the response list, and mark
  check-in / no-show / cancelled.

Portal admins can also work RSVPs from **Sign-ups & RSVP** in the church
portal control panel when that surface is used. This folder remains a
standalone app with its own access code.

## How a response is recorded

RSVPs are stored in `visitor_rsvps` (visitors database) with the event's
`event_id` and, when the event has an upcoming date, that date's
`occurrence_id`.

- **Confident member match** → recorded with `person_id` (member database).
- **Anyone else** → saved in `visitor_registrations` (`source = rsvp`,
  `source_event_id` set) and recorded with `visitor_registration_id`. Guests are
  **never** auto-added to the member database — the People Sign-Up admin review
  is where registrations get promoted.

Every RSVP row also keeps the name/city/email/phone given, so the list is
readable even before anyone is linked.

## Events

RSVPs are for member-database events only: `events` (active, not archived),
showing the soonest scheduled `event_occurrences` date that is today or later,
else the event's own start date.

## Configuration

- **`config/rsvp.config.json`** — non-sensitive UI settings (title, which fields to
  show, `allow_maybe`, messages).
- **`config/rsvp.secure.php`** — database settings + admin token. Executed, never
  served as text. Env first, then portal `.env`, then defaults.

  | Setting | Env var | Fallback |
  | --- | --- | --- |
  | Member database host/port/name/user/pass (MySQL) | `MEMBERS_DB_*` (then `DB_*`) | portal `.env` |
  | Visitors database file (SQLite, relative to the Ekklesia root) | `VISITORS_DB_PATH` | `storage/private/database/visitors.sqlite` |
  | Admin access code | `RSVP_ADMIN_TOKEN` | `change-me-events_rsvp-admin` |

  **Change `RSVP_ADMIN_TOKEN` before going live.**

## Install

1. The visitors database is `database/visitors/001_schema.sql` (built by
   `database/migrate/visitors_from_legacy.php`); events come from the member
   database (`database/members/001_schema.sql`).
2. Set database env vars (or rely on the portal `.env` fallback) and a strong
   `RSVP_ADMIN_TOKEN`.
3. Share links like `events_rsvp/event.php?event_id=5`. Admins visit
   `events_rsvp/admin_attendance.php`.

## Security

- Prepared statements everywhere; output escaped via `e()`.
- CSRF token on every form; rotated after submit/login.
- Event identity is re-resolved server-side on submit — the posted title is never
  trusted.
- Admin pages are token-gated with `hash_equals` and a regenerated session id.
- DB errors are logged, never shown to guests.
