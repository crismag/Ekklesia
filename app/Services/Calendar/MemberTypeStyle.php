<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Services\MemberTypeIcons;

/**
 * How a celebrant's member type is marked on a printed calendar.
 *
 * One colour per type, fixed across every theme so it keeps its meaning:
 * G&A sky blue, Trailblazer green, Radical orange. Colour is never the only
 * mark. Each type also prints the symbol the people directory already uses
 * (MemberTypeIcons), so the types stay apart on a greyscale printer and for a
 * reader who does not see the colours. A type that is not one of the three,
 * or no type at all, gets a neutral rule and no symbol rather than a guess.
 *
 * The colours are only ever used on the rule beside a name and on the symbol,
 * never on the name itself, which stays in the sheet's ink for contrast.
 */
final class MemberTypeStyle
{
    /**
     * The three groups, in the order the legend lists them.
     *
     * `label` is used only when the database gives no name; the legend prints
     * the member type as Ekklesia stores it. The colours are checked in
     * tests/Regression against white for a 3:1 non-text contrast.
     */
    public const GROUPS = [
        'gna' => ['label' => 'G&A', 'color' => '#2b87d1'],
        'trailblazer' => ['label' => 'Trailblazer', 'color' => '#2f8a4a'],
        'radical' => ['label' => 'Radical', 'color' => '#cf6a12'],
    ];

    public const NEUTRAL = '#8b9590';

    public function __construct(private readonly ?MemberTypeIcons $icons = null)
    {
    }

    /** Built from the directory's own symbol configuration. */
    public static function fromConfig(string $configPath): self
    {
        return new self(MemberTypeIcons::fromFile($configPath));
    }

    /**
     * Which group a stored member type belongs to, or null.
     *
     * Matched without case, punctuation or "and", and with a trailing plural
     * dropped, so "G&A", "G and A", "GNA", "Gifts & Arrows", "Radicals" and
     * "trailblazer" all land where they should. Nothing else is guessed at.
     */
    public static function group(string $memberType): ?string
    {
        $key = strtolower(trim($memberType));
        $key = (string) preg_replace('/\band\b/', '', $key);
        $key = (string) preg_replace('/[^a-z0-9]+/', '', $key);

        return match (true) {
            in_array($key, ['ga', 'gna', 'giftsarrows', 'giftarrows', 'giftsarrow'], true) => 'gna',
            in_array($key, ['trailblazer', 'trailblazers'], true) => 'trailblazer',
            in_array($key, ['radical', 'radicals'], true) => 'radical',
            default => null,
        };
    }

    /** The symbol for a stored member type, as inline SVG, or '' for none. */
    public function symbol(string $memberType): string
    {
        if ($this->icons === null || self::group($memberType) === null) {
            return '';
        }
        $symbol = $this->icons->symbolFor($memberType);

        return $symbol === null ? '' : $this->icons->svg($symbol);
    }

    /**
     * The one place the colours become CSS: custom properties on the sheet.
     * A theme may paint behind a name, never redefine these.
     */
    public static function cssVars(): string
    {
        $vars = [];
        foreach (self::GROUPS as $id => $g) {
            $vars[] = '--mt-' . $id . ':' . $g['color'];
        }
        $vars[] = '--mt-none:' . self::NEUTRAL;

        return implode(';', $vars);
    }

    /**
     * The legend: one row per group that actually appears on the sheet, named
     * as the database names it.
     *
     * @param list<string> $memberTypes every member type printed on the sheet
     * @return list<array{group:string,label:string,symbol:string}>
     */
    public function legend(array $memberTypes): array
    {
        $seen = [];
        foreach ($memberTypes as $type) {
            $group = self::group($type);
            if ($group !== null && !isset($seen[$group])) {
                $seen[$group] = $type;
            }
        }
        $rows = [];
        foreach (self::GROUPS as $id => $g) {
            if (!isset($seen[$id])) {
                continue;
            }
            $rows[] = ['group' => $id, 'label' => trim($seen[$id]) ?: $g['label'], 'symbol' => $this->symbol($seen[$id])];
        }

        return $rows;
    }
}
