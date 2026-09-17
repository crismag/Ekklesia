import fs from 'node:fs';
import path from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { isHtmlStatusAcceptable } from './helpers/pageChecks.js';
import { expectUnlessKnown } from './helpers/findings.js';

function portalShellCss(): string {
  const src = fs.readFileSync(path.join(process.cwd(), 'resources/views/_portal-shell.php'), 'utf8');
  const match = src.match(/\$style = <<<'CSS'\n([\s\S]*?)\nCSS;/);
  if (!match) throw new Error('Could not extract portal shell CSS nowdoc');
  return match[1];
}

function homeFixtureHtml(): string {
  return `<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--portal-gutter:clamp(1rem,2vw,2rem);--container-wide:1520px;--container-readable:42rem;
--paper:#fff;--line:#d9e4dd;--soft:#eef4f0;--ink:#17211b;--bg:#f7faf8}
html,body{margin:0;background:var(--bg)}
.topbar-stub{height:48px;background:#0c2f28;color:#fff;padding:12px var(--portal-gutter)}
.hero{position:relative;min-height:80px;background:#123b31;color:#fff}
.home-layout{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(260px,.72fr);gap:18px}
.panel{background:#d7eee6;min-height:160px}
</style>
${portalShellCss()}
</head><body>
<div class="shell" data-layout="workspace" data-left="none" data-right="none">
  <div class="topbar-stub">Header</div>
  <section class="hero"><div class="hero-copy">Welcome</div></section>
  <main id="portal-main" tabindex="-1">
    <div class="home-layout">
      <article class="panel" id="homeEvents">Upcoming events</article>
      <aside class="panel" id="homeNotes">Announcements</aside>
    </div>
  </main>
</div>
</body></html>`;
}

function workspaceFixtureHtml(): string {
  return `<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--portal-gutter:clamp(1rem,2vw,2rem);--container-wide:1520px;--container-readable:42rem;
--portal-left-expanded:280px;--portal-left-collapsed:44px;--portal-right-expanded:320px;
--paper:#fff;--line:#d9e4dd;--soft:#eef4f0;--ink:#17211b;--bg:#f7faf8}
html,body{margin:0;background:var(--bg)}
.calendar-frame{background:#d7eee6;min-height:240px}
.ev-agenda{background:#e8f0ea;min-height:160px}
.topbar-stub{height:48px;background:#0c2f28;color:#fff;padding:12px var(--portal-gutter)}
</style>
${portalShellCss()}
</head><body>
<div class="shell" data-layout="workspace" data-left="open" data-right="none">
  <div class="topbar-stub">Header</div>
  <main id="portal-main" tabindex="-1">
    <section class="portal-body" aria-label="Calendar workspace">
      <aside class="portal-left" id="portalLeft"><div class="portal-left-head">Calendars</div></aside>
      <div class="portal-main-slot layout-workspace">
        <article class="panel">
          <div class="calendar-frame" id="calendarRoot">Month grid</div>
        </article>
      </div>
    </section>
    <section class="panel" style="margin-top:16px"><div class="ev-agenda">Events table</div></section>
  </main>
</div>
</body></html>`;
}

type Box = {
  viewport: number;
  shell: number;
  main: number;
  content: number;
  mainMaxWidth: string;
};

async function openOrSkip(page: Page, route: string): Promise<boolean> {
  const response = await page.goto(route, { waitUntil: 'domcontentloaded' });
  expect(response, `${route} should produce a response`).toBeTruthy();
  const status = response!.status();
  if (status >= 500) {
    expectUnlessKnown(false, {
      kind: 'html-5xx',
      severity: 'info',
      route,
      message: `${route} returned ${status}. Often missing DB/env; skip width assertions.`,
    });
    return false;
  }
  expectUnlessKnown(isHtmlStatusAcceptable(status), {
    kind: 'html-status',
    severity: 'warning',
    route,
    message: `${route} returned ${status}`,
  });
  return status === 200;
}

async function measureWorkspace(page: Page, contentSelector: string): Promise<Box> {
  return page.evaluate((sel) => {
    const shell = document.querySelector('.shell') as HTMLElement | null;
    const main = document.querySelector('#portal-main') as HTMLElement | null;
    const content = document.querySelector(sel) as HTMLElement | null;
    const target = content ?? main;
    return {
      viewport: window.innerWidth,
      shell: shell ? Math.round(shell.getBoundingClientRect().width) : 0,
      main: main ? Math.round(main.getBoundingClientRect().width) : 0,
      content: target ? Math.round(target.getBoundingClientRect().width) : 0,
      mainMaxWidth: main ? getComputedStyle(main).maxWidth : '',
    };
  }, contentSelector);
}

test.describe('Workspace fills the main column (computed styles)', () => {
  test('fixture: calendar frame tracks the viewport and grows when the left rail collapses', async ({
    page,
  }) => {
    const widths = [1440, 1920, 2560] as const;
    const contents: number[] = [];
    for (const width of widths) {
      await page.setViewportSize({ width, height: 900 });
      await page.setContent(workspaceFixtureHtml(), { waitUntil: 'domcontentloaded' });
      const box = await measureWorkspace(page, '.calendar-frame');
      expect(box.shell, `${width} shell`).toBeGreaterThan(width - 8);
      expect(box.mainMaxWidth, `${width} main max-width`).toBe('none');
      expect(box.main, `${width} main`).toBeGreaterThan(width * 0.9);
      expect(box.content, `${width} calendar-frame`).toBeGreaterThan(width * 0.7);
      if (width >= 1920) expect(box.content).toBeGreaterThan(1520);
      contents.push(box.content);
    }
    expect(contents[1]).toBeGreaterThan(contents[0] + 200);
    expect(contents[2]).toBeGreaterThan(contents[1] + 200);

    await page.setViewportSize({ width: 1920, height: 900 });
    await page.setContent(workspaceFixtureHtml(), { waitUntil: 'domcontentloaded' });
    const open = await measureWorkspace(page, '.calendar-frame');
    await page.locator('.shell').evaluate((el) => el.setAttribute('data-left', 'collapsed'));
    const collapsed = await measureWorkspace(page, '.calendar-frame');
    expect(collapsed.content).toBeGreaterThan(open.content + 80);
  });

  test('fixture: calendar frame grows when the right rail collapses', async ({ page }) => {
    const html = workspaceFixtureHtml()
      .replace('data-right="none"', 'data-right="open"')
      .replace(
        '</div>\n    </section>',
        '</div>\n      <aside class="portal-right" id="portalRight"><div class="portal-right-head">Day</div></aside>\n    </section>',
      );
    await page.setViewportSize({ width: 1920, height: 900 });
    await page.setContent(html, { waitUntil: 'domcontentloaded' });
    const open = await measureWorkspace(page, '.calendar-frame');
    await page.locator('.shell').evaluate((el) => el.setAttribute('data-right', 'collapsed'));
    const collapsed = await measureWorkspace(page, '.calendar-frame');
    expect(collapsed.content).toBeGreaterThan(open.content + 80);
  });

  test('fixture: home fills the workspace instead of a centered 1520px column', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 2560, height: 900 });
    await page.setContent(homeFixtureHtml(), { waitUntil: 'domcontentloaded' });
    const box = await measureWorkspace(page, '.home-layout');
    expect(box.shell).toBeGreaterThan(2550);
    expect(box.mainMaxWidth).toBe('none');
    expect(box.main).toBeGreaterThan(2560 * 0.9);
    expect(box.content).toBeGreaterThan(1520);

    const wide = await page.evaluate(() => {
      const shell = document.querySelector('.shell') as HTMLElement | null;
      const main = document.querySelector('#portal-main') as HTMLElement | null;
      if (!shell || !main) {
        return { layout: '', workspaceMain: 0, wideMain: 0 };
      }
      const probe = document.createElement('div');
      probe.className = 'shell';
      probe.setAttribute('data-layout', 'wide');
      const inner = document.createElement('main');
      inner.id = 'portal-main';
      inner.textContent = 'wide';
      probe.appendChild(inner);
      document.body.appendChild(probe);
      const wideMain = Math.round(inner.getBoundingClientRect().width);
      probe.remove();
      return {
        layout: shell.getAttribute('data-layout') ?? '',
        workspaceMain: Math.round(main.getBoundingClientRect().width),
        wideMain,
      };
    });
    expect(wide.layout).toBe('workspace');
    expect(wide.workspaceMain).toBeGreaterThan(wide.wideMain + 200);
  });
});

test.describe('Workspace fills the main column', () => {
  for (const width of [1440, 1920, 2560] as const) {
    test(`events list content grows with a ${width}px viewport`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      if (!(await openOrSkip(page, '/events'))) return;

      const box = await measureWorkspace(page, '.ev-agenda, .panel, #portal-main');
      expect(box.shell, 'shell should reach the viewport').toBeGreaterThan(width - 8);
      expect(box.mainMaxWidth, 'workspace main must not carry a centered max-width').toBe('none');
      // Gutters are clamp(1rem, 2vw, 2rem) on both sides — never hundreds of px.
      expect(box.main).toBeGreaterThan(width * 0.9);
      expect(box.content).toBeGreaterThan(width * 0.88);
      // Growing the viewport must grow the working surface, not empty margins.
      if (width >= 1920) {
        expect(box.content).toBeGreaterThan(1520);
      }
    });
  }

  test('calendar month grid fills the main slot and grows when the left rail collapses', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    if (!(await openOrSkip(page, '/calendar'))) return;

    const before = await measureWorkspace(page, '.calendar-frame, .portal-main-slot');
    expect(before.shell).toBeGreaterThan(1910);
    expect(before.mainMaxWidth).toBe('none');
    expect(before.main).toBeGreaterThan(1920 * 0.9);
    expect(before.content).toBeGreaterThan(1400);

    const toggle = page.locator('.portal-left-toggle');
    if ((await toggle.count()) === 0) return;
    const widthBefore = before.content;
    await toggle.click();
    await expect(page.locator('.shell')).toHaveAttribute('data-left', 'collapsed');
    const after = await measureWorkspace(page, '.calendar-frame, .portal-main-slot');
    expect(after.content).toBeGreaterThan(widthBefore + 80);
  });

  test('login remains an intentional wide surface, not a 2560px stretched form', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 2560, height: 900 });
    if (!(await openOrSkip(page, '/login'))) return;
    const box = await measureWorkspace(page, '#portal-main');
    expect(box.shell).toBeGreaterThan(2550);
    expect(box.main).toBeLessThanOrEqual(1520);
  });

  test('home fills the workspace on a wide viewport', async ({ page }) => {
    await page.setViewportSize({ width: 2560, height: 900 });
    if (!(await openOrSkip(page, '/'))) return;
    const box = await measureWorkspace(page, '.home-layout, #portal-main');
    expect(box.shell).toBeGreaterThan(2550);
    expect(box.mainMaxWidth).toBe('none');
    expect(box.main).toBeGreaterThan(2560 * 0.9);
    expect(box.content).toBeGreaterThan(1520);
  });
});
