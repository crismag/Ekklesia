# Standalone Application Theme Support

`people_signup/` and `events_rsvp/` are separate applications with their own
bootstrap, session handling and database access. Until now they also had their
own hardcoded palette — a `:root` block duplicating the *forest* preset — so
changing the portal theme left both public-facing apps unchanged. Theme
switching is a shipped admin feature, and these two apps silently opted out.

## What was implemented

`shared/theme-tokens.php` — a single function, `theme_tokens_style_block()`,
emitting custom properties and nothing else.

**It does not couple the backends.** The active preset is stored in
`config/theme.json`, which is where `ThemeSettingsService` keeps it; the emitter
reads that file directly with `json_decode`. No autoloader, no service
container, no database, no session. It is loaded from each app's existing
`includes/helpers.php`, which every page already reaches, and emitted
immediately after the app's stylesheet at each of the 13 include sites — after,
because both stylesheets declare their own `:root`, and at equal specificity the
later declaration wins.

**It is not a shell.** No chrome, no navigation, no layout — palette only.

## Three findings that changed the implementation

Each of these would have shipped a regression to a public-facing page.

**1. Geometry must not be inherited.** The presets carry `--radius` alongside
the colours, and the standalone apps define `--radius:10px` against the presets'
`8px`. Emitting the preset wholesale moved every card, input and button on both
apps to 8px corners. The emitter now emits **colour-valued tokens only**; theme
inheritance here means palette, not layout.

**2. `--brand-dark` has no token equivalent.** The apps ship `#0e6a5e`, and no
preset token carries that value — `--deep` is `#123b31`. Bridging it would have
silently restyled button hover on a public sign-up form. It is deliberately left
unbridged.

**3. A fill token cannot be bridged without its foreground.** This was the
serious one. The apps hardcode `color:#fff` on `.btn{background:var(--brand)}`.
Mapping `--brand` to `--teal` alone would have produced:

| Preset | white on `--teal` |
|---|---|
| facebook | 4.23:1 — fail |
| earthy-serene | 3.93:1 — fail |
| vibrant-calm | **2.39:1 — fail** |

Three of nine presets would have put failing contrast on the primary button of
the public sign-up and RSVP forms. The portal already solves this with paired
tokens — `--on-<tok>` for a foreground on a fill, `--<tok>-ink` for the same hue
darkened until it is legible as text — so the bridge now exposes both, and the
stylesheets use `--brand` for fills and borders but `--brand-ink` for text.

After pairing, **all bridged pairs pass 4.5:1 on all nine presets** (worst case
4.51:1).

## Verification

- **Computed styles**, captured in a real browser across three pages
  (`/people_signup/`, `/people_signup/advanced.php`, `/events_rsvp/event.php`),
  are **byte-identical** before and after under the default preset. This holds
  by construction, not coincidence: in forest `--teal-ink` equals `--teal`,
  `--on-teal` equals `#ffffff`, and `--paper` equals `#fff`, so every paired
  token resolves to exactly the value the apps already shipped.
- Switching the active preset to *warm* changes card surfaces, button fills,
  body background and text colour on the standalone pages, confirming the
  mechanism works end to end. `config/theme.json` was restored afterwards and
  verified unmodified.
- Markup is unchanged apart from the injected `<style>` element.

## Known limitation — deferred

**The header band still does not follow the theme.** Both stylesheets hardcode
its gradient:

```css
background:linear-gradient(180deg,#0e6a5e 0,#117b6d 180px,var(--bg) 181px)
```

Under a non-default preset the page body, cards and buttons follow the theme
while this band stays forest green. That is a visible inconsistency, and it is
**not fixed here on purpose**: no token carries `#0e6a5e`, so any bridge changes
the default appearance of two public-facing pages. That is an owner decision
about brand appearance, not a safe refactor.

The same applies to a handful of hardcoded tints that remain forest-flavoured:
the focus ring `rgba(17,123,109,.18)`, the segmented-control inset shadow, and
`.brand{color:#eef4f0}`.

**Recommendation:** add a `--band-top` / `--band-bottom` token pair to all nine
presets, seeded with the current `#0e6a5e`/`#117b6d` in forest so the default is
preserved exactly, then point the gradient at them. That is a
`config/theme.json` change plus two CSS lines, and it would complete the work —
but it needs a decision on what those two colours should be in the other eight
presets, which is a design question rather than an engineering one.

Note also that today's active preset **is** forest, so production appearance is
entirely unaffected by this change. The limitation only becomes visible if an
administrator switches themes.
