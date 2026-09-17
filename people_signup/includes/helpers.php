<?php
/**
 * Standalone helpers for the People Sign-Up module: config loading, output
 * escaping, CSRF, request helpers, and member matching.
 */
declare(strict_types=1);


// Presentation only: the active portal theme's custom properties. Reads
// config/theme.json directly, so this app keeps its own bootstrap and
// database access and stays independent of the portal container.
require_once dirname(__DIR__, 2) . '/shared/theme-tokens.php';

require_once __DIR__ . '/db.php';

if (!function_exists('sg_config')) {
    function sg_config(): array
    {
        static $c = null;
        if ($c === null) {
            $j = __DIR__ . '/../config/signup.config.json';
            $c = is_readable($j) ? (json_decode((string) file_get_contents($j), true) ?: []) : [];
        }
        return $c;
    }
    /** Dot-path config lookup: sg_cfg('app.title', 'default'). */
    function sg_cfg(string $path, $default = null)
    {
        $c = sg_config();
        foreach (explode('.', $path) as $k) {
            if (!is_array($c) || !array_key_exists($k, $c)) {
                return $default;
            }
            $c = $c[$k];
        }
        return $c;
    }

    function e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
    function sg_str($v): string { return trim((string) ($v ?? '')); }
    function sg_intn($v): ?int { $v = sg_str($v); return $v === '' ? null : (int) $v; }

    function sg_session(): void { if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); } }
    function sg_csrf(): string
    {
        sg_session();
        if (empty($_SESSION['sg_csrf'])) { $_SESSION['sg_csrf'] = bin2hex(random_bytes(32)); }
        return $_SESSION['sg_csrf'];
    }
    function sg_csrf_ok(?string $t): bool
    {
        sg_session();
        return is_string($t) && !empty($_SESSION['sg_csrf']) && hash_equals($_SESSION['sg_csrf'], $t);
    }
    function sg_csrf_rotate(): void { sg_session(); $_SESSION['sg_csrf'] = bin2hex(random_bytes(32)); }
    /** Visitors database (SQLite): registrations, promotions, access codes. */
    function sg_db(): PDO { return signup_db(); }
    /** Member database (MySQL): people, member_types. */
    function sg_members_db(): PDO { return signup_members_db(); }

    // ---- Rotating admin access code (ChristLikeness + WORD, with expiry) -----
    function sg_admin_module(): string { return 'signup'; }
    function sg_admin_prefix(): string { return (string) sg_cfg('admin_access.prefix', 'ChristLikeness'); }

    /** Generate a memorable WORD ID, e.g. "Harvest482". */
    function sg_admin_gen_word(): string
    {
        $words = ['Sunrise', 'Grace', 'Harvest', 'Cedar', 'River', 'Beacon', 'Anchor',
                  'Summit', 'Haven', 'Journey', 'Radiant', 'Kindred', 'Shepherd', 'Cornerstone'];
        return $words[random_int(0, count($words) - 1)] . random_int(100, 999);
    }

    /** Current access code row (latest for this module) with computed status, or null. */
    function sg_admin_active(): ?array
    {
        $st = sg_db()->prepare('SELECT * FROM visitor_admin_access_codes WHERE module = :m ORDER BY id DESC LIMIT 1');
        $st->execute([':m' => sg_admin_module()]);
        $r = $st->fetch();
        if (!$r) { return null; }
        $exp = signup_timestamp((string) $r['expires_at']);
        $active = $exp !== false && $exp >= time();
        return [
            'word'        => (string) $r['code'],
            'code'        => sg_admin_prefix() . $r['code'],
            'issued_at'   => (string) $r['issued_at'],
            'expires_at'  => (string) $r['expires_at'],
            'active'      => $active,
            'remaining_days' => $active ? (int) ceil(($exp - time()) / 86400) : 0,
            'note'        => (string) ($r['note'] ?? ''),
        ];
    }

    /** Issue a new WORD valid for $days days. Returns the new active status. */
    function sg_admin_set_word(string $word, int $days, ?string $note = null): array
    {
        $word = preg_replace('/[^A-Za-z0-9]/', '', $word) ?? '';
        if ($word === '') { $word = sg_admin_gen_word(); }
        $days = max(1, min(365, $days));
        $expires = signup_now($days * 86400);
        // issued_at and expires_at are church local time (see signup_now()).
        $st = sg_db()->prepare('INSERT INTO visitor_admin_access_codes (module, code, issued_at, expires_at, note) VALUES (:m, :w, :i, :e, :n)');
        $st->execute([':m' => sg_admin_module(), ':w' => $word, ':i' => signup_now(), ':e' => $expires, ':n' => $note ?: null]);
        return sg_admin_active();
    }

    /** Seed a default code the first time, so the area is never left with no code. */
    function sg_admin_ensure_seed(): void
    {
        if (sg_admin_active() === null) {
            sg_admin_set_word((string) sg_cfg('admin_access.default_word', 'Welcome'), (int) sg_cfg('admin_access.default_days', 7), 'auto-seeded');
        }
    }

    /** Validate a submitted access code: current non-expired WORD code, or the master recovery key. */
    function sg_admin_valid_code(?string $given): bool
    {
        $given = (string) $given;
        if ($given === '') { return false; }
        $master = (string) (signup_secure()['admin_token'] ?? '');
        if ($master !== '' && hash_equals($master, $given)) { return true; }
        $a = sg_admin_active();
        return $a !== null && $a['active'] && hash_equals($a['code'], $given);
    }

    /**
     * Member type names a registration may carry (member_types.name in the
     * member database). Promotion resolves the name to member_type_id.
     *
     * @return list<string>
     */
    function sg_member_type_names(): array
    {
        return array_values(array_map('strval', (array) sg_cfg('member_types', ['Radical', 'Trailblazer', 'G&A'])));
    }

    /**
     * Auto-detect Member Type. Age bands: ≤10 → G&A; 11–29 → Radical;
     * ≥30 → Trailblazer. A married adult (non-child) → Trailblazer regardless
     * of age. Returns null when there's nothing to go on (no birth year, not married).
     */
    function sg_detect_member_type(?int $birthYear, bool $married): ?string
    {
        $age = ($birthYear && $birthYear > 1900) ? ((int) date('Y') - $birthYear) : null;
        $isChild = $age !== null && $age <= 10;

        if ($married && !$isChild) { return 'Trailblazer'; }
        if ($age === null) { return null; }
        if ($age <= 10) { return 'G&A'; }
        if ($age <= 29) { return 'Radical'; }      // 11–15 uncertain band defaults here
        return 'Trailblazer';
    }
    function sg_json($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    function sg_digits(?string $s): string { return preg_replace('/\D+/', '', (string) $s) ?? ''; }

    /**
     * Normalize a free-text value for comparison. Returns '' for anything that is
     * empty or an obvious placeholder, so blank/placeholder values never match
     * each other (e.g. two people with email "n/a" are NOT the same person).
     */
    function sg_nz($v): string
    {
        $s = strtolower(trim((string) ($v ?? '')));
        static $placeholders = [
            '', 'n/a', 'na', 'none', 'null', 'nil', 'nan', 'unknown', 'unkown',
            'tbd', 'test', '-', '--', '.', 'x', 'xx', 'xxx', 'xxxx', 'notgiven',
            'noemail', 'no email', 'nophone', 'no phone', '0', '00', '000',
        ];
        return in_array($s, $placeholders, true) ? '' : $s;
    }

    /** Canonical phone key (last 10 digits) or '' when there aren't enough digits. */
    function sg_ph($v): string
    {
        $d = sg_digits((string) ($v ?? ''));
        return strlen($d) >= 7 ? substr($d, -10) : '';
    }

    /**
     * Build a normalized person from raw parts. `emails`/`phones` are arrays so a
     * candidate can carry several (home/work/cell). Blank/placeholder entries are
     * dropped, so only real, provided values ever take part in matching.
     *
     * @return array{first:string,last:string,bm:int,by:int,emails:list<string>,phones:list<string>}
     */
    function sg_person($first, $last, $bm, $by, array $emails, array $phones): array
    {
        $en = [];
        foreach ($emails as $e) { $e = sg_nz($e); if ($e !== '') { $en[$e] = 1; } }
        $pn = [];
        foreach ($phones as $p) { $p = sg_ph($p); if ($p !== '') { $pn[$p] = 1; } }
        return [
            'first'  => sg_nz($first),
            'last'   => sg_nz($last),
            'bm'     => (int) $bm,
            'by'     => (int) $by,
            'emails' => array_keys($en),
            'phones' => array_keys($pn),
        ];
    }

    /**
     * The 4-5 component comparison. Each component only counts when BOTH sides
     * actually provided a real value. Returns the list of matched component
     * labels among: first name, last name, birth month/year, email, mobile.
     *
     * @return list<string>
     */
    /**
     * Forgiving first-name equality: exact, or the leading token matches (covers
     * "Vince" vs "Vince Cedric"), or one is a prefix of the other.
     */
    function sg_first_eq(string $a, string $b): bool
    {
        if ($a === '' || $b === '') { return false; }
        if ($a === $b) { return true; }
        if (strtok($a, ' ') === strtok($b, ' ')) { return true; }
        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }

    function sg_components(array $a, array $b): array
    {
        $why = [];
        if (sg_first_eq($a['first'], $b['first']))           { $why[] = 'first name'; }
        if ($a['last'] !== ''  && $a['last'] === $b['last'])   { $why[] = 'last name'; }
        if ($a['bm'] > 0 && $a['by'] > 0 && $a['bm'] === $b['bm'] && $a['by'] === $b['by']) { $why[] = 'birth month/year'; }
        if (array_intersect($a['emails'], $b['emails']))       { $why[] = 'email'; }
        foreach ($a['phones'] as $pa) {
            if (in_array($pa, $b['phones'], true)) { $why[] = 'mobile'; break; }
        }
        return $why;
    }

    /**
     * Classify a set of matched components into 'exact' | 'possible' | 'none'.
     * Exact = full name plus at least one strong identifier, OR a direct
     * email/phone hit alongside a matching last name. Anything with two or more
     * signals (or a lone strong identifier) is a 'possible' for admin review.
     *
     * @param list<string> $why
     */
    function sg_match_level(array $why): string
    {
        $has = static fn (string $k): bool => in_array($k, $why, true);
        $first  = $has('first name');
        $last   = $has('last name');
        $birth  = $has('birth month/year');
        $strong = $has('email') || $has('mobile') || $birth;

        if ($first && $last && $strong) { return 'exact'; }        // name + one more
        if (($has('email') || $has('mobile')) && $last) { return 'exact'; } // contact + last
        if ($last && $birth) { return 'exact'; }                   // last + exact birthdate (nickname-safe)
        if (count($why) >= 2 || $has('email') || $has('mobile')) { return 'possible'; }
        return 'none';
    }

    /**
     * Match a submitted person against existing people in the member database
     * using the 5-component comparison. Never auto-merges. $db is the member
     * database connection (sg_members_db()).
     *
     * @param array{first_name?:string,last_name?:string,email?:string,phone?:string,birth_month?:int|string,birth_year?:int|string} $p
     * @return array{exact:list<array<string,mixed>>,possible:list<array<string,mixed>>}
     */
    function sg_match_members(PDO $db, array $p): array
    {
        $in    = sg_person($p['first_name'] ?? '', $p['last_name'] ?? '', $p['birth_month'] ?? 0, $p['birth_year'] ?? 0, [$p['email'] ?? ''], [$p['phone'] ?? '']);
        $last  = $in['last'];
        $email = $in['emails'][0] ?? '';

        // Need at least one usable identifier to bother searching.
        if ($last === '' && $email === '' && ($in['phones'] === [])) {
            return ['exact' => [], 'possible' => []];
        }

        // Candidate pull: same last name (covers name matches) OR an email hit.
        $conds = [];
        $params = [];
        if ($last !== '')  { $conds[] = 'LOWER(last_name) = :last'; $params[':last'] = $last; }
        if ($email !== '') { $conds[] = 'LOWER(email) = :e1'; $params[':e1'] = $email; }
        if ($conds === []) { return ['exact' => [], 'possible' => []]; }
        $sql = 'SELECT id, first_name AS fn, last_name AS ln, email,
                       mobile_phone AS mobile, home_phone AS home,
                       birth_month AS bm, birth_year AS by2, city
                  FROM people
                 WHERE ' . implode(' OR ', $conds) . ' LIMIT 200';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $exact = [];
        $possible = [];
        foreach ($stmt->fetchAll() as $r) {
            $cand = sg_person($r['fn'], $r['ln'], $r['bm'], $r['by2'], [$r['email']], [$r['mobile'], $r['home']]);
            $why  = sg_components($in, $cand);
            $level = sg_match_level($why);
            if ($level === 'none') { continue; }

            $card = [
                'id'   => (int) $r['id'],
                'name' => trim(sg_str($r['fn']) . ' ' . sg_str($r['ln'])),
                'city' => sg_str($r['city']),
                'why'  => $why,
            ];
            if ($level === 'exact') { $exact[] = $card; } else { $possible[] = $card; }
        }

        return ['exact' => $exact, 'possible' => $possible];
    }

    /**
     * Detect prior registrations for the same person (self-duplicate detection)
     * in the visitors database, using the same 5-component comparison. Ignores
     * rows already handled (promoted/rejected) and an optional row id to exclude.
     *
     * @param array{first_name?:string,last_name?:string,email?:string,phone?:string,birth_month?:int|string,birth_year?:int|string} $p
     * @return array{exact:list<array<string,mixed>>,possible:list<array<string,mixed>>}
     */
    function sg_find_signup_dupes(PDO $db, array $p, ?int $excludeId = null): array
    {
        $in    = sg_person($p['first_name'] ?? '', $p['last_name'] ?? '', $p['birth_month'] ?? 0, $p['birth_year'] ?? 0, [$p['email'] ?? ''], [$p['phone'] ?? '']);
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
        if ($excludeId !== null) {
            $sql .= ' AND id <> :ex';
            $params[':ex'] = $excludeId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 100';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $exact = [];
        $possible = [];
        foreach ($stmt->fetchAll() as $r) {
            $cand = sg_person($r['fn'], $r['ln'], $r['bm'], $r['by2'], [$r['email']], [$r['phone']]);
            $why  = sg_components($in, $cand);
            $level = sg_match_level($why);
            if ($level === 'none') { continue; }
            $card = [
                'id'    => (int) $r['id'],
                'name'  => trim(sg_str($r['fn']) . ' ' . sg_str($r['ln'])),
                'city'  => sg_str($r['city']),
                'when'  => (string) $r['created_at'],
                'why'   => $why,
            ];
            if ($level === 'exact') { $exact[] = $card; } else { $possible[] = $card; }
        }

        return ['exact' => $exact, 'possible' => $possible];
    }
}
