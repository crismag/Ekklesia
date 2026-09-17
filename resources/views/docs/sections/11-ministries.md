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

Three doors, none of them named the same thing:

- **Ministries** (top bar) — the chooser. Open a team to see its members, serving grid, and posted lists.
- **Members & leaders** (Administration) — create a team and who is on it, with *group roles* (Member, Teacher, Leader).
- **Ministry list** (Administration) — which ministry pages appear on the public site. It does not create teams.

> **Tip:** A ministry's **schedule assignments** (who serves on which date) are separate from its **group roles** (a person's standing on the team). The first is the serving grid (or Calendar's day panel); the second is Members & leaders.

## Viewing ministries

Open **Ministries** from the top bar. Each ministry card shows its name, campus,
and current leaders. Selecting a ministry opens its dashboard with the members,
leaders, and recent schedule activity for that team.

- Use the **campus selector** in the top bar to focus on one campus. Choosing **All campuses** shows every ministry across locations.
- A ministry page at `/ministry/{name}` (for example `/ministry/ushers`) is a friendly shortcut to the same dashboard.

## Creating and editing a ministry

Ministries are created and configured by a portal-wide admin.

1. Open Administration → **Members & leaders**.
2. Add the ministry with its **name** and **campus**.
3. Save. The ministry can receive members, leaders, and schedules. Turn on public visibility separately under **Ministry list**.

Campuses come from the **Campuses** admin page (under *Church setup*), so make
sure the campus exists first.

> **Best Practice:** Keep ministry names short and recognizable — they appear on schedules, the calendar, and the public directory.

## Members, leaders, and roles

On **Members & leaders** you manage who is on each team and their group role:

- **Member** — the default standing for anyone on the team.
- **Teacher** / **Leader** — tags that mark elevated standing. **Leaders** are surfaced on the ministry page and can be granted scheduling access.

Leaders are filtered by campus, so a leader assigned to the Scarborough campus
is listed only under that campus's view.

See **Roles, Leaders & Members** for the full walkthrough of assigning roles and
granting a leader scheduling access.

## Ministries and scheduling

Once a ministry has members, its **Serving grid** button lets a leader fill
the roles on upcoming activity dates. Assignments made there show up on:

- each person's **My Schedule**,
- the **Calendar** (as assignment items),
- and the ministry dashboard.

## Campus filtering

Everything ministry-related respects the campus selector:

- **A specific campus** shows only that campus's ministries, leaders, and people.
- **All campuses** aggregates across locations.

If a leader or member seems to be "missing," confirm the campus selector isn't
pinned to a single campus.
