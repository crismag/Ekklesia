# Ekklesia feature guide

Ekklesia supports the day-to-day work of Christlikeness Church, from maintaining
member records to publishing the next month's calendar. This page summarizes
what it does; each area has a detailed [feature guide](features/README.md), and the
in-app `/docs` guide contains screen-by-screen instructions.

## Members and household records

**People** is the lookup directory. Administrators use **Member records** to add
and edit people, classifications, contacts and campus affiliation. Household
records group contacts and addresses; related-household links preserve family
connections. Duplicate review ranks possible duplicate households by evidence and lets an
administrator merge them, and record history shows who changed what, and when.

Hub `.xlsx` and Google Sheets imports are staged for review before application.
Reviewers resolve matches, duplicates and ministry mappings, then apply the
batch. Exports produce church roster workbooks; CSV member export is also available.

## Ministry coordination and serving

A ministry workspace brings its overview, members and leaders, serving roles,
and schedule together. Membership and positions describe the team; serving
assignments describe who fills a role on a particular date.

Leaders and schedulers work within their granted scope. They can use the serving
grid for a range of dates or fill a role from Calendar's day panel. Personal
schedules show assignments, and people record the dates they are unavailable;
conflict indicators and empty-role counts help planners spot problems. The grid
does not yet check recorded unavailability. These indicators do not send
assignment invitations or implement an accept/decline workflow.

## Events and the church calendar

The shared event editor handles activities, recurring series, campus and ministry
context, event types and tags. Dated occurrences allow users to work with a
particular instance of a series. Audience restrictions apply to event listings,
calendar feeds and direct access, including leaders-only activities.

Month, Agenda, Week and Day views combine events, assignments, birthdays,
anniversaries and holiday layers. A date opens a contextual day panel; permitted
users can create an activity or edit serving from that context. The global campus
selector determines the active campus throughout the workspace.

Signed-in users can save private views and open shared views. Publications and
screen views use the saved-view service. Campus is not stored in a saved view.
Custom “Others” calendars remain browser-local and are not included in saved
views or printed publications. Holiday lists are cached so rendering does not
require a live holiday service.

## Publications, PDF and PowerPoint

The print studio has content and layout controls, a document preview, and a theme
panel. It supports monthly, weekly, agenda, Sunday, annual and ministry-planner
layouts; Letter, A4, Legal, Tabloid and A3 paper; orientation, density, typeface,
headers, footers and editable publication notes.

- Save, rename, duplicate and reopen publications; administrators can share them.
- Use rolling periods such as “This month” without rebuilding the recipe.
- Choose automatic seasonal monthly themes or a fixed built-in theme.
- Upload checked background images with positioning, transparency and readability controls.
- Download PowerPoint theme starters, design slide 1, and upload named template regions.
- Replace theme versions or retire a theme; administrators can share church themes.
- Print or choose Save as PDF in the browser dialog.
- Export an editable `.pptx` monthly calendar with text boxes, day rectangles,
  a grouped member-type key, and supported custom artwork.

**One page** keeps a month on one page/slide with overflow counts; **Grow to fit**
includes all entries with continuation pages/slides when required. PDF follows
the chosen print layout; PowerPoint always exports the monthly grid. PowerPoint
themes support a defined subset of slide artwork, and export omits some built-in
decorations. Birthday names use preferred/first names with optional last initials;
publications never print ages (the separate birthday printable lists ages). See [the print guide](../resources/views/docs/sections/15-printing.md).

## Visitors and event responses

The public sign-up and RSVP modules accept registrations and event responses.
Administrators review registrations, compare them with member records, resolve
duplicates, record review notes, and promote visitors into member records. Visitors remain
in a separate SQLite database until reviewed; a promotion creates or matches a
member-database person and records the connection.

Standalone module administration uses its own access codes. The portal's
**Visitors & RSVPs → Access codes** workspace manages rotating codes and expiry.
These modules are separate entry points; RSVP is not a toggle on the portal's
event editor. See [sign-up](../people_signup/README.md) and [RSVP](../events_rsvp/README.md).

## Access and administration

Member, Scheduler, Leader and Admin roles can be scoped to campus and ministry.
Administrators provision accounts, link people, manage roles, reset passwords,
and activate/deactivate access. The system guards the last active portal-wide
administrator and prevents self-deactivation/deletion.

Password login is available; configured hosts also offer Google authentication
and emailed sign-in links for existing accounts. Google uses identity scopes,
not Gmail, Drive or Calendar permissions. Missing OAuth/mail configuration
keeps the corresponding login option hidden.

Administrators manage church information, campuses, announcements, appearance,
navigation, record settings and accounts. Maintenance provides member/visitor
backups, JSON snapshots and roster exports. Archives are private and downloaded
through an authorized route. See [maintenance](ops-maintenance.md) and
[permissions](../resources/views/docs/sections/06-permissions.md).
