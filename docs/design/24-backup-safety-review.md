# Backup Safety Review — `MaintenanceBackupService` + `PrivateArchiveStore`

Reviewed against the destructive-operation and privacy checklist. Findings
below are from reading the code and exercising the store; **no destructive
restore was performed, and no backup contents appear in this document, in any
log, or in any fixture.**

## The headline: there is no restore path

The most important finding is a negative one. Searching the codebase for any
restore, import-dump or `LOAD DATA` path returns **nothing**. The service
*produces* archives; nothing consumes them. Every concern about "restoring into
the wrong database" is therefore currently unreachable.

This also corrects an earlier characterisation of mine. I described the service
as performing `DROP TABLE IF EXISTS`. **It does not.** That statement is written
*into the dump text*, exactly as `mysqldump` does, so that the file is
self-contained if a human ever replays it. The service never executes it. The
distinction matters: this is not destructive code.

**Consequence for the future:** if a restore feature is ever added, it inherits
a dump that begins with `DROP TABLE`. That is the moment to require explicit
target confirmation and fail closed on an ambiguous destination — not before.

## What the archives contain

Genuinely sensitive, and treated as such:

- **The MySQL dump** — every row of every base table, so all member data.
- **`state/members.json`** — names, email, cell phone, address, city, state,
  postcode, country, birth date, membership date.
- `state/events.json`, `state/schedules.json` — operational, low sensitivity.
- `index.json` — metadata only (category, type, relative path, byte count). **No
  PII**, which is what makes it safe for the admin listing to read.

## Protections verified

| Concern | Finding |
|---|---|
| Location | `storage/private/YYYY/mm/`, outside `public/` |
| Web reachability | Denied three ways: outside the docroot, a self-written `.htaccess` in the store, and the `storage` deny rule added to `church_portal/.htaccess` |
| File permissions | `0640` files, `0750` directories — confirmed by exercising the store |
| Path traversal (write) | `token()` strips everything but `[a-z0-9_-]`, so category/type/extension cannot escape |
| Path traversal (read) | `absolute()` rejects `..`, resolves `realpath`, and requires containment in the root. `../../.env` and `../../../../etc/passwd` were both rejected |
| Download authorization | `GET /admin/maintenance/file` requires `isPortalWideAdmin`. Verified: **403 for anonymous and for a member**, on the traversal attempts too |
| Backup / export authorization | `POST /admin/maintenance/backup` and `.../export-xlsx` both require `isPortalWideAdmin` |
| Caching | `Cache-Control: private, no-store` on download |
| Destination selection | Not user-supplied — derived from the store root and a timestamp |

## Two issues found and fixed

**1. Silent overwrite.** Filenames were precise to the minute
(`mysql.portal.08_25.1430.sql`), so two backups started within the same minute
resolved to the same path and the second **overwrote the first** — losing a
backup at exactly the moment someone was trying hard to take one. `allocate()`
now suffixes on collision; three writes in the same minute produce three
distinct files and the first stays intact.

**2. The maintenance page fetched archive metadata for non-admins.** The route
had no guard: it called `listRecent()` and the campus service before rendering.
The *template* did gate correctly (`if (!$isAdmin)`), so nothing leaked — an
anonymous request returns a sign-in prompt with no filenames and no controls,
and that was verified. But the data was being loaded into scope regardless. The
route now fetches only for a portal-wide admin, so a future template change
cannot turn a rendering detail into a disclosure.

## Accepted, with reasoning

- **Partial failure during a dump** writes a truncated `.sql`. There is no
  checksum or completion marker, so a truncated archive is not distinguishable
  from a complete one. Worth adding *if* a restore path is ever built; harmless
  while nothing consumes these files.
- **No retention policy.** Archives accumulate; `index.json` keeps only the last
  200 entries, so older files stop being listed while remaining on disk. An
  operational matter — someone should decide how long member data should sit in
  `storage/private`.
- **`ensurePrivateRoot()` writes both `Require all denied` and `Deny from all`**,
  mixing Apache 2.2 and 2.4 syntax. Harmless with `mod_access_compat` present,
  and belt-and-braces by intent.

## Handling rules for these artifacts

Backups contain member names, addresses, phone numbers and email addresses.
They must not be copied into logs, screenshots, AI review artifacts, repository
fixtures, or documentation examples — including this document, which
deliberately quotes no archive content. Anyone downloading one is handling the
congregation's personal data and should treat it accordingly.
