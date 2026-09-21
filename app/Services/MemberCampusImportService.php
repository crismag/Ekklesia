<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MemberImportRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Multi-step campus member import:
 *  1. Ingest the primary Hub worksheet (optional secondary fill-in) into staging.
 *  2. Admins clean/edit staged rows.
 *  3. Apply only ready rows to people for the chosen campus.
 */
final readonly class MemberCampusImportService
{
    public const MAX_ROWS = 2000;

    /**
     * What an imported person's classification starts as.
     *
     * A constant rather than a setting: it is the answer to "who is this
     * spreadsheet full of", and a church that wants a different answer wants a
     * different import, not a checkbox.
     */
    public const DEFAULT_CLASSIFICATION = 'Member';

    /** Enough unknown keywords to see the pattern, not so many the alert is unreadable. */
    private const MAX_UNKNOWN_KEYWORDS_SHOWN = 12;

    private const ROW_FIELDS = [
        'last_name', 'first_name', 'middle_name', 'preferred_name', 'email', 'phone',
        'address_raw', 'address_line1', 'city', 'region', 'postal_code', 'country',
        'birth_year', 'birth_month', 'birth_day', 'member_since', 'member_type',
        'ministry', 'confirmed', 'status', 'notes', 'matched_person_id',
    ];

    public function __construct(
        private PDO $db,
        private MemberImportRepository $staging,
        private PersonAdminService $people,
        private MemberWorkbookParser $parser = new MemberWorkbookParser(),
        private MemberImportPlanner $planner = new MemberImportPlanner(),
        private MemberSheetMerger $merger = new MemberSheetMerger(),
        private MemberImportDeduper $deduper = new MemberImportDeduper(),
        // Ministry assignment is optional: absent (as in unit tests) the import
        // behaves exactly as before and simply does not touch memberships.
        private ?MemberMinistryAssigner $ministries = null,
        private ?\App\Contracts\MinistryRepository $ministryRepo = null,
        private ?\App\Adapters\Sql\SqlAuthAdapter $portalAuth = null,
        // Last, and optional: every existing positional caller keeps working.
        private ?MinistryNameMap $nameMap = null,
    ) {
    }

    public function parser(): MemberWorkbookParser
    {
        return $this->parser;
    }

    /**
     * The staging schema is owned by migrations/portal/007-member-import-staging.sql.
     *
     * This used to CREATE TABLE IF NOT EXISTS on every call, plus an
     * ensureColumn() that issued ALTER TABLE — a second migration system living
     * in application code, able to drift from the real migration without anyone
     * noticing. Now the absence of the tables is a loud operational failure that
     * names the migration to apply.
     */
    public function ensureSchema(): void
    {
        $this->staging->assertSchemaReady();
    }

    /**
     * Parse both worksheets, merge, and store a staging batch. Does not write people.
     *
     * @return array{batch:array<string,mixed>,counts:array<string,int>,warnings:list<string>}
     */
    public function ingest(
        string $path,
        int $campusId,
        // The signed-in account id (0 from the command line).
        int $actorId,
        ?string $hubSheet = MemberWorkbookParser::HUB_SHEET,
        ?string $nySheet = '',
        string $sourceLabel = '',
        bool $allowCsv = false,
        MemberMatchRules $rules = new MemberMatchRules(),
    ): array {
        $this->ensureSchema();
        if ($this->parser->isCsvPath($path) && !$allowCsv) {
            throw new InvalidArgumentException(MemberWorkbookParser::CSV_MERGE_WARNING);
        }
        $campus = $this->requireCampus($campusId);
        $parsed = $this->parser->parseWorkbook($path, $hubSheet, $nySheet);
        $hubRows = $parsed['hub']['rows'] ?? [];
        $nyRows = $parsed['ny']['rows'] ?? [];
        if (count($hubRows) + count($nyRows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('That workbook has too many member rows (max ' . self::MAX_ROWS . ').');
        }
        $merged = $this->merger->merge($hubRows, $nyRows);
        if ($merged === []) {
            throw new InvalidArgumentException('No member rows were found on those worksheets.');
        }

        // Every row is staged, duplicates included. Rows that look like the
        // same person share a group, and the suggested decision for each group
        // is applied below as an ordinary, reversible decision — the importer
        // can change it on the staging page. Dropping rows here is what lost a
        // parent "Jessie" to a child "Jessie James" with nothing left to undo.
        $groupOf = [];
        foreach ($this->deduper->groups($merged, $rules) as $n => $indexes) {
            foreach ($indexes as $i) {
                $groupOf[$i] = $n + 1;
            }
        }

        // Matched by row position. Matching back by email or name key gave
        // every row sharing a household inbox the same person.
        $plan = $this->planner->plan(array_map($this->toPlannerRow(...), $merged), $this->people->matchIndex(), $campusId, $rules);
        $matchByIndex = [];
        foreach ($plan['update'] as $item) {
            $matchByIndex[(int) $item['index']] = (int) $item['person_id'];
        }

        $warnings = array_merge(
            $parsed['warnings'],
            $this->ministryKeywordWarnings($merged),
            $this->addressWarnings($merged),
            $this->memberTypeWarnings($merged),
        );
        $stagingRows = [];
        foreach ($merged as $i => $row) {
            $stagingRows[] = $this->toStagingRow($row, $matchByIndex[$i] ?? null) + ['duplicate_group' => $groupOf[$i] ?? null];
        }
        $batchId = $this->staging->createBatch(
            [
                'campus_id' => $campusId,
                'source_label' => $sourceLabel !== '' ? $sourceLabel : ($campus['name'] . ' workbook'),
                'hub_sheet' => $parsed['hub']['sheet'] ?? null,
                'ny_sheet' => $parsed['ny']['sheet'] ?? null,
                'hub_updated' => $parsed['hub']['updated'] ?? null,
                'ny_updated' => $parsed['ny']['updated'] ?? null,
                'warnings' => $warnings,
                'duplicate_report' => null,
                'match_rules' => $rules->toArray(),
                'created_by_account_id' => $actorId,
            ],
            $stagingRows
        );

        if ($groupOf !== []) {
            $this->suggestDuplicateDecisions($batchId);
        }

        $batch = $this->batch($batchId);
        return ['batch' => $batch ?? [], 'counts' => $this->rowCounts($batchId), 'warnings' => $warnings];
    }

    /**
     * Map a merged worksheet row to the staging row shape.
     *
     * Pulled out of the insert loop: deciding which person a row matches, and
     * normalising blanks to NULL, are import rules — they belong here, while
     * the INSERT itself belongs behind the repository.
     *
     * @param array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function toStagingRow(array $row, ?int $match): array
    {
        $email = (string) ($row['email'] ?? '');
        $since = (string) ($row['member_since'] ?? '');

        $blankToNull = static fn (string $k): ?string => ($row[$k] ?? '') !== '' ? (string) $row[$k] : null;

        return [
            'last_name' => $row['last_name'],
            'first_name' => $blankToNull('first_name'),
            'middle_name' => $blankToNull('middle_name'),
            'preferred_name' => $blankToNull('preferred_name'),
            'email' => $email !== '' ? $email : null,
            'phone' => $blankToNull('phone'),
            'address_raw' => $blankToNull('address_raw'),
            'address_line1' => $blankToNull('address_line1'),
            'city' => $blankToNull('city'),
            'region' => $blankToNull('region'),
            'postal_code' => $blankToNull('postal_code'),
            'country' => $blankToNull('country'),
            'birth_year' => $row['birth_year'] ?: null,
            'birth_month' => $row['birth_month'] ?: null,
            'birth_day' => $row['birth_day'] ?: null,
            'member_since' => $since !== '' ? $since : null,
            'member_type' => $blankToNull('member_type'),
            'ministry' => $blankToNull('ministry'),
            'confirmed' => $blankToNull('confirmed'),
            'source' => $row['source'],
            'filled_from' => $row['filled_from'] ?? [],
            'status' => $row['status'],
            'matched_person_id' => $match,
            'notes' => $blankToNull('notes'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function batches(int $limit = 20): array
    {
        return $this->staging->listBatches($limit);
    }

    /** @return array<string,mixed>|null */
    public function batch(int $id): ?array
    {
        return $this->staging->findBatch($id);
    }

    /**
     * @return array{rows:list<array<string,mixed>>,counts:array<string,int>}
     */
    public function rows(int $batchId, string $status = ''): array
    {
        return [
            'rows' => $this->staging->listRows($batchId, $status),
            'counts' => $this->rowCounts($batchId),
        ];
    }

    /** @return array<string,int> */
    public function rowCounts(int $batchId): array
    {
        return $this->staging->rowCounts($batchId);
    }

    public function updateRow(int $id, string $field, mixed $value): void
    {
        if (!in_array($field, self::ROW_FIELDS, true)) {
            throw new InvalidArgumentException('That field cannot be edited.');
        }
        $row = $this->staging->findRowWithBatchStatus($id);
        if ($row === null) {
            throw new InvalidArgumentException('Unknown staging row.');
        }
        if ((string) $row['batch_status'] === 'applied') {
            throw new InvalidArgumentException('That batch has already been applied.');
        }
        if ($field === 'status' && !in_array((string) $value, ['draft', 'ready', 'skip'], true)) {
            throw new InvalidArgumentException('Invalid row status.');
        }
        if ($field === 'email' && is_string($value) && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email looks invalid.');
        }
        if ($field === 'last_name' && trim((string) $value) === '') {
            throw new InvalidArgumentException('Last name is required.');
        }
        $v = is_string($value) ? trim($value) : $value;
        if ($v === '') {
            $v = null;
        }
        $this->staging->updateRowField($id, $field, $v, self::ROW_FIELDS);
    }

    public function markReadyMissing(int $batchId): int
    {
        return $this->staging->markReadyMissing($batchId);
    }

    /**
     * Apply ready staged rows to the campus. Draft/skip rows are left out of the roster.
     *
     * @return array{created:int,updated:int,removed:int,warnings:list<string>}
     */
    public function applyBatch(int $batchId, int $actorId): array
    {
        $batch = $this->batch($batchId);
        if ($batch === null) {
            throw new InvalidArgumentException('Unknown import batch.');
        }
        if ((string) $batch['status'] === 'applied') {
            throw new InvalidArgumentException('That batch was already applied.');
        }
        $data = $this->rows($batchId, 'ready');
        if ($data['rows'] === []) {
            throw new InvalidArgumentException('Mark at least one cleaned row as Ready before applying.');
        }
        $fileRows = array_map($this->toPlannerRow(...), $data['rows']);
        $result = $this->apply($fileRows, (int) $batch['campus_id'], $actorId, $this->batchRules($batch));
        $this->staging->markBatchApplied($batchId);
        return $result;
    }

    /** The match rules a batch was staged with. @param array<string,mixed> $batch */
    public function batchRules(array $batch): MemberMatchRules
    {
        return MemberMatchRules::fromJson(isset($batch['match_rules']) ? (string) $batch['match_rules'] : null);
    }

    /**
     * The duplicate groups of a batch, each with its rows and decision, for the
     * staging page. Empty for a batch staged before decisions existed (its
     * report is the old list of removed names; see legacyDuplicates()).
     *
     * @return list<array{group:int,decision:string,keep:?int,suggested:int,rows:list<array<string,mixed>>,filled:array<string,mixed>}>
     */
    public function duplicateGroups(int $batchId): array
    {
        $batch = $this->batch($batchId);
        $report = is_array($batch['duplicate_report'] ?? null) ? $batch['duplicate_report'] : [];
        if (($report['version'] ?? 0) !== 2) {
            return [];
        }
        $byGroup = [];
        foreach ($this->staging->listRows($batchId) as $row) {
            $g = (int) ($row['duplicate_group'] ?? 0);
            if ($g > 0) {
                $byGroup[$g][] = $row;
            }
        }
        $out = [];
        foreach ($report['groups'] ?? [] as $entry) {
            $g = (int) $entry['group'];
            $rows = $byGroup[$g] ?? [];
            usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
            // Show each row as the workbook had it: the kept row's own values,
            // with what the merge filled in listed beside them. Otherwise a
            // parent "Jessie" whose name was expanded from a child "Jessie
            // James" shows as a second "Jessie James".
            $filled = [];
            $keep = isset($entry['keep']) ? (int) $entry['keep'] : null;
            foreach ((array) ($entry['filled'] ?? []) as $field => [$before, $after]) {
                $filled[(string) $field] = $after;
                foreach ($rows as $k => $r) {
                    if ((int) $r['id'] === $keep && (string) ($r[$field] ?? '') === (string) ($after ?? '')) {
                        $rows[$k][$field] = $before;
                    }
                }
            }
            $out[] = [
                'group' => $g,
                'decision' => (string) ($entry['decision'] ?? 'keep'),
                'keep' => $keep,
                'suggested' => (int) ($entry['suggested'] ?? 0),
                'rows' => $rows,
                'filled' => $filled,
            ];
        }

        return $out;
    }

    /**
     * A batch staged before duplicate decisions: the names that were removed.
     *
     * @return list<array{name:string,kept:string}>
     */
    public function legacyDuplicates(array $batch): array
    {
        $report = is_array($batch['duplicate_report'] ?? null) ? $batch['duplicate_report'] : [];

        return ($report['version'] ?? 0) === 2 ? [] : array_values(array_filter($report, 'is_array'));
    }

    /**
     * Record the suggested decision for every group of a freshly staged batch:
     * keep the row with more filled fields, as the import always has.
     */
    private function suggestDuplicateDecisions(int $batchId): void
    {
        $byGroup = [];
        foreach ($this->staging->listRows($batchId) as $row) {
            $g = (int) ($row['duplicate_group'] ?? 0);
            if ($g > 0) {
                $byGroup[$g][] = $row;
            }
        }
        ksort($byGroup);
        $report = ['version' => 2, 'groups' => []];
        foreach ($byGroup as $g => $rows) {
            usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
            $suggested = (int) $rows[$this->deduper->keeperIndex($rows)]['id'];
            $report['groups'][] = [
                'group' => $g,
                'rows' => array_map(static fn (array $r): int => (int) $r['id'], $rows),
                'suggested' => $suggested,
                'decision' => 'separate',
                'keep' => null,
                'skipped' => [],
                'filled' => [],
            ];
        }
        $this->staging->saveDuplicateReport($batchId, $report);
        foreach ($report['groups'] as $entry) {
            $this->resolveDuplicate($batchId, (int) $entry['group'], 'keep', (int) $entry['suggested']);
        }
    }

    /**
     * Decide one duplicate group: keep one row as the person (the others are
     * set to Skip and fill its blanks), or keep every row as a different
     * person. The previous decision is undone first, so a group can be
     * changed back and forth until the batch is applied.
     *
     * Undoing only touches what the decision wrote and nobody has changed
     * since: a filled field still holding the filled value, a row still on
     * Skip. An edit made by hand in between is left alone.
     */
    public function resolveDuplicate(int $batchId, int $group, string $decision, int $keepRowId = 0): void
    {
        if (!in_array($decision, ['keep', 'separate'], true)) {
            throw new InvalidArgumentException('Choose which row to keep, or keep them all.');
        }
        $batch = $this->batch($batchId);
        if ($batch === null) {
            throw new InvalidArgumentException('Unknown import batch.');
        }
        if ((string) $batch['status'] === 'applied') {
            throw new InvalidArgumentException('That batch has already been applied.');
        }
        $report = is_array($batch['duplicate_report'] ?? null) ? $batch['duplicate_report'] : [];
        $at = null;
        foreach ($report['groups'] ?? [] as $k => $entry) {
            if ((int) $entry['group'] === $group) {
                $at = $k;
            }
        }
        if (($report['version'] ?? 0) !== 2 || $at === null) {
            throw new InvalidArgumentException('Unknown duplicate group.');
        }
        $entry = $report['groups'][$at];

        $rows = [];
        foreach ($this->staging->listRows($batchId) as $row) {
            if ((int) ($row['duplicate_group'] ?? 0) === $group) {
                $rows[(int) $row['id']] = $row;
            }
        }
        ksort($rows);
        if ($decision === 'keep' && !isset($rows[$keepRowId])) {
            throw new InvalidArgumentException('That row is not part of this duplicate group.');
        }
        $write = function (int $id, string $field, mixed $value) use (&$rows): void {
            $this->staging->updateRowField($id, $field, $value, self::ROW_FIELDS);
            $rows[$id][$field] = $value;
        };
        $same = static fn (mixed $x, mixed $y): bool => (string) ($x ?? '') === (string) ($y ?? '');

        // Undo the previous decision.
        $prevKeep = isset($entry['keep']) ? (int) $entry['keep'] : 0;
        if ($prevKeep > 0 && isset($rows[$prevKeep])) {
            foreach ((array) ($entry['filled'] ?? []) as $field => [$before, $after]) {
                if (in_array($field, self::ROW_FIELDS, true) && $same($rows[$prevKeep][$field] ?? null, $after)) {
                    $write($prevKeep, $field, $before);
                }
            }
        }
        foreach ((array) ($entry['skipped'] ?? []) as $id => $status) {
            if (isset($rows[(int) $id]) && $rows[(int) $id]['status'] === 'skip') {
                $write((int) $id, 'status', (string) $status);
            }
        }
        $entry['filled'] = [];
        $entry['skipped'] = [];
        $entry['keep'] = null;

        // Apply the new one.
        if ($decision === 'keep') {
            $keeper = $rows[$keepRowId];
            $merged = $keeper;
            foreach ($rows as $id => $row) {
                if ($id === $keepRowId) {
                    continue;
                }
                $merged = $this->deduper->fillFrom($merged, $row);
                $entry['skipped'][(string) $id] = (string) $row['status'];
                $write($id, 'status', 'skip');
            }
            foreach (self::ROW_FIELDS as $field) {
                if (!$same($keeper[$field] ?? null, $merged[$field] ?? null)) {
                    $entry['filled'][$field] = [$keeper[$field] ?? null, $merged[$field]];
                    $write($keepRowId, $field, $merged[$field]);
                }
            }
            $entry['keep'] = $keepRowId;
        }
        $entry['decision'] = $decision;
        // JSON objects, even when empty, so the stored shape never changes.
        $entry['filled'] = (object) $entry['filled'];
        $entry['skipped'] = (object) $entry['skipped'];
        $report['groups'][$at] = $entry;
        $this->staging->saveDuplicateReport($batchId, $report);
    }

    public function discardBatch(int $batchId): void
    {
        $batch = $this->batch($batchId);
        if ($batch === null) {
            throw new InvalidArgumentException('Unknown import batch.');
        }
        if ((string) $batch['status'] === 'applied') {
            throw new InvalidArgumentException('Applied batches cannot be discarded.');
        }
        $this->staging->deleteBatch($batchId);
    }

    /**
     * Parse worksheets and match against people without writing.
     *
     * @return array{
     *   sheets:list<string>,
     *   primary:?string,
     *   secondary:?string,
     *   rows:int,
     *   ready:int,
     *   draft:int,
     *   plan:array{create:int,update:int,remove:int,warnings:list<string>},
     *   unlink:list<array<string,mixed>>,
     *   warnings:list<string>
     * }
     */
    public function preview(
        string $path,
        int $campusId,
        ?string $hubSheet = MemberWorkbookParser::HUB_SHEET,
        ?string $nySheet = '',
        MemberMatchRules $rules = new MemberMatchRules(),
    ): array {
        $this->requireCampus($campusId);
        $parsed = $this->parser->parseWorkbook($path, $hubSheet, $nySheet);
        $merged = $this->merger->merge($parsed['hub']['rows'] ?? [], $parsed['ny']['rows'] ?? []);
        $deduped = $this->deduper->dedupe($merged, $rules);
        $merged = $deduped['rows'];
        $ready = 0;
        $draft = 0;
        foreach ($merged as $row) {
            if (($row['status'] ?? '') === 'ready') {
                $ready++;
            } else {
                $draft++;
            }
        }
        $plan = $this->planner->plan(array_map($this->toPlannerRow(...), $merged), $this->people->matchIndex(), $campusId, $rules);
        return [
            'sheets' => $parsed['sheets'],
            'primary' => $parsed['hub']['sheet'] ?? null,
            'secondary' => $parsed['ny']['sheet'] ?? null,
            'rows' => count($merged),
            'ready' => $ready,
            'draft' => $draft,
            'duplicates_removed' => $deduped['removed'],
            'plan' => [
                'create' => count($plan['create']),
                'update' => count($plan['update']),
                'remove' => count($plan['remove']),
                'warnings' => $plan['warnings'],
            ],
            'unlink' => $plan['remove'],
            'warnings' => array_merge(
                $parsed['warnings'],
                $deduped['warnings'],
                $this->ministryKeywordWarnings($merged),
                $this->addressWarnings($merged),
                $this->memberTypeWarnings($merged),
            ),
        ];
    }

    /**
     * @param list<array<string,mixed>> $fileRows
     * @return array{created:int,updated:int,removed:int,warnings:list<string>}
     */
    public function apply(array $fileRows, int $campusId, int $actorId, MemberMatchRules $rules = new MemberMatchRules()): array
    {
        $this->requireCampus($campusId);
        if ($fileRows === []) {
            throw new InvalidArgumentException('Nothing to import.');
        }
        $plan = $this->planner->plan($fileRows, $this->people->matchIndex(), $campusId, $rules);
        $clsId = $this->importClassificationId();
        $typeIds = $this->memberTypeIds();

        // Build every payload first and check it, so a bad value is reported as
        // a list of named rows the administrator can act on — rather than
        // aborting mid-apply on the first one with a message that says only
        // "An email address looks invalid" and never says whose.
        $problems = [];
        $dropped = [];
        foreach (['update', 'create'] as $kind) {
            foreach ($plan[$kind] as $item) {
                $personId = $kind === 'update' ? (int) $item['person_id'] : 0;
                $payload = $this->toSavePayload($item['row'], $campusId, $clsId, $typeIds, $personId);
                $who = trim((string) ($item['row']['last_name'] ?? '') . ', ' . (string) ($item['row']['first_name'] ?? ''));

                // Unusable addresses already on file are dropped by the payload
                // builder; say so rather than letting them vanish quietly.
                $existing = $personId > 0 ? $this->people->find($personId) : null;
                if (is_array($existing)) {
                    foreach (MemberImportPayload::unusableEmails($existing) as $field => $_) {
                        if (trim((string) ($payload[$field] ?? '')) === '') {
                            $dropped[] = $who . ' (person ' . $personId . ')';
                        }
                    }
                }

                foreach (MemberImportPayload::unusableEmails($payload) as $field => $value) {
                    $problems[] = $who . ' — ' . $field . ' is not a valid email address.';
                }
                if (trim((string) ($payload['last_name'] ?? '')) === '') {
                    $problems[] = $who . ' — last name is required.';
                }
            }
        }
        if ($problems !== []) {
            throw new InvalidArgumentException(
                count($problems) . ' row(s) cannot be applied. Fix them in the staged list, then apply again:'
                . "\n" . implode("\n", array_slice($problems, 0, 25))
                . (count($problems) > 25 ? "\n… and " . (count($problems) - 25) . ' more.' : '')
            );
        }

        $this->db->beginTransaction();
        try {
            foreach ($plan['update'] as $item) {
                $this->people->save(
                    $this->toSavePayload($item['row'], $campusId, $clsId, $typeIds, (int) $item['person_id']),
                    $actorId
                );
            }
            // Keep the id save() hands back. It used to be discarded, and
            // assignMinistries then skipped every created row for want of a
            // person_id — so on the run that first loaded a campus, which is
            // the run where almost everybody is new, nobody got their
            // ministries. It looked like a mapping fault and was not one.
            foreach ($plan['create'] as $i => $item) {
                $newId = $this->people->save(
                    $this->toSavePayload($item['row'], $campusId, $clsId, $typeIds, 0),
                    $actorId
                );
                if ($newId > 0) {
                    $plan['create'][$i]['person_id'] = $newId;
                }
            }
            foreach ($plan['remove'] as $item) {
                $this->people->setPrimaryCampus((int) $item['person_id'], null, $actorId);
            }
            $ministryOutcome = $this->assignMinistries($plan);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $warnings = $plan['warnings'];
        // State the classification rather than leaving it implicit. Everyone
        // arriving from a spreadsheet is filed as a member, and an
        // administrator who disagrees should find that out here rather than by
        // noticing it on the directory afterwards.
        $classified = count($plan['create']) + count($plan['update']);
        if ($classified > 0) {
            $warnings[] = $classified . ' person(s) were classified as "' . self::DEFAULT_CLASSIFICATION
                . '", which is where every imported person starts. Change any that should differ from '
                . 'the people list.';
        }
        foreach (($ministryOutcome['warnings'] ?? []) as $w) {
            $warnings[] = $w;
        }
        if ($dropped !== []) {
            $unique = array_values(array_unique($dropped));
            $warnings[] = count($unique) . ' record(s) had an email address on file that is not a valid address, '
                . 'so it could not be written back and was cleared: ' . implode('; ', $unique) . '.';
        }

        return [
            'created' => count($plan['create']),
            'updated' => count($plan['update']),
            'removed' => count($plan['remove']),
            'warnings' => $warnings,
        ];
    }

    /** @return array{filename:string,csv:string,count:int} */
    public function exportCsv(int $campusId): array
    {
        $campus = $this->requireCampus($campusId);
        $people = $this->people->exportCampus($campusId);
        $ministryCells = $this->ministryCellsForCampus($campusId);
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new RuntimeException('Could not build the export.');
        }
        fputcsv($fh, [
            'confirmed w/ the brethren',
            'LAST NAME, FIRST NAME',
            'Preferred Name',
            'MIDDLE NAME',
            'Birthday',
            'Address',
            'Contact Information',
            'Email',
            'Member Since',
            'Member Type',
            'Ministry',
        ]);
        $months = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        foreach ($people as $p) {
            $last = trim((string) $p['last_name']);
            $first = trim((string) $p['first_name']);
            $name = $last . ($first !== '' ? ', ' . $first : '');
            $bm = (int) ($p['birth_month'] ?? 0);
            $bd = (int) ($p['birth_day'] ?? 0);
            $by = (int) ($p['birth_year'] ?? 0);
            $bday = '';
            if ($bm > 0 && $bd > 0) {
                $bday = ($months[$bm] ?? (string) $bm) . ' ' . $bd . ($by > 0 ? ', ' . $by : '');
            }
            $addrParts = array_filter([
                trim((string) ($p['address_line1'] ?? '')),
                trim((string) ($p['city'] ?? '')),
                trim(implode(' ', array_filter([
                    (string) ($p['region'] ?? ''),
                    (string) ($p['postal_code'] ?? ''),
                ]))),
            ]);
            fputcsv($fh, [
                '',
                $name,
                '',
                trim((string) ($p['middle_name'] ?? '')),
                $bday,
                implode(', ', $addrParts),
                (string) ($p['mobile_phone'] ?? ''),
                (string) ($p['email'] ?? ''),
                (string) ($p['member_since'] ?? ''),
                (string) ($p['member_type'] ?? ''),
                $ministryCells[(int) ($p['id'] ?? 0)] ?? '',
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $campus['name']));
        $slug = trim($slug, '-') ?: 'campus';
        return [
            'filename' => 'members-' . $slug . '-' . date('Y-m-d') . '.csv',
            'csv' => $csv,
            'count' => count($people),
        ];
    }

    public function downloadGoogleSheet(string $urlOrId): string
    {
        $id = $this->extractSpreadsheetId($urlOrId);
        $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($id) . '/export?format=xlsx';
        $bytes = $this->httpGet($url);
        if ($bytes === '' || !str_starts_with($bytes, 'PK')) {
            throw new InvalidArgumentException(
                'Could not download that Google Sheet as Excel. Make sure the file is shared with "Anyone with the link".'
            );
        }
        $tmp = tempnam(sys_get_temp_dir(), 'members-');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }
        $xlsx = $tmp . '.xlsx';
        rename($tmp, $xlsx);
        file_put_contents($xlsx, $bytes);
        return $xlsx;
    }

    public function extractSpreadsheetId(string $urlOrId): string
    {
        $urlOrId = trim($urlOrId);
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $urlOrId, $m)) {
            return $m[1];
        }
        if (preg_match('/^[a-zA-Z0-9-_]{20,}$/', $urlOrId)) {
            return $urlOrId;
        }
        throw new InvalidArgumentException('That does not look like a Google Sheets link or id.');
    }

    /** @param array<string,mixed> $row */
    public function toPlannerRow(array $row): array
    {
        $iso = '';
        $bm = (int) ($row['birth_month'] ?? 0);
        $bd = (int) ($row['birth_day'] ?? 0);
        $by = (int) ($row['birth_year'] ?? 0);
        if ($bm > 0 && $bd > 0) {
            $iso = sprintf('%04d-%02d-%02d', $by > 0 ? $by : 1900, $bm, $bd);
        }
        $since = (string) ($row['member_since'] ?? '');
        return [
            'name_raw' => trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'middle_name' => (string) ($row['middle_name'] ?? ''),
            'preferred_name' => (string) ($row['preferred_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'address_raw' => (string) ($row['address_raw'] ?? ''),
            'address_line1' => (string) ($row['address_line1'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'region' => (string) ($row['region'] ?? 'Ontario'),
            'postal_code' => (string) ($row['postal_code'] ?? ''),
            'country' => (string) ($row['country'] ?? 'CA'),
            'birthday' => [
                'year' => $by > 0 ? $by : null,
                'month' => $bm > 0 ? $bm : null,
                'day' => $bd > 0 ? $bd : null,
                'iso' => $iso !== '' ? $iso : null,
            ],
            'member_since' => [
                'iso' => $since !== '' ? substr($since, 0, 10) : null,
            ],
            'member_type' => (string) ($row['member_type'] ?? ''),
            'ministry' => (string) ($row['ministry'] ?? ''),
        ];
    }

    /**
     * Fill blank member types on a staged batch from each person's age.
     *
     * Deliberately an explicit action rather than something ingest() does. It
     * writes to the staging rows only — nothing reaches people until the
     * batch is applied — so the suggestions stay reviewable and editable, and
     * discarding the batch discards them.
     *
     * A row that already has a member type is left alone, and so is one whose
     * age falls in a band too weak to be worth acting on.
     *
     * @return array{filled:int,skipped:int,byType:array<string,int>}
     */
    public function fillMemberTypesFromAge(int $batchId): array
    {
        $suggester = $this->memberTypeSuggester();
        $filled = 0;
        $skipped = 0;
        $byType = [];

        foreach ($this->rows($batchId)['rows'] as $row) {
            if (trim((string) ($row['member_type'] ?? '')) !== '') {
                continue;
            }
            $birthYear = (int) ($row['birth_year'] ?? 0);
            $hit = $suggester->forPerson('', $birthYear > 0 ? $birthYear : null);
            if ($hit === null) {
                $skipped++;
                continue;
            }
            $this->updateRow((int) $row['id'], 'member_type', $hit['type']);
            $byType[$hit['type']] = ($byType[$hit['type']] ?? 0) + 1;
            $filled++;
        }

        return ['filled' => $filled, 'skipped' => $skipped, 'byType' => $byType];
    }

    /**
     * The suggester, built from the shipped age bands.
     *
     * Constructed per call rather than injected: this class is readonly, and
     * the object is a small pure lookup over a config file.
     */
    private function memberTypeSuggester(): MemberTypeSuggester
    {
        return MemberTypeSuggester::fromFile(
            dirname(__DIR__, 2) . '/config/member-type-age-bands.json'
        );
    }

    /**
     * Say how many rows arrive with no member type, and how many of those a
     * birth year could speak to.
     *
     * Reported, not applied. Age is evidence about a member type and not proof
     * of one — the roster has Trailblazer running 3 to 75 — so filling the
     * column silently would put a guess where an administrator would read a
     * fact. The staging screen offers it as an action instead.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function memberTypeWarnings(array $rows): array
    {
        $suggester = $this->memberTypeSuggester();
        $missing = 0;
        $suggestable = 0;
        $byType = [];

        foreach ($rows as $row) {
            if (trim((string) ($row['member_type'] ?? '')) !== '') {
                continue;
            }
            $missing++;
            $birthYear = (int) ($row['birth_year'] ?? 0);
            $hit = $suggester->forPerson('', $birthYear > 0 ? $birthYear : null);
            if ($hit !== null) {
                $suggestable++;
                $byType[$hit['type']] = ($byType[$hit['type']] ?? 0) + 1;
            }
        }

        if ($missing === 0) {
            return [];
        }
        if ($suggestable === 0) {
            return [
                $missing . ' row(s) have no member type, and no birth date to suggest one from. '
                . 'Set them on the staged rows, or leave them blank and set them later.',
            ];
        }

        arsort($byType);
        $parts = [];
        foreach ($byType as $type => $count) {
            $parts[] = $count . ' × ' . $type;
        }

        return [
            $missing . ' row(s) have no member type. Their ages suggest one for ' . $suggestable
            . ' of them (' . implode(', ', $parts) . '). Nothing has been filled in: age indicates a '
            . 'member type but does not settle it, so use "Fill blank member types from age" on the '
            . 'staged rows if you want them applied, and check them before importing.',
        ];
    }

    /**
     * Report addresses the workbook wrote in a shape that could not be read whole.
     *
     * The address column is free text, so a portion of it never parses
     * cleanly — a missing postal code cannot be invented, and a line with no
     * recognisable city has to be looked at. This says how many and why, at
     * staging time, while address_raw is still sitting beside the parsed
     * fields and the original can be re-read as many times as needed. After
     * apply the raw line is gone and only the parse survives.
     *
     * Counts and reasons only. The addresses themselves are member data and do
     * not belong in a message stored on the batch.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function addressWarnings(array $rows): array
    {
        $reasons = [
            'no-postal-code' => 'no postal code in the source text',
            'no-city' => 'no city could be identified',
            'no-street' => 'no street line',
            'street-has-no-number' => 'a street line with no street number',
        ];

        $counts = [];
        $withAddress = 0;
        $needReview = 0;
        foreach ($rows as $row) {
            $raw = trim((string) ($row['address_raw'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $withAddress++;
            $parsed = $this->parser->parseAddressDetailed($raw);
            $flagged = false;
            foreach ($parsed['issues'] as $issue) {
                if (!isset($reasons[$issue])) {
                    continue;
                }
                $counts[$issue] = ($counts[$issue] ?? 0) + 1;
                $flagged = true;
            }
            if ($flagged) {
                $needReview++;
            }
        }
        if ($needReview === 0) {
            return [];
        }

        $parts = [];
        foreach ($counts as $issue => $count) {
            $parts[] = $count . ' with ' . $reasons[$issue];
        }

        return [
            $needReview . ' of ' . $withAddress . ' address(es) could not be read completely: '
            . implode('; ', $parts) . '. These rows still import — the parts that were read are correct — '
            . 'but the missing pieces are missing in the workbook itself. Correct them in the sheet, or '
            . 'edit the staged row, before applying.',
        ];
    }

    /**
     * Report ministry keywords the workbook uses that no ministry answers to.
     *
     * This runs while parsing rather than while applying, because parsing is
     * when an administrator can still act on it — correct a spelling in the
     * sheet, or add the name as an alias. By apply time the memberships are
     * already wrong.
     *
     * It is more than cosmetic: the workbook is authoritative, so a keyword
     * that matches nothing does not merely fail to add a membership, it can
     * remove one the person still holds.
     *
     * Only the keyword and its frequency are reported. Naming the people would
     * write roster identities into a message that is stored on the batch.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function ministryKeywordWarnings(array $rows): array
    {
        if ($this->ministries === null) {
            return [];
        }

        $unknown = [];
        foreach ($rows as $row) {
            $cell = trim((string) ($row['ministry'] ?? ''));
            if ($cell === '') {
                continue;
            }
            foreach ($this->ministries->resolve($cell)['unmatched'] as $keyword) {
                $key = strtolower($keyword);
                $unknown[$key] ??= ['label' => $keyword, 'count' => 0];
                $unknown[$key]['count']++;
            }
        }
        if ($unknown === []) {
            return [];
        }

        uasort($unknown, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $shown = array_slice($unknown, 0, self::MAX_UNKNOWN_KEYWORDS_SHOWN);
        $parts = [];
        foreach ($shown as $entry) {
            $parts[] = $entry['label'] . ' (' . $entry['count'] . ')';
        }
        $more = count($unknown) - count($shown);

        return [
            count($unknown) . ' ministry keyword(s) in this workbook match no ministry and will not be imported: '
            . implode(', ', $parts) . ($more > 0 ? ', and ' . $more . ' more' : '') . '. '
            . 'Because the workbook decides who serves where, anyone listed only under an unmatched keyword '
            . 'will also lose the membership it was meant to name. Correct the spelling in the sheet, or add '
            . 'the name as an alias in the ministry catalogue, before applying.',
        ];
    }

    /**
     * Give imported people their ministry memberships and positions.
     *
     * The workbook is authoritative for the people this import applies: a
     * ministry their cell does not list is removed (see the guard below for
     * the sheet that names no ministry at all).
     *
     * Leaders keep what their leadership implies. Someone who leads a ministry
     * — a leader membership, or a leader account role scoped to it — keeps the
     * membership even when the sheet omits it, and is never turned back into a
     * plain member: a new membership is written with the member role, an
     * existing one keeps its role. Leadership itself is never written here.
     *
     * Positions follow the same authority as memberships. For every ministry
     * the cell lists, the person's positions there become exactly the ones the
     * cell gave it, so a ministry listed with no positions has its positions
     * cleared. A ministry the cell does not list keeps its positions only when
     * the membership itself is kept (leaders); otherwise they go with it.
     *
     * @param array<string,mixed> $plan
     * @return array{warnings:list<string>}
     */
    private function assignMinistries(array $plan): array
    {
        if ($this->ministries === null || $this->ministryRepo === null) {
            return ['warnings' => []];
        }

        // Work out what the workbook says for each person first.
        $desired = [];
        $desiredPositions = [];
        $unmatched = [];
        $seen = [];
        $sheetMentionsAnyMinistry = false;
        foreach (['update', 'create'] as $kind) {
            foreach ($plan[$kind] as $item) {
                $personId = (int) ($item['person_id'] ?? 0);
                if ($personId <= 0) {
                    // Only a row that genuinely failed to save reaches this now;
                    // created rows carry the id save() returned.
                    continue;
                }
                $cell = (string) ($item['row']['ministry'] ?? '');
                if (trim($cell) !== '') {
                    $sheetMentionsAnyMinistry = true;
                }
                $resolved = $this->ministries->resolve($cell);
                foreach ($resolved['unmatched'] as $name) {
                    $unmatched[$name] = ($unmatched[$name] ?? 0) + 1;
                }
                // Every name the sheet used, matched or not, with the ministry
                // it became. The review form is meant to answer "did Psalmist
                // go where I think it went?" as well as "what failed?", and a
                // record of only the failures cannot answer the first.
                foreach ($resolved['matched'] as $name => $ministryId) {
                    $positions = $seen[$name]['positions'] ?? [];
                    foreach ($resolved['matchedPositions'][$name] ?? [] as $position) {
                        if (!in_array($position, $positions, true)) {
                            $positions[] = $position;
                        }
                    }
                    $seen[$name] = ['count' => (int) ($seen[$name]['count'] ?? 0) + 1,
                                    'id' => (int) $ministryId, 'status' => 'matched',
                                    'positions' => $positions];
                }
                $desired[$personId] = $resolved['ids'];
                $desiredPositions[$personId] = $resolved['positions'];
            }
        }

        if ($desired === []) {
            return ['warnings' => []];
        }

        $current = $this->ministryRepo->listMinistryIdsForPeople(array_keys($desired));

        // Leadership is granted in the portal, not in the workbook, so a leader
        // keeps the membership their leadership implies even when the sheet
        // does not list it.
        $leaderOf = [];
        if ($this->portalAuth !== null) {
            foreach ($this->portalAuth->listMinistryLeaderPersonIds() as $ministryId => $people) {
                foreach ($people as $personId) {
                    $leaderOf[(int) $personId][(int) $ministryId] = true;
                }
            }
        }
        foreach ($this->ministryRepo->listLeadersByMinistry(null) as $leader) {
            $leaderOf[(int) $leader['person_id']][(int) $leader['ministry_id']] = true;
        }

        $added = 0;
        $removed = 0;
        $keptForLeaders = 0;
        $positionsSet = 0;

        foreach ($desired as $personId => $wanted) {
            $have = $current[$personId] ?? [];
            $leads = $leaderOf[$personId] ?? [];

            foreach ($wanted as $ministryId) {
                // Only a new membership is written: setting the role on an
                // existing one would turn a leader back into a member.
                if (!in_array($ministryId, $have, true)) {
                    $this->ministryRepo->setMemberRole($personId, $ministryId, MemberMinistryAssigner::MEMBER_ROLE);
                    $added++;
                }
                $positions = $desiredPositions[$personId][$ministryId] ?? [];
                $this->ministryRepo->setMemberPositions($personId, $ministryId, $positions);
                $positionsSet += count($positions);
            }

            // The workbook is authoritative: a ministry it does not list is not
            // a current ministry. An empty cell is a real answer — some people
            // serve in none — which is why this runs even when nothing is
            // wanted.
            //
            // Except when the sheet mentions no ministry anywhere. That looks
            // identical to a ministry column that failed to map, and clearing
            // every membership on a parsing accident is not recoverable from
            // the workbook. Refusing to delete is the safe reading of an
            // ambiguous sheet.
            if (!$sheetMentionsAnyMinistry) {
                continue;
            }

            foreach ($have as $ministryId) {
                if (in_array($ministryId, $wanted, true)) {
                    continue;
                }
                if (isset($leads[$ministryId])) {
                    $keptForLeaders++;
                    continue;
                }
                $this->ministryRepo->removeMemberFromMinistry($personId, $ministryId);
                $removed++;
            }
        }

        $warnings = [];
        if ($added > 0) {
            $warnings[] = $added . ' ministry membership(s) were added from the workbook (role: member).';
        }
        if ($positionsSet > 0) {
            $warnings[] = $positionsSet . ' ministry position(s) were set from the workbook.';
        }
        if ($removed > 0) {
            $warnings[] = $removed . ' ministry membership(s) were removed because the workbook no longer lists them.';
        }
        if ($keptForLeaders > 0) {
            $warnings[] = $keptForLeaders . ' membership(s) were kept despite not being listed, because the person '
                . 'leads that ministry in the portal.';
        }
        if (!$sheetMentionsAnyMinistry) {
            $warnings[] = 'No ministry was named anywhere in this workbook, so existing memberships were left alone. '
                . 'If the sheet really does have a Ministry column, check that its header was recognised before '
                . 'treating this import as authoritative.';
        }
        // Write what this run saw where somebody can act on it. A sentence in a
        // flash message told an administrator a name had been ignored and gave
        // them nowhere to say what it should have been, so the same name was
        // ignored again on the next import, and the one after that.
        if ($this->nameMap !== null) {
            foreach ($seen as $name => $info) {
                $this->nameMap->observe((string) $name, (int) $info['count'], (int) $info['id'], 'matched', $info['positions']);
            }
            foreach ($unmatched as $name => $count) {
                $this->nameMap->observe((string) $name, (int) $count, null, 'unmatched');
            }
            if ($seen !== [] || $unmatched !== []) {
                $this->nameMap->save();
            }
        }

        if ($unmatched !== []) {
            arsort($unmatched);
            $shown = array_slice(array_keys($unmatched), 0, 10);
            $warnings[] = count($unmatched) . ' ministry name(s) did not match any ministry and were ignored: '
                . implode(', ', $shown) . (count($unmatched) > 10 ? ', …' : '')
                . '. Say what they mean under “Ministry names from the workbook” and re-apply.';
        }

        return ['warnings' => $warnings];
    }

    /**
     * Each campus member's Ministry cell, as the workbook writes it.
     *
     * "Guest Services (Usher, Emcee), Psalmists": the ministries a person
     * currently belongs to, by name, each followed by its positions. The import
     * reads that form back to the same memberships and positions, which is
     * what lets an exported roster be edited and re-imported.
     *
     * Empty when ministries are not wired (as in unit tests).
     *
     * @return array<int,string> person id => cell
     */
    public function ministryCellsForCampus(int $campusId): array
    {
        if ($this->ministryRepo === null) {
            return [];
        }

        $byPerson = [];
        $ministries = $this->ministryRepo->listMinistriesAdmin(null);
        usort($ministries, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
        foreach ($ministries as $ministry) {
            if (empty($ministry['active'])) {
                continue;
            }
            foreach ($this->ministryRepo->listMinistryMembers((int) $ministry['ministry_id'], $campusId) as $member) {
                $byPerson[(int) $member['person_id']][] = [
                    'name' => (string) $ministry['name'],
                    'positions' => $member['positions'],
                ];
            }
        }

        $cells = [];
        foreach ($byPerson as $personId => $memberships) {
            $cells[$personId] = MemberRosterXlsxWriter::ministryCell($memberships);
        }

        return $cells;
    }

    /** @return array<string,mixed> */
    private function requireCampus(int $campusId): array
    {
        if ($campusId <= 0) {
            throw new InvalidArgumentException('Choose a campus to import into.');
        }
        $row = $this->staging->findCampus($campusId);
        if ($row === null) {
            throw new InvalidArgumentException('Unknown campus.');
        }
        return $row;
    }

    /** @return array<string,int> */
    private function memberTypeIds(): array
    {
        $out = [];
        foreach ($this->people->memberTypes() as $opt) {
            $out[strtolower((string) $opt['name'])] = (int) $opt['id'];
        }
        return $out;
    }

    /**
     * The classification every imported person starts with.
     *
     * A spreadsheet says who is on the roster, not what their standing is, so
     * everyone arriving through an import begins as a member and an
     * administrator changes it from there if they need to.
     *
     * Resolving it used to end in "?? 1" — take option id 1 if the name is not
     * found. That is right only by coincidence: it happens to be Member here.
     * Delete that option and re-create the list in another order and every
     * imported person would be silently filed under whatever landed at id 1,
     * with nothing on screen to say so. Failing is better: a wrong
     * classification on a whole roster is expensive to notice and tedious to
     * undo, and the fix is one option away.
     *
     * @throws InvalidArgumentException when the classification does not exist
     */
    private function importClassificationId(): int
    {
        $wanted = self::DEFAULT_CLASSIFICATION;
        $id = $this->classificationId($wanted);
        if ($id !== null) {
            return $id;
        }

        $available = array_map(
            static fn (array $o): string => (string) $o['name'],
            $this->people->classifications(),
        );

        throw new InvalidArgumentException(sprintf(
            'Imported people are classified as "%s", but there is no such classification. '
            . 'Add it under Member types, or rename an existing one. %s',
            $wanted,
            $available === []
                ? 'No classifications are set up at all.'
                : 'Currently available: ' . implode(', ', $available) . '.',
        ));
    }

    private function classificationId(string $name): ?int
    {
        foreach ($this->people->classifications() as $opt) {
            if (strcasecmp((string) $opt['name'], $name) === 0) {
                return (int) $opt['id'];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int> $typeIds
     * @return array<string,mixed>
     */
    private function toSavePayload(array $row, int $campusId, int $statusId, array $typeIds, int $personId): array
    {
        $existing = $personId > 0 ? $this->people->find($personId) : null;
        return MemberImportPayload::forSave($row, $campusId, $statusId, $typeIds, $personId, $existing);
    }

    private function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_USERAGENT => 'ChristlikenessPortal/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($body) && $code >= 200 && $code < 400) {
                return $body;
            }
            return '';
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => 45, 'follow_location' => 1, 'header' => "User-Agent: ChristlikenessPortal/1.0\r\n"],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : '';
    }
}
