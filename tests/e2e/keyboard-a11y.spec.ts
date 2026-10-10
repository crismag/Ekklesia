import { test } from '@playwright/test';
import { expectUnlessKnown, writeFindingsReport } from './helpers/findings.js';
import { probeFocusVisibility } from './helpers/pageChecks.js';

test.describe('Keyboard accessibility baseline', () => {
  test.afterAll(() => {
    writeFindingsReport();
  });

  test('login form is reachable by Tab and records focus treatment', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const response = await page.goto('/login', { waitUntil: 'domcontentloaded' });
    if (!response || response.status() !== 200) {
      test.info().annotations.push({ type: 'note', description: `login status ${response?.status()}` });
      return;
    }

    await probeFocusVisibility(page, '/login');

    const email = page.locator('input[type="email"], input[name="email"]').first();
    if ((await email.count()) === 0) return;

    await page.keyboard.press('Tab');
    const focusedTag = await page.evaluate(() => document.activeElement?.tagName ?? '');
    expectUnlessKnown(focusedTag !== 'BODY', {
      kind: 'a11y-keyboard',
      severity: 'warning',
      route: '/login',
      message: 'Tab from load did not move focus off body on /login',
    });
  });
});
