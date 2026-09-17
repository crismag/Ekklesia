<?php
/**
 * Standalone helpers for the Events RSVP module: config, escaping, CSRF, event
 * loading (member database events), and member matching.
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
    /** Visitors database (SQLite): RSVPs, registrations, access codes. */
    function rv_db(): PDO { return rsvp_db(); }
    /** Member database (MySQL): people, events, event_occurrences. */
    function rv_members_db(): PDO { return rsvp_members_db(); }

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
        $st = rv_db()->prepare('SELECT * FROM visitor_admin_access_codes WHERE module = :m ORDER BY id DESC LIMIT 1');
        $st->execute([':m' => rv_admin_module()]);
        $r = $st->fetch();
        if (!$r) { return null; }
        $exp = rsvp_timestamp((string) $r['expires_at']);
        $active = $exp !== false && $exp >= time();
        return [
            'word' => (string) $r['code'], 'code' => rv_admin_prefix() . $r['code'],
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
        $expires = rsvp_now($days * 86400);
        // issued_at and expires_at are church local time (see rsvp_now()).
        $st = rv_db()->prepare('INSERT INTO visitor_admin_access_codes (module, code, issued_at, expires_at, note) VALUES (:m, :w, :i, :e, :n)');
        $st->execute([':m' => rv_admin_module(), ':w' => $word, ':i' => rsvp_now(), ':e' => $expires, ':n' => $note ?: null]);
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
     * Load an RSVP-able event by id from the member database (events), with
     * the soonest scheduled date that is today or later. Returns a normalized
     * shape (id, occurrence_id, title, date, time, location, description) or
     * null. $db is the member database connection (rv_members_db()).
     */
    function rv_load_event(PDO $db, int $eventId): ?array
    {
        if ($eventId <= 0) { return null; }

        $stmt = $db->prepare(
            "SELECT e.id, e.title, e.summary, e.starts_on, e.start_time,
                    e.location_name, e.location_address,
                    (SELECT o.id FROM event_occurrences o
                      WHERE o.event_id = e.id AND o.status = 'scheduled'
                        AND o.starts_at >= CURDATE()
                      ORDER BY o.starts_at LIMIT 1) AS next_occurrence_id,
                    (SELECT MIN(o.starts_at) FROM event_occurrences o
                      WHERE o.event_id = e.id AND o.status = 'scheduled'
                        AND o.starts_at >= CURDATE()) AS next_starts_at
               FROM events e
              WHERE e.id = :id AND e.is_active = 1 AND e.archived_at IS NULL LIMIT 1"
        );
        $stmt->execute([':id' => $eventId]);
        $r = $stmt->fetch();
        if (!$r) { return null; }
        if ($r['next_starts_at']) {
            $date = substr((string) $r['next_starts_at'], 0, 10);
            $time = substr((string) $r['next_starts_at'], 11, 5);
        } else {
            $date = $r['starts_on'] ? (string) $r['starts_on'] : null;
            $time = $r['start_time'] ? substr((string) $r['start_time'], 0, 5) : null;
        }
        $loc = rv_str($r['location_name']);
        if (rv_str($r['location_address']) !== '') {
            $loc = $loc === '' ? rv_str($r['location_address']) : $loc . ' — ' . rv_str($r['location_address']);
        }
        return [
            'id' => (int) $r['id'],
            'occurrence_id' => $r['next_occurrence_id'] !== null ? (int) $r['next_occurrence_id'] : null,
            'title' => (string) $r['title'],
            'date' => $date,
            'time' => $time,
            'location' => $loc, 'description' => (string) ($r['summary'] ?? ''),
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
        // Exact needs the person's own name: households share email and phone.
        if ($first && $last && $strong) { return 'exact'; }
        if (count($why) >= 2 || $has('email') || $has('mobile')) { return 'possible'; }
        return 'none';
    }

    /**
     * Match a submitted person against existing people in the member database
     * using the 5-component comparison. $db is rv_members_db().
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
        if ($last !== '')  { $conds[] = 'LOWER(last_name) = :last'; $params[':last'] = $last; }
        if ($email !== '') { $conds[] = 'LOWER(email) = :e1'; $params[':e1'] = $email; }
        if ($conds === []) { return ['exact' => [], 'possible' => []]; }
        $sql = 'SELECT id, first_name AS fn, last_name AS ln, email,
                       mobile_phone AS mobile, home_phone AS home,
                       birth_month AS bm, birth_year AS by2, city
                  FROM people WHERE ' . implode(' OR ', $conds) . ' LIMIT 200';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $exact = []; $possible = [];
        foreach ($stmt->fetchAll() as $r) {
            $cand = rv_person($r['fn'], $r['ln'], $r['bm'], $r['by2'], [$r['email']], [$r['mobile'], $r['home']]);
            $why  = rv_components($in, $cand);
            $level = rv_match_level($why);
            if ($level === 'none') { continue; }
            $card = ['id' => (int) $r['id'], 'name' => trim(rv_str($r['fn']) . ' ' . rv_str($r['ln'])), 'city' => rv_str($r['city']), 'why' => $why];
            if ($level === 'exact') { $exact[] = $card; } else { $possible[] = $card; }
        }
        return ['exact' => $exact, 'possible' => $possible];
    }

    /**
     * Self-duplicate detection against visitor_registrations (visitors database)
     * using the same 5-component comparison. Optionally scope to one source event.
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
                  FROM visitor_registrations
                 WHERE status IN (\'new\', \'reviewed\', \'duplicate\')
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
