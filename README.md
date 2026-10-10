# Ekklesia

**Church coordination, member management, and calendar publishing in one application.**

Ekklesia is a production web application developed for Christlikeness Church
and in use by the church. It brings people and households, ministries, events,
serving schedules, visitor registration, and church publications into one
mobile-friendly workspace for members, leaders, schedulers, and administrators.

**Status:** in production at Christlikeness Church, and deployable by another
church on its own host. See the [feature guides](docs/features/README.md) for
everything it does, area by area.

## What the church can do

| Area | Included capabilities |
| --- | --- |
| People & households | Searchable directory with privacy for limited viewers; member records and classifications; campus affiliation; household contacts and addresses; family roles and related households; duplicate-household review and merge; record history. |
| Ministries | Campus-aware directory; ministry workspaces with overview, members and leaders, serving roles, and schedules; positions. |
| Events | Shared event editor; recurring series and dated occurrences; campus, ministry, type and tags; audience-controlled ministry and leadership activities. |
| Serving | Assign people to ministry roles on activity dates; personal schedules and recorded unavailable dates; conflict indicators; unfilled-role counts; serving grid, calendar day-panel editing and rosters. |
| Calendar | Month, Agenda, Week and Day views; events, serving assignments, birthdays, anniversaries and cached holiday layers; campus filtering; private and shared saved views. |
| Visitors & RSVPs | Public guest sign-up and event response modules; reviewer workspace; member matching and duplicate detection; reviewed visitor promotion; rotating administrative access codes. |
| Print & publications | Preview studio; reusable saved publications; six layouts; paper and orientation choices; monthly seasonal themes; custom backgrounds; browser printing/PDF; PowerPoint theme upload and editable calendar export. |
| Accounts & access | Password sign-in; optional Google and emailed sign-in links; administrator-provisioned accounts; scoped roles; first-login password changes; account activity history and last-admin safeguards. |
| Administration | Campuses, record settings, church information, announcements, appearance and navigation; staged Hub workbook/Google Sheets import; roster export; private backups and JSON snapshots. |

See the [feature guides](docs/features/README.md) for workflows and practical details.
The in-app guide at `/docs` explains the screens to church users.

## Calendar publishing

From **Calendar → Print this view**, carry the current dates and layers into a
publication. Choose a monthly or weekly calendar, agenda, Sunday schedule, year
at a glance, or ministry planner, then customize its title, notes, appearance,
and paper size. Save the recipe to reuse it next month or share it with the church.

The print studio includes automatic seasonal themes, uploaded background
pictures, and **PowerPoint-designed themes**. Download a starter, decorate it in
PowerPoint, and upload the `.pptx`; Ekklesia fills its named regions with calendar
data. **Export editable PowerPoint** produces a monthly grid with editable text
boxes and day shapes, ready for further church artwork or announcements.

Printing and PDF use the browser's print dialog. PowerPoint export always uses
the monthly grid; some built-in decorative artwork is omitted. The selected
campus remains the source of the publication, and publications never print ages.
See [Printing & Publications](resources/views/docs/sections/15-printing.md).

## Deployment and operation

Ekklesia is self-hosted: PHP 8.2+, MySQL/MariaDB for member records and SQLite
for visitor registrations and RSVPs. Set installation credentials and paths in
`.env`, keep private storage outside public access, initialize the databases,
and apply tracked migrations before serving the application.

- [Installation and deployment](docs/deploy/installation.md)
- [Production configuration](docs/deploy/production-config.md)
- [Release workflow and web-server routing](docs/deploy/README.md)
- [Database schema and legacy migration](database/README.md)
- [Backups, import and export](docs/ops-maintenance.md)

Google and emailed sign-in links become available when the host's OAuth and
mail settings are configured; they authenticate existing accounts and do not
create members or grant roles. Private installations can use the optional
[site gate](tools/site-gate/README.md) to restrict the entire site.

## Architecture

Pages are server-rendered PHP, with browser-side JavaScript and CSS. The active
runtime is the front controller in `public/index.php`, route closures in
`routes/web.php` and `routes/api.php`, views in `resources/views/`, and services
and repositories under `app/`. It does not require a React build to serve pages;
Composer and npm retain packages from the original scaffold and development tools.

`people_signup/` and `events_rsvp/` are standalone public entry points sharing
Ekklesia's databases. Production rewrite rules preserve their directory routes.
The member schema is owned by Ekklesia; ChurchCRM is not required at runtime.
Ekklesia originated from `crismag/ChurchPortal`; legacy mapping and import tools
remain available for migrating that installation.

## Local development

Copy `.env.example` to `.env`, configure a local database and private storage,
and follow the [installation guide](docs/deploy/installation.md) for schema setup.
Leave `PORTAL_BASE_PATH` empty for the built-in development server:

```bash
php -S 127.0.0.1:8765 -t public public/index.php
```

## Verification

```bash
php tests/Architecture/BoundaryTest.php
php tests/Regression/run.php
php tests/Regression/schedule-event-scope.php
node tests/Regression/source-contracts.mjs
php tools/check-contrast.php
php tools/check-privacy.php
```

Composer aliases: `composer test:architecture`, `composer test:regression`.
Browser checks and prerequisites: [regression harness](docs/regression-harness.md).
The release script runs local checks, preserves server-owned settings, applies
migrations behind a temporary maintenance gate, and smoke-checks the release.

## Documentation

[Documentation index](docs/README.md) · [Feature guides](docs/features/README.md) ·
[In-app help](resources/views/docs/sections/) · [Support](docs/help.md)

Current product documentation describes shipped capabilities. Dated design
records are retained for engineering context and future enhancements.
