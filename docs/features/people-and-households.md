# People and households

Ekklesia keeps the church's member records. Each person can belong to one
household, households can be linked to related households, and every change is
written to history.

## The directory

**People** (`/people`) is where anyone looks someone up.

| Feature | Detail |
| --- | --- |
| Search | Fuzzy search across names, ministries, households and roles |
| Filters | Ministry, birthday month (or "this month"), active or inactive, has or lacks assignments or ministries, member type |
| Views | Table or cards; optional roles and campus columns; sortable; 20, 50 or 100 per page |
| Settings | Administrators choose the directory's columns and header text, for the whole church or per campus |

People without scheduling access see a person's first name and last initial
only, without their email, phone numbers, address or household. Leaders,
schedulers and administrators see full contact details.

Each person has a profile page with their photo, campus, member type, status,
ministries and, for those permitted, their contact details. A person can upload
or remove their own photo, choose their primary campus, and update their name,
contact details, address, birthday and gender from their account.

## Member records

Administrators maintain records in **Administration → Member records**
(`/admin/people`), with search, classification and member-type filters, and
the campus from the top-bar selector.

| Group | Fields |
| --- | --- |
| Name | First, middle, last (required), preferred, suffix |
| Personal | Gender, birthday (month and day, with optional year) |
| Contact | Email, mobile, home phone |
| Address | Two address lines, city, region, postal code, country |
| Church | Household and household role, membership classification, member type, campus, member since |

A person belongs to one campus. Classifications are colour-coded by status
(attending, prospective, not attending) from the church's own settings, and
the lists of classifications, family roles and member types are edited in
**Record settings**. A person view brings together identity, contact, a map,
their household and related households, ministries, linked sign-in accounts and
recent history.

A person can be deleted once they have been removed from their ministries;
the deletion is recorded in history with their name.

## Households

**Households** (`/admin/families`) group people who live together.

- Edit the household's name, address, home phone, email, wedding date and
  newsletter preference, or mark it inactive.
- Add people to a household and set each person's family role.
- Look up the household's location on a map (OpenStreetMap geocoding).
- Delete a household once nobody remains in it.

**Related households** link families that belong together — a married child's
household to their parents', extended family, or a second record for the same
home. The link is shown on both households and recorded on both.

## Duplicate households

**Households → Duplicates** groups households that may be the same family, by
name or by street address, and ranks each group as *likely*, *worth a look* or
*no signal* from the evidence: the same address, the same email, the same or
nearly the same surname, and same-named members.

To resolve a group, choose the household to keep and tick the ones to merge
into it. Merging moves the people across and removes the merged households.
Groups that are not duplicates are left as they are; there is no "not a
duplicate" marker, so they continue to appear.

## Record history

**People → History** (`/people/history`) lists who created, edited, moved,
merged, linked or deleted a person or household, and when. Filter by person,
household, action or date. History records the action, not the before and
after values of each field.

## Birthdays and anniversaries

Birthdays appear as a calendar layer and on the birthday printable sheet
(`/printables/birthdays`). On screen, calendar birthdays show the person's
age; the birthday sheet includes an age column. **Print-studio publications
never print ages:** they show preferred or first names with an optional last
initial.

The anniversaries layer uses each household's wedding date and stays empty
until wedding dates are entered.
