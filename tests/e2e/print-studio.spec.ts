import { test, expect } from '@playwright/test';

/**
 * The Print studio: three panels around one sheet.
 *
 * Needs a signed-in session, because the studio's calendars (birthdays in
 * particular) are only fed to someone signed in. Set PORTAL_E2E_SESSION to a
 * `portal_session` cookie value for an account on the database under test;
 * without it these tests are skipped, not failed. One session is reused for
 * every test: sign-in is rate limited by design.
 */
const session = process.env.PORTAL_E2E_SESSION ?? '';
const MONTH = 'dateMode=custom&start=2026-09-01&end=2026-09-30&sources=birthdays';

test.describe('print studio', () => {
  test.skip(session === '', 'PORTAL_E2E_SESSION is not set');

  test.beforeEach(async ({ context, baseURL }) => {
    const host = new URL(baseURL ?? 'http://127.0.0.1:8765').hostname;
    await context.addCookies([{ name: 'portal_session', value: session, domain: host, path: '/' }]);
  });

  test('each panel folds on its own and folding changes no setting', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/calendar/print-setup?' + MONTH);
    const frame = page.locator('#pcFrame');
    await expect(frame).toHaveAttribute('src', /calendar\/print\?/);
    const before = await frame.getAttribute('src');

    await page.click('#pcLeftToggle');
    await expect(page.locator('#pcLayout')).toHaveAttribute('data-left', 'closed');
    await expect(page.locator('#pcLeftRail')).toBeVisible();
    await expect(page.locator('#pcLayout')).toHaveAttribute('data-right', 'open');

    await page.click('#pcRightToggle');
    await expect(page.locator('#pcLayout')).toHaveAttribute('data-right', 'closed');
    expect(await frame.getAttribute('src')).toBe(before);

    await page.click('#pcLeftRail');
    await expect(page.locator('#pcLeftToggle')).toHaveAttribute('aria-expanded', 'true');
  });

  test('tabs move with the arrow keys', async ({ page }) => {
    await page.goto('/calendar/print-setup?' + MONTH);
    await page.focus('#pcTab-content');
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('#pcTab-people')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#pcPane-people')).toBeVisible();
    await expect(page.locator('#pcPane-content')).toBeHidden();
  });

  test('choosing a theme updates the sheet, and zoom never changes it', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/calendar/print-setup?' + MONTH);
    await page.click('label.pc-theme[data-theme="economy"]');
    const frame = page.locator('#pcFrame');
    await expect(frame).toHaveAttribute('src', /theme=economy/);
    const themed = await frame.getAttribute('src');
    await page.click('#pcZoom100');
    await expect(page.locator('#pcZoom100')).toHaveAttribute('aria-pressed', 'true');
    expect(await frame.getAttribute('src')).toBe(themed);
  });

  test('automatic follows the printed month, and a manual choice can go back to it', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/calendar/print-setup?dateMode=custom&start=2026-10-01&end=2026-10-31&sources=birthdays');
    await page.click('label.pc-theme[data-theme="auto"]');
    await expect(page.locator('#pcThemeModeText')).toContainText('October prints in Maple Colour');
    await expect(page.locator('#pcFrame')).toHaveAttribute('src', /theme=auto/);

    await page.click('label.pc-theme[data-theme="open-skies"]');
    await expect(page.locator('#pcThemeModeText')).toContainText('overrides the monthly theme');
    await expect(page.locator('#pcUseAuto')).toBeVisible();

    await page.click('#pcUseAuto');
    await expect(page.locator('label.pc-theme[data-theme="auto"] input')).toBeChecked();
    await expect(page.locator('#pcUseAuto')).toBeHidden();
    await expect(page.locator('#pcFrame')).toHaveAttribute('src', /theme=auto/);
  });

  test('the gallery groups the monthly themes by Canadian season', async ({ page }) => {
    await page.goto('/calendar/print-setup?' + MONTH);
    const winter = page.locator('#pcSeason-winter ~ label.pc-theme').first();
    await expect(winter).toContainText('December · Winter');
    await expect(page.locator('.pc-theme', { hasText: 'January · Winter' })).toHaveCount(1);
    await expect(page.locator('.pc-theme', { hasText: 'November · Fall' })).toHaveCount(1);
  });

  test('printing the studio page prints no controls', async ({ page }) => {
    await page.goto('/calendar/print-setup?' + MONTH);
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('#pcLeft')).toBeHidden();
    await expect(page.locator('#pcRight')).toBeHidden();
    await expect(page.locator('.ps-print-note')).toBeVisible();
  });

  test('the printed sheet names celebrants without ages and keys member types', async ({ page }) => {
    await page.goto('/calendar/print?template=monthly&' + MONTH);
    const text = await page.locator('body').innerText();
    expect(text).not.toMatch(/\(\d{1,3}\)/);
    await expect(page.locator('h1.doc-title')).toHaveText('Birthdays');
    await page.emulateMedia({ media: 'print' });
    await expect(page.locator('.cal')).toBeVisible();
  });
});
