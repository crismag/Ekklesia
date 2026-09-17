# Permissions, Login & Accounts

The portal has its own accounts and roles. This section explains how people sign
in, the kinds of access, and how accounts are created and managed.

## Signing in

- Go to the sign-in page from the **Sign in** button in the top bar, or `/login`.
- Sign in with your **email** and password.
- After signing in you'll see your dashboard, your schedule, and any tools your role grants. Anyone with administration sees **Administration** in the user menu.

If you're new, an administrator creates your account and shares a temporary
password. You may be asked to change it on first sign-in.

## Access types (roles)

A portal account can hold one or more **roles**, and each role can be scoped to a
campus and/or a ministry:

- **Admin** — full control. An admin with **no campus/ministry scope** is a **portal-wide admin** (sees the whole control panel). A scoped admin is limited to that area.
- **Leader** — manages a ministry's schedules and people within their scope.
- **Scheduler** — can build and edit schedules.
- **Member** — the standard signed-in account: your own schedule, calendar, and profile.

> **Tip:** "Portal-wide admin" is the important distinction. The Administration entry links to the control panel only for portal-wide admins; scoped users see it but it does nothing.

## What each role can do

| Capability | Member | Scheduler | Leader | Portal-wide Admin |
| --- | --- | --- | --- | --- |
| View own schedule & calendar | ✓ | ✓ | ✓ | ✓ |
| Manage own profile photo & campus | ✓ | ✓ | ✓ | ✓ |
| Build / edit schedules | | ✓ | ✓ (in scope) | ✓ |
| Manage a ministry's people & roles | | | ✓ (in scope) | ✓ |
| See Ministry & Leadership events | | | ✓ | ✓ |
| Member records, Families, Member types, Campuses | | | | ✓ |
| Maintenance (backup, import, export) | | | | ✓ |
| Create accounts & assign roles | | | | ✓ |
| Church Info, Appearance, Site settings | | | | ✓ |

### Ministry and Leadership events

Every event has a **type**, and a type says who it is for. Types marked
*Leaders only* — **Ministry events** and **Leadership** — are hidden from
members everywhere: the events list, the calendar, and a direct link all
behave as though the event does not exist. Nothing is merely greyed out.

Ministry and Leadership are the same access level. They are two categories so
a calendar can show one without the other, not two tiers of secrecy.

Schedulers can create and manage events but are **not** given this visibility,
so an elders' meeting stays private from the person building the roster.
Portal-wide admins always see everything.

## Managing accounts (Users & access)

Portal-wide admins manage logins on **Admin → Users & access**. The list shows every
login with the person it belongs to, its roles, whether it is active and when it last
signed in; search and the filters find, for example, logins that must change their
password or have never signed in. Open a login to change it:

1. **Add a login** — enter an email, display name, and a password (min 12 characters). Optionally give it an initial role. New logins are set to *must change password at next sign-in* by default.
2. **Link it to a person** — find the member record the login belongs to. A login belongs to one person: a login already linked to someone else, or a person who already has a login, is refused.
3. **Assign roles** — add one or more roles, each optionally scoped to a campus and/or ministry.
4. **Reset password** — set a new password for someone who's locked out.
5. **Activate / deactivate** — turn access off without deleting the login.

Every change made there is recorded, and **Admin → Activity history** shows who changed
which login or record, and when.

### Safety guards

The system protects against locking everyone out:

- You **can't remove the last portal-wide admin** role.
- You **can't deactivate or delete the last active portal-wide admin**.
- You **can't deactivate or delete your own account**.

## Standalone module access codes

The guest **Sign-up** and **RSVP** modules use their own admin access codes
rather than a portal login. Each code is a fixed **ChristLikeness** prefix plus a
rotating **WORD ID** that expires (default one week). Manage the code — set or
generate the word and its duration — from each module's *Access code* page,
reachable from **Sign-ups & RSVP** in the admin sidebar.

> **Warning:** Passwords must be at least 12 characters. Share temporary passwords privately, and encourage users to change them on first sign-in.
