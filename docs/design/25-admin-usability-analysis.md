# Admin Area — Usability Analysis

Measured across 22 admin routes signed in as a portal-wide admin, at 1440px and
390px. Every number below was observed, not estimated.

## Why the dashboard feels empty — it is a third navigation layer

There are **three** navigation surfaces stacked on top of each other:

| Surface | Items | Already in the sidebar |
|---|---|---|
| Sidebar (on every admin page) | 19 | — |
| `/admin` Overview tiles | 13 | **12** |
| `/admin/dashboard` | 2 links | **1** |

**`/admin/dashboard`'s entire unique contribution is one link: `/admin/hero`.**
It is 35 lines rendering four cards, each of which is a description plus an
"Open ›" link to somewhere else. It holds no data, no counts, no state, and
nothing you can act on. That is the whole answer to "it does not present much
value": it is a menu for a menu, and the menu it duplicates is permanently
visible three inches to the left.

`/admin` Overview has the same problem in weaker form — 12 of its 13 tiles
repeat the sidebar. Its one genuinely useful element is the environment
snapshot, which is real diagnostic information available nowhere else.

## A third of the advertised surface is not built

Rendered status badges across the admin area:

| Badge | Count |
|---|---|
| LIVE | 20 |
| PLANNED | 13 |
| BETA | 5 |

**`/admin/links` is 0 live and 2 planned** — a permanent sidebar entry whose
page exists only to say the feature does not exist. `/admin/calendar`,
`/admin/events` and `/admin/me` are each 1–2 live links plus 2 planned
placeholders; their live links go to pages reachable from the main portal
navigation anyway.

Being honest about what is not built is good practice. Giving each unbuilt
feature a permanent navigation slot is not: it inflates the sidebar to 19 items,
of which roughly a third lead to a description of future work.

## Per-section assessment

Measured at 1440px. "Own content" means content beyond the shared shell chrome.

| Section | Verdict | Evidence |
|---|---|---|
| **People** | **Strongest page.** The only list with both search and pagination | 25 rows shown, pager + search present |
| **Maintenance** | Real and useful — backups, import, export | 3 forms, 11 actions, 4 live tiles |
| **Families** | Works; list of 25 with a form | 25 rows |
| **Duplicate families** | **Worst page in the product** (below) | 82 rows, 37 forms, 167 inputs, 9,621px |
| **Options** | Functional but 55 separate forms | 55 forms, no bulk save |
| **System Users** | Functional, one form per row | 12 rows, 12 forms |
| **Campuses** | Fine at this scale | 2 rows, 5 forms |
| **Church Info** | Straightforward single form | 1 form, 13 inputs |
| **Theme / Hero** | Genuine editors | Hero: 26 inputs, 32 actions |
| **Header / Footer** | **Were completely broken** (below) | 0 populated fields, JS error |
| **Ministries / Groups** | Mixed; 2 planned items each | — |
| **Dashboard** | No value — one unique link | 2 links, 0 data |
| **Overview** | Duplicates sidebar; env snapshot is the exception | 12/13 tiles duplicated |
| **Calendar / Events / My Pages** | Link directories into the main portal | 1–2 live links + 2 planned each |
| **Sign-ups & RSVP** | Link directory into `people_signup` | no own content |
| **Site Links** | Nothing exists | 0 live, 2 planned |

## Defect found and fixed: Header and Footer editors were dead

`admin-header.php` and `admin-footer.php` built their config as
`htmlspecialchars(json_encode(...))` and embedded it **inside a `<script>`
block**. Script content is raw text and is never HTML-decoded, so the parser met
a literal `&quot;` and threw `Unexpected token '&'`. Because `const cfg` sits at
line 58 and the save handler at line 121 of the **same** block, nothing after
the failure ran — the Save button was never even bound.

Measured before the fix: **1 JS error and 0 populated fields on both pages.**
After: **0 errors, 12 populated fields on Header and 2 on Footer.**

So an administrator opening Header or Footer saw empty inputs and a button that
did nothing. Nothing could be saved, and nothing was corrupted — the handler
never attached — but the feature was entirely non-functional.

The same `htmlspecialchars(json_encode(...))` value is used correctly in
`index.php` and `admin-hero.php`, where it goes into an HTML **attribute** —
which *is* HTML-decoded, so escaping is right there. Only the two script-context
uses were wrong. Fixed with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`,
which is both valid JS and safe against `</script>` breakout.

## The one-form-per-row pattern

`/admin/families/duplicates` renders **82 rows, 37 forms and 167 inputs on a
single page — 9,621px on desktop and 12,911px on mobile**, roughly 13 phone
screens. It has **no pagination and no bulk actions**, so resolving duplicates
means scrolling a wall of controls and submitting them one at a time.

`/admin/options` (55 forms) and `/admin/users` (12 forms) share the pattern at
smaller scale. **No admin page has a bulk action of any kind.**

## What is genuinely good

- **No horizontal overflow at 390px on any admin page** — the responsive work
  holds up.
- **Authorization is consistent**: every mutating and download route checks
  `isPortalWideAdmin`, and the templates gate their content independently.
- **People** is a well-built list page and the right model for the others.
- The environment snapshot on Overview is real, useful diagnostic information.

## Recommendations, highest value first

1. **Give `/admin/dashboard` real content or remove it.** It should answer "what
   needs my attention?" — staged import batches awaiting review, pending guest
   sign-ups, when the last backup ran, unlinked portal accounts, people/campus
   counts. Every one of these is already reachable through an existing service
   (`makeMemberCampusImportService`, `makeMaintenanceBackupService`,
   `makeSystemUserService`, `makePersonAdminService`), so this is presentation
   work, not new plumbing. If that is not wanted, delete the page and let
   Overview be the landing screen.
2. **Paginate `/admin/families/duplicates` and add bulk resolve.** A 12,911px
   page with 167 inputs is not usable on a phone and barely usable on a desktop.
3. **Collapse the PLANNED sections.** Move the 13 planned items into a single
   "Roadmap" entry rather than giving each a permanent sidebar slot. That takes
   the sidebar from 19 items to about 12 real ones.
4. **Fold the link-only sections into the main portal.** Calendar, Events, My
   Pages and Sign-ups mostly point at pages already in the primary navigation.
5. **Trim Overview's duplicated tiles**, keeping the environment snapshot.
6. **Introduce bulk selection** on Options and Users, following whatever pattern
   the duplicates page adopts.

Items 1, 3, 4 and 5 are presentation-only and low risk. Item 2 is the largest
usability win but needs care, because the duplicates page performs destructive
merges.
