# People Sign-Up (standalone)

A self-contained guest sign-up web page. It runs on the same server as the church
portal but has **no dependency** on the portal's PHP includes, styles, or runtime.
Everything it needs is under this folder.

## What it does

- **`index.php`** — quick, mobile-first guest sign-up (First name, Last name,
  Town/City, Reason for visit, Birth month + year required; Birth day + Invited by
  optional).
- **`advanced.php`** — full form with member-record fields (middle/preferred name,
  gender, full address, church background, notes). Same required core.
- **`submit_signup.php`** — validates, matches against existing members, and
  records the guest in `visitor_registrations` (visitors database). It **never**
  writes to the member database.
- **`admin_review.php`** — token-gated review console: filter by status, see live
  match suggestions, link a guest to an existing member, and mark
  reviewed / duplicate / rejected.
- **`migrate.php`** — promotes selected registrations to members (see below).

Staff also review submissions from the portal admin **Sign-ups & RSVP** page
when that wiring is used; this folder remains a standalone app with its own
admin token.

## Matching (never auto-merges)

On submit, `sg_match_members()` looks up candidates by last name / email and
classifies them:

- **exact** — name matches *and* a strong signal (email, phone, or birth
  month+year). The row is stored as `duplicate` with `matched_person_id` set so an
  admin can confirm-and-link rather than create a new person.
- **possible** — weaker overlap; shown to the admin as a suggestion only.
- **no match** — stored as `new`.

Nothing is auto-inserted into the member database. Promotion is a deliberate
admin step.

## Promotion to member

Promoting a registration creates a person in the member database (`people`), or,
when the row's **Member #** is one of the people it matches, links it to that
person without creating one. A confident match that is not linked is refused
unless forced. Then, in the visitors database, the registration is set to
`promoted` with `matched_person_id` and a `visitor_promotions` row records the
outcome (`created` or `matched_existing`).

The two databases cannot share a transaction: the person is written first. If
the visitors write then fails, the admin is told the person's id; setting
Member # to it and promoting again records the link.

## Configuration

- **`config/signup.config.json`** — non-sensitive UI settings (title, reason
  options, required-field toggles, messages). Safe to edit by hand.
- **`config/signup.secure.php`** — sensitive values (database settings, admin token).
  Returns a PHP array; it is executed, never served as text. It reads environment
  variables first, then falls back to parsing the portal `.env`, then to
  placeholder defaults.

  | Setting | Env var | Fallback |
  | --- | --- | --- |
  | Member database host/port/name/user/pass (MySQL) | `MEMBERS_DB_*` (then `DB_*`) | portal `.env` |
  | Visitors database file (SQLite, relative to the Ekklesia root) | `VISITORS_DB_PATH` | `storage/private/database/visitors.sqlite` |
  | Admin access code | `SIGNUP_ADMIN_TOKEN` | `change-me-people_signup-admin` |

  **Change `SIGNUP_ADMIN_TOKEN` before going live.**

## Install

1. The visitors database is `database/visitors/001_schema.sql` (built by
   `database/migrate/visitors_from_legacy.php`); the member database is
   `database/members/001_schema.sql`.
2. Set the database env vars (or rely on the portal `.env` fallback) and a strong
   `SIGNUP_ADMIN_TOKEN`.
3. Visit `people_signup/index.php`. Admins visit `people_signup/admin_review.php`.

## Security

- Prepared statements everywhere; output escaped via `e()`.
- CSRF token on every form; rotated after a successful submit/login.
- Admin pages are token-gated with `hash_equals` and a regenerated session id.
- DB errors are logged (`error_log`) and never shown to guests.
- `config/` holds only PHP (executed) and non-sensitive JSON.
