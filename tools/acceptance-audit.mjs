#!/usr/bin/env node
/**
 * Independent production acceptance probe (read-only except POST /api/login).
 *
 * Usage:
 *   PORTAL_AUDIT_EMAIL=… PORTAL_AUDIT_PASSWORD=… node tools/acceptance-audit.mjs
 *
 * Never prints credentials. Never writes PII into the report (no names, emails,
 * phones, addresses). Does not POST/PUT/DELETE anything except login.
 */
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE = process.env.PORTAL_AUDIT_BASE ?? 'https://christlikeness.crishub.com/church_portal';
const EMAIL = process.env.PORTAL_AUDIT_EMAIL ?? '';
const PASSWORD = process.env.PORTAL_AUDIT_PASSWORD ?? '';
const OUT_DIR = path.join(root, 'reports', 'acceptance-audit');

/**
 * Routes that intentionally render without application chrome.
 *
 * /password/change is the forced-reset page: it renders when
 * must_change_password is set, and giving it navigation, a campus switcher or
 * a search trigger would let a user walk away from a mandatory password change.
 * Its lack of chrome is the security behaviour, not a defect — so flagging it
 * reports 10 High findings (one per role x viewport) that must never be
 * "fixed". The exemption is counted and reported rather than silently dropped.
 */
const CHROMELESS_ROUTES = new Set(['/password/change']);
let chromelessExempted = 0;

const VIEWPORTS = [
  { name: '360', width: 360, height: 800 },
  { name: '390', width: 390, height: 844 },
  { name: '430', width: 430, height: 932 },
  { name: '768', width: 768, height: 1024 },
  { name: '820', width: 820, height: 1180 },
  { name: '830', width: 830, height: 1180 },
  { name: '900', width: 900, height: 1200 },
  { name: '1024', width: 1024, height: 768 },
  { name: '1280', width: 1280, height: 800 },
  { name: '1440', width: 1440, height: 900 },
];

const ANON_ROUTES = [
  '/', '/login', '/events', '/ministries', '/people', '/calendar', '/docs',
  '/docs/navigation', '/printables', '/printables/events', '/printables/birthdays',
  '/printables/schedules', '/schedule-board', '/events/1', '/ministries/1',
  '/people/1', '/my-schedule', '/availability', '/account', '/admin',
  '/calendar/settings', '/password/change',
];

const MEMBER_ROUTES = [
  '/', '/ministries', '/ministries/1', '/schedule-board', '/people', '/people/1',
  '/calendar', '/events', '/events/1', '/docs', '/my-schedule', '/availability',
  '/account', '/printables', '/printables/events', '/printables/birthdays',
  '/printables/schedules', '/calendar/settings',
];

// Derived from BASE: these were hardcoded to production, so a run pointed at a
// local environment silently reported production's routing instead of its own.
// The origin-level pair is deliberate — the standalone apps must NOT be
// reachable at the site root, only beneath the portal prefix.
const STANDALONE = [
  `${BASE}/people_signup/`,
  `${new URL(BASE).origin}/people_signup/`,
  `${BASE}/events_rsvp/`,
  `${new URL(BASE).origin}/events_rsvp/`,
];

function sanitizeClass(s) {
  return String(s || '').replace(/\s+/g, ' ').slice(0, 80);
}

async function measure(page) {
  return page.evaluate(() => {
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const docW = document.documentElement.scrollWidth;

    const vis = (el) => {
      if (!el) return false;
      const st = getComputedStyle(el);
      if (st.display === 'none' || st.visibility === 'hidden' || Number(st.opacity) === 0) return false;
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    };

    const nav = document.querySelector('nav.portal-nav, nav[aria-label="Primary"]');
    const navLabels = nav
      ? [...nav.querySelectorAll('a')].map((a) => (a.textContent || '').replace(/\s+/g, ' ').trim()).filter(Boolean)
      : [];

    let clippedNav = 0;
    if (nav) {
      const nr = nav.getBoundingClientRect();
      [...nav.querySelectorAll('a')].forEach((a) => {
        if (!vis(a)) return;
        const r = a.getBoundingClientRect();
        if (r.right > nr.right + 2 || r.left < nr.left - 2) clippedNav += 1;
      });
    }

    const overflowOffenders = [];
    document.querySelectorAll('body *').forEach((node) => {
      const el = node;
      const st = getComputedStyle(el);
      if (st.display === 'none' || st.visibility === 'hidden') return;
      const r = el.getBoundingClientRect();
      if (r.width > vw + 2 && r.right > vw + 2) {
        overflowOffenders.push({
          tag: el.tagName.toLowerCase(),
          id: el.id || '',
          className: String(el.className || '').slice(0, 80),
          width: Math.round(r.width),
        });
      }
    });

    const h1s = [...document.querySelectorAll('h1')].map((h) => (h.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80));
    const mains = document.querySelectorAll('main').length;
    const skip = document.querySelector('a.skip-link');
    const skipHref = skip ? skip.getAttribute('href') : null;
    const skipTarget = skipHref && skipHref.startsWith('#') ? document.querySelector(skipHref) : null;
    const portalMainIds = document.querySelectorAll('#portal-main').length;

    const campusDesktop = document.getElementById('campusSelect');
    const campusMobile = document.getElementById('campusSelectMobile');
    const campusVisible = vis(campusDesktop) || vis(campusMobile);
    const campusOptionCount = (campusDesktop || campusMobile)
      ? (campusDesktop || campusMobile).options.length
      : 0;

    const searchBtn = document.getElementById('searchBtn');
    const searchVisible = vis(searchBtn);

    const drawer = document.getElementById('sideDrawer');
    const drawerOpen = drawer ? drawer.classList.contains('open') : false;
    const drawerInert = drawer ? drawer.hasAttribute('inert') : false;
    let drawerTabStops = 0;
    if (drawer) {
      const candidates = drawer.querySelectorAll('a, button, input, select, textarea, [tabindex]');
      candidates.forEach((el) => {
        if (el.tabIndex < 0) return;
        if (!vis(el) && drawerInert) return;
        const root = el.getRootNode();
        // inert on ancestor should exclude from sequential focus
        let n = el;
        let blocked = false;
        while (n) {
          if (n instanceof Element && n.hasAttribute('inert')) { blocked = true; break; }
          n = n.parentElement;
        }
        if (!blocked) drawerTabStops += 1;
      });
    }

    const sub12 = [];
    document.querySelectorAll('body *').forEach((el) => {
      const st = getComputedStyle(el);
      if (st.display === 'none' || st.visibility === 'hidden') return;
      if (!(el.textContent || '').trim()) return;
      const px = parseFloat(st.fontSize);
      if (px > 0 && px < 12) {
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) return;
        sub12.push({ tag: el.tagName.toLowerCase(), className: String(el.className || '').slice(0, 60), px: Math.round(px * 10) / 10 });
      }
    });

    const smallTargets = [];
    document.querySelectorAll('a, button, input, select, textarea, [role="button"], summary').forEach((el) => {
      const st = getComputedStyle(el);
      if (st.display === 'none' || st.visibility === 'hidden' || st.pointerEvents === 'none') return;
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) return;
      const w = r.width;
      const h = r.height;
      if (w < 24 || h < 24) {
        const cls = String(el.className || '');
        const inMonthChip = Boolean(el.closest('.month-grid, .month-day'));
        smallTargets.push({
          tag: el.tagName.toLowerCase(),
          id: el.id || '',
          className: cls.slice(0, 80),
          w: Math.round(w),
          h: Math.round(h),
          inMonthChip,
        });
      }
    });

    const moreBtn = document.getElementById('moreBtn');
    const hamBtn = document.getElementById('menuButton');
    const contextBar = document.querySelector('.context-bar');
    const adminInert = document.querySelector('.admin-inert');

    const developerNeedles = [
      'once persistence',
      'not wired',
      'TODO',
      'FIXME',
      'lorem ipsum',
      'placeholder text',
      'Coming soon',
      'not switched on',
    ];
    const bodyText = (document.body.innerText || '').toLowerCase();
    const copyHits = developerNeedles.filter((n) => bodyText.includes(n.toLowerCase()));

    return {
      title: document.title.slice(0, 80),
      lang: document.documentElement.getAttribute('lang'),
      docW,
      vw,
      vh,
      overflows: docW > vw + 2,
      overflowOffenders: overflowOffenders.slice(0, 8),
      navLabels,
      clippedNav,
      h1Count: h1s.length,
      h1s,
      mains,
      skipPresent: Boolean(skip),
      skipHref,
      skipTargetExists: Boolean(skipTarget),
      skipTargetTag: skipTarget ? skipTarget.tagName.toLowerCase() : null,
      portalMainIds,
      campusVisible,
      campusOptionCount,
      campusDesktopVisible: vis(campusDesktop),
      campusMobileVisible: vis(campusMobile),
      searchVisible,
      moreVisible: vis(moreBtn),
      hamVisible: vis(hamBtn),
      contextBarVisible: vis(contextBar),
      drawerOpen,
      drawerInert,
      drawerTabStops,
      sub12Count: sub12.length,
      sub12: sub12.slice(0, 8),
      smallTargetsCount: smallTargets.length,
      smallTargetsExempt: smallTargets.filter((t) => t.inMonthChip).length,
      smallTargetsNonExempt: smallTargets.filter((t) => !t.inMonthChip).length,
      smallTargetsSample: smallTargets.filter((t) => !t.inMonthChip).slice(0, 8),
      adminInertPresent: Boolean(adminInert),
      copyHits,
      mcCards: document.querySelectorAll('.mc-card').length,
      agendaBtn: Boolean(document.querySelector('[data-view="agenda"], button[data-view="agenda"], .segmented button')),
      peopleTable: Boolean(document.querySelector('.directory-table')),
      peopleCardsMode: Boolean(document.querySelector('.filters-summary')) && vis(document.querySelector('.filters-summary')),
    };
  }).then((m) => {
    m.overflowOffenders = (m.overflowOffenders || []).map((o) => ({ ...o, className: sanitizeClass(o.className) }));
    m.sub12 = (m.sub12 || []).map((o) => ({ ...o, className: sanitizeClass(o.className) }));
    m.smallTargetsSample = (m.smallTargetsSample || []).map((o) => ({ ...o, className: sanitizeClass(o.className) }));
    return m;
  });
}

async function goto(page, route) {
  const url = route.startsWith('http') ? route : `${BASE}${route}`;
  const res = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.waitForTimeout(400);
  return { status: res?.status() ?? 0, finalUrl: page.url() };
}

function fingerprintNav(labels) {
  return (labels || []).join('|');
}

async function runRole(browser, role, routes, storageState) {
  const findings = [];
  const matrix = [];
  const navSet = new Set();
  const context = await browser.newContext({
    storageState,
    ignoreHTTPSErrors: true,
    userAgent: 'CursorAcceptanceAudit/1.0',
  });
  const page = await context.newPage();

  for (const vp of VIEWPORTS) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    for (const route of routes) {
      let status = 0;
      try {
        const g = await goto(page, route);
        status = g.status;
      } catch (e) {
        matrix.push({ role, route, viewport: vp.name, error: 'navigation-failed', status: 0 });
        continue;
      }
      const m = await measure(page);
      navSet.add(fingerprintNav(m.navLabels));
      const row = {
        role,
        route,
        viewport: vp.name,
        status,
        overflows: m.overflows,
        clippedNav: m.clippedNav,
        h1Count: m.h1Count,
        mains: m.mains,
        skipPresent: m.skipPresent,
        skipTargetExists: m.skipTargetExists,
        skipTargetTag: m.skipTargetTag,
        portalMainIds: m.portalMainIds,
        campusVisible: m.campusVisible,
        campusDesktopVisible: m.campusDesktopVisible,
        campusMobileVisible: m.campusMobileVisible,
        searchVisible: m.searchVisible,
        drawerInert: m.drawerInert,
        drawerTabStops: m.drawerTabStops,
        sub12Count: m.sub12Count,
        smallTargetsNonExempt: m.smallTargetsNonExempt,
        smallTargetsExempt: m.smallTargetsExempt,
        nav: m.navLabels,
        copyHits: m.copyHits,
        h1s: m.h1s,
        mcCards: m.mcCards,
        peopleCardsMode: m.peopleCardsMode,
        moreVisible: m.moreVisible,
        hamVisible: m.hamVisible,
        adminInertPresent: m.adminInertPresent,
      };
      matrix.push(row);

      const flag = (severity, claim, observed) => {
        findings.push({
          severity,
          role,
          route,
          viewport: vp.name,
          claim,
          observed,
        });
      };
      if (m.overflows) flag('High', 'horizontal-overflow', { docW: m.docW, vw: m.vw, offenders: m.overflowOffenders });
      if (m.clippedNav > 0) flag('High', 'nav-clipped', { clippedNav: m.clippedNav, nav: m.navLabels });
      if (m.h1Count < 1) flag('High', 'missing-h1', { h1s: m.h1s });
      if (m.mains < 1) flag('High', 'missing-main', {});
      const chromeless = CHROMELESS_ROUTES.has(route);
      if (chromeless) chromelessExempted += 1;
      if (!m.skipPresent && !chromeless) flag('High', 'missing-skip-link', {});
      if (m.skipPresent && !m.skipTargetExists) flag('Medium', 'skip-target-missing', { href: m.skipHref });
      if (m.portalMainIds > 1) flag('Medium', 'duplicate-portal-main-id', { count: m.portalMainIds });
      if (!m.campusVisible && !chromeless) flag('High', 'campus-control-not-visible', {
        desktop: m.campusDesktopVisible,
        mobile: m.campusMobileVisible,
        options: m.campusOptionCount,
      });
      if (!m.searchVisible && !chromeless) flag('High', 'search-trigger-not-visible', {});
      // A chrome-less route has no drawer to be inert; see CHROMELESS_ROUTES.
      if (!m.drawerInert && !chromeless) flag('High', 'closed-drawer-not-inert', {});
      if (m.drawerTabStops > 0) flag('High', 'closed-drawer-tab-stops', { count: m.drawerTabStops });
      if (m.smallTargetsNonExempt > 0) flag('Medium', 'wcag-2.5.8-sub-24px', {
        count: m.smallTargetsNonExempt,
        sample: m.smallTargetsSample,
      });
    }
  }

  // Keyboard / search / campus / agenda qualitative probes at 390 and 1440
  for (const vp of [{ name: '390', width: 390, height: 844 }, { name: '1440', width: 1440, height: 900 }]) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await goto(page, '/');
    const searchOpened = await page.evaluate(() => {
      const ev = new KeyboardEvent('keydown', { key: 'k', code: 'KeyK', ctrlKey: true, bubbles: true });
      document.dispatchEvent(ev);
      const overlay = document.querySelector('.search-overlay, #searchOverlay, [data-search-overlay]');
      const input = document.querySelector('#searchInput, .search-overlay input, input[type="search"]');
      const overlayOpen = overlay && (overlay.classList.contains('open') || getComputedStyle(overlay).display !== 'none');
      return { overlayOpen: Boolean(overlayOpen), inputFocused: document.activeElement === input };
    });
    findings.push({
      severity: 'info',
      role,
      route: '/',
      viewport: vp.name,
      claim: 'ctrl-k-search',
      observed: searchOpened,
    });
    await page.keyboard.press('Escape');

    const skipFocus = await page.evaluate(() => {
      const skip = document.querySelector('a.skip-link');
      if (!skip) return { present: false };
      skip.focus();
      const st = getComputedStyle(skip);
      const r = skip.getBoundingClientRect();
      return {
        present: true,
        focused: document.activeElement === skip,
        left: Math.round(r.left),
        outline: st.outline,
        boxShadow: st.boxShadow,
        color: st.color,
        background: st.backgroundColor,
      };
    });
    findings.push({
      severity: 'info',
      role,
      route: '/',
      viewport: vp.name,
      claim: 'skip-link-focus',
      observed: skipFocus,
    });

    if (role === 'member' && vp.name === '390') {
      await goto(page, '/calendar');
      const agenda = await page.evaluate(() => {
        const buttons = [...document.querySelectorAll('button, [role="tab"]')].map((b) => ({
          text: (b.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40),
          active: b.classList.contains('active') || b.getAttribute('aria-selected') === 'true',
          w: Math.round(b.getBoundingClientRect().width),
          h: Math.round(b.getBoundingClientRect().height),
        }));
        const items = document.querySelectorAll('.agenda-item, .agenda li, [data-agenda-item], .cal-agenda .row, .agenda-row');
        const minH = items.length
          ? Math.min(...[...items].map((el) => el.getBoundingClientRect().height).filter((h) => h > 0))
          : 0;
        return {
          buttons: buttons.filter((b) => /month|week|day|agenda/i.test(b.text)),
          agendaItemCount: items.length,
          agendaMinHeight: minH,
          viewHint: (document.body.innerText || '').slice(0, 0),
        };
      });
      findings.push({
        severity: 'info',
        role,
        route: '/calendar',
        viewport: vp.name,
        claim: 'calendar-agenda-phone',
        observed: agenda,
      });
    }
  }

  await context.close();
  return { findings, matrix, navFingerprints: [...navSet] };
}

async function privacyAndApi() {
  const out = { checks: [], failures: 0 };
  const get = async (path, headers = {}) => {
    const res = await fetch(`${BASE}${path}`, { headers, redirect: 'manual' });
    const text = await res.text();
    return { status: res.status, text, headers: Object.fromEntries(res.headers.entries()) };
  };
  for (const p of [
    '/api/people-directory',
    '/api/me',
    '/api/ministries',
    '/api/admin/ministries',
    '/api/admin/leaders',
  ]) {
    const r = await get(p);
    const ok = r.status === 401;
    out.checks.push({ label: `${p} anonymous`, status: r.status, ok });
    if (!ok) out.failures += 1;
  }
  const pub = await get('/api/public/people-directory');
  if (pub.status !== 200) {
    out.checks.push({ label: 'public directory reachable', status: pub.status, ok: false });
    out.failures += 1;
  } else {
    const data = JSON.parse(pub.text);
    const people = data.people || data.items || [];
    const lower = pub.text.toLowerCase();
    const fields = ['"email"', '"address"', '"address1"', '"homephone"', '"cellphone"'];
    for (const f of fields) {
      const hit = lower.includes(f);
      out.checks.push({ label: `public omits ${f}`, ok: !hit });
      if (hit) out.failures += 1;
    }
    let unmasked = 0;
    for (const p of people) {
      const display = String(p.displayName || '');
      const initial = String(p.lastInitial || '');
      if (initial && !/^[A-Za-z]\.?$/.test(initial)) unmasked += 1;
      if (display && /\s+[A-Za-z]{2,}$/.test(display) && !/\s+[A-Za-z]\.$/.test(display)) unmasked += 1;
    }
    out.checks.push({ label: `surname-masked (${people.length} entries)`, ok: unmasked === 0, unmasked });
    if (unmasked !== 0) out.failures += 1;
    out.peopleCount = people.length;
  }
  const html = await get('/people');
  const needles = ['mailto:', '@gmail.', '@yahoo.', '@hotmail.'];
  for (const n of needles) {
    const hit = html.text.toLowerCase().includes(n);
    out.checks.push({ label: `/people HTML omits ${n}`, ok: !hit });
    if (hit) out.failures += 1;
  }
  return out;
}

function contrastFromTheme() {
  const theme = JSON.parse(fs.readFileSync(path.join(root, 'config/theme.json'), 'utf8'));
  function lum(h) {
    h = h.replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    const c = [0, 2, 4].map((i) => {
      const v = parseInt(h.slice(i, i + 2), 16) / 255;
      return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function cr(a, b) {
    const l1 = lum(a);
    const l2 = lum(b);
    const hi = Math.max(l1, l2);
    const lo = Math.min(l1, l2);
    return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
  }
  const failures = [];
  let checked = 0;
  const presets = Object.keys(theme.presets);
  for (const name of presets) {
    const v = theme.presets[name].vars;
    const paper = v['--paper'] ?? '#ffffff';
    const soft = v['--soft'] ?? paper;
    const tests = [
      ['ink on paper', v['--ink'], paper],
      ['muted on paper', v['--muted'], paper],
      ['muted on soft', v['--muted'], soft],
    ];
    for (const t of ['--teal', '--gold', '--rose', '--blue']) {
      const nm = t.slice(2);
      if (!v[t]) continue;
      if (v[`--on-${nm}`]) tests.push([`on-${nm} on ${nm}`, v[`--on-${nm}`], v[t]]);
      else tests.push([`MISSING --on-${nm}`, '#ffffff', v[t]]);
      if (v[`${t}-ink`]) {
        tests.push([`${nm}-ink on paper`, v[`${t}-ink`], paper]);
        tests.push([`${nm}-ink on soft`, v[`${t}-ink`], soft]);
      } else tests.push([`MISSING ${t}-ink`, v[t], paper]);
    }
    for (const [label, a, b] of tests) {
      if (!a || !b) continue;
      checked += 1;
      const r = cr(a, b);
      if (r < 4.5) failures.push({ preset: name, label, ratio: r });
    }
  }
  return { presets: presets.length, checked, failures };
}

async function standaloneReachable() {
  const rows = [];
  for (const url of STANDALONE) {
    try {
      const res = await fetch(url, { redirect: 'manual' });
      rows.push({ url, status: res.status });
    } catch (e) {
      rows.push({ url, status: 0, error: 'fetch-failed' });
    }
  }
  return rows;
}

async function main() {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  const contrast = contrastFromTheme();
  const privacy = await privacyAndApi();
  const standalone = await standaloneReachable();

  const browser = await chromium.launch({ headless: true });
  const anon = await runRole(browser, 'anonymous', ANON_ROUTES, undefined);

  let member = { findings: [], matrix: [], navFingerprints: [], login: 'skipped' };
  if (EMAIL && PASSWORD) {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    const loginRes = await page.request.post(`${BASE}/api/login`, {
      data: { email: EMAIL, password: PASSWORD },
      headers: { 'Content-Type': 'application/json' },
    });
    const loginStatus = loginRes.status();
    const loginJson = await loginRes.json().catch(() => ({}));
    const loginOk = loginStatus === 200 && !loginJson.error;
    member.login = { status: loginStatus, ok: loginOk, hasActor: Boolean(loginJson.actor || loginJson.user || loginJson.ok || loginOk) };
    if (loginOk) {
      const state = await ctx.storageState();
      await ctx.close();
      const m = await runRole(browser, 'member', MEMBER_ROUTES, state);
      member = { ...m, login: member.login };
    } else {
      await ctx.close();
    }
  }

  await browser.close();

  const report = {
    generatedAt: new Date().toISOString(),
    base: BASE,
    harness: {
      sourceContracts: 'run separately',
      contrast,
      privacy: { failures: privacy.failures, checkCount: privacy.checks.length, peopleCount: privacy.peopleCount ?? null },
      standalone,
    },
    claims: {
      distinctNavAnon: anon.navFingerprints.length,
      distinctNavMember: member.navFingerprints.length,
      navFingerprintsAnon: anon.navFingerprints,
      navFingerprintsMember: member.navFingerprints,
    },
    findingCounts: {
      anon: anon.findings.filter((f) => f.severity !== 'info').length,
      member: member.findings.filter((f) => f.severity !== 'info').length,
    },
    findings: [...anon.findings, ...member.findings],
    matrix: [...anon.matrix, ...member.matrix],
    memberLogin: member.login,
  };

  fs.writeFileSync(path.join(OUT_DIR, 'raw.json'), JSON.stringify(report, null, 2));
  const summary = {
    generatedAt: report.generatedAt,
    contrast,
    privacyFailures: privacy.failures,
    privacyChecks: privacy.checks,
    standalone,
    distinctNavAnon: anon.navFingerprints.length,
    distinctNavMember: member.navFingerprints.length,
    overflow: report.findings.filter((f) => f.claim === 'horizontal-overflow').length,
    clippedNav: report.findings.filter((f) => f.claim === 'nav-clipped').length,
    missingH1: report.findings.filter((f) => f.claim === 'missing-h1').length,
    missingMain: report.findings.filter((f) => f.claim === 'missing-main').length,
    chromelessRouteChecksExempted: chromelessExempted,
    chromelessRoutes: [...CHROMELESS_ROUTES],
    missingSkip: report.findings.filter((f) => f.claim === 'missing-skip-link').length,
    campusHidden: report.findings.filter((f) => f.claim === 'campus-control-not-visible').length,
    searchHidden: report.findings.filter((f) => f.claim === 'search-trigger-not-visible').length,
    drawerTabs: report.findings.filter((f) => f.claim === 'closed-drawer-tab-stops').length,
    duplicateMain: report.findings.filter((f) => f.claim === 'duplicate-portal-main-id').length,
    sub24: report.findings.filter((f) => f.claim === 'wcag-2.5.8-sub-24px').length,
    memberLogin: member.login,
  };
  fs.writeFileSync(path.join(OUT_DIR, 'summary.json'), JSON.stringify(summary, null, 2));
  console.log(JSON.stringify(summary, null, 2));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
