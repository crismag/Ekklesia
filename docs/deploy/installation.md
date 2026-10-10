# Installation and deployment

Ekklesia is a self-hosted PHP application. This guide covers a new installation
and routine releases; migrating Church Portal records is a separate operation.

## Runtime and host

- PHP 8.2+ for web requests and CLI tools.
- MySQL/MariaDB member database, with PDO MySQL; SQLite visitors database, with PDO SQLite.
- PHP `mbstring`, ZIP and XML/SimpleXML for workbook and PowerPoint processing;
  GD for checked/re-encoded print background images.
- Writable private storage for visitors, archives, uploaded print assets and mail
  files when using the development file transport.
- HTTPS and a production web server configured for PHP and the application routes.
- SSH, rsync and a remote PHP CLI for the supplied release
  and backup workflow. Node is used for development source-contract checks.

The active front controller loads the application directly. A React/Vite build
is not required to serve the portal. See the regression harness for browser-test
and development dependencies.

## Configure an installation

Copy `.env.example` to `.env` on the host and set:

| Setting | Purpose |
| --- | --- |
| `APP_ENV=production`, `APP_DEBUG=false` | Production error handling |
| `APP_URL` | Canonical HTTPS address, including any URL mount |
| `PORTAL_BASE_PATH` | Empty for a domain root, or the installation's subpath |
| `MEMBERS_DB_*` | Member database connection |
| `VISITORS_DB_PATH` | Private SQLite file; default `storage/private/database/visitors.sqlite` |
| `MAINTENANCE_PRIVATE_PATH` | Private archive location; default `storage/private` |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Optional Google sign-in |
| `MAIL_TRANSPORT` and `MAIL_*` | Optional emailed sign-in links |
| `PORTAL_HARDCODED_ADMIN_PASSWORD` | Password of the `church admin` recovery login, at least 12 characters; unset leaves that login off |
| `PORTAL_DEFAULT_PASSWORD_TEMPLATE` | First-time password for people without a login, with `{F}` and `{L}` for their initials; unset leaves first-time sign-in off |

The two sign-in secrets exist only in the installation's `.env`; the code has
no built-in value for either. Choose your own, keep them private, and change
them by editing `.env`. The release script refuses to deploy while either is
missing from the server's `.env`, unless `EKKLESIA_ALLOW_UNSET_SIGNIN_SECRETS=1`
says that sign-in path should stay off.

Use `.env.example` and [production configuration](production-config.md) for exact
OAuth callback and mail settings. Set standalone sign-up/RSVP access credentials
according to their module guides before opening them to visitors. Keep secrets
out of version control and verify private files cannot be downloaded over HTTP.

Copy the repository's initial church/site settings into `config/` once when
setting up a new host. Subsequent releases preserve server-owned settings through
`.rsync-deploy-exclude`; do not overwrite administrator changes with repository
seed values. Configure campuses, church details and appearance for the installation.

## Initialize databases and access

1. Create an empty member database and a dedicated database user. Load
   `database/members/001_schema.sql`; `002_sheet_views.sql` defines the roster views.
2. Create the private SQLite file from `database/visitors/001_schema.sql`.
3. Inspect `php tools/migrate.php --status`. Fresh base schemas may already contain
   objects introduced by later migrations. Confirm the actual schema against
   each pending migration before recording it with `--baseline`; baseline records
   all pending files without executing them. Apply genuinely missing migrations
   with `--apply` only after resolving any overlap. Never baseline missing objects.
4. Create the first portal-wide administrator with `tools/create-admin-user.php`
   (running it without arguments prints its usage). Use a private terminal and a
   strong password, then manage further accounts from **Users & access**.
5. Confirm member and visitor connections, private-storage permissions, and the
   availability of backup tooling. Sign in and configure the church workspaces.

For Church Portal data, follow [the database migration guide](../../database/README.md)
in an isolated target. Its legacy rebuild script recreates the target database;
that script is not part of routine deployment.

## Route the web application

The repository tracks root and `public/` `.htaccess` rules for an Apache-compatible
rewrite host. They protect server-side files and preserve standalone public
entry points. A domain may need the parent site-root reference adapted to its
mount. nginx needs equivalent explicit rules because it does not read `.htaccess`.
See [routing and release details](README.md).

Verify the portal, `/docs`, `/people_signup/`, `/events_rsvp/` and printables under
the configured mount, plus denied access to `.env`, private storage and source
files. A private installation can use the optional [site gate](../../tools/site-gate/README.md);
keep its server-only lock and smoke-check cookie private.

## Routine releases

Back up the member database, visitors file and server-owned settings before a
release. Name the Ekklesia target explicitly; the release tool refuses the legacy
Church Portal folder.

```bash
export EKKLESIA_DEPLOY_REMOTE='your-ssh-alias'
export EKKLESIA_DEPLOY_PATH='/path/to/ekklesia'
export EKKLESIA_SMOKE_URL='https://your-church.example'
tools/deploy.sh --dry-run
tools/deploy.sh
```

The script verifies locally, checks remote migration history, raises a temporary
maintenance gate, syncs files, applies migrations, lifts the gate and smoke-checks
routes. It preserves server-owned settings and never uses `rsync --delete`.
A failed migration stops the release. The gate expires after 15 minutes; inspect
and repair a failed release promptly rather than treating expiry as recovery.

After release, confirm no migrations remain pending and check login, campus
selection, event/calendar visibility, a serving assignment, visitor review, print
preview and an editable PowerPoint download with authorized test data. Google
and emailed links should be checked when enabled. Avoid destructive tests on
live member records.

Rollback code by deploying an earlier compatible commit. Database migrations
are forward-only; schema/data recovery requires a reviewed repair or backup
restore. See [recovery details](README.md) and [maintenance](../ops-maintenance.md).
