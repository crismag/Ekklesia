# Church Portal CSS technical-debt inventory

Generated: 2026-08-25T15:19:27.246Z

This is a **baseline**, not a rewrite. Later UI work should drive these numbers down without changing product behavior.

## Totals

| Metric | Count |
|---|---|
| `filesScanned` | 102 |
| `htmlPages` | 40 |
| `inlineStyleBlocks` | 49 |
| `hardcodedColorLiterals` | 1479 |
| `uniqueColorLiterals` | 295 |
| `uniqueBreakpointPxValues` | 59 |
| `minWidthDeclarations` | 92 |
| `outlineNoneDeclarations` | 12 |
| `focusSelectors` | 33 |
| `pagesWithOwnViewportMeta` | 39 |
| `pagesInheritingViewportMeta` | 1 |
| `pagesMissingViewportMeta` | 0 |
| `colorLiteralsCollidingWithThemeTokens` | 14 |
| `colorLiteralOccurrencesCollidingWithThemeTokens` | 416 |
| `neutralColorLiterals` | 26 |
| `oneOffColorLiterals` | 255 |
| `outlineNonePaired` | 14 |
| `outlineNoneUnpaired` | 1 |
| `classesUsedInFourOrMoreFiles` | 50 |

## Viewport meta coverage

All scanned HTML PHP pages include a viewport meta tag.

## Breakpoints (`max-width` / `min-width` px values)

| px | occurrences | sample files |
|---|---|---|
| 24 | 2 | resources/views/ministries.php, printable/_printable-shell.php |
| 36 | 1 | resources/views/_portal-shell.php |
| 44 | 3 | resources/views/calendar.php, resources/views/ministry-dashboard.php, resources/views/roster-editor.php |
| 52 | 1 | resources/views/_portal-shell.php |
| 56 | 1 | resources/views/calendar.php |
| 64 | 2 | resources/views/_portal-shell.php, resources/views/admin-options.php |
| 70 | 2 | resources/views/admin-people-import.php, people_signup/admin_review.php |
| 78 | 2 | resources/views/_portal-shell.php, resources/views/people.php |
| 84 | 1 | resources/views/_portal-shell.php |
| 90 | 1 | resources/views/admin-people-import.php |
| 96 | 3 | resources/views/admin-person-view.php, events_rsvp/admin_attendance.php, events_rsvp/assets/rsvp.css |
| 100 | 1 | resources/views/_portal-shell.php |
| 104 | 1 | resources/views/_portal-shell.php |
| 110 | 1 | resources/views/_portal-shell.php |
| 120 | 4 | resources/views/admin-families.php, resources/views/admin-people.php, resources/views/schedule-editor.php, printable/_printable-shell.php |
| 130 | 3 | resources/views/admin-users.php, resources/views/schedule-editor.php |
| 132 | 4 | resources/views/calendar-settings.php, resources/views/events-list.php, resources/views/ministry-dashboard.php, resources/views/my-schedule.php |
| 140 | 2 | resources/views/admin-campuses.php, resources/views/admin-people-import.php |
| 148 | 1 | resources/views/schedule-editor.php |
| 150 | 2 | resources/views/people.php, admin/groups_and_ministries/ministries.php |
| 160 | 4 | resources/views/_portal-shell.php, resources/views/admin-people-import.php, resources/views/schedule-editor.php |
| 180 | 9 | resources/views/_portal-shell.php, resources/views/admin-options.php, resources/views/admin-people-import.php, resources/views/calendar-settings.php |
| 188 | 4 | resources/views/calendar.php, resources/views/events-list.php, resources/views/index.php, resources/views/ministry-dashboard.php |
| 190 | 2 | resources/views/admin-person-view.php, resources/views/schedule-editor.php |
| 200 | 5 | resources/views/admin-options.php, resources/views/person-detail.php, resources/views/schedule-editor.php, printable/birthdays.php |
| 220 | 6 | resources/views/_portal-shell.php, resources/views/roster-editor.php, printable/_printable-shell.php, printable/events.php |
| 230 | 2 | resources/views/person-detail.php, admin/groups_and_ministries/ministries.php |
| 240 | 1 | resources/views/schedule-editor.php |
| 320 | 1 | resources/views/roster-editor.php |
| 360 | 1 | resources/views/schedule-editor.php |
| 420 | 3 | resources/views/ministry-chooser.php, resources/css/app.css, people_signup/assets/signup.css |
| 480 | 1 | resources/views/admin-person-edit.php |
| 520 | 3 | resources/views/calendar.php, resources/views/ministry-chooser.php, resources/views/password-change.php |
| 540 | 1 | resources/views/events-detail.php |
| 560 | 1 | resources/views/_admin-shell.php |
| 600 | 1 | resources/views/admin-family-edit.php |
| 640 | 14 | resources/views/_portal-shell.php, resources/views/admin-campuses.php, resources/views/admin-church-info.php, resources/views/admin-outreach.php |
| 650 | 2 | resources/views/index.php, resources/views/login.php |
| 670 | 1 | resources/views/ministry-dashboard.php |
| 700 | 2 | resources/views/availability.php, resources/views/ministry-dashboard.php |
| 720 | 8 | resources/views/_portal-shell.php, resources/views/admin-people.php, resources/views/admin-person-edit.php, resources/views/availability.php |
| 760 | 13 | resources/views/_portal-shell.php, resources/views/admin-person-view.php, resources/views/calendar-settings.php, resources/views/calendar.php |
| 800 | 3 | resources/views/roster-editor.php |
| 820 | 12 | resources/views/_portal-shell.php, resources/views/account.php, resources/views/calendar-settings.php, resources/views/calendar.php |
| 821 | 2 | resources/views/calendar.php, resources/views/index.php |
| 840 | 1 | resources/views/admin-hero.php |
| 860 | 2 | resources/views/_portal-shell.php, resources/views/events-detail.php |
| 880 | 6 | resources/views/_admin-shell.php, resources/views/_portal-shell.php, resources/views/admin-maintenance.php, resources/views/admin-people-import.php |
| 900 | 7 | resources/views/_admin-shell.php, resources/views/_portal-shell.php, resources/views/ministries.php, resources/views/ministry-dashboard.php |
| 920 | 1 | resources/views/my-schedule.php |
| 980 | 4 | resources/views/docs/leader.php, resources/views/docs/member.php, resources/views/docs/shortcuts.php, resources/views/iot.php |
| 1000 | 2 | resources/views/index.php, resources/views/ministry-dashboard.php |
| 1023 | 4 | resources/views/_portal-shell.php, resources/views/docs.php, resources/views/people.php |
| 1024 | 1 | resources/views/docs.php |
| 1080 | 3 | resources/views/calendar-settings.php, resources/views/calendar.php |
| 1100 | 2 | resources/views/_portal-shell.php, resources/views/index.php |
| 1120 | 1 | resources/views/people.php |
| 1180 | 2 | resources/views/index.php, resources/css/app.css |
| 1200 | 1 | resources/views/_portal-shell.php |

## Heaviest files by hardcoded color literals

| File | colors | `<style>` blocks | media queries | min-width decls | outline:none | :focus |
|---|---|---|---|---|---|---|
| `resources/views/_portal-shell.php` | 185 | 4 | 15 | 12 | 7 | 14 |
| `resources/views/schedule-editor.php` | 132 | 1 | 1 | 12 | 0 | 0 |
| `resources/views/ministry-dashboard.php` | 68 | 1 | 4 | 4 | 0 | 0 |
| `resources/views/calendar.php` | 60 | 1 | 6 | 8 | 0 | 0 |
| `resources/views/index.php` | 56 | 1 | 4 | 6 | 0 | 1 |
| `resources/views/admin-person-view.php` | 49 | 1 | 1 | 3 | 0 | 0 |
| `people_signup/admin_review.php` | 49 | 1 | 0 | 3 | 1 | 2 |
| `resources/views/admin-people-import.php` | 41 | 1 | 1 | 4 | 0 | 2 |
| `resources/views/admin-users.php` | 39 | 1 | 1 | 1 | 0 | 0 |
| `resources/views/people.php` | 37 | 1 | 4 | 6 | 0 | 0 |
| `resources/views/admin-campuses.php` | 36 | 1 | 1 | 1 | 0 | 0 |
| `resources/views/calendar-settings.php` | 35 | 1 | 3 | 1 | 0 | 0 |
| `resources/views/roster-editor.php` | 35 | 1 | 3 | 2 | 0 | 0 |
| `resources/views/admin-family-edit.php` | 33 | 1 | 1 | 0 | 0 | 0 |
| `events_rsvp/assets/rsvp.css` | 33 | 0 | 0 | 0 | 1 | 3 |
| `resources/views/admin-people.php` | 32 | 1 | 1 | 1 | 0 | 0 |
| `resources/views/admin-hero.php` | 31 | 1 | 1 | 0 | 0 | 0 |
| `resources/views/my-schedule.php` | 29 | 1 | 2 | 2 | 0 | 0 |
| `resources/views/admin-families.php` | 28 | 1 | 0 | 1 | 0 | 0 |
| `resources/views/docs.php` | 28 | 1 | 4 | 2 | 0 | 0 |
| `resources/views/ministries.php` | 28 | 1 | 4 | 3 | 0 | 1 |
| `resources/views/password-change.php` | 27 | 1 | 0 | 0 | 1 | 1 |
| `people_signup/assets/signup.css` | 26 | 0 | 1 | 0 | 1 | 3 |
| `resources/views/_admin-shell.php` | 25 | 1 | 2 | 1 | 0 | 0 |
| `resources/views/person-detail.php` | 24 | 1 | 1 | 3 | 0 | 0 |

## How to re-run

```bash
node tools/css-debt-inventory.mjs
```

Compare `reports/css-debt-inventory.json` before and after visual modernization.
