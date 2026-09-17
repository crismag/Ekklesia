<?php
/**
 * Site gate for a private Ekklesia deployment (for example the demo on real data).
 *
 * The browser asks for the site password once; a session cookie then lets every
 * request through until the browser is closed. The server-only
 * .htaccess-site-lock sends every request without that cookie here.
 *
 * Install (server only, never deployed from the repository):
 *   - copy this file to the site root as site-gate.php
 *   - create the settings file in the account's home folder, three levels above the site root (see SETTINGS below):
 *       <?php return ['token' => '<64 hex>', 'password_hash' => '<password_hash()>', 'name' => 'Ekklesia demo'];
 *   - put the same token in .htaccess-site-lock (see tools/site-gate/README.md)
 */
declare(strict_types=1);

// public_html → the domain folder → domains → the account's home folder.
define('SETTINGS', dirname(__DIR__, 3) . '/.ekklesia-site-gate.php');
const COOKIE = 'ekklesia_site';

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

$settings = is_file(SETTINGS) ? require SETTINGS : null;
if (!is_array($settings) || empty($settings['token']) || empty($settings['password_hash'])) {
    http_response_code(503);
    exit('This site is locked and its gate is not configured.');
}
$name = (string) ($settings['name'] ?? 'Private site');

// Where to go afterwards: only a path on this site.
$back = (string) ($_POST['back'] ?? $_SERVER['REQUEST_URI'] ?? '/');
if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//') || str_starts_with($back, '/site-gate.php')) {
    $back = '/';
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (password_verify((string) ($_POST['password'] ?? ''), (string) $settings['password_hash'])) {
        setcookie(COOKIE, (string) $settings['token'], [
            'expires'  => 0,          // until the browser is closed
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        header('Location: ' . $back, true, 303);
        exit;
    }
    usleep(800000);
    $error = 'That password is not right.';
    http_response_code(401);
}

// Background requests (the portal's own API calls) get a plain answer, not a page.
$accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
    http_response_code(401);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'This site is locked. Reload the page to enter the site password.']));
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(401);
}
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($name) ?></title>
<style>
  :root { color-scheme: light; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0f3a2e; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #17211b; padding: 16px; box-sizing: border-box; }
  form { background: #fff; border-radius: 12px; padding: 28px; width: min(360px, 100%); box-shadow: 0 16px 40px rgba(0,0,0,.25); box-sizing: border-box; }
  h1 { margin: 0 0 6px; font-size: 22px; }
  p { margin: 0 0 18px; color: #4f5d55; font-size: 14px; }
  label { display: block; font-weight: 700; font-size: 14px; margin-bottom: 6px; }
  input { width: 100%; box-sizing: border-box; font: inherit; padding: 11px 12px; border: 1px solid #c7d4cd; border-radius: 8px; }
  input:focus-visible, button:focus-visible { outline: 3px solid #1f8a70; outline-offset: 2px; }
  button { margin-top: 14px; width: 100%; font: inherit; font-weight: 700; padding: 11px 12px; border: 0; border-radius: 8px; background: #0f5a45; color: #fff; cursor: pointer; }
  .err { margin: 0 0 12px; padding: 8px 10px; border-radius: 8px; background: #fdecea; color: #8a1c14; font-size: 14px; }
</style>
</head>
<body>
<form method="post" action="/site-gate.php">
  <h1><?= $e($name) ?></h1>
  <p>This site is private. Enter the site password once; it is remembered until you close the browser.</p>
  <?php if ($error !== ''): ?><div class="err" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <label for="password">Site password</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
  <input type="hidden" name="back" value="<?= $e($back) ?>">
  <button type="submit">Continue</button>
</form>
</body>
</html>
