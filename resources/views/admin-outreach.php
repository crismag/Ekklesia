<?php
require_once __DIR__ . '/_admin-shell.php';
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
$isAdmin = $actor !== null && (bool) ($actor['isPortalWideAdmin'] ?? false);

/** A labelled launch link. */
$link = static function (string $href, string $label, string $desc, string $kind = ''): string {
    $cls = $kind === 'primary' ? 'oc-btn primary' : 'oc-btn';
    return '<a class="' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
        . '<span class="oc-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
        . '<span class="oc-desc">' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</span></a>';
};

$signup = $base . '/people_signup';
$rsvp   = $base . '/events_rsvp';

$content = '<style>
    .oc-grid{display:grid;gap:12px;grid-template-columns:1fr 1fr}
    @media (max-width:640px){.oc-grid{grid-template-columns:1fr}}
    .oc-btn{display:block;padding:12px 14px;border:1px solid var(--line,#dbe4ec);border-radius:10px;text-decoration:none;background:#fff}
    .oc-btn:hover{border-color:#137a5f;background:#f7fbf9}
    .oc-btn.primary{background:#0c5a45;border-color:#0c5a45}
    .oc-btn.primary .oc-label,.oc-btn.primary .oc-desc{color:#eafaf3}
    .oc-label{display:block;font-weight:800;color:var(--ink,#1b2a24)}
    .oc-desc{display:block;font-size:12px;color:var(--muted,#5c6b63);margin-top:2px}
    .oc-note{font-size:12px;color:var(--muted,#5c6b63);margin-top:10px}
    .code{background:var(--soft,#eef4f0);border-radius:5px;padding:1px 5px;font-family:ui-monospace,monospace}
  </style>'

    . '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Guest Sign-Up</h2><p>New-people registration (not for existing members).</p></div></div>'
    . '<div class="admin-card-body"><div class="oc-grid">'
    . $link($signup . '/admin_review.php', 'Review sign-ups →', 'Spreadsheet of staged guests; edit, match & promote to members', 'primary')
    . $link($signup . '/index.php', 'Public sign-up form', 'Share this link with first-time & returning guests')
    . $link($signup . '/advanced.php', 'Full registration form', 'Longer form with address, background and contact details')
    . $link($signup . '/admin_access.php', 'Access code', 'Set / rotate the WORD ID and its validity window')
    . '</div></div></article>'

    . '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Event RSVP</h2><p>Per-event RSVPs & attendance.</p></div></div>'
    . '<div class="admin-card-body"><div class="oc-grid">'
    . $link($rsvp . '/admin_attendance.php', 'RSVP & attendance →', 'Per-event tallies and check-in / no-show', 'primary')
    . $link($rsvp . '/admin_access.php', 'Access code', 'Set / rotate the WORD ID and its validity window')
    . '</div>'
    . '<p class="oc-note">Share an event RSVP link as <span class="code">' . $rsvp . '/event.php?event_id=&lt;id&gt;</span> (the event id from the church calendar).</p>'
    . '</div></article>'

    . '<article class="admin-card">'
    . '<div class="admin-card-head"><div><h2>Access codes</h2><p>How staff sign in to these areas.</p></div></div>'
    . '<div class="admin-card-body" style="color:var(--muted)">'
    . 'Each admin area is protected by a rotating access code — a fixed <span class="code">ChristLikeness</span> prefix plus a WORD ID that expires (default one week). '
    . 'Manage each from its <b>Access code</b> page above; staff only need the WORD.'
    . '</div></article>';

echo admin_render_page([
    'basePath' => $basePath, 'activeId' => 'outreach',
    'pageTitle' => 'Sign-ups & RSVP · Admin', 'pageSubtitle' => 'Registration and RSVP tools.',
    'sectionTitle' => 'Sign-ups & RSVP',
    'sectionDescription' => 'Common access point for guest registration, event RSVPs, and their access codes.',
    'actor' => $actor, 'campusSelector' => $campusSelector, 'isAdmin' => $isAdmin,
], static fn (): string => $content);
