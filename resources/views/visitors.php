<?php
/**
 * Visitors & RSVPs › Visitors: the registration review queue.
 *
 * @var string $basePath
 * @var ?array $actor
 * @var array $campusSelector
 * @var bool $refused
 * @var bool $missing
 * @var bool $unavailable
 * @var mixed $flash
 * @var array $queue (when allowed)
 */
require_once __DIR__ . '/_visitors-kit.php';
$queue ??= null;   // absent when the page is refused

echo visitors_render([
    'basePath' => $basePath, 'activeId' => 'visitors',
    'title' => 'Visitors',
    'description' => 'Guest sign-ups and RSVPs from people who are not yet in the member records. Review each one, then promote it to a member record, link it to someone already there, or set it aside.',
    'actor' => $actor, 'campusSelector' => $campusSelector,
    'refused' => $refused, 'missing' => $missing, 'unavailable' => $unavailable, 'flash' => $flash,
], static function () use ($basePath, $queue): string {
    $base = visitors_e($basePath);
    $counts = $queue['counts'];
    $status = $queue['status'];
    $search = $queue['search'];
    $all = array_sum($counts);

    $link = static function (?string $s, string $q, int $page = 1) use ($base): string {
        // No status means New, the work waiting; 'all' asks for everything.
        $params = array_filter(['status' => $s === 'new' ? null : ($s ?? 'all'), 'q' => $q !== '' ? $q : null, 'page' => $page > 1 ? $page : null], static fn ($v) => $v !== null);
        return $base . '/visitors' . ($params !== [] ? '?' . visitors_e(http_build_query($params)) : '');
    };

    // Status filters, with counts.
    $filters = [
        ['new', 'New'], ['reviewed', 'Reviewed'], ['duplicate', 'Possible match / duplicate'],
        ['promoted', 'Promoted'], ['rejected', 'Rejected'], [null, 'All'],
    ];
    $html = '<nav class="ek-toolbar" aria-label="Filter registrations by status">';
    foreach ($filters as [$s, $label]) {
        $n = $s === null ? $all : (int) ($counts[$s] ?? 0);
        $html .= '<a class="ek-chip" href="' . $link($s, $search) . '"' . ($status === $s ? ' aria-pressed="true" aria-current="true"' : '') . '>'
            . visitors_e($label) . ' <span class="ek-badge">' . $n . '</span></a>';
    }
    $html .= '</nav>';

    $html .= '<form class="ek-toolbar" method="get" action="' . $base . '/visitors" role="search">'
        . '<input type="hidden" name="status" value="' . visitors_e($status ?? 'all') . '">'
        . '<label class="sr-only" for="vs-q">Search registrations</label>'
        . '<input class="ek-input" style="max-width:22rem" id="vs-q" type="search" name="q" value="' . visitors_e($search) . '" placeholder="Name, email, phone or town">'
        . '<button class="ek-btn" type="submit">Search</button>'
        . ($search !== '' ? '<a class="ek-btn ek-btn-quiet" href="' . $link($status, '') . '">Clear</a>' : '')
        . '<span class="ek-spacer"></span>'
        . '<a class="ek-btn ek-btn-quiet" href="' . $base . '/visitors/access#public-links">Public links</a>'
        . '</form>';

    $rows = $queue['rows'];
    if ($rows === []) {
        if ($search !== '') {
            $html .= '<div class="ek-empty"><strong>No registrations match “' . visitors_e($search) . '”</strong>'
                . '<p>Try part of a name, an email address or a phone number.</p>'
                . '<a class="ek-btn" href="' . $link($status, '') . '">Clear the search</a></div>';
        } elseif ($all === 0) {
            $html .= '<div class="ek-empty"><strong>No registrations yet</strong>'
                . '<p>Guests appear here when they fill in the sign-up form or RSVP to an event. Link to those pages from the church website.</p>'
                . '<a class="ek-btn ek-btn-primary" href="' . $base . '/visitors/access#public-links">See the public links</a></div>';
        } elseif ($status === 'new') {
            $html .= '<div class="ek-empty"><strong>Nothing new to review</strong>'
                . '<p>Every registration has been looked at.</p>'
                . '<a class="ek-btn" href="' . $link('reviewed', '') . '">See reviewed registrations</a></div>';
        } else {
            $html .= '<div class="ek-empty"><strong>No registrations marked ' . visitors_e(strtolower(visitors_status_label((string) $status))) . '</strong>'
                . '<p>Registrations appear here once a reviewer gives them this status.</p>'
                . '<a class="ek-btn" href="' . $link(null, '') . '">See all registrations</a></div>';
        }

        return $html;
    }

    $html .= '<div class="ek-card"><div class="ek-table-wrap"><table class="ek-table">'
        . '<caption class="sr-only">Registrations' . ($status !== null ? ': ' . visitors_e(visitors_status_label($status)) : '') . '</caption>'
        . '<thead><tr><th scope="col">Name</th><th scope="col">Came through</th><th scope="col">Contact</th><th scope="col">Received</th><th scope="col">Status</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $name = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']);
        $contact = array_filter([(string) ($r['email'] ?? ''), (string) ($r['phone'] ?? ''), (string) ($r['city'] ?? '')], static fn ($v) => trim($v) !== '');
        $match = '';
        if ($r['matched_person'] !== null && $r['status'] !== 'promoted') {
            $match = '<div class="vs-small vs-muted">Looks like ' . visitors_e($r['matched_person']['name']) . '</div>';
        } elseif ($r['matched_person'] !== null) {
            $match = '<div class="vs-small vs-muted">Member record: ' . visitors_e($r['matched_person']['name']) . '</div>';
        }
        $html .= '<tr>'
            . '<td><a class="vs-name" href="' . $base . '/visitors/' . (int) $r['id'] . '">' . visitors_e($name !== '' ? $name : 'Registration #' . (int) $r['id']) . '</a>' . $match . '</td>'
            . '<td>' . visitors_e(match ((string) $r['source']) { 'rsvp' => 'Event RSVP', 'greeter' => 'Greeter', 'import' => 'Import', default => 'Sign-up form' })
            . ($r['reason_for_visit'] ? '<div class="vs-small vs-muted">' . visitors_e($r['reason_for_visit']) . '</div>' : '') . '</td>'
            . '<td>' . implode('<br>', array_map('visitors_e', $contact)) . '</td>'
            . '<td>' . visitors_e(visitors_when((string) $r['created_at'])) . '</td>'
            . '<td>' . visitors_status_badge((string) $r['status']) . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table></div></div>';

    if ($queue['pages'] > 1) {
        $page = $queue['page'];
        $html .= '<nav class="vs-pager" aria-label="Pages">'
            . ($page > 1 ? '<a class="ek-btn" href="' . $link($status, $search, $page - 1) . '">Previous</a>' : '')
            . '<span class="vs-muted">Page ' . $page . ' of ' . $queue['pages'] . ' · ' . (int) $queue['total'] . ' registrations</span>'
            . ($page < $queue['pages'] ? '<a class="ek-btn" href="' . $link($status, $search, $page + 1) . '">Next</a>' : '')
            . '</nav>';
    }

    return $html;
});
