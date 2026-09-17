# Church Portal regression harness

How to run the automated checks. These tests record current behavior, including
known visual gaps. Do not restyle production UI only to make optional
Playwright a11y/overflow checks green unless you intend that product change.
Set `REGRESSION_STRICT=1` only when you want overflow/a11y/viewport to fail CI.

## What exists

| Layer | Location | Needs PHP app? | Needs DB? |
|---|---|---|---|
| CSS debt inventory | `tools/css-debt-inventory.mjs` → `reports/css-debt-inventory.*` | No | No |
| Source contracts | `node tests/Regression/source-contracts.mjs` | No | No |
| PHP invariants | `php tests/Regression/run.php` | PHP 8.2+ CLI | No |
| Architecture tests | `php tests/Architecture/BoundaryTest.php` | PHP 8.2+ CLI | No |
| Playwright e2e | `npx playwright test` | PHP built-in server (or `PORTAL_E2E_BASE_URL`) | Optional; many HTML routes render with `$actor = null` |

There was **no** Playwright suite before this work. Existing coverage was
`tests/Architecture/BoundaryTest.php` only.

## Viewports

Reusable list: `tests/e2e/helpers/viewports.ts` (`PORTAL_VIEWPORTS`)

- 360×800, 390×844, 430×932, 768×1024, 1024×768, 1440×900
- Campus chrome collapses at **820px** (`CAMPUS_NAV_COLLAPSE_PX`). CSS uses
  `display: none` on `.topbar-campus`; the `#campusSelect` node and
  `portal_campus_id` cookie must remain.

## Commands

```bash
# CSS baseline (Node only)
node tools/css-debt-inventory.mjs

# Source contracts (Node only)
node tests/Regression/source-contracts.mjs

# Service + source invariants (PHP)
php tests/Regression/run.php
php tests/Architecture/BoundaryTest.php

# Browser suite against php -S (default http://127.0.0.1:8765)
npm install
npx playwright install chromium
npx playwright test

# Point at an already-running portal (skip php -S)
PORTAL_E2E_SKIP_WEBSERVER=1 PORTAL_E2E_BASE_URL=http://127.0.0.1:8765 npx playwright test

# Treat overflow / a11y / missing viewport as failures
REGRESSION_STRICT=1 npx playwright test
```

Composer aliases: `composer test:architecture`, `composer test:regression`.

npm aliases: `npm run inventory:css`, `npm run test:contracts`, `npm run test:e2e`.

Dev server used by Playwright:

```bash
php -S 127.0.0.1:8765 -t public public/index.php
```

Leave `PORTAL_BASE_PATH` empty for `php -S`. Production mount is `/church_portal`.

## After a UI change

1. Re-run `node tools/css-debt-inventory.mjs` after CSS/token work and compare
   `reports/css-debt-inventory.json` totals (hardcoded colors, breakpoint px
   values, viewport meta coverage, `outline: none`, `:focus` selectors).
2. Re-run source contracts if touching People privacy, campus cookie name,
   admin `confirm()` copy, or `schedule_editor_denied`.
3. Re-run Playwright public smoke + responsive sample on every chrome/layout
   change. Soft findings land in `reports/generated/e2e-findings.json`.
4. Never weaken `PROTECTED_MUTATIONS` expectations. A 401/403/302-to-error is
   success; a 200 that looks like a write succeeded is a **real** failure.
5. Intentionally unauthorized **API** responses are not failures. Unauthenticated
   **HTML GET** of `/admin`, `/my-schedule`, etc. currently **renders** — that is
   documented product behavior, not a bug to “fix” in tests.
6. Do not hit production with destructive POSTs. Local accounts need a
   provisioned DB; this harness does not invent credentials.

## Behaviors now protected

- Public People directory omits `contact`/`address` and masks surnames
  (`maskedName`).
- Member role cannot `createEvent` / `cancelOccurrence`; campus-scoped leaders
  cannot create events for out-of-scope campuses.
- Members cannot `saveAssignments`; leaders cannot write another ministry’s
  schedule.
- Unauthenticated privileged mutations are rejected (or 5xx from missing DB,
  recorded as environment, never as success).
- `portal_campus_id` is not cleared when compact CSS hides the campus select.
- Admin destructive actions still use `confirm()`.
- Forced password change flag `mustChangePassword` remains on login JSON.
- Schedule editor deny notice `schedule_editor_denied` remains in `routes/web.php`.

## Known gaps (do not treat as implementation bugs in this phase)

- **No PHP in some agent VMs** — Playwright `webServer` and `run.php` cannot
  execute until PHP is installed. Node inventory + source contracts still run.
- **No authenticated fixtures** without `.env` + ChurchCRM/portal DB. Live
  privacy JSON, campus `<select>` options, and role-sensitive **UI** actions
  cannot be exercised end-to-end here.
- HTML pages may return **JSON 401** or **5xx** when AuthService is wired and
  DB is missing. Recorded as findings (`html-json-mismatch`, `html-5xx`).
- Overflow, unlabeled controls, missing `html lang`, missing viewport meta,
  and missing focus rings are **baselines** unless `REGRESSION_STRICT=1`.

## Recommendations for the visual architecture / implementation agent

- Keep changes out of `_portal-shell.php` unless a later phase explicitly owns
  chrome. Prefer page-local CSS until a token pass is scheduled.
- After each visual PR: inventory diff + Playwright sample viewports + source
  contracts.
- When introducing a shared breakpoint system, drive
  `uniqueBreakpointPxValues` down without changing the 820px campus-collapse
  **behavior**.
- Add an authenticated Playwright project only after a dedicated test user and
  DB seed exist. Until then, keep PHP service tests as the authz source of truth.
