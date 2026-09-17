<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * A ministry schedule, as a thing to be printed rather than as rows.
 *
 * The document a church posts on its noticeboard does not correspond to one
 * database record. It consolidates assignments made against service occurrences
 * (assignments → serving_roles → ministries) with roster schedules kept
 * separately (rosters → roster_slots), and those two have different shapes and
 * different words for the same ideas.
 *
 * This is where they stop being different. Everything past this point — the
 * template, the CSS, any later renderer — sees one structure:
 *
 *     Service → Section (ministry) → Assignment (role) → People
 *
 * The template must not query anything. That is not a style preference: a
 * template that queries cannot be tested without a database, cannot be
 * rendered from a fixture, and grows a second copy of the consolidation rules
 * the moment a second template exists.
 *
 * Ids are carried for diagnostics and for whatever links a later screen wants.
 * Nothing visual depends on them.
 *
 * Serializable on purpose: a published, immutable revision of a schedule is
 * plainly useful later, and an object that cannot be written down cannot become
 * one. Nothing here writes JSON today.
 */
final class MinistryScheduleDocument
{
    public const TYPE = 'ministry_schedule';

    /** A warning that something is worth a second look before printing. */
    public const LEVEL_INFO = 'info';

    /** A warning that the schedule is probably not finished. */
    public const LEVEL_WARN = 'warn';

    /**
     * @param list<array{
     *   title:string, ministryId:?int, source:string,
     *   assignments:list<array{role:?string,people:list<array{name:string,personId:?int}>,note:?string}>,
     *   weight:int
     * }> $sections
     * @param list<array{level:string,kind:string,message:string}> $warnings
     * @param array{ministries:int,assignments:int,volunteers:int,people:int} $stats
     * @param list<string> $sources
     */
    public function __construct(
        public readonly string $title,
        public readonly string $serviceDate,
        public readonly string $serviceTitle,
        public readonly string $displayDate,
        public readonly array $sections,
        public readonly array $warnings = [],
        public readonly array $stats = ['ministries' => 0, 'assignments' => 0, 'volunteers' => 0, 'people' => 0],
        public readonly array $sources = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }

    /** Warnings at one level, for a summary that separates "check this" from "this is fine". */
    public function warningsOfLevel(string $level): array
    {
        return array_values(array_filter(
            $this->warnings,
            static fn (array $w): bool => $w['level'] === $level,
        ));
    }

    /**
     * The whole document as plain arrays.
     *
     * Exists so the document can be written down — a fixture, a test
     * expectation, and eventually a published revision that stays true after
     * the underlying assignments change.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'documentType' => self::TYPE,
            'title' => $this->title,
            'service' => [
                'date' => $this->serviceDate,
                'title' => $this->serviceTitle,
                'displayDate' => $this->displayDate,
            ],
            'sections' => $this->sections,
            'warnings' => $this->warnings,
            'stats' => $this->stats,
            'sources' => $this->sources,
        ];
    }
}
