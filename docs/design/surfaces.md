# Ekklesia surfaces (Phase 2)

Phase 1 moved the Church Portal onto the redesigned database with behaviour
preserved. Phase 2 reorganises the product into workspaces and modernises the
experience. It does not reimplement proven functionality.

## Principles

- **One application, several workspaces.** Not separate apps.
- **Preserve what works.** The Calendar, the Serving/Scheduling UI (schedule
  editor, board, rosters) and every printable keep their layouts, interactions
  and output. They are fitted into the new shell with only the visual
  consistency changes that requires.
- **Redesign what is weak.** People & Records, Ministry workspaces, Visitors &
  RSVPs and Admin may be substantially reworked.
- **Oikonomia is the visual reference, not a template.** Borrow the application
  shell, navigation pattern, spacing, typography, forms, cards, tables and
  interaction patterns where they fit. Ekklesia keeps its own identity (the
  church's theme presets, forest green by default) and a denser information
  layout suited to records and schedules.
- **Themes keep working.** Colours come from the active theme preset's CSS
  variables (`--deep`, `--teal`, `--ink`, `--muted`, `--line`, `--paper`,
  `--soft`, `--bg`, `--gradient-top`, `--gradient-mid`, `--radius`, `--spacing`,
  …) and the derived tokens in `portal_theme_style_block()`.
- **Behaviour and authorization are unchanged.** Moving a page never widens who
  may see or do it. Old URLs keep working (redirect when a page moves).
- **Print stays clean.** Shell chrome never prints.
- **Phone width works** (no horizontal scroll at 390px); keyboard focus visible.

## Workspaces and navigation

The application sidebar lists workspaces. The current workspace expands to show
its pages; each workspace page also shows the workspace tabs at the top of the
content. Items a user may not use are not shown (same rules as today:
signed-in, `admin`, `perm:<permission>`).

| Workspace | Pages (label → URL) | Who |
|---|---|---|
| **Home** | Home `/` · My schedule `/my-schedule` · My availability `/availability` · Account `/account` | signed in (Home is public) |
| **People & Records** | Directory `/people` · Member records `/admin/people` · Households `/admin/families` · Import & export `/admin/maintenance/import` · Record history `/people/history` · Record settings `/admin/options` | Directory: as today; the rest admin |
| **Ministries** | Ministries `/ministries` · each ministry `/ministries/{id}` with tabs Overview · Members & leaders · Serving roles · Schedule · Manage ministries `/admin/ministries` | as today; managing needs `perm:manage_ministry_roles` / admin |
| **Events & Calendar** | Calendar `/calendar` · Events `/events` · New event `/events/new` · Event categories `/admin/event-types` · Calendar settings `/admin/calendar` · Print calendar `/calendar/print-setup` | as today |
| **Serving & Scheduling** | Schedules `/schedules` · Schedule board `/schedule-board` · Rosters `/rosters` · Printables `/printables` | as today |
| **Visitors & RSVPs** | Visitors `/visitors` (registrations: review, match, promote) · RSVPs `/visitors/rsvps` (by event, attendance) · Access codes `/visitors/access` · public links to the sign-up form and RSVP pages | admin (as the outreach pages today) |
| **Admin** | Users & access `/admin/users` · Church information `/admin/church-info` · Campuses `/admin/campuses` · Website & appearance (announcements, home banner, theme, header, footer, quick links) · Backups & maintenance `/admin/maintenance` · Activity history `/admin/history` · System `/admin/system` | admin |

Decisions:

- Import & export belongs to People & Records only (the workbook export moves
  out of Backups & maintenance).
- Members & Leaders is a tab of each ministry workspace; `/admin/groups-and-ministries`
  redirects there.
- Visitors & RSVPs is one workspace and one workflow in the main app, replacing
  `/admin/outreach`. The standalone module admin pages under `people_signup/`
  and `events_rsvp/` keep working for greeters who use an access code instead of
  a login.
- Anonymous visitors see only the public items: Home, Calendar, Events,
  Ministries, and the sign-up / RSVP links.

## Shell

- **Desktop (≥1024px):** fixed left sidebar (about 248px, collapsible to icons)
  in the theme's deep colour with the church brand at the top, workspace groups,
  and the signed-in user at the bottom. A slim top bar over the content holds the
  current workspace / page title, campus selector, search and user menu.
- **Below 1024px:** no persistent sidebar; the menu button opens the same
  navigation in the existing drawer.
- The top-bar tab row driven by `config/chrome.json` `primaryNav` is replaced by
  the workspace sidebar. `chrome.json` keeps brand and footer text.
- Existing page structure (`<div class="shell">` → `portal_header()` → `<main>` →
  `portal_footer()`) keeps working unchanged; the shell offsets content for the
  sidebar with CSS.

## Kit (for redesigned pages)

Server-rendered PHP helpers and `ek-` CSS classes, defined once in the shell so
pages do not carry their own copies:

- `ek_page_header(string $title, string $description = '', string $actionsHtml = ''): string`
- `ek_workspace_tabs(string $basePath, string $workspaceId, string $activePageId, ?array $actor): string`
- Classes: `ek-page`, `ek-card`, `ek-card-head`, `ek-card-body`, `ek-grid`,
  `ek-stats` / `ek-stat`, `ek-table` (with `ek-table-wrap` for horizontal scroll
  on small screens), `ek-form`, `ek-field`, `ek-input`, `ek-select`, `ek-btn`
  (`ek-btn-primary`, `ek-btn-quiet`, `ek-btn-danger`), `ek-badge`, `ek-chip`,
  `ek-empty` (empty state with a next step), `ek-toolbar`, `ek-tabs`, `ek-alert`
  (`is-ok`, `is-error`).
- Kit styles are scoped to `ek-` classes: they do not restyle the Calendar,
  Scheduling or printable pages.

## Checkpoints

Targeted automated checks while building; a browser walkthrough when each
workspace is complete (desktop and phone width); a final integrated walkthrough,
then deploy to the demo.
