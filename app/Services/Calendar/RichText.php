<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * The small amount of formatting an editorial note on a calendar may carry.
 *
 * Top and bottom information are written by church administrators and then
 * printed and pinned up, so they need a heading, a bold word and a list — and
 * nothing else. An allow-list rather than a strip-list: anything not named here
 * is removed, so a tag nobody thought about is safe by default rather than
 * dangerous by default.
 *
 * There is no `style`, no `class`, no `id`, no href. A note on a printed sheet
 * has nothing to link to and nothing to style; permitting either would mean
 * this had to defend against everything CSS and URLs can do.
 */
final class RichText
{
    /** Tags an editorial note may use. */
    private const ALLOWED = ['p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'h3', 'h4'];

    /** Longer than this is not a note; it is a document. */
    public const MAX_LENGTH = 4000;

    /**
     * Keep the handful of tags above, drop everything else and every attribute.
     *
     * Text inside a removed tag is kept: somebody who pastes from a word
     * processor should lose the markup, not the sentence.
     */
    public static function sanitize(string $html): string
    {
        $html = mb_substr(trim($html), 0, self::MAX_LENGTH);
        if ($html === '') {
            return '';
        }

        // Script and style carry their *content* as code, so unlike every other
        // disallowed tag their contents go with them.
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|template)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|template)\b[^>]*/?>#i', '', $html);
        // Comments can hide conditional markup.
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);

        $allowed = '<' . implode('><', self::ALLOWED) . '>';
        $html = strip_tags($html, $allowed);

        // Every attribute goes, including the ones that look harmless: there is
        // no attribute an editorial note needs, so none has to be judged.
        $html = (string) preg_replace_callback(
            '#<\s*([a-z0-9]+)\b[^>]*?(/?)>#i',
            static fn (array $m): string => '<' . strtolower($m[1]) . ($m[2] === '/' ? ' /' : '') . '>',
            $html,
        );
        $html = (string) preg_replace_callback(
            '#</\s*([a-z0-9]+)\s*>#i',
            static fn (array $m): string => '</' . strtolower($m[1]) . '>',
            $html,
        );

        return trim($html);
    }

    /**
     * Whether this note would render as nothing.
     *
     * "<p></p>" and "<p>&nbsp;</p>" are what a rich-text field leaves behind
     * when somebody types and then deletes. Treating them as content would
     * reserve page height for an empty box — the one thing the optional regions
     * must never do.
     */
    public static function isBlank(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Non-breaking space is whitespace to a reader, whatever \s thinks.
        $text = str_replace(["\xC2\xA0", "\u{200B}"], ' ', $text);

        return trim($text) === '';
    }

    /** Sanitised, or an empty string when what survives is nothing. */
    public static function clean(string $html): string
    {
        $out = self::sanitize($html);

        return self::isBlank($out) ? '' : $out;
    }
}
