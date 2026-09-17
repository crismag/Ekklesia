<?php
/**
 * Print INSERTs for household_links from the portal's related-families file.
 *
 *   php database/migrate/household_links_from_json.php [config/related-families.json]
 *
 * The legacy portal kept these links in a JSON side-file; the member database
 * keeps them as rows. Production's file may hold links a checkout does not, so
 * pass the production copy when migrating production.
 */
declare(strict_types=1);

$path = $argv[1] ?? dirname(__DIR__, 2) . '/config/related-families.json';
$data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
$links = is_array($data['links'] ?? null) ? $data['links'] : [];

$relationships = ['parent-child' => 'parent_child', 'extended' => 'extended', 'household' => 'same_residence'];
echo "-- household_links from " . basename($path) . ': ' . count($links) . " link(s)\n";
foreach ($links as $link) {
    $a = (int) ($link['a'] ?? 0);
    $b = (int) ($link['b'] ?? 0);
    $rel = $relationships[(string) ($link['rel'] ?? '')] ?? null;
    if ($a <= 0 || $b <= 0 || $a === $b || $rel === null) {
        fwrite(STDERR, 'Skipped an unreadable link: ' . json_encode($link) . "\n");
        continue;
    }
    // Only parent_child is directional (a = parents); store the others once, lower id first.
    if ($rel !== 'parent_child' && $a > $b) {
        [$a, $b] = [$b, $a];
    }
    printf(
        "INSERT IGNORE INTO household_links (household_id, related_household_id, relationship) SELECT %d, %d, '%s' FROM DUAL WHERE EXISTS (SELECT 1 FROM households WHERE id = %d) AND EXISTS (SELECT 1 FROM households WHERE id = %d);\n",
        $a, $b, $rel, $a, $b
    );
}
