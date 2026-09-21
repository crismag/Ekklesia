<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * What a calendar entry *is*, said in presentation terms rather than domain ones.
 *
 * The renderer used to reach straight into the entry and print `title` in one
 * weight and `time` in another, which is fine while every entry is an event.
 * It is not fine once a birthday calendar is a first-class publication: on that
 * sheet the person is the headline, whereas on a ministry sheet the assignment
 * is the headline and the assignee is the footnote. Those are different
 * hierarchies over the same normalised entry.
 *
 * So this projects an entry onto three slots — primary, secondary, meta — and
 * leaves the domain model alone. A birthday is the exception to "only decide
 * where it goes": the printed calendar names a celebrant by the name they go
 * by and never prints their age, so the age the loader puts in the screen
 * title is dropped here rather than moved.
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
     * @return array{primary:string,secondary:string,meta:string,category:string,person:bool,memberType:string,group:?string}
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
        $memberType = is_array($entry['person'] ?? null) ? (string) ($entry['person']['memberType'] ?? '') : '';
        $extra = ['memberType' => $memberType, 'group' => $memberType !== '' ? MemberTypeStyle::group($memberType) : null];

        if ($kind === 'birth') {
            // A birthday prints the name the celebrant goes by and nothing
            // else: no age, and so no birth year to work back to. The same in
            // every display mode, Compact included.
            return [
                'primary' => self::celebrant($entry, $shortNames),
                'secondary' => '',
                'meta' => '',
                'category' => $kind,
                'person' => true,
            ] + $extra;
        }
        if (!$split) {
            // The unsplit form: title as the loader wrote it, time beside it.
            // This is what the wall calendar printed before there were tiers,
            // and Compact promises to reproduce it exactly.
            return [
                'primary' => DisplayName::forEntry($title, $kind, $shortNames),
                'secondary' => ($entry['all_day'] ?? true) ? '' : (string) ($entry['time'] ?? ''),
                'meta' => '',
                'category' => $kind !== '' ? $kind : 'event',
                'person' => $person,
            ] + $extra;
        }
        if ($person) {
            // An anniversary: the bracketed part ("10 years") is a separate
            // fact, so it becomes the secondary line.
            $suffix = '';
            $name = $title;
            if (preg_match('/\s*\(([^)]*)\)\s*$/u', $title, $m) === 1) {
                $suffix = trim($m[1]);
                $name = trim(substr($title, 0, -strlen($m[0])));
            }
            if ($shortNames && !$allowWrap) {
                $name = DisplayName::shorten($name);
            }
            return [
                'primary' => $name,
                'secondary' => $suffix,
                'meta' => '',
                'category' => $kind,
                'person' => true,
            ] + $extra;
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
        ] + $extra;
    }

    /**
     * The name a birthday is printed under.
     *
     * The preferred name when there is one, otherwise the first name, with a
     * last initial only when asked for (two Jessies on one day). The full name
     * in the title is the last resort, for a feed that sent no name parts, and
     * even then the age is taken off it.
     *
     * @param array<string,mixed> $entry
     */
    public static function celebrant(array $entry, bool $withInitial): string
    {
        $p = is_array($entry['person'] ?? null) ? $entry['person'] : [];
        $given = trim((string) ($p['preferred'] ?? ''));
        if ($given === '') {
            $given = trim((string) ($p['first'] ?? ''));
        }
        if ($given === '') {
            $title = (string) ($entry['title'] ?? '');
            $bare = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $title));
            return $withInitial ? DisplayName::shorten($bare) : $bare;
        }
        $last = trim((string) ($p['last'] ?? ''));
        if ($withInitial && $last !== '') {
            return $given . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.';
        }

        return $given;
    }
}
