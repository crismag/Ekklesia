# Maintenance, backups, and member import

Portal-wide admins only. UI: **Administration → Maintenance**
(`/admin/maintenance`). For what each task is for, see the
[administration and maintenance guide](features/administration-and-maintenance.md).

## Private archive

Backups and exports are written under `storage/private/` unless
`MAINTENANCE_PRIVATE_PATH` is set. Apache denies the folder via `.htaccess`, and
it is gitignored except `.htaccess`, `index.html` and `.gitkeep`.

Name pattern:

```text
YYYY/mm/<category>.<type>.<mm_dd>.<HHMM>.<ext>
```

Example: `2026/08/mysql.members.08_25.1422.sql`

Download: `GET /admin/maintenance/file?path=` (portal-wide admin). Paths cannot
contain `..`.

## Backups

`POST /admin/maintenance/backup` (the Maintenance page's buttons):

| `kind` | `target` | Writes |
| --- | --- | --- |
| `mysql` | `members` | A SQL dump of the member database (`mysql.members.*.sql`) |
| `mysql` | `visitors` | A consistent copy of the visitors SQLite database (`VACUUM INTO`) |
| `mysql` | `all` | Both of the above |
| `state` | — | JSON snapshots: `events` (events, occurrences, campuses, tags), `members` (selected person columns) and `schedules` (assignments and rosters) |

Dumps are produced in PHP (`MaintenanceBackupService`), so shared hosting does
not need `mysqldump`. The handler allows 180 seconds; very large databases may
need a host-side dump instead.

Backups run when an admin asks for them. For regular protection, also schedule
host-level database backups. There is no restore action in the application:
restore a member dump with the MySQL client on the server, and replace the
visitors SQLite file while the site is in maintenance.

## Styled Excel export

`POST /admin/maintenance/export-xlsx` builds a new roster `.xlsx` from the
database: header styling, frozen title and header rows, autofilter, and one
sheet per campus (or a selected campus). Saved as `members.roster.*.xlsx`.

`GET /admin/people/export?campus_id=` downloads a campus as CSV in the roster
column layout.

## Member import

Open **Maintenance → Member import** (`/admin/maintenance/import`). The staging
tables (`member_import_batches` and related) are part of the member schema.

1. Choose the campus and the Hub preset (North York or Scarborough). The
   primary Hub sheet is enough; a secondary sheet optionally fills gaps.
2. Upload `.xlsx` (12 MB, 2,000 rows) or paste a Google Sheets URL shared with
   anyone with the link. **CSV is refused** because merged household cells are
   lost.
3. Review staging: resolve duplicates inside the batch, map ministry names,
   and mark rows ready, draft or skip.
4. Apply. Only **ready** rows are applied, after confirmation.

Match order: email, then last name plus first-name token, plus any extra rules
chosen at upload. The primary sheet wins; the secondary fills empty fields, and
secondary-only people stay **draft**. Blank Hub cells do not wipe an existing
email, phone or address.

**Campus membership is replaced.** Existing members of the campus that no
*ready* row matches — including rows left as draft or skip — are unlinked from
the campus (not deleted). An applied batch cannot be re-applied or discarded.

CLI:

```bash
php tools/member-import.php --source=/path/to/hub.xlsx --preset=ny --dump-json
php tools/member-import.php --source=file.xlsx --preset=ny --campus-id=3 --ingest
php tools/member-import.php --apply-batch=ID --confirm=APPLY
```

`--dump-json` does not touch the database. `--apply-batch` requires
`--confirm=APPLY`.

## Hub serving ministries

Hub roster cells list teams as comma-separated tags. The serving list lives in
`config/ministry-catalog.json`. `GS: Usher` means **Guest Services** with the
role **Usher**; `G&A` means **Gifts and Arrows**. Combined phrases
(`Events & Prayer Ministry`) expand to two teams.

```bash
php tools/sync-ministry-catalog.php          # dry-run against the ministries table
php tools/sync-ministry-catalog.php --apply  # rename / create / deactivate
```

Renames keep the ministry's id, so memberships, serving roles and schedules
stay attached. Sync ensures Guest Services has the serving roles **Usher** and
**Emcee**, and does not invent ministries from the `GS:` prefix.

## Before a live apply

Take a member backup and a state snapshot on Maintenance first. Review the
duplicate list, and mark every person who belongs to the campus as ready.
