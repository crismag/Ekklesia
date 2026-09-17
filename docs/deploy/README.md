# Production web-server configuration

## The core divergence

**Local development runs nginx. Production runs Apache.**

The two are configured by entirely different mechanisms with no shared source:

| | Local | Production |
|---|---|---|
| Server | nginx | Apache + `mod_rewrite` |
| Config | `/etc/nginx/sites-available/christlikeness` (outside the repo) | three `.htaccess` files |
| Doc root | `/mnt/ai/workspaces/christlikeness` | `~/domains/crishub.com/public_html/christlikeness` |

Until now **none of the production routing rules were tracked in the
repository**. They existed only on the server. That has a concrete cost: routing
behaviour cannot be reviewed, cannot be restored if lost, and — most
importantly — **cannot fail locally before it fails in production**.

That is not hypothetical. Two outages in this codebase came from exactly this
gap, and neither was reproducible on a developer machine:

- `/people_signup/` returned a JSON 404 in production only. The app-level
  rewrite passed through real *files* (`-f`) but not *directories*, so a
  directory URL fell through to the portal front controller, which has no such
  route.
- `/events_rsvp/` returned a bare Apache 403, because the directory had no
  index to serve (fixed separately by adding `events_rsvp/index.php`).

The three files are now tracked, byte-identical to what production serves. They
contain routing rules only — **no credentials, no secrets** — so they are
committed as real files rather than as `.example` templates. Two live inside the
application and deploy with it; the third sits above the repository root and is
kept here as a reference copy.

## The three files

### 1. `docs/deploy/site-root.htaccess` → deploys to `christlikeness/.htaccess`

Above the repository root, so it is a **reference copy, not a deployed file**.
Changes here must be applied to the server by hand.

- Corrects browser-cached `/public/...` URLs with a 301
- Passes `church_portal` and `churchcrm` through to their own `.htaccess`
- Redirects the bare site root to `/church_portal`

### 2. `.htaccess` (repository root) → `church_portal/.htaccess`

The application boundary, and the file that governs which of the four
applications answers a request:

- Real files are served directly (`-f`)
- `/` goes to the front controller
- **`people_signup`, `events_rsvp` and `printable` are passed through** — these
  are separate applications, not portal routes. This rule is what prevents the
  directory-404 class of bug above; it must not be removed.
- Everything else is rewritten into `public/`

### 3. `public/.htaccess`

A standard front-controller configuration: disables `MultiViews` and indexes,
preserves the `Authorization` header, strips trailing slashes on non-directories,
and sends anything that is not a real file or directory to `index.php`.

## What is still not reproducible locally

Tracking these files makes production routing **reviewable and restorable**. It
does not make it **testable** — nginx does not read `.htaccess`, so a change to
these rules still cannot be exercised on a developer machine.

The local nginx config reaches the same destinations by different means: `^~`
prefix locations for the standalone apps, and an `alias` plus a named
`@church_portal_front` location for the portal. The two configurations are
maintained in parallel by hand and can drift.

Closing that gap properly would mean running Apache locally — a container or a
second vhost. That is a real change to the development environment and is
**deferred as an owner decision**, not undertaken here.

**Until then, any change to routing must be verified against production after
deploy**, not only locally. The four URLs that exercise each distinct path:

```
/church_portal/                  portal front controller
/church_portal/people_signup/    standalone app, directory URL
/church_portal/events_rsvp/      standalone app, directory URL (302 -> event.php)
/church_portal/printable/        standalone app
```

A response of `403`, or a JSON `no route` body, means the passthrough rule in
`.htaccess` has been lost.

## Deployment command

Production is a plain file tree (no `.git`, its own `.env`). Deploy with rsync,
using the exclude file — **never with `--delete`**:

```bash
rsync -az --exclude-from=.rsync-deploy-exclude ./ Hostinger:<production path>/
```

Then verify by comparing md5 of the changed files, and smoke-test the routes
listed above.

### Settings the portal writes, and why deploy must not carry them

Seven files under `config/` are edited through the admin screens and belong to
the server that owns them:

```
announcements.json  chrome.json   church-info.json  hero.json
ministries.json     people.json   theme.json
```

They used to ship from the repository on every deploy, so a change made in
`/admin/header` survived until the next release and then silently reverted.
That is what kept removing Events from the main menu: it was being added on the
site and overwritten from the repo hours later, with nothing to say so.

They are excluded now. The copies in the repository stay as the starting point
for a **new** installation — copy them across once, by hand, when first setting
a server up, and never again:

```bash
rsync -az config/{announcements,chrome,church-info,hero,ministries,people,theme}.json \
  Hostinger:<production path>/config/
```

Everything else under `config/` is shipped data rather than settings —
`ca-postal-areas.json`, `ministry-catalog.json`, `address-cities.json`,
`member-type-*.json`, `classification-status.json` — and is deployed normally.
Those are regenerated by tools and belong under version control.

### Why there is an exclude file

`tools/anonymize-dev-db.php` was deployed to production once, and **it ran
there**. Its gates checked `APP_ENV` and required a loopback database host —
and the production host has `APP_ENV=local` with its database on `127.0.0.1`,
so both passed. A read-only `--plan` reported the live table counts, and the
destructive `--copy` was stopped only by a MySQL privilege error.

Two lessons, both now enforced:

1. **Never infer "this is not production" from configuration.** Production is
   free to look like anything. The tool now requires a machine to declare itself
   a sanctioned target with `ANONYMIZE_ALLOW_DEV=1`, and refuses in every mode —
   including read-only ones — without it. Absence is refusal.
2. **Do not ship development tooling to a live server.** Even a well-gated
   destructive tool has no reason to exist there.

`APP_ENV=local` on the production host is itself worth correcting; it is the
kind of value other code may reasonably trust. That is an owner decision, since
changing it may affect error reporting or other behaviour.

## Security hardening in `church_portal/.htaccess`

The passthrough rule (`RewriteCond %{REQUEST_FILENAME} -f`) serves **any real
file**, which meant the entire server-side tree was reachable over HTTP — and
Apache executed the PHP among it. Verified against production before the fix:

| URL | Was | Now |
|---|---|---|
| `/app/Services/AuthService.php` | 200 (executed) | 403 |
| `/routes/web.php` | 200 (executed) | 403 |
| `/tools/check-privacy.php` | 200 (executed) | 403 |
| `/tools/create-admin-user.php` | 500 (executed, missing CLI args) | 403 |
| `/tools/reset-portal-password.php` | 500 (executed, missing CLI args) | 403 |
| `/migrations/…​.sql` | 200 (schema served as text) | 403 |
| `/config/ministries.json`, `/config/church-info.json` | 200 | 403 |
| `/resources/views/*.php` | 200 (templates executed) | 403 |
| `/docs/**.md`, `composer.json`, `package.json` | 200 | 403 |

The two password/admin scripts are the ones that matter: they were being
executed by the web server, and only failed because they expect CLI arguments.

Deny rules run **before** the passthrough, and cover `app`, `bootstrap`,
`config`, `migrations`, `resources`, `routes`, `storage`, `tests`, `tools`,
`design-system`, `vendor`, `node_modules`; documentation files; repository
metadata; dotfiles (except `.well-known`); and backup/dump extensions.

`docs/` needs care: `/docs/<section>` is a **portal route** while `docs/` is
also a real directory. A blanket deny would break the route, so only real files
with an extension are denied — the route has none and still falls through to the
front controller. Verified: `/docs`, `/docs/navigation` and `/docs/permissions`
all still return 200.

**This cannot be tested locally** (nginx does not read `.htaccess`), so the
change was deployed on its own, with `.htaccess.pre-hardening` kept on the
server as a rollback, and all 17 portal routes plus the standalone apps
re-checked immediately afterwards.

## Deploying

**Use `tools/deploy.sh`.** It runs the migration as a stage of the deployment
rather than as a line in this document that somebody has to remember.

```bash
tools/deploy.sh              # verify, gate, sync, migrate, release, smoke
tools/deploy.sh --dry-run    # show what would sync and what would migrate
tools/deploy.sh --skip-tests # sync without the local gate — say why
```

Six stages, in this order and no other:

| | stage | on failure |
|---|---|---|
| 1 | local suites must be green | stops; nothing leaves the machine |
| 2 | read the server's migration state | stops on a checksum mismatch |
| 3 | raise the maintenance gate | stops |
| 4 | `rsync` the tree (never `--delete`) | stops, gate stays up |
| 5 | `migrate.php --apply`, then confirm nothing pending | **stops, gate stays up, release is not live** |
| 6 | lift the gate, smoke-check four URLs | reports a bad status code |

Stage 3 is the part that is easy to skip and should not be. `rsync` overwrites
files in place — there is no atomic release swap on shared hosting — so between
stages 4 and 5 the site is running new code against a schema its migration has
not reached. The gate closes that window.

The gate fails open by design. It is a file, `storage/maintenance.flag`, whose
first line is a timestamp, and `App\Core\Maintenance` ignores it once it is
more than **15 minutes** old. A deploy that dies halfway — a dropped connection,
a closed laptop — cannot leave the church's site dark. Clear it early with:

```bash
ssh Hostinger 'rm -f <deploy-path>/storage/maintenance.flag'
```

While it is up the portal answers **503** with `Retry-After` and `Cache-Control:
no-store` — a short page for browsers, `{"kind":"maintenance"}` for the API — so
nothing caches the outage and clients back off rather than hammer the server.
The flag is server-owned and excluded from `rsync`; a local copy must never
travel.

The target must be named every time with `EKKLESIA_DEPLOY_REMOTE`,
`EKKLESIA_DEPLOY_PATH` and `EKKLESIA_SMOKE_URL`; there are no defaults, and the
script refuses a path ending in `church_portal`, the Church Portal's production
folder.

### Rollback and recovery

Files roll back by deploying an earlier commit. **Schema does not.** Migrations
here are forward-only: there are no down-scripts, and a column that has been
added stays. That is deliberate — a generated rollback is untested code run at
the worst possible moment — but it means schema changes have to be additive
enough that the previous release can still run against them. Migration 010 is
the shape to copy: a nullable column whose absence means exactly what the old
behaviour meant.

If a migration fails mid-run, nothing after it was attempted and it was not
recorded, so `--apply` can be run again once the cause is fixed. If a migration
half-succeeded inside a single file, repair it by hand and use `--baseline` to
record it, rather than editing the history table directly.

### Running migrations by hand

Still possible, and still correct when you are inspecting rather than releasing:

```bash
php tools/migrate.php --status    # what is applied, what is pending
php tools/migrate.php --apply     # apply everything pending, in order
```

It is safe to run when nothing is pending — it exits without touching the
database.

### This is not hypothetical — it has now happened twice

In the Church Portal, the member-import migration was never applied anywhere,
and the import failed the moment application code stopped creating its tables at
runtime.

Then the event-type audience migration arrived with a pull request
and was not applied to production. The calendar query joins that column, so the
query threw and **the calendar showed no events and no birthdays at all** — with
no error visible to the user. The data was intact the whole time.

Both were invisible for the same reason: nothing recorded what had been applied.
Run `--status` after every deploy; it takes a second and answers the question.

### Why a runner exists

In the Church Portal, a member-import migration sat in the repository for weeks
without ever being applied to the deployed database, and **nothing could have
told us**: there was no record of which migrations had run. It stayed invisible
because the import service created its staging tables at runtime, so the
migration was never actually needed. When that runtime DDL was removed — schema
belongs to migrations, not to request handlers — the gap surfaced as a hard
failure in the middle of a member import.

### Where migrations live

The member database starts from `database/members/001_schema.sql`. Every later
change is a file in `database/members/migrations/`, applied to the member
database (`MEMBERS_DB_*`). The visitors SQLite file has its own schema in
`database/visitors/001_schema.sql`.

### How it behaves

- Applies in filename order and stops on the first failure.
- **A failed migration is never recorded as applied**, so a re-run retries it.
- Records `filename`, a SHA-256 `checksum` and `applied_at` in
  `schema_migrations` in the member database.
- An already-applied migration is skipped, not re-executed.
- A migration edited after being applied shows as `CHANGED SINCE APPLIED`
  rather than silently diverging.
- `--baseline` records pending migrations as applied **without running them**,
  for adopting a database whose schema was created by hand. Use it only after
  confirming the objects genuinely exist: *a missing history record is not the
  same as missing schema.*

## Postal area index

`config/ca-postal-areas.json` maps every Canadian forward sortation area (the
first three characters of a postal code) to a place, a province and a centroid.
It is built from the GeoNames postal dump, which is published under the
Creative Commons Attribution 4.0 licence — credit belongs to
[GeoNames](https://www.geonames.org/).

Rebuild it with:

```bash
php tools/build-postal-index.php
```

It is committed rather than fetched at runtime: address parsing must work with
no network, and the file is small enough that pinning a known-good copy is
worth more than always having the newest one. Rebuild when the roster spreads
somewhere the file names poorly.

The reason it is worth having at all is that it answers offline what we would
otherwise ask a geocoder — 98% of the workbook's addresses get their city and
province from it — and it answers one thing better. Toronto amalgamated North
York, Scarborough, Etobicoke and East York in 1998, so Nominatim and Photon
both say "Toronto" for all of them. GeoNames keeps the borough, so this file
says "Scarborough", which is what the roster says and what the campuses are
named after.

## Holiday calendars

`config/holidays.json` holds the public holidays drawn on the calendar — one set
per country or province, each a layer viewers can switch off. It is shipped
data, not a setting, so it deploys normally.

Managed from **Administration → Calendar & events → Calendar**: add a country,
sync it, hide it, remove it. The command line does the same thing for a server
being set up before anyone can sign in:

```bash
php tools/fetch-holidays.php                                    # what is cached
php tools/fetch-holidays.php --country=CA --region=CA-ON --write
php tools/fetch-holidays.php --country=PH --label="Philippine holidays" --write
php tools/fetch-holidays.php --refresh --write                  # top every set up
php tools/fetch-holidays.php --remove=us
```

Because the file is now edited from the admin screens, it is **excluded from
deploy** like the other settings — see above. The repository copy is the
starting point for a new installation only.

Source: [Nager.Date](https://date.nager.at), a public holiday API needing no key.

Holidays are **fetched, not computed**. Deriving Ontario's list from its rules
looks straightforward and gets it wrong: it produces Easter Monday, which is a
federal-employee holiday, and Remembrance Day, which is statutory in nine other
provinces but not here. Legislatures also change their minds — National Day for
Truth and Reconciliation did not exist before 2021.

They are **cached, not fetched at render time**, because the calendar must draw
with no network and a year-old holiday list is still a correct holiday list.
Each set holds six years; re-run `--refresh --write` once a year. A set whose
cache has run out is reported by `HolidayCalendars::exhausted()` rather than
silently showing an empty calendar.
