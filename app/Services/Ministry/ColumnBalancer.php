<?php

declare(strict_types=1);

namespace App\Services\Ministry;

/**
 * Deciding which card goes in which of three columns.
 *
 * CSS columns would do this, and would do it differently in every browser and
 * differently again between screen and print — which is not acceptable for a
 * document somebody prints twice and expects to match. So the placement is
 * decided here, deterministically, and CSS is left to do what it is good at:
 * laying out the text inside each card.
 *
 * The algorithm is the obvious one — put the next card in the shortest column —
 * and it is deliberately not more than that. The weights are estimates, the
 * real heights depend on where the text wraps, and a cleverer algorithm built
 * on estimates would just be confidently wrong instead of roughly right.
 *
 * Ties go to the leftmost column, so the same document always produces the same
 * page.
 */
final class ColumnBalancer
{
    /**
     * Distribute sections across a fixed number of columns.
     *
     * @param list<array{title:string,weight:int,...}> $sections
     * @return list<list<array<string,mixed>>> one list per column
     */
    public static function distribute(array $sections, int $columns = 3): array
    {
        $columns = max(1, $columns);
        $out = array_fill(0, $columns, []);
        $heights = array_fill(0, $columns, 0);

        foreach ($sections as $section) {
            // Decided here so the template never has to ask a service anything.
            $section['tall'] = self::isTall($section);
            $target = 0;
            for ($i = 1; $i < $columns; $i++) {
                // Strictly less: a tie keeps the leftmost column, so the layout
                // does not depend on iteration order.
                if ($heights[$i] < $heights[$target]) {
                    $target = $i;
                }
            }
            $out[$target][] = $section;
            $heights[$target] += max(1, (int) ($section['weight'] ?? 1));
        }

        return $out;
    }

    /**
     * How much one column of the Classic sheet holds.
     *
     * Measured, not guessed. On Letter portrait at the template's type sizes, a
     * column has about 824 CSS px between the masthead and the bottom margin,
     * and a rendered column of weight 37 measures 803 px — so a weight unit is
     * roughly 21.7 px and a column holds about 38 of them.
     *
     * That makes the reference schedule, whose tallest column is 37, fit with
     * almost nothing to spare. It is meant to: this is the density the sheet is
     * designed for, and anything past it is genuinely a second page.
     *
     * Re-measure this if the type sizes or paddings in classic.css change.
     * A capacity that has drifted from the CSS is worse than none, because it
     * reports a page as full when it is not.
     *
     * Used to warn and to allow breaking — never to shrink. A schedule nobody
     * can read from two feet away has failed at the one thing it is for.
     */
    public const COLUMN_CAPACITY = 38;

    /**
     * Whether one card is, on its own, taller than a column can hold.
     *
     * `break-inside: avoid` is right for an ordinary card — half of Facilities
     * on one page is worse than none of it. But a ministry with fourteen duties
     * is taller than the page, and telling the browser not to break it does not
     * make it fit: it pushes the whole card to a second page and leaves the
     * first two-thirds empty. Such a card is allowed to break between duties
     * instead, which is the only division that reads sensibly.
     */
    public static function isTall(array $section): bool
    {
        return (int) ($section['weight'] ?? 0) > self::COLUMN_CAPACITY;
    }

    /** @param list<list<array<string,mixed>>> $columns */
    public static function overflows(array $columns): bool
    {
        foreach ($columns as $column) {
            $height = 0;
            foreach ($column as $section) {
                $height += max(1, (int) ($section['weight'] ?? 1));
            }
            if ($height > self::COLUMN_CAPACITY) {
                return true;
            }
        }

        return false;
    }

    /** The tallest column's weight, for diagnostics. */
    public static function tallest(array $columns): int
    {
        $max = 0;
        foreach ($columns as $column) {
            $height = 0;
            foreach ($column as $section) {
                $height += max(1, (int) ($section['weight'] ?? 1));
            }
            $max = max($max, $height);
        }

        return $max;
    }
}
