# Ekklesia feature guides

Ekklesia is the church application of Christlikeness Church, in production use
by the church for its people and households, ministries, events, serving
schedules, visitor registrations and printed calendars. These guides describe
what it does today, area by area, for the people who run it: administrators,
ministry leaders, schedulers and members.

For screen-by-screen help inside the application, open **Help** (`/docs`). For
installation and operation, see [deployment](../deploy/installation.md) and
[maintenance](../ops-maintenance.md).

## Feature map

| Area | What the church does with it | Guide |
| --- | --- | --- |
| People and households | Keep member records, households and family links; find people in the directory; review duplicate households; see record history | [People and households](people-and-households.md) |
| Ministries and serving | Run each ministry's workspace, team, positions and serving roles; fill serving schedules on a grid or from the calendar; post rosters | [Ministries and serving](ministries-and-serving.md) |
| Events and the calendar | Create one-off and repeating activities; manage individual dates; see everything in Month, Agenda, Week and Day views with layers and saved views | [Events and the calendar](events-and-calendar.md) |
| Publications | Print or save as PDF a monthly, weekly, agenda, Sunday, annual or planner publication; design PowerPoint themes; export an editable PowerPoint calendar | [Publications](publications.md) |
| Visitors and RSVPs | Take guest sign-ups and event responses on public pages; review, match and promote visitors into member records | [Visitors and RSVPs](visitors-and-rsvps.md) |
| Accounts and access | Give people accounts and roles scoped to a campus or ministry; sign in with a password, Google or an emailed link | [Accounts and access](accounts-and-access.md) |
| Administration and maintenance | Set up campuses, record options, church information, announcements and appearance; import and export rosters; take private backups | [Administration and maintenance](administration-and-maintenance.md) |

## Who does what

Every account holds one or more roles. A role can be limited to a campus, a
ministry, or both; an **Admin** role with no limit is a **portal-wide
administrator**, who sees the Administration control panel.

| Capability | Member | Scheduler | Leader | Admin |
| --- | :---: | :---: | :---: | :---: |
| See own serving schedule and set own unavailable dates | ✓ | ✓ | ✓ | ✓ |
| Open ministry workspaces and the calendar | ✓ | ✓ | ✓ | ✓ |
| Build and edit serving schedules (in scope) | | ✓ | ✓ | ✓ |
| Create and edit events (in scope) | | ✓ | ✓ | ✓ |
| See others' availability and ministry schedules | | ✓ | ✓ | ✓ |
| Manage a ministry's serving roles and positions | | | ✓ | ✓ |
| See leader-audience activities | | | ✓ | ✓ |
| Delete or cancel event dates | | ✓ | | ✓ |
| Administration control panel, member records, visitors, accounts | | | | Portal-wide only |

Details and the reasoning behind each limit are in
[Accounts and access](accounts-and-access.md).

## Shared ideas

- **One campus at a time.** The campus selector in the top bar sets the active
  campus everywhere: directory, ministries, calendar and publications.
- **Membership is not serving.** Belonging to a ministry, holding a position,
  and being assigned to serve on a date are three different records.
- **Being called a leader is not access.** Marking someone as a ministry's
  leader is a label; what an account can do comes from its roles.
- **Changes are recorded.** Member, household and account changes are written
  to history with who did what and when.

## Related documentation

| Document | Purpose |
| --- | --- |
| [Product overview](../../README.md) | What Ekklesia is, architecture and verification |
| [In-app help sources](../../resources/views/docs/sections/) | The `/docs` guide shown inside the application |
| [Installation](../deploy/installation.md) · [Release and routing](../deploy/README.md) · [Production configuration](../deploy/production-config.md) | Running an installation |
| [Maintenance](../ops-maintenance.md) | Backups, import and export |
| [Sign-up module](../../people_signup/README.md) · [RSVP module](../../events_rsvp/README.md) | The public guest pages |
