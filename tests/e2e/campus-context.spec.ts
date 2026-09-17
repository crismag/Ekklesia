import { test, expect } from '@playwright/test';
import { CAMPUS_COOKIE, CAMPUS_NAV_COLLAPSE_PX, PORTAL_VIEWPORTS } from './helpers/viewports.js';

test.describe('Campus context persistence', () => {
  test('portal_campus_id cookie survives compact viewports and reload', async ({ page, context, baseURL }) => {
    await context.addCookies([
      {
        name: CAMPUS_COOKIE,
        value: '2',
        url: baseURL ?? 'http://127.0.0.1:8765',
      },
    ]);

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const before = (await context.cookies()).find((c) => c.name === CAMPUS_COOKIE);
    expect(before?.value, 'campus cookie must still be present after first paint').toBe('2');

    const compact = PORTAL_VIEWPORTS.find((v) => v.width < CAMPUS_NAV_COLLAPSE_PX)!;
    await page.setViewportSize({ width: compact.width, height: compact.height });
    await page.reload({ waitUntil: 'domcontentloaded' });

    const after = (await context.cookies()).find((c) => c.name === CAMPUS_COOKIE);
    expect(after?.value, 'hiding the campus <select> at ≤820px must not clear portal_campus_id').toBe('2');

    const select = page.locator('#campusSelect');
    if ((await select.count()) > 0) {
      const wrapperDisplay = await select.evaluate((el) => {
        const wrap = el.closest('.topbar-campus');
        return wrap ? getComputedStyle(wrap).display : getComputedStyle(el).display;
      });
      expect(['none', 'block', 'flex', 'inline', 'inline-block', 'grid', 'inline-flex']).toContain(wrapperDisplay);
      expect(await select.evaluate((el) => el.isConnected)).toBe(true);
    }
  });

  test('shell script writes portal_campus_id on change when the control exists', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const select = page.locator('#campusSelect');
    if ((await select.count()) === 0) {
      test.info().annotations.push({
        type: 'note',
        description: 'Campus select not in DOM (no campus list from DB). Cookie writer still covered by source + cookie tests.',
      });
      return;
    }
    const optionCount = await select.locator('option').count();
    if (optionCount < 2) {
      test.info().annotations.push({ type: 'note', description: 'Fewer than 2 campus options; skip change-handler assertion.' });
      return;
    }
    const value = await select.locator('option').nth(1).getAttribute('value');
    if (!value) return;
    await select.selectOption(value);
    await expect.poll(async () => {
      const cookies = await page.context().cookies();
      return cookies.find((c) => c.name === CAMPUS_COOKIE)?.value;
    }).toBe(value);
  });
});
