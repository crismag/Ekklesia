# Administration UX audit and redesign

## Phase 0 — findings

Measured against the running application with four accounts (portal-wide admin,
ministry leader, scheduler, ordinary member) at 1440px and 390px.

### 1. The navigation advertises capability nobody checks

`admin_sections()` in `resources/views/_admin-shell.php` returned the same 26
entries to every caller. It took no actor and had no permission argument, so a
member with no admin role saw Control board, Maintenance, Users & Access,
Appearance and the rest in full.

The pages themselves are properly guarded — a member's `/admin/users` renders
567 characters of chrome and **zero** account rows, against 2,146 characters
and 12 for an admin. So this was never a data leak, and the authorisation
boundary is where it should be: in the route handlers and the API.

It was a usability failure instead, and a specific one: every one of those 26
links led an unprivileged member to an empty page. The navigation promised
capability the application would then refuse to give, with no explanation.

### 2. Six pages had no way in

`/admin/announcements`, `/admin/hero` and `/admin/links` are working content
management screens that appeared in no menu. They were reachable only by typing
the URL. `/admin/families/duplicates` and `/admin/maintenance/import` were
reachable only from inside another page. `/admin/dashboard` is a second admin
home left over from the merge that produced the control board.

### 3. Rare, dangerous work sat in the most prominent slot

Maintenance — backups, restores, imports — was the second entry in the sidebar,
above People. Ordering by risk inverted: the least frequent and most
destructive area had the highest prominence.

### 4. Personal functions were filed under Administration

`Personal → My Pages` put account management inside the admin tree, so an
ordinary member had no route to it and an administrator reached their own
profile through a section about running the church.

Worse, **there was no sign-out control anywhere in the page chrome.** Signing
out required knowing to click your own first name in the header, landing on
`/account`, scrolling to "Your access", and pressing "Sign out of this device".

### 5. Development vocabulary had become product vocabulary

67 occurrences of `LIVE`, `BETA` and `PLANNED` badges across 14 admin views,
plus a Roadmap section in the primary navigation. These describe the state of
the software to the people building it, not the state of the church to the
people running it.

### 6. The control board listed implementation rather than work

Its section order was: At a glance, Needs attention, Directory breakdown,
Manage, Configuration files, Environment. Two of the six sections — JSON
filenames on disk with sizes and modification times, and `APP_ENV` /
`PORTAL_BASE_PATH` / PHP version — are troubleshooting information sitting on
the page an administrator opens to do their daily work.

"Needs attention" is the strongest thing on the page and it was second.

### 7. (Withdrawn) Duplicate route keys

An earlier pass reported that
`POST /admin/maintenance/import/{apply,discard,ingest,row}` were each defined
twice. They are not. Those names appear a second time as the right-hand side of
a deliberate legacy-alias map that points the older `/admin/people/import/*`
paths at the current handlers, preserving old bookmarks. Counting only actual
route definitions gives 88 keys, all distinct.

Recorded rather than deleted because the grep that produced it — matching the
route string anywhere in the file — is an easy mistake to repeat.

## Findings matrix

| Function | Persona | Frequency | Risk | Destination |
|---|---|---|---|---|
| People, Families | Office / membership admin | High | Medium | Church Operations |
| Ministries, leaders | Ministry leader | High | Low | Church Operations |
| Calendar, Events | Calendar coordinator | High | Low | Church Operations |
| Sign-ups & RSVP | Office staff | Medium | Low | Church Operations |
| Church info, Campuses | Church leader | Low | Medium | Church Setup |
| Users & access | Church administrator | Low | High | Portal Management |
| Appearance, header, footer, hero | Church administrator | Low | Low | Portal Management |
| Import members | Membership admin | Occasional | Medium | Data & Maintenance |
| Export, backup | Church administrator | Occasional | Low | Data & Maintenance |
| Restore | Church administrator | Very low | **Critical** | Advanced, confirmed |
| Config files, environment | Technical support | Very low | Low (read-only) | Advanced |
| Roadmap | Product/development | n/a | None | Advanced |
| My profile, schedule, account | Everyone | Daily | Low | **Out of Administration** |

## Final information architecture

```
Administration
  Overview                     /admin

  People & families            admin
    People, Families, Sign-ups & RSVP, Member types
  Ministries                   manage_ministry_roles
    Members & leaders, Ministry list
  Calendar & events            manage_events
    Calendar, Events, Event categories

  Church setup                 admin
    Church information, Campuses
  Portal management            admin
    Users & access, Announcements, Home page banner,
    Theme & colours, Header menu, Footer, Quick links
  Data & maintenance           admin
    Import members, Backups & exports

  Advanced                     admin
    System information, Development roadmap
```

Personal functions are **not** in this tree. They live in a menu under the
signed-in name in the header: My profile, My schedule, My availability,
Administration (only when authorised), Sign out.

## Decisions that departed from the brief

**No role model was introduced.** The brief sketches a Calendar Coordinator who
sees calendars but not backups. The existing authorisation is a single
`isPortalWideAdmin` boolean plus a `PortalPermission` enum, and the brief also
says not to invent a role model. So navigation filters on the permissions that
already exist: `manage_events` opens Calendar & events, `manage_ministry_roles`
opens Ministries, and everything else remains portal-admin. That delivers the
brief's example without new concepts.

**Recovery confirmations were not built, because recovery does not exist.**
The brief specifies careful wording for restoring a backup. There is no restore
anywhere in the portal — backups are written and downloaded, never applied.
Building one would add the most dangerous operation in the product under cover
of a UX pass. It is left undone deliberately.

**The "Manage" grid was kept.** It duplicates the sidebar, which argues for
removing it, but it is also the only place the areas are described rather than
merely named. It was trimmed instead: the personal entry and the two unbuilt
entries are gone.

## Accessibility sweep

Every administration page was measured at 1440px and 390px, for controls with
no accessible name, targets under 24px (measuring the *effective* target — a
checkbox inside a label is clicked via the label), tables with no accessible
name, horizontal page scroll, and console errors.

| | before | after |
|---|---|---|
| Controls with no accessible name | 84 across 8 pages | **0** |
| Tables with no accessible name | 5 | **0** |
| Targets under 24px | 100+ | **0**\* |
| Pages scrolling horizontally at 390px | 1 | **0** |

\* Two inline links remain under 24px — a breadcrumb and a link inside a
sentence. WCAG 2.5.8 exempts targets "in a sentence or otherwise constrained by
the line-height of non-target text", so they are compliant as they stand.

The single most common defect was a `<label>` rendered next to an input with no
`for`/`id` between them: it looks like a label, and is not one. That accounted
for most of the 84 — ten on Church information, thirteen on Campuses, five on
Users & access, and so on.

Two structural fixes were worth more than their size. `.sr-only` did not exist
in the codebase, so the first caption written against it would have rendered
visibly. And three scroll containers were not positioned ancestors, so an
absolutely-positioned caption inside a wide table escaped and pushed the whole
document sideways on a phone.

## Remaining issues

- The admin sidebar renders as `<details>` groups with no active-section
  memory across pages beyond the open group.
- Cards versus table on `/people` is a display-mode choice rather than a
  responsive breakpoint, so a phone still gets the table unless the user
  switches.
- Colour literals (`#0f4e97` for person and family name links) moved from
  inline `style` attributes into classes but are still literals; they belong in
  the theme tokens, which has its own contrast-gated process.
