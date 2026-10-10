<?php

declare(strict_types=1);

/**
 * Personal account, schedule and notes are not church administration.
 * The old /admin/me "My Pages" screen duplicated /account; keep the URL
 * reachable so bookmarks do not 404, and send people to Account.
 *
 * @var string $basePath
 */
$account = rtrim((string) ($basePath ?? ''), '/') . '/account';
if (!headers_sent()) {
    header('Location: ' . $account, true, 302);
    header('Cache-Control: no-store');
}

$safe = htmlspecialchars($account, ENT_QUOTES, 'UTF-8');
echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    . '<meta http-equiv="refresh" content="0;url=' . $safe . '">'
    . '<title>Redirecting to Account</title></head><body>'
    . '<p>Personal account settings live under Account, not Administration. '
    . '<a href="' . $safe . '">Continue to Account</a>.</p>'
    . '</body></html>';
