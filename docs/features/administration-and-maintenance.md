# Administration and maintenance

Portal-wide administrators set up the church's information and look after its
data from **Administration** (`/admin`).

## Settings

| Area | What it controls |
| --- | --- |
| Campuses | Add, edit and remove campuses; choose the main campus |
| Church information | The church's name, contact details and website, used across the site and in publications |
| Record settings | The lists of classifications, family roles and member types |
| Event types | The kinds of activity and the audience each one has (public, members, leaders) |
| Calendar | Holiday calendars and their sync |
| Ministries | The ministry list and the ministries page's title and subtitle |
| Directory settings | The People directory's columns and header text, for the church or per campus |
| Announcements | Banner announcements, with drafts |
| Appearance | Theme colours (presets are checked against WCAG AA contrast), home page hero, header and footer, and navigation links |
| Accounts and activity | See [Accounts and access](accounts-and-access.md) |

## Importing the church roster

Ekklesia can bring in the church's roster workbook (the "Hub" spreadsheet)
from an `.xlsx` file (up to 12 MB, 2,000 rows) or a Google Sheets link shared
with anyone who has the link. Imports are **staged**: nothing changes until an
administrator applies the batch.

1. **Upload** the workbook, choose the campus and the sheet layout, and choose
   any extra matching rules (full first name, birthday, email, phone, member
   type).
2. **Review** each row: edit it inline and mark it *ready*, *draft* or *skip*.
   Fill blank member types from age bands.
3. **Resolve duplicates** inside the batch: keep one row (the others fill its
   blanks) or keep them as separate people. These choices can be changed until
   the batch is applied.
4. **Map ministries**: match each ministry name in the workbook to a ministry,
   or mark it as not a ministry.
5. **Apply**: ready rows create or update people and their ministries.

> **Important — the campus roster is replaced.** Applying a batch treats it as
> the campus's complete roster. Anyone on that campus who is not matched by a
> *ready* row — including rows left as draft or skip — is removed from the
> campus. Mark everyone who belongs as ready, and take a backup first. An
> applied batch cannot be applied again or undone in the application.

The workbook layouts are those of Christlikeness Church's own roster sheets.

## Exporting

| Export | Content |
| --- | --- |
| Roster workbook | A formatted `.xlsx` with a sheet per campus (or one campus), saved to the private archive and downloaded |
| CSV | One campus's members in the roster's column layout |

## Backups

**Maintenance** takes a backup on demand:

| Backup | Content |
| --- | --- |
| Members | A full dump of the member database |
| Visitors | A copy of the visitors database |
| Both | Members and visitors together |
| Snapshot | Events, members and schedules as JSON, for inspection or reuse |

Backups are stored in a private archive outside the public site and downloaded
only by administrators. They are taken when an administrator asks; schedule
regular backups at the host as well. Restoring a backup is done by the server
operator, not from inside the application — see [maintenance](../ops-maintenance.md).

## Releases and the maintenance gate

The release script checks the code, applies database changes behind a
temporary **maintenance gate** — visitors see a short "back soon" page — and
then checks the live site. The gate lifts itself after fifteen minutes if a
release is interrupted. See [release and routing](../deploy/README.md).
