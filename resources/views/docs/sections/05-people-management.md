# Member records — Add, Edit & Manage

The **Directory** is look-up only. Adding, editing, and deleting a record is
People & Records → **Member records**, which only portal administrators see.
(Older notes call this Administration → **Member records**; it is the same
page.) This section covers the list, the person record, the editor, photos, and
the self-service controls a person has on their own directory profile.

## Member records

Open People & Records → **Member records**. The page shows:

- **Stat cards** — total people, active households, people without a member type (and without a status, when there are any).
- **A searchable list** of everyone, with filters and paging.
- **Breakdowns** — people by *membership status* (Member, Guest, …) and by *member type* (Radical, Trailblazer, G&A). Each name opens the list filtered to it.

**Add person** is at the top of the page. The **Directory** tab is a different
door: look someone up by ministry, family, birthday month, or search. It does
not add records.

### Finding people

- Type a **name or email** in the search box.
- Narrow by **membership status** or **member type**. "Not set" under member type finds the people still missing one.
- **Campus comes from the selector in the top bar**, not from this page. There is one campus control for the whole site; the page says which campus it is showing.
- Results page 25 at a time. Click a person's **name** to open their record, or **Edit** to go straight to the editor.

### Reading the list

Membership status and member type are written out in full. A small dot beside
the status is green for attending, amber for not attending, and blue for
prospective; the member type carries its symbol (an arrow for G&A, a seedling
for Radical, a pennant for Trailblazer). The words always say it; the marks are
only a second cue. On a phone each person becomes a small card.

## Adding a person

1. On **Member records**, choose **Add person**.
2. Fill in the form. **Only the last name is required** — everything else is optional (matching how the record editor validates).
3. Choose **Add person**. You land on the new record.

The editor is in sections:

- **Identity** — first, middle, last, preferred name, suffix, gender, and birthday (month and day together, or both blank; year optional).
- **Contact & address** — email, phones, and address. Leave the address blank to use the household's.
- **Household** — the household they belong to and their role in it.
- **Membership** — membership status, member type, campus, and member since.

From a household record, **Add a person** opens the editor with that household
already chosen.

> **Tip:** Assigning a **campus** here makes the person show up when that campus is selected in filters, birthdays, and ministry views.

## Editing a person

From the list choose **Edit**, or open the record and choose **Edit record**.
The same form is used, pre-filled. **Save changes** returns you to the record,
and the change is added to the record's history.

## The person record

Clicking a person's **name** opens their record, in sections:

- **Identity** — photo (or initials), names, gender, birthday.
- **Contact & address** — call, text, email and copy buttons; map links, an embedded map when the household has been located, and **Refresh map location**.
- **Household** — the household and the person's role, the others in it, related households, and other households at the same address. **Open household** goes to the household record.
- **Ministries & positions** — the ministries they belong to, positions held and whether they lead. Read-only here: membership is changed in Ministries (**Manage members & leaders**).
- **Membership** — status, member type, campus, member since.
- **Portal logins** — any login linked to this person, its roles and when it last signed in. Read-only here: logins are managed in **Users & access**.
- **Recent history** — the latest changes to the record, with **All history** opening Record history for this person.
- **Delete record** — with a confirmation naming the person.

**Directory profile** at the top opens the same person as members see them.

### Refresh map location

When a person belongs to a household, **Refresh map location** looks up the
household's position from its address (via OpenStreetMap) and stores it, so the
map appears. Coordinates live on the **household** record, shared by everyone
in it.

## Photos

Photos are stored in a portal-owned folder and served by the portal. A person's
photo shows on their profile and in the directory; when none is set, their
initials are shown instead.

- **Admins** can set a photo from the profile.
- **The person themselves** can upload or remove their own photo (see below).

## Self-service on your own profile

When someone is signed in and viewing **their own** public profile
(`/people/{their id}`), a **Manage my profile** panel appears with:

- **Profile photo** — upload or remove (images are re-encoded to a safe square).
- **Primary campus** — choose the campus they're affiliated with.
- **Address coordinates** — a Refresh button to map their family's address.

Portal-wide admins get the same controls on anyone's profile. Everyone else sees
a read-only profile.

> **Warning:** Deleting a person removes their record, member type, and campus links. It can't be undone — deactivate or reassign instead when you only need to retire someone. Campus **import apply** unlinks leftover people from that campus; it does not delete them.

## Importing and exporting

Bulk load from the Hub Excel workbook, and the member workbook download, are on
People & Records → **Import & export**. See **Import, Export & Backups**.

## Households

Households are managed on People & Records → **Households**: search households,
see how many people are in each, and open one. The household record lists its
people (each linking to their record, with **Add a person**), related
households (link and unlink), other households at the same address, its recent
history, and its details — shared address, phone, email, wedding anniversary —
with **Refresh from the address** for the map. A household can't be deleted
while people are still in it; move them or mark the household inactive instead.
**Review duplicates** finds household records that are really one household.

## Record history

People & Records → **Record history** lists who added or changed a person or
household record, and when — newest first, with filters for the person, the
kind of record, the kind of change and a date range. It is read-only and only
portal administrators see it. Changes from the earlier system are included and
marked as such.

## Record settings

The choices in the membership status, household role and member type lists are
managed on People & Records → **Record settings**: add, rename, reorder, or
delete. The **People** column shows how many people have each option and opens
them; an option still in use can't be deleted until those people are changed.
