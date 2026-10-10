<?php

declare(strict_types=1);

/**
 * Shared page primitives (Phase 2).
 *
 * Design notes
 * ------------
 * 1. Class names are namespaced `pc-*` ("portal component"). The audit found
 *    `.button` redefined in 14 views, `.card` in 5, `.shell` in 20 — a bare
 *    `.btn`/`.card` here would collide with page CSS that is still in place.
 *    Namespacing lets pages migrate one phase at a time with no flag day.
 * 2. CSS is emitted ONCE from the shell foundation block, not from these render
 *    functions. Phase 0 proved roughly half the page views bypass shell helper
 *    functions (6 hand-roll their own footer), so function-local CSS would not
 *    reach them.
 * 3. Every value is a token. No raw hex.
 */

if (!function_exists('pc_attr')) {
    function pc_attr(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pc_button')) {
    /**
     * @param array{label:string,href?:string,variant?:string,size?:string,
     *              icon?:string,type?:string,id?:string,disabled?:bool,
     *              ariaLabel?:string,attrs?:string} $o
     */
    function pc_button(array $o): string
    {
        $variant = (string) ($o['variant'] ?? 'secondary');
        $size    = (string) ($o['size'] ?? 'md');
        $cls     = 'pc-btn pc-btn--' . $variant . ' pc-btn--' . $size;
        $icon    = isset($o['icon']) ? portal_icon((string) $o['icon']) : '';
        $label   = pc_attr((string) ($o['label'] ?? ''));
        $aria    = isset($o['ariaLabel']) ? ' aria-label="' . pc_attr((string) $o['ariaLabel']) . '"' : '';
        $id      = isset($o['id']) ? ' id="' . pc_attr((string) $o['id']) . '"' : '';
        $extra   = (string) ($o['attrs'] ?? '');

        if (!empty($o['href'])) {
            return '<a class="' . $cls . '" href="' . pc_attr((string) $o['href']) . '"' . $id . $aria . ' ' . $extra . '>'
                . $icon . '<span>' . $label . '</span></a>';
        }
        $type = pc_attr((string) ($o['type'] ?? 'button'));
        $dis  = !empty($o['disabled']) ? ' disabled' : '';
        return '<button class="' . $cls . '" type="' . $type . '"' . $id . $aria . $dis . ' ' . $extra . '>'
            . $icon . '<span>' . $label . '</span></button>';
    }
}

if (!function_exists('pc_page_header')) {
    /**
     * Standard page header. One <h1> per page — never duplicated by a breadcrumb.
     * @param array{title:string,kicker?:string,description?:string,actions?:string,
     *              variant?:string,id?:string} $o
     */
    function pc_page_header(array $o): string
    {
        $variant = (string) ($o['variant'] ?? 'standard');
        $kicker  = ($o['kicker'] ?? '') !== ''
            ? '<p class="pc-page-kicker">' . pc_attr((string) $o['kicker']) . '</p>' : '';
        $desc    = ($o['description'] ?? '') !== ''
            ? '<p class="pc-page-desc">' . pc_attr((string) $o['description']) . '</p>' : '';
        $actions = ($o['actions'] ?? '') !== ''
            ? '<div class="pc-page-actions">' . (string) $o['actions'] . '</div>' : '';
        $id      = isset($o['id']) ? ' id="' . pc_attr((string) $o['id']) . '"' : '';

        return '<header class="pc-page-header pc-page-header--' . $variant . '"' . $id . '>'
            . '<div class="pc-page-headings">' . $kicker
            . '<h1 class="pc-page-title">' . pc_attr((string) ($o['title'] ?? '')) . '</h1>'
            . $desc . '</div>' . $actions . '</header>';
    }
}

if (!function_exists('pc_section_header')) {
    function pc_section_header(string $title, string $actions = '', int $level = 2): string
    {
        $l = max(2, min(4, $level));
        return '<div class="pc-section-header">'
            . '<h' . $l . ' class="pc-section-title">' . pc_attr($title) . '</h' . $l . '>'
            . ($actions !== '' ? '<div class="pc-section-actions">' . $actions . '</div>' : '')
            . '</div>';
    }
}

if (!function_exists('pc_empty_state')) {
    /**
     * Empty state. The audit found ~10 bare grey sentences with no action;
     * ui-ux-pro-max flags "Show helpful message and action" as the rule.
     * @param array{title:string,body?:string,icon?:string,action?:string,tone?:string} $o
     */
    function pc_empty_state(array $o): string
    {
        $icon = '<span class="pc-empty-icon" aria-hidden="true">'
            . portal_icon((string) ($o['icon'] ?? 'search')) . '</span>';
        $body = ($o['body'] ?? '') !== ''
            ? '<p class="pc-empty-body">' . pc_attr((string) $o['body']) . '</p>' : '';
        $act  = ($o['action'] ?? '') !== ''
            ? '<div class="pc-empty-action">' . (string) $o['action'] . '</div>' : '';
        $tone = (string) ($o['tone'] ?? 'neutral');
        return '<div class="pc-empty pc-empty--' . $tone . '" role="status">'
            . $icon
            . '<p class="pc-empty-title">' . pc_attr((string) ($o['title'] ?? '')) . '</p>'
            . $body . $act . '</div>';
    }
}

if (!function_exists('pc_signed_out')) {
    /** Signed-out state WITH an action. Never render actions that cannot succeed. */
    function pc_signed_out(string $basePath, string $what = 'this content'): string
    {
        return pc_empty_state([
            'icon'   => 'profile',
            'title'  => 'Sign in required',
            'body'   => 'Sign in to see ' . $what . '.',
            'action' => pc_button([
                'label'   => 'Sign in',
                'href'    => $basePath . '/login',
                'variant' => 'primary',
                'size'    => 'md',
            ]),
        ]);
    }
}

if (!function_exists('pc_unavailable')) {
    /**
     * Feature-flagged / unwired functionality. The spec forbids building the
     * missing backends and forbids shipping the engineering note as body copy.
     */
    function pc_unavailable(string $title, string $why = 'This feature is not available yet.'): string
    {
        return '<div class="pc-unavailable" role="status">'
            . '<p class="pc-unavailable-title">' . pc_attr($title) . '</p>'
            . '<p class="pc-unavailable-body">' . pc_attr($why) . '</p>'
            . '</div>';
    }
}

if (!function_exists('pc_badge')) {
    function pc_badge(string $label, string $tone = 'neutral'): string
    {
        return '<span class="pc-badge pc-badge--' . pc_attr($tone) . '">' . pc_attr($label) . '</span>';
    }
}

if (!function_exists('pc_skeleton')) {
    /** Layout-matched loading placeholder; respects prefers-reduced-motion. */
    function pc_skeleton(int $rows = 3, string $label = 'Loading'): string
    {
        $out = '<div class="pc-skeleton" role="status" aria-live="polite" aria-label="' . pc_attr($label) . '">';
        for ($i = 0; $i < max(1, $rows); $i++) {
            $out .= '<span class="pc-skeleton-row"></span>';
        }
        return $out . '</div>';
    }
}

if (!function_exists('pc_error_state')) {
    function pc_error_state(string $title, string $body = '', string $action = ''): string
    {
        return '<div class="pc-error" role="alert">'
            . '<p class="pc-error-title">' . pc_attr($title) . '</p>'
            . ($body !== '' ? '<p class="pc-error-body">' . pc_attr($body) . '</p>' : '')
            . ($action !== '' ? '<div class="pc-error-action">' . $action . '</div>' : '')
            . '</div>';
    }
}
