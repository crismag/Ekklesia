import { expect, type APIResponse, type Page } from '@playwright/test';
import { expectUnlessKnown, recordFinding } from './findings.js';

export type OverflowReport = {
  documentWidth: number;
  viewportWidth: number;
  overflows: boolean;
  offenders: Array<{ tag: string; id: string; className: string; width: number; right: number }>;
  dialogsOutOfBounds: Array<{ selector: string; right: number; bottom: number }>;
  controlsOutsideViewport: Array<{ tag: string; id: string; name: string; right: number; bottom: number }>;
};

export type A11yBaseline = {
  missingLang: boolean;
  unlabeledControls: number;
  imagesMissingAlt: number;
  buttonsMissingName: number;
  headings: string[];
  invalidAria: number;
  landmarkCount: number;
};

export function isBenignConsole(text: string): boolean {
  return /favicon|net::ERR_ABORTED|Failed to load resource/i.test(text);
}

export function isHtmlStatusAcceptable(status: number): boolean {
  return status === 200 || status === 302 || status === 304;
}

export function isUnauthorizedStatus(status: number): boolean {
  return [401, 403, 302, 303, 307, 308, 404, 405, 419, 422].includes(status);
}

export async function checkViewportMeta(page: Page, route: string): Promise<boolean> {
  const count = await page.locator('meta[name="viewport"]').count();
  const ok = count > 0;
  expectUnlessKnown(ok, {
    kind: 'missing-viewport-meta',
    severity: 'warning',
    route,
    message: `${route} has no viewport meta tag`,
  });
  return ok;
}

export async function measureOverflow(page: Page): Promise<OverflowReport> {
  return page.evaluate(() => {
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const documentWidth = document.documentElement.scrollWidth;
    const offenders: Array<{ tag: string; id: string; className: string; width: number; right: number }> = [];
    document.querySelectorAll('body *').forEach((node) => {
      const el = node as HTMLElement;
      const rect = el.getBoundingClientRect();
      if (rect.width > viewportWidth + 2 && rect.right > viewportWidth + 2) {
        const style = window.getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden') return;
        offenders.push({
          tag: el.tagName.toLowerCase(),
          id: el.id,
          className: String(el.className ?? '').slice(0, 120),
          width: Math.round(rect.width),
          right: Math.round(rect.right),
        });
      }
    });
    const dialogsOutOfBounds: Array<{ selector: string; right: number; bottom: number }> = [];
    document.querySelectorAll('[role="dialog"], dialog, .modal, .overlay, .search-overlay').forEach((node) => {
      const rect = (node as HTMLElement).getBoundingClientRect();
      if (rect.width === 0 && rect.height === 0) return;
      if (rect.right > viewportWidth + 8 || rect.bottom > viewportHeight + 8 || rect.left < -8) {
        dialogsOutOfBounds.push({
          selector:
            (node as HTMLElement).id
            || String((node as HTMLElement).className ?? '').slice(0, 80)
            || node.nodeName,
          right: Math.round(rect.right),
          bottom: Math.round(rect.bottom),
        });
      }
    });
    const controlsOutsideViewport: Array<{ tag: string; id: string; name: string; right: number; bottom: number }> = [];
    document.querySelectorAll('button, a, input, select, textarea, [role="button"]').forEach((node) => {
      const el = node as HTMLElement;
      const style = window.getComputedStyle(el);
      if (style.display === 'none' || style.visibility === 'hidden') return;
      const rect = el.getBoundingClientRect();
      if (rect.width === 0 && rect.height === 0) return;
      if (rect.right > viewportWidth + 8 || rect.left < -8) {
        controlsOutsideViewport.push({
          tag: el.tagName.toLowerCase(),
          id: el.id,
          name: (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 80),
          right: Math.round(rect.right),
          bottom: Math.round(rect.bottom),
        });
      }
    });
    return {
      documentWidth,
      viewportWidth,
      overflows: documentWidth > viewportWidth + 2,
      offenders: offenders.slice(0, 20),
      dialogsOutOfBounds,
      controlsOutsideViewport: controlsOutsideViewport.slice(0, 12),
    };
  });
}

export function recordOverflow(route: string, viewport: string, report: OverflowReport): void {
  expectUnlessKnown(!report.overflows, {
    kind: 'horizontal-overflow',
    severity: 'warning',
    route,
    viewport,
    message: `${route} @ ${viewport} document width ${report.documentWidth} > viewport ${report.viewportWidth}`,
    detail: report.offenders.slice(0, 8),
  });
  expectUnlessKnown(report.dialogsOutOfBounds.length === 0, {
    kind: 'dialog-out-of-bounds',
    severity: 'warning',
    route,
    viewport,
    message: `${route} @ ${viewport} has dialogs extending past the viewport`,
    detail: report.dialogsOutOfBounds,
  });
  expectUnlessKnown(report.controlsOutsideViewport.length === 0, {
    kind: 'control-outside-viewport',
    severity: 'warning',
    route,
    viewport,
    message: `${route} @ ${viewport} has ${report.controlsOutsideViewport.length} interactive controls extending past the viewport`,
    detail: report.controlsOutsideViewport,
  });
}

export async function collectA11yBaseline(page: Page): Promise<A11yBaseline> {
  return page.evaluate(() => {
    const unlabeledControls = [...document.querySelectorAll('input, select, textarea')].filter((el) => {
      const input = el as HTMLInputElement;
      if (input.type === 'hidden') return false;
      if (input.getAttribute('aria-hidden') === 'true') return false;
      const id = input.id;
      const labelled = Boolean(
        (id && document.querySelector(`label[for="${CSS.escape(id)}"]`))
        || input.closest('label')
        || input.getAttribute('aria-label')
        || input.getAttribute('aria-labelledby'),
      );
      return !labelled;
    }).length;

    const imagesMissingAlt = [...document.querySelectorAll('img')].filter((img) => !img.hasAttribute('alt')).length;

    const buttonsMissingName = [...document.querySelectorAll('button, [role="button"]')].filter((el) => {
      const name = (el.getAttribute('aria-label') || el.textContent || '').trim();
      return name.length === 0;
    }).length;

    let invalidAria = 0;
    document.querySelectorAll('[aria-hidden]').forEach((el) => {
      const val = el.getAttribute('aria-hidden');
      if (val !== 'true' && val !== 'false') invalidAria += 1;
    });
    document.querySelectorAll('[role]').forEach((el) => {
      if ((el.getAttribute('role') || '').trim() === '') invalidAria += 1;
    });

    const landmarkCount = document.querySelectorAll('main, nav, header, footer, [role="main"], [role="navigation"], [role="banner"], [role="contentinfo"]').length;

    return {
      missingLang: !document.documentElement.getAttribute('lang'),
      unlabeledControls,
      imagesMissingAlt,
      buttonsMissingName,
      headings: [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].map((h) => h.tagName.toLowerCase()),
      invalidAria,
      landmarkCount,
    };
  });
}

export function recordA11y(route: string, viewport: string, baseline: A11yBaseline): void {
  expectUnlessKnown(!baseline.missingLang, {
    kind: 'a11y-lang',
    severity: 'warning',
    route,
    viewport,
    message: `${route} missing html lang`,
  });
  expectUnlessKnown(baseline.unlabeledControls === 0, {
    kind: 'a11y-unlabeled-control',
    severity: 'warning',
    route,
    viewport,
    message: `${route} has ${baseline.unlabeledControls} unlabeled form controls`,
  });
  expectUnlessKnown(baseline.imagesMissingAlt === 0, {
    kind: 'a11y-img-alt',
    severity: 'info',
    route,
    viewport,
    message: `${route} has ${baseline.imagesMissingAlt} images without alt`,
  });
  expectUnlessKnown(baseline.buttonsMissingName === 0, {
    kind: 'a11y-button-name',
    severity: 'warning',
    route,
    viewport,
    message: `${route} has ${baseline.buttonsMissingName} buttons without accessible name`,
  });
  expectUnlessKnown(baseline.invalidAria === 0, {
    kind: 'a11y-invalid-aria',
    severity: 'warning',
    route,
    viewport,
    message: `${route} has ${baseline.invalidAria} invalid ARIA attributes`,
  });
  expectUnlessKnown(baseline.landmarkCount > 0, {
    kind: 'a11y-landmarks',
    severity: 'info',
    route,
    viewport,
    message: `${route} has no header/nav/main/footer landmarks`,
  });
  expectUnlessKnown(baseline.headings.includes('h1') || baseline.headings.length > 0, {
    kind: 'a11y-headings',
    severity: 'info',
    route,
    viewport,
    message: `${route} heading outline: ${baseline.headings.join(',') || '(none)'}`,
  });
}

export async function collectInternalHrefs(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const origin = location.origin;
    return [...document.querySelectorAll('a[href]')]
      .map((a) => (a as HTMLAnchorElement).getAttribute('href') || '')
      .filter((href) => href && !href.startsWith('#') && !href.startsWith('mailto:') && !href.startsWith('tel:') && !href.startsWith('javascript:'))
      .map((href) => {
        try {
          const url = new URL(href, location.href);
          if (url.origin !== origin) return '';
          return url.pathname + url.search;
        } catch {
          return '';
        }
      })
      .filter(Boolean);
  });
}

export async function probeFocusVisibility(page: Page, route: string): Promise<void> {
  const first = page.locator('a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])').first();
  if ((await first.count()) === 0) return;
  await first.focus();
  const outline = await first.evaluate((el) => {
    const style = getComputedStyle(el);
    return {
      outline: style.outlineStyle,
      outlineWidth: style.outlineWidth,
      boxShadow: style.boxShadow,
    };
  });
  const hasFocusTreatment =
    (outline.outline !== 'none' && outline.outlineWidth !== '0px')
    || (outline.boxShadow !== 'none' && outline.boxShadow !== '');
  expectUnlessKnown(hasFocusTreatment, {
    kind: 'a11y-focus-visible',
    severity: 'warning',
    route,
    message: `${route} first focusable control has no detectable outline/box-shadow on focus`,
    detail: outline,
  });
}

export async function assertMutationRejected(res: APIResponse, label: string): Promise<void> {
  const status = res.status();
  const contentType = res.headers()['content-type'] ?? '';
  const location = res.headers()['location'] ?? '';
  if (status >= 500) {
    recordFinding({
      kind: 'mutation-server-error',
      severity: 'info',
      route: label,
      message: `${label} returned ${status} without a session (environment or missing DB). Treated as non-success.`,
    });
    return;
  }
  if (/notice=(deleted|saved|merged|ok)\b/i.test(location)) {
    expect(status, `${label} redirected as if the privileged mutation succeeded`).not.toBe(302);
    return;
  }
  if (isUnauthorizedStatus(status) || status === 400) {
    return;
  }
  if (contentType.includes('json')) {
    const body = await res.json().catch(() => ({} as Record<string, unknown>));
    const looksDenied = Boolean(
      body && (body.error || body.kind === 'permission_denied' || body.kind === 'validation_failed' || body.success === false),
    );
    if (looksDenied) return;
    expect(body && (body as { success?: boolean }).success, `${label} JSON must not report success`).not.toBe(true);
  }
  expect(status, `${label} must not succeed as an unauthenticated mutation`).not.toBe(200);
}
