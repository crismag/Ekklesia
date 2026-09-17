<?php
/**
 * Privacy invariant gate (INV-8). Anonymous callers must never receive
 * surnames, email addresses or postal addresses from the people surfaces.
 * Run against a live local instance. Exit 1 on any violation.
 *
 * Usage: php tools/check-privacy.php [baseUrl]
 */
declare(strict_types=1);

$base = $argv[1] ?? 'http://christlikeness.local/church_portal';
$fail = 0;
$checks = 0;

function get(string $url): array {
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int) $m[1]; }
    }
    return [$code, (string) $body];
}
function ok(string $label): void { printf("  ok  %s\n", $label); }
function bad(string $label, string $detail = ''): void {
    global $fail; $fail++;
    printf("  FAIL %s%s\n", $label, $detail !== '' ? " — $detail" : '');
}

echo "Anonymous API gating\n";
foreach ([
    '/api/people-directory',
    '/api/me',
    '/api/ministries',
    '/api/admin/ministries',
    '/api/admin/leaders',
] as $path) {
    $checks++;
    [$code] = get($base . $path);
    $code === 401 ? ok("$path returns 401") : bad("$path returns $code", 'expected 401');
}

echo "Public directory masking\n";
$checks++;
[$code, $body] = get($base . '/api/public/people-directory');
if ($code !== 200) {
    bad('/api/public/people-directory reachable', "got $code");
} else {
    $data = json_decode($body, true);
    $people = $data['people'] ?? $data['items'] ?? [];
    if (!is_array($people) || $people === []) {
        bad('public directory returned people');
    } else {
        ok('public directory returned ' . count($people) . ' people');
        $checks++;
        $lower = strtolower($body);
        foreach (['"email"', '"address"', '"address1"', '"homephone"', '"cellphone"'] as $field) {
            $checks++;
            str_contains($lower, $field)
                ? bad("public directory must not expose $field")
                : ok("public directory omits $field");
        }
        // Every entry must be masked: a lastInitial, never a full surname.
        $unmasked = 0;
        foreach ($people as $p) {
            $display = (string) ($p['displayName'] ?? '');
            $initial = (string) ($p['lastInitial'] ?? '');
            if ($initial !== '' && !preg_match('/^[A-Za-z]\.?$/', $initial)) { $unmasked++; }
            if ($display !== '' && preg_match('/\s+[A-Za-z]{2,}$/', $display)) {
                // trailing token longer than one letter => probable full surname
                if (!preg_match('/\s+[A-Za-z]\.$/', $display)) { $unmasked++; }
            }
        }
        $checks++;
        $unmasked === 0
            ? ok('all ' . count($people) . ' public entries are surname-masked')
            : bad("$unmasked public entries expose a surname");
    }
}

echo "Anonymous HTML must not leak contact data\n";
[$code, $html] = get($base . '/people');
$checks++;
if ($code !== 200) {
    bad('/people renders', "got $code");
} else {
    ok('/people renders');
    foreach (['mailto:', '@gmail.', '@yahoo.', '@hotmail.'] as $needle) {
        $checks++;
        str_contains(strtolower($html), $needle)
            ? bad("/people anonymous HTML contains $needle")
            : ok("/people anonymous HTML omits $needle");
    }
}

printf("\n%d checks; %d failures\n", $checks, $fail);
exit($fail > 0 ? 1 : 0);
