# MASTER Design System — Church Portal

Status: **in use** for theme tokens (`config/theme.json`). Created 2026-08-24
from the UI/UX audit (the audit markdown under `docs/design/` was removed).

> Contrast is fixed by pairing, not by changing brand hues. Each fill carries
> `--on-<tok>` (foreground on that fill) and `--<tok>-ink` (the hue darkened
> for use as text). Only `--muted` was nudged in a few presets.

Retrieval convention (ui-ux-pro-max): read this file first; then check
`design-system/church-portal/pages/<page>.md` for an override; if none exists,
Master rules apply exclusively.

## Provenance — read this before treating any value as authoritative

| Source | What it contributed | Status |
|---|---|---|
| `ui-ux-pro-max --design-system` | **Style: Minimalism & Swiss** (Best for: enterprise apps, dashboards, professional tools; a11y risk low; requires contrast-text-4.5, keyboard, visible-focus, reduced-motion) | **Adopted** — returned consistently across two independent queries |
| `ui-ux-pro-max --design-system` | Pattern "Hero + Testimonials/Features + CTA"; purple `#7C3AED` + Cormorant Garamond; then navy `#1E3A5F` + Outfit/Work Sans | **Rejected** — both runs returned landing-page patterns. This is an operational portal, not a marketing site, and the palettes are exactly the "generic SaaS" / "church brochure" outcomes the brief rules out. |
| `ui-ux-pro-max --domain ux` | Mobile-first vs max-width queries; focus-not-obscured; error-summary + inline errors; empty-state guidance; touch-target rules; table handling | **Adopted** — cited inline below |
| Existing `config/theme.json` | Forest palette, `--radius`, `--font-scale`, 9 presets | **Kept and extended** — it is already warm, distinctive, and church-appropriate |
| Existing `people_signup/assets/signup.css` | 48 px inputs, `box-shadow` focus rings, mobile-first authoring | **Promoted to Master** — the best CSS already in the product |
| Measured contrast (this audit) | Every colour value below | **Verified** — ratios quoted |

**Colour direction: keep the forest palette.** Replacing a warm, distinctive
deep-teal identity with generic navy or purple would trade away the one thing
that already reads as "this church's tool" and move toward the banking-dashboard
look the brief prohibits. The work is to *fix* the palette's accessibility, not
to swap it.

---

## 1. Colour tokens

### 1.0 Scope correction — this applies to ALL 9 presets

The table below was originally derived from the `forest` preset alone. That was
insufficient. Measured across every preset in `config/theme.json`:

| Preset | Failures below 4.5:1 |
|---|---|
| minimalist | **none** |
| forest | muted-on-soft 4.35 · gold 3.07 · white-on-gold 3.07 |
| compact | muted-on-soft 4.35 · gold 3.98 |
| facebook | teal 4.23 · white-on-teal 4.23 · gold 1.76 · white-on-blue 4.23 |
| metallic-chic | gold 1.92 |
| cool-collected | gold 2.10 |
| warm | teal 4.46 · gold 2.98 · white-on-gold 3.07 |
| earthy-serene | muted 4.23 · muted-on-soft 3.58 · teal 3.56 · white-on-teal 3.93 · white-on-blue 2.18 |
| **vibrant-calm** | **teal 2.32 · white-on-teal 2.39 · white-on-rose 1.88** |

**8 of 9 presets fail.** `vibrant-calm` renders the primary action button at
**2.39:1**. Every preset must pass a CI contrast gate for the full token matrix;
a preset that cannot pass is corrected or **withdrawn from `/admin/theme`**,
never shipped (INV-6).

Token names below are **canonical**; legacy names (`--teal`, `--ink`, `--muted`,
`--gold`, `--rose`, `--blue`) survive as **compatibility aliases** so existing
views keep rendering. Literals migrate per-file, per-phase — a repo-wide colour
search-and-replace is prohibited (INV-5).

### 1.1 Forest reference values — as implemented in Phase 0

Verified against `config/theme.json`. *(kept)* = byte-identical to the original.

| Token | Value | Notes |
|---|---|---|
| `--ink` | `#17211b` | *(kept)* 16.54:1 on paper |
| `--muted` | `#627169` | **nudged** from `#66756d`, which measured **4.35:1 on `--soft`** (fails AA). ΔE 1.6 — imperceptible. A foreground cannot be fixed by pairing. |
| `--line` | `#d9e4dd` | *(kept)* borders only, never text |
| `--paper` | `#ffffff` | *(kept)* card surface |
| `--bg` | `#f7faf8` | *(kept)* app background |
| `--soft` | `#eef4f0` | *(kept)* subtle fill / table header |
| `--deep` | `#123b31` | *(kept)* header, 12.38:1 |
| `--gradient-top` / `--gradient-mid` | `#0c2f28` / `#123b31` | *(kept)* |
| `--radius` · `--spacing` · `--font-scale` | `8px` · `1` · `1` | *(kept — preset-owned, see §4)* |

### Brand hues and their pairs

**The hue is never changed.** Each fill carries a generated foreground.

| Fill *(kept)* | `--on-<tok>` | `--<tok>-ink` | Notes |
|---|---|---|---|
| `--teal` `#117b6d` | `#ffffff` (5.15) | `#117b6d` | already safe under white text |
| `--gold` `#c48725` | `#3b280b` | `#92651c` | white on gold = **3.07:1**, prohibited |
| `--rose` `#b84957` | `#ffffff` (5.09) | `#b84957` | safe |
| `--blue` `#276a9f` | `#ffffff` (5.76) | `#276a9f` | safe |

Usage rule:

- filling a surface with a brand hue → foreground **must** be `var(--on-<tok>)`,
  never a literal `#fff`
- the hue as text on `--paper`/`--soft`/`--bg` → use `var(--<tok>-ink)`
- decoration with no text on it (borders, icons, chart fills) → the raw hue

Semantic tokens (`--success`, `--danger`, `--info`) are promoted from existing
hardcoded literals during Phase 2, following the same pairing rule.

### Focus

| Token | Value | Why |
|---|---|---|
| `--focus-ring` | `#0e6a5e` | on light surfaces |
| `--focus-ring-inverse` | `#ffffff` | on `--deep` — **12.38:1**, versus the current UA default `#101010` on `--deep` which measures **1.54:1 (invisible)** |
| `--focus-shadow` | `0 0 0 3px color-mix(in srgb, var(--teal) 30%, transparent)` | pattern lifted from `signup.css`. **Phase 2 must add an `rgba()` fallback** before any component depends on it. |

**Rule:** 913 hardcoded hex values currently live in views. No new raw hex in any
component — token or nothing.

---

## 2. Typography

**Keep Inter.** The skill suggested Outfit + Work Sans; declining, because Inter
is already loaded, is a superb operational UI face at small sizes, and adding two
display webfonts to a self-hosted PHP app buys a marketing look this product does
not want. A single optional display face for page titles is the only extension
worth considering (see Open Questions).

Current state: **13 unrelated sizes on `/`** including a leaked `13.3333px` and a
sub-floor `10px`; every page invents its own ramp; the ramp is identical at 1440
and 390 px.

### Scale — 8 steps, 1.20 ratio, fluid where it matters

| Token | Mobile → Desktop | Weight / line-height | Use |
|---|---|---|---|
| `--fs-display` | `clamp(26px, 5vw, 34px)` | 700 / 1.15 | Page hero title, one per page |
| `--fs-h1` | `clamp(22px, 3.2vw, 28px)` | 700 / 1.2 | Page title |
| `--fs-h2` | `clamp(18px, 2.2vw, 22px)` | 650 / 1.25 | Section |
| `--fs-h3` | `17px` | 650 / 1.3 | Card title |
| `--fs-body` | `16px` | 400 / 1.55 | **Body floor — never below this for prose** |
| `--fs-sm` | `14px` | 400 / 1.5 | Secondary, table cells |
| `--fs-xs` | `13px` | 500 / 1.45 | Meta, captions |
| `--fs-micro` | `12px` | 600 / 1.4 | **Absolute floor.** Badges, uppercase labels only |

Retire `10px`, `11px`, `13.3333px`, `12.5px`, `14.5px`, `21px`, `19px`, `17px`
(as an ad-hoc value), `30px`, `34px`. `--font-scale` in `theme.json` already
drives `html{font-size:calc(14px * var(--font-scale))}` — keep that hook and let
the clamp() scale ride on it.

**Nav labels:** currently 10–11 px uppercase. Move to `--fs-micro` (12px/600) with
`letter-spacing: .04em`, or drop uppercase and use `--fs-sm`.

---

## 3. Spacing — 4 px base

`--sp-1: 4px` · `--sp-2: 8px` · `--sp-3: 12px` · `--sp-4: 16px` · `--sp-5: 20px` ·
`--sp-6: 24px` · `--sp-8: 32px` · `--sp-10: 40px` · `--sp-12: 48px` · `--sp-16: 64px`

Density dial 7/10 (operational, data-dense but not cramped). Card padding
`--sp-4` mobile / `--sp-5` desktop. Section rhythm `--sp-8`. Page gutter
`--sp-4` mobile / `--sp-6` desktop.

`theme.json`'s existing `--spacing` multiplier is retained as a global scale.

---

## 4. Radii, elevation, borders

**Radii are DERIVED, never hardcoded.** Presets own `--radius` and vary it
**4px → 14px** (`minimalist` 4 · `compact` 6 · `forest`/`facebook`/`earthy-serene` 8 ·
`cool-collected`/`warm` 10 · `metallic-chic` 12 · `vibrant-calm` 14). A fixed
ladder would break theme switching:

```css
--radius-sm:  calc(var(--radius) * .6);
--radius-lg:  calc(var(--radius) * 1.4);
--radius-full: 999px;
```

| Token | Resolves to (forest, `--radius:8px`) | Use |
|---|---|---|
| `--radius-sm` | ~5px | badges, chips, inline inputs |
| `--radius` | 8px *(preset-owned)* | buttons, inputs, cards |
| `--radius-lg` | ~11px | sheets, dialogs, hero panels |
| `--radius-full` | 999px | avatars, pills |

The same rule applies to spacing (`--spacing` varies 0.85 → 1.1) and type
(`--font-scale` varies 0.92 → 1.0). Both are preset-owned and must be respected,
not overridden.

Elevation — **three levels only** (currently 4–8 distinct shadows per page):

| Token | Value | Use |
|---|---|---|
| `--shadow-1` | `0 1px 2px rgba(12,40,30,.06)` | cards at rest |
| `--shadow-2` | `0 4px 12px rgba(12,40,30,.10)` | dropdowns, popovers, raised cards |
| `--shadow-3` | `0 18px 46px rgba(12,40,30,.14)` | dialogs, bottom sheets *(from signup.css)* |

Swiss discipline: prefer a `--line` border over a shadow. Never combine
`--shadow-2` with a border on the same element.

---

## 5. Components

Sizes assume the **44 px minimum touch target** on all pointer types (currently
54–74 % of mobile controls fail this).

### Buttons

| Variant | Fill | Text | Border | Use |
|---|---|---|---|---|
| Primary | `--primary` | `#fff` (5.15) | none | one per view |
| Secondary | `--paper` | `--primary-700` | `--line-strong` | common actions |
| Ghost | transparent | `--primary-700` | none | tertiary, toolbars |
| Danger | `--danger` | `#fff` (6.54) | none | destructive, always confirmed |
| Inverse | `#fff` | `--deep` | none | **only on `--deep` surfaces** |

Heights `36 / 44 / 52` (`sm` desktop-dense only / **`md` default** / `lg` mobile
primary). Hover 150–250 ms. **Fixes M6:** one sign-in control, one label
("Sign in"), one variant, defined once in `portal_header()`.

### Inputs, selects, textareas
`min-height: 48px` (from signup.css), `--radius`, `1.5px solid --line-strong`,
`--fs-body` (16 px prevents iOS zoom-on-focus). Focus:
`border-color: --primary; box-shadow: --focus-shadow; outline: none` — a
replacement ring, never a bare removal.

Labels are **always visible** above the field. Placeholder is never the label.
Errors: inline below the field, `--danger-2`, wired with `aria-describedby`, plus
a **focusable error summary** at the top of the form on failed submit
(`ui-ux-pro-max`: *Focusable Error Summary, severity High* — "link each item to
its invalid field; retain inline errors").

### Cards
`--paper`, `1px solid --line`, `--radius`, `--shadow-1`, padding `--sp-4/5`.
Structure: optional kicker → title (`--fs-h3`) → body (`--fs-sm`) → footer action.
**Footer actions baseline-align across a row** (fixes P3) — use
`grid-template-rows: auto 1fr auto`.

### Lists & tables
- **≥1024 px:** table. `--soft` header, `--fs-sm` cells, 44 px min row height,
  `--line` rules, sticky header inside a labelled scroll region.
- **<1024 px:** the table is **replaced**, not scrolled — a stacked card list with
  a 2–3 field summary and the rest behind a disclosure. `ui-ux-pro-max` permits
  either scroll or cards; for a 7-column directory on a 390 px phone, cards win.
- Column count over 5 requires a column-visibility control on desktop.
- Never `min-width: 640px` on a table inside a 390 px viewport (current
  `schedule-editor` behaviour).

### Badges
`--radius-sm`, `--fs-micro`, tinted fill + dark text — verified pairs:
`--primary-800` on `--primary-tint` = **8.06**; `#75500f` on `#fdf3e0` = **6.55**;
`--danger-2` on `#fdecea` = **5.95**. Never white text on `--warning-accent`.

### Tabs, segmented controls
44 px min height; active = `--primary` underline (2 px) + `--ink` text — never
colour alone. **Never wrap into multiple ragged rows** (current `/admin`
behaviour): overflow to a horizontal scroll strip with edge fades, or collapse to
a select below 640 px.

### Dialogs & sheets
- **≥768 px:** centred dialog, `--radius-lg`, `--shadow-3`, max-width 560 px
- **<768 px:** **bottom sheet**, full width, `--radius-lg` top corners, drag
  handle, max-height 88 vh, internal scroll
- Both: `role="dialog"`, `aria-modal="true"`, focus trapped **and released**,
  Esc closes, focus returns to the invoker. The existing drawer already does most
  of this — reuse it.

### Navigation
- **≥1024 px:** top bar + primary nav + context bar
- **768–1023 px:** top bar + context bar + drawer (nav collapses; **campus must
  stay visible**)
- **<768 px:** top bar (brand, search, avatar) + context row + drawer;
  a **bottom bar of ≤5 items** is the recommended addition
  (`ui-ux-pro-max` bottom-nav limit)
- Drawer keeps its current 64 px items and `role="dialog"` — and gains `inert` /
  `hidden` when closed (fixes C2), plus campus, search, account and sign-in.

### Page headers — three sanctioned patterns, and only three
1. **Hero** — dashboard only. Max **240 px** on mobile (currently 438 px = 52 % of
   the viewport). Carousel gets prev/next **with accessible names**, pause on
   hover/focus, and stops under reduced motion.
2. **Standard** — kicker + `<h1>` + one-line description + action slot. The
   default for every other page.
3. **Workspace** — standard + context chips (campus · ministry · role) + tabs.
   For ministry workspace, schedule editor, admin.

All three: exactly one `<h1>`, never duplicated by a breadcrumb (fixes `/admin`).

### Feedback states — required on every data surface
- **Loading:** skeleton matching final layout (reserve space; CLS < 0.1). No
  spinner-only screens. Currently: none anywhere.
- **Empty:** icon + one-line cause + **one primary action**. Never a bare grey
  sentence (`ui-ux-pro-max`: *Empty States — "Show helpful message and action",
  don't "Blank empty screens"*). Fixes H6, and the 900×780 px void on
  `/ministries`.
- **Signed-out:** explain + **"Sign in" button**, and hide actions that cannot
  succeed (the Shout Out composer).
- **Error:** what failed, what to do, retry action. Never a raw exception.
- **Unfinished:** if a feature is not wired, hide it behind a flag. **Never ship
  the engineering note as body copy** (fixes C8).

### Iconography
`portal_icon()`'s inline SVG set is the only sanctioned source; extend it to
cover body-copy needs. **Retire emoji as icons** — currently `→`×33, `✓`×18,
`⚡`×8, `★`×4, `🎉`×3, `👋`×2, `✕`×2, `🖨`, `📍`, `☰`. Decorative icons get
`aria-hidden="true"`; icon-only buttons get an `aria-label` (fixes M2's 16
nameless controls on `/admin`).

---

## 6. Responsive contract

**Mobile-first.** All new CSS uses `min-width` queries over a mobile base
(`ui-ux-pro-max`: *Mobile First — don't "Desktop default + max-width queries"*).

Four breakpoints replace the current sixteen:

| Token | Value | Tier |
|---|---|---|
| `--bp-sm` | `480px` | large phone |
| `--bp-md` | `768px` | tablet |
| `--bp-lg` | `1024px` | laptop |
| `--bp-xl` | `1280px` | desktop |

Container: `width: min(100% - 2 * gutter, 1180px)`. Retire 540/600/640/720/760/
800/820/840/860/880/900/920/1000/1080/1120 as they are touched.

## 7. Motion

Motion dial 2/10. Durations 150 ms (micro) / 200–250 ms (hover, disclosure) /
300 ms (sheet, drawer). Easing `ease-out` entering, faster exiting.

**Required, currently absent:**
```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: .01ms !important; animation-iteration-count: 1 !important;
    transition-duration: .01ms !important; scroll-behavior: auto !important;
  }
}
```
The hero carousel must not auto-advance under reduced motion.

## 8. Accessibility floor (non-negotiable)

1. Text contrast ≥ 4.5:1; UI/graphical ≥ 3:1 — every token above is measured
2. Visible focus on **every** interactive element; `--focus-ring-inverse` on dark
3. Focus never obscured by sticky chrome — use `scroll-padding-top`
   (`ui-ux-pro-max`: *Focus Not Obscured (Minimum), severity High*)
4. One `<h1>` per page; no skipped levels
5. `<main>` on every page + a skip link (currently 2 of 13 pages have `<main>`,
   0 have a skip link)
6. Visible labels on all inputs; inline errors + focusable error summary
7. 44 px minimum touch targets, 8 px apart
8. `prefers-reduced-motion` honoured
9. Closed off-canvas UI is `inert`/`hidden` and out of the tab order
10. Never colour alone to convey state

## 9. Anti-patterns for this product

Do not produce: a marketing landing page (hero + testimonials + conversion CTA);
a banking dashboard (dense KPI tiles, arbitrary colour-blocked stat cards — the
current teal/blue/gold "Portal Pulse" trio is drifting this way); a developer
admin console (monospace, dark chrome, raw IDs); an overdecorated brochure
(script/serif display faces, ornament, stock photography, scripture as
decoration rather than content).

Aim for: **calm, legible, operational warmth.** Generous type, restrained colour,
real content over ornament, and states that tell a volunteer what to do next.
