<?php

declare(strict_types=1);

require_once __DIR__ . '/_portal-components.php';

if (!function_exists('portal_theme_style_block')) {
    /**
     * Emit a <style> block with the active theme's CSS variables and a couple
     * of derived rules (font-scale → html font-size; hero-gradient → body
     * background). Pages call this once at the top of <head>; calling it
     * twice is a no-op since portal_header() doesn't include this block.
     */
    function portal_theme_style_block(): string
    {
        try {
            $preset = \App\Providers\PortalServiceProvider::makeThemeSettingsService()->loadActivePreset();
        } catch (\Throwable) {
            return '';
        }
        $vars = $preset['vars'] ?? [];
        $rules = '';
        foreach ($vars as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;
            if (!preg_match('/^--[a-z-]+$/', $name)) continue;
            if (preg_match('/[<>{};]/', $value)) continue;
            $rules .= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ':' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . ';';
        }
        if ($rules === '') {
            return '';
        }
        // font-scale and gradient endpoints are derived helpers used by the
        // themed pages — emit them only when the theme actually defines them.
        $extras = '';
        if (isset($vars['--font-scale'])) {
            $extras .= 'html{font-size:calc(14px * var(--font-scale,1))}';
        }

        // --- Phase 0 foundation tokens -------------------------------------
        // Derived from preset-owned values so theme switching keeps working.
        // Presets vary --radius 4px..14px and --spacing 0.85..1.1; hardcoding
        // a ladder here would break them.
        $derived = ''
            . '--radius-sm:calc(var(--radius,8px) * .6);'
            . '--radius-lg:calc(var(--radius,8px) * 1.4);'
            . '--radius-full:999px;'
            . '--sp-1:calc(4px * var(--spacing,1));'
            . '--sp-2:calc(8px * var(--spacing,1));'
            . '--sp-3:calc(12px * var(--spacing,1));'
            . '--sp-4:calc(16px * var(--spacing,1));'
            . '--sp-5:calc(20px * var(--spacing,1));'
            . '--sp-6:calc(24px * var(--spacing,1));'
            . '--sp-8:calc(32px * var(--spacing,1));'
            . '--sp-10:calc(40px * var(--spacing,1));'
            . '--sp-12:calc(48px * var(--spacing,1));'
            . '--sp-16:calc(64px * var(--spacing,1));'
            // Focus tokens. The UA default ring measures 1.54:1 on --deep,
            // which is why an inverse ring is required on dark chrome.
            . '--focus-ring:var(--teal,#117b6d);'
            . '--focus-ring-inverse:#ffffff;'
            . '--focus-shadow:0 0 0 3px color-mix(in srgb, var(--teal,#117b6d) 30%, transparent);'
            // Content-width modes. The shell and workspace pages fill the
            // viewport; these tokens exist only for opt-in wide/readable surfaces.
            . '--container-readable:42rem;'
            . '--container-narrow:42rem;'
            . '--container-wide:1520px;'
            . '--container-default:none;'
            . '--container-admin:none;'
            . '--portal-gutter:clamp(1rem,2vw,2rem);'
            . '--portal-left-expanded:280px;'
            . '--portal-left-collapsed:44px;'
            . '--portal-right-expanded:320px;';

        // Canonical footer styling. Duplicated footer CSS was removed from page
        // views. It lives HERE, not in portal_footer(), because some pages
        // hand-roll <footer class="portal-footer"> and never call that function.
        $footer = '.portal-footer{display:flex;flex-direction:column;gap:var(--sp-3,12px);'
            . 'margin-top:var(--sp-6,24px);padding:var(--sp-4,16px) 0;'
            . 'color:var(--muted,#627169);font-size:12px;'
            . 'border-top:1px solid var(--line,#d9e4dd)}'
            . '@media (min-width:640px){.portal-footer{flex-direction:row;justify-content:space-between}}'
            . '.portal-footer.on-dark{color:rgba(248,255,251,.72);border-top-color:rgba(255,255,255,.18)}';

        // Skip link — none existed anywhere in the product (audit M1).
        // Visually hidden until focused, then pinned over the header.
        $skip = '.skip-link{position:absolute;left:-9999px;top:0;z-index:200;'
            . 'background:var(--paper,#fff);color:var(--teal,#117b6d);'
            . 'padding:12px 16px;min-height:44px;box-sizing:border-box;'
            . 'border-radius:var(--radius,8px);font-weight:700;'
            . 'text-decoration:none;box-shadow:0 4px 12px rgba(12,40,30,.18)}'
            . '.skip-link:focus{left:8px;top:8px}'
            // #portal-main now lives on each page's own <main>.
            . '#portal-main{scroll-margin-top:72px}'
            . '#portal-main:focus{outline:none}';

        // The shell emitted <a class="button"> for the auth action but defined NO
        // base .button — all 14 page views supplied it. Any page that did not
        // (e.g. a new one) rendered a raw unstyled link outside the bar. Same
        // "shell ships an unstyled component" defect as the Phase 0 footer.
        // Scoped to .topbar-actions so page-body .button rules are unaffected.
        $chromeBtn = '.topbar-actions .button{display:inline-flex;align-items:center;justify-content:center;'
            . 'gap:6px;min-height:36px;padding:0 14px;border-radius:var(--radius,8px);'
            . 'background:var(--paper,#fff);color:var(--deep,#123b31);border:1px solid transparent;'
            . 'font:inherit;font-size:13px;font-weight:700;line-height:1;text-decoration:none;cursor:pointer;'
            . 'white-space:nowrap}'
            . '.topbar-actions .button:hover{background:var(--soft,#eef4f0)}'
            . '.topbar-actions .button.secondary{background:rgba(255,255,255,.12);color:#f8fffb;'
            . 'border-color:rgba(255,255,255,.4)}'
            . '@media(max-width:820px){.topbar-actions .button{min-height:44px}}';

        // WCAG 2.4.7 — the product defined only TWO :focus rules; everything else
        // fell back to the UA default ring, which measures 1.54:1 against --deep
        // (i.e. invisible on the dark header). :focus-visible keeps mouse users
        // ring-free while guaranteeing a visible indicator for keyboard users.
        $focus = ':focus-visible{outline:3px solid var(--focus-ring,#117b6d);outline-offset:2px;border-radius:2px}'
            // Dark chrome needs the inverse ring (12.38:1 on --deep).
            . '.topbar :focus-visible,.side-drawer :focus-visible{'
            . 'outline-color:var(--focus-ring-inverse,#fff)}'
            // The campus select explicitly set outline:none with no replacement.
            . '.topbar-campus-select:focus-visible{outline:3px solid var(--focus-ring-inverse,#fff) !important;outline-offset:1px}'
            // The command-palette input and results set outline:none with no
            // replacement — the one remaining unpaired case in the product.
            . '.search-input:focus{outline:none;box-shadow:0 0 0 3px color-mix(in srgb, var(--teal,#117b6d) 30%, transparent)}'
            . '@supports not (color: color-mix(in srgb, red 50%, transparent)){'
            . '.search-input:focus{box-shadow:0 0 0 3px rgba(17,123,109,.3)}}'
            . '.search-result:focus-visible,.search-result.active{outline:2px solid var(--teal,#117b6d);outline-offset:-2px}'
            // Sticky header must not obscure the focused element (WCAG 2.4.11).
            . 'html{scroll-padding-top:72px}';

        // ---- Phase 2: shared page primitives -------------------------------
        // Emitted HERE (not from the render helpers) because ~half the page
        // views bypass shell helper functions; function-local CSS would not
        // reach them. Namespaced pc-* so it cannot collide with the page CSS
        // still in place (.button is redefined in 14 views, .card in 5).
        $components = ''
        // Buttons — 44px minimum, token-driven, focus handled by :focus-visible.
        . '.pc-btn{display:inline-flex;align-items:center;justify-content:center;gap:var(--sp-2,8px);'
        . 'min-height:44px;padding:0 var(--sp-4,16px);border-radius:var(--radius,8px);'
        . 'font:inherit;font-size:14px;font-weight:700;line-height:1;cursor:pointer;'
        . 'text-decoration:none;border:1px solid transparent;transition:background .18s,border-color .18s}'
        . '.pc-btn svg{width:18px;height:18px;flex:0 0 auto}'
        . '.pc-btn--primary{background:var(--teal,#117b6d);color:var(--on-teal,#fff)}'
        . '.pc-btn--primary:hover{background:var(--teal-ink,#0e6a5e)}'
        . '.pc-btn--secondary{background:var(--paper,#fff);color:var(--teal-ink,#117b6d);border-color:var(--line,#d9e4dd)}'
        . '.pc-btn--secondary:hover{background:var(--soft,#eef4f0)}'
        . '.pc-btn--ghost{background:transparent;color:var(--teal-ink,#117b6d)}'
        . '.pc-btn--ghost:hover{background:var(--soft,#eef4f0)}'
        . '.pc-btn--danger{background:var(--rose,#b84957);color:var(--on-rose,#fff)}'
        . '.pc-btn--inverse{background:var(--paper,#fff);color:var(--deep,#123b31);border-color:var(--line,#d9e4dd)}'
        . '.pc-btn--inverse:hover{background:var(--soft,#eef4f0)}'
        // Outline-on-dark: for dark bands. Keeps a visible border if the element
        // ends up over the light body (the audit's white-on-white hero CTAs).
        . '.pc-btn--onDark{background:rgba(255,255,255,.12);color:#f8fffb;border-color:rgba(255,255,255,.45)}'
        . '.pc-btn--onDark:hover{background:rgba(255,255,255,.22)}'
        . '.pc-btn--sm{min-height:36px;font-size:13px;padding:0 var(--sp-3,12px)}'
        . '.pc-btn--lg{min-height:52px;font-size:15px;padding:0 var(--sp-5,20px)}'
        . '.pc-btn[disabled]{opacity:.55;cursor:not-allowed}'
        . '@media(max-width:640px){.pc-btn--block-mobile{width:100%}}'
        // Page header — one h1, actions stack below the title on small screens.
        . '.pc-page-header{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;'
        . 'gap:var(--sp-3,12px);margin:0 0 var(--sp-5,20px)}'
        . '.pc-page-headings{min-width:0;flex:1 1 320px}'
        . '.pc-page-kicker{margin:0 0 4px;font-size:12px;font-weight:800;letter-spacing:.05em;'
        . 'text-transform:uppercase;color:var(--muted,#627169)}'
        . '.pc-page-title{margin:0;font-size:clamp(22px,3.2vw,28px);line-height:1.2;font-weight:700;'
        . 'color:var(--ink,#17211b);overflow-wrap:break-word}'
        . '.pc-page-desc{margin:6px 0 0;font-size:15px;line-height:1.5;color:var(--muted,#627169);max-width:68ch}'
        . '.pc-page-actions{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px)}'
        // On dark bands the header text must invert.
        . '.pc-page-header--onDark .pc-page-title{color:#f8fffb}'
        . '.pc-page-header--onDark .pc-page-desc,.pc-page-header--onDark .pc-page-kicker{color:rgba(248,255,251,.78)}'
        . '@media(max-width:640px){.pc-page-header{align-items:stretch}'
        . '.pc-page-actions{width:100%}.pc-page-actions>*{flex:1 1 auto}}'
        // Section header
        . '.pc-section-header{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;'
        . 'gap:var(--sp-2,8px);margin:0 0 var(--sp-3,12px)}'
        . '.pc-section-title{margin:0;font-size:18px;font-weight:650;line-height:1.25;color:var(--ink,#17211b)}'
        . '.pc-section-actions{display:flex;gap:var(--sp-2,8px);flex-wrap:wrap}'
        // Empty / signed-out / error / unavailable states
        . '.pc-empty{display:grid;justify-items:center;text-align:center;gap:var(--sp-2,8px);'
        . 'padding:var(--sp-8,32px) var(--sp-4,16px);border:1px dashed var(--line,#d9e4dd);'
        . 'border-radius:var(--radius,8px);background:var(--paper,#fff)}'
        . '.pc-empty-icon{display:grid;place-items:center;width:44px;height:44px;border-radius:var(--radius-full,999px);'
        . 'background:var(--soft,#eef4f0);color:var(--teal-ink,#117b6d)}'
        . '.pc-empty-icon svg{width:20px;height:20px}'
        . '.pc-empty-title{margin:0;font-size:16px;font-weight:700;color:var(--ink,#17211b)}'
        . '.pc-empty-body{margin:0;font-size:14px;line-height:1.5;color:var(--muted,#627169);max-width:52ch}'
        . '.pc-empty-action{margin-top:var(--sp-2,8px)}'
        . '.pc-unavailable{padding:var(--sp-4,16px);border:1px solid var(--line,#d9e4dd);'
        . 'border-radius:var(--radius,8px);background:var(--soft,#eef4f0)}'
        . '.pc-unavailable-title{margin:0;font-size:14px;font-weight:700;color:var(--ink,#17211b)}'
        . '.pc-unavailable-body{margin:4px 0 0;font-size:13px;line-height:1.5;color:var(--muted,#627169)}'
        . '.pc-error{padding:var(--sp-4,16px);border:1px solid var(--rose,#b84957);'
        . 'border-radius:var(--radius,8px);background:var(--paper,#fff)}'
        . '.pc-error-title{margin:0;font-size:15px;font-weight:700;color:var(--rose-ink,#b84957)}'
        . '.pc-error-body{margin:4px 0 0;font-size:14px;line-height:1.5;color:var(--muted,#627169)}'
        . '.pc-error-action{margin-top:var(--sp-3,12px)}'
        // Badges — tinted fill + dark text, never white on a light hue.
        . '.pc-badge{display:inline-flex;align-items:center;gap:4px;border-radius:var(--radius-sm,6px);'
        . 'padding:3px 8px;font-size:12px;font-weight:700;line-height:1.4;white-space:nowrap}'
        . '.pc-badge--neutral{background:var(--soft,#eef4f0);color:var(--ink,#17211b)}'
        . '.pc-badge--brand{background:var(--soft,#eef4f0);color:var(--teal-ink,#117b6d)}'
        . '.pc-badge--warn{background:var(--soft,#eef4f0);color:var(--gold-ink,#92651c)}'
        . '.pc-badge--danger{background:var(--soft,#eef4f0);color:var(--rose-ink,#b84957)}'
        // Skeleton — animation suppressed under reduced motion by the global rule.
        . '.pc-skeleton{display:grid;gap:var(--sp-2,8px)}'
        . '.pc-skeleton-row{display:block;height:14px;border-radius:var(--radius-sm,6px);'
        . 'background:linear-gradient(90deg,var(--soft,#eef4f0) 25%,var(--line,#d9e4dd) 37%,var(--soft,#eef4f0) 63%);'
        . 'background-size:400% 100%;animation:pc-shimmer 1.4s ease infinite}'
        . '.pc-skeleton-row:nth-child(2){width:88%}.pc-skeleton-row:nth-child(3){width:72%}'
        . '@keyframes pc-shimmer{0%{background-position:100% 50%}100%{background-position:0 50%}}'
        // Responsive record list — the <1024px replacement for wide tables.
        . '.pc-records{display:grid;gap:var(--sp-2,8px)}'
        . '.pc-record{display:grid;gap:6px;padding:var(--sp-3,12px);background:var(--paper,#fff);'
        . 'border:1px solid var(--line,#d9e4dd);border-radius:var(--radius,8px)}'
        . '.pc-record-main{display:flex;align-items:center;justify-content:space-between;gap:var(--sp-2,8px)}'
        . '.pc-record-title{font-size:15px;font-weight:700;color:var(--ink,#17211b);min-width:0;overflow-wrap:anywhere}'
        . '.pc-record-meta{display:flex;flex-wrap:wrap;gap:6px 12px;font-size:13px;color:var(--muted,#627169)}'
        . '.pc-record a{color:var(--teal-ink,#117b6d)}';

        // WCAG 2.3.3 — no prefers-reduced-motion support existed anywhere.
        $motion = '@media (prefers-reduced-motion: reduce){'
            . '*,*::before,*::after{'
            . 'animation-duration:.01ms !important;animation-iteration-count:1 !important;'
            . 'transition-duration:.01ms !important;scroll-behavior:auto !important}}';

        return "<style>:root{{$rules}{$derived}}{$extras}{$footer}{$skip}{$focus}{$chromeBtn}{$components}{$motion}</style>";
    }
}

if (!function_exists('portal_chrome')) {
    function portal_chrome(): array
    {
        try {
            return \App\Providers\PortalServiceProvider::makeChromeSettingsService()->load();
        } catch (\Throwable) {
            return [
                'header' => ['brandTitle' => 'Scheduler', 'brandSubtitle' => '', 'primaryNav' => []],
                'footer' => ['leftText' => 'Church Portal', 'rightText' => '', 'minimal' => true],
            ];
        }
    }
}

if (!function_exists('portal_nav_has_path')) {
    /**
     * Whether a nav list already contains this portal path (e.g. '/events').
     *
     * Used so overflow items are never a second copy of a primary tab.
     *
     * @param list<array{href?:string}> $items
     */
    function portal_nav_has_path(array $items, string $basePath, string $path): bool
    {
        $suffix = '/' . ltrim($path, '/');
        $want = rtrim($basePath, '/') . ($suffix === '/' ? '/' : $suffix);
        $wantNorm = rtrim($want, '/') ?: '/';
        foreach ($items as $item) {
            $got = rtrim((string) ($item['href'] ?? ''), '/') ?: '/';
            if ($got === $wantNorm) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('portal_icon')) {
    function portal_icon(string $name): string
    {
        $icons = [
            'search' => '<path d="M11 5a6 6 0 1 1-4.24 10.24L2.9 18.1 1.5 16.7l3.86-3.86A6 6 0 0 1 11 5Zm0 2a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/>',
            'profile' => '<path d="M11 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"/>',
            'menu' => '<path d="M3 6h18v2H3V6Zm0 5h18v2H3v-2Zm0 5h18v2H3v-2Z"/>',
            'dashboard' => '<path d="M4 4h7v7H4V4Zm9 0h7v4h-7V4ZM4 13h7v7H4v-7Zm9 6v-7h7v7h-7Z"/>',
            'ministry' => '<path d="M12 2 3 7v10l9 5 9-5V7l-9-5Zm0 2.3 6.9 3.8L12 12 5.1 8.1 12 4.3ZM5 9.8l6 3.3V21l-6-3.3V9.8Zm8 10.2v-8l6-3.3v8l-6 3.3Z"/>',
            'calendar' => '<path d="M7 2v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7Zm12 8H5v10h14V10ZM5 6h14v2H5V6Z"/>',
            'events' => '<path d="M12 2 2 7v10l10 5 10-5V7L12 2Zm0 2.2 7.7 3.8L12 12 4.3 8 12 4.2ZM4 9.5l8 4.1 8-4.1V17l-8 4-8-4V9.5Z"/>',
            'availability' => '<path d="M5 3h14v2H5V3Zm0 4h14v14H5V7Zm2 2v10h10V9H7Zm2 2h6v2H9v-2Zm0 4h6v2H9v-2Z"/>',
            'people' => '<path d="M9 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm6 1a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3 20v-1c0-2.76 2.91-5 6.5-5S16 16.24 16 19v1H3Zm14.5 0c-.07-1.84-.71-3.48-1.78-4.63A7.28 7.28 0 0 1 22 19v1h-4.5Z"/>',
            'availability-short' => '<path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 5h-2v6l5 3 .9-1.8-3.9-2.2V7Z"/>',
            'settings' => '<path d="M19.43 12.98c.04-.32.07-.65.07-.98s-.03-.66-.07-.98l2.11-1.65a.5.5 0 0 0 .12-.64l-2-3.46a.5.5 0 0 0-.61-.22l-2.49 1a7.66 7.66 0 0 0-1.69-.98l-.38-2.65A.5.5 0 0 0 14 2h-4a.5.5 0 0 0-.49.42l-.38 2.65a7.66 7.66 0 0 0-1.69.98l-2.49-1a.5.5 0 0 0-.61.22l-2 3.46a.5.5 0 0 0 .12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65a.5.5 0 0 0-.12.64l2 3.46a.5.5 0 0 0 .61.22l2.49-1a7.66 7.66 0 0 0 1.69.98l.38 2.65A.5.5 0 0 0 10 22h4a.5.5 0 0 0 .49-.42l.38-2.65a7.66 7.66 0 0 0 1.69-.98l2.49 1a.5.5 0 0 0 .61-.22l2-3.46a.5.5 0 0 0-.12-.64l-2.11-1.65ZM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7Z"/>',
            'admin'    => '<path d="M12 2 4 5v6c0 4.97 3.4 9.62 8 11 4.6-1.38 8-6.03 8-11V5l-8-3Zm0 5a3 3 0 1 1-2.95 3.55A3 3 0 0 1 12 7Zm0 11.2c-2.03 0-3.85-1.04-4.94-2.66a8.45 8.45 0 0 1 9.88 0c-1.09 1.62-2.91 2.66-4.94 2.66Z"/>',
            'docs'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm-1 1.5L18.5 9H14a1 1 0 0 1-1-1V3.5ZM8 13h8v2H8v-2Zm0 4h8v2H8v-2Zm0-8h5v2H8V9Z"/>',
        ];

        $path = $icons[$name] ?? $icons['dashboard'];
        return '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true">' . $path . '</svg>';
    }
}

if (!function_exists('portal_menu_items')) {
    /**
     * @param list<array{href:string,label:string,icon?:string}> $items
     */
    function portal_menu_items(string $basePath, array $items): string
    {
        $links = [];
        foreach ($items as $item) {
            $links[] = sprintf(
                '<a href="%s">%s%s</a>',
                htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'),
                isset($item['icon']) ? portal_icon((string) $item['icon']) . ' ' : '',
                htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'),
            );
        }

        return implode('', $links);
    }
}

if (!function_exists('portal_mobile_nav_menu_items')) {
    /**
     * @param list<array{label:string,href?:string,icon?:string,items?:list<array{label:string,href:string,icon?:string}>}> $groups
     */
    function portal_mobile_nav_menu_items(array $groups = []): string
    {
        $links = [];
        foreach ($groups as $group) {
            if (isset($group['href'])) {
                $links[] = sprintf(
                    '<a href="%s">%s%s</a>',
                    htmlspecialchars((string) $group['href'], ENT_QUOTES, 'UTF-8'),
                    isset($group['icon']) ? portal_icon((string) $group['icon']) . ' ' : '',
                    htmlspecialchars((string) $group['label'], ENT_QUOTES, 'UTF-8'),
                );
                continue;
            }

            foreach (($group['items'] ?? []) as $item) {
                $links[] = sprintf(
                    '<a href="%s">%s%s</a>',
                    htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8'),
                    isset($item['icon']) ? portal_icon((string) $item['icon']) . ' ' : '',
                    htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8'),
                );
            }
        }

        return implode('', $links);
    }
}

if (!function_exists('portal_shell_mods')) {
    /**
     * Declarative application-shell attributes.
     *
     * Pages describe the layout they need; they do not reimplement the chrome.
     *
     * Default is workspace: fill the main column. Wide/readable are opt-in.
     *
     * @param 'workspace'|'wide'|'readable' $layout
     * @param 'none'|'open'|'collapsed' $left
     * @param 'none'|'open'|'collapsed' $right
     */
    function portal_shell_mods(string $layout = 'workspace', string $left = 'none', string $right = 'none'): string
    {
        $layouts = ['workspace' => true, 'wide' => true, 'readable' => true];
        $panels = ['none' => true, 'open' => true, 'collapsed' => true];
        $layout = isset($layouts[$layout]) ? $layout : 'workspace';
        $left = isset($panels[$left]) ? $left : 'none';
        $right = isset($panels[$right]) ? $right : 'none';

        return 'data-layout="' . $layout . '" data-left="' . $left . '" data-right="' . $right . '"';
    }
}

if (!function_exists('portal_right_toggle')) {
    /** Collapse/expand control for an in-page right rail. */
    function portal_right_toggle(string $label = 'Details'): string
    {
        $esc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        return '<button type="button" class="portal-right-toggle" data-portal-right-toggle'
            . ' aria-expanded="true" aria-controls="portalRight" aria-label="Collapse ' . $esc . '">'
            . '<span class="portal-right-toggle-label">' . $esc . '</span>'
            . '<span class="portal-right-toggle-icon" aria-hidden="true">&#x203a;</span>'
            . '</button>';
    }
}

if (!function_exists('portal_left_toggle')) {
    /** Collapse/expand control for an in-page left rail. */
    function portal_left_toggle(string $label = 'Controls'): string
    {
        $esc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        return '<button type="button" class="portal-left-toggle" data-portal-left-toggle'
            . ' aria-expanded="true" aria-controls="portalLeft" aria-label="Collapse ' . $esc . '">'
            . '<span class="portal-left-toggle-icon" aria-hidden="true">&#x2039;</span>'
            . '<span class="portal-left-toggle-label">' . $esc . '</span>'
            . '</button>';
    }
}

if (!function_exists('portal_nav_bar')) {
    /**
     * @param list<array{label:string,href?:string,icon?:string,items?:list<array{label:string,href:string,icon?:string}>}> $groups
     */
    function portal_nav_bar(string $basePath, array $groups = []): string
    {
        static $styleEmitted = false;
        if ($groups === []) {
            // Flat top-level tabs — no nested dropdowns on desktop (all extras live in the drawer).
            $groups = [
                ['label' => 'Home',         'href' => $basePath . '/',             'icon' => 'dashboard'],
                ['label' => 'My Schedule',  'href' => $basePath . '/my-schedule',  'icon' => 'calendar'],
                ['label' => 'Ministries',   'href' => $basePath . '/ministries',   'icon' => 'ministry'],
                ['label' => 'Calendar',     'href' => $basePath . '/calendar',     'icon' => 'events'],
                ['label' => 'Events',       'href' => $basePath . '/events',       'icon' => 'availability-short'],
            ];
        }

        $style = '';
        if (!$styleEmitted) {
            $styleEmitted = true;
            $style = <<<'CSS'
<style>
/* ======================================================
   Portal global topbar — Facebook-style single bar
   ====================================================== */

/* Application shell — fluid viewport width. Content modes (workspace / wide /
   readable) constrain *surfaces*, not the chrome. */
.shell{
  width:100%;
  max-width:none;
  margin:0;
  padding:0 0 42px;
  min-width:0;
  box-sizing:border-box;
}
.shell[data-left="open"]{--portal-left-width:var(--portal-left-expanded,280px)}
.shell[data-left="collapsed"]{--portal-left-width:var(--portal-left-collapsed,44px)}
.shell[data-left="none"],.shell:not([data-left]){--portal-left-width:0px}
.shell[data-right="open"]{--portal-right-width:var(--portal-right-expanded,320px)}
.shell[data-right="collapsed"]{--portal-right-width:var(--portal-left-collapsed,44px)}
.shell[data-right="none"],.shell:not([data-right]){--portal-right-width:0px}

.portal-gutter,
.shell>#portal-main,
.shell>main,
.shell>.admin-layout,
.shell>.admin-titleblock,
.shell>.docs-grid,
.shell>.hero,
.shell>.pc-layout,
.shell>.portal-footer,
.shell>.portal-titleblock{
  padding-inline:var(--portal-gutter,clamp(1rem,2vw,2rem));
  box-sizing:border-box;
  min-width:0;
}
.admin-layout>#portal-main,
.docs-grid>#portal-main,
.portal-body>#portal-main,
.portal-body>.portal-main-slot{
  padding-inline:0;
}

/* A full-bleed band spans the block it belongs to, and nothing more.
   These bands were written as left/right:calc(-1 * gutter), which is right only
   while the block is *inset* by the gutter. Fluid pages span the shell and carry
   the gutter as padding instead, so the negative offsets reached 28px past the
   right edge — enough to make /people and /admin scroll sideways for no visible
   reason. Anchoring to the block's own edges is correct either way. */
.shell>.hero::before,
.shell>.admin-titleblock::before,
.shell>.portal-titleblock::before{left:0;right:0}

/* Default: the working surface fills the shell. Wide/readable opt in. */
.shell>#portal-main,
.shell>main,
.shell>.admin-layout,
.shell>.admin-titleblock,
.shell>.docs-grid,
.shell>.pc-layout{
  width:100%;
  max-width:none;
  margin-inline:0;
}
.shell[data-layout="wide"]>#portal-main,
.shell[data-layout="wide"]>main,
.shell[data-layout="wide"]>.docs-grid,
.shell[data-layout="wide"]>.pc-layout,
.shell[data-layout="wide"]>.admin-layout,
.shell[data-layout="wide"]>.admin-titleblock{
  width:min(100%,var(--container-wide,1520px));
  margin-inline:auto;
}
.shell[data-layout="readable"]>#portal-main,
.shell[data-layout="readable"]>main{
  width:min(100%,var(--container-readable,42rem));
  margin-inline:auto;
}
/* Stacked form fields stay readable on fluid pages; tables and grids still fill. */
.shell[data-layout="workspace"] .field>input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]),
.shell[data-layout="workspace"] .field>select,
.shell[data-layout="workspace"] .field>textarea{
  max-width:40rem;
}
.layout-workspace{width:100%;max-width:none;min-width:0}
.layout-wide{width:min(100%,var(--container-wide,1520px));margin-inline:auto}
.layout-readable{width:min(100%,var(--container-readable,42rem));margin-inline:auto}
/* Workspace descendants inherit the main column. Do not re-center them. */
.shell[data-layout="workspace"] .portal-body,
.shell[data-layout="workspace"] .portal-main-slot,
.shell[data-layout="workspace"] .calendar-frame,
.shell[data-layout="workspace"] .admin-content,
.shell[data-layout="workspace"] .ev-agenda,
.shell[data-layout="workspace"] .directory-table{
  width:100%;
  max-width:none;
  min-width:0;
}

/* Three-region workspace: LEFT | MAIN | RIGHT. Absent panels are omitted from
   the DOM (or [hidden]) so they consume zero tracks and zero gap. */
.portal-body{
  display:grid;
  grid-template-columns:minmax(0,1fr);
  column-gap:12px;
  align-items:start;
  min-width:0;
  width:100%;
}
.portal-body:has(>.portal-left:not([hidden])){
  grid-template-columns:var(--portal-left-width,280px) minmax(0,1fr);
}
.portal-body:has(>.portal-right:not([hidden])):not(:has(>.portal-left:not([hidden]))){
  grid-template-columns:minmax(0,1fr) var(--portal-right-width,320px);
}
.portal-body:has(>.portal-left:not([hidden])):has(>.portal-right:not([hidden])){
  grid-template-columns:var(--portal-left-width,280px) minmax(0,1fr) var(--portal-right-width,320px);
}
.portal-left,.portal-right,.portal-main-slot{min-width:0}
.portal-left[hidden],.portal-right[hidden]{display:none}
.portal-left{
  position:sticky;top:12px;
  background:var(--paper,#fff);
  border:1px solid var(--line,#d9e4dd);
  border-radius:var(--radius,8px);
  box-shadow:0 16px 42px rgba(27,50,40,.08);
  overflow:hidden;
}
.portal-left-head{
  display:flex;align-items:center;gap:8px;
  padding:8px;
  border-bottom:1px solid var(--line,#d9e4dd);
  background:var(--soft,#eef4f0);
}
.portal-left-body{padding:8px;display:grid;gap:10px}
.portal-left-toggle{
  display:inline-flex;align-items:center;justify-content:center;gap:6px;
  min-width:44px;min-height:44px;padding:0 10px;
  border:0;background:transparent;color:var(--ink,#17211b);
  font:inherit;font-size:13px;font-weight:700;cursor:pointer;border-radius:var(--radius-sm,6px);
}
.portal-left-toggle:hover{background:var(--paper,#fff)}
.portal-left-toggle-icon{display:inline-block;width:1em;text-align:center}
.shell[data-left="collapsed"] .portal-left-body,
.shell[data-left="collapsed"] .portal-left-toggle-label{display:none}
.shell[data-left="collapsed"] .portal-left-head{border-bottom:0;padding:4px;justify-content:center}
.shell[data-left="collapsed"] .portal-left-toggle-icon{transform:scaleX(-1)}
.shell[data-left="none"] .portal-left-toggle{display:none}

.portal-right{
  position:sticky;top:12px;
  background:var(--paper,#fff);
  border:1px solid var(--line,#d9e4dd);
  border-radius:var(--radius,8px);
  box-shadow:0 16px 42px rgba(27,50,40,.08);
  overflow:hidden;
  min-width:0;
}
.portal-right-head{
  display:flex;align-items:center;gap:8px;justify-content:flex-end;
  padding:8px;
  border-bottom:1px solid var(--line,#d9e4dd);
  background:var(--soft,#eef4f0);
}
.portal-right-body{padding:0;min-width:0}
.portal-right-toggle{
  display:inline-flex;align-items:center;justify-content:center;gap:6px;
  min-width:44px;min-height:44px;padding:0 10px;
  border:0;background:transparent;color:var(--ink,#17211b);
  font:inherit;font-size:13px;font-weight:700;cursor:pointer;border-radius:var(--radius-sm,6px);
}
.portal-right-toggle:hover{background:var(--paper,#fff)}
.portal-right-toggle-icon{display:inline-block;width:1em;text-align:center}
.shell[data-right="collapsed"] .portal-right-body,
.shell[data-right="collapsed"] .portal-right-toggle-label{display:none}
.shell[data-right="collapsed"] .portal-right-head{border-bottom:0;padding:4px;justify-content:center}
.shell[data-right="collapsed"] .portal-right-toggle-icon{transform:scaleX(-1)}
.shell[data-right="none"] .portal-right-toggle{display:none}

@media(max-width:760px){
  .shell{width:100%;padding-top:0}
  .portal-body:has(>.portal-left:not([hidden])),
  .portal-body:has(>.portal-right:not([hidden])),
  .portal-body:has(>.portal-left:not([hidden])):has(>.portal-right:not([hidden])){
    grid-template-columns:minmax(0,1fr);
  }
  .portal-left{position:static}
  .portal-left-toggle,.portal-right-toggle{display:none}
  .portal-right{position:static}
  .portal-main-slot{order:-1}
}

/* Screen-shell changes must not leak into paper. Printables keep their own
   @page sizes; this only stops the fluid chrome from becoming the print box. */
@media print{
  .portal-left,.portal-right,.portal-left-toggle,.portal-right-toggle,.topbar,.side-drawer,.skip-link,.search-overlay{display:none !important}
  .shell,.shell>#portal-main,.shell>main,.portal-body,.portal-main-slot{
    width:auto;max-width:none;margin:0;padding:0;
    grid-template-columns:minmax(0,1fr);
  }
}

/* Topbar: 3-column grid [brand | nav | actions] */
.topbar{
  display:grid;
  grid-template-columns:auto 1fr auto;
  align-items:stretch;
  height:56px;
  background:linear-gradient(135deg,var(--gradient-top,#0e3528) 0%,var(--gradient-mid,#1c6642) 100%);
  color:#f8fffb;
  margin-bottom:18px;
  position:relative;
  z-index:30;
  box-shadow:0 2px 12px rgba(9,25,20,.28);
}

/* Brand cell */
.topbar-brand{
  display:flex;
  align-items:center;
  gap:10px;
  padding:0 14px;
  flex-shrink:0;
  min-width:0;
}
.portal-brand{
  flex:0 0 auto;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:36px;height:36px;
  border-radius:10px;
  background:rgba(255,255,255,.15);
  border:1px solid rgba(255,255,255,.28);
  text-decoration:none;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.14);
  transition:background .12s;
}
.portal-brand:hover{background:rgba(255,255,255,.24)}
.portal-brand-mark{position:relative;display:block;width:24px;height:24px;border-radius:6px;background:#fff center/cover no-repeat box-shadow:0 0 0 1px rgba(255,255,255,.18)}
.portal-brand-text{
  color:#f8fffb;
  font-weight:900;
  font-size:15px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width:180px;
  letter-spacing:-.01em;
}
@media(max-width:640px){.portal-brand-text{display:none}}

/* Center nav cell */
.portal-nav{
  display:flex;
  align-items:stretch;
  justify-content:center;
  /* Phase 1: was overflow:hidden, which silently CLIPPED tabs from 830px down
     (audit: /my-schedule lost DASHBOARD and DOCS at 900px). Never hide a
     destination — scroll it instead, with a fade hinting there is more. */
  overflow-x:auto;
  overflow-y:hidden;
  scrollbar-width:none;
  -ms-overflow-style:none;
  scroll-snap-type:x proximity;
  margin:0;
  padding:0;
}
.portal-nav::-webkit-scrollbar{display:none}
.portal-nav .nav-tab{scroll-snap-align:start;flex:0 0 auto}
/* Between the drawer breakpoint and 1023px the full tab row must still FIT —
   scrolling is the safety net, not the design. Tighten spacing so all five
   primary tabs are visible without interaction. */
/* NOTE: these use .portal-nav .nav-tab (not bare .nav-tab) because the base
   .nav-tab rule is declared later in this same stylesheet — a media query adds
   no specificity, so a bare selector here would lose on source order. */
/* 12px labels need a little more room than the old 10px ones; tighten padding
   from 1200px down so the full row still fits without scrolling. */
@media(max-width:1200px){
  .portal-nav .nav-tab{padding:0 12px}
}
@media(max-width:1023px){
  .portal-nav{justify-content:flex-start}
  .portal-nav .nav-tab{padding:0 10px;letter-spacing:.02em}
  /* The standalone admin icon now duplicates "Settings & Admin" in the More
     menu; dropping it below 1024 returns 36px to the tab row.
     Needs .topbar-actions specificity: a later .icon-btn rule sets display:grid. */
  .topbar-actions .settings-btn,.topbar-actions .admin-inert{display:none!important}
  /* the campus control is the widest item in the actions column; cap it so the
     primary tabs keep their room */
  .topbar-campus .topbar-campus-select{max-width:104px!important;min-width:0!important}
}
@media(max-width:900px){
  .portal-nav .nav-tab{padding:0 6px;font-size:12px}
  .topbar-campus .topbar-campus-select{max-width:78px!important}
}
/* 821-860px is the tightest band: the drawer has not taken over yet and the
   full tab row plus actions must still fit. */
@media(max-width:860px){
  .portal-nav .nav-tab{padding:0 4px;font-size:12px}
  .topbar-campus .topbar-campus-select{max-width:52px!important}
  .topbar-actions .button{max-width:84px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
}
/* If a future nav ever exceeds the row, fade the edge so the scroll is legible
   rather than clipping a destination out of existence. */
.portal-nav.is-scrollable{
  -webkit-mask-image:linear-gradient(to right,#000 0,#000 calc(100% - 24px),transparent 100%);
  mask-image:linear-gradient(to right,#000 0,#000 calc(100% - 24px),transparent 100%);
}

/* Search + overflow ("More") controls */
.search-btn{display:grid!important}
.more-wrap{position:relative;display:grid;place-items:center}
.more-menu{position:absolute;right:0;top:calc(100% + 6px);min-width:220px;z-index:70;
  background:var(--paper,#fff);border:1px solid var(--line,#d9e4dd);
  border-radius:var(--radius,8px);box-shadow:0 4px 12px rgba(12,40,30,.18);
  padding:6px;display:grid;gap:2px}
.more-menu[hidden]{display:none}
.more-item{display:flex;align-items:center;gap:10px;min-height:44px;padding:8px 10px;
  border-radius:var(--radius-sm,6px);color:var(--ink,#17211b);text-decoration:none;
  font-size:14px;font-weight:600}
.more-item:hover,.more-item:focus-visible{background:var(--soft,#eef4f0)}
.more-item svg{width:18px;height:18px;color:var(--teal,#117b6d);flex:0 0 auto}

/* Each tab */
.nav-tab{
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  gap:3px;
  padding:0 18px;
  height:100%;
  text-decoration:none;
  color:rgba(248,255,251,.62);
  font-size:12px;
  font-weight:700;
  letter-spacing:.02em;
  text-transform:uppercase;
  border-bottom:3px solid transparent;
  border-top:3px solid transparent; /* balance so text sits dead-center */
  white-space:nowrap;
  cursor:pointer;
  transition:color .12s,background .12s,border-color .12s;
  position:relative;
  min-width:64px;
}
.nav-tab svg{width:22px;height:22px;flex-shrink:0;margin-bottom:1px}
.nav-tab:hover{color:#f8fffb;background:rgba(255,255,255,.09)}
.nav-tab.is-active{color:#f8fffb;border-bottom-color:var(--gold,#4ade80);background:rgba(255,255,255,.07)}

/* Groups with sub-items: flyout on hover via visibility (no layout shift) */
.nav-group{position:relative;display:flex;align-items:stretch}
.nav-flyout{
  visibility:hidden;
  opacity:0;
  pointer-events:none;
  position:absolute;
  top:calc(100% + 6px);
  left:50%;
  transform:translateX(-50%);
  min-width:220px;
  background:#fff;
  border-radius:10px;
  border:1px solid var(--line,#dde8e2);
  box-shadow:0 12px 40px rgba(9,25,20,.22);
  padding:8px;
  z-index:40;
  transition:opacity .14s,visibility .14s;
}
.nav-group:hover .nav-flyout,.nav-group:focus-within .nav-flyout{
  visibility:visible;
  opacity:1;
  pointer-events:auto;
}
.nav-flyout a{
  display:flex;
  align-items:center;
  gap:9px;
  padding:9px 11px;
  border-radius:7px;
  color:var(--ink,#1a2e26);
  text-decoration:none;
  font-size:13px;
  font-weight:700;
  transition:background .1s;
}
.nav-flyout a:hover,.nav-flyout a.is-active{background:var(--soft,#f0f7f3)}
.nav-flyout a svg{width:17px;height:17px;color:var(--accent,#1a6b4a)}
/* Small arrow under the active tab when flyout contains the active link */
.nav-tab.has-active{color:#f8fffb;border-bottom-color:var(--gold,#4ade80);background:rgba(255,255,255,.07)}

/* Actions cell (right) */
.topbar-actions{
  display:flex;
  align-items:center;
  gap:6px;
  padding:0 12px;
  flex-shrink:0;
}
.icon-btn{
  width:36px;height:36px;
  display:grid;
  place-items:center;
  border:1px solid rgba(255,255,255,.22);
  border-radius:50%;
  background:rgba(255,255,255,.1);
  color:#f8fffb;
  text-decoration:none;
  font-weight:900;
  cursor:pointer;
  transition:background .12s;
}
.icon-btn:hover{background:rgba(255,255,255,.22)}
.icon-btn.admin-inert{opacity:.5;cursor:default}
.icon-btn.admin-inert:hover{background:transparent}
.icon-btn svg{width:18px;height:18px}
.topbar-actions .button,.topbar-actions button.button{
  min-height:36px;
  padding:0 14px;
  font-size:13px;
  border-radius:8px;
  box-shadow:none;
}
/* Touch sizing must come AFTER this rule: an earlier @media(max-width:820px)
   block in the foundation CSS sets 44px but loses on source order to the rule
   above (identical specificity). */
@media(max-width:820px){
  .topbar-actions .button,.topbar-actions button.button{min-height:44px}
  /* Only enlarge the brand where the nav has already handed over to the drawer;
     at 821-860 the tab row is tightest and needs the space. */
  .portal-brand{width:44px;height:44px}
}
.topbar-actions .button.secondary{
  background:rgba(255,255,255,.12);
  border:1px solid rgba(255,255,255,.22);
  color:#f8fffb;
}
/* Inline campus selector — !important rules prevent page-level select CSS from overriding */
.topbar-campus{
  display:inline-flex;
  align-items:center;
  gap:6px;
  background:rgba(255,255,255,.1);
  border:1px solid rgba(255,255,255,.24);
  border-radius:8px;
  padding:0 8px;
  color:#f8fffb;
  height:36px;
  flex-shrink:0;
}
.topbar-campus svg{width:14px;height:14px;flex-shrink:0;opacity:.8}
.topbar-campus-select{
  border:0 !important;
  background:transparent !important;
  color:#f8fffb !important;
  font-size:12px !important;
  font-weight:700 !important;
  cursor:pointer;
  outline:none;
  min-width:100px;
  max-width:160px;
  height:36px !important;
  padding:0 4px !important;
  box-shadow:none !important;
  border-radius:0 !important;
  width:auto !important;
}
.topbar-campus-select option{background:var(--gradient-mid,#123b31);color:#f8fffb}
/* hamburger — hidden on desktop */
.ham-btn{display:none!important}
@media(max-width:820px){
  .portal-nav{display:none}
  /* Touch targets: the icon buttons are 36px on desktop (pointer-precise) but
     must reach the 44px minimum on touch. */
  .topbar-actions .icon-btn{width:44px;height:44px}
  /* Campus stays reachable: the topbar select is replaced by the context bar
     below the header, NOT hidden (audit C5 / INV-3 — campus filters data via
     the portal_campus_id cookie whether or not the control is visible). */
  .topbar-campus,.topbar-actions .button.secondary,.settings-btn,.more-wrap{display:none}
  /* ...except the user menu, which is now the only route to sign out.
     Hiding it here would take personal functions and sign-out away from
     every phone. */
  .topbar-actions .user-menu,.topbar-actions .user-menu .button.secondary{display:inline-flex}
  .topbar-actions .button{padding:0 10px;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .ham-btn{display:grid!important}
}
@media(max-width:820px) and (orientation:landscape){
  .topbar-actions .button{min-width:36px;padding:0 8px}
  .topbar-actions .button .welcome-label{display:none}
}

/* Signed-in user menu.
   The header previously held a bare link to the account page and the product
   had no sign-out control in its chrome at all. This is where personal
   functions live now, so it has to work on a phone as well as a desktop. */
.user-menu{position:relative;display:inline-flex}
.user-menu-btn{display:inline-flex;align-items:center;gap:6px}
.user-menu-caret{flex:0 0 auto;opacity:.7}
.user-menu-panel{position:absolute;top:calc(100% + 6px);right:0;z-index:60;
  min-width:210px;padding:6px;display:flex;flex-direction:column;
  background:var(--surface,#fff);border:1px solid var(--line,#d9e4dd);
  border-radius:var(--radius,8px);box-shadow:0 10px 28px rgba(0,0,0,.16)}
.user-menu-panel[hidden]{display:none}
.user-menu-item{display:block;width:100%;text-align:left;padding:10px 12px;
  border:0;background:none;border-radius:6px;font:inherit;color:var(--ink,#20302a);
  text-decoration:none;cursor:pointer;
  /* 44px target: these are the first controls a phone user reaches for. */
  min-height:44px;line-height:24px}
.user-menu-item:hover,.user-menu-item:focus-visible{background:var(--soft,#eef4f0)}
.user-menu-sep{margin-top:6px;border-top:1px solid var(--line,#d9e4dd);
  padding-top:10px;border-radius:0 0 6px 6px}
.user-menu-signout{margin:0}
.user-menu-signout button{color:var(--ink,#20302a)}

/* Persistent context bar — campus/role. Shown below 820px, where the topbar
   campus select is hidden. Above 820px the topbar select is the control and
   this bar would be duplicate chrome, so it is suppressed. */
.context-bar{display:none}
@media(max-width:820px){
  .context-bar{display:flex;flex-wrap:wrap;align-items:center;gap:var(--sp-2,8px);
    margin:-10px 0 14px;padding:8px 12px;background:var(--soft,#eef4f0);
    border:1px solid var(--line,#d9e4dd);border-radius:var(--radius,8px)}
  .ctx-chip{display:inline-flex;align-items:center;gap:6px;min-height:44px}
  .ctx-label{font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;
    color:var(--muted,#627169)}
  .ctx-select{min-height:44px;font:inherit;font-size:14px;font-weight:700;
    color:var(--ink,#17211b);background:var(--paper,#fff);
    border:1px solid var(--line,#d9e4dd);border-radius:var(--radius-sm,6px);padding:0 8px}
  .ctx-value{font-size:14px;font-weight:700;color:var(--ink,#17211b)}
}

/* Title block below topbar */
.portal-titleblock{display:grid;gap:6px;margin:0 0 22px;color:#f8fffb;max-width:1100px}
.portal-titleblock h1{margin:0;font-size:clamp(30px,4vw,46px);line-height:1.02;max-width:880px;overflow-wrap:break-word;word-break:break-word}
.portal-titleblock p{margin:0;color:rgba(248,255,251,.78);font-size:13px;max-width:720px;overflow-wrap:break-word;word-break:break-word;white-space:normal}

/* ======================================================
   Side drawer
   ====================================================== */
.drawer-backdrop{display:none;position:fixed;inset:0;background:rgba(9,25,20,.52);z-index:49;-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px)}
.drawer-backdrop.open{display:block}
.side-drawer{position:fixed;top:0;right:0;width:min(320px,100vw);height:100%;height:100dvh;background:#fff;z-index:50;display:flex;flex-direction:column;transform:translateX(110%);transition:transform .28s cubic-bezier(.25,.8,.25,1);box-shadow:-6px 0 28px rgba(9,25,20,.22);overflow:hidden}
.side-drawer.open{transform:translateX(0)}
.drawer-head{display:flex;align-items:center;justify-content:space-between;padding:16px 12px 14px;background:linear-gradient(135deg,var(--gradient-top,#0e3528) 0%,var(--gradient-mid,#1a6b4a) 100%);color:#f8fffb;flex-shrink:0;min-height:72px}
.drawer-user{display:flex;align-items:center;gap:10px;flex:1;min-width:0}
.drawer-avatar{width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.38);display:grid;place-items:center;font-weight:900;font-size:18px;flex-shrink:0}
.drawer-uname{font-weight:700;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.drawer-close{width:44px;height:44px;display:grid;place-items:center;border:0;background:rgba(255,255,255,.14);border-radius:50%;color:#fff;cursor:pointer;font-size:22px;flex-shrink:0;transition:background .12s}
.drawer-close:hover{background:rgba(255,255,255,.26)}
.drawer-body{flex:1;overflow-y:auto;padding:4px 0 32px;-webkit-overflow-scrolling:touch}
.drawer-section-title{padding:14px 16px 4px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#6b8077}
.drawer-divider{border:none;border-top:1px solid var(--line,#dde8e2);margin:6px 0}
.drawer-item{display:flex;align-items:center;gap:14px;min-height:52px;padding:10px 16px;color:var(--ink,#1a2e26);text-decoration:none;font-size:15px;font-weight:600;transition:background .12s}
.drawer-item:active,.drawer-item:hover{background:var(--soft,#f0f7f3)}
.drawer-item-icon{width:44px;height:44px;display:grid;place-items:center;border-radius:12px;background:var(--soft,#f0f7f3);flex-shrink:0;color:var(--accent,#1a6b4a)}
.drawer-item-icon svg{width:22px;height:22px}
.drawer-item-label{flex:1;line-height:1.2}

/* ======================================================
   Search overlay
   ====================================================== */
.search-backdrop{display:none;position:fixed;inset:0;background:rgba(9,25,20,.62);z-index:59;-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px)}.search-backdrop.open{display:block}
.search-overlay[hidden]{display:none}.search-overlay{position:fixed;inset:0;z-index:60;display:grid;place-items:start center;padding:72px 16px 40px;overflow:auto;pointer-events:none}.search-overlay:not([hidden]){pointer-events:auto}
.search-card{width:min(640px,100%);background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(9,25,20,.3);overflow:hidden;display:grid;animation:srchIn .18s cubic-bezier(.25,.8,.25,1)}@keyframes srchIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:none}}
.search-input-row{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--line,#d9e4dd)}.search-icon-inline{color:var(--muted,#66756d);flex-shrink:0;display:flex}.search-icon-inline svg{width:20px;height:20px}.search-input{flex:1;border:0;outline:none;font:500 17px/1.4 inherit;color:var(--ink,#17211b);background:transparent}.search-input::placeholder{color:var(--muted,#66756d);font-weight:400}
.search-close{width:32px;height:32px;border:0;border-radius:50%;background:var(--soft,#eef4f0);color:var(--muted,#66756d);cursor:pointer;display:grid;place-items:center;font-size:16px;flex-shrink:0;transition:background .12s}.search-close:hover{background:var(--line,#d9e4dd)}
.search-results{max-height:min(460px,58vh);overflow-y:auto;display:grid}.search-hint{padding:20px 18px;color:var(--muted,#66756d);font-size:14px}.search-group{display:grid}.search-group-label{padding:10px 18px 4px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--muted,#66756d)}
.search-result{display:flex;align-items:center;gap:12px;padding:10px 18px;text-decoration:none;color:var(--ink,#17211b);transition:background .1s;outline:none}.search-result:hover,.search-result.active{background:var(--soft,#eef4f0)}
.search-result-icon{width:36px;height:36px;border-radius:10px;background:var(--soft,#eef4f0);display:grid;place-items:center;flex-shrink:0;color:var(--teal,#117b6d)}.search-result-icon svg{width:18px;height:18px}
.search-result-text{display:grid;gap:1px;min-width:0}.search-result-title{font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.search-result-sub{font-size:12px;color:var(--muted,#66756d);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.search-footer{display:flex;align-items:center;gap:14px;padding:9px 16px;border-top:1px solid var(--line,#d9e4dd);background:var(--soft,#eef4f0);font-size:12px;color:var(--muted,#66756d);flex-wrap:wrap}
.srch-kbd{display:inline-flex;padding:1px 5px;border:1px solid var(--line,#d9e4dd);border-radius:4px;background:#fff;font-family:monospace;font-size:12px;color:var(--ink,#17211b);gap:2px}

/* ======================================================
   Portal notice toast
   ====================================================== */
.portal-notice{position:fixed;top:18px;right:18px;z-index:80;width:min(380px,calc(100vw - 32px));background:#fffaf2;border:1px solid #ecdcc8;border-radius:10px;box-shadow:0 22px 56px rgba(9,25,20,.24);overflow:hidden;animation:noticeIn .18s ease-out}
.portal-notice-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;background:#fff4e6;border-bottom:1px solid #ecdcc8}
.portal-notice-title{font-weight:950;color:#7b2445}
.portal-notice-close{width:30px;height:30px;border:1px solid #dfcbb3;border-radius:7px;background:#fff;color:var(--deep,#123b31);cursor:pointer;font-weight:950}
.portal-notice-body{padding:12px 14px;color:#765f4b;font-weight:800;font-size:13px;line-height:1.45}
@keyframes noticeIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
</style>
CSS;
        }

        $links = [];
        foreach ($groups as $group) {
            if (isset($group['href'])) {
                // Direct top-level tab — single link, no flyout
                $links[] = sprintf(
                    '<a class="nav-tab" href="%s">%s<span>%s</span></a>',
                    htmlspecialchars((string) $group['href'], ENT_QUOTES, 'UTF-8'),
                    isset($group['icon']) ? portal_icon((string) $group['icon']) : '',
                    htmlspecialchars((string) $group['label'], ENT_QUOTES, 'UTF-8'),
                );
                continue;
            }

            // Group with sub-items: tab acts as label; flyout appears on hover.
            // Use visibility/opacity so no layout shift occurs (no flicker).
            $flyoutItems = '';
            // Use the first item's href as the tab's own href so it's still clickable.
            $firstHref = '';
            foreach (($group['items'] ?? []) as $item) {
                if ($firstHref === '') {
                    $firstHref = htmlspecialchars((string) ($item['href'] ?? ''), ENT_QUOTES, 'UTF-8');
                }
                $flyoutItems .= sprintf(
                    '<a href="%s">%s<span>%s</span></a>',
                    htmlspecialchars((string) ($item['href'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    isset($item['icon']) ? portal_icon((string) $item['icon']) : '',
                    htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8'),
                );
            }

            $links[] = sprintf(
                '<div class="nav-group"><a class="nav-tab" href="%s">%s<span>%s</span></a><div class="nav-flyout">%s</div></div>',
                $firstHref,
                isset($group['icon']) ? portal_icon((string) $group['icon']) : '',
                htmlspecialchars((string) $group['label'], ENT_QUOTES, 'UTF-8'),
                $flyoutItems,
            );
        }

        return $style . '<nav class="portal-nav" aria-label="Primary">' . implode('', $links) . '</nav>';
    }
}

if (!function_exists('portal_header')) {
    /**
     * @param list<array{id:int,name:string,selected?:bool}> $campuses
     * @param list<array{label:string,href?:string,icon?:string,items?:list<array{label:string,href:string,icon?:string}>}> $navGroups
     * @param list<array{href:string,label:string,icon?:string}> $menuItems
     * @param list<array{href:string,label:string,icon?:string,class?:string,id?:string}> $actionItems
     */
    function portal_header(
        string $basePath,
        string $title,
        string $subtitle,
        array $campuses = [],
        ?int $selectedCampusId = null,
        ?array $actor = null,
        array $navGroups = [],
        array $menuItems = [],
        array $actionItems = [],
        string $loginLabel = 'Sign in',
        string $loginHref = '',
        bool $includeAllCampusesOption = false,
    ): string {
        // Pull live header overrides (brand text, primary nav) from chrome.json.
        // Pages may pass a non-empty $navGroups and we'll respect that, but if
        // they leave it empty (most calls do), we fall back to the configured
        // nav so admins can edit it via /admin/header without code changes.
        $chrome = portal_chrome();

        // ---- Phase 1: single navigation source -------------------------------
        // Before Phase 1, 15 views each passed a literal $navGroups array, which
        // produced FIVE different primary navigations across the portal (audit
        // C1) — on /people the "People" tab was missing entirely. The passed
        // array is now deliberately IGNORED: config/chrome.json is the sole
        // source of the primary nav, so it stays identical on every route and
        // remains editable from /admin/header.
        //
        // Nothing is lost. Destinations that used to appear ad-hoc in some page
        // navs (Availability, Printables, My Schedule, Account, Admin) are
        // reachable from the overflow menu on desktop and the drawer on mobile,
        // and are role-gated exactly as before.
        $legacyNavGroups = $navGroups;   // retained for debugging/BC only
        $navGroups = [];
        if (!empty($chrome['header']['primaryNav']) && is_array($chrome['header']['primaryNav'])) {
            $navGroups = array_map(static function (array $item) use ($basePath): array {
                return [
                    'href'  => $basePath . (string) ($item['href'] ?? '/'),
                    'label' => (string) ($item['label'] ?? ''),
                    'icon'  => (string) ($item['icon']  ?? 'dashboard'),
                ];
            }, $chrome['header']['primaryNav']);
        }

        // Secondary destinations: never shown as primary tabs, always reachable
        // from the overflow menu (desktop) and the drawer (mobile). Events,
        // Docs and Printables are omitted here when they already occupy a
        // primary tab, so chrome.json cannot list the same door twice.
        $isSignedIn = $actor !== null;
        $secondaryNav = [];
        if ($isSignedIn) {
            $secondaryNav[] = ['href' => $basePath . '/my-schedule',  'label' => 'My Schedule',  'icon' => 'calendar'];
            $secondaryNav[] = ['href' => $basePath . '/availability', 'label' => 'Availability', 'icon' => 'availability'];
            // Same destination as the Ministries tab: the overflow name is the
            // job ("Serving"), not a second product.
            $secondaryNav[] = ['href' => $basePath . '/ministries', 'label' => 'Serving', 'icon' => 'ministry'];
        }
        if (!portal_nav_has_path($navGroups, $basePath, '/events')) {
            $secondaryNav[] = ['href' => $basePath . '/events', 'label' => 'Events', 'icon' => 'events'];
        }
        if (!portal_nav_has_path($navGroups, $basePath, '/printables')) {
            $secondaryNav[] = ['href' => $basePath . '/printables', 'label' => 'Printables', 'icon' => 'docs'];
        }
        if ($isSignedIn) {
            $secondaryNav[] = ['href' => $basePath . '/account', 'label' => 'Account', 'icon' => 'profile'];
        }
        // Docs belong in overflow unless an administrator pinned them in
        // primaryNav via /admin/header. Do not append them to the tab row.
        if (!portal_nav_has_path($navGroups, $basePath, '/docs')) {
            $secondaryNav[] = ['href' => $basePath . '/docs', 'label' => 'Docs', 'icon' => 'docs'];
        }
        // Auto-load campuses from the service layer when the caller passes none.
        // This ensures every page gets a campus selector without per-page wiring.
        if ($campuses === []) {
            try {
                $autoLoaded = \App\Providers\PortalServiceProvider::makeMinistryService()->getCampusSelector(
                    new \App\Core\ActorContext(
                        actorId: 0,
                        personId: null,
                        displayName: 'Public',
                        permissions: [\App\Core\PortalPermission::ViewMinistrySchedule],
                        ministryScopeIds: [],
                        isPortalWideAdmin: true,
                        campusScopeIds: [],
                    )
                );
                $campuses = $autoLoaded['campuses'] ?? [];
            } catch (\Throwable) {
                $campuses = [];
            }
        }

        // Priority order for selected campus:
        //   1. Cookie portal_campus_id — user's explicit persistent choice (JS sets it on change).
        //   2. Explicitly passed $selectedCampusId (caller already resolved it, e.g. from route).
        //   3. Logged-on actor's primary campus ($actor['currentCampusId']).
        //   4. null → "All campuses" option is pre-selected.
        // Campus resolution, in priority order:
        //   1. A cookie naming a campus this user can actually see — their own
        //      explicit, persistent choice. It outranks whatever the page
        //      passed in; it used to be consulted only when the caller passed
        //      null, so every page supplying a default (the dashboard, docs,
        //      and the whole admin shell) ignored the selector entirely.
        //   2. A present-but-empty cookie, which means "All campuses". The
        //      control stores the empty option verbatim, and reading it through
        //      FILTER_VALIDATE_INT turned that into false and fell through to a
        //      default — so choosing All silently snapped back to one campus.
        //   3. Whatever the page passed in.
        //   4. The signed-in actor's own campus.
        //   5. null, meaning All campuses.
        //
        // A cookie naming a campus outside this user's scope is stale or
        // borrowed, and is treated as absent rather than as a choice: labelling
        // their page "All campuses" while they are scoped to one is worse than
        // ignoring it.
        $cookieDecided = false;
        if (array_key_exists('portal_campus_id', $_COOKIE)) {
            $raw = trim((string) $_COOKIE['portal_campus_id']);
            if ($raw === '') {
                $selectedCampusId = null;
                $cookieDecided = true;
            } else {
                $available = [];
                foreach ($campuses as $campus) {
                    $available[(int) $campus['id']] = true;
                }
                $cookieVal = filter_var($raw, FILTER_VALIDATE_INT);
                if ($cookieVal !== false && $cookieVal > 0 && isset($available[$cookieVal])) {
                    $selectedCampusId = $cookieVal;
                    $cookieDecided = true;
                }
            }
        }

        if (!$cookieDecided
            && $selectedCampusId === null
            && $actor !== null
            && isset($actor['currentCampusId'])
            && is_int($actor['currentCampusId'])
        ) {
            $selectedCampusId = $actor['currentCampusId'];
        }

        // Always add "All campuses" first — consistent across every page.
        // It is selected when no specific campus has been resolved above.
        $campusOptions = sprintf(
            '<option value=""%s>All campuses</option>',
            $selectedCampusId === null ? ' selected' : '',
        );
        foreach ($campuses as $campus) {
            $isSelected = $selectedCampusId !== null && (int) $campus['id'] === $selectedCampusId;
            $campusOptions .= sprintf(
                '<option value="%d"%s>%s</option>',
                (int) $campus['id'],
                $isSelected ? ' selected' : '',
                htmlspecialchars((string) $campus['name'], ENT_QUOTES, 'UTF-8'),
            );
        }

        $actorFirstName = portal_first_name($actor['displayName'] ?? null);
        if ($actor === null) {
            $authAction = sprintf(
                '<a class="button" href="%s">%s</a>',
                htmlspecialchars($loginHref, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($loginLabel, ENT_QUOTES, 'UTF-8'),
            );
        } else {
            // The name in the corner was a bare link to /account. Signing out
            // meant knowing to click your own name, landing on the account
            // page, scrolling to "Your access" and pressing a button there —
            // there was no sign-out anywhere in the page chrome.
            //
            // It is also where personal functions belong. They were filed under
            // Administration, which put an ordinary member's own profile behind
            // a section about running the church, and left members with no way
            // to reach it at all.
            $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $items = [
                ['href' => $basePath . '/account',     'label' => 'My profile'],
                ['href' => $basePath . '/my-schedule', 'label' => 'My schedule'],
                ['href' => $basePath . '/availability','label' => 'My availability'],
            ];
            // Administration is offered only to those who have some.
            if (!empty($actor['isPortalWideAdmin'])
                || array_intersect(
                    ['manage_events', 'manage_schedules', 'manage_ministry_roles'],
                    (array) ($actor['permissions'] ?? []),
                ) !== []
            ) {
                $items[] = ['href' => $basePath . '/admin', 'label' => 'Administration', 'group' => true];
            }

            $menuItems = '';
            foreach ($items as $item) {
                $menuItems .= sprintf(
                    '<a role="menuitem" class="user-menu-item%s" href="%s">%s</a>',
                    !empty($item['group']) ? ' user-menu-sep' : '',
                    $esc((string) $item['href']),
                    $esc((string) $item['label']),
                );
            }

            $authAction =
                '<div class="user-menu" data-user-menu>'
                . '<button class="button secondary user-menu-btn" type="button" id="userMenuBtn"'
                . ' aria-haspopup="menu" aria-expanded="false" aria-controls="userMenu">'
                . '<span class="welcome-label">' . $esc($actorFirstName) . '</span>'
                . '<svg class="user-menu-caret" viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M7 10l5 5 5-5z"/></svg>'
                . '</button>'
                . '<div class="user-menu-panel" id="userMenu" role="menu" aria-labelledby="userMenuBtn" hidden>'
                . $menuItems
                . '<form method="post" action="' . $esc($basePath . '/logout') . '" class="user-menu-signout" data-signout>'
                . '<button role="menuitem" type="submit" class="user-menu-item user-menu-sep">Sign out</button>'
                . '</form>'
                . '</div></div>';
        }

        $titleBlock = '';
        if ($title !== '' || $subtitle !== '') {
            $titleBlock = sprintf(
                '<div class="portal-titleblock"><h1>%s</h1><p>%s</p></div>',
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'),
            );
        }

        $avatarChar = ($actor !== null && $actorFirstName !== 'Friend')
            ? strtoupper(substr($actorFirstName, 0, 1))
            : '?';

        // Build drawer body: "Quick Access" shortcuts + main grouped nav
        $drawerQuickHtml = '';
        $drawerMenuHtml  = '';
        foreach ($navGroups as $group) {
            if (isset($group['href'])) {
                // top-level link (e.g. Home) goes straight into menu
                $drawerMenuHtml .= sprintf(
                    '<a class="drawer-item" href="%s"><span class="drawer-item-icon">%s</span><span class="drawer-item-label">%s</span></a>',
                    htmlspecialchars((string) $group['href'], ENT_QUOTES, 'UTF-8'),
                    isset($group['icon']) ? portal_icon((string) $group['icon']) : '',
                    htmlspecialchars((string) $group['label'], ENT_QUOTES, 'UTF-8'),
                );
                continue;
            }
            $drawerMenuHtml .= sprintf(
                '<div class="drawer-section-title">%s</div>',
                htmlspecialchars((string) $group['label'], ENT_QUOTES, 'UTF-8'),
            );
            foreach (($group['items'] ?? []) as $item) {
                $itemLabel = strtolower((string) ($item['label'] ?? ''));
                $itemHtml  = sprintf(
                    '<a class="drawer-item" href="%s"><span class="drawer-item-icon">%s</span><span class="drawer-item-label">%s</span></a>',
                    htmlspecialchars((string) ($item['href'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    isset($item['icon']) ? portal_icon((string) $item['icon']) : '',
                    htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8'),
                );
                // Surface schedule/availability as thumb-reachable shortcuts
                if (str_contains($itemLabel, 'schedule') || str_contains($itemLabel, 'availability')) {
                    $drawerQuickHtml .= $itemHtml;
                } else {
                    $drawerMenuHtml .= $itemHtml;
                }
            }
            $drawerMenuHtml .= '<hr class="drawer-divider">';
        }

        // Admin control. Hidden when logged out. For a portal-wide admin it links
        // to the control panel; for a signed-in non-admin it shows but does nothing.
        // Visible search trigger — mouse, keyboard and touch. Ctrl/Cmd+K still works.
        $searchBtnHtml = '<button class="icon-btn search-btn" id="searchBtn" type="button"'
            . ' aria-label="Search the portal" aria-keyshortcuts="Control+K" title="Search (Ctrl+K)">'
            . portal_icon('search') . '</button>';

        // Overflow menu — keeps every secondary destination reachable on desktop
        // without lengthening the primary tab row (which used to clip silently).
        $moreItems = '';
        foreach ($secondaryNav as $item) {
            $moreItems .= sprintf(
                '<a class="more-item" role="menuitem" href="%s">%s<span>%s</span></a>',
                htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8'),
                portal_icon((string) $item['icon']),
                htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8'),
            );
        }

        $settingsHref = htmlspecialchars($basePath . '/admin', ENT_QUOTES, 'UTF-8');
        $isAdminUser = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);
        $adminIconHtml = '';
        // Permission-aware presentation (Cursor F10): a non-admin was shown a
        // disabled Admin control with no explanation, which reads as a broken or
        // withheld feature. Admins get the control; everyone else simply does
        // not see it. Authorization is unchanged — /admin and the admin APIs
        // enforce exactly as before; this only stops advertising a destination
        // the user cannot use.
        if ($actor !== null && $isAdminUser) {
            $adminIconHtml = '<a class="icon-btn settings-btn" href="' . $settingsHref . '" title="Admin" aria-label="Admin">' . portal_icon('admin') . '</a>';
        }

        if ($isAdminUser) {
            $moreItems .= sprintf(
                '<a class="more-item" role="menuitem" href="%s">%s<span>%s</span></a>',
                $settingsHref, portal_icon('admin'), 'Administration',
            );
        }
        $moreHtml = $moreItems === '' ? '' :
            '<div class="more-wrap">'
            . '<button class="icon-btn more-btn" id="moreBtn" type="button" aria-label="More destinations"'
            . ' aria-haspopup="menu" aria-expanded="false" aria-controls="moreMenu" title="More">'
            . portal_icon('menu') . '</button>'
            . '<div class="more-menu" id="moreMenu" role="menu" hidden>' . $moreItems . '</div>'
            . '</div>';

        // Surface the same destination in the drawer (admins only get a link).
        if ($isAdminUser) {
            $drawerMenuHtml .= '<hr class="drawer-divider">'
                . sprintf(
                    '<a class="drawer-item" href="%s"><span class="drawer-item-icon">%s</span><span class="drawer-item-label">%s</span></a>',
                    $settingsHref,
                    portal_icon('admin'),
                    'Administration',
                );
        }

        // Search is reachable on touch, where Ctrl+K is not available.
        $drawerSearchHtml = '<a class="drawer-item" id="drawerSearchBtn" href="#" role="button">'
            . '<span class="drawer-item-icon">' . portal_icon('search') . '</span>'
            . '<span class="drawer-item-label">Search</span></a>';

        // Every secondary destination, so mobile hides nothing the desktop offers.
        $drawerSecondary = '';
        foreach ($secondaryNav as $item) {
            $drawerSecondary .= sprintf(
                '<a class="drawer-item" href="%s"><span class="drawer-item-icon">%s</span><span class="drawer-item-label">%s</span></a>',
                htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8'),
                portal_icon((string) $item['icon']),
                htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8'),
            );
        }
        if ($drawerSecondary !== '') {
            $drawerMenuHtml .= '<div class="drawer-section-title">More</div>' . $drawerSecondary;
        }

        $drawerMenuHtml = $drawerSearchHtml . $drawerMenuHtml;

        $drawerBodyHtml = $drawerQuickHtml !== ''
            ? '<div class="drawer-section-title">Quick Access</div>' . $drawerQuickHtml . '<hr class="drawer-divider">' . $drawerMenuHtml
            : $drawerMenuHtml;

            $drawerHtml = sprintf(
            '<div class="drawer-head">'
            . '<div class="drawer-user"><span class="drawer-avatar">%s</span><span class="drawer-uname">%s</span></div>'
            . '<button class="drawer-close" id="drawerClose" type="button" aria-label="Close navigation menu">&#x2715;</button>'
            . '</div>'
            . '<div class="drawer-body">%s</div>',
            htmlspecialchars($avatarChar, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($actorFirstName, ENT_QUOTES, 'UTF-8'),
            $drawerBodyHtml,
        );

        // Self-contained drawer JS — no per-page listener needed
            $navFitJs = '<script>(function(){'
                . 'function mark(){var n=document.querySelector(\'.portal-nav\');if(!n)return;'
                . 'n.classList.toggle(\'is-scrollable\',n.scrollWidth>n.clientWidth+1);}'
                . 'mark();window.addEventListener(\'resize\',mark);'
                . '})();</script>';

            $moreJs = '<script>(function(){'
                . 'var mb=document.getElementById(\'moreBtn\'),mm=document.getElementById(\'moreMenu\');'
                . 'if(!mb||!mm)return;'
                . 'function open(){mm.hidden=false;mb.setAttribute(\'aria-expanded\',\'true\');var f=mm.querySelector(\'a\');f&&f.focus();}'
                . 'function close(f){mm.hidden=true;mb.setAttribute(\'aria-expanded\',\'false\');if(f)mb.focus();}'
                . 'mb.addEventListener(\'click\',function(e){e.stopPropagation();mm.hidden?open():close(false);});'
                . 'document.addEventListener(\'click\',function(e){if(!mm.hidden&&!mm.contains(e.target)&&e.target!==mb)close(false);});'
                . 'document.addEventListener(\'keydown\',function(e){if(e.key===\'Escape\'&&!mm.hidden)close(true);});'
                . '})();</script>';

            // Same open/close contract as the overflow menu above, so the two
            // header menus behave identically. Arrow keys are wired because
            // this is a role="menu" and a keyboard user is entitled to expect
            // them; Escape returns focus to the button that opened it.
            $userMenuJs = '<script>(function(){'
                . 'var b=document.getElementById(\'userMenuBtn\'),m=document.getElementById(\'userMenu\');'
                . 'if(!b||!m)return;'
                . 'function items(){return Array.prototype.slice.call(m.querySelectorAll(\'.user-menu-item\'));}'
                . 'function open(){m.hidden=false;b.setAttribute(\'aria-expanded\',\'true\');var f=items()[0];f&&f.focus();}'
                . 'function close(rf){m.hidden=true;b.setAttribute(\'aria-expanded\',\'false\');if(rf)b.focus();}'
                . 'b.addEventListener(\'click\',function(e){e.stopPropagation();m.hidden?open():close(false);});'
                . 'document.addEventListener(\'click\',function(e){if(!m.hidden&&!m.contains(e.target)&&e.target!==b)close(false);});'
                . 'document.addEventListener(\'keydown\',function(e){'
                . 'if(e.key===\'Escape\'&&!m.hidden){close(true);return;}'
                . 'if(m.hidden)return;'
                . 'if(e.key!==\'ArrowDown\'&&e.key!==\'ArrowUp\')return;'
                . 'var list=items(),i=list.indexOf(document.activeElement);'
                . 'if(i<0)return;e.preventDefault();'
                . 'var n=e.key===\'ArrowDown\'?(i+1)%list.length:(i-1+list.length)%list.length;'
                . 'list[n].focus();});'
                . '})();</script>';

            $drawerJs = '<script>(function(){'
                . 'var btn=document.getElementById(\'menuButton\');'
                . 'var campusBtn=document.getElementById(\'campusIconBtn\');'
                . 'var drawer=document.getElementById(\'sideDrawer\');'
                . 'var backdrop=document.getElementById(\'drawerBackdrop\');'
                . 'var closeBtn=document.getElementById(\'drawerClose\');'
                . 'function openD(){if(!drawer)return;drawer.removeAttribute(\'inert\');drawer.removeAttribute(\'aria-hidden\');drawer.classList.add(\'open\');backdrop&&backdrop.classList.add(\'open\');btn&&btn.setAttribute(\'aria-expanded\',\'true\');document.body.style.overflow=\'hidden\';var f=drawer.querySelector(\'a,button\');f&&f.focus();}'
                . 'function closeD(){if(!drawer)return;if(drawer.contains(document.activeElement))document.activeElement.blur();drawer.setAttribute(\'inert\',\'\');drawer.setAttribute(\'aria-hidden\',\'true\');drawer.classList.remove(\'open\');backdrop&&backdrop.classList.remove(\'open\');btn&&btn.setAttribute(\'aria-expanded\',\'false\');document.body.style.overflow=\'\';btn&&btn.focus();}'
                . 'btn&&btn.addEventListener(\'click\',openD);'
                . 'campusBtn&&campusBtn.addEventListener(\'click\',openD);'
                . 'closeBtn&&closeBtn.addEventListener(\'click\',closeD);'
                . 'backdrop&&backdrop.addEventListener(\'click\',closeD);'
                . 'document.addEventListener(\'keydown\',function(e){if(e.key===\'Escape\')closeD();});'
                . '})();</script>';

        $noticeHtml = '';
        $noticeJs = '';
        if ((string) ($_GET['portal_notice'] ?? '') === 'schedule_editor_denied') {
            $noticeHtml = '<div class="portal-notice" id="portalNotice" role="status" aria-live="polite">'
                . '<div class="portal-notice-head"><div class="portal-notice-title">Schedule editor access</div><button class="portal-notice-close" id="portalNoticeClose" type="button" aria-label="Dismiss notice">x</button></div>'
                . '<div class="portal-notice-body">The schedule editor is reserved for ministry leaders and administrators with schedule management access. You can still review posted schedule assignees from the ministry page.</div>'
                . '</div>';
            $noticeJs = '<script>(function(){'
                . 'var n=document.getElementById(\'portalNotice\');var b=document.getElementById(\'portalNoticeClose\');'
                . 'function close(){if(n)n.remove();try{var u=new URL(window.location.href);u.searchParams.delete(\'portal_notice\');window.history.replaceState({},document.title,u.toString());}catch(e){}}'
                . 'b&&b.addEventListener(\'click\',close);setTimeout(close,9000);'
                . '})();</script>';
        }

        $searchBasePath = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
        $searchOverlayHtml = '<div id="searchBackdrop" class="search-backdrop"></div>'
            . '<div id="searchOverlay" class="search-overlay" role="dialog" aria-modal="true" aria-label="Site search" hidden data-base="' . $searchBasePath . '">'
            . '<div class="search-card">'
            . '<div class="search-input-row">'
            . '<span class="search-icon-inline"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M11 5a6 6 0 1 1-4.24 10.24L2.9 18.1 1.5 16.7l3.86-3.86A6 6 0 0 1 11 5Zm0 2a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/></svg></span>'
            . '<input type="search" id="portalSearchInput" class="search-input" placeholder="Search people, events, ministries, pages\u{2026}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" aria-label="Search the portal">'
            . '<button class="search-close" id="searchCloseBtn" type="button" aria-label="Close search">&#x2715;</button>'
            . '</div>'
            . '<div class="search-results" id="searchResults" aria-live="polite" aria-atomic="true"><div class="search-hint">Type to search across people, events, ministries and pages&#x2026;</div></div>'
            . '<div class="search-footer">'
            . '<span><kbd class="srch-kbd">&#x2191;</kbd><kbd class="srch-kbd">&#x2193;</kbd> Navigate</span>'
            . '<span><kbd class="srch-kbd">&#x23CE;</kbd> Open</span>'
            . '<span><kbd class="srch-kbd">Esc</kbd> Close</span>'
            . '<span style="margin-left:auto"><kbd class="srch-kbd">Ctrl</kbd><kbd class="srch-kbd">K</kbd> Toggle</span>'
            . '</div>'
            . '</div>'
            . '</div>';

        $searchJs = <<<'SRCHJS'
<script>(function(){
var overlay=document.getElementById('searchOverlay');
var backdrop=document.getElementById('searchBackdrop');
var input=document.getElementById('portalSearchInput');
var results=document.getElementById('searchResults');
var closeBtn=document.getElementById('searchCloseBtn');
var btn=document.getElementById('searchBtn');
if(!overlay)return;
var basePath=overlay.dataset.base||'';
var cache={people:null,events:null,ministries:null};
var debounceTimer=null;
var allItems=[];
var activeIdx=-1;
var pages=[
  {title:'Home',href:basePath+'/',sub:'Church events, ministries, and schedules',type:'page'},
  {title:'My Schedule',href:basePath+'/my-schedule',sub:'Personal assignments across ministries',type:'page'},
  {title:'Ministry Browser',href:basePath+'/ministries',sub:'Browse and manage ministries',type:'page'},
  {title:'People',href:basePath+'/people',sub:'Look up members (not the admin records page)',type:'page'},
  {title:'Calendar',href:basePath+'/calendar',sub:'Events calendar view',type:'page'},
  {title:'Events',href:basePath+'/events',sub:'Upcoming services and gatherings',type:'page'},
  {title:'Printables',href:basePath+'/printables',sub:'Printable calendars and ministry schedules',type:'page'},
  {title:'Docs',href:basePath+'/docs',sub:'User guide for leaders and members',type:'page'},
];
var SVG={
  page:'<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 4h7v7H4V4Zm9 0h7v4h-7V4ZM4 13h7v7H4v-7Zm9 6v-7h7v7h-7Z"/></svg>',
  person:'<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm6 1a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3 20v-1c0-2.76 2.91-5 6.5-5S16 16.24 16 19v1H3Zm14.5 0c-.07-1.84-.71-3.48-1.78-4.63A7.28 7.28 0 0 1 22 19v1h-4.5Z"/></svg>',
  event:'<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 2v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7Zm12 8H5v10h14V10ZM5 6h14v2H5V6Z"/></svg>',
  ministry:'<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2 3 7v10l9 5 9-5V7l-9-5Zm0 2.3 6.9 3.8L12 12 5.1 8.1 12 4.3ZM5 9.8l6 3.3V21l-6-3.3V9.8Zm8 10.2v-8l6-3.3v8l-6 3.3Z"/></svg>'
};
function esc(s){return String(s||'').replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function match(s,q){return String(s||'').toLowerCase().includes(q);}
var HINT='<div class="search-hint">Type to search across people, events, ministries and pages\u2026</div>';
function openSearch(){overlay.hidden=false;backdrop.classList.add('open');document.body.style.overflow='hidden';input.focus();input.select();prefetch();}
function closeSearch(){overlay.hidden=true;backdrop.classList.remove('open');document.body.style.overflow='';input.value='';results.innerHTML=HINT;allItems=[];activeIdx=-1;}
function prefetch(){
  if(!cache.people){fetch(basePath+'/api/public/people-directory',{credentials:'same-origin'}).then(function(r){return r.ok?r.json():null;}).then(function(d){if(d&&d.people)cache.people=d.people;}).catch(function(){});}
  if(!cache.events){fetch(basePath+'/api/events?limit=200',{credentials:'same-origin'}).then(function(r){return r.ok?r.json():null;}).then(function(d){if(d&&d.events)cache.events=d.events;}).catch(function(){});}
  if(!cache.ministries){fetch(basePath+'/api/ministries',{credentials:'same-origin'}).then(function(r){return r.ok?r.json():null;}).then(function(d){if(d&&d.ministries)cache.ministries=d.ministries;}).catch(function(){});}
}
function setActive(idx){
  var els=results.querySelectorAll('.search-result');
  els.forEach(function(el){el.classList.remove('active');});
  if(idx>=0&&idx<els.length){els[idx].classList.add('active');els[idx].scrollIntoView({block:'nearest'});}
  activeIdx=idx;
}
function renderGroup(label,items){
  if(!items.length)return'';
  var rows=items.map(function(item){
    var idx=allItems.indexOf(item);
    return'<a class="search-result" href="'+esc(item.href)+'" data-idx="'+idx+'">'
      +'<span class="search-result-icon">'+SVG[item.type]+'</span>'
      +'<span class="search-result-text">'
      +'<span class="search-result-title">'+esc(item.title)+'</span>'
      +'<span class="search-result-sub">'+esc(item.sub)+'</span>'
      +'</span></a>';
  }).join('');
  return'<div class="search-group"><div class="search-group-label">'+esc(label)+'</div>'+rows+'</div>';
}
function renderResults(q){
  if(!q||q.length<2){results.innerHTML='<div class="search-hint">Type at least 2 characters\u2026</div>';allItems=[];activeIdx=-1;return;}
  var ql=q.toLowerCase();
  var pm=pages.filter(function(p){return match(p.title,ql)||match(p.sub,ql);});
  var people=cache.people
    ?cache.people.filter(function(p){return match(p.displayName,ql)||match(p.firstName,ql)||match(p.email,ql)||match(p.ministries,ql);}).slice(0,8).map(function(p){return{title:p.displayName||p.firstName,sub:[p.classificationName,p.ministries].filter(Boolean).join(' \u00b7')||'Member',href:basePath+'/people/'+p.personId,type:'person'};})
    :[];
  var events=cache.events
    ?cache.events.filter(function(e){return match(e.title,ql);}).slice(0,6).map(function(e){
      var d=e.next_occurrence_at?new Date(e.next_occurrence_at).toLocaleDateString():null;
      var id=e.event_id||e.eventId||0;
      return{title:e.title,sub:d?'Next: '+d:'Upcoming event',href:id?basePath+'/events/'+id:basePath+'/events',type:'event'};
    })
    :[];
  var mins=cache.ministries
    ?cache.ministries.filter(function(m){return match(m.name,ql);}).slice(0,5).map(function(m){return{title:m.name,sub:m.campusId?'Ministry \u00b7 Campus #'+m.campusId:'Ministry',href:basePath+'/ministries/'+m.ministryId,type:'ministry'};})
    :[];
  allItems=pm.concat(people,events,mins);
  activeIdx=-1;
  if(!allItems.length){results.innerHTML='<div class="search-hint">No results for <strong>'+esc(q)+'</strong></div>';return;}
  results.innerHTML=renderGroup('Pages',pm)+renderGroup('People',people)+renderGroup('Events',events)+renderGroup('Ministries',mins);
}
btn&&btn.addEventListener('click',openSearch);var btn2=document.getElementById('drawerSearchBtn');btn2&&btn2.addEventListener('click',function(e){e.preventDefault();openSearch();});
closeBtn&&closeBtn.addEventListener('click',closeSearch);
backdrop&&backdrop.addEventListener('click',closeSearch);
input&&input.addEventListener('input',function(){clearTimeout(debounceTimer);debounceTimer=setTimeout(function(){renderResults(input.value.trim());},180);});
input&&input.addEventListener('keydown',function(e){
  var els=results.querySelectorAll('.search-result');
  if(e.key==='ArrowDown'){e.preventDefault();setActive(Math.min(activeIdx+1,els.length-1));}
  else if(e.key==='ArrowUp'){e.preventDefault();setActive(Math.max(activeIdx-1,0));}
  else if(e.key==='Enter'&&activeIdx>=0&&els[activeIdx]){e.preventDefault();els[activeIdx].click();}
  else if(e.key==='Escape'){closeSearch();}
});
document.addEventListener('keydown',function(e){
  if(e.key==='k'&&(e.ctrlKey||e.metaKey)){var t=((document.activeElement||{}).tagName||'').toUpperCase();if(t==='INPUT'||t==='TEXTAREA'||t==='SELECT')return;e.preventDefault();overlay.hidden?openSearch():closeSearch();}
  else if(e.key==='Escape'&&!overlay.hidden){closeSearch();}
});
})();</script>
SRCHJS;

        // Header enhancement JS: mark active nav-tab and has-active parent group on load.
        // Uses prefix matching so /ministries/123, /ministries/editor, etc. all activate
        // the Ministries tab without requiring an exact path match.
        $headerEnhanceJs = '<script>(function(){'
            // Campus persistence: write cookie when the user changes the topbar select so every
            // subsequent page load pre-selects the same campus without needing a URL param.
            // Value "" means "All campuses" (cookie stores empty string → PHP skips it via > 0 check).
            . 'var _csAll=[document.getElementById("campusSelect"),document.getElementById("campusSelectMobile")].filter(Boolean);'
            . '_csAll.forEach(function(_cs){'
            . '  _cs.addEventListener("change",function(){'
            . '    var _exp=new Date(Date.now()+30*24*60*60*1000).toUTCString();'
            . '    document.cookie="portal_campus_id="+encodeURIComponent(_cs.value)+"; path=/; expires="+_exp+"; SameSite=Lax";'
            // Keep the two controls in lockstep so the visible one always shows
            // the campus actually being applied.
            . '    _csAll.forEach(function(o){if(o!==_cs)o.value=_cs.value;});'
            // The cookie previously only took effect on the NEXT navigation, so
            // the control looked inert. Re-render so the filter the user just
            // chose is the filter they see. Cookie remains the mechanism.
            . '    window.location.reload();'
            . '  });'
            . '});'
            . 'function norm(p){try{var u=new URL(p,window.location.origin);return u.pathname.replace(/\/$/,"");}catch(e){return p.replace(/\/$/,"");}}'
            . 'var home="' . htmlspecialchars(rtrim($basePath, '/'), ENT_QUOTES, 'UTF-8') . '";'
            . 'var path=window.location.pathname.replace(/\/$/,"");'
            . 'document.querySelectorAll(".portal-nav .nav-tab,.nav-flyout a").forEach(function(a){'
            . '  try{'
            . '    var href=norm(a.getAttribute("href")||"");'
            . '    if(!href)return;'
            // Exact match OR prefix match (tab href is a proper prefix of current path,
            // e.g. /church_portal/ministries matches /church_portal/ministries/123).
            // The home tab (basePath/) only activates on exact match to avoid matching every page.
            . '    var isHome=(href===home||href===home+"/");'
            . '    var active=isHome?(path===home||path===home+"/"):(path===href||(path.startsWith(href)&&path[href.length]==="/"||path.startsWith(href+"//")));'
            . '    if(active){'
            . '      a.classList.add("is-active");'
            . '      var g=a.closest(".nav-group");'
            . '      if(g){var t=g.querySelector(".nav-tab");if(t)t.classList.add("has-active");}'
            . '    }'
            . '  }catch(e){}'
            . '});'
            . '})();</script>';

        // Compact inline campus selector shown in the topbar on desktop.
        // Hidden on mobile (≤820px) where the drawer handles campus switching.
        if ($campuses !== [] && $campusOptions !== '') {
            $campusSelectHtml = '<div class="topbar-campus">'
                . portal_icon('dashboard')
                . '<select id="campusSelect" class="topbar-campus-select" aria-label="Campus selector">'
                . $campusOptions
                . '</select>'
                . '</div>';
        } else {
            $campusSelectHtml = '';
        }

        // ---- Persistent application context (campus / ministry / role) ------
        // Campus is functional state, not chrome: the portal_campus_id cookie is
        // the highest-priority filter source on every page. Below 820px the
        // topbar select is hidden, so without this bar the data stays filtered
        // with no indicator and no control (audit C5, INV-3).
        $contextChips = '';
        if ($campuses !== [] && $campusOptions !== '') {
            $contextChips .= '<label class="ctx-chip ctx-campus">'
                . '<span class="ctx-label">Campus</span>'
                . '<select id="campusSelectMobile" class="ctx-select" aria-label="Campus selector">'
                . $campusOptions
                . '</select>'
                . '</label>';
        }
        // Role chip: the shell's actor payload exposes only isPortalWideAdmin, so
        // "Admin" is the single role it can state truthfully. account_roles is
        // multi-row (admin/leader/scheduler/member, each optionally scoped to a
        // ministry and campus) — rendering "Member" for a scoped leader would be
        // actively misleading, so the chip is omitted rather than guessed.
        // Full role/scope display needs those roles surfaced in the actor payload;
        // that is a backend context change, explicitly out of scope for Phase 1.
        if ($actor !== null && ($actor['isPortalWideAdmin'] ?? false)) {
            $contextChips .= '<span class="ctx-chip ctx-role"><span class="ctx-label">Role</span>'
                . '<span class="ctx-value">Admin</span></span>';
        }
        $contextBarHtml = $contextChips === '' ? '' :
            '<div class="context-bar" role="group" aria-label="Application context">' . $contextChips . '</div>';

        // Panel collapse is a shell concern so saved views can later persist
        // left/right without reading calendar internals.
        // Bound by delegation, and restored after the DOM is parsed.
        //
        // This script is emitted with the topbar, which comes *before* the left
        // panel it controls. The previous version resolved the toggle button
        // once, immediately — so querySelector returned null, no listener was
        // ever attached, and the collapse control silently did nothing on every
        // page that had one. Delegation cannot be broken by document order, and
        // survives a panel being re-rendered underneath it.
        $shellJs = '<script>(function(){'
            . 'var KEY="church_portal_shell_v1";'
            . 'function shell(){return document.querySelector(".shell");}'
            . 'function read(){try{return JSON.parse(localStorage.getItem(KEY)||"{}")||{};}catch(e){return {};}}'
            . 'function write(p){var o=read();for(var k in p)o[k]=p[k];try{localStorage.setItem(KEY,JSON.stringify(o));}catch(e){}}'
            . 'function syncSide(side){var s=shell();if(!s)return;'
            . 'var btn=document.querySelector("[data-portal-"+side+"-toggle]");if(!btn)return;'
            . 'var open=s.getAttribute("data-"+side)!=="collapsed";'
            . 'btn.setAttribute("aria-expanded",open?"true":"false");'
            . 'var lab=btn.querySelector(".portal-"+side+"-toggle-label");'
            . 'var name=lab?lab.textContent.trim():"panel";'
            . 'btn.setAttribute("aria-label",(open?"Collapse ":"Expand ")+name);}'
            . 'function restore(){var s=shell();if(!s)return;var saved=read();'
            . 'if(saved.left&&document.querySelector("[data-portal-left-toggle]")'
            . '&&s.getAttribute("data-left")!=="none")s.setAttribute("data-left",saved.left);'
            . 'if(saved.right&&document.querySelector("[data-portal-right-toggle]")'
            . '&&s.getAttribute("data-right")!=="none")s.setAttribute("data-right",saved.right);'
            . 'syncSide("left");syncSide("right");}'
            . 'document.addEventListener("click",function(e){'
            . 'var left=e.target.closest&&e.target.closest("[data-portal-left-toggle]");'
            . 'var right=e.target.closest&&e.target.closest("[data-portal-right-toggle]");'
            . 'var s=shell();if(!s)return;'
            . 'if(left){if(s.getAttribute("data-left")==="none")return;'
            . 's.setAttribute("data-left",s.getAttribute("data-left")==="collapsed"?"open":"collapsed");'
            . 'write({left:s.getAttribute("data-left")});syncSide("left");}'
            . 'if(right){if(s.getAttribute("data-right")==="none")return;'
            . 's.setAttribute("data-right",s.getAttribute("data-right")==="collapsed"?"open":"collapsed");'
            . 'write({right:s.getAttribute("data-right")});syncSide("right");}});'
            . 'if(document.readyState==="loading")'
            . 'document.addEventListener("DOMContentLoaded",restore);else restore();'
            . '})();</script>';

        // 3-column grid: [brand] [nav] [actions]
        return portal_theme_style_block() . sprintf(
            '<a class="skip-link" href="#portal-main">Skip to main content</a>'
            . '<header class="topbar">'
            . '<div class="topbar-brand">'
            . '<a class="portal-brand" href="%s/" aria-label="%s"><span class="portal-brand-mark" aria-hidden="true" style="background-image:url(\'%s/images/christlikeness_colored.jpg\')"></span></a>'
            . '<span class="portal-brand-text" aria-hidden="true">%s</span>'
            . '</div>'
            . '%s'
            . '<div class="topbar-actions">'
            . '%s'
            . '%s'
            . '%s'
            . '%s'
            . '%s'
            . '<button class="icon-btn ham-btn" id="menuButton" type="button" aria-label="Open navigation menu" aria-controls="sideDrawer" aria-expanded="false">%s</button>'
            . '</div>'
            . '</header>'
            . '%s'
            . '<div class="drawer-backdrop" id="drawerBackdrop"></div>'
            . '<aside class="side-drawer" id="sideDrawer" role="dialog" aria-modal="true" aria-label="Navigation menu" inert aria-hidden="true">%s</aside>'
            . '%s%s%s%s',
            htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($chrome['header']['brandTitle'] ?? 'Scheduler') . ' home', ENT_QUOTES, 'UTF-8'),
            // The logo follows the base path: the app runs at a site root or under a folder.
            htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($chrome['header']['brandTitle'] ?? ''), ENT_QUOTES, 'UTF-8'),
            portal_nav_bar($basePath, $navGroups),
            $campusSelectHtml,
            $searchBtnHtml,
            $moreHtml,
            $adminIconHtml,
            $authAction,
            portal_icon('menu'),
            $contextBarHtml,
            $drawerHtml,
            $drawerJs . $moreJs . $userMenuJs . $navFitJs . $shellJs,
            $searchOverlayHtml . $searchJs,
            $noticeHtml . $noticeJs,
            $headerEnhanceJs,
            $titleBlock,
        );
    }
}

if (!function_exists('portal_footer')) {
    /**
     * Render the global footer. Pages can pass left/right strings to override,
     * otherwise we read config/chrome.json so admins can manage the text +
     * minimal-mode toggle from /admin/footer without code changes.
     */
    function portal_footer(string $left = '', string $right = ''): string
    {
        $chrome = portal_chrome();
        $cfgLeft  = (string) ($chrome['footer']['leftText']  ?? 'Church Portal');
        $cfgRight = (string) ($chrome['footer']['rightText'] ?? '');
        $minimal  = (bool)   ($chrome['footer']['minimal']   ?? true);
        $finalLeft  = $left  !== '' ? $left  : $cfgLeft;
        $finalRight = $right !== '' ? $right : $cfgRight;

        $cls = 'portal-footer' . ($minimal ? ' is-minimal' : '');
        // The minimal style flattens the footer to a single thin row with no
        // border/padding noise — applied via a tiny self-contained CSS rule
        // so individual page CSS doesn't have to know about the toggle.
        $minimalCss = $minimal
            ? '<style>.portal-footer.is-minimal{border:0;padding:8px 0;margin-top:14px;font-size:12px;opacity:.78}.portal-footer.is-minimal>span:nth-child(2):empty{display:none}</style>'
            : '';
        return $minimalCss . '<footer class="' . $cls . '"><span>' . htmlspecialchars($finalLeft, ENT_QUOTES, 'UTF-8') . '</span><span>' . htmlspecialchars($finalRight, ENT_QUOTES, 'UTF-8') . '</span></footer>';
    }
}

if (!function_exists('portal_first_name')) {
    function portal_first_name(?string $displayName, string $fallback = 'Friend'): string
    {
        $displayName = trim((string) $displayName);
        if ($displayName === '') {
            return $fallback;
        }

        $parts = preg_split('/\s+/', $displayName);
        if (!is_array($parts) || $parts === []) {
            return $fallback;
        }

        $first = trim((string) $parts[0]);
        return $first !== '' ? $first : $fallback;
    }
}

if (!function_exists('portal_ministry_icon')) {
    function portal_ministry_icon(string $name): string
    {
        $key = strtolower($name);
        $map = [
            'music' => 'events',
            'worship' => 'events',
            'children' => 'people',
            'youth' => 'people',
            'prayer' => 'calendar',
            'media' => 'search',
            'care' => 'availability',
            'outreach' => 'ministry',
        ];
        foreach ($map as $needle => $icon) {
            if (str_contains($key, $needle)) {
                return portal_icon($icon);
            }
        }
        return portal_icon('ministry');
    }
}
