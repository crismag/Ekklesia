<?php

declare(strict_types=1);

/**
 * Standalone-scaffold front controller.
 *
 * Resolves "<METHOD> <PATH>" against routes/api.php + routes/web.php and
 * invokes the matched closure with a merged request array containing
 * query, body, cookies, and headers. JSON-encodes API responses; renders
 * web responses as HTML or JSON based on closure return type.
 *
 * Compatible with PHP's built-in dev server:
 *   php -S 127.0.0.1:8080 -t public public/index.php
 *
 * In a Laravel install this is replaced by Illuminate's HTTP kernel; the
 * route closures defined in routes/*.php are reused there as Route handlers.
 */

use App\Core\Config\EnvLoader;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Load .env early so PORTAL_BASE_PATH is available before route lookup.
EnvLoader::loadOnce(__DIR__ . '/../.env');

// A release is landing: hold requests rather than serve new code against a
// schema its migration has not reached yet. Deployment overwrites files in
// place, so this window is real and has broken this site twice. One stat() per
// request, and the flag expires on its own so a half-finished deploy cannot
// leave the site dark.
if (\App\Core\Maintenance::isActive()) {
    http_response_code(503);
    header('Retry-After: ' . \App\Core\Maintenance::retryAfter());
    header('Cache-Control: no-store');
    $wantsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/');
    $reason = \App\Core\Maintenance::reason();
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'The portal is briefly unavailable while an update is applied.',
            'kind' => 'maintenance',
            'reason' => $reason,
        ]);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Back shortly — Ekklesia</title>'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;'
        . 'font:16px/1.6 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;'
        . 'color:#1b3228;background:#f4f8f6;padding:24px}'
        . 'main{max-width:26rem;text-align:center}h1{font-size:20px;margin:0 0 8px}'
        . 'p{margin:0;color:#4b5c53}</style></head><body><main>'
        . '<h1>Back in a moment</h1><p>The church portal is being updated. '
        . 'This page refreshes itself; nothing has been lost.</p>'
        . ($safeReason !== '' ? '<p>' . $safeReason . '</p>' : '')
        . '</main><script>setTimeout(function(){location.reload();}, 15000);</script>'
        . '</body></html>';
    return;
}

/**
 * Base path under which the portal is mounted (e.g. "/church_portal" when
 * served behind nginx as `christlikeness.local/church_portal/`).
 *   - Primary source: PORTAL_BASE_PATH env var (set in .env or via fastcgi_param).
 *   - Fallback: derived from SCRIPT_NAME ('/church_portal/index.php' → '/church_portal').
 *   - Empty string for the PHP built-in dev server (php -S).
 */
$basePath = rtrim((string) (EnvLoader::get('PORTAL_BASE_PATH') ?? ''), '/');
if ($basePath === '' && isset($_SERVER['SCRIPT_NAME'])) {
    $scriptName = (string) $_SERVER['SCRIPT_NAME'];
    if (preg_match('#^(.*?)/(?:public/)?index\.php$#', $scriptName, $m) && $m[1] !== '') {
        $basePath = $m[1];
    }
}

// PHP -S: serve static asset files (css, js, images, etc.) directly when present.
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $candidate = __DIR__ . $requested;
    if ($requested !== '/' && is_file($candidate)) {
        return false;
    }
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path   = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
$path   = '/' . ltrim($path, '/');

// Strip the base-path prefix so route keys can stay absolute ("/api/login").
if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $path = substr($path, strlen($basePath));
    if ($path === '' || $path === false) {
        $path = '/';
    }
}
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

$routes = array_merge(
    require __DIR__ . '/../routes/api.php',
    require __DIR__ . '/../routes/web.php',
);

$key = "$method $path";
$pathParams = [];

if (isset($routes[$key])) {
    $handler = $routes[$key];
} else {
    // Try parameterized route keys, e.g. 'DELETE /api/availability/{id}'.
    // We compile each `{name}` segment to `(?P<name>[^/]+)` once per request,
    // which is cheap given the route table size.
    $handler = null;
    foreach ($routes as $routeKey => $routeHandler) {
        if (!str_contains($routeKey, '{')) {
            continue;
        }
        if (!str_starts_with($routeKey, $method . ' ')) {
            continue;
        }
        $routePath = substr($routeKey, strlen($method) + 1);
        // preg_quote escapes `.`/`/`/`{`/`}` etc. so literal segments stay safe;
        // we then turn the escaped placeholder `\{name\}` into a named group.
        $quoted  = preg_quote($routePath, '#');
        $pattern = preg_replace('/\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}/', '(?P<$1>[^/]+)', $quoted);
        $regex   = '#^' . $pattern . '$#';
        if (preg_match($regex, $path, $m)) {
            foreach ($m as $k => $v) {
                if (!is_int($k)) {
                    $pathParams[$k] = $v;
                }
            }
            $handler = $routeHandler;
            break;
        }
    }
    if ($handler === null) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => "No route for $method $path"]);
        return;
    }
}

$rawBody = file_get_contents('php://input') ?: '';
$bodyParams = [];
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (str_contains($contentType, 'application/json') && $rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $bodyParams = $decoded;
    }
} else {
    $bodyParams = $_POST;
}

$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
$normalizedHeaders = [];
foreach ($headers as $hk => $hv) {
    $normalizedHeaders[strtolower((string) $hk)] = (string) $hv;
}

$request = array_merge(
    $_GET,
    $bodyParams,
    $pathParams,                  // path params win over body/query of the same name
    [
        'cookies'        => $_COOKIE,
        'authorization'  => $normalizedHeaders['authorization'] ?? null,
        '_remote_addr'   => $_SERVER['REMOTE_ADDR'] ?? null,
        '_user_agent'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
        '_method'        => $method,
        '_path'          => $path,
        '_base_path'     => $basePath,
    ],
);

try {
    $result = $handler($request);
} catch (PermissionDenied $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(401);
    header('Content-Type: application/json');
        $payload = ['error' => $e->getMessage(), 'kind' => 'validation_failed'];
        if (method_exists($e, 'getErrors')) {
            $payload['errors'] = $e->getErrors();
        }
        echo json_encode($payload);
    return;
} catch (\App\Exceptions\TooManyAttempts $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(429);
    header('Retry-After: ' . $e->retryAfterSeconds);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage(), 'kind' => 'too_many_attempts']);
    return;
} catch (ValidationFailed $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage(), 'kind' => 'validation_failed']);
    return;
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log(sprintf(
        '[church_portal] %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    http_response_code(500);
    header('Content-Type: application/json');
    $payload = ['error' => 'Internal server error', 'kind' => 'server_error'];
    if (filter_var(getenv('APP_DEBUG') ?: '', FILTER_VALIDATE_BOOLEAN)) {
        $payload['detail']  = $e->getMessage();
        $payload['where']   = $e->getFile() . ':' . $e->getLine();
    }
    echo json_encode($payload);
    return;
}

if (is_string($result)) {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo $result;
    return;
}

if (!headers_sent()) {
    header('Content-Type: application/json');
    // Every JSON response here is live, per-actor, per-campus data. None of it
    // carried a caching directive, which left the browser free to decide for
    // itself — and it does: a deleted event went on appearing in the calendar
    // feed because the feed was answered from cache rather than from the
    // database it had already been removed from.
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}
echo json_encode($result);
