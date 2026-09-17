<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Related-families map — an admin-confirmed, presentation-only link between two
 * family records (e.g. a parents' family and a married child's family, or two
 * households sharing a residence).
 *
 * Stored in a portal-owned JSON file (config/related-families.json). This adds
 * NO database columns and does not touch any family/person record — it is a
 * removable side-file that the UI reads to show cross-family relationships that
 * a single per_fam_ID cannot express (a person belongs to only one family).
 */
final class RelatedFamiliesService
{
    /** Relationship types. Directional ones store a=parent, b=child. */
    public const RELS = [
        'parent-child' => 'Parent ↔ married child',
        'extended'     => 'Extended family',
        'household'    => 'Same household',
    ];

    public function __construct(private readonly string $configPath) {}

    /** @return list<array{a:int,b:int,rel:string}> */
    public function links(): array
    {
        if (!is_file($this->configPath)) {
            return [];
        }
        $raw = file_get_contents($this->configPath);
        $data = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
        $links = is_array($data['links'] ?? null) ? $data['links'] : [];
        $out = [];
        foreach ($links as $l) {
            $a = (int) ($l['a'] ?? 0);
            $b = (int) ($l['b'] ?? 0);
            $rel = (string) ($l['rel'] ?? 'extended');
            if ($a > 0 && $b > 0 && $a !== $b && isset(self::RELS[$rel])) {
                $out[] = ['a' => $a, 'b' => $b, 'rel' => $rel];
            }
        }
        return $out;
    }

    /**
     * Links touching a family, from that family's point of view.
     * @return list<array{other:int,rel:string,label:string}>
     */
    public function forFamily(int $famId): array
    {
        $out = [];
        foreach ($this->links() as $l) {
            if ($l['a'] !== $famId && $l['b'] !== $famId) {
                continue;
            }
            $isA = $l['a'] === $famId;
            $out[] = [
                'other' => $isA ? $l['b'] : $l['a'],
                'rel'   => $l['rel'],
                'label' => $this->label($l['rel'], $isA),
            ];
        }
        return $out;
    }

    private function label(string $rel, bool $isA): string
    {
        return match ($rel) {
            'parent-child' => $isA ? "Married child's family" : "Parents' family",
            'household'    => 'Same household',
            default        => 'Extended family',
        };
    }

    /**
     * Create a link. For 'parent-child', $direction ('parent'|'child') says which
     * side $from is; symmetric types ignore direction.
     */
    public function link(int $from, int $to, string $rel, string $direction = 'parent'): void
    {
        if ($from <= 0 || $to <= 0 || $from === $to) {
            throw new InvalidArgumentException('Pick a different family to link.');
        }
        if (!isset(self::RELS[$rel])) {
            throw new InvalidArgumentException('Unknown relationship.');
        }
        if ($rel === 'parent-child') {
            [$a, $b] = $direction === 'child' ? [$to, $from] : [$from, $to];
        } else {
            [$a, $b] = $from < $to ? [$from, $to] : [$to, $from];
        }
        $links = $this->links();
        foreach ($links as $l) {
            if (($l['a'] === $a && $l['b'] === $b) || ($l['a'] === $b && $l['b'] === $a)) {
                // Already linked — update the relationship type in place.
                $l = null;
            }
        }
        // Rebuild without any existing pair, then append.
        $rebuilt = array_values(array_filter($links, static fn ($l) =>
            !(($l['a'] === $a && $l['b'] === $b) || ($l['a'] === $b && $l['b'] === $a))));
        $rebuilt[] = ['a' => $a, 'b' => $b, 'rel' => $rel];
        $this->save($rebuilt);
    }

    public function unlink(int $x, int $y): void
    {
        $rebuilt = array_values(array_filter($this->links(), static fn ($l) =>
            !(($l['a'] === $x && $l['b'] === $y) || ($l['a'] === $y && $l['b'] === $x))));
        $this->save($rebuilt);
    }

    /** Drop every link that references a family (e.g. after it's merged away). */
    public function removeFamily(int $famId): void
    {
        $rebuilt = array_values(array_filter($this->links(), static fn ($l) => $l['a'] !== $famId && $l['b'] !== $famId));
        if (count($rebuilt) !== count($this->links())) {
            $this->save($rebuilt);
        }
    }

    /** @param list<array{a:int,b:int,rel:string}> $links */
    private function save(array $links): void
    {
        $encoded = json_encode(['links' => $links], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode related-families map.');
        }
        $dir = dirname($this->configPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create config directory: $dir");
        }
        $tmp = tempnam($dir, 'relfam-');
        if ($tmp === false || file_put_contents($tmp, $encoded . "\n", LOCK_EX) === false) {
            if ($tmp !== false) { @unlink($tmp); }
            throw new RuntimeException('Failed to stage related-families write.');
        }
        if (!@rename($tmp, $this->configPath)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to save related-families map.');
        }
        @chmod($this->configPath, 0664);
    }
}
