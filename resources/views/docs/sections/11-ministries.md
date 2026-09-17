# Ministries — Create & Manage

Ministries are the teams people serve on. They drive scheduling, leader access,
and the ministry directory. The church's serving teams come from the Hub roster
column (comma-separated tags). The official unique list is:

Admin, Creatives, Dance Ministry, Events, Facilities, Field, Gifts and Arrows,
Guest Services, MTE, Prayer, Productions, Psalmist, Victuals.

Hub shorthand is mapped onto that list: **G&A** is Gifts and Arrows, **GS** is
Guest Services, **MTE** is More than Enough, **Psalmist** is the team previously
labeled Psalmists, **Field** is Field Ministry. Text after `GS:` is a **role**
on Guest Services (`Usher`, `Emcee`), not another ministry. `GS: Usher and Emcee`
means both roles on Guest Services. Combined tags such as
`Events & Prayer Ministry` are two ministries (Events and Prayer).
Sample groups such as CrisTest are not serving teams.

Admins can create extra ministries, but the Hub catalog is the list that should
match member records. On the server, `php tools/sync-ministry-catalog.php`
shows the rename/create/deactivate plan; `--apply` writes it to `group_grp`
without changing existing memberships on renamed groups.

## Where ministries live

Everything about ministries is in the **Ministries** workspace:

- **Ministries** — the directory. Every ministry with its campus, how many
  members it has, whether it schedules people and its next serving date. Your
  own ministries come first. Leaders' names show on the ministries you may see
  the people of.
- **Each ministry** (`/ministries/{id}`) has four tabs:
  - **Overview** — leaders, positions and serving roles at a glance, the dates
    it serves next, and the way into the schedule editor and printables.
  - **Members & leaders** — who is on the team, who leads it, and each
    member's positions. This replaces the old Administration page of the same
    name; its old addresses open this tab.
  - **Serving roles** — the roles the schedule editor fills on each date.
  - **Schedule** — who serves on each date in the next eight weeks. Changes are
    made in the schedule editor.
- **Manage ministries** (administrators) — create, rename, move, deactivate or
  delete ministries, and the **Ministry list** settings: what the church
  website may list publicly.

Members & leaders and Serving roles are shown to the ministry's leaders and
schedulers; changing them needs a leader or administrator account for that
ministry.

> **Tip:** A ministry's **schedule assignments** (who serves on which date) are separate from its **membership** (who is on the team, and who leads it). The first is the serving grid (or Calendar's day panel); the second is the ministry's Members & leaders tab.

## Viewing ministries

Open **Ministries** from the sidebar. Choose a campus above the list to see that
campus's ministries and the ones serving every campus, with member counts for
that campus; **All campuses** shows everything. Type in **Find a ministry** to
narrow the list.

A ministry page at `/ministry/{name}` (for example `/ministry/victuals`) is a
friendly shortcut to the same ministry.

## Creating and editing a ministry

Ministries are created and configured by a portal-wide admin.

1. Open Ministries → **Manage ministries**.
2. Under **Add a ministry**, enter its **name** and choose a **campus** (or All
   campuses), then **Create ministry**. You are taken to its Members & leaders
   tab to add people.
3. **Edit** renames a ministry or moves it to another campus. **Deactivate**
   hides it from the directory and the schedule editor and keeps its history;
   **Delete** removes it with its memberships and serving roles.

Whether it appears on the church website is set separately under **Ministry
list** (the *Public listing* section of the same page).

Campuses come from the **Campuses** admin page, so make sure the campus exists
first.

> **Best Practice:** Keep ministry names short and recognizable — they appear on schedules, the calendar, and the public directory.

## Members, leaders, and positions

On a ministry's **Members & leaders** tab you manage who is on the team:

- **Add someone** — type a name, choose **Member** or **Leader**, and add them.
- **Make leader / Make member** — change someone's standing on the team.
  **Leaders** are shown on the ministry's overview.
- **Positions** — what someone does in the ministry (Usher, Emcee), separated
  by commas.
- **Remove** — take someone off the team. Past schedules are not changed.

When a campus is chosen in the header, the tab shows that campus's members, with
a link to show everyone.

See **Roles, Leaders & Members** for granting a leader scheduling access.

## Ministries and scheduling

Once a ministry has members and serving roles, **Open schedule editor** lets a leader fill
the roles on upcoming activity dates. Assignments made there show up on:

- each person's **My Schedule**,
- the **Calendar** (as assignment items),
- and the ministry's **Schedule** tab.

## Campus filtering

Everything ministry-related respects the campus selector:

- **A specific campus** shows only that campus's ministries, leaders, and people.
- **All campuses** aggregates across locations.

If a leader or member seems to be "missing," confirm the campus selector isn't
pinned to a single campus.
