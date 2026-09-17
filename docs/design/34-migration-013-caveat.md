---
id: 34-migration-013-caveat
title: Migration 013 — the bootstrap does not protect a later "off"
date: 2026-08-28
status: known-issue
audience: [claude, cursor, dba]
related:
  - migrations/portal/013-assignment-scheduling-scope.sql
  - docs/design/32-incomplete-capabilities.md
---

# Migration 013 — the bootstrap does not protect a later "off"

## What the file claims

`013-assignment-scheduling-scope.sql` says of its Sunday Service bootstrap:

> Already-enabled rows are left alone so an administrator's later "off" is not
> overwritten if this file is reapplied after a manual rollback of the history row.

## What it actually does

```sql
UPDATE `events_event`
   SET `assignment_scheduling_enabled` = 1
 WHERE `assignment_scheduling_enabled` = 0
   AND LOWER(`event_title`) LIKE '%sunday service%';
```

The `WHERE` selects exactly the rows an administrator has turned **off**. Rows
that are already on are indeed untouched — but they were never the ones at risk.
Reapplying the file re-enables every disabled Sunday Service, which is the
outcome the comment says it prevents.

Reproduced on a development copy: set `event_id = 5` to `0`, re-ran the file,
and the row came back as `1`.

## Why it has not been fixed here

The migration runner stores a SHA-256 of each applied file, and `tools/deploy.sh`
refuses to ship when the server reports a checksum mismatch. Editing 013 after it
has been applied — which it now has, locally and in production — would break
deploys for a comment correction. **Do not edit 013.**

## Blast radius

Small. Migrations run once; the history row prevents a second run. The scenario
the comment describes — a manual rollback of that row followed by a re-apply — is
the only way to hit it, and it is a deliberate operator action.

## If it ever needs fixing

Add a new migration rather than editing 013. The durable fix is a column that
records that the bootstrap has run (or that a row was set deliberately), so the
`UPDATE` can exclude administrator decisions instead of targeting them. Until
somebody actually needs to roll 013 back, the correct action is to leave the SQL
alone and treat this note as the correction to its comment.
