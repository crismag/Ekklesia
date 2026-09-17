<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * What a calendar entry *is*, said in presentation terms rather than domain ones.
 *
 * The renderer used to reach straight into the entry and print `title` in one
 * weight and `time` in another, which is fine while every entry is an event.
 * It is not fine once a birthday calendar is a first-class publication: on that
 * sheet the person is the headline and "(29)" is a footnote, whereas on a
 * ministry sheet the assignment is the headline and the assignee is the
 * footnote. Those are different hierarchies over the same normalised entry.
 *
 * So this projects an entry onto three slots — primary, secondary, meta — and
 * leaves the domain model alone. Nothing here invents information: the age in
 * "Ramon Bayeta (29)" is displayed only because the loader already put it in
 * the title, and this class only decides *where* it goes. Whether an age may be
 * shown at all remains the calendar loader's decision, upstream of here.
 */
final class EntryPresentation
{
    /**
     * Split one entry into what should be big and what should be small.
     *
     * @param array<string,mixed> $entry a normalised CalendarViewModel entry
     * @param bool $shortNames the reader's "shorten names" choice
     * @param bool $allowWrap whether the layout can afford a two-line primary,
     *        which decides if a person's name may keep its full form
     * @return array{primary:string,secondary:string,meta:string,category:string,person:bool}
     */
    public static function of(
        array $entry,
        bool $shortNames,
        bool $allowWrap = false,
        bool $split = true,
    ): array {
        $title = (string) ($entry['title'] ?? '');
        $kind = (string) ($entry['kind'] ?? '');
        $person = DisplayName::isPersonEntry($kind);

        if (!$split) {
            // The unsplit form: title as the loader wrote it, time beside it.
            // This is what the wall calendar printed before there were tiers,
            // and Compact promises to reproduce it exactly — including keeping
            // "(29)" inside the headline rather than lifting it out.
            return [
                'primary' => DisplayName::forEntry($title, $kind, $shortNames),
                'secondary' => ($entry['all_day'] ?? true) ? '' : (string) ($entry['time'] ?? ''),
                'meta' => '',
                'category' => $kind !== '' ? $kind : 'event',
                'person' => $person,
            ];
        }

        if ($person) {
            // The bracketed part is a separate fact about the person, not part
            // of their name, so it becomes the secondary line instead of
            // riding along inside a headline set at sixteen point.
            $suffix = '';
            $name = $title;
            if (preg_match('/\s*\(([^)]*)\)\s*$/u', $title, $m) === 1) {
                $suffix = trim($m[1]);
                $name = trim(substr($title, 0, -strlen($m[0])));
            }

            // Shortening exists to make a long name fit a small row. Where the
            // row is large enough to wrap, it is not needed, and the full name
            // is what the celebrant would rather see on the noticeboard.
            if ($shortNames && !$allowWrap) {
                $name = DisplayName::shorten($name);
            }

            return [
                'primary' => $name,
                'secondary' => $suffix,
                'meta' => '',
                'category' => $kind,
                'person' => true,
            ];
        }

        // An ordinary event: what it is, then when, then where. Location is
        // third because it is the first thing to lose when a cell tightens.
        $where = (string) ($entry['location'] ?? '');
        if ($where === '') {
            $where = (string) ($entry['ministry'] ?? '');
        }

        return [
            'primary' => $title,
            'secondary' => ($entry['all_day'] ?? true) ? '' : (string) ($entry['time'] ?? ''),
            'meta' => $where,
            'category' => $kind !== '' ? $kind : 'event',
            'person' => false,
        ];
    }
}
