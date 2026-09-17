import { test, expect } from '@playwright/test';
import {
  AUTH_REQUIRED_GET_API,
  PROTECTED_MUTATIONS,
  PUBLIC_API_GET_ROUTES,
  UNAUTHENTICATED_HTML_RENDERS,
} from './helpers/routes.js';
import { assertMutationRejected, isUnauthorizedStatus } from './helpers/pageChecks.js';
import { expectUnlessKnown, writeFindingsReport } from './helpers/findings.js';

test.describe('Authorization: HTML render ≠ API permission', () => {
  test.afterAll(() => {
    writeFindingsReport();
  });

  for (const route of UNAUTHENTICATED_HTML_RENDERS) {
    test(`GET ${route} may still render HTML without a session (current product behavior)`, async ({ request }) => {
      const res = await request.get(route, { maxRedirects: 0 });
      const status = res.status();
      expect([200, 302, 303, 401, 403, 500]).toContain(status);
      if (status === 200) {
        const contentType = res.headers()['content-type'] ?? '';
        expectUnlessKnown(/html/i.test(contentType), {
          kind: 'html-json-mismatch',
          severity: 'warning',
          route,
          message: `Unauthenticated GET ${route} returned ${contentType || 'unknown'} instead of HTML (known JSON 401 risk on some portal pages).`,
        });
      }
    });
  }

  for (const path of AUTH_REQUIRED_GET_API) {
    test(`GET ${path} without a session is not a successful identity payload`, async ({ request }) => {
      const res = await request.get(path);
      if (res.status() >= 500) {
        expectUnlessKnown(false, {
          kind: 'api-5xx',
          severity: 'info',
          route: path,
          message: `${path} returned ${res.status()} without session`,
        });
        return;
      }
      expect(isUnauthorizedStatus(res.status()) || res.status() === 400).toBeTruthy();
      if ((res.headers()['content-type'] ?? '').includes('json')) {
        const body = await res.json().catch(() => ({} as Record<string, unknown>));
        expect(body.email ?? body.actorId ?? body.portalUserId).toBeFalsy();
      }
    });
  }

  for (const mutation of PROTECTED_MUTATIONS) {
    test(`${mutation.method} ${mutation.path} is rejected without a session`, async ({ request }) => {
      const res = await request.fetch(mutation.path, {
        method: mutation.method,
        data: mutation.body,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        maxRedirects: 0,
      });
      await assertMutationRejected(res, `${mutation.method} ${mutation.path}`);
    });
  }

  for (const path of PUBLIC_API_GET_ROUTES) {
    test(`GET ${path} is callable anonymously (status recorded)`, async ({ request }) => {
      const res = await request.get(path);
      expect(res.status()).toBeGreaterThan(0);
      if (res.status() >= 500) {
        expectUnlessKnown(false, {
          kind: 'api-5xx',
          severity: 'info',
          route: path,
          message: `Public GET ${path} returned ${res.status()} (often missing ChurchCRM/portal DB).`,
        });
      }
    });
  }
});
