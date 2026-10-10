<?php
/**
 * Visitors & RSVPs › Access codes: the codes greeters type to open the sign-up
 * and RSVP modules' own review pages, and the public links for the website.
 *
 * @var string $basePath
 * @var ?array $actor
 * @var array $campusSelector
 * @var bool $refused
 * @var bool $missing
 * @var bool $unavailable
 * @var mixed $flash
 * @var ?array $codes
 * @var ?array $upcomingEvents
 */
require_once __DIR__ . '/_visitors-kit.php';
$codes ??= null;            // absent when the page is refused
$upcomingEvents ??= null;

echo visitors_render([
    'basePath' => $basePath, 'activeId' => 'access',
    'title' => 'Access codes',
    'description' => 'Greeters without a portal account open the sign-up review and RSVP attendance pages with an access code. Each code expires; issuing a new one replaces the old one at once.',
    'actor' => $actor, 'campusSelector' => $campusSelector,
    'refused' => $refused, 'missing' => $missing, 'unavailable' => $unavailable, 'flash' => $flash,
], static function () use ($basePath, $codes, $upcomingEvents): string {
    $base = visitors_e($basePath);
    $modules = [
        'signup' => ['Guest sign-up review', 'Opens the sign-up review spreadsheet for greeters.', '/people_signup/admin_review.php'],
        'rsvp' => ['RSVP attendance', 'Opens RSVP attendance for check-in at the door.', '/events_rsvp/admin_attendance.php'],
    ];

    $html = '<div class="ek-grid">';
    foreach ($modules as $module => [$title, $what, $page]) {
        $code = $codes[$module];
        $id = 'vs-' . $module;
        $html .= '<section class="ek-card" aria-labelledby="' . $id . '-title"><div class="ek-card-head"><div><h2 id="' . $id . '-title">' . visitors_e($title) . '</h2>'
            . '<p>' . visitors_e($what) . ' <a href="' . $base . $page . '">Open the page</a></p></div></div><div class="ek-card-body"><div class="ek-form" style="max-width:none">';

        if ($code === null) {
            $html .= '<div class="ek-alert"><div><strong>No code yet.</strong> The page sets a starting code the first time a greeter opens it; issue one here to choose it yourself.</div></div>';
        } else {
            $html .= '<div><span class="ek-label">Current code</span><div class="vs-code">' . visitors_e($code['code']) . '</div>'
                . '<div class="ek-toolbar" style="margin-top:6px">'
                . ($code['active'] ? '<span class="ek-badge is-ok">Active</span>' : '<span class="ek-badge is-error">Expired</span>')
                . '<span class="vs-small">Word: <strong>' . visitors_e($code['word']) . '</strong></span>'
                . '<span class="vs-small vs-muted">Issued ' . visitors_e(visitors_when($code['issued_at'])) . '</span>'
                . '<span class="vs-small ' . ($code['active'] ? 'vs-muted' : '') . '">' . ($code['active'] ? 'Expires ' : 'Expired ') . visitors_e(visitors_when($code['expires_at']))
                . ($code['active'] ? ' (' . (int) $code['remaining_days'] . ' ' . ((int) $code['remaining_days'] === 1 ? 'day' : 'days') . ' left)' : '') . '</span>'
                . '</div>'
                . ($code['note'] !== '' ? '<p class="vs-small vs-muted" style="margin:6px 0 0">Note: ' . visitors_e($code['note']) . '</p>' : '')
                . '<p class="ek-hint" style="margin:6px 0 0">Greeters only need the word; the page already shows “' . visitors_e($code['prefix']) . '”.</p></div>';
        }

        $html .= '<form class="ek-form" method="post" action="' . $base . '/visitors/access" style="max-width:none">'
            . '<input type="hidden" name="module" value="' . $module . '">'
            . '<div class="ek-field"><label for="' . $id . '-word">New word</label>'
            . '<input class="ek-input" id="' . $id . '-word" name="word" maxlength="64" autocomplete="off" pattern="[A-Za-z0-9]*" placeholder="For example Harvest482">'
            . '<span class="ek-hint">Letters and numbers only. Leave it empty and choose Generate for a random word.</span></div>'
            . '<div class="ek-field"><label for="' . $id . '-days">Valid for (days)</label>'
            . '<input class="ek-input" style="max-width:8rem" id="' . $id . '-days" name="days" type="number" min="1" max="365" value="7" required></div>'
            . '<div class="ek-field"><label for="' . $id . '-note">Note (optional)</label>'
            . '<input class="ek-input" id="' . $id . '-note" name="note" maxlength="120" placeholder="For example weekend welcome team"></div>'
            . '<div class="vs-actions"><button class="ek-btn ek-btn-primary" type="submit" name="action" value="set">Save this word</button>'
            . '<button class="ek-btn" type="submit" name="action" value="generate">Generate a word</button></div>'
            . '</form></div></div></section>';
    }
    $html .= '</div>';

    return $html . visitors_public_links_card($basePath, $upcomingEvents);
});
