# Architecture Debt — Direct SQL in the Service Layer

Recorded 2026-08-25, when `tests/Architecture/BoundaryTest.php` was restored to
green and the violations became visible for the first time.

## The rule

`BoundaryTest.php` enforces: **SQL is permitted only in `app/Adapters/**`** —
the source-specific boundary. `app/Http`, `app/Services`, `app/Repositories`
and `resources/js` must be source-agnostic.

## Why this went unnoticed

The suite could not even load: `FakeScheduleRepository` had drifted behind
`ScheduleRepository` (missing `fetchScheduleBoard`), and `FakeMinistryRepository`
was missing **19** contract methods. PHP raised a fatal before any assertion ran,
so the rule silently stopped being enforced and violations accumulated.

Both fakes are now contract-complete, so the rule is live again.

## Update 2026-08-25 — PR #7, and what was done about it

PR #7 introduced two new services containing direct SQL, which the ratchet
correctly rejected. They were **not** treated the same way, because they are not
the same kind of code.

### `MemberCampusImportService` — refactored, not baselined

684 lines, 18 database call sites, doing domain persistence on
`member_import_batch` / `member_import_row`. That is exactly what the rule
exists for, so it now goes through `MemberImportRepository` →
`ChurchCrmMemberImportAdapter`, following the same Contract/Repository/Adapter
shape as Ministry, Schedule, Event and Calendar. The service keeps the import
rules — parsing, merging, de-duplication, which rows are ready — and keeps a PDO
handle **only** to open a transaction around `PersonAdminService` writes, which
is orchestration rather than SQL.

Behaviour was verified by round-trip against the real schema: ingest (a
transactional multi-row write), read back, field update, `markReadyMissing`, and
discard — with the batch count returning to its starting value.

### `MaintenanceBackupService` — narrow infrastructure exception

196 lines, 5 call sites, all generic: `SHOW FULL TABLES`, `SHOW CREATE TABLE`,
`SELECT *` and `INSERT` against whatever tables happen to exist. It persists no
domain entity, so there is no repository it could sit behind. Inventing
`BackupRepository::showCreateTable(string $table)` would relocate the SQL while
abstracting nothing, and would imply a domain boundary that does not exist.

Listed in `$sqlDebtBaseline` with that justification written at the entry.
**The general rule is not relaxed** — domain persistence in a service still
fails, which is precisely why the import service was refactored instead.

**Architectural follow-up (deferred, deliberately):** the honest home for this
class is an infrastructure/database namespace, which would express the
distinction in the directory structure rather than in a comment. Moving it means
touching the provider, the routes that call it, and its namespace — churn that
is not justified while the maintenance feature is still settling. Recorded here
rather than done.

### Schema ownership

The import service also carried `CREATE TABLE IF NOT EXISTS` and an
`ensureColumn()` that issued `ALTER TABLE` — a second migration system inside
application code, free to drift from `migrations/portal/007-member-import-staging.sql`
without anyone noticing. Both are gone. The column sets were compared first and
are identical, `duplicate_report` included, so the migration is a complete
superset. Missing tables are now a loud operational failure naming the migration
to apply, verified on a database that did not have them.

## Current debt: 8 files

| File | Notes |
|---|---|
| `app/Services/MaintenanceBackupService.php` | **infrastructure exception** — generic schema/table-level operations, not domain persistence (see above) |
| `app/Services/CampusAdminService.php` | campus CRUD |
| `app/Services/ChurchCrmIdentityResolver.php` | ChurchCRM identity lookups |
| `app/Services/FamilyAdminService.php` | includes **family merge/delete** |
| `app/Services/OptionAdminService.php` | option CRUD |
| `app/Services/PersonAdminService.php` | includes **person delete** |
| `app/Services/RosterScheduleService.php` | roster persistence |
| `app/Services/SystemUserService.php` | includes **user delete** |

Roughly 51 SQL statements. Three of these services perform destructive
operations, which is why they were not refactored opportunistically.

## How the debt is contained

`BoundaryTest.php` carries an explicit `$sqlDebtBaseline` listing exactly these
seven paths. **The rule is not relaxed:**

- any **other** file containing direct SQL fails the suite;
- a listed file that has been cleaned but left on the list **also** fails,
  so the baseline can only shrink.

Both directions are verified — a temporary violation in `EventService.php` fails,
and a stale entry fails.

## Migration plan (not attempted in this run)

Per service: introduce an adapter method for each query, move the SQL there,
have the service depend on the contract, then remove the file from
`$sqlDebtBaseline` in the same change.

Suggested order — least destructive first, so the pattern is proven before the
risky ones:

1. `ChurchCrmIdentityResolver` (read-only lookups)
2. `OptionAdminService`
3. `CampusAdminService`
4. `RosterScheduleService`
5. `PersonAdminService` · `FamilyAdminService` · `SystemUserService`
   (destructive — each needs its confirmation and authorization tests re-run)

**This is a real refactor, not cleanup.** It should be its own piece of work with
its own review, not folded into UI modernization.
