<?php

declare(strict_types=1);

/**
 * Directory entry point for the standalone RSVP app.
 *
 * Without this, a request for /events_rsvp/ had no index to serve: Apache
 * returned a bare 403 (and, before the routing fix, the portal front controller
 * swallowed it and answered with a JSON "no route" error).
 *
 * RSVP is meaningless without an event, and event.php already renders a proper
 * styled "Event not available" page when it has no usable event_id — so this
 * simply forwards there, preserving any query string. No new landing app, no
 * behaviour change, and no authorization change: event.php enforces exactly as
 * before.
 *
 * The target is a sibling file, never this directory, so no redirect loop is
 * possible.
 */

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'event.php' . ($query !== '' ? '?' . $query : '');

header('Location: ' . $target, true, 302);
echo '<!doctype html><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>Event RSVP</title>'
    . '<p>Redirecting to the <a href="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">event RSVP page</a>.</p>';
