# People Sign-Up (standalone)

A self-contained guest sign-up web page. It runs on the same server as the church
portal but has **no dependency** on the portal's PHP includes, styles, or runtime.
Everything it needs is under this folder.

## What it does

- **`index.php`** — quick, mobile-first guest sign-up (First name, Last name,
  Town/City, Reason for visit, Birth month + year required; Birth day + Invited by
  optional).
- **`advanced.php`** — full form with ChurchCRM-style fields (middle/nick name,
  gender, full address, church background, notes). Same required core.
- **`submit_signup.php`** — validates, matches against existing members, and
  records the guest in the flat review table `people_signup_temp`. It **never**
  writes to the main member tables.
- **`admin_review.php`** — token-gated review console: filter by status, see live
  match suggestions, link a guest to an existing member, and mark
  reviewed / duplicate / migrated / rejected.

Staff also review submissions from the portal admin **Sign-ups & RSVP** page
when that wiring is used; this folder remains a standalone app with its own
admin token.

## Matching (never auto-merges)

On submit, `sg_match_members()` looks up candidates by last name / email and
classifies them:

- **exact** — name matches *and* a strong signal (email, phone, or birth
  month+year). The row is stored as `duplicate` with `matched_member_id` set so an
  admin can confirm-and-link rather than create a new person.
- **possible** — weaker overlap; shown to the admin as a suggestion only.
- **no match** — stored as `new`.

Nothing is auto-inserted into ChurchCRM. Migration is a deliberate admin step.

## Configuration

- **`config/signup.config.json`** — non-sensitive UI settings (title, reason
  options, required-field toggles, messages). Safe to edit by hand.
- **`config/signup.secure.php`** — sensitive values (DB credentials, admin token).
  Returns a PHP array; it is executed, never served as text. It reads environment
  variables first, then falls back to parsing the portal `.env`, then to
  placeholder defaults.

  | Setting | Env var | Fallback |
  | --- | --- | --- |
  | DB host/port/name/user/pass | `CHURCHCRM_DB_*` (then `DB_*`) | portal `.env` |
  | Admin access code | `SIGNUP_ADMIN_TOKEN` | `change-me-people_signup-admin` |

  **Change `SIGNUP_ADMIN_TOKEN` before going live.**

## Install

1. Apply the schema once: `sql/001_people_signup_temp.sql` (creates
   `people_signup_temp` in the ChurchCRM database).
2. Set the DB env vars (or rely on the portal `.env` fallback) and a strong
   `SIGNUP_ADMIN_TOKEN`.
3. Visit `people_signup/index.php`. Admins visit `people_signup/admin_review.php`.

## Security

- Prepared statements everywhere; output escaped via `e()`.
- CSRF token on every form; rotated after a successful submit/login.
- Admin pages are token-gated with `hash_equals` and a regenerated session id.
- DB errors are logged (`error_log`) and never shown to guests.
- `config/` holds only PHP (executed) and non-sensitive JSON.
