<?php

declare(strict_types=1);

/**
 * Theme tokens for the standalone applications (people_signup, events_rsvp).
 *
 * These are separate applications with their own bootstrap, session and
 * database access. They deliberately do NOT load the portal autoloader or its
 * service container, and this file does not change that: it reads
 * config/theme.json directly, which is where ThemeSettingsService stores the
 * active preset anyway. No DI, no database, no session — a data file read.
 *
 * This is presentation only. It emits custom properties and nothing else: no
 * chrome, no navigation, no layout. It is not a shell, and the standalone apps
 * remain independent.
 *
 * Emit it AFTER the app's own stylesheet. Both stylesheets declare a :root
 * block of their own; at equal specificity the later declaration wins, so
 * emitting afterwards lets the active theme override the built-in defaults
 * while leaving those defaults in place as a fallback if this file is missing.
 */

if (!function_exists('theme_tokens_style_block')) {
    /**
     * @return string A <style> element, or '' when the theme cannot be read —
     *                in which case the app's own :root defaults still apply.
     */
    function theme_tokens_style_block(): string
    {
        $path = dirname(__DIR__) . '/config/theme.json';
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return '';
        }

        $active = (string) ($decoded['active'] ?? 'forest');
        $preset = $decoded['presets'][$active] ?? $decoded['presets']['forest'] ?? null;
        $vars = is_array($preset['vars'] ?? null) ? $preset['vars'] : [];
        if ($vars === []) {
            return '';
        }

        // Same validation the portal applies: a theme file is admin-editable,
        // so a value must never be able to close the declaration and inject
        // rules or markup.
        $rules = '';
        foreach ($vars as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;
            if (preg_match('/^--[a-z-]+$/', $name) !== 1) {
                continue;
            }
            if (preg_match('/[<>{};]/', $value) === 1) {
                continue;
            }
            // Colour tokens only. The presets also carry geometry (--radius,
            // --spacing, --font-scale), and the standalone apps have their own:
            // emitting --radius here would silently move both apps from their
            // 10px corners to the preset's 8px, restyling every card, input and
            // button on two public-facing pages. Theme inheritance here means
            // palette, not layout.
            if (preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\(|hsla?\(|color-mix\()/', $value) !== 1) {
                continue;
            }
            $rules .= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ':'
                . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . ';';
        }

        if ($rules === '') {
            return '';
        }

        // The standalone apps name their colours by role (--brand, --accent)
        // rather than by hue, so those roles are mapped onto portal tokens and
        // the app stylesheets keep working unchanged.
        //
        // Only roles whose forest value is *identical* to the token are bridged,
        // so the default theme renders pixel-identically. --brand-dark is
        // deliberately not bridged: the apps ship #0e6a5e and no token carries
        // that value, so mapping it onto --deep (#123b31) would silently
        // restyle button hover on a public sign-up page. Extending the bridge
        // there means editing the two stylesheets and accepting a change to
        // their default appearance — an owner decision, not a safe default.
        // See docs/design/21-standalone-theme-support.md.
        // --brand is a *fill*; --brand-ink is the same hue darkened until it is
        // legible as text. The distinction is not cosmetic: three presets
        // (facebook, earthy-serene, vibrant-calm) have a --teal light enough
        // that white-on-brand measures as low as 2.39:1, so a bridge that
        // mapped the fill without its paired foreground would put failing
        // contrast on public sign-up and RSVP buttons. Under the default preset
        // --teal-ink equals --teal and --on-teal equals #fff, so the paired
        // tokens render identically to what the apps already shipped.
        $bridge = '--brand:var(--teal,#117b6d);'
            . '--brand-ink:var(--teal-ink,#117b6d);'
            . '--brand-on:var(--on-teal,#ffffff);'
            . '--accent:var(--soft,#eef4f0);';

        return '<style>:root{' . $rules . $bridge . '}</style>';
    }
}
