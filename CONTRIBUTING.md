# Contributing to Ekklesia

Thank you for helping. Ekklesia is a church management application in daily use
at Christlikeness Church, and other churches can install it for themselves.
Improvements that help one church usually help others, so contributions of every
size are welcome.

## Ways to help

- **Fix bugs**, small or large.
- **Build features** that serve real church workflows.
- **Improve documentation**: installation, the feature guides, the in-app help.
- **Improve usability and accessibility**: keyboard use, screen readers,
  contrast, small screens.
- **Add tests** for behavior that is not yet covered.
- **Improve installation and hosting**: other web servers, intranet setups,
  clearer troubleshooting.
- **Describe a real church need.** An issue explaining how your church schedules
  volunteers, tracks visitors or prints its calendar is valuable even without
  code.
- **Propose translations.** Ekklesia is English-only today; there is no
  translation system yet. If you want to help build one, open an issue first.

Some bounded starting points are listed under
[contributor opportunities](docs/releasing.md#contributor-opportunities).

## How to contribute

1. **Fork** the repository and create a branch from `main`.
2. **Small fixes and documentation improvements** can go straight to a pull
   request.
3. **Discuss first** in an issue before you start on:
   - substantial features;
   - database schema changes;
   - new dependencies (Composer, npm or external services);
   - changes to permissions, roles or who can see personal information.

   This saves you from building something that cannot be merged as it is.
4. **Keep pull requests focused**: one problem or feature per PR.
5. **Describe** the problem, the behavior after your change, and how you
   verified it. The pull request template asks for these.
6. **Include screenshots** for visible changes, using fictional or anonymized
   data only.

## Development setup

You do not need access to any church's installation to contribute. Everything
below runs on your own machine with fictional data.

**Requirements:** PHP 8.2 or newer with `pdo_mysql`, `pdo_sqlite`, `mbstring`,
`zip`, `xml` (SimpleXML/DOM) and `gd`; MySQL 8 or MariaDB (the setup below was
verified with MariaDB 10.11); Node.js (current LTS) for the source-contract and
browser checks.

```bash
git clone https://github.com/<you>/Ekklesia.git
cd Ekklesia
cp .env.example .env              # then set MEMBERS_DB_* for a local database
php tools/install-database.php    # shows what it will do
php tools/install-database.php --install
php tools/create-admin-user.php you@example.org 'a-long-local-password' 'Your Name'
php -S 127.0.0.1:8765 -t public public/index.php
```

Open <http://127.0.0.1:8765/> and sign in. Leave `PORTAL_BASE_PATH` empty for
the built-in server. The built-in server serves the portal only; the standalone
sign-up and RSVP modules need a web server with the repository's rewrite rules
(see the [installation guide](docs/deploy/installation.md)).

The databases start empty: no campuses, member types, people or ministries.
Create a campus and the lists you need under **Administration**, then add
fictional people, households and ministries through the portal. There is no
sample-data generator yet; building a safe one is listed among the
[contributor opportunities](docs/releasing.md#contributor-opportunities).

## How the code is organized

Ekklesia is server-rendered PHP with browser JavaScript and CSS. It does **not**
use Laravel, React or Inertia at run time; those packages in `composer.json`
and `package.json` are left over from the original scaffold.

| Path | What lives there |
| --- | --- |
| `public/index.php` | The front controller |
| `routes/web.php`, `routes/api.php` | Route closures for pages and JSON endpoints |
| `resources/views/` | Page templates and the in-app help (`resources/views/docs/sections/`) |
| `app/Services/` | Business rules and permission checks |
| `app/Repositories/`, `app/Contracts/` | Data access, through contracts |
| `app/Adapters/` | The only place SQL is allowed |
| `people_signup/`, `events_rsvp/` | Standalone public modules for guest sign-up and event RSVPs |
| `database/members/` | MySQL/MariaDB schema and migrations |
| `database/visitors/` | SQLite schema for visitor sign-ups and RSVPs |
| `tools/` | Command-line tools: install, migrate, deploy, import, checks |

The architecture test enforces the main boundaries: controllers do not reach
repositories or adapters directly, SQL stays in `app/Adapters/`, and services
check permissions. A small list of older services with direct SQL is tracked as
debt; that list may only shrink.

## Database changes

- Add a new file in `database/members/migrations/`, named
  `NNN-what-it-does.sql`. Never edit a migration that has been released:
  installations record a checksum and the release script refuses a changed one.
- Update `database/members/001_schema.sql` in the same change, so a fresh
  installation matches. `tools/install-database.php` checks that every
  migration's tables and columns are in the base schema.
- Guard drops with `IF EXISTS`; never `TRUNCATE`.
- Say in the pull request what the migration changes and whether it touches
  existing data.

See [database/members/migrations/README.md](database/members/migrations/README.md).

## Checks to run

```bash
php tests/Architecture/BoundaryTest.php
php tests/Regression/run.php
node tests/Regression/source-contracts.mjs
php tools/check-contrast.php
php tools/check-privacy.php
```

Run the checks for the area you changed, and say which ones you ran in the
pull request. The browser tests (`npx playwright test`) are described in the
[regression harness](docs/regression-harness.md). Add or update a regression
check for behavior you introduce or fix.

## Data and secrets

- **Never contribute real member records**, not even a few rows. Use fictional
  names, `example.com`/`example.org` email addresses and 555-01xx phone
  numbers.
- **Never commit credentials**: no `.env` files, passwords, tokens, API keys
  or private database dumps.
- **Redact logs and screenshots** before attaching them to issues or pull
  requests: names, contact details, birthdays, addresses and anything else
  personal.
- If you notice personal data or a secret in the repository, do not repeat it
  publicly; report it privately as described in [SECURITY.md](SECURITY.md).

## Ownership and review

- You keep the copyright in your contributions.
- By submitting a contribution, you license it under the repository's
  `AGPL-3.0-only` license. Material from third parties keeps its own license;
  say so in the pull request if you include any.
- Only submit work you have the right to submit.
- There is no contributor license agreement and no copyright assignment.
- The maintainer decides whether a change fits the project and can be
  maintained. A pull request may be declined or need changes; contributing does
  not guarantee acceptance or a review deadline.

See [GOVERNANCE.md](GOVERNANCE.md) for how decisions are made and
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) for how we treat each other.
