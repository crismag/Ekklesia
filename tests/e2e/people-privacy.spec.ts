import { test, expect } from '@playwright/test';
import { expectUnlessKnown, writeFindingsReport } from './helpers/findings.js';

test.describe('Public people privacy (live payload if DB is available)', () => {
  test.afterAll(() => {
    writeFindingsReport();
  });

  test('public directory JSON does not include contact/address for anonymous callers', async ({ request }) => {
    const res = await request.get('/api/public/people-directory');
    if (res.status() >= 500 || res.status() === 404) {
      expectUnlessKnown(false, {
        kind: 'privacy-untestable-live',
        severity: 'info',
        route: '/api/public/people-directory',
        message: `Live public directory returned ${res.status()}; PHP source contract still covers masking.`,
      });
      return;
    }
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    const people = Array.isArray(body.people) ? body.people : [];
    for (const person of people.slice(0, 25)) {
      expect(person.contact, 'anonymous public directory must omit contact').toBeUndefined();
      expect(person.address, 'anonymous public directory must omit address').toBeUndefined();
      expect(person.lastName, 'anonymous public directory must omit full last name').toBeUndefined();
      if (typeof person.displayName === 'string' && person.lastName) {
        expect(person.displayName.includes(person.lastName)).toBe(false);
      }
      if (typeof person.displayName === 'string') {
        expect(person.displayName).not.toMatch(/\s[A-Z][a-z]{2,}$/);
      }
    }
  });
});
