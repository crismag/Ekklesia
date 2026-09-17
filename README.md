# Church Portal

Operational portal for Christlikeness Church: schedules, events, people, and
admin tools. Member and event rows still live in the ChurchCRM MySQL tables
(`person_per`, `events_event`, …). The portal is the product UI and owns
logins, campuses, church info, photos, import staging, and private archives.

Production: `https://christlikeness.crishub.com/church_portal/`

## Stack (as implemented)

This is **not** Laravel, React, or Inertia. A few Composer packages and unused
`resources/js` files remain from an early scaffold; no page loads them.

- PHP 8.2+ front controller: `public/index.php`
- Routes: `routes/web.php`, `routes/api.php` (closures)
- Views: server-rendered PHP under `resources/views/` with inline CSS/JS
- Chrome: `_portal-shell.php`, `_admin-shell.php`
- Services in `app/Services/` enforce permissions and writes
- Two MySQL connections: `PORTAL_DB_*` (portal tables) and `CHURCHCRM_DB_*`
  (people / events / legacy CRM tables)

Standalone apps next to the portal (nginx may serve them directly):

- `people_signup/` — guest sign-up
- `events_rsvp/` — event RSVP

## Local run

```bash
# Leave PORTAL_BASE_PATH empty for php -S
php -S 127.0.0.1:8765 -t public public/index.php
```

Copy `.env.example` to `.env` and set `PORTAL_DB_*` and `CHURCHCRM_DB_*`.
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
- MySQL dumps of the people and/or portal databases
- JSON snapshots of events, schedule rosters, and members
- Styled Hub-format `.xlsx` export from the database

Files are stored under `storage/private/` (or `MAINTENANCE_PRIVATE_PATH`) as
`YYYY/mm/<category>.<type>.<mm_dd>.<HHMM>.<ext>` and are not web-accessible.
Download only via `/admin/maintenance/file`.

CLI import: `php tools/member-import.php --help`  
Ministry Hub names: `php tools/sync-ministry-catalog.php` (dry-run; add `--apply` on the people database)  
Details: [docs/ops-maintenance.md](docs/ops-maintenance.md)

Apply `migrations/portal/007-member-import-staging.sql` on the **people**
database before first import.

## Documentation map

See [docs/README.md](docs/README.md). In-app user guide: `/docs`.
