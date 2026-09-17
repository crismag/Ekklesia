# What is blocked, what is deferred, and exactly what would unblock it

Written at the end of the reliability/debt phase. Everything here was reachable
in the code and deliberately not built — either because it needs input nobody
has supplied, or because building it now would mean inventing the thing it is
supposed to describe.

---

## BLOCKED — input required

### Rooms and resources, with conflict detection

**What exists.** ChurchCRM's `locations` table is present and **empty**:

```
location_id, location_typeId, location_name, location_address,
location_city, location_state, location_zip, location_country,
location_phone, location_email, location_timzezone
```

`events_event.location_id` is set on **0** events. `custom_location_name` is set
on 1. No adapter reads `locations` at all.

**Why nothing was built.** A room picker whose only entries are *Room A* and
*Main Hall* is not a feature — it is a piece of fiction that then has to be
un-invented once the real names arrive, after somebody has already filed events
against the fake ones. The brief and the repository agree on this: no
speculative schema, no invented church data.

**Exactly what is needed to unblock it**, in order of how much it changes:

1. **The list of bookable places.** Name, and which campus each belongs to.
   Nothing else is required to start. For example — and these are placeholders
   in this document, not data to be entered:

   | Name | Campus |
   |---|---|
   | *(your sanctuary's name)* | North York |
   | *(your hall's name)* | Scarborough |

2. **Whether a place can be double-booked.** Some can — a car park, a foyer. A
   sanctuary usually cannot. This is one boolean per row and it is the entire
   difference between "conflict" and "coincidence".

3. **Whether anything other than a room is bookable.** Projectors, vans,
   instruments. If yes, resources are a separate entity from rooms and the
   schema differs; if no, `locations` is enough and the migration is smaller.

**What the design would then be**, so the decision is not re-litigated later:

- A booking is `(occurrence_id, location_id)` — attached to the occurrence, not
  the event, because "this week we are in the hall" is exactly the case that
  matters, and per-occurrence overrides already work that way.
- A conflict is *the same non-shareable location, with overlapping occurrence
  times, on occurrences that are not cancelled*. Cancelled dates cannot
  conflict; that is now expressible because `is_cancelled` is honoured.
- Detection runs at save time and reports rather than refuses. A church
  genuinely does double-book on purpose sometimes, and a scheduler who cannot
  override the machine will keep the schedule somewhere else instead.

Until item 1 arrives, none of this is written.

### Development database anonymisation

The tool exists, is excluded from deployment, and cannot run: the destination
database does not exist and the credential it would use has no rights to create
it. Two statements, from `docs/design/19-dev-data-anonymization.md`:

```sql
CREATE DATABASE `christlike_dev`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `christlike_dev`.*
  TO 'u471078694_csadmin_mdb'@'localhost';
```

These need running by someone with database-creation rights. They are not in
source control and should not be. Once they exist the documented workflow runs
unchanged, and the source database stays read-only from the tool's side by
design — that is the property the tool is built around, not a setting.

### Member reconciliation

Blocked on the Hub XLSX, which is not on this machine. Nothing was fabricated
to stand in for it. When the workbook arrives the path is: inspect its columns
before mapping anything, stage into `member_import_*`, preview, and require a
human decision on every ambiguous identity match — the staging tables and the
review screen already exist for exactly this.

---

## Deferred — buildable, deliberately not built yet

### Event templates

The brief gates these on the editor and occurrence model being stable. They now
are. What makes templates worth waiting a little longer is that the interesting
part is not the defaults — it is deciding what a template must **not** carry:

- not an id of anything;
- not historical assignments, which belong to the dates they were made for;
- not a start date, which is the one thing that is different every time.

A template is therefore a saved *shape*: title, description, duration, ministry,
campus, type, recurrence pattern, tags. All of those now exist as fields on the
editor, which is what makes this a small feature rather than a schema question.
One table, and the editor prefilling from it.

### Natural-language quick add

Explicitly a convenience parser, not an AI dependency, and explicitly never a
path that creates records directly:

```
text → parsed draft → the ordinary editor → the ordinary validation
```

The editor is now a single shared component with one validation path, which is
what makes this safe to add later: the parser fills the same form a person would
have filled, and everything downstream is unchanged. Adding it before that
sharing existed would have meant a second creation path.

---

## Human review required — not automatable

### 15 person/family records with genuinely different addresses

Formatting-only differences were already fixed and must not re-enter this queue.
What remains are records where the person and the household really do give
different addresses. That is sometimes a stale record and sometimes a student
living away, and the data cannot tell which.

### 4 split-household pairs

Maningo (different unit numbers), De Jose, Rivera, Buenaflor/Galicia
(postcode-only match). None may be merged on surname, postcode, similar address,
age or church role alone — the combination that looks convincing is exactly the
combination that produced the earlier false merges.

### 15 households whose roles no rule can settle

The Rosario case is the reason there is no heuristic here: Ronn is a child by
household relationship and an adult by age, and both are true. Age is a property
of a person, not a substitute for a relationship.

The missing domain concept is a **person-to-person relationship graph** —
parent, child, spouse, guardian, dependent, sibling — and it is not a task to
attach to something else. It changes household semantics across the portal, so
it needs its own design and its own migration plan before any code.

---

## `docs/pending/test_events,txt`

Source material. It contains real service schedules — SEED Level 1 (Batch 6),
RADICAL Life Service, MTE, instrument training, across both campuses — and it is
reference, not an instruction. No church records were created from it. Creating
production church data requires explicit authorisation, and a schedule appearing
in a repository document is not that.
