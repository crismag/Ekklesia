<?php

declare(strict_types=1);

namespace App\Services\Events;

/**
 * What makes two tags the same tag.
 *
 * "Christmas", "christmas" and " Christmas " are one thing to anyone reading a
 * calendar. A directory that disagrees quietly grows three tags nobody meant to
 * create, and then filtering by one of them finds a third of the events.
 *
 * So identity is a slug and display is a label, and they are computed here
 * rather than at each call site, because a rule applied in four places is four
 * rules waiting to diverge.
 */
final class TagName
{
    /** The column is varchar(64); a longer tag is refused, never truncated. */
    public const MAX_LENGTH = 64;

    /**
     * The comparison key: lowercased, punctuation collapsed to single hyphens.
     *
     * Returns an empty string for anything that is not a tag — blank, or
     * punctuation alone — so a caller can reject it rather than store a row
     * whose identity is "".
     */
    public static function slug(string $raw): string
    {
        $s = mb_strtolower(trim($raw), 'UTF-8');
        // Unicode-aware: "Año Nuevo" must not collapse to "a-o-nuevo".
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);

        return trim($s, '-');
    }

    /** The display form: collapsed whitespace, otherwise exactly as typed. */
    public static function label(string $raw): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim($raw)));
    }

    /** Whether this is a usable tag at all. */
    public static function isValid(string $raw): bool
    {
        $slug = self::slug($raw);

        return $slug !== ''
            && mb_strlen($slug) <= self::MAX_LENGTH
            && mb_strlen(self::label($raw)) <= self::MAX_LENGTH;
    }

    /**
     * A submitted list, cleaned: deduplicated by slug, order preserved.
     *
     * Order is kept because the first spelling of a tag is the one that becomes
     * its label, and because a list that reorders itself on save looks like it
     * lost something.
     *
     * @param list<string> $raw
     * @return list<array{slug:string,label:string}>
     */
    public static function normaliseList(array $raw): array
    {
        $out = [];
        foreach ($raw as $one) {
            $one = (string) $one;
            if (!self::isValid($one)) {
                continue;
            }
            $slug = self::slug($one);
            if (isset($out[$slug])) {
                continue;
            }
            $out[$slug] = ['slug' => $slug, 'label' => self::label($one)];
        }

        return array_values($out);
    }

    /**
     * Split a typed string into tags.
     *
     * Commas separate; a tag may contain spaces, because "Youth Ministry" is
     * one tag and splitting on whitespace would make it two.
     *
     * @return list<string>
     */
    public static function split(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $s): bool => $s !== '',
        ));
    }
}
