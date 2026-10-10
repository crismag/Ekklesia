# Releases and collaboration workflow

How changes reach `main`, how releases are published, and where contributors
can start.

## Pull requests

- Every change reaches `main` through a pull request, including the
  maintainer's own larger changes.
- The author runs the checks for the area they changed and lists them in the
  pull request (see [CONTRIBUTING.md](../CONTRIBUTING.md#checks-to-run)). There
  is no hosted CI at present, so these checks are run locally; `tools/deploy.sh`
  runs the PHP and source-contract suites again before any deployment.
- The maintainer reviews and merges. One approving review from the maintainer
  is enough for a project this size.
- Pull requests that add a migration, change permissions or change
  configuration say so in the template's "Database and configuration" section.

## Versions and tags

Releases are tagged `vMAJOR.MINOR.PATCH` on `main`:

- **PATCH**: fixes, with no migration and no configuration change.
- **MINOR**: new capabilities, new migrations or new optional settings, with
  existing installations upgrading by the usual steps.
- **MAJOR**: changes an installation must act on (a removed setting, a manual
  data step, a changed requirement).

Ekklesia has no releases yet. The first release should be tagged once this
repository's installation and contribution documents have been checked by
someone installing from scratch.

## Release notes

Each GitHub release describes:

1. **Changes**, in plain language for church administrators.
2. **Database migrations**: the new files in `database/members/migrations/`, and
   whether they touch existing data.
3. **Configuration changes**: new, changed or removed `.env` settings and
   `config/` files, and what to set.
4. **Upgrade steps**: back up, deploy, `php tools/migrate.php --apply`, and
   anything else.
5. **Source**: the tag link, for installations to use as
   `EKKLESIA_SOURCE_URL`.

## Corresponding source for a running release

An installation that runs a release unmodified sets, in its `.env`:

```ini
EKKLESIA_SOURCE_URL=https://github.com/crismag/Ekklesia/tree/v1.0.0
EKKLESIA_SOURCE_VERSION=v1.0.0
```

An installation that runs a modified version points these at its own fork, at
the tag or commit it deploys. Update them with every deployment; the release
script does not change them. See [licensing.md](licensing.md).

## Labels

Issues and pull requests use GitHub's standard labels: `bug`, `enhancement`,
`documentation`, `good first issue`, `help wanted` and `accessibility`, plus
`question`, `duplicate`, `invalid` and `wontfix` for triage.

## Contributor opportunities

Bounded pieces of work that would help now. Comment on or open an issue before
starting, so two people do not do the same one.

1. **Maintenance gate reads a stale file time** (`good first issue`, `bug`).
   `Maintenance::expiryOf` calls `filemtime` after `is_file` without clearing
   PHP's stat cache, so an unparseable flag can be judged by a stale time. The
   check "an unparseable flag falls back to its mtime and still holds briefly"
   in `tests/Regression/maintenance-gate.php` fails today.
   *Done when* that check passes and no other check regresses.
2. **Geocoding contact is hard-coded** (`good first issue`, `enhancement`).
   The User-Agent sent to OpenStreetMap Nominatim in
   `app/Services/PersonAdminService.php` and `people_signup/geocode.php` names
   one person's address. Nominatim's policy expects each installation to
   identify itself. *Done when* the contact comes from a documented `.env`
   setting, geocoding is skipped with a clear message when it is unset, and a
   regression check covers both.
3. **OpenStreetMap attribution** (`enhancement`, `documentation`).
   Coordinates and place names come from OpenStreetMap data, whose license asks
   for visible attribution. *Done when* the screens that show geocoded results,
   or the About page, credit "© OpenStreetMap contributors" with a link.
4. **nginx configuration** (`help wanted`, `documentation`).
   Production routing rules exist only as Apache `.htaccess` files.
   *Done when* `docs/deploy/` has an nginx server block that reproduces them
   (front controller, standalone modules, denied private paths), verified on a
   real nginx with the route list in the installation guide.
5. **Fictional sample data** (`help wanted`, `enhancement`).
   A fresh installation starts empty. *Done when* an optional command loads a
   small fictional church (campuses, member types, households, ministries,
   events), refuses to run on a database that already has people, and is never
   run by installation or deployment.
6. **Unused scaffold packages** (`good first issue`).
   `composer.json` and `package.json` declare Laravel, Inertia and React, which
   the application does not use. *Done when* they are removed, the development
   tools that remain (Playwright, Vite if still needed) still work, and the
   installation guide no longer has to explain them.
