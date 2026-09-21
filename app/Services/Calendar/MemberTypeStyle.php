<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * How a celebrant's member type is marked on a printed calendar.
 *
 * By colour on the name itself, never by an extra mark beside it: a busy day
 * has no room to spare. Each type has one hue, fixed across every theme so it
 * keeps its meaning — G&A sky blue, Trailblazer green, Radical orange — used
 * in one of two ways the reader chooses:
 *
 *   highlight  the name on a light tint of its hue, in the sheet's ink
 *              (the default: the hue is shown true and the text stays dark)
 *   text       the name itself in a deeper shade of its hue, dark enough to
 *              read as small text
 *
 * The coloured rule beside each entry uses the hue at full strength. A type
 * that is not one of the three, or none, is printed plainly with a grey rule.
 * Colour is the only marker, by the church's choice: on a black-and-white
 * printer the three types are not distinguishable.
 */
final class MemberTypeStyle
{
    /**
     * The three groups, in the order the legend lists them.
     *
     * `color` is the hue (rule, swatch border), `tint` the highlight behind a
     * name, `ink` the name's colour in text mode. `label` is used only when the
     * database gives no name. Contrast is checked in tests/Regression.
     */
    public const GROUPS = [
        'gna' => ['label' => 'G&A', 'color' => '#2b87d1', 'tint' => '#d3e9fa', 'ink' => '#1b64a0'],
        'trailblazer' => ['label' => 'Trailblazer', 'color' => '#2f8a4a', 'tint' => '#d5eedb', 'ink' => '#236b38'],
        'radical' => ['label' => 'Radical', 'color' => '#cf6a12', 'tint' => '#fde0c4', 'ink' => '#a14d06'],
    ];

    /** How a member type is marked on a name. */
    public const MARKS = ['highlight', 'text'];

    public const NEUTRAL = '#8b9590';

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

    /**
     * The one place the colours become CSS: custom properties on the sheet.
     * A theme may paint around a name, never redefine these.
     */
    public static function cssVars(): string
    {
        $vars = [];
        foreach (self::GROUPS as $id => $g) {
            $vars[] = '--mt-' . $id . ':' . $g['color'];
            $vars[] = '--mt-' . $id . '-tint:' . $g['tint'];
            $vars[] = '--mt-' . $id . '-ink:' . $g['ink'];
        }
        $vars[] = '--mt-none:' . self::NEUTRAL;

        return implode(';', $vars);
    }

    /**
     * The legend: one row per group that actually appears on the sheet, named
     * as the database names it.
     *
     * @param list<string> $memberTypes every member type printed on the sheet
     * @return list<array{group:string,label:string}>
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
            $rows[] = ['group' => $id, 'label' => trim($seen[$id]) ?: $g['label']];
        }

        return $rows;
    }
}
