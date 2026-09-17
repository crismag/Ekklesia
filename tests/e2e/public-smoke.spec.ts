import { test, expect, type Page } from '@playwright/test';
import { PUBLIC_HTML_ROUTES, SAMPLE_DETAIL_ROUTES } from './helpers/routes.js';
import { PORTAL_VIEWPORTS } from './helpers/viewports.js';
import {
  checkViewportMeta,
  collectA11yBaseline,
  collectInternalHrefs,
  isBenignConsole,
  isHtmlStatusAcceptable,
  measureOverflow,
  probeFocusVisibility,
  recordA11y,
  recordOverflow,
} from './helpers/pageChecks.js';
import { expectUnlessKnown, writeFindingsReport } from './helpers/findings.js';

async function openRoute(page: Page, route: string) {
  const errors: string[] = [];
  const pageErrors: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(msg.text());
  });
  page.on('pageerror', (err) => pageErrors.push(err.message));
  const response = await page.goto(route, { waitUntil: 'domcontentloaded' });
  return { response, errors, pageErrors };
}

test.describe('Public HTML smoke', () => {
  test.afterAll(() => {
    writeFindingsReport();
  });

  for (const route of PUBLIC_HTML_ROUTES) {
    test(`${route} renders without unexpected failure @1440`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      const { response, errors, pageErrors } = await openRoute(page, route);
      expect(response, `${route} should produce a response`).toBeTruthy();
      const status = response!.status();
      if (status >= 500) {
        expectUnlessKnown(false, {
          kind: 'html-5xx',
          severity: 'info',
          route,
          message: `${route} returned ${status}. Often missing DB/env in this workspace; not a reason to change UI.`,
        });
        return;
      }
      expectUnlessKnown(isHtmlStatusAcceptable(status), {
        kind: 'html-status',
        severity: 'warning',
        route,
        message: `${route} returned ${status}`,
      });
      if (status === 200) {
        await expect(page.locator('body')).toBeVisible();
        await checkViewportMeta(page, route);
        await probeFocusVisibility(page, route);
        const hrefs = [...new Set(await collectInternalHrefs(page))].slice(0, 8);
        for (const href of hrefs) {
          const nav = await page.request.get(href, { maxRedirects: 0 });
          const navStatus = nav.status();
          if (navStatus === 404) {
            expectUnlessKnown(false, {
              kind: 'broken-internal-nav',
              severity: 'warning',
              route,
              message: `${route} links to ${href} which returned 404`,
            });
          }
        }
      }
      const serious = [...pageErrors, ...errors.filter((e) => !isBenignConsole(e))];
      expectUnlessKnown(serious.length === 0, {
        kind: 'js-exception',
        severity: 'warning',
        route,
        message: `${route} console/page errors: ${serious.slice(0, 3).join(' | ')}`,
      });
    });
  }

  for (const route of SAMPLE_DETAIL_ROUTES) {
    test(`${route} is reachable (status recorded)`, async ({ page }) => {
      const { response } = await openRoute(page, route);
      const status = response?.status() ?? 0;
      expectUnlessKnown(status !== 0, {
        kind: 'detail-unreachable',
        severity: 'info',
        route,
        message: `${route} produced no HTTP response`,
      });
      if (status >= 500) {
        expectUnlessKnown(false, {
          kind: 'html-5xx',
          severity: 'info',
          route,
          message: `${route} returned ${status}`,
        });
      }
    });
  }
});

test.describe('Responsive + a11y sample', () => {
  const sample = ['/', '/login', '/people', '/calendar', '/ministries', '/events'];

  for (const route of sample) {
    for (const viewport of PORTAL_VIEWPORTS) {
      test(`${route} @ ${viewport.name}`, async ({ page }) => {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        const { response } = await openRoute(page, route);
        const status = response?.status() ?? 0;
        if (status !== 200) {
          test.info().annotations.push({ type: 'skip-reason', description: `status ${status}` });
          return;
        }
        const overflow = await measureOverflow(page);
        recordOverflow(route, viewport.name, overflow);
        const a11y = await collectA11yBaseline(page);
        recordA11y(route, viewport.name, a11y);
      });
    }
  }
});
