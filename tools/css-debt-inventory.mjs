#!/usr/bin/env node
/**
 * CSS / markup technical-debt inventory for the Church Portal PHP views.
 *
 * Does not rewrite anything. Emits JSON + Markdown so later UI work can
 * show measurable before/after (hardcoded colors, breakpoints, viewports).
 *
 * Usage: node tools/css-debt-inventory.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const scanRoots = [
  'resources/views',
  'resources/css',
  'printable',
  'people_signup',
  'events_rsvp',
  'admin',
];

const COLOR_RE = /#(?:[0-9a-fA-F]{3,8})\b|rgba?\(\s*[\d.]+(?:\s*,\s*[\d.]+){2,3}\s*\)|hsla?\(\s*[\d.]+(?:\s*,\s*[\d.%]+){2,3}\s*\)/g;
const MEDIA_RE = /@media[^{]+\{/g;
const BP_RE = /(?:max-width|min-width)\s*:\s*(\d+)px/g;
const MINWIDTH_DECL_RE = /min-width\s*:\s*[^;]+;/g;
const OUTLINE_NONE_RE = /outline\s*:\s*none/gi;
const FOCUS_RE = /:focus(?:-visible)?\b/g;
const STYLE_BLOCK_RE = /<style\b[^>]*>/gi;
const VIEWPORT_RE = /<meta\s+[^>]*name=["']viewport["'][^>]*>/i;
const CLASS_ATTR_RE = /class=["']([^"']+)["']/g;
const HEX_IN_STYLE_VAR = /--[a-z-]+:\s*#[0-9a-fA-F]{3,8}/g;

/**
 * Token values, by preset. A hardcoded literal that exactly equals a themed
 * token value is the highest-value debt in the product: it looks correct under
 * the default preset and silently fails to change under the other eight. That
 * makes it a *correctness* defect, not just untidiness — which is why the
 * inventory separates it from ordinary one-off colours.
 */
function loadThemeTokens() {
  const file = path.join(root, 'config/theme.json');
  const byValue = new Map();
  if (!fs.existsSync(file)) return byValue;
  const presets = JSON.parse(fs.readFileSync(file, 'utf8')).presets ?? {};
  for (const [presetName, preset] of Object.entries(presets)) {
    for (const [token, value] of Object.entries(preset.vars ?? {})) {
      if (typeof value !== 'string') continue;
      const key = normaliseColor(value);
      if (!key) continue;
      if (!byValue.has(key)) byValue.set(key, new Set());
      byValue.get(key).add(`${token} (${presetName})`);
    }
  }
  return byValue;
}

/** #ABC and #AABBCC compare equal; case is irrelevant. */
function normaliseColor(value) {
  const text = String(value).trim().toLowerCase();
  const short = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/.exec(text);
  if (short) return `#${short[1]}${short[1]}${short[2]}${short[2]}${short[3]}${short[3]}`;
  if (/^#[0-9a-f]{6}$/.test(text)) return text;
  if (/^#[0-9a-f]{8}$/.test(text)) return text.slice(0, 7);
  return text;
}

const NEUTRAL_RE = /^#(?:([0-9a-f])\1){3}$/;

/** Pure greys, black and white carry no brand hue, so they rarely need a token. */
function isNeutral(value) {
  const key = normaliseColor(value);
  if (NEUTRAL_RE.test(key)) return true;
  const rgb = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/.exec(key);
  if (!rgb) return false;
  const [r, g, b] = rgb.slice(1).map((h) => parseInt(h, 16));
  return Math.max(r, g, b) - Math.min(r, g, b) <= 6;
}

/**
 * A page inherits viewport meta when it renders through a shell that emits one.
 * Scanning files in isolation reports those pages as defective when the served
 * HTML is correct — verified against the rendered output of /admin.
 */
const SHELL_INCLUDE_RE = /(?:require|include)(?:_once)?[^;]*_(?:portal|admin|printable)-shell\.php/;

function shellEmitsViewport(shellFile) {
  const full = path.join(root, shellFile);
  return fs.existsSync(full) && VIEWPORT_RE.test(fs.readFileSync(full, 'utf8'));
}

/**
 * outline:none is only a defect when nothing replaces the indicator. Counting
 * every occurrence overstates the risk: most are paired with a box-shadow ring
 * or a border-colour change in the same rule.
 */
function classifyOutlineNone(source) {
  const rules = [];
  const ruleRe = /([^{}]*)\{([^{}]*)\}/g;
  let match;
  while ((match = ruleRe.exec(source)) !== null) {
    rules.push({ selector: match[1].split(/[\n;]/).pop().trim(), body: match[2] });
  }

  // A replacement indicator is frequently declared in a *separate* rule from
  // the outline:none — `.x{outline:none}` then `.x:focus{box-shadow:...}`. A
  // per-rule check reports those as unpaired when they are correctly handled,
  // so the indicator set is collected across the whole file first.
  const withIndicator = new Set();
  for (const rule of rules) {
    const hasRing = /box-shadow\s*:\s*(?!none)/i.test(rule.body)
      || /outline\s*:\s*(?!none|0\b)/i.test(rule.body)
      || /border-color\s*:/i.test(rule.body);
    if (!hasRing) continue;
    for (const part of rule.selector.split(',')) {
      withIndicator.add(baseSelector(part));
    }
  }

  const paired = [];
  const unpaired = [];
  for (const rule of rules) {
    if (!/outline\s*:\s*(?:none|0)\b/i.test(rule.body)) continue;
    for (const part of rule.selector.split(',')) {
      const base = baseSelector(part);
      if (base === '') continue;
      (withIndicator.has(base) ? paired : unpaired).push(base);
    }
  }
  return { paired, unpaired };
}

/** `.x:focus-visible > a` and `.x` compare equal for indicator purposes. */
function baseSelector(selector) {
  return selector
    // Shell CSS is built by PHP string concatenation, so a selector arrives as
    // `. '.topbar-campus-select`. Left as-is, the concatenation operator reads
    // as part of the selector and the rule never matches its own indicator.
    .replace(/["']/g, '')
    .replace(/^\s*\.\s+/, '')
    .replace(/::?[a-z-]+(\([^)]*\))?/gi, '')
    .replace(/\s+/g, ' ')
    .replace(/^[^a-z0-9.#\[]*/i, '')
    .trim();
}

function walk(dir, acc = []) {
  if (!fs.existsSync(dir)) return acc;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (['node_modules', 'vendor', '.git'].includes(entry.name)) continue;
      walk(full, acc);
    } else if (/\.(php|css|js|html|md)$/.test(entry.name)) {
      acc.push(full);
    }
  }
  return acc;
}

function rel(file) {
  return path.relative(root, file).replaceAll('\\', '/');
}

const files = scanRoots.flatMap((dir) => walk(path.join(root, dir)));
const perFile = [];
const breakpointSet = new Map();
const classFiles = new Map();
const colorSet = new Map();
let styleBlocks = 0;
let colorCount = 0;
let minWidthDecls = 0;
let outlineNone = 0;
let focusRules = 0;
let viewportPresent = 0;
let viewportMissing = [];
let viewportInherited = [];
let htmlPages = [];
const themeTokens = loadThemeTokens();
const outlineUnpaired = [];
let outlinePaired = 0;

for (const file of files) {
  const source = fs.readFileSync(file, 'utf8');
  const relative = rel(file);
  const fileStyleBlocks = source.match(STYLE_BLOCK_RE)?.length ?? 0;
  const colors = source.match(COLOR_RE) ?? [];
  const minWidths = source.match(MINWIDTH_DECL_RE) ?? [];
  const outlines = source.match(OUTLINE_NONE_RE) ?? [];
  const focuses = source.match(FOCUS_RE) ?? [];
  const media = source.match(MEDIA_RE) ?? [];
  const isHtmlPage = /<!doctype html|<html[\s>]/i.test(source) && file.endsWith('.php');

  styleBlocks += fileStyleBlocks;
  colorCount += colors.length;
  minWidthDecls += minWidths.length;
  outlineNone += outlines.length;
  focusRules += focuses.length;

  for (const color of colors) {
    colorSet.set(color, (colorSet.get(color) ?? 0) + 1);
  }

  let bpMatch;
  const bpRe = new RegExp(BP_RE.source, 'g');
  while ((bpMatch = bpRe.exec(source)) !== null) {
    const px = bpMatch[1];
    if (!breakpointSet.has(px)) breakpointSet.set(px, []);
    breakpointSet.get(px).push(relative);
  }

  let classMatch;
  const classRe = new RegExp(CLASS_ATTR_RE.source, 'g');
  while ((classMatch = classRe.exec(source)) !== null) {
    for (const name of classMatch[1].split(/\s+/).filter(Boolean)) {
      if (!classFiles.has(name)) classFiles.set(name, new Set());
      classFiles.get(name).add(relative);
    }
  }

  const outlineClass = classifyOutlineNone(source);
  outlinePaired += outlineClass.paired.length;
  for (const selector of outlineClass.unpaired) {
    outlineUnpaired.push({ file: relative, selector });
  }

  if (isHtmlPage) {
    htmlPages.push(relative);
    if (VIEWPORT_RE.test(source)) {
      viewportPresent += 1;
    } else if (SHELL_INCLUDE_RE.test(source) && ['resources/views/_portal-shell.php', 'resources/views/_admin-shell.php', 'printable/_printable-shell.php'].some(shellEmitsViewport)) {
      viewportInherited.push(relative);
    } else {
      viewportMissing.push(relative);
    }
  }

  if (fileStyleBlocks || colors.length || minWidths.length || media.length) {
    perFile.push({
      file: relative,
      styleBlocks: fileStyleBlocks,
      hardcodedColors: colors.length,
      mediaQueries: media.length,
      minWidthDeclarations: minWidths.length,
      outlineNone: outlines.length,
      focusSelectors: focuses.length,
      cssVariablesWithHex: source.match(HEX_IN_STYLE_VAR)?.length ?? 0,
    });
  }
}

perFile.sort((a, b) => b.hardcodedColors - a.hardcodedColors);

const duplicatedClasses = [...classFiles.entries()]
  .filter(([, set]) => set.size >= 4)
  .map(([name, set]) => ({ className: name, fileCount: set.size, files: [...set].slice(0, 12) }))
  .sort((a, b) => b.fileCount - a.fileCount);

const breakpoints = [...breakpointSet.entries()]
  .map(([px, filesForBp]) => ({
    pixels: Number(px),
    occurrences: filesForBp.length,
    files: [...new Set(filesForBp)].slice(0, 20),
  }))
  .sort((a, b) => a.pixels - b.pixels);

// Classify every unique literal. The three buckets carry different risk and
// therefore different remediation strategies — see docs/design/20-css-debt-strategy.md.
const colorClasses = { themeCollision: [], neutral: [], oneOff: [] };
for (const [value, count] of colorSet.entries()) {
  const tokens = themeTokens.get(normaliseColor(value));
  if (tokens) {
    colorClasses.themeCollision.push({ value, count, matches: [...tokens].slice(0, 4) });
  } else if (isNeutral(value)) {
    colorClasses.neutral.push({ value, count });
  } else {
    colorClasses.oneOff.push({ value, count });
  }
}
for (const bucket of Object.values(colorClasses)) bucket.sort((a, b) => b.count - a.count);

const themeCollisionOccurrences = colorClasses.themeCollision.reduce((sum, entry) => sum + entry.count, 0);

const report = {
  generatedAt: new Date().toISOString(),
  scanRoots,
  totals: {
    filesScanned: files.length,
    htmlPages: htmlPages.length,
    inlineStyleBlocks: styleBlocks,
    hardcodedColorLiterals: colorCount,
    uniqueColorLiterals: colorSet.size,
    uniqueBreakpointPxValues: breakpoints.length,
    minWidthDeclarations: minWidthDecls,
    outlineNoneDeclarations: outlineNone,
    focusSelectors: focusRules,
    pagesWithOwnViewportMeta: viewportPresent,
    pagesInheritingViewportMeta: viewportInherited.length,
    pagesMissingViewportMeta: viewportMissing.length,
    colorLiteralsCollidingWithThemeTokens: colorClasses.themeCollision.length,
    colorLiteralOccurrencesCollidingWithThemeTokens: themeCollisionOccurrences,
    neutralColorLiterals: colorClasses.neutral.length,
    oneOffColorLiterals: colorClasses.oneOff.length,
    outlineNonePaired: outlinePaired,
    outlineNoneUnpaired: outlineUnpaired.length,
    classesUsedInFourOrMoreFiles: duplicatedClasses.length,
  },
  pagesMissingViewportMeta: viewportMissing,
  pagesInheritingViewportMeta: viewportInherited,
  colorClasses,
  outlineNoneUnpaired: outlineUnpaired,
  breakpoints,
  topColorLiterals: [...colorSet.entries()]
    .sort((a, b) => b[1] - a[1])
    .slice(0, 40)
    .map(([value, count]) => ({ value, count })),
  duplicatedClassNames: duplicatedClasses.slice(0, 60),
  heaviestFiles: perFile.slice(0, 40),
};

const outDir = path.join(root, 'reports');
fs.mkdirSync(outDir, { recursive: true });
const jsonPath = path.join(outDir, 'css-debt-inventory.json');
fs.writeFileSync(jsonPath, JSON.stringify(report, null, 2));

const md = [];
md.push('# Church Portal CSS technical-debt inventory');
md.push('');
md.push(`Generated: ${report.generatedAt}`);
md.push('');
md.push('This is a **baseline**, not a rewrite. Later UI work should drive these numbers down without changing product behavior.');
md.push('');
md.push('## Totals');
md.push('');
md.push('| Metric | Count |');
md.push('|---|---|');
for (const [key, value] of Object.entries(report.totals)) {
  md.push(`| \`${key}\` | ${value} |`);
}
md.push('');
md.push('## Viewport meta coverage');
md.push('');
if (viewportMissing.length === 0) {
  md.push('All scanned HTML PHP pages include a viewport meta tag.');
} else {
  md.push('Pages **missing** `<meta name="viewport">`:');
  md.push('');
  for (const file of viewportMissing) md.push(`- \`${file}\``);
}
md.push('');
md.push('## Breakpoints (`max-width` / `min-width` px values)');
md.push('');
md.push('| px | occurrences | sample files |');
md.push('|---|---|---|');
for (const row of breakpoints) {
  md.push(`| ${row.pixels} | ${row.occurrences} | ${row.files.slice(0, 4).join(', ')} |`);
}
md.push('');
md.push('## Heaviest files by hardcoded color literals');
md.push('');
md.push('| File | colors | `<style>` blocks | media queries | min-width decls | outline:none | :focus |');
md.push('|---|---|---|---|---|---|---|');
for (const row of report.heaviestFiles.slice(0, 25)) {
  md.push(`| \`${row.file}\` | ${row.hardcodedColors} | ${row.styleBlocks} | ${row.mediaQueries} | ${row.minWidthDeclarations} | ${row.outlineNone} | ${row.focusSelectors} |`);
}
md.push('');
md.push('## How to re-run');
md.push('');
md.push('```bash');
md.push('node tools/css-debt-inventory.mjs');
md.push('```');
md.push('');
md.push('Compare `reports/css-debt-inventory.json` before and after visual modernization.');
md.push('');

const mdPath = path.join(outDir, 'css-debt-inventory.md');
fs.writeFileSync(mdPath, md.join('\n'));

console.log(`Wrote ${rel(jsonPath)}`);
console.log(`Wrote ${rel(mdPath)}`);
console.log(JSON.stringify(report.totals, null, 2));
