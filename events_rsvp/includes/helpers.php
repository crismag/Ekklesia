<?php
/**
 * Standalone helpers for the Events RSVP module: config, escaping, CSRF, event
 * loading (ChurchCRM events_event or module-owned rsvp_events), and ChurchCRM
 * member matching.
 */
declare(strict_types=1);


// Presentation only: the active portal theme's custom properties. Reads
// config/theme.json directly, so this app keeps its own bootstrap and
// database access and stays independent of the portal container.
require_once dirname(__DIR__, 2) . '/shared/theme-tokens.php';

require_once __DIR__ . '/db.php';

if (!function_exists('rv_config')) {
    function rv_config(): array
    {
        static $c = null;
        if ($c === null) {
            $j = __DIR__ . '/../config/rsvp.config.json';
            $c = is_readable($j) ? (json_decode((string) file_get_contents($j), true) ?: []) : [];
        }
        return $c;
    }
    function rv_cfg(string $path, $default = null)
    {
        $c = rv_config();
        foreach (explode('.', $path) as $k) {
            if (!is_array($c) || !array_key_exists($k, $c)) { return $default; }
            $c = $c[$k];
        }
        return $c;
    }

    function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
    function rv_str($v): string { return trim((string) ($v ?? '')); }
    function rv_intn($v): ?int { $v = rv_str($v); return $v === '' ? null : (int) $v; }
    function rv_digits(?string $s): string { return preg_replace('/\D+/', '', (string) $s) ?? ''; }

    function rv_session(): void { if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); } }
    function rv_csrf(): string { rv_session(); if (empty($_SESSION['rv_csrf'])) { $_SESSION['rv_csrf'] = bin2hex(random_bytes(32)); } return $_SESSION['rv_csrf']; }
    function rv_csrf_ok(?string $t): bool { rv_session(); return is_string($t) && !empty($_SESSION['rv_csrf']) && hash_equals($_SESSION['rv_csrf'], $t); }
    function rv_csrf_rotate(): void { rv_session(); $_SESSION['rv_csrf'] = bin2hex(random_bytes(32)); }
    function rv_db(): PDO { return rsvp_db(); }

    // ---- Rotating admin access code (ChristLikeness + WORD, with expiry) -----
    function rv_admin_module(): string { return 'rsvp'; }
    function rv_admin_prefix(): string { return (string) rv_cfg('admin_access.prefix', 'ChristLikeness'); }

    function rv_admin_gen_word(): string
    {
        $words = ['Sunrise', 'Grace', 'Harvest', 'Cedar', 'River', 'Beacon', 'Anchor',
                  'Summit', 'Haven', 'Journey', 'Radiant', 'Kindred', 'Shepherd', 'Cornerstone'];
        return $words[random_int(0, count($words) - 1)] . random_int(100, 999);
    }

    function rv_admin_active(): ?array
    {
        $st = rv_db()->prepare('SELECT * FROM signup_admin_access WHERE module = :m ORDER BY id DESC LIMIT 1');
        $st->execute([':m' => rv_admin_module()]);
        $r = $st->fetch();
        if (!$r) { return null; }
        $exp = strtotime((string) $r['expires_at']);
        $active = $exp !== false && $exp >= time();
        return [
            'word' => (string) $r['word'], 'code' => rv_admin_prefix() . $r['word'],
            'issued_at' => (string) $r['issued_at'], 'expires_at' => (string) $r['expires_at'],
            'active' => $active, 'remaining_days' => $active ? (int) ceil(($exp - time()) / 86400) : 0,
            'note' => (string) ($r['note'] ?? ''),
        ];
    }

    function rv_admin_set_word(string $word, int $days, ?string $note = null): array
    {
        $word = preg_replace('/[^A-Za-z0-9]/', '', $word) ?? '';
        if ($word === '') { $word = rv_admin_gen_word(); }
        $days = max(1, min(365, $days));
        $expires = date('Y-m-d H:i:s', time() + $days * 86400);
        $st = rv_db()->prepare('INSERT INTO signup_admin_access (module, word, expires_at, note) VALUES (:m, :w, :e, :n)');
        $st->execute([':m' => rv_admin_module(), ':w' => $word, ':e' => $expires, ':n' => $note ?: null]);
        return rv_admin_active();
    }

    function rv_admin_ensure_seed(): void
    {
        if (rv_admin_active() === null) {
            rv_admin_set_word((string) rv_cfg('admin_access.default_word', 'Welcome'), (int) rv_cfg('admin_access.default_days', 7), 'auto-seeded');
        }
    }

    function rv_admin_valid_code(?string $given): bool
    {
        $given = (string) $given;
        if ($given === '') { return false; }
        $master = (string) (rsvp_secure()['admin_token'] ?? '');
        if ($master !== '' && hash_equals($master, $given)) { return true; }
        $a = rv_admin_active();
        return $a !== null && $a['active'] && hash_equals($a['code'], $given);
    }
    function rv_json($data, int $code = 200): void { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; }

    /**
     * Load an event by id from the configured source. Returns a normalized shape
     * (id, title, date, time, location, description, source) or null.
     */
    function rv_load_event(PDO $db, int $eventId): ?array
    {
        if ($eventId <= 0) { return null; }
        $source = (string) rv_cfg('event_source', 'churchcrm');

        if ($source === 'rsvp_events') {
            $stmt = $db->prepare('SELECT id, title, event_date, event_time, location, description
                                    FROM rsvp_events WHERE id = :id AND is_active = 1 LIMIT 1');
            $stmt->execute([':id' => $eventId]);
            $r = $stmt->fetch();
            if (!$r) { return null; }
            return [
                'id' => (int) $r['id'], 'title' => (string) $r['title'],
                'date' => $r['event_date'], 'time' => $r['event_time'],
                'location' => (string) ($r['location'] ?? ''), 'description' => (string) ($r['description'] ?? ''),
                'source' => 'rsvp_events',
            ];
        }

        // Default: ChurchCRM events_event (+ soonest upcoming/today occurrence).
        $stmt = $db->prepare(
            'SELECT e.event_id, e.event_title, e.event_desc, e.event_start, e.event_end,
                    e.custom_location_name, e.custom_location_address,
                    (SELECT MIN(occurrence_start) FROM event_occurrence o
                      WHERE o.event_id = e.event_id AND o.is_cancelled = 0
                        AND o.occurrence_start >= CURDATE()) AS next_occ
               FROM events_event e
              WHERE e.event_id = :id AND e.inactive = 0 LIMIT 1'
        );
        $stmt->execute([':id' => $eventId]);
        $r = $stmt->fetch();
        if (!$r) { return null; }
        $start = $r['next_occ'] ?: $r['event_start'];
        $loc = rv_str($r['custom_location_name']);
        if (rv_str($r['custom_location_address']) !== '') {
            $loc = $loc === '' ? rv_str($r['custom_location_address']) : $loc . ' — ' . rv_str($r['custom_location_address']);
        }
        return [
            'id' => (int) $r['event_id'], 'title' => (string) $r['event_title'],
            'date' => $start ? substr((string) $start, 0, 10) : null,
            'time' => $start ? substr((string) $start, 11, 5) : null,
            'location' => $loc, 'description' => (string) ($r['event_desc'] ?? ''),
            'source' => 'churchcrm',
        ];
    }

    /**
     * Normalize a value for comparison; '' for empty/placeholder so blanks never
     * match each other.
     */
    function rv_nz($v): string
    {
        $s = strtolower(trim((string) ($v ?? '')));
        static $placeholders = [
            '', 'n/a', 'na', 'none', 'null', 'nil', 'nan', 'unknown', 'unkown',
            'tbd', 'test', '-', '--', '.', 'x', 'xx', 'xxx', 'xxxx', 'notgiven',
            'noemail', 'no email', 'nophone', 'no phone', '0', '00', '000',
        ];
        return in_array($s, $placeholders, true) ? '' : $s;
    }

    /** Canonical phone key (last 10 digits) or '' when too short. */
    function rv_ph($v): string
    {
        $d = rv_digits((string) ($v ?? ''));
        return strlen($d) >= 7 ? substr($d, -10) : '';
    }

    /**
     * Build a normalized person; blank/placeholder emails and phones are dropped.
     * @return array{first:string,last:string,bm:int,by:int,emails:list<string>,phones:list<string>}
     */
    function rv_person($first, $last, $bm, $by, array $emails, array $phones): array
    {
        $en = [];
        foreach ($emails as $e) { $e = rv_nz($e); if ($e !== '') { $en[$e] = 1; } }
        $pn = [];
        foreach ($phones as $p) { $p = rv_ph($p); if ($p !== '') { $pn[$p] = 1; } }
        return [
            'first'  => rv_nz($first), 'last' => rv_nz($last),
            'bm' => (int) $bm, 'by' => (int) $by,
            'emails' => array_keys($en), 'phones' => array_keys($pn),
        ];
    }

    /**
     * 5-component comparison; each counts only when both sides provided a value.
     * @return list<string>
     */
    /** Forgiving first-name equality (exact, leading token, or prefix). */
    function rv_first_eq(string $a, string $b): bool
    {
        if ($a === '' || $b === '') { return false; }
        if ($a === $b) { return true; }
        if (strtok($a, ' ') === strtok($b, ' ')) { return true; }
        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }

    function rv_components(array $a, array $b): array
    {
        $why = [];
        if (rv_first_eq($a['first'], $b['first']))             { $why[] = 'first name'; }
        if ($a['last'] !== ''  && $a['last'] === $b['last'])   { $why[] = 'last name'; }
        if ($a['bm'] > 0 && $a['by'] > 0 && $a['bm'] === $b['bm'] && $a['by'] === $b['by']) { $why[] = 'birth month/year'; }
        if (array_intersect($a['emails'], $b['emails']))       { $why[] = 'email'; }
        foreach ($a['phones'] as $pa) {
            if (in_array($pa, $b['phones'], true)) { $why[] = 'mobile'; break; }
        }
        return $why;
    }

    /** Classify matched components into 'exact' | 'possible' | 'none'. */
    function rv_match_level(array $why): string
    {
        $has = static fn (string $k): bool => in_array($k, $why, true);
        $first = $has('first name'); $last = $has('last name');
        $birth = $has('birth month/year');
        $strong = $has('email') || $has('mobile') || $birth;
        if ($first && $last && $strong) { return 'exact'; }
        if (($has('email') || $has('mobile')) && $last) { return 'exact'; }
        if ($last && $birth) { return 'exact'; }
        if (count($why) >= 2 || $has('email') || $has('mobile')) { return 'possible'; }
        return 'none';
    }

    /**
     * Match a submitted person against existing ChurchCRM members (person_per)
     * using the 5-component comparison.
     * @return array{exact:list<array<string,mixed>>,possible:list<array<string,mixed>>}
     */
    function rv_match_members(PDO $db, array $p): array
    {
        $in    = rv_person($p['first_name'] ?? '', $p['last_name'] ?? '', $p['birth_month'] ?? 0, $p['birth_year'] ?? 0, [$p['email'] ?? ''], [$p['phone'] ?? '']);
        $last  = $in['last'];
        $email = $in['emails'][0] ?? '';
        if ($last === '' && $email === '' && ($in['phones'] === [])) {
            return ['exact' => [], 'possible' => []];
        }

        $conds = [];
        $params = [];
        if ($last !== '')  { $conds[] = 'LOWER(per_LastName) = :last'; $params[':last'] = $last; }
        if ($email !== '') { $conds[] = '(LOWER(per_Email) = :e1 OR LOWER(per_WorkEmail) = :e2)'; $params[':e1'] = $email; $params[':e2'] = $email; }
        $sql = 'SELECT per_ID AS id, per_FirstName AS fn, per_LastName AS ln,
                       per_Email AS email, per_WorkEmail AS wemail,
                       per_CellPhone AS cell, per_HomePhone AS home, per_WorkPhone AS work,
                       per_BirthMonth AS bm, per_BirthYear AS by2, per_City AS city
                  FROM person_per WHERE ' . implode(' OR ', $conds) . ' LIMIT 200';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $exact = []; $possible = [];
        foreach ($stmt->fetchAll() as $r) {
            $cand = rv_person($r['fn'], $r['ln'], $r['bm'], $r['by2'], [$r['email'], $r['wemail']], [$r['cell'], $r['home'], $r['work']]);
            $why  = rv_components($in, $cand);
            $level = rv_match_level($why);
            if ($level === 'none') { continue; }
            $card = ['id' => (int) $r['id'], 'name' => trim(rv_str($r['fn']) . ' ' . rv_str($r['ln'])), 'city' => rv_str($r['city']), 'why' => $why];
            if ($level === 'exact') { $exact[] = $card; } else { $possible[] = $card; }
        }
        return ['exact' => $exact, 'possible' => $possible];
    }

    /**
     * Self-duplicate detection against people_signup_temp (staged guests) using
     * the same 5-component comparison. Optionally scope to one source event.
     * @return array{exact:list<array<string,mixed>>,possible:list<array<string,mixed>>}
     */
    function rv_find_signup_dupes(PDO $db, array $p, ?int $eventId = null): array
    {
        $in    = rv_person($p['first_name'] ?? '', $p['last_name'] ?? '', $p['birth_month'] ?? 0, $p['birth_year'] ?? 0, [$p['email'] ?? ''], [$p['phone'] ?? '']);
        $last  = $in['last'];
        $email = $in['emails'][0] ?? '';
        if ($last === '' && $email === '' && ($in['phones'] === [])) {
            return ['exact' => [], 'possible' => []];
        }

        $conds = [];
        $params = [];
        if ($last !== '')  { $conds[] = 'LOWER(last_name) = :last'; $params[':last'] = $last; }
        if ($email !== '') { $conds[] = 'LOWER(email) = :e1'; $params[':e1'] = $email; }
        $sql = 'SELECT id, first_name AS fn, last_name AS ln, email, phone,
                       birth_month AS bm, birth_year AS by2, city, created_at
                  FROM people_signup_temp
                 WHERE migration_status IN (\'new\', \'reviewed\', \'duplicate\')
                   AND (' . implode(' OR ', $conds) . ')';
        if ($eventId !== null) { $sql .= ' AND source_event_id = :ev'; $params[':ev'] = $eventId; }
        $sql .= ' ORDER BY created_at DESC LIMIT 100';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $exact = []; $possible = [];
        foreach ($stmt->fetchAll() as $r) {
            $cand = rv_person($r['fn'], $r['ln'], $r['bm'], $r['by2'], [$r['email']], [$r['phone']]);
            $why  = rv_components($in, $cand);
            $level = rv_match_level($why);
            if ($level === 'none') { continue; }
            $card = ['id' => (int) $r['id'], 'name' => trim(rv_str($r['fn']) . ' ' . rv_str($r['ln'])), 'city' => rv_str($r['city']), 'when' => (string) $r['created_at'], 'why' => $why];
            if ($level === 'exact') { $exact[] = $card; } else { $possible[] = $card; }
        }
        return ['exact' => $exact, 'possible' => $possible];
    }
}
