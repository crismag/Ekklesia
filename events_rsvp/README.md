# Events RSVP (standalone)

A self-contained RSVP web page. It runs on the same server as the church portal
but has **no dependency** on the portal's PHP includes, styles, or runtime.

## What it does

- **`event.php?event_id=123`** — RSVP form for a single event. The event is always
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

- **Confident member match** → recorded as `person_type = member` with `member_id`.
- **Anyone else** → staged in `people_signup_temp` (`source = rsvp`,
  `source_event_id` set) and recorded as `person_type = new_signup` with
  `signup_id`. Guests are **never** auto-added to the main member tables — the
  People Sign-Up admin review is where staged people get promoted.

Every attendance row also keeps name/city/email/phone **snapshots** so the list is
readable even before anyone is linked.

## Event source

`config/rsvp.config.json` → `event_source`:

- `churchcrm` (default) — reads `events_event` and shows the soonest occurrence
  that is today or later.
- `rsvp_events` — reads this module's own lightweight `rsvp_events` table (for
  events that don't live in ChurchCRM).

## Configuration

- **`config/rsvp.config.json`** — non-sensitive UI settings (title, which fields to
  show, `allow_maybe`, messages, `event_source`).
- **`config/rsvp.secure.php`** — DB credentials + admin token. Executed, never
  served as text. Env first, then portal `.env`, then defaults.

  | Setting | Env var | Fallback |
  | --- | --- | --- |
  | DB host/port/name/user/pass | `CHURCHCRM_DB_*` (then `DB_*`) | portal `.env` |
  | Admin access code | `RSVP_ADMIN_TOKEN` | `change-me-events_rsvp-admin` |

  **Change `RSVP_ADMIN_TOKEN` before going live.**

## Install

1. Apply `sql/001_rsvp_tables.sql` once (creates `rsvp_events` and
   `rsvp_attendance` in the ChurchCRM database).
2. Set DB env vars (or rely on the portal `.env` fallback) and a strong
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
