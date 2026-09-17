<?php
/**
 * Shared pieces of the Visitors & RSVPs pages: the page frame (with the
 * refusal, not-found and unavailable states), labels, dates and the public
 * links card. Built on the ek- kit; the few vs- classes below are layout this
 * workspace alone needs.
 */
declare(strict_types=1);

require_once __DIR__ . '/_admin-shell.php';

if (!function_exists('visitors_e')) {
    function visitors_e(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array{basePath:string,activeId:string,title:string,description:string,actor:?array,campusSelector:array,refused:bool,missing:bool,unavailable:bool,flash:mixed} $page
     */
    function visitors_render(array $page, callable $body): string
    {
        $base = visitors_e($page['basePath']);

        return admin_render_page([
            'basePath' => $page['basePath'],
            'activeId' => $page['activeId'],
            'pageTitle' => $page['title'] . ' · Visitors & RSVPs',
            'pageSubtitle' => '',
            'sectionTitle' => $page['title'],
            'sectionDescription' => $page['description'],
            'actor' => $page['actor'],
            'campusSelector' => $page['campusSelector'],
            'isAdmin' => !empty($page['actor']['isPortalWideAdmin']),
        ], static function () use ($page, $body, $base): string {
            $html = visitors_styles();
            $flash = $page['flash'];
            if (is_array($flash) && isset($flash['text'])) {
                $ok = ($flash['kind'] ?? '') === 'ok';
                $html .= '<div class="ek-alert ' . ($ok ? 'is-ok' : 'is-error') . '" role="' . ($ok ? 'status' : 'alert') . '">'
                    . ($ok ? '' : '<strong>Not saved.</strong> ') . visitors_e($flash['text']) . '</div>';
            }
            if ($page['refused']) {
                return $html . '<div class="ek-empty"><strong>Visitors &amp; RSVPs is for church administrators</strong>'
                    . '<p>Registrations and RSVPs hold guests\' contact details, so only portal administrators review them. Greeters use the access code pages instead.</p>'
                    . '<a class="ek-btn" href="' . $base . '/">Go to the home page</a></div>';
            }
            if ($page['unavailable']) {
                return $html . '<div class="ek-empty"><strong>The visitors database is not available</strong>'
                    . '<p>Registrations and RSVPs are kept in a separate database, and it could not be opened. The details are in the server log; ask whoever maintains the portal to check it.</p></div>';
            }
            if ($page['missing']) {
                return $html . '<div class="ek-empty"><strong>That registration is not here</strong>'
                    . '<p>It may have been removed, or the link is mistyped.</p>'
                    . '<a class="ek-btn" href="' . $base . '/visitors">Back to registrations</a></div>';
            }

            return $html . $body();
        });
    }

    function visitors_styles(): string
    {
        return <<<'CSS'
<style>
.vs-layout{display:grid;gap:var(--sp-4,16px);grid-template-columns:minmax(0,1fr)}
@media(min-width:1100px){.vs-layout{grid-template-columns:minmax(0,1fr) 340px;align-items:start}}
.vs-stack{display:grid;gap:var(--sp-4,16px);min-width:0}
.vs-facts{display:grid;grid-template-columns:minmax(120px,max-content) minmax(0,1fr);gap:6px 16px;margin:0;font-size:14px}
.vs-facts dt{color:var(--muted,#627169);font-weight:600}
.vs-facts dd{margin:0;overflow-wrap:anywhere}
@media(max-width:480px){.vs-facts{grid-template-columns:minmax(0,1fr)}.vs-facts dd{margin-bottom:6px}}
.vs-muted{color:var(--muted,#627169)}
.vs-small{font-size:12px}
.vs-list{list-style:none;margin:0;padding:0;display:grid;gap:var(--sp-2,8px)}
.vs-match{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border:1px solid var(--line,#d9e4dd);border-radius:var(--radius,8px);background:var(--paper,#fff)}
.vs-match form{margin:0}
.vs-why{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
.vs-actions{display:flex;flex-wrap:wrap;gap:var(--sp-2,8px)}
.vs-actions form{margin:0}
.vs-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:20px;font-weight:700;letter-spacing:.02em;overflow-wrap:anywhere}
.vs-url{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}
.vs-check{display:flex;gap:8px;align-items:flex-start;font-size:14px}
.vs-check input{margin-top:3px}
.vs-pager{display:flex;flex-wrap:wrap;align-items:center;gap:var(--sp-2,8px)}
.vs-name{font-weight:700}
.vs-inline{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0}
.vs-inline .ek-select{width:auto;min-width:9rem}
.ek-table td .vs-inline .ek-select{min-height:34px;padding:4px 8px}
</style>
CSS;
    }

    function visitors_status_label(string $status): string
    {
        return match ($status) {
            'new' => 'New',
            'reviewed' => 'Reviewed',
            'duplicate' => 'Duplicate',
            'promoted' => 'Promoted',
            'rejected' => 'Rejected',
            default => ucfirst($status),
        };
    }

    function visitors_status_badge(string $status): string
    {
        $tone = match ($status) {
            'promoted' => ' is-ok',
            'duplicate' => ' is-warn',
            'rejected' => ' is-error',
            default => '',
        };

        return '<span class="ek-badge' . $tone . '">' . visitors_e(visitors_status_label($status)) . '</span>';
    }

    /** 'Sun, Sep 20, 2026' (and ' · 10:30 AM' when a time is given). */
    function visitors_date(?string $date, ?string $time = null): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        $out = date('D, M j, Y', $ts);
        if ($time !== null && $time !== '' && $time !== '00:00') {
            $t = strtotime($date . ' ' . $time);
            if ($t !== false) {
                $out .= ' · ' . date('g:i A', $t);
            }
        }

        return $out;
    }

    /** A stored local timestamp as 'Sep 20, 2026, 10:30 AM'. */
    function visitors_when(?string $stamp): string
    {
        $ts = $stamp ? strtotime($stamp) : false;

        return $ts === false ? '' : date('M j, Y, g:i A', $ts);
    }

    /** Scheme, host and base path of this portal, for links other sites use. */
    function visitors_origin(string $basePath): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';

        return ($https ? 'https' : 'http') . '://' . $host . rtrim($basePath, '/');
    }

    function visitors_rsvp_url(string $basePath, int $eventId): string
    {
        return visitors_origin($basePath) . '/events_rsvp/event.php?event_id=' . $eventId;
    }

    /**
     * The links the church website points at: the guest sign-up forms and
     * each event's RSVP page.
     *
     * @param list<array<string,mixed>> $upcomingEvents
     */
    function visitors_public_links_card(string $basePath, array $upcomingEvents): string
    {
        $origin = visitors_origin($basePath);
        $field = static fn (string $id, string $label, string $url, string $hint): string =>
            '<div class="ek-field"><label for="' . $id . '">' . visitors_e($label) . '</label>'
            . '<input class="ek-input vs-url" id="' . $id . '" type="text" readonly value="' . visitors_e($url) . '" onfocus="this.select()">'
            . '<span class="ek-hint">' . visitors_e($hint) . ' <a href="' . visitors_e($url) . '" target="_blank" rel="noopener">Open<span class="sr-only"> ' . visitors_e($label) . ' in a new tab</span></a></span></div>';

        $html = '<section class="ek-card" id="public-links" aria-labelledby="public-links-title">'
            . '<div class="ek-card-head"><div><h2 id="public-links-title">Public links</h2>'
            . '<p>Pages anyone can open without signing in. Link to them from the church website.</p></div></div>'
            . '<div class="ek-card-body"><div class="ek-form" style="max-width:none">'
            . $field('link-signup', 'Guest sign-up form', $origin . '/people_signup/', 'The short form for first-time and returning guests.')
            . $field('link-signup-full', 'Full registration form', $origin . '/people_signup/advanced.php', 'The longer form with address and background.')
            . '<div class="ek-field"><span class="ek-label">Event RSVP pages</span>'
            . '<span class="ek-hint">Each event has its own RSVP page: <span class="vs-url">' . visitors_e($origin) . '/events_rsvp/event.php?event_id=</span> followed by the event\'s number from Events. Responses are filed under the event\'s next scheduled date.</span></div>';

        if ($upcomingEvents === []) {
            $html .= '<p class="vs-muted">No events are scheduled in the next 60 days. <a href="' . visitors_e($basePath) . '/events/new">Add an event</a> to get an RSVP link.</p>';
        } else {
            $html .= '<div class="ek-table-wrap"><table class="ek-table"><caption class="sr-only">RSVP links for events in the next 60 days</caption>'
                . '<thead><tr><th scope="col">Event</th><th scope="col">Next date</th><th scope="col">RSVP link</th></tr></thead><tbody>';
            foreach ($upcomingEvents as $event) {
                $url = visitors_rsvp_url($basePath, (int) $event['id']);
                $html .= '<tr><td><a href="' . visitors_e($basePath) . '/events/' . (int) $event['id'] . '">' . visitors_e($event['title']) . '</a></td>'
                    . '<td>' . visitors_e(visitors_date(substr((string) $event['next_starts_at'], 0, 10), substr((string) $event['next_starts_at'], 11, 5))) . '</td>'
                    . '<td><input class="ek-input vs-url" type="text" readonly aria-label="RSVP link for ' . visitors_e($event['title']) . '" value="' . visitors_e($url) . '" onfocus="this.select()"></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        return $html . '</div></div></section>';
    }
}
