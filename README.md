# Ekklesia

The church's own system for Christlikeness Church: people and households,
ministries, the calendar, serving schedules and printables, visitor sign-ups
and RSVPs, and the admin tools around them.

Ekklesia started from the Church Portal (`crismag/ChurchPortal` at `44dcc9e`),
which stays in production and is the reference until Ekklesia replaces it. It
runs on a redesigned database instead of the ChurchCRM-derived tables: see
[database/README.md](database/README.md).

## Stack (as implemented)

This is **not** Laravel, React, or Inertia. A few Composer packages and unused
`resources/js` files remain from an early scaffold; no page loads them.

- PHP 8.2+ front controller: `public/index.php`
- Routes: `routes/web.php`, `routes/api.php` (closures)
- Views: server-rendered PHP under `resources/views/` with inline CSS/JS
- Chrome: `_portal-shell.php`, `_admin-shell.php`
- Services in `app/Services/` enforce permissions and writes
- The member database (MySQL, `MEMBERS_DB_*`) and the visitors database
  (SQLite, `VISITORS_DB_PATH`); schema in `database/`

Standalone public modules (nginx may serve them directly):

- `people_signup/` — guest sign-up
- `events_rsvp/` — event RSVP

## Local run

```bash
# Leave PORTAL_BASE_PATH empty for php -S
php -S 127.0.0.1:8765 -t public public/index.php
```

Copy `.env.example` to `.env` and set `MEMBERS_DB_*`. Build a local member
database and visitors file from legacy dumps with `database/migrate/run.sh` and
`database/migrate/visitors_from_legacy.php` (see `database/README.md`).
Production mount uses `PORTAL_BASE_PATH=/church_portal`.

## Checks

```bash
php tests/Architecture/BoundaryTest.php
php tests/Regression/run.php
node tests/Regression/source-contracts.mjs
composer test:architecture
composer test:regression
```

Browser suite: `docs/regression-harness.md`.

## Admin: Maintenance

Portal-wide admins use **Admin → Maintenance** (`/admin/maintenance`) for:

- Campus member import from a Hub `.xlsx` or Google Sheets link (staging, then apply)
- Backups of the member database (MySQL dump) and the visitors database (SQLite copy)
- JSON snapshots of events, schedules, and members
- Styled Hub-format `.xlsx` export from the database

Files are stored under `storage/private/` (or `MAINTENANCE_PRIVATE_PATH`) as
`YYYY/mm/<category>.<type>.<mm_dd>.<HHMM>.<ext>` and are not web-accessible.
Download only via `/admin/maintenance/file`.

CLI import: `php tools/member-import.php --help`  
Ministry Hub names: `php tools/sync-ministry-catalog.php` (dry-run; add `--apply`)  
Details: [docs/ops-maintenance.md](docs/ops-maintenance.md)

Schema changes after `database/members/001_schema.sql` go in
`database/members/migrations/` and are applied with `php tools/migrate.php --apply`.

## Documentation map

See [docs/README.md](docs/README.md). In-app user guide: `/docs`.
