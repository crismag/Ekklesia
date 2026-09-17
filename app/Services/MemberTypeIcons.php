<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A symbol for each member type, for the people directory's narrowest column.
 *
 * The column first showed the full name, which was wide enough to push the Edit
 * button off the screen, and then a two-letter code — Tr, Ra, G — which read
 * like chemical symbols.
 *
 * A symbol here marks a member type recorded on the person and means nothing
 * beyond it. In particular it does not encode an age band: the roster has
 * Trailblazer running 3 to 75 and Radical 0 to 35, and the types overlap on
 * marital status and other affiliations besides. Reading a symbol as "adult"
 * or "child" would be reading something into the data that is not there.
 *
 * Assignments live in configuration because member types are edited by
 * administrators. A type nobody has mapped falls back to a short text code, so
 * adding one degrades gracefully rather than leaving a blank cell.
 *
 * Nothing depends on the symbol alone: each carries its member type name for
 * hover and for assistive technology, and the page prints a key.
 */
final class MemberTypeIcons
{
    /** @var array<string,string> comparison key => symbol key */
    private array $index = [];

    /** @var array<string,string> symbol key => human label */
    private array $symbols = [];

    /**
     * @param array<string,string> $types   member type name => symbol key
     * @param array<string,string> $symbols symbol key => label
     */
    public function __construct(array $types = [], array $symbols = [])
    {
        foreach ($types as $name => $symbol) {
            $key = $this->key((string) $name);
            if ($key !== '') {
                $this->index[$key] = (string) $symbol;
            }
        }
        $this->symbols = $symbols;
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return new self();
        }

        return new self(
            is_array($decoded['types'] ?? null) ? $decoded['types'] : [],
            is_array($decoded['symbols'] ?? null) ? $decoded['symbols'] : [],
        );
    }

    /** The symbol assigned to a member type, or null when none is. */
    public function symbolFor(string $memberType): ?string
    {
        $symbol = $this->index[$this->key($memberType)] ?? null;

        return $symbol !== null && $this->svg($symbol) !== '' ? $symbol : null;
    }

    /** The symbol's own name — used only where the shape needs describing. */
    public function symbolLabel(string $symbol): string
    {
        return $this->symbols[$symbol] ?? ucfirst($symbol);
    }

    /**
     * The symbol as inline SVG.
     *
     * Decorative: the caller supplies the accessible name, because the useful
     * name is the member type, not the shape.
     */
    public function svg(string $symbol): string
    {
        $body = match ($symbol) {
            // An archery arrow: shaft, head, fletching. The earlier attempt
            // drew the head against a box and came out as the universal
            // "opens in a new tab" glyph, which is a different promise.
            'arrow' => '<path d="M4.5 19.5 19 5"/>'
                . '<path d="M19.5 4.5 12.9 5.6M19.5 4.5l-1.1 6.6"/>'
                . '<path d="m4.5 19.5 1.9-.4M4.5 19.5l.4-1.9"/>',
            // A pennant on a pole. Chosen over a torch, which at this size was
            // a lightbulb, and which shared a silhouette with the seedling.
            'flag' => '<path d="M6 3.2V21"/>'
                . '<path d="M6 4.6h11.6l-2.6 3.6 2.6 3.6H6z"/>',
            'torch' => '<path d="M12 2.8c2.1 2.7 3.1 4.5 3.1 5.9a3.1 3.1 0 0 1-6.2 0c0-1.4 1-3.2 3.1-5.9z"/>'
                . '<path d="M9.9 12.2h4.2M12 12.2V21"/>',
            // A shoot with two leaves. Radical is root, so the plant is the
            // name's own image rather than an arbitrary mark.
            'seedling' => '<path d="M12 21v-7.4"/>'
                . '<path d="M12 13.6C12 10 9.1 7.1 5.5 7.1c0 3.6 2.9 6.5 6.5 6.5z"/>'
                . '<path d="M12 13.6c0-3.1 2.5-5.6 5.6-5.6 0 3.1-2.5 5.6-5.6 5.6z"/>',
            'dove' => '<path d="M20.6 5.4c-2.3 0-3.8 1.1-5.3 2.9-1.6 2-3.1 3.1-5.4 3.1-3 0-5.4 2.4-5.4 5.4 0 .7.6 1.3 1.3 1.3 3.3 0 5.6-1 7.6-3"/>'
                . '<path d="M13.4 15.1c3.6-.6 6.5-3.6 7.2-7.2M8.5 18.6 6.7 21"/>',
            'shield' => '<path d="M12 3.2 5.2 6v5.6c0 4.2 2.9 8.1 6.8 9.2 3.9-1.1 6.8-5 6.8-9.2V6z"/>',
            'star' => '<path d="m12 3.4 2.7 5.5 6 .9-4.3 4.2 1 6-5.4-2.8-5.4 2.8 1-6L3.3 9.8l6-.9z"/>',
            default => '',
        };
        if ($body === '') {
            return '';
        }

        // Stroked rather than filled: at 18px an outline keeps more of a shape's
        // silhouette than a solid does, which is what makes three of them
        // distinguishable in a table cell.
        return '<svg class="mt-sym" viewBox="0 0 24 24" width="18" height="18" fill="none"'
            . ' stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">' . $body . '</svg>';
    }

    /** Punctuation- and case-insensitive, so "G&A" also matches "G and A". */
    private function key(string $name): string
    {
        $key = strtolower(trim($name));
        $key = (string) preg_replace('/\band\b/', '', $key);

        return (string) preg_replace('/[^a-z0-9]+/', '', $key);
    }
}
