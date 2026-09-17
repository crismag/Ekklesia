# Member records — Add, Edit & Manage

The top-bar **People** tab is look-up only. Adding, editing, and deleting a
record is Administration → **Member records** (under *People & families*). This
section covers that admin page, the profile view, photos, and the self-service
controls a person has on their own public profile.

## Member records

Open Administration → **Member records**. The page shows:

- **Stat cards** — total people, active families, and typed members.
- **Breakdowns** — people by *classification* (Member, Guest, …) and by *member type* (Radical, Trailblazer, G&A).
- **Quick actions** — Add person, Maintenance (backups and roster import), Campuses, and a link to the public directory.
- **A searchable list** of everyone, with filters and paging.

The top-bar **People** tab is a different door: look someone up by ministry,
family, birthday month, or search. It does not add records.

### Finding people

- Type a **name or email** in the search box.
- Narrow by **classification** or **member type**. "Not set" under member type finds the people still missing one.
- **Campus comes from the selector in the top bar**, not from this page. There is one campus control for the whole site.
- Results paginate 25 at a time. Click a person's **name** to open their profile, or **Edit** — pinned to the right-hand edge, so it stays on screen however narrow the window.

### Reading the list

Two columns are deliberately narrow, because spelled out they took more width
than the person's name:

- **Cls** — a coloured circle for classification. Green means attending, orange not attending, grey not recorded. The letters inside are the classification's short code, so the mark still works in print and for anyone who does not separate the two colours.
- **Type** — a symbol for member type: an arrow for G&A, a seedling for Radical, a pennant for Trailblazer.

The two cards above the list — **By classification** and **By member type** —
are also the key: every mark appears there beside the name it stands for and the
number of people it covers. Hovering a mark names it too.

## Adding a person

1. On **Member records**, click **+ Add person**.
2. Fill in the form. **Only the last name is required** — everything else is optional (matching how the record editor validates).
3. Click **Create person**.

The editor is grouped for clarity:

- **Name & identity** — title, first, middle, last, suffix, gender.
- **Birth date** — month and day must be entered together (or left blank); year is optional.
- **Membership** — classification, member type, membership date.
- **Family & campus** — the household they belong to, their family role, and their primary campus.
- **Contact** — emails and phones.
- **Location** — address, city, province, postal code, country.
- **Social** — Facebook, LinkedIn, X.

> **Tip:** Assigning a **primary campus** here makes the person show up when that campus is selected in filters, birthdays, and ministry views.

## Editing a person

From the list, click **Edit**, or open the profile and choose **Edit**. The same
grouped form is used, pre-filled with the person's details. Changes are saved
against the record with an updated-by/edited-on stamp.

## The profile view

Clicking a person's **name** opens their profile — a read view with:

- a **photo** (or initials when no photo is set),
- quick info (gender, classification, member type, family role, membership date, birthday),
- **contact** rows with call, text, email, and copy-to-clipboard buttons,
- **address** with map links (and an embedded map when coordinates are known),
- **campus affiliations**, and
- a **Family** panel listing household members, each linking to their profile.

### The Actions menu

The **Actions** button (admins) offers: Edit person, View public profile,
Change family/role, View photo, and **Delete person** (with a confirmation).

### Refresh coordinates

When a person belongs to a family, a **Refresh coordinates** button looks up the
family's map location from its address (via OpenStreetMap) and stores it, so the
map appears. Coordinates live on the **family** record, shared by everyone in
that household.

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

## Importing a campus roster

Bulk load from the Hub Excel workbook lives on **Maintenance**, not on
**Member records** and not on the People tab. See **Import, Export & Backups**.

## Families

Households are managed on the **Families** page (under *People & families*):
search families, see member counts, and open a family to edit its shared
address, phone, email, and wedding/anniversary date. The family editor lists its
members (each linking to their profile) and includes the same Refresh
coordinates button. A family can't be deleted while it still has members —
reassign them or deactivate the family instead.

## Dropdown options

The values in the classification, family-role, and member-type dropdowns are
managed on the **Member types** page (under *People & families*). See that page to
add, rename, reorder, or remove options. An option that's still in use can't be
deleted until the people using it are reassigned.
