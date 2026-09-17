# Site & Navigation

The fastest way to learn where everything lives. There are three navigation
surfaces: the **top bar** (everyone), the **user menu** (signed-in), and the
**administration sidebar** (staff and leaders).

## The top bar

- **Brand / home** — returns to Home.
- **Primary nav** — Home, Calendar, Ministries, People, Events. Five tabs, in the order the work is usually done: look at the week, then whose work it is, then who, then what is on.
- **More** — the overflow menu, for the pages you want often but not constantly: My Schedule, Availability, Serving, Printables, Account, Docs and Administration. Docs is there rather than in the tabs; it is the page you read once and the calendar is the page you read daily.
- **Campus selector** — pins the whole site to one campus, or **All campuses**. This is the *only* campus control; pages no longer carry their own.
- **Search** — people, activities, ministries and pages. An activity found here opens that activity, not the list it came from.
- **Your name** — opens the user menu.

> **Tip:** **Serving** in the overflow opens Ministries. Staffing is reached through the ministry whose work it is, which is why there is no separate Serving page to get lost in.

> **Tip:** Most "why can't I see X?" questions come down to the **campus selector**. If a person, ministry or activity seems missing, set it to **All campuses**.

## The words this portal uses

Five words do most of the work. Two of them used to name the same thing
(serving vs a posted list), and **People** is easy to mix up with the admin
records page.

| Word | What it means |
| --- | --- |
| **Activity** | Anything on the calendar — a service, a meeting, a holiday, a training night. The Events page lists them and the URL is still `/events`. |
| **Serving** | Who is on the roles of an activity's date. The serving grid is where those names go. |
| **Posted list** | A standing rota with no activity behind it — a cleaning rotation, a pickup run. It is not a second kind of schedule. |
| **Calendar** | Where you look, and where you act on a day. Click a date for what is on it; **Print this view** is the wall calendar of what you are looking at. |
| **People** | The top-bar directory. Look someone up. Adding a record is **Member records** under Administration. |

A ministry's page offers **Serving grid** and **Posted lists** as separate
buttons, because they answer different questions.

## The user menu

Click your name in the top right. Everything about *you* is here, whether or not
you administer anything:

- **My profile** — your account, password and details.
- **My schedule** — where you are serving.
- **My availability** — when you cannot serve.
- **Administration** — only if your account includes it.
- **Sign out**.

This works the same on a phone. Personal pages are not part of Administration:
an ordinary member reaches them the same way an administrator does.

## Administration

Open it from the user menu, or go to `/admin`. The sidebar is ordered by how
often the work is done — everyday church work first, system administration last
— and **you only see what your account can actually use**. A ministry leader
who manages events sees Calendar & events and nothing else; if your account
includes no administration at all, the area says so plainly rather than showing
a menu that leads nowhere.

| Group | What is in it |
| --- | --- |
| **Overview** | What needs attention, common tasks, how the records stand |
| **People & families** | Member records, Families, Sign-ups & RSVP, Member types |
| **Ministries** | Members & leaders, Ministry list |
| **Calendar & events** | Calendar, Events, Event categories |
| **Church setup** | Church information, Campuses |
| **Portal management** | Users & access, Announcements, Home page banner, Theme & colours, Header menu, Footer, Quick links |
| **Data & maintenance** | Import members, Backups & exports |
| **Advanced** | System information, Development roadmap |

**Advanced** is deliberately last and deliberately dull. It holds read-only
troubleshooting information — configuration files, environment details — useful
when something is wrong or somebody is helping you remotely, and at no other
time.

## Common destinations

| I want to… | Go to |
| --- | --- |
| Look someone up | **People** (top bar) |
| Add or edit a member record | Administration → **Member records** |
| Bring a roster in from a spreadsheet | Import members |
| Download member records, or make a backup | Backups & exports |
| Manage a household | Families |
| Review guest sign-ups and event RSVPs | Sign-ups & RSVP |
| Change the dropdown options (classifications, member types) | Member types |
| Open a ministry workspace | **Ministries** (top bar) |
| Add or edit a ministry team, and who is on it | Administration → **Members & leaders** |
| Choose which ministries appear on the public site | Administration → **Ministry list** |
| See the church-wide calendar | Calendar |
| See what is on one day, and who is on it | Calendar — click the date (right panel) |
| Reopen the layers you use every Sunday | Calendar → **Open view…** |
| Print the month you are looking at | Calendar → **Print this view** |
| Print a roster sheet, event list or birthdays | Printables |
| Create an activity on the date you are looking at | Calendar — **New event on this day**, or Events |
| Create or edit an activity from the list | Events |
| Staff the roles on a date | Calendar's day panel, or the ministry's **Serving grid** |
| Keep a standing rota with no activity behind it | The ministry's **Posted lists** |
| Change an activity's colour, or who can see it | Event categories |
| Add or edit campuses | Campuses |
| Update church name, contact and address | Church information |
| Create logins and say what people may do | Users & access |
| Restyle the site | Theme & colours, Header menu, Footer |

## Home

Home is the **church visiting page** — the same public feed for guests and
members:

- **Everyone** sees the welcome rotator, upcoming events, announcements, ministries, and visit/contact information. Signing in is not required.
- **Signed-in members** also get a **For you** overlay: greeting, this week, my schedule and next assignments. It never replaces the public feed.
- **Leaders** also get **Lead a ministry**.
- **Admins** write announcements under **Portal management → Announcements**.

## Public vs. signed-in

- **Public pages** show a safe, limited view — first name and last initial, no contact details or addresses.
- **Signing in** unlocks contact details and your own schedule.
- What you can *see* and what you can *do* are checked on the server every time, not decided by which links are on screen.
