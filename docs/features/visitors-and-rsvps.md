# Visitors and RSVPs

Two public pages collect information from guests: **sign-up** for people
visiting the church, and **RSVP** for responses to an event. Guest entries are
kept in a separate visitors database until the church reviews them, and only a
reviewed decision brings someone into the member records.

## Guest sign-up

The sign-up page (`people_signup/`) has a quick form and an optional fuller
one.

| Form | Fields |
| --- | --- |
| Quick | First and last name, city, reason for visiting (the church's own list), birth month and year; optionally birth day and who invited them |
| Advanced | Adds middle and preferred name, gender, email, phone, address, church background and notes |
| Extras | Address with map lookup, marital status, member type and social links |

On submission, Ekklesia compares the guest with member records by name, birth
month and year, email and mobile:

- **Already a member** — the guest is told they are already registered, and
  nothing new is stored.
- **Possibly a member** — the sign-up is stored with the likely match noted
  for the reviewer.
- **Signed up before** — a repeat sign-up from the same guest is marked as a
  duplicate.

## Event responses

Each event has an RSVP page (`events_rsvp/event.php?event_id=…`) where a guest
gives their name and, optionally, contact details, answers **Yes**, **Maybe**
or **No**, and says how many are coming (up to 50) with a note. Members are
recognised and linked to their record.

For each event the church sees totals of yes, maybe and no, expected heads,
and members versus visitors, and can mark each response as registered,
checked in, no-show or cancelled. RSVP pages do not limit capacity or send
reminders.

## Reviewing visitors

**Visitors & RSVPs** (`/visitors`) is the administrators' review workspace.

| Step | What happens |
| --- | --- |
| Queue | Registrations by status — new, reviewed, duplicate, promoted, rejected — with search |
| Record | The guest's details beside possible member matches, their other sign-ups, RSVPs and earlier promotions |
| Notes and status | Add review notes; mark reviewed, duplicate or rejected |
| Promote | **Create** a new member record (choosing membership status and campus) or **link** the guest to the matching member |

Promotion writes the member record first and then marks the guest as
promoted, recording the connection in both databases. A guest who looks like
an existing member is not created twice unless the reviewer confirms it.

## Access codes

The standalone review pages of each module are opened with an access code.
Administrators issue codes in **Visitors & RSVPs → Access codes**: generate
or choose a word, set how many days it is valid, and add a note. A new code
replaces the old one immediately. Issue a code before sharing the review
pages, and rotate it when people change roles. The same screen lists the RSVP
links for events in the next 60 days.

See the [sign-up](../../people_signup/README.md) and
[RSVP](../../events_rsvp/README.md) module guides for installation.
