<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * Shortening a name so a wall calendar can hold the day it belongs to.
 *
 * "Clarisse Daise Manzo Bayeta (29)" is most of a cell, and the day it sits on
 * then reports "+3 more" — so the long name crowds out the very people it is
 * listed beside. Shortened, it is "Clarisse B. (29)".
 *
 * Offered rather than imposed: a small church knows its Ramons apart and a
 * large one does not, so the choice belongs to whoever is printing.
 *
 * Lives here rather than inside a template because two templates need it, and a
 * rule copied into two places is two rules waiting to disagree.
 */
final class DisplayName
{
    /**
     * First name, last initial, and whatever was in brackets.
     *
     * The bracketed part — "(29)", "(10 years)" — is the reason the entry is on
     * the calendar at all, so it always survives. A single-word name is
     * returned untouched: there is no initial to take.
     */
    public static function shorten(string $title): string
    {
        $suffix = '';
        if (preg_match('/\s*(\([^)]*\))\s*$/u', $title, $m) === 1) {
            $suffix = ' ' . $m[1];
            $title = trim(substr($title, 0, -strlen($m[0])));
        }

        $parts = array_values(array_filter(preg_split('/\s+/u', trim($title)) ?: []));
        if (count($parts) < 2) {
            return trim($title . $suffix);
        }

        $last = (string) array_pop($parts);
        $initial = mb_substr($last, 0, 1);
        if ($initial === '') {
            return trim($title . $suffix);
        }

        return $parts[0] . ' ' . mb_strtoupper($initial) . '.' . $suffix;
    }

    /** Whether this kind of entry is a person's name at all. */
    public static function isPersonEntry(string $kind): bool
    {
        // Only birthdays and anniversaries are named after people. Shortening
        // "Sunday Service - NY" to "Sunday S." would be nonsense.
        return $kind === 'birth' || $kind === 'anniv';
    }

    /** Apply the choice to one entry title. */
    public static function forEntry(string $title, string $kind, bool $short): string
    {
        return $short && self::isPersonEntry($kind) ? self::shorten($title) : $title;
    }
}
