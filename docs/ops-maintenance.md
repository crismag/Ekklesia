# Maintenance, backups, and member import

Portal-wide admins only. UI: **Admin → Maintenance** (`/admin/maintenance`).
Dashboard settings also links here.

## Private archive

Dumps and exports are written under `storage/private/` unless
`MAINTENANCE_PRIVATE_PATH` is set. Apache is denied via `.htaccess`. The folder
is gitignored except `.htaccess`, `index.html`, and `.gitkeep`.

Name pattern:

```text
YYYY/mm/<category>.<type>.<mm_dd>.<HHMM>.<ext>
```

Example: `2026/08/mysql.people.08_25.1422.sql`

Download: `GET /admin/maintenance/file?path=` (authenticated). Paths cannot
contain `..`.

## MySQL backup

`POST /admin/maintenance/backup` with `kind=mysql` and `target` of `people`,
`portal`, or `both`. Implemented in PHP (`MaintenanceBackupService`); shared
hosting does not need `mysqldump`. Large databases may hit PHP time limits
(the handler sets 180 seconds).

## Event / schedule / member state

`POST /admin/maintenance/backup` with `kind=state` writes JSON snapshots:

| File type | Source |
|---|---|
| `state.events` | People DB: `events_event`, `event_occurrence`, `events_event_campus` |
| `state.members` | People DB: `person_per` plus primary campus and member-type custom field |
| `state.schedules` | Portal DB: `schedule_roster*` |

Missing tables are stored as empty lists.

## Styled Excel export

`POST /admin/maintenance/export-xlsx` builds a **new** Hub-like `.xlsx` from
the database (not a rewrite of the Google Drive file): forest header, frozen
title/header, autofilter, one sheet per campus (or a selected campus). Saved as
`members.roster.*.xlsx`.

`GET /admin/people/export` still downloads a CSV for a campus.

## Member import

Moved off the People page. Open **Maintenance → Member import**
(`/admin/maintenance/import`). Old `/admin/people/import` URLs redirect.

1. Apply `migrations/portal/007-member-import-staging.sql` on the **people**
   database (same connection as `person_per`).
2. Choose campus and Hub preset (North York or Scarborough). Primary Hub sheet
   is enough; secondary is optional fill-in.
3. Upload `.xlsx` or paste a Google Sheets URL (exported as xlsx). **CSV is
   refused** in the UI because merged household cells are lost.
4. Review staging: duplicates are collapsed before apply; leftover campus
   members will be **unlinked** from the campus, not deleted.
5. Apply only **ready** rows. Confirm in the UI.

Match order: email, then last name + first-name token. Hub/primary wins;
secondary fills empty fields. Secondary-only people stay **draft**. Blank Hub
cells do not wipe existing CRM email/phone/address.

CLI:

```bash
php tools/member-import.php --source=/path/to/hub.xlsx --preset=ny --dump-json
php tools/member-import.php --source=file.xlsx --preset=ny --campus-id=3 --ingest
php tools/member-import.php --apply-batch=ID --confirm=APPLY
```

`--dump-json` does not touch the database. `--apply-batch` requires
`--confirm=APPLY`.

## Hub serving ministries

Hub roster cells list teams as comma-separated tags. The unique serving list
lives in `config/ministry-catalog.json`. `GS: Usher` means **Guest Services**
with role **Usher**. `G&A` means **Gifts and Arrows**. Combined ministry phrases
(`Events & Prayer Ministry`) expand to two teams.

```bash
php tools/sync-ministry-catalog.php          # dry-run against ChurchCRM group_grp
php tools/sync-ministry-catalog.php --apply  # rename / create / deactivate
```

Renames keep the same `grp_ID`, so existing members, roles, and schedules stay
attached. Sync also ensures Guest Services has schedule roles **Usher** and
**Emcee**. It does not invent extra ministries from the `GS:` prefix.

## Before a live apply

Take a people-DB MySQL backup (and a state snapshot) on Maintenance first.
Review the duplicate warning list and understand unlink behaviour for that
campus.
