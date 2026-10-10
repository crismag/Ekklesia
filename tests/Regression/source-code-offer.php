<?php

declare(strict_types=1);

/**
 * The source-code offer (AGPL-3.0-only): every installation links to /source,
 * which shows the operator's configured source for the running version or,
 * failing that, the upstream project labelled as a reference.
 *
 * Run: php tests/Regression/source-code-offer.php
 */

require_once __DIR__ . '/../../app/Core/Config/EnvLoader.php';
require_once __DIR__ . '/../../app/Services/SourceCodeOffer.php';

use App\Services\SourceCodeOffer;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

echo "What the operator configures\n";
$tag = new SourceCodeOffer('https://github.com/example-church/ekklesia/tree/v1.2.0', 'v1.2.0');
check('a configured https URL is this installation\'s source', $tag->isConfigured() && $tag->url() === 'https://github.com/example-church/ekklesia/tree/v1.2.0');
check('and its version label is shown', $tag->version() === 'v1.2.0');
check('an http URL is accepted for an intranet host', (new SourceCodeOffer('http://git.intranet.local/ekklesia.tar.gz'))->isConfigured());

echo "\nWhat is never shown\n";
check('no URL falls back to the upstream project', !(new SourceCodeOffer(null))->isConfigured() && (new SourceCodeOffer(null))->url() === SourceCodeOffer::UPSTREAM_URL);
check('a blank URL falls back too', !(new SourceCodeOffer('   '))->isConfigured());
check('a URL with credentials is refused, so a token never reaches visitors', !(new SourceCodeOffer('https://user:secret@git.example.org/ekklesia'))->isConfigured());
check('a token in the user part alone is refused', !(new SourceCodeOffer('https://ghp_abc123@github.com/x/y'))->isConfigured());
check('a non-web scheme is refused', !(new SourceCodeOffer('file:///var/www/ekklesia'))->isConfigured() && !(new SourceCodeOffer('javascript:alert(1)'))->isConfigured());
check('markup in the URL is refused', !(new SourceCodeOffer('https://example.org/"><script>'))->isConfigured());
check('an invalid version label is dropped, not shown', (new SourceCodeOffer('https://example.org/src', '<b>1</b>'))->version() === null);
check('a fallback never claims to be this installation\'s source', (new SourceCodeOffer('ftp://example.org/src'))->url() === SourceCodeOffer::UPSTREAM_URL);

echo "\nWhat the license says\n";
check('the identifier is AGPL-3.0-only', SourceCodeOffer::LICENSE_SPDX === 'AGPL-3.0-only');

echo "\nWhere the link appears\n";
$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root . '/routes/web.php');
check('GET /source is a route', str_contains($routes, "'GET /source'"));
check('and reads the operator\'s configuration', str_contains($routes, 'SourceCodeOffer::fromEnvironment()'));
$view = (string) file_get_contents($root . '/resources/views/source.php');
check('the page escapes the link it prints', str_contains($view, '$e($url)'));
check('the fallback is labelled as the upstream reference', str_contains($view, 'upstream project is a reference'));
$shell = (string) file_get_contents($root . '/resources/views/_portal-shell.php');
check('the shared footer links to /source under the base path', str_contains($shell, "\$base . '/source'"));
$about = (string) file_get_contents($root . '/resources/views/about.php');
check('About links to the source page', str_contains($about, '/source">Get the source code'));
$example = (string) file_get_contents($root . '/.env.example');
check('.env.example documents EKKLESIA_SOURCE_URL', str_contains($example, 'EKKLESIA_SOURCE_URL='));
check('.env.example documents EKKLESIA_SOURCE_VERSION', str_contains($example, 'EKKLESIA_SOURCE_VERSION='));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
