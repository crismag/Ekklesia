<?php
/**
 * Visitors & RSVPs › RSVPs: responses for one event date, with attendance.
 *
 * @var string $basePath
 * @var ?array $actor
 * @var array $campusSelector
 * @var bool $refused
 * @var bool $missing
 * @var bool $unavailable
 * @var mixed $flash
 * @var ?array $overview
 */
require_once __DIR__ . '/_visitors-kit.php';
$overview ??= null;   // absent when the page is refused

echo visitors_render([
    'basePath' => $basePath, 'activeId' => 'rsvps',
    'title' => 'RSVPs',
    'description' => 'Who said they are coming to each event date, and who came. Mark attendance on the day.',
    'actor' => $actor, 'campusSelector' => $campusSelector,
    'refused' => $refused, 'missing' => $missing, 'unavailable' => $unavailable, 'flash' => $flash,
], static function () use ($basePath, $overview): string {
    $base = visitors_e($basePath);
    $occasions = $overview['occasions'];
    $selected = $overview['selected'];

    if ($selected === null) {
        return '<div class="ek-empty"><strong>No RSVPs yet</strong>'
            . '<p>Responses appear here once someone uses an event\'s RSVP page. Each event has its own link for the church website.</p>'
            . '<a class="ek-btn ek-btn-primary" href="' . $base . '/visitors/access#public-links">Get RSVP links</a></div>';
    }

    // ---- Event date picker ------------------------------------------------------
    $groups = ['Upcoming' => '', 'Past' => ''];
    foreach ($occasions as $o) {
        $label = $o['title'] . ($o['date'] ? ' — ' . visitors_date($o['date'], $o['time']) : '') . ' (' . (int) $o['responses'] . ')';
        $groups[$o['upcoming'] ? 'Upcoming' : 'Past'] .= '<option value="' . visitors_e($o['key']) . '"' . ($o['key'] === $selected['key'] ? ' selected' : '') . '>' . visitors_e($label) . '</option>';
    }
    $options = '';
    foreach ($groups as $g => $opts) {
        if ($opts !== '') {
            $options .= '<optgroup label="' . $g . '">' . $opts . '</optgroup>';
        }
    }
    $html = '<form class="ek-toolbar" method="get" action="' . $base . '/visitors/rsvps">'
        . '<label class="ek-label" for="vs-occasion">Event date</label>'
        . '<select class="ek-select" style="width:auto;max-width:100%" id="vs-occasion" name="occasion" onchange="this.form.submit()">' . $options . '</select>'
        . '<noscript><button class="ek-btn" type="submit">Show</button></noscript>'
        . '</form>';

    $html .= '<div class="ek-toolbar"><strong>' . visitors_e($selected['title']) . '</strong>'
        . '<span class="vs-muted">' . visitors_e(visitors_date($selected['date'], $selected['time'])) . '</span>'
        . ($selected['event_exists']
            ? '<a class="ek-btn ek-btn-quiet" href="' . $base . '/events/' . (int) $selected['event_id'] . '">Event details</a>'
              . '<a class="ek-btn ek-btn-quiet" href="' . visitors_e(visitors_rsvp_url($basePath, (int) $selected['event_id'])) . '" target="_blank" rel="noopener">RSVP page<span class="sr-only"> (opens in a new tab)</span></a>'
            : '<span class="ek-badge is-warn">No longer in the calendar</span>')
        . '</div>';

    $t = $overview['totals'];
    $stat = static fn (string $label, int $n, string $hint = ''): string =>
        '<div class="ek-stat"><span class="ek-stat-label">' . visitors_e($label) . '</span><span class="ek-stat-value">' . $n . '</span>'
        . ($hint !== '' ? '<span class="vs-small vs-muted">' . visitors_e($hint) . '</span>' : '') . '</div>';
    $html .= '<div class="ek-stats">'
        . $stat('Yes', $t['yes']) . $stat('Maybe', $t['maybe']) . $stat('No', $t['no'])
        . $stat('Expected', $t['expected'], 'people, counting parties')
        . $stat('Checked in', $t['checked_in'], 'people, counting parties')
        . $stat('No-shows', $t['no_show'])
        . '</div>';

    $html .= '<div class="ek-card"><div class="ek-table-wrap"><table class="ek-table">'
        . '<caption class="sr-only">RSVPs for ' . visitors_e($selected['title']) . '</caption>'
        . '<thead><tr><th scope="col">Name</th><th scope="col">Response</th><th scope="col" class="is-num">Party</th><th scope="col">Contact and notes</th><th scope="col">Member or visitor</th><th scope="col">Attendance</th></tr></thead><tbody>';
    $marks = ['registered' => 'Registered', 'checked_in' => 'Checked in', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];
    foreach ($overview['rows'] as $r) {
        $rid = (int) $r['id'];
        $name = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']);
        if ((int) ($r['person_id'] ?? 0) > 0) {
            $pid = (int) $r['person_id'];
            $who = '<span class="ek-badge is-ok">Member</span><div class="vs-small"><a href="' . $base . '/admin/people/view?id=' . $pid . '">'
                . visitors_e($r['person']['name'] ?? ('Member #' . $pid)) . '</a></div>';
        } elseif ((int) ($r['visitor_registration_id'] ?? 0) > 0) {
            $who = '<span class="ek-badge">Visitor</span><div class="vs-small"><a href="' . $base . '/visitors/' . (int) $r['visitor_registration_id'] . '">Registration #' . (int) $r['visitor_registration_id'] . '</a></div>';
        } else {
            $who = '<span class="ek-badge">Visitor</span>';
        }
        $contact = array_filter([(string) ($r['email'] ?? ''), (string) ($r['phone'] ?? ''), (string) ($r['city'] ?? '')], static fn ($v) => trim($v) !== '');
        $select = '';
        foreach ($marks as $value => $label) {
            $select .= '<option value="' . $value . '"' . ($r['attendance'] === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $html .= '<tr>'
            . '<td class="vs-name">' . visitors_e($name !== '' ? $name : 'RSVP #' . $rid) . '</td>'
            . '<td><span class="ek-badge' . ($r['response'] === 'yes' ? ' is-ok' : ($r['response'] === 'no' ? ' is-error' : ' is-warn')) . '">' . visitors_e(ucfirst((string) $r['response'])) . '</span></td>'
            . '<td class="is-num">' . (int) $r['party_size'] . '</td>'
            . '<td>' . implode('<br>', array_map('visitors_e', $contact))
            . ($r['notes'] ? '<div class="vs-small vs-muted">“' . visitors_e($r['notes']) . '”</div>' : '') . '</td>'
            . '<td>' . $who . '</td>'
            . '<td><form class="vs-inline" method="post" action="' . $base . '/visitors/rsvps">'
            . '<input type="hidden" name="rsvp_id" value="' . $rid . '">'
            . '<input type="hidden" name="occasion" value="' . visitors_e($selected['key']) . '">'
            . '<label class="sr-only" for="vs-att-' . $rid . '">Attendance for ' . visitors_e($name) . '</label>'
            . '<select class="ek-select" id="vs-att-' . $rid . '" name="attendance">' . $select . '</select>'
            . '<button class="ek-btn" type="submit">Save</button>'
            . '</form></td>'
            . '</tr>';
    }
    $html .= '</tbody></table></div></div>';

    return $html;
});
