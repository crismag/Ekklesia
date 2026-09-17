# Import, Export & Backups

Everything to do with getting records in, out, and safely copied. Only a
**portal administrator** can use these. Importing and exporting member records
is People & Records → **Import & export**; backups are Admin → **Backups &
maintenance**.

## Import members from a spreadsheet

Bring a campus roster in from Excel instead of typing every person by hand.

1. **People & Records → Import & export**, then **Import a campus roster**.
2. Choose the **campus** this roster belongs to and a **preset** (North York or Scarborough). The primary sheet is enough; a secondary sheet is optional.
3. Upload an **.xlsx** file or paste a Google Sheets link. CSV is refused, because a household address merged down a family in Excel survives only on the first row of a CSV export.
4. **Check the staged rows.** Nothing has touched a member record yet.
5. **Apply ready rows.**

Everything between step 3 and step 5 is the point of the feature. The staged
rows keep the original spreadsheet text beside what was read from it, so a row
can be re-read as often as needed. Once applied, the original line is gone.

### What the import tells you before you apply

- **Duplicate rows in the sheet** are collapsed, and the ones removed are listed so you can check that two different people were not merged.
- **Addresses it could not read whole** are counted with the reason — a missing postcode is not the same problem as an unrecognisable city. The parts it did read are still correct.
- **Ministry names it does not recognise** are named. This matters more than it looks: the workbook decides who serves where, so a name that matches nothing can *remove* a membership rather than merely fail to add one.
- **Rows with no member type** are counted, along with how many could be suggested from the person's age.

### What applying does

- People already on file are **matched and updated**, not duplicated.
- Everyone imported is classified as **Member** — a spreadsheet says who is on the roster, not what their standing is. Change any that differ afterwards.
- Ministries come from the sheet, which is **authoritative**: a ministry it does not list is removed. A leader keeps the membership their leadership implies.
- People on that campus who are **not** in the file lose that campus affiliation. They are not deleted from the church.

> **Before a large import, make a backup.** It is one button, below.

### Filling blank member types

If rows arrive with no member type, **Fill blank member types from age** offers
one based on the ages of people who already hold each type. It writes to the
staged rows only, so you can change them, and it never overwrites a type the
sheet supplied. Age indicates a member type here; it does not settle it — check
them before applying.

## Download member records

On **Import & export**, **Export member records** builds a fresh Excel workbook from the database, one sheet per campus or a
single campus if you choose one. This is generated from current records, not a
copy of the original Drive file. A copy is kept in the private archive; the
latest exports are listed beside the button, and all of them under **Recent
backups & exports** on Backups & maintenance.

## Back up church data

**Create backup** copies both databases into a private folder on the server that
is not reachable from the web. Do this before a large import, or anything else
that changes many records at once.

**Advanced backup options** holds the parts that are occasionally needed and are
not what you came here for: each database separately, and snapshots of the
current events, schedules and member records as data files — useful for
comparing what changed between two points in time.

## Recent backups & exports

What has been saved, most recent first, described by what it contains rather
than by its filename — *Member records · Database backup* rather than
`mysql.people.08_25.1422.sql`. The stored paths are still there under
**Technical details** when somebody needs them.

There is no restore in the portal. Backups are written and downloaded; putting
one back is a deliberate act performed by whoever administers the server.
