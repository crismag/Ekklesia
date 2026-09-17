# Creating Events

Events are the moments your church gathers — Sunday services, prayer nights, leaders' meetings, baptisms, outreach days. The Events page is where you announce them, attach details, and (when needed) wire up a roster of volunteers.

[Insert Screenshot: The "+ New Event" button on the Events page, top-right corner.]

## When to create an event

Create an event whenever:

- A new gathering is being added to the calendar (one-time or recurring).
- An existing event needs a different time, location, or description.
- You want volunteers to see a serving opportunity attached to a specific moment.

Don't create an event for:

- Routine internal admin tasks (use a Schedule or Roster instead).
- Personal reminders (those belong in your own calendar).
- Recurring serving rhythms with no public-facing element (a Roster covers this better).

## Step-by-step: creating a one-time event

1. **Click "Events"** in the main menu.
2. **Click "+ New Event"** in the top-right of the Events page.
3. **Fill in the required fields** (covered below).
4. **Click "Save"**.

[Insert Screenshot: The event creation form with all fields visible.]

The event now appears on the Calendar, the Events list, and any Dashboard that shows it.

## From the calendar

Creating from Calendar is the point: the date you are looking at is the date you mean.

1. Click the day (or **New event** in the toolbar, or an hour in Week/Day).
2. If the day panel opens first, choose **New event on this day**.
3. The create form is the same one as **Events → New event**, with the date already filled.

You do not need forty tab stops through the month grid. Empty space in a month cell opens the day for everyone; create is offered inside the panel to those who may.

## Step-by-step: creating a recurring event

The flow is the same as a one-time event, with one extra step:

1. After filling in the basics, scroll to the **Recurrence** section.
2. Choose a pattern. The editor offers *Doesn't repeat*, *Every week*, *Every 2 weeks*, two monthly options (same date, or same weekday), and *Selected dates…*. There is no daily pattern.
3. Set an end date (or "no end date" for indefinite series).
4. Click **Save**.

The portal generates one occurrence per matching date. You can later cancel or modify a single occurrence without disturbing the rest of the series.

> **Tip:** Use *Every week* for Sunday services, *Every month, on the same weekday* for a first-Sunday meeting, and *Selected dates…* when the dates do not follow a step.

## Field-by-field guide

### Title

A short, scannable name. Examples: *Sunday Service*, *Youth Night — May*, *Baptism Class*.

- **Required:** Yes.
- **Tip:** If you have multiple campuses, append the campus suffix: *Sunday Service — SC*, *Sunday Service — NY*.

### Date and Time

When the event starts and ends.

- **Required:** Yes.
- **Format:** Date picker + time picker. End time is optional but recommended.

### Campus

Which campus is hosting.

- **Required:** Yes.
- **Tip:** "All Campuses" is for genuinely church-wide events (e.g., All-Church Prayer). For most events, pick one campus.

### Location

A short description of where it takes place. *Main Auditorium*, *Youth Hall*, *Online via Zoom*.

- **Required:** No, but strongly recommended.

### Description

A few lines explaining the purpose, audience, or any prep needed.

- **Required:** No.
- **Tip:** Keep it short. Three sentences is usually plenty.

### Recurrence (optional)

If this event repeats, configure the pattern here.

### Allow ministry assignments for this event (optional)

Tick this when this event should be a candidate for the campus default the serving grid opens with — a Sunday Service, not a one-off training night. It is off by default.

Appearing on the calendar and being the campus default are deliberately two different things. An activity is on the calendar because it is happening. The serving grid lists every activity that has an occurrence in the dates you loaded on this campus, whether or not this is ticked. This tick only feeds **Administration → Campus Locations → Default assignment event**, so a scheduler lands on Sunday Service instead of an empty workspace.

Ticking it does not schedule anything by itself. An administrator still has to set it as that campus's default. Un-ticking it later removes it from the default chooser but never deletes assignments already made, and the serving grid still offers the event whenever it happens in the loaded dates.

> **Tip:** Each campus nominates one of these events as the one its scheduler opens with — usually that campus's Sunday Service. An administrator sets it under Administration, then Campus Locations, by editing a campus and choosing a *Default assignment event*.

### Attached Roster (optional)

If volunteers are needed, link an existing roster or create a new one inline. The roster slots will show up on the Schedules grid and the Calendar.

## A real example

Here is what a complete event entry looks like:

| Field        | Value                                                |
|--------------|------------------------------------------------------|
| Title        | Mid-Week Prayer — SC                                 |
| Date / Time  | Wed, May 14, 2026 — 7:00 PM to 8:30 PM               |
| Campus       | South Campus                                         |
| Location     | Prayer Room (Building B, 2nd floor)                  |
| Description  | Open prayer night focused on our missionaries. Bring a friend. |
| Recurrence   | Every week, Wednesday, until end of August           |
| Roster       | "Prayer Night Hosts" — 2 greeters, 1 tech            |

[Insert Screenshot: The event detail page showing the values above plus a "Sign up to serve" call-to-action.]

## Events and Schedules — how they relate

This trips up new leaders, so it's worth being clear:

- An **Event** — an activity on the calendar — is a public-facing moment ("Sunday Service, 9:30 AM").
- A **Schedule** is the internal staffing for that moment ("Anna on Lead Vocals, David on Drums").

Being on the calendar with an occurrence in the loaded dates is enough for the serving grid to offer the event. *Allow ministry assignments for this event* is only how a campus nominates its default — usually Sunday Service. Most activities never need the tick.

When you create an event with an attached roster, the portal generates the schedule slots automatically. You then go to **Schedules** to fill the names in.

If you create just an event with no roster, that's fine — it shows up on the calendar but no volunteers are needed.

If you create just a schedule with no event, that's also fine — it represents internal serving (like a weekly office cleaning rotation) that doesn't need a public listing.

## What to expect after saving

- The event appears on the Calendar within a second.
- It appears on the Events list immediately.
- If a roster was attached, the schedule slots appear on the Schedules grid.
- Members of the campus see it on their Dashboard.
- If you allowed ministry assignments, an administrator can set the event as that campus's default for the serving grid. The grid already offers it whenever it happens in the loaded dates; a leader still chooses it, or it is that campus's default.

## Common mistakes to avoid

- **Creating duplicate recurring events.** If "Sunday Service" already exists as a weekly event, don't create another one — edit the existing series instead.
- **Setting the wrong campus.** A South Campus event posted under North Campus shows up on the wrong dashboards. Double-check before saving.
- **Skipping the description.** A title alone often isn't enough context for someone deciding whether to attend.
- **Attaching a roster you didn't intend to.** If you accidentally pick a roster, the slots appear on the Schedules grid and may confuse volunteers. Detach it before saving if you're not sure.

## Fixing a mistake

Everything below happens on the event's own page (**Events → the event**, or click the
event from the Calendar). Nothing here needs a developer.

### The time is wrong on every date

The usual version of this: a weekly service was generated at 7:30 AM when it meets at
7:00 PM. The dates are right; only the clock time is wrong.

1. Open the event.
2. In the **Occurrences** panel, click **Fix times**.
3. Enter the correct start time, optionally a new length, and choose whether to apply it to today onward or to every occurrence including past ones.
4. Click **Move these occurrences**.

The dates are never changed — only the time of day. Past occurrences are left alone
unless you ask for "every occurrence", because they are a record of what happened.

If two occurrences would end up on the same date at the same time, the portal refuses the
whole change and tells you which date clashes. Delete the duplicate first, then retry.

### One date is wrong

Click **Time** on that row. Change the date, the time, or both, then **Save time**.
The occurrence keeps its current length unless you type a new one.

### A whole series was generated wrongly

In the **Occurrences** panel, click **Delete many**, then choose to remove either the
occurrences from today onward or every one of them. The event itself survives, so you can
generate a corrected series straight afterwards.

Alternatively, in **Generate occurrences**, tick **Replace the existing occurrences
instead of adding to them**. Generating otherwise *adds* to what is already there, which
is how a corrected second attempt ends up sitting beside the wrong first one.

### A few specific dates are wrong

Tick the checkboxes beside those rows and click **Delete selected**.

### The whole event should not exist

**Delete event** in the right-hand column removes the event with all of its occurrences.
This cannot be undone.

### Anything with volunteers already assigned

Deleting occurrences that carry assignments is refused the first time and tells you how
many there are. Confirm only when you are sure those rostered slots should go too.

> **Note:** The calendar and the events page read the same records. If the calendar shows a time you thought you had changed, the change did not save — it is not a second copy to update separately.
