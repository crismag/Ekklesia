<?php

declare(strict_types=1);

namespace App\Services;

/**
 * What the workbook calls a ministry, and which ministry that actually is.
 *
 * The catalog in config/ministry-catalog.json holds the names and aliases the
 * church already knew about. This holds the ones it did not: whatever a
 * spreadsheet actually said, what an administrator decided it meant, and how
 * often it has turned up.
 *
 * It exists because the previous behaviour was to report unrecognised names as
 * a sentence — "3 ministry name(s) did not match any ministry and were
 * ignored: Dance, …" — and then forget them. Nobody could act on that. The
 * name was ignored again on the next import, and the import after that.
 *
 * Two kinds of entry:
 *
 *   a decision   name → ministry id, or 0 meaning "this is not a ministry,
 *                stop asking me about it"
 *   an observation  the raw text as the sheet wrote it, a count, and when it
 *                was last seen — so the form can show what is actually in the
 *                workbook rather than only what failed
 *
 * A decision wins over the catalog. An administrator who has said what a name
 * means should not be overruled by a guess.
 */
final class MinistryNameMap
{
    /** @param array{map?:array<string,int>,seen?:array<string,array<string,mixed>>} $data */
    private function __construct(
        private readonly string $path,
        private array $data,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = ['map' => [], 'seen' => []];
        }
        $decoded['map'] = is_array($decoded['map'] ?? null) ? $decoded['map'] : [];
        $decoded['seen'] = is_array($decoded['seen'] ?? null) ? $decoded['seen'] : [];

        return new self($path, $decoded);
    }

    /**
     * The comparison key.
     *
     * Deliberately the same shape of normalisation the assigner uses on group
     * names — case, punctuation and spacing removed — so "G & A", "g&a" and
     * "G and A" are one decision rather than three.
     */
    public static function key(string $name): string
    {
        $v = strtolower(trim($name));
        $v = (string) preg_replace('/[^a-z0-9]+/', ' ', $v);
        // "and" and "the" are dropped for the same reason the assigner drops
        // them: "G&A", "G and A" and "G & A" are one name somebody wrote three
        // ways, and asking an administrator to decide each spelling separately
        // would be asking them to do the computer's job.
        $words = array_values(array_filter(
            preg_split('/\s+/', trim($v)) ?: [],
            static fn (string $w): bool => $w !== '' && $w !== 'and' && $w !== 'the',
        ));

        return implode(' ', $words);
    }

    /** The ministry an administrator said this name means, if they said. */
    public function idFor(string $name): ?int
    {
        $k = self::key($name);
        if ($k === '' || !array_key_exists($k, $this->data['map'])) {
            return null;
        }
        $id = (int) $this->data['map'][$k];

        return $id > 0 ? $id : null;
    }

    /** Whether a name has been marked "not a ministry". */
    public function isIgnored(string $name): bool
    {
        $k = self::key($name);

        return array_key_exists($k, $this->data['map']) && (int) $this->data['map'][$k] === 0;
    }

    public function hasDecision(string $name): bool
    {
        return array_key_exists(self::key($name), $this->data['map']);
    }

    /**
     * Record that a workbook used this name, and how many rows used it.
     *
     * Observations are kept for names that mapped as well as ones that did not.
     * A form showing only the failures cannot answer "did Psalmist go to the
     * right place?", which is the question an administrator actually has after
     * an import surprises them.
     */
    public function observe(string $raw, int $count, ?int $resolvedId, string $status): void
    {
        $k = self::key($raw);
        if ($k === '') {
            return;
        }
        $prev = is_array($this->data['seen'][$k] ?? null) ? $this->data['seen'][$k] : [];
        $this->data['seen'][$k] = [
            'raw' => trim($raw) !== '' ? trim($raw) : (string) ($prev['raw'] ?? $k),
            'count' => (int) ($prev['count'] ?? 0) + max(0, $count),
            'lastCount' => max(0, $count),
            'resolvedId' => $resolvedId,
            'status' => $status,
            'lastSeen' => date('Y-m-d H:i:s'),
        ];
    }

    /** An administrator's decision. A ministry id, or 0 to ignore the name. */
    public function decide(string $name, int $ministryId): void
    {
        $k = self::key($name);
        if ($k === '') {
            return;
        }
        $this->data['map'][$k] = max(0, $ministryId);
    }

    public function forget(string $name): void
    {
        unset($this->data['map'][self::key($name)]);
    }

    /**
     * Everything the last parses saw, worst first.
     *
     * Sorted so the names that need a decision are at the top and, among those,
     * the ones affecting the most rows come first — the order somebody would
     * work through them in.
     *
     * @return list<array{key:string,raw:string,count:int,status:string,decidedId:?int,needsDecision:bool}>
     */
    public function rows(): array
    {
        $out = [];
        foreach ($this->data['seen'] as $k => $row) {
            $decided = array_key_exists($k, $this->data['map']) ? (int) $this->data['map'][$k] : null;
            $status = (string) ($row['status'] ?? 'unmatched');
            $needs = $decided === null && $status === 'unmatched';
            $out[] = [
                'key' => (string) $k,
                'raw' => (string) ($row['raw'] ?? $k),
                'count' => (int) ($row['count'] ?? 0),
                'lastCount' => (int) ($row['lastCount'] ?? 0),
                'lastSeen' => (string) ($row['lastSeen'] ?? ''),
                'status' => $status,
                'resolvedId' => isset($row['resolvedId']) ? (int) $row['resolvedId'] : null,
                'decidedId' => $decided,
                'needsDecision' => $needs,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            if ($a['needsDecision'] !== $b['needsDecision']) {
                return $a['needsDecision'] ? -1 : 1;
            }
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }

            return strcmp($a['raw'], $b['raw']);
        });

        return $out;
    }

    public function pendingCount(): int
    {
        $n = 0;
        foreach ($this->rows() as $r) {
            if ($r['needsDecision']) {
                $n++;
            }
        }

        return $n;
    }

    public function save(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(
            $this->path,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX,
        );
    }
}
