# Installation and deployment

Ekklesia is a self-hosted PHP application. This guide is written for someone
installing it without help from the people who built it. Pick the section that
matches what you are doing:

| You are | Go to |
| --- | --- |
| Setting up Ekklesia for your church or institution | [New installation](#new-installation) |
| Developing or testing on your own machine | [Development installation](#development-installation) |
| Moving records out of the legacy Church Portal / ChurchCRM | [Migrating from Church Portal](#migrating-from-church-portal) |
| Updating an Ekklesia installation that already runs | [Upgrading](#upgrading-an-existing-installation) |

Paid help with any of these is available through
[CrisHub](https://crishub.com/contact/); see [SUPPORT.md](../../SUPPORT.md).

## Requirements

- **PHP 8.2 or newer**, for web requests and the command-line tools, with these
  extensions: `pdo_mysql`, `pdo_sqlite`, `mbstring`, `zip`, `xml` (SimpleXML
  and DOM), `gd` (print backgrounds) and `openssl`. `curl` or `allow_url_fopen`
  is needed only for optional geocoding, holiday refresh and Google sign-in.
- **MySQL 8 or MariaDB** for the member database. The new-installation steps
  below were verified with MariaDB 10.11 and PHP 8.3.
- **SQLite** (through `pdo_sqlite`) for the visitors database.
- **A web server that runs PHP**: Apache with `mod_rewrite` uses the tracked
  `.htaccess` files as they are. nginx needs equivalent rules (see
  [routing](#web-server-routing)).
- **HTTPS** for any installation people reach over a network.
- **Private storage** outside the web root, or blocked from it: the visitors
  database, backups, uploaded print assets and mail files live there.
- For the supplied release script only: SSH, rsync and PHP on the server.

The application does not need Composer or npm to run. `composer.json` and
`package.json` list packages from an earlier scaffold and development tools;
nothing in them is loaded by the running site.

## Configuration

Copy `.env.example` to `.env` in the installation's root and fill it in. `.env`
holds secrets: never commit it, and make sure it cannot be downloaded (the
tracked `.htaccess` files deny it).

| Setting | Purpose |
| --- | --- |
| `APP_ENV=production`, `APP_DEBUG=false` | Production error handling. Never leave `APP_DEBUG` on for a reachable site. |
| `APP_URL` | The canonical HTTPS address, including any subpath |
| `PORTAL_BASE_PATH` | Empty for a domain root, or the subpath, for example `/ekklesia` |
| `MEMBERS_DB_*` | Member database connection |
| `VISITORS_DB_PATH` | Private SQLite file; default `storage/private/database/visitors.sqlite` |
| `MAINTENANCE_PRIVATE_PATH` | Private archive location; default `storage/private` |
| `PORTAL_HARDCODED_ADMIN_PASSWORD` | Password of the `church admin` recovery login, at least 12 characters; unset leaves that login off |
| `PORTAL_DEFAULT_PASSWORD_TEMPLATE` | First-time password for people without a login, with `{F}` and `{L}` for their initials; unset leaves first-time sign-in off |
| `SIGNUP_ADMIN_TOKEN`, `RSVP_ADMIN_TOKEN` | Master keys for the sign-up and RSVP review pages |
| `EKKLESIA_SOURCE_URL`, `EKKLESIA_SOURCE_VERSION` | Where users get the source of the version you run; see [licensing](../licensing.md) |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Optional Google sign-in |
| `MAIL_TRANSPORT` and `MAIL_*` | Optional emailed sign-in links |

The sign-in secrets exist only in `.env`; the code has no built-in value for
either. Choose your own and keep them private. `.env.example` explains each
setting; [production configuration](production-config.md) covers Google and
mail in detail.

**Time zone.** Set the church's time zone under **Administration → Church
information** (stored as `timeZone` in `config/church-info.json`, for example
`America/Toronto` or `Asia/Manila`). Every date and time follows it: what is
stored, what "today" and "upcoming" mean, and what every visitor's browser
shows, wherever they are. The server's own time zone does not matter, so the
same installation works on any host. **Administration → System** shows the
zone in use, the server's own zone and how the database connection was set;
installing and every deployment also report it.

Church-specific settings live in `config/` (church name and address, theme,
announcements, ministries). The repository's copies are Christlikeness Church's
starting values: replace the church name, logo (`public/images/`) and details
with your own. After the first setup, `config/` belongs to the installation;
the release script does not overwrite it.

## New installation

1. **Put the code on the server.** Download a release, or clone the repository,
   into a folder outside the public web root if your host allows it.
2. **Create an empty member database** and a database user that has full rights
   on it only.
3. **Create `.env`** from `.env.example` (see [Configuration](#configuration)).
4. **Initialize the databases:**

   ```bash
   php tools/install-database.php            # shows what it will do
   php tools/install-database.php --install
   ```

   It loads `database/members/001_schema.sql` and `002_sheet_views.sql`, then
   checks each migration in `database/members/migrations/` against the database
   it built. A migration whose tables and columns are all present is recorded as
   included; one with none present is applied; anything else stops the tool. It
   also creates the visitors database when that file does not exist yet.
   It refuses a member database that already has tables, and never drops or
   overwrites anything.
5. **Confirm the schema is current:** `php tools/migrate.php --status` should
   list every migration as adopted or applied, with nothing pending.
6. **Create the first administrator** in a private terminal:

   ```bash
   php tools/create-admin-user.php admin@your-church.example 'a-long-unique-password' 'Your Name'
   ```

   The password appears in your shell history; clear it afterwards, or change
   the password after the first sign-in. Manage further accounts from
   **Users & access** in the portal.
7. **Route the web server** to the application (see
   [Web server routing](#web-server-routing)) and **make private storage
   writable** by the web server user: `storage/private/` (or your
   `MAINTENANCE_PRIVATE_PATH`) and the visitors database file.
8. **Sign in and set up the church**: first the time zone and church details
   under **Administration → Church information**, then campuses, member types
   and other lists, appearance, and finally people, households and ministries.
   A new installation starts empty.
9. **Verify** (see [Checking an installation](#checking-an-installation)).

## Development installation

Follow [CONTRIBUTING.md](../../CONTRIBUTING.md#development-setup). In short: a
local MySQL/MariaDB database, `.env` from `.env.example`, steps 4–6 above, then
`php -S 127.0.0.1:8765 -t public public/index.php` with `PORTAL_BASE_PATH`
empty. PHP's built-in server serves the portal only; the standalone sign-up and
RSVP modules need a web server with the rewrite rules. Use fictional data only.

## Migrating from Church Portal

Ekklesia started as Church Portal, which sat on a ChurchCRM database. Moving
those records is a separate, one-time operation, described in the
[database guide](../../database/README.md). Its rebuild script **drops and
recreates** the target member database, so:

- run it only into a new, isolated database, never into an installation people
  are using;
- back up everything first;
- review the verification report before pointing the portal at the result.

A new church without Church Portal data never needs these scripts.

## Upgrading an existing installation

1. **Back up** the member database, the visitors database file and the
   server's `config/` and `.env`.
2. **Read the release notes** for new migrations and configuration changes.
3. **Deploy the new code.** With the supplied script:

   ```bash
   export EKKLESIA_DEPLOY_REMOTE='your-ssh-alias'
   export EKKLESIA_DEPLOY_PATH='/path/to/ekklesia'
   export EKKLESIA_SMOKE_URL='https://your-church.example'
   tools/deploy.sh --dry-run
   tools/deploy.sh
   ```

   It runs the checks locally, refuses a migration that changed after it was
   applied, refuses while a sign-in secret is missing from the server's `.env`
   (unless `EKKLESIA_ALLOW_UNSET_SIGNIN_SECRETS=1`), raises a maintenance gate,
   syncs files without `--delete` and without touching `.env`, `config/` or
   private storage, applies migrations, lifts the gate and checks key routes.
   Without the script: copy the code, keeping `.env`, `config/` and
   `storage/private/`, then run `php tools/migrate.php --apply`.
4. **Update `EKKLESIA_SOURCE_URL`** and `EKKLESIA_SOURCE_VERSION` to the version
   you now run.
5. **Check** that `php tools/migrate.php --status` reports nothing pending, then
   go through [Checking an installation](#checking-an-installation).

Migrations are forward-only. To roll back code, deploy an earlier compatible
version; recovering schema or data needs a backup restore or a reviewed repair.
See [release and recovery details](README.md) and
[maintenance](../ops-maintenance.md).

## Web server routing

The repository tracks two `.htaccess` files for Apache-compatible hosts:

- the **root** `.htaccess` serves real files, passes the standalone modules
  (`people_signup/`, `events_rsvp/`, `printable/`) through, and sends
  everything else to `public/`;
- `public/.htaccess` sends requests that are not real files to `index.php`.

Both deny server-side files. For a subpath installation, set
`PORTAL_BASE_PATH` and `APP_URL` to match. nginx does not read `.htaccess`, so
it needs equivalent rules; [README.md](README.md) explains each rule, and
`docs/nginx_christlikeness_local.md` is a local development example.

### Intranet installations

An installation reachable only on your organization's network is set up the
same way. Use HTTPS on the intranet too where you can, set `APP_URL` to the
address people actually use, and note that the AGPL source-code offer still
applies to people who use a modified version over that network (see
[licensing](../licensing.md)). Geocoding, holiday refresh and Google sign-in
need outbound internet access; without it they are simply unavailable.

### Restricting the whole site

The optional [site gate](../../tools/site-gate/README.md) puts a password in
front of every page, for an installation that should not be visible at all
until people sign in.

## Checking an installation

- The portal, `/login`, `/docs` and `/source` load under your address and
  subpath; `/people_signup/` and `/events_rsvp/` load if you use them.
- `/.env`, `/storage/private/`, `/database/` and `/app/` are **not**
  downloadable.
- `php tools/migrate.php --status` reports nothing pending.
- **Administration → System → Time zone** names the church's zone, not the
  default, and shows the church's current time correctly.
- You can sign in, choose a campus, see the calendar, assign someone to serve,
  review a visitor, preview a print layout and download an editable PowerPoint.
- Google sign-in and emailed links work, if you enabled them.
- `/source` shows the source of the version you run.

Use fictional or authorized test records; never test destructively on live
member records.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| `install-database.php` says the database already has tables | It only initializes an empty database. For an existing installation use `tools/migrate.php`. |
| It stops on a migration that is "only partly in the base schema" | The repository's base schema and that migration disagree. Report it; empty the database before trying again. |
| Every page returns 404 or the directory listing | The rewrite rules are not active (Apache `AllowOverride`, `mod_rewrite`, or the nginx rules). |
| Links point to the wrong address | `APP_URL` and `PORTAL_BASE_PATH` must match the real address and subpath. |
| Sign-up or RSVP pages are missing | They need the web server's rewrite rules; PHP's built-in server does not serve them. |
| The `church admin` login does not work | `PORTAL_HARDCODED_ADMIN_PASSWORD` is unset or shorter than 12 characters. |
| First-time sign-in with an email is never offered | `PORTAL_DEFAULT_PASSWORD_TEMPLATE` is unset, or lacks `{F}` or `{L}`. |
| Uploads or the visitors database fail | The web server user cannot write to private storage. |
