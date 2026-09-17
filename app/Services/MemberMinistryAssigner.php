<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Turns the workbook's free-text ministry cell into ministry memberships.
 *
 * The import previously parsed and staged the ministry column and then dropped
 * it: nothing between the staging table and person_per carried it, so the review
 * screen showed ministries that were never going to be saved.
 *
 * Membership in this portal is a single row in person2group2role_p2g2r linking a
 * person, a group and a role. There is no separate membership table.
 *
 * Role: members are imported with role 0. That is not a shortcut — 282 of the
 * 284 existing memberships carry role 0, and the roles that do exist are
 * per-ministry assignment roles (Porter, Runner, Create1) rather than a
 * Member/Leader convention. The sheet defines no role, so inventing one would
 * put data in the database that nobody entered.
 *
 * Leadership is not a role either: it lives in portal_user_roles as an RBAC
 * grant scoped to a ministry, and is managed in the portal rather than in the
 * workbook. This class never writes it — it only makes sure a leader keeps the
 * membership their leadership implies.
 */
final class MemberMinistryAssigner
{
    /** Members are imported with no assignment role; see the class comment. */
    public const MEMBER_ROLE_ID = 0;

    /** Longest ministry name in words, plus room for a compound phrase. */
    private const MAX_PHRASE_WORDS = 5;

    /** @var array<string,int>|null comparison key => group id */
    private ?array $index = null;

    /**
     * @param callable():list<array<string,mixed>> $listMinistries returns the
     *        ministries as ministry_id + name. A callable rather than
     *        MinistryService itself: that class is final, and this only needs
     *        the list, so the dependency stays substitutable in tests.
     */
    public function __construct(
        private readonly MinistryCatalog $catalog,
        private $listMinistries,
        // Optional: a portal without a decisions file behaves exactly as it did
        // before, which keeps this class usable from tests and tools.
        private readonly ?MinistryNameMap $nameMap = null,
    ) {
    }

    /**
     * Resolve the ministry cell to group ids.
     *
     * The cell is not reliably delimited. One member's ministries arrive as
     * "Facilities Psalmist Victuals" — separated by nothing but spaces — while
     * ministry names themselves contain spaces ("Guest Services"). Splitting on
     * whitespace would shred the multi-word names; splitting only on commas, as
     * this did before, left that whole cell unrecognised and imported nothing.
     *
     * So the cell is read left to right, taking the longest known ministry name
     * or alias that fits at each position. Words that match nothing are
     * reported rather than dropped: an unrecognised keyword means somebody's
     * ministry did not import, and because the workbook is authoritative it can
     * also mean an existing membership was removed.
     *
     * @return array{ids:list<int>,unmatched:list<string>,matched:array<string,int>}
     */
    public function resolve(string $ministryCell): array
    {
        if ($this->isBlankish($ministryCell)) {
            return ['ids' => [], 'unmatched' => [], 'matched' => []];
        }

        // Separators that are never part of a name. "&" is deliberately absent:
        // it sits inside both an alias (G&A) and a compound the catalog knows
        // how to split ("Events & Prayer Ministry").
        $chunks = preg_split('/[,;\/|\r\n]+/', $ministryCell) ?: [];

        $ids = [];
        $unmatched = [];
        $matched = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || $this->isBlankish($chunk)) {
                continue;
            }
            [$chunkIds, $chunkUnmatched, $chunkMatched] = $this->scanPhrase($chunk);
            foreach ($chunkIds as $id) {
                $ids[$id] = $id;
            }
            foreach ($chunkUnmatched as $word) {
                $unmatched[strtolower($word)] = $word;
            }
            foreach ($chunkMatched as $text => $id) {
                $matched[$text] = $id;
            }
        }

        return ['ids' => array_values($ids), 'unmatched' => array_values($unmatched), 'matched' => $matched];
    }

    /**
     * Greedy longest-match across the words of one chunk.
     *
     * Longest first so "Guest Services" wins over a bare "Guest", and so a
     * compound phrase is offered to the catalog whole before its parts are.
     *
     * @return array{0:list<int>,1:list<string>,2:array<string,int>}
     */
    private function scanPhrase(string $chunk): array
    {
        $words = preg_split('/\s+/', trim($chunk)) ?: [];
        $count = count($words);
        $ids = [];
        $unmatched = [];
        $matched = [];

        // Consecutive unrecognised words are reported as one run rather than
        // one alert each: "Worship Team" is a single thing somebody meant, and
        // splitting it makes the alert harder to act on, not easier.
        $run = [];
        $flush = static function () use (&$run, &$unmatched): void {
            if ($run !== []) {
                $unmatched[] = implode(' ', $run);
                $run = [];
            }
        };

        for ($i = 0; $i < $count;) {
            $hit = false;
            for ($len = min(self::MAX_PHRASE_WORDS, $count - $i); $len >= 1; $len--) {
                $phrase = implode(' ', array_slice($words, $i, $len));
                // A name an administrator has marked "not a ministry" is
                // consumed silently. Reporting it again every import is the
                // behaviour the decision was made to stop.
                if ($this->nameMap !== null && $this->nameMap->isIgnored($phrase)) {
                    $flush();
                    $i += $len;
                    $hit = true;
                    break;
                }
                $found = $this->phraseToIds($phrase);
                if ($found !== []) {
                    $flush();
                    foreach ($found as $id) {
                        $ids[] = $id;
                    }
                    // Remember the words the sheet used, not only the ids they
                    // became: the review form is about the spreadsheet's
                    // vocabulary, so it has to show the spreadsheet's words.
                    $matched[$phrase] = $found[0];
                    $i += $len;
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $word = trim($words[$i]);
                if ($word !== '' && !$this->isBlankish($word)) {
                    $run[] = $word;
                }
                $i++;
            }
        }
        $flush();

        return [$ids, $unmatched, $matched];
    }

    /**
     * One phrase to ministry ids, via the catalog and then the group list.
     *
     * A phrase the catalog recognises but that has no group is not silently
     * accepted: returning nothing lets the caller report it as unrecognised
     * instead of quietly assigning fewer ministries than the sheet named.
     *
     * @return list<int>
     */
    private function phraseToIds(string $phrase): array
    {
        // An administrator's decision first. They have told the portal what
        // this word means; a catalog guess must not overrule that, and a name
        // marked "not a ministry" must stop being reported for ever.
        if ($this->nameMap !== null && $this->nameMap->hasDecision($phrase)) {
            $decided = $this->nameMap->idFor($phrase);

            return $decided !== null ? [$decided] : [];
        }

        $names = $this->catalog->parseHubCell($phrase);
        if ($names === []) {
            $direct = $this->groupIdFor($phrase);

            return $direct !== null ? [$direct] : [];
        }

        $ids = [];
        foreach ($names as $name) {
            $id = $this->resolveCanonical((string) $name);
            if ($id === null) {
                return [];
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * A catalog name to a group, by any name the catalog knows it under.
     *
     * The canonical name is not always the name the church calls the group.
     * "MTE" is the canonical for a group recorded as "More than Enough", and
     * "Field" for one recorded as "Field Ministry" — so looking up only the
     * canonical found nothing, and every member of those two ministries was
     * reported as unmatched however the sheet spelled them. The aliases were
     * already there to recognise the input; they now also help find the group.
     */
    private function resolveCanonical(string $canonical): ?int
    {
        $direct = $this->groupIdFor($canonical);
        if ($direct !== null) {
            return $direct;
        }

        foreach ($this->catalog->serving() as $row) {
            if ($this->normalise((string) $row['name']) !== $this->normalise($canonical)) {
                continue;
            }
            foreach ($row['aliases'] as $alias) {
                $id = $this->groupIdFor((string) $alias);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    /** Cells that mean "nothing here" and are not worth reporting. */
    private function isBlankish(string $value): bool
    {
        $v = strtolower(trim($value));

        return $v === '' || preg_match('/^(n\/?a|none|null|-+|tbd|\.)$/i', $v) === 1;
    }

    private function groupIdFor(string $name): ?int
    {
        $this->index ??= $this->buildIndex();

        return $this->index[$this->normalise($name)] ?? null;
    }

    /** @return array<string,int> */
    private function buildIndex(): array
    {
        $out = [];
        try {
            foreach (($this->listMinistries)() as $m) {
                $id = (int) ($m['ministry_id'] ?? $m['ministryId'] ?? 0);
                $name = $this->normalise((string) ($m['name'] ?? ''));
                if ($id > 0 && $name !== '') {
                    $out[$name] = $id;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    /**
     * A comparison key tolerant of the differences that are not differences.
     *
     * The catalog canonicalises G&A to "Gifts and Arrows" while the group is
     * named "Gift and Arrows". Trimming a trailing "s" off the whole string
     * cannot reconcile those — the plural is on the first word — so every word
     * is singularised and the joining words dropped. "Psalmist" and
     * "Psalmists" meet the same way.
     */
    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);
        $out = '';
        foreach (preg_split('/\s+/', trim($value)) ?: [] as $word) {
            if ($word === '' || $word === 'and' || $word === 'the') {
                continue;
            }
            $out .= strlen($word) > 3 && str_ends_with($word, 's') ? substr($word, 0, -1) : $word;
        }

        return $out;
    }
}
