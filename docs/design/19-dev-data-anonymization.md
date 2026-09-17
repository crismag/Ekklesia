# Development Database Anonymisation

**Status: tooling complete and tested; execution blocked on one grant.**

The local development database is a restore of production. It currently holds
**252 real people** with names, home addresses, three phone numbers each, two
email addresses each, and dates of birth — plus 582 live session rows and the
portal account table. Every screenshot, bug report, test fixture and AI-assisted
review taken against it is handling real congregational PII.

## The workflow

Anonymisation never runs in place:

```
SOURCE DB  --mysqldump-->  TARGET COPY  --anonymise-->  sanitised dev DB
(untouched)                                             (what dev points at)
```

`tools/anonymize-dev-db.php`

| Mode | Effect |
|---|---|
| `--plan` *(default)* | Read-only. Reports scope. Writes nothing. |
| `--copy --target=X` | Dumps source into `X`, then anonymises `X`. |
| `--anonymise --target=X` | Anonymises an already-populated copy. |
| `--verify --target=X` | Scans `X` for residual identifying data. |

Write modes additionally require `--i-understand`.

## Incident — this tool ran on production once

Worth recording, because the original design was wrong in a way that looked
right.

The gates checked that `APP_ENV` was a development value and that the database
host was loopback. Deployed to production, **both passed**: that host has
`APP_ENV=local` and its database on `127.0.0.1`. A read-only `--plan` reported
the live table counts, and the destructive `--copy` was stopped only by a MySQL
privilege error — not by anything in this file.

The flaw was the premise. **Inferring "this is not production" from
configuration cannot be made safe**, because production is free to look like
anything, and here it looked exactly like a development box.

The test is now inverted. A machine must declare itself a sanctioned target:

```
ANONYMIZE_ALLOW_DEV=1
```

Without it the tool refuses in **every mode, including read-only ones**.
Absence is refusal, and no production `.env` will contain it. A public HTTPS
`APP_URL` is also refused outright, whatever `APP_ENV` claims.

Separately, the tool is now excluded from deployment
(`.rsync-deploy-exclude`) and was deleted from the production host: a
destructive development tool has no reason to exist on a live server, however
well gated.

## Why it cannot silently touch production

The protection is structural, not merely a check. Every mutating statement in
the tool executes against a `$target` handle returned by `openTarget()`, and
that function refuses to return a handle when the target name equals the source
name. **There is no code path that can UPDATE, DELETE or TRUNCATE the source
database.** The `mysqldump` that reads the source is read-only by nature.

Five gates run before any write, each failing closed — anything that cannot be
positively confirmed as local development is treated as production:

| Gate | Refuses when |
|---|---|
| Environment | `APP_ENV` is not `local`/`dev`/`development`/`testing` |
| Host | `PORTAL_DB_HOST` is not loopback |
| Identity | `--target` equals the source database |
| Naming | `--target` lacks a `dev`/`test`/`local`/`sandbox`/`anon`/`staging` word |
| Blocklist | `--target` contains `prod`/`production`/`live`/`www` |

All five were exercised and all five refuse. Credentials are passed to
`mysqldump` through `MYSQL_PWD` in the child environment rather than on the
command line, so they cannot appear in `ps` output or shell history.

## What it produces

Deterministic (HMAC-seeded), so the same person always becomes the same
pseudonym — reviews are reproducible and screenshots stay stable across re-runs.
Rotating `ANONYMIZE_SALT` invalidates a leaked mapping.

Relationship-preserving, because a directory of unrelated random strings cannot
exercise the features being reviewed:

- Surnames derive from `family_grp_id`, so **households share a surname**
- `couples_tbl.male`/`female` regenerate from the linked person, so cached
  names agree with the people table instead of drifting
- `portal_users.display_name` follows the person the account links to
- `schedule_roster_assignment.display_name` follows the assignee
- Birth dates shift within ±150 days, keeping `birthdays_view` and age brackets
  exercisable while no real date of birth survives

Contact details are drawn from ranges that cannot reach anyone: `@example.invalid`
(RFC 2606 — undeliverable even if dev is misconfigured to send mail) and the
`(555) 01xx` fictional dialling range.

Free text (`schedule_roster.notes`, `schedule_roster_slot.notes`,
`couples_tbl.details`, audit `payload_json`) is **cleared, not rewritten** —
there is no reliable way to detect PII inside prose, so none is kept. Sessions
and tokens are emptied. Every account gets one known dev password.

The 6 views need no separate handling: they read the anonymised base tables and
are recreated by the dump.

## Verification

`tests/Regression/anonymize-logic.php` — 13 assertions, no database required,
covering determinism, salt rotation, family surname sharing, gendered pools,
name distribution (247 distinct names across 252 people), index bounds, and PII
map coverage. **13/13 passing.**

`--verify` then checks the sanitised copy on its own terms — no value is
compared against the source, so verification never re-reads real data. It
asserts: no email outside `@example.invalid`, no phone outside the 555 range,
no retained IP addresses or user agents, no surviving sessions or tokens, no
residual free text, and every surname drawn from the pseudonym pool.

`--plan` was run against the real database and reports **~3,325 values** to be
rewritten. It prints only counts and column names, never values, so its output
is safe to paste into a ticket.

## Blocker — what is needed to execute this

The development MySQL user holds:

```
GRANT USAGE ON *.* TO 'u471078694_csadmin_mdb'@'localhost'
GRANT ALL PRIVILEGES ON `u471078694_christlike_mdb`.* TO 'u471078694_csadmin_mdb'@'localhost'
```

`USAGE` on `*.*` means **it cannot create a database**, and the one database it
can write to is the source. Executing the copy therefore requires a privilege
this environment does not have.

Anonymising in place would be the only alternative, and that is a destructive
operation against the sole copy of the data. Per the standing instruction, I
stopped rather than doing that.

**One-time action needed** (as a MySQL administrator):

```sql
CREATE DATABASE `christlike_dev` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `christlike_dev`.* TO 'u471078694_csadmin_mdb'@'localhost';
FLUSH PRIVILEGES;
```

Then:

```bash
php tools/anonymize-dev-db.php --plan
php tools/anonymize-dev-db.php --copy --target=christlike_dev --i-understand
php tools/anonymize-dev-db.php --verify --target=christlike_dev
# then set PORTAL_DB_DATABASE=christlike_dev in .env
```

Until that runs, development continues against real PII. That is the single
largest outstanding privacy risk in this repository, and it is not fixable from
inside the application.
