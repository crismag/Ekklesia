<?php
/**
 * Visitors & RSVPs › one registration: what they gave us, how they reached us,
 * who they may already be, and the review decision.
 *
 * @var string $basePath
 * @var ?array $actor
 * @var array $campusSelector
 * @var bool $refused
 * @var bool $missing
 * @var bool $unavailable
 * @var mixed $flash
 * @var ?array $record
 */
require_once __DIR__ . '/_visitors-kit.php';
$record ??= null;   // absent when refused or not found

$reg = $record['registration'] ?? null;
$name = $reg !== null ? trim((string) $reg['first_name'] . ' ' . (string) $reg['last_name']) : '';

echo visitors_render([
    'basePath' => $basePath, 'activeId' => 'visitors',
    'title' => $reg !== null ? ($name !== '' ? $name : 'Registration #' . (int) $reg['id']) : 'Registration',
    'description' => $reg !== null
        ? 'Registration #' . (int) $reg['id'] . ', received ' . visitors_when((string) $reg['created_at']) . '.'
        : '',
    'actor' => $actor, 'campusSelector' => $campusSelector,
    'refused' => $refused, 'missing' => $missing, 'unavailable' => $unavailable, 'flash' => $flash,
], static function () use ($basePath, $record, $reg, $name): string {
    $base = visitors_e($basePath);
    $id = (int) $reg['id'];
    $status = (string) $reg['status'];
    $action = $base . '/visitors/' . $id;
    $people = $record['people'];
    $personLink = static fn (int $pid, string $label): string =>
        '<a href="' . $base . '/admin/people/view?id=' . $pid . '">' . visitors_e($label) . '</a>';

    $html = '<p class="ek-crumbs"><a href="' . $base . '/visitors' . ($status !== 'new' ? '?status=' . visitors_e($status) : '') . '">&larr; ' . visitors_e(visitors_status_label($status)) . ' registrations</a></p>';
    $html .= '<div class="vs-layout"><div class="vs-stack">';

    // ---- What they submitted -------------------------------------------------
    $months = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $birth = trim(implode(' ', array_filter([
        $months[(int) $reg['birth_month']] ?? '',
        (int) $reg['birth_day'] > 0 ? (string) (int) $reg['birth_day'] . ((int) $reg['birth_year'] > 0 ? ',' : '') : '',
        (int) $reg['birth_year'] > 0 ? (string) (int) $reg['birth_year'] : '',
    ])));
    $address = implode(', ', array_filter(array_map(static fn ($v) => trim((string) $v), [
        $reg['address_line1'], $reg['address_line2'], $reg['city'], $reg['region'], $reg['postal_code'], $reg['country'],
    ])));
    $facts = [
        'Full name' => trim(implode(' ', array_filter([(string) $reg['first_name'], (string) $reg['middle_name'], (string) $reg['last_name']]))),
        'Preferred name' => (string) $reg['preferred_name'],
        'Gender' => $reg['gender'] ? ucfirst((string) $reg['gender']) : '',
        'Birth' => $birth,
        'Married' => $reg['is_married'] === null ? '' : ((int) $reg['is_married'] === 1 ? 'Yes' : 'No'),
        'Email' => (string) $reg['email'],
        'Phone' => (string) $reg['phone'],
        'Address' => $address,
        'Facebook' => (string) $reg['facebook'],
        'LinkedIn' => (string) $reg['linkedin'],
        'X / Twitter' => (string) $reg['twitter'],
        'Member type' => (string) $reg['member_type_name'],
        'Reason for visit' => (string) $reg['reason_for_visit'],
        'Invited by' => (string) $reg['invited_by'],
        'Church background' => (string) $reg['church_background'],
        'Visit notes' => (string) $reg['visit_notes'],
    ];
    $html .= '<section class="ek-card" aria-labelledby="vs-submitted"><div class="ek-card-head"><div><h2 id="vs-submitted">What they gave us</h2>'
        . '<p>As submitted. Only what they filled in is shown.</p></div></div><div class="ek-card-body"><dl class="vs-facts">';
    foreach ($facts as $label => $value) {
        if (trim($value) === '') {
            continue;
        }
        $html .= '<dt>' . visitors_e($label) . '</dt><dd>' . nl2br(visitors_e($value)) . '</dd>';
    }
    $html .= '</dl></div></section>';

    // ---- How they reached us -------------------------------------------------
    $source = match ((string) $reg['source']) {
        'rsvp' => 'Responded to an event RSVP page',
        'greeter' => 'Entered by a greeter',
        'import' => 'Imported',
        default => 'Filled in the guest sign-up form',
    };
    $html .= '<section class="ek-card" aria-labelledby="vs-reached"><div class="ek-card-head"><div><h2 id="vs-reached">How they reached us</h2></div></div><div class="ek-card-body">'
        . '<p style="margin:0">' . visitors_e($source)
        . ($record['source_event'] !== null ? ' for <a href="' . $base . '/events/' . (int) $record['source_event']['id'] . '">' . visitors_e($record['source_event']['title']) . '</a>' : '')
        . ', ' . visitors_e(visitors_when((string) $reg['created_at'])) . '.</p>';
    if ($record['rsvps'] !== []) {
        $html .= '<div class="ek-table-wrap" style="margin-top:12px"><table class="ek-table"><caption class="sr-only">RSVPs from this registration</caption>'
            . '<thead><tr><th scope="col">Event</th><th scope="col">Response</th><th scope="col" class="is-num">Party</th><th scope="col">Attendance</th></tr></thead><tbody>';
        foreach ($record['rsvps'] as $rsvp) {
            $key = (int) $rsvp['event_id'] . ':' . ($rsvp['occurrence_id'] !== null ? (int) $rsvp['occurrence_id'] : '');
            $html .= '<tr><td><a href="' . $base . '/visitors/rsvps?occasion=' . visitors_e(rawurlencode($key)) . '">' . visitors_e($rsvp['title']) . '</a>'
                . '<div class="vs-small vs-muted">' . visitors_e(visitors_date($rsvp['date'], $rsvp['time'])) . '</div></td>'
                . '<td>' . visitors_e(ucfirst((string) $rsvp['response'])) . '</td>'
                . '<td class="is-num">' . (int) $rsvp['party_size'] . '</td>'
                . '<td>' . visitors_e(ucfirst(str_replace('_', ' ', (string) $rsvp['attendance']))) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div></section>';

    // ---- Possible matches in the member records --------------------------------
    $matches = $record['matches'];
    $canPromote = $record['promotion_block'] === null;
    $html .= '<section class="ek-card" aria-labelledby="vs-matches"><div class="ek-card-head"><div><h2 id="vs-matches">Already in the member records?</h2>'
        . '<p>Compared by name, email, phone and birth month and year. A likely match shares their own name plus one of those. A household often shares an email or phone, so a shared contact alone is only a possible match.</p></div></div><div class="ek-card-body">';
    if ($matches['exact'] === [] && $matches['possible'] === []) {
        $html .= '<p class="vs-muted" style="margin:0">No one in the member records looks like this person.</p>';
    } else {
        $html .= '<ul class="vs-list">';
        foreach (['exact' => 'Likely the same person', 'possible' => 'Possible match'] as $level => $levelLabel) {
            foreach ($matches[$level] as $m) {
                $pid = (int) $m['id'];
                $mname = trim((string) $m['first_name'] . ' ' . (string) $m['last_name']);
                $html .= '<li class="vs-match"><div><strong>' . $personLink($pid, $mname !== '' ? $mname : 'Member #' . $pid) . '</strong>'
                    . ' <span class="ek-badge' . ($level === 'exact' ? ' is-warn' : '') . '">' . $levelLabel . '</span>'
                    . ($m['city'] !== '' ? '<div class="vs-small vs-muted">' . visitors_e($m['city']) . ' · member #' . $pid . '</div>' : '<div class="vs-small vs-muted">Member #' . $pid . '</div>')
                    . '<div class="vs-why">Same ' . visitors_e(implode(', ', $m['why'])) . '</div></div>';
                if ($canPromote) {
                    $html .= '<form method="post" action="' . $action . '">'
                        . '<input type="hidden" name="action" value="promote"><input type="hidden" name="mode" value="link">'
                        . '<input type="hidden" name="person_id" value="' . $pid . '">'
                        . '<button class="ek-btn" type="submit">Link to this person</button></form>';
                }
                $html .= '</li>';
            }
        }
        $html .= '</ul>';
    }
    $html .= '</div></section>';

    // ---- Other registrations that look like this one ----------------------------
    $dupes = array_merge(
        array_map(static fn ($d) => $d + ['level' => 'exact'], $record['duplicates']['exact']),
        array_map(static fn ($d) => $d + ['level' => 'possible'], $record['duplicates']['possible']),
    );
    if ($dupes !== []) {
        $html .= '<section class="ek-card" aria-labelledby="vs-dupes"><div class="ek-card-head"><div><h2 id="vs-dupes">Other registrations like this one</h2>'
            . '<p>Open registrations that may be the same person signing up again.</p></div></div><div class="ek-card-body"><ul class="vs-list">';
        foreach ($dupes as $d) {
            $dname = trim((string) $d['first_name'] . ' ' . (string) $d['last_name']);
            $html .= '<li class="vs-match"><div><a class="vs-name" href="' . $base . '/visitors/' . (int) $d['id'] . '">' . visitors_e($dname) . '</a> '
                . visitors_status_badge((string) $d['status'])
                . '<div class="vs-small vs-muted">Received ' . visitors_e(visitors_when((string) $d['created_at'])) . ' · same ' . visitors_e(implode(', ', $d['why'])) . '</div></div></li>';
        }
        $html .= '</ul></div></section>';
    }

    $html .= '</div><div class="vs-stack">';

    // ---- Decision ---------------------------------------------------------------
    if ($status === 'promoted') {
        $html .= '<section class="ek-card" aria-labelledby="vs-promoted"><div class="ek-card-head"><div><h2 id="vs-promoted">Promoted</h2></div></div><div class="ek-card-body">';
        if ($record['promotions'] === [] && (int) $reg['matched_person_id'] > 0) {
            $pid = (int) $reg['matched_person_id'];
            $html .= '<p style="margin:0">Member record: ' . $personLink($pid, $people[$pid]['name'] ?? 'Member #' . $pid) . '.</p>';
        }
        foreach ($record['promotions'] as $p) {
            $pid = (int) $p['person_id'];
            $html .= '<p style="margin:0 0 8px">' . ($p['outcome'] === 'created' ? 'Created member record ' : 'Linked to member record ')
                . $personLink($pid, $people[$pid]['name'] ?? 'Member #' . $pid)
                . ' on ' . visitors_e(visitors_when((string) $p['promoted_at']))
                . ($p['promoted_by_account_id'] !== null ? ' by account #' . (int) $p['promoted_by_account_id'] : ' from the sign-up review page') . '.</p>'
                . ($p['notes'] ? '<p class="vs-small vs-muted" style="margin:0 0 8px">' . visitors_e($p['notes']) . '</p>' : '');
        }
        $html .= '</div></section>';
    } elseif ($canPromote) {
        $statusOptions = '';
        foreach ($record['membership_statuses'] as $i => $s) {
            $statusOptions .= '<option value="' . (int) $s['id'] . '"' . ($i === 0 ? ' selected' : '') . '>' . visitors_e($s['name']) . '</option>';
        }
        $campusOptions = '<option value="0">No campus yet</option>';
        foreach ($record['campuses'] as $c) {
            $campusOptions .= '<option value="' . (int) $c['id'] . '">' . visitors_e($c['name']) . '</option>';
        }
        $hasExact = $matches['exact'] !== [];
        $html .= '<section class="ek-card" aria-labelledby="vs-promote"><div class="ek-card-head"><div><h2 id="vs-promote">Promote to a member record</h2>'
            . '<p>' . ($matches['exact'] !== [] || $matches['possible'] !== []
                ? 'If this is someone listed under “Already in the member records?”, link it there instead.'
                : 'Creates a person in the member records from what they gave us.') . '</p></div></div>'
            . '<div class="ek-card-body"><form class="ek-form" method="post" action="' . $action . '">'
            . '<input type="hidden" name="action" value="promote"><input type="hidden" name="mode" value="create">'
            . '<div class="ek-field"><label for="vs-status">Membership status</label><select class="ek-select" id="vs-status" name="membership_status_id">' . $statusOptions . '</select></div>'
            . '<div class="ek-field"><label for="vs-campus">Campus</label><select class="ek-select" id="vs-campus" name="campus_id">' . $campusOptions . '</select></div>'
            . ($hasExact
                ? '<label class="vs-check"><input type="checkbox" name="force" value="1" required> <span>This is someone new, not the likely match above. Create a separate record anyway.</span></label>'
                : '')
            . '<div><button class="ek-btn ek-btn-primary" type="submit">Create member record</button></div>'
            . '</form></div></section>';
    } else {
        $html .= '<div class="ek-alert"><div>' . visitors_e((string) $record['promotion_block']) . '</div></div>';
    }

    if ($status !== 'promoted') {
        $decisions = [
            'reviewed' => ['Mark reviewed', 'ek-btn'],
            'duplicate' => ['Mark duplicate', 'ek-btn'],
            'rejected' => ['Reject', 'ek-btn ek-btn-danger'],
            'new' => ['Move back to new', 'ek-btn ek-btn-quiet'],
        ];
        $html .= '<section class="ek-card" aria-labelledby="vs-review"><div class="ek-card-head"><div><h2 id="vs-review">Review</h2>'
            . '<p>Now: ' . visitors_e(visitors_status_label($status))
            . ($reg['reviewed_at'] ? ', last decided ' . visitors_e(visitors_when((string) $reg['reviewed_at'])) : '') . '.</p></div></div>'
            . '<div class="ek-card-body"><div class="vs-actions">';
        foreach ($decisions as $value => [$label, $class]) {
            if ($value === $status) {
                continue;
            }
            $html .= '<form method="post" action="' . $action . '"><input type="hidden" name="action" value="status">'
                . '<input type="hidden" name="status" value="' . $value . '">'
                . '<button class="' . $class . '" type="submit">' . $label . '</button></form>';
        }
        $html .= '</div></div></section>';
    }

    $html .= '<section class="ek-card" aria-labelledby="vs-notes"><div class="ek-card-head"><div><h2 id="vs-notes">Reviewer notes</h2>'
        . '<p>For the people reviewing registrations. The guest never sees these.</p></div></div>'
        . '<div class="ek-card-body"><form class="ek-form" method="post" action="' . $action . '">'
        . '<input type="hidden" name="action" value="notes">'
        . '<div class="ek-field"><label class="sr-only" for="vs-notes-text">Reviewer notes</label>'
        . '<textarea class="ek-input" id="vs-notes-text" name="reviewer_notes" rows="5">' . visitors_e($reg['reviewer_notes']) . '</textarea></div>'
        . '<div><button class="ek-btn" type="submit">Save notes</button></div></form></div></section>';

    return $html . '</div></div>';
});
