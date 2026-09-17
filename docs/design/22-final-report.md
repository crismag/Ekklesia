# Final Report — Owner Items #8–#16 and the Acceptance Sweep

13 commits on `main`, ahead of `origin/main`. **Not deployed** — deployment is
an outward-facing action and has been left for explicit approval.

## Test and build status

| Suite | Result |
|---|---|
| `tests/Regression/source-contracts.mjs` | 29 / 29 |
| `tests/Regression/run.php` | 37 / 37 |
| `tests/Architecture/BoundaryTest.php` | passing (was **fatal-erroring**) |
| `tests/Regression/anonymize-logic.php` | 13 / 13 (new) |
| `tools/check-privacy.php` | 18 checks, 0 failures |
| `tools/check-contrast.php` | 135 pairs × 9 presets, 0 failures |

No build step exists — the stack is server-rendered PHP with no bundler.

## Acceptance status

`tools/acceptance-audit.mjs`, run against local for **anonymous, member and
admin**:

| Metric | Before | Now |
|---|---|---|
| `missingSkip` | 10 | **0** |
| `campusHidden` | 10 | **0** |
| `searchHidden` | 10 | **0** |
| `sub24` (WCAG 2.5.8) | 57 | **0** |
| `missingMain` | 10 | **0** |
| overflow / clippedNav / missingH1 / duplicateMain | 0 | 0 |
| High or Medium findings, any route or role | — | **none** |

Plus two sweeps of my own across 17–18 routes × 3 roles: **no stuck loading
spinners and no raw server error text anywhere**, and no JavaScript exceptions
(the only console errors were 401s, which are the API correctly refusing
anonymous requests).

**Nothing was achieved by weakening a check.** One exemption was added — the
deliberately chrome-less `/password/change`, which accounted for 30 of the 40
shell findings across 10 role × viewport combinations. Giving that page
navigation would let a user walk away from a mandatory password change, so those
findings could never be fixed. The exemption names one route and is **reported
in the summary** as `chromelessRouteChecksExempted`, not silently dropped.

## Two measurement bugs corrected

Worth calling out separately, because both would have caused wasted or actively
harmful work:

- **The CSS debt inventory over-reported.** Viewport meta showed 2 missing when
  the true figure was 0 — `admin.php` renders through a shell that emits the tag,
  which a per-file scan cannot see. `outline:none` showed 12 defects when the
  true figure was 1 — the replacement indicator is usually declared in a separate
  rule, and shell CSS built by PHP concatenation arrived as `. '.selector` so
  never matched its own indicator. A debt metric that over-reports drives churn
  through correct code.
- **The acceptance audit measured the wrong environment.** Its four standalone
  routing checks were hardcoded to production URLs, so a run pointed at local
  reported production's routing as though it were the system under test.

## Remaining defects

None known at High or Medium severity in the portal itself. Two accepted
conditions, both documented:

1. `#portal-main:focus{outline:none}` — the skip-link target. A programmatic
   focus destination, not an interactive control; suppressing a ring around a
   whole page region is the conventional treatment. Reviewed and retained.
2. The standalone header-band gradient does not follow the theme (below).

## Structural risks

**1. The development database contains real PII — unresolved, and not fixable
from inside the application.** 252 real people with addresses, phone numbers,
emails and dates of birth. The anonymisation tooling is complete, tested and
gated, but the dev MySQL user holds `USAGE` on `*.*` and `ALL PRIVILEGES` only on
the source, so it **cannot create the target database, and the only database it
can write to is the one holding the real data**. Anonymising in place would be
destructive against the sole copy, so I stopped. This is the largest outstanding
privacy risk in the repository.

**2. Local runs nginx; production runs Apache.** Routing rules now live in the
repo and are reviewable and restorable — but still **not testable**, because
nginx never reads `.htaccess`. Two past outages came from this gap. Any routing
change must be verified against production after deploy.

**3. Cascade order remains the dominant failure mode.** This programme's CSS
regressions have almost all come from specificity or rule order, not wrong
values — most recently two `@media(max-width:900px)` blocks in `ministries.php`
where the second silently reverted the first's 24px touch target. This is why
the CSS debt plan explicitly refuses a repository-wide substitution.

## Deferred recommendations

- **Create `christlike_dev` and grant on it** (one SQL statement, in
  `19-dev-data-anonymization.md`), then run the copy-and-anonymise. Highest
  priority.
- **Add `--band-top` / `--band-bottom` to all nine presets**, seeded with the
  current `#0e6a5e` / `#117b6d` so the default is preserved exactly, then point
  the standalone gradient at them. Completes theme support; needs a design
  decision on the other eight presets.
- **CSS debt Stage 1** — 193 unambiguous literals across ~28 files, ≤8 per file,
  one file at a time. `#fff` is deliberately excluded: it maps to four different
  tokens depending on meaning, and guessing wrong yields a contrast failure.
- **Run Apache locally** (container or second vhost) to make routing testable.
- Extracting the 47 inline `<style>` blocks remains the highest-risk change
  available and should not be attempted without a visual-regression baseline.

## Files produced

`shared/theme-tokens.php` · `events_rsvp/index.php` · `tools/anonymize-dev-db.php`
· `tests/Regression/anonymize-logic.php` · `.htaccess` · `public/.htaccess` ·
`docs/deploy/README.md` · `docs/deploy/site-root.htaccess` ·
`docs/design/19-dev-data-anonymization.md` · `20-css-debt-strategy.md` ·
`21-standalone-theme-support.md` · this report.

## Owner decisions needed

1. **Create the dev database and grant** so anonymisation can run. Until then,
   development continues against real congregational data.
2. **Deploy these 13 commits?** Not done — awaiting approval. Note `#12`
   (`/events_rsvp/`) only takes effect in production, where the bare 403 is.
3. **Band colours for the other eight presets** (see above).
4. **Whether to run Apache locally**, accepting the dev-environment change.

## Note on local test accounts

To exercise signed-in coverage I reset the passwords of two pre-existing local
audit accounts (`audit.member`, `audit.admin`) in the **local development
database only**, and cleared their forced-password-change flag. No production
credential was used or changed, and no credential was written to the repository.
