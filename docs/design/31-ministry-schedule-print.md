# Ministry schedule — a publication, not a report

The church prints one sheet a week and pins it up: every ministry, every duty,
every name. It is a different document from the calendar prints, and it is built
differently.

## The sources it consolidates

Two systems, both real, neither designed for printing.

**`assignment` → `roles` → `group_grp`** (ChurchCRM). Someone rostered onto a
role of a ministry for a dated occurrence. Read through
`ScheduleService::getScheduleBoard()`, which returns occurrence, event title,
ministry id and name, role id/name, person id and name — already campus-scoped.
This *is* the shape the printed sheet has always had.

**`schedule_roster` / `_slot` / `_assignment`** (portal). A container with dated
or weekday slots, each carrying a label, a location and one or more assignees.
Read through `RosterScheduleService::expandRostersInWindow()`.

The `roles` table also carries `is_blocking` and `recommended_count` — a real
grounding for "required role" if that warning is ever wanted. It is not read
today, and the reason is in the builder's comments rather than invented here.

Nothing new was created. No ministry from the reference image exists as a
record; it is a layout reference and was treated as one.

## Architecture

```
ScheduleService ──┐
                  ├──► MinistryScheduleDocumentBuilder ──► MinistryScheduleDocument
RosterScheduleService ┘                                              │
                                                                     ▼
                                                      ColumnBalancer::distribute
                                                                     │
                                                                     ▼
                                              print/ministry-schedule/classic.php
                                                                     │
                                                          ┌──────────┴──────────┐
                                                          ▼                     ▼
                                                       preview            print / PDF
```

**The template queries nothing.** That is not tidiness: a template that queries
cannot be rendered from a fixture, cannot be tested without a database, and
grows a second copy of the consolidation rules the moment a second template
exists. A regression test asserts the absence directly — no `PDO`, no `query(`,
no repository, no service.

The document is serializable and round-trips through JSON. Nothing writes JSON
today; a published immutable revision is an obvious later ask and an object that
cannot be written down cannot become one.

### Consolidation decisions

- A ministry appearing in **both** systems is one card. Same ministry and same
  role from both is one duty recorded twice, not two duties.
- A role whose ministry group was deleted keeps its people, under
  *Unassigned ministry*. Losing them silently is worse than a plain heading.
- Sections are ordered **alphabetically** — any order is arbitrary, but an
  arbitrary order that changes between printings is worse, because people learn
  where their ministry sits on the sheet.
- A duty with nobody on it stays on the page and says *Unfilled*. It is the most
  useful thing a schedule can say before Sunday.

### Column placement

Decided in `ColumnBalancer`, not by CSS columns — those flow differently between
browsers and again between screen and print, and a sheet printed twice has to
match. Next card into the shortest column; ties go left, so nothing depends on
iteration order. A test asserts the same document places identically twice.

`COLUMN_CAPACITY` is **measured, not guessed**: a column has ~824 CSS px of
usable height and a rendered column of weight 37 measures 803 px, so a weight
unit is ~21.7 px and a column holds ~38. The reference density's tallest column
is 37 — it fits with almost nothing to spare, which is what "the density this
template is designed for" means. **Re-measure it if the type sizes in
`classic.css` change.**

## Translating the reference

| Reference | How |
|---|---|
| Diagonal blue corners | Two inline SVGs, three tones, `aria-hidden`, behind the content and `pointer-events: none` |
| MINISTRY SCHEDULE | 37pt, weight 800, navy — the strongest thing on the page by a wide margin |
| AUGUST 23, 2026 | 23pt, brighter blue — prominent but plainly subordinate |
| Navy ministry headers | `.ms-card-head`, white on `--ms-primary`, centred, wraps inside the bar |
| Role labels | `.ms-role`, 9.2pt/800, accent blue — visually stronger than the names under them |
| Names | `.ms-name`, 10.2pt/500, `overflow-wrap: anywhere`, never truncated, never shrunk |
| Striped list cards | `.ms-card--flat` — a card whose duties have no role names |
| Three uneven columns | CSS Grid of three real columns, each filled by the balancer |

Every colour is a variable on `.ms-sheet`. A Christmas or Easter variant should
be six values, not a search through a stylesheet. No variants were built.

**The font stack leads with system faces.** A church office PC may have no
network when somebody hits Print, and a sheet that silently falls back to Times
is not the document that was designed. Archivo is an enhancement.

## Capacity

Content is never shrunk to fit. A schedule nobody can read from two feet away
has failed at the one thing it exists for.

- Within capacity → one Letter sheet.
- Over capacity → a clean second page, plus *"This schedule continues on the
  next page"* on the sheet itself and a note in the preview.
- A **single card taller than a page** (a ministry with fourteen duties) is
  allowed to break between duties, never inside one. Refusing to break it does
  not make it fit — it pushes the whole card to the next sheet and wastes this
  one.

## Authorisation

`ViewMinistrySchedule` or `ManageSchedules`, or portal-wide admin.

`ViewMinistryDashboard` is deliberately **not** accepted, even though the
per-ministry schedule grid accepts it: every member holds it, and a dashboard
summary is not the whole church's duty roster with names. A member who serves
sees their own duties on `/my-schedule`. This was caught by a test, not by
reading — the first draft of the gate let any member read every name.

Verified: unauthenticated **401**, member **403**, scheduler **200**, admin
**200**.

## Limitations

- **Density.** ~38 weight units per column, about 13 ministries and 40
  assignments. Past that it is two pages by design.
- **Times.** The sheet shows who, not when. Both sources carry times; the
  reference does not show them and neither does this template.
- **Roster/assignment matching.** When both systems describe the same Sunday,
  roster slots are matched to duties by time only. Anything cleverer would be
  guessing.
- **One template.** Classic only. Compact and mobile renderers would consume the
  same document; neither was built, because common infrastructure extracted from
  one example is a guess.
