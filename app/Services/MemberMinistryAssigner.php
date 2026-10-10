<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Turns the workbook's free-text ministry cell into ministry memberships and
 * the positions held in them.
 *
 * The import previously parsed and staged the ministry column and then dropped
 * it: nothing between the staging table and `people` carried it, so the review
 * screen showed ministries that were never going to be saved.
 *
 * Membership is one ministry_members row linking a person and a ministry with
 * role 'member' or 'leader'. What someone does there ("Usher", "Emcee") is a
 * position on that membership (ministry_member_positions), not a role.
 *
 * Role: people are imported as 'member'. The sheet defines no role, so
 * inventing one would put data in the database that nobody entered, and an
 * existing leader is never demoted to member by an import.
 *
 * Positions come from the cell in two ways:
 *  - a word or phrase the catalog lists as a position of a ministry
 *    (config/ministry-catalog.json "roles"): "Usher" or "GS: Usher" or
 *    "Guest Services Usher" is Guest Services with the position Usher;
 *  - the export's form, a ministry followed by its positions in parentheses:
 *    "Guest Services (Usher, Emcee)". The words in the parentheses are the
 *    positions of the ministry just before them, taken as written (with the
 *    catalog's spelling when the catalog knows the position), so a position
 *    set in the portal survives an export and re-import even when the catalog
 *    does not list it.
 *
 * Leadership that grants access lives in account roles and is managed in the
 * portal rather than in the workbook. This class never writes it.
 */
final class MemberMinistryAssigner
{
    /** People are imported with the plain membership role; see the class comment. */
    public const MEMBER_ROLE = 'member';

    /** Longest ministry name in words, plus room for a compound phrase. */
    private const MAX_PHRASE_WORDS = 5;

    /** @var array<string,int>|null comparison key => ministry id */
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
     * Resolve the ministry cell to ministry ids and positions.
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
     * positions: ministry id => the positions the cell gave it (only ministries
     * with at least one). matchedPositions: the sheet's words => the positions
     * they carried, for the review form.
     *
     * @return array{
     *   ids:list<int>,
     *   unmatched:list<string>,
     *   matched:array<string,int>,
     *   positions:array<int,list<string>>,
     *   matchedPositions:array<string,list<string>>
     * }
     */
    public function resolve(string $ministryCell): array
    {
        $out = ['ids' => [], 'unmatched' => [], 'matched' => [], 'positions' => [], 'matchedPositions' => []];
        if ($this->isBlankish($ministryCell)) {
            return $out;
        }

        $ids = [];
        $unmatched = [];
        foreach ($this->chunks($ministryCell) as $chunk) {
            // "Guest Services (Usher, Emcee) Psalmist": each parenthesised
            // list belongs to the ministry named just before it.
            $rest = $chunk;
            while ($rest !== '') {
                $open = strpos($rest, '(');
                $close = $open === false ? false : strpos($rest, ')', $open);
                if ($open === false || $close === false) {
                    $this->absorb($this->scanPhrase(str_replace(['(', ')'], ' ', $rest)), $ids, $unmatched, $out);
                    break;
                }
                $before = substr($rest, 0, $open);
                $inside = substr($rest, $open + 1, $close - $open - 1);
                $rest = trim(substr($rest, $close + 1));

                $scan = $this->scanPhrase($before);
                $this->absorb($scan, $ids, $unmatched, $out);
                $owner = $scan['last'];
                if ($owner === null) {
                    // Nothing before the parentheses to hang them on: read the
                    // words inside as ordinary cell text.
                    foreach ($this->splitPositions($inside) as $word) {
                        $this->absorb($this->scanPhrase($word), $ids, $unmatched, $out);
                    }
                    continue;
                }
                foreach ($this->splitPositions($inside) as $position) {
                    $name = $this->canonicalPosition($owner, $position);
                    $this->addPosition($out, $owner, $name);
                    $phrase = trim((string) $scan['lastPhrase']);
                    if ($phrase !== '' && !in_array($name, $out['matchedPositions'][$phrase] ?? [], true)) {
                        $out['matchedPositions'][$phrase][] = $name;
                    }
                }
            }
        }

        foreach ($unmatched as $word) {
            $out['unmatched'][] = $word;
        }
        $out['ids'] = array_values($ids);

        return $out;
    }

    /**
     * The cell split on separators that are never part of a name, except
     * inside parentheses, where a comma separates positions.
     *
     * "&" is deliberately not a separator: it sits inside both an alias (G&A)
     * and a compound the catalog knows how to split ("Events & Prayer Ministry").
     *
     * @return list<string>
     */
    private function chunks(string $cell): array
    {
        $chunks = [];
        $current = '';
        $depth = 0;
        $length = strlen($cell);
        for ($i = 0; $i < $length; $i++) {
            $c = $cell[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($depth === 0 && strpbrk($c, ",;/|\r\n") !== false) {
                $chunks[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $chunks[] = $current;

        $out = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '' && !$this->isBlankish($chunk)) {
                $out[] = $chunk;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function splitPositions(string $inside): array
    {
        $out = [];
        foreach (preg_split('/\s*[,;\/|&]\s*|\s+and\s+/i', $inside) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && !$this->isBlankish($part)) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * Fold one scan into the running result.
     *
     * @param array{ids:list<int>,unmatched:list<string>,matched:array<string,int>,positions:array<int,list<string>>,matchedPositions:array<string,list<string>>,last:?int,lastPhrase:?string} $scan
     * @param array<int,int>    $ids
     * @param array<string,string> $unmatched
     * @param array<string,mixed>  $out
     */
    private function absorb(array $scan, array &$ids, array &$unmatched, array &$out): void
    {
        foreach ($scan['ids'] as $id) {
            $ids[$id] = $id;
        }
        foreach ($scan['unmatched'] as $word) {
            $unmatched[strtolower($word)] = $word;
        }
        foreach ($scan['matched'] as $text => $id) {
            $out['matched'][$text] = $id;
        }
        foreach ($scan['positions'] as $id => $names) {
            foreach ($names as $name) {
                $this->addPosition($out, (int) $id, $name);
            }
        }
        foreach ($scan['matchedPositions'] as $text => $names) {
            foreach ($names as $name) {
                if (!in_array($name, $out['matchedPositions'][$text] ?? [], true)) {
                    $out['matchedPositions'][$text][] = $name;
                }
            }
        }
    }

    /** @param array<string,mixed> $out */
    private function addPosition(array &$out, int $ministryId, string $name): void
    {
        foreach ($out['positions'][$ministryId] ?? [] as $have) {
            if (strcasecmp($have, $name) === 0) {
                return;
            }
        }
        $out['positions'][$ministryId][] = $name;
    }

    /**
     * Greedy longest-match across the words of one chunk.
     *
     * Longest first so "Guest Services" wins over a bare "Guest", and so a
     * compound phrase is offered to the catalog whole before its parts are.
     *
     * @return array{ids:list<int>,unmatched:list<string>,matched:array<string,int>,positions:array<int,list<string>>,matchedPositions:array<string,list<string>>,last:?int,lastPhrase:?string}
     */
    private function scanPhrase(string $chunk): array
    {
        $words = preg_split('/\s+/', trim($chunk), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($words);
        $ids = [];
        $unmatched = [];
        $matched = [];
        $positions = [];
        $matchedPositions = [];
        $last = null;
        $lastPhrase = null;

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
                if ($found['ids'] !== []) {
                    $flush();
                    foreach ($found['ids'] as $id) {
                        $ids[] = $id;
                    }
                    foreach ($found['positions'] as $id => $names) {
                        foreach ($names as $name) {
                            $positions[$id][] = $name;
                            $matchedPositions[$phrase][] = $name;
                        }
                    }
                    // Remember the words the sheet used, not only the ids they
                    // became: the review form is about the spreadsheet's
                    // vocabulary, so it has to show the spreadsheet's words.
                    $matched[$phrase] = $found['ids'][0];
                    $last = $found['ids'][count($found['ids']) - 1];
                    $lastPhrase = $phrase;
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

        return [
            'ids' => $ids,
            'unmatched' => $unmatched,
            'matched' => $matched,
            'positions' => $positions,
            'matchedPositions' => $matchedPositions,
            'last' => $last,
            'lastPhrase' => $lastPhrase,
        ];
    }

    /**
     * One phrase to ministry ids and positions, via the catalog and then the
     * ministry list.
     *
     * A phrase the catalog recognises but that has no ministry is not silently
     * accepted: returning nothing lets the caller report it as unrecognised
     * instead of quietly assigning fewer ministries than the sheet named.
     *
     * @return array{ids:list<int>,positions:array<int,list<string>>}
     */
    private function phraseToIds(string $phrase): array
    {
        $none = ['ids' => [], 'positions' => []];

        // An administrator's decision first. They have told the portal what
        // this word means; a catalog guess must not overrule that, and a name
        // marked "not a ministry" must stop being reported for ever.
        if ($this->nameMap !== null && $this->nameMap->hasDecision($phrase)) {
            $decided = $this->nameMap->idFor($phrase);

            return $decided !== null ? ['ids' => [$decided], 'positions' => []] : $none;
        }

        // "Victuals GS: Usher" is two things. The catalog reads a "GS:" prefix
        // anywhere in its input and would take the whole phrase as Guest
        // Services, so a colon is only offered to it at the start of a phrase.
        $colon = strpos($phrase, ':');
        if ($colon !== false && str_contains(trim(substr($phrase, 0, $colon)), ' ')) {
            return $none;
        }

        $parsed = $this->catalog->parseHubAssignments($phrase);
        if ($parsed['ministries'] === []) {
            $direct = $this->groupIdFor($phrase);

            return $direct !== null ? ['ids' => [$direct], 'positions' => []] : $none;
        }

        $ids = [];
        $positions = [];
        foreach ($parsed['ministries'] as $name) {
            $id = $this->resolveCanonical((string) $name);
            if ($id === null) {
                return $none;
            }
            $ids[] = $id;
            foreach ($parsed['roles'][$name] ?? [] as $role) {
                $positions[$id][] = (string) $role;
            }
        }

        return ['ids' => $ids, 'positions' => $positions];
    }

    /**
     * A position as the catalog spells it for that ministry, or as written.
     */
    private function canonicalPosition(int $ministryId, string $position): string
    {
        $key = $this->catalog->key($position);
        foreach ($this->catalog->serving() as $row) {
            if ($row['roles'] === [] || $this->resolveCanonical((string) $row['name']) !== $ministryId) {
                continue;
            }
            foreach ($row['roles'] as $role) {
                if ($this->catalog->key($role) === $key) {
                    return $role;
                }
            }
        }

        return $position;
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
