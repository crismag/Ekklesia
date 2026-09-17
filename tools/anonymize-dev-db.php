<?php

declare(strict_types=1);

/**
 * Development database anonymisation.
 *
 * Workflow (never in-place):
 *
 *     SOURCE  --mysqldump-->  TARGET COPY  --anonymise-->  sanitised dev DB
 *
 * The source database is never written to. That is structural, not a policy:
 * every mutating statement in this file executes on $target, and openTarget()
 * refuses to return a handle when the target name equals the source name. There
 * is no code path that can UPDATE, DELETE or TRUNCATE the source.
 *
 * Modes
 *   --plan                 read-only; reports what would change. The default.
 *   --copy                 mysqldump source into --target, then anonymise it.
 *   --anonymise            anonymise an already-populated --target.
 *   --verify               scan --target for residual identifying data.
 *
 * Write modes additionally require --i-understand.
 *
 * Anonymisation is deterministic (HMAC-seeded), so the same person always
 * becomes the same pseudonym: reviews are reproducible and screenshots stay
 * stable across re-runs. It is relationship-preserving — families share a
 * surname, couples' cached names match the people table, portal accounts match
 * the person they link to, roster display names match the assignee.
 *
 * Usage:
 *   php tools/anonymize-dev-db.php --plan
 *   php tools/anonymize-dev-db.php --copy --target=christlike_dev --i-understand
 *   php tools/anonymize-dev-db.php --verify --target=christlike_dev
 */

// ---------------------------------------------------------------- arguments

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $arg, $m) !== 1) {
        fail("Unrecognised argument: {$arg}");
    }
    $opts[$m[1]] = $m[2] ?? true;
}

$mode = null;
foreach (['plan', 'copy', 'anonymise', 'anonymize', 'verify'] as $candidate) {
    if (isset($opts[$candidate])) {
        if ($mode !== null) {
            fail('Choose exactly one mode.');
        }
        $mode = $candidate === 'anonymize' ? 'anonymise' : $candidate;
    }
}
$mode ??= 'plan';

$env = loadEnv(__DIR__ . '/../.env');
$sourceDb = $env['PORTAL_DB_DATABASE'] ?? '';
$host = $env['PORTAL_DB_HOST'] ?? '';
$port = (int) ($env['PORTAL_DB_PORT'] ?? 3306);
$user = $env['PORTAL_DB_USERNAME'] ?? '';
$pass = $env['PORTAL_DB_PASSWORD'] ?? '';
$appEnv = strtolower(trim((string) ($env['APP_ENV'] ?? '')));
$target = is_string($opts['target'] ?? null) ? $opts['target'] : '';
$salt = (string) ($env['ANONYMIZE_SALT'] ?? 'church-portal-dev-anonymisation-v1');
$GLOBALS['anonymizeAllowFlag'] = trim((string) ($env['ANONYMIZE_ALLOW_DEV'] ?? ''));
$GLOBALS['anonymizeAppUrl'] = trim((string) ($env['APP_URL'] ?? ''));
$devPassword = is_string($opts['dev-password'] ?? null) ? $opts['dev-password'] : 'DevPassw0rd!';

// ------------------------------------------------------------- safety gates

/**
 * Every gate fails closed: anything it cannot positively confirm as a local
 * development environment is treated as production.
 */
function assertSafe(string $mode, string $appEnv, string $host, string $sourceDb, string $target): void
{
    // ---- Layer 1: explicit opt-in, required in every mode --------------
    // Positive opt-in, checked first and required in every mode.
    //
    // This gate exists because the original design trusted APP_ENV, and that
    // assumption was wrong in the one place it mattered: the production host
    // also has APP_ENV=local, and its database is on 127.0.0.1, so both the
    // environment and host gates passed there. Deployed to production, the
    // tool read the live database and was stopped from writing only by a MySQL
    // privilege error — not by anything here.
    //
    // Inferring "this is not production" from configuration cannot be made
    // safe, because production is free to look like anything. So the test is
    // inverted: a machine must explicitly declare itself a sanctioned
    // anonymisation target by setting ANONYMIZE_ALLOW_DEV=1 in its .env.
    // Absence is refusal, and no production .env will ever contain it.
    if (($GLOBALS['anonymizeAllowFlag'] ?? '') !== '1') {
        fail("ANONYMIZE_ALLOW_DEV is not set to 1 in .env.\n"
            . "        Refusing to run, in any mode, including read-only ones.\n\n"
            . "        This tool never infers that it is safe to run. A development machine\n"
            . "        must opt in explicitly by adding to its .env:\n\n"
            . "            ANONYMIZE_ALLOW_DEV=1\n\n"
            . "        Do not add it to a production .env.");
    }

    $appUrlLower = strtolower(trim((string) ($GLOBALS['anonymizeAppUrl'] ?? '')));

    // ---- Layer 2: positive identification of a KNOWN production install ----
    //
    // Independent of any environment label, because labels drift. These signals
    // are properties of where the code physically is, not of what a file claims.
    //
    // Note what is deliberately NOT used here: the database name and the
    // database user. The development database is a restore of production and
    // carries the *same* name (u471078694_christlike_mdb) and the same
    // credentials, so blocking on those would block legitimate development use
    // while proving nothing — they are identical in both places.
    $selfPath = str_replace('\\', '/', (string) realpath(__DIR__));
    $productionPathMarkers = ['/public_html/', '/domains/', '/htdocs/', '/www/'];
    foreach ($productionPathMarkers as $marker) {
        if (str_contains($selfPath, $marker)) {
            fail("This copy of the tool is installed at:\n"
                . "          {$selfPath}\n\n"
                . "        That path contains '{$marker}', which marks a web-server document\n"
                . "        root. Refusing outright — a destructive development tool has no\n"
                . "        business running from a served directory, whatever the .env says.");
        }
    }

    $hostName = strtolower((string) @gethostname());
    $productionHosts = ['christlikeness.crishub.com', 'crishub.com'];
    foreach ($productionHosts as $needle) {
        if ($hostName !== '' && str_contains($hostName, $needle)) {
            fail("Machine hostname '{$hostName}' matches a known production host. Refusing.");
        }
        if ($appUrlLower !== '' && str_contains($appUrlLower, $needle)) {
            fail("APP_URL '{$appUrlLower}' matches a known production host. Refusing.");
        }
    }

    // ---- Layer 3: environment label (weakest signal, checked last) ---------
    $devEnvs = ['local', 'dev', 'development', 'testing'];
    if (!in_array($appEnv, $devEnvs, true)) {
        fail("APP_ENV is " . ($appEnv === '' ? '(unset)' : $appEnv) . ", not one of: " . implode(', ', $devEnvs) . ".");
    }

    // A public-looking APP_URL means production regardless of what APP_ENV says.
    $appUrl = $appUrlLower;
    if ($appUrl !== '' && preg_match('#^https://#', $appUrl) === 1
        && preg_match('#(localhost|127\.0\.0\.1|\.local|\.test|\.localhost)#', $appUrl) !== 1) {
        fail("APP_URL is '{$appUrl}', which is a public HTTPS address.\n"
            . "        Refusing to run against what looks like a live deployment.");
    }

    $loopback = ['127.0.0.1', '::1', 'localhost', '[::1]'];
    if (!in_array(strtolower($host), $loopback, true)) {
        fail("PORTAL_DB_HOST is '{$host}', which is not loopback.\n"
            . "        Refusing to run against a non-local database host.");
    }

    if ($mode === 'plan') {
        return; // read-only from here on; the remaining gates guard writes.
    }

    if ($target === '') {
        fail('Write modes require --target=<database>.');
    }

    if (strcasecmp($target, $sourceDb) === 0) {
        fail("--target is the same database as the source ({$sourceDb}).\n"
            . "        Refusing: anonymisation must run on a COPY. The source database must\n"
            . "        remain untouched.");
    }

    if (preg_match('/(^|_)(dev|development|local|test|sandbox|anon|staging)(_|$)/i', $target) !== 1) {
        fail("--target '{$target}' does not look like a development database.\n"
            . "        Its name must contain one of: dev, development, local, test, sandbox,\n"
            . "        anon, staging — as a _-delimited word. This prevents a mistyped target\n"
            . "        from pointing at a real database.");
    }

    if (preg_match('/(prod|production|live|www)/i', $target) === 1) {
        fail("--target '{$target}' contains a production-like word. Refusing.");
    }
}

// --------------------------------------------------------------- pseudonyms

const FIRST_M = ['Adrian','Alan','Andre','Arthur','Bernard','Blake','Caleb','Carlos','Cedric','Colin','Damien','Darren','Dennis','Desmond','Douglas','Edwin','Elliot','Emmanuel','Ernest','Felix','Franklin','Gerald','Gilbert','Gordon','Grant','Harold','Hector','Howard','Ivan','Jerome','Julian','Keith','Kenneth','Lawrence','Leonard','Lionel','Malcolm','Marcus','Martin','Maurice','Miles','Nathan','Neville','Norman','Oscar','Percy','Quentin','Ralph','Raymond','Reginald','Roland','Rupert','Selwyn','Sidney','Stanley','Terrence','Theodore','Vernon','Wallace','Warren'];
const FIRST_F = ['Adele','Agnes','Alison','Annette','Beatrice','Bernadette','Bridget','Camille','Carmen','Cecilia','Charlotte','Clara','Constance','Corinne','Delia','Dorothy','Edith','Eileen','Elaine','Eleanor','Estelle','Eunice','Evelyn','Felicity','Florence','Frances','Geraldine','Gladys','Gloria','Harriet','Hazel','Helena','Imelda','Irene','Iris','Janice','Josephine','Juliet','Katrina','Leona','Lorraine','Lucille','Mabel','Marcia','Marguerite','Marion','Maureen','Mildred','Miriam','Monica','Nadine','Naomi','Nora','Olive','Paulette','Priscilla','Rosalind','Sylvia','Theresa','Yvonne'];
const SURNAMES = ['Abernathy','Ashcombe','Ballantyne','Barrowman','Beauchamp','Birchwood','Blackwood','Braithwaite','Bramhall','Carrington','Castellane','Chadwick','Clearwater','Copperfield','Cranbrook','Cuthbertson','Danforth','Deverell','Donnelly','Dunmore','Eastbrook','Ellsworth','Fairweather','Fenwick','Fitzgerald','Fothergill','Galbraith','Garrowby','Greenhalgh','Halloran','Hartfield','Hawthorne','Hollingsworth','Huntington','Inglewood','Kingsley','Larkspur','Lockhart','Loxley','Marchetti','Merriweather','Middleton','Montrose','Northcote','Oakhurst','Pemberton','Pennington','Quimby','Radcliffe','Ravensworth','Redmayne','Rutherford','Sandringham','Seymour','Sinclair','Somerville','Stanhope','Sutherland','Thornbury','Underwood','Vandermeer','Wainwright','Westbrook','Wetherby','Whitlock','Willoughby','Winterbourne','Woodruff','Yarborough','Zimmerman'];
const STREETS = ['Alder','Birch','Cedar','Chapel','Chestnut','Clover','Elmfield','Fernway','Garden','Harvest','Juniper','Laurel','Maple','Meadow','Mulberry','Orchard','Pine','Poplar','Rosewood','Sycamore','Thistle','Willow'];
const STREET_TYPES = ['Avenue','Close','Court','Crescent','Drive','Lane','Place','Road','Street','Way'];
const CITIES = ['Ashford','Bridgeton','Clearview','Eastvale','Fairhaven','Glenmoor','Havenport','Kingsford','Lakeshore','Millbrook','Northfield','Oakvale','Riverton','Southgate','Westbury'];

/** Deterministic index derived from a stable key — same input, same output, always. */
function seedIndex(string $salt, string $scope, string $key, int $modulus): int
{
    $digest = hash_hmac('sha256', $scope . ':' . $key, $salt);

    return (int) (hexdec(substr($digest, 0, 12)) % $modulus);
}

function pseudoSurname(string $salt, string $familyKey): string
{
    return trim(SURNAMES[seedIndex($salt, 'surname', $familyKey, count(SURNAMES))], ', ');
}

function pseudoFirstName(string $salt, string $personKey, ?string $gender): string
{
    $pool = $gender === 'F' ? FIRST_F : ($gender === 'M' ? FIRST_M : array_merge(FIRST_M, FIRST_F));

    return $pool[seedIndex($salt, 'first', $personKey, count($pool))];
}

// -------------------------------------------------------------- connections

function openSource(string $host, int $port, string $db, string $user, string $pass): PDO
{
    return new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/**
 * The only handle any mutating statement is ever executed against. It cannot
 * be opened onto the source database — so the source cannot be written to,
 * regardless of what the rest of this file does.
 */
function openTarget(string $host, int $port, string $target, string $sourceDb, string $user, string $pass): PDO
{
    if (strcasecmp($target, $sourceDb) === 0) {
        fail('Refused: target database is the source database.');
    }

    return openSource($host, $port, $target, $user, $pass);
}

// ------------------------------------------------------------------- helpers

function loadEnv(string $path): array
{
    if (!is_readable($path)) {
        fail("Cannot read {$path}");
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $out[trim($k)] = trim($v, " \t\"'");
    }

    return $out;
}

function fail(string $message): never
{
    fwrite(STDERR, "\n  REFUSED: {$message}\n\n");
    exit(1);
}

function say(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

/** Columns this tool rewrites, by table. Anything not listed is left as-is. */
function piiMap(): array
{
    return [
        'christlikeness_people_tbl' => ['first_name', 'middle_name', 'last_name', 'address_1', 'address_2', 'city', 'zip', 'home_phone', 'work_phone', 'cell_phone', 'email', 'work_email', 'birth_date'],
        'couples_tbl' => ['male', 'female', 'details'],
        'portal_users' => ['email', 'display_name', 'password_hash'],
        'schedule_roster_assignment' => ['display_name'],
        'schedule_roster' => ['notes'],
        'schedule_roster_slot' => ['notes'],
        'portal_audit_log' => ['ip_address', 'user_agent', 'summary', 'payload_json'],
        'church_campus_info' => ['contact_email', 'contact_num'],
    ];
}

/** Tables emptied rather than rewritten: they hold only secrets or session traces. */
function truncateTables(): array
{
    return ['portal_sessions', 'portal_tokens'];
}

// ----------------------------------------------------------------- planning

/**
 * Read-only. Reports scope only — counts and column names, never values, so a
 * plan can be pasted into a report or ticket without leaking what it protects.
 */
function runPlan(PDO $source, string $sourceDb): int
{
    say("PLAN — read-only. No database is modified.\n");
    say("  Source: {$sourceDb} (never written to by this tool)\n");

    $total = 0;
    say('  Columns to be rewritten in the copy:');
    foreach (piiMap() as $table => $columns) {
        $exists = $source->prepare('select count(*) from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?');
        $present = [];
        foreach ($columns as $column) {
            $exists->execute([$sourceDb, $table, $column]);
            if ((int) $exists->fetchColumn() > 0) {
                $present[] = $column;
            }
        }
        if ($present === []) {
            say(sprintf('    %-28s  (table absent — skipped)', $table));
            continue;
        }
        $rows = (int) $source->query('select count(*) from `' . $table . '`')->fetchColumn();
        $total += $rows * count($present);
        say(sprintf('    %-28s  %5d rows x %2d cols   %s', $table, $rows, count($present), implode(', ', $present)));
    }

    say("\n  Tables to be emptied (secrets and session traces):");
    foreach (truncateTables() as $table) {
        $rows = (int) $source->query('select count(*) from `' . $table . '`')->fetchColumn();
        say(sprintf('    %-28s  %5d rows', $table, $rows));
    }

    $views = $source->prepare('select table_name from information_schema.views where table_schema = ?');
    $views->execute([$sourceDb]);
    $viewNames = $views->fetchAll(PDO::FETCH_COLUMN);
    say("\n  Views recreated by the copy (they read anonymised base tables, so they");
    say('  need no separate treatment): ' . count($viewNames));
    foreach ($viewNames as $view) {
        say('    ' . $view);
    }

    say(sprintf("\n  ~%d values would be rewritten.\n", $total));
    say('  To execute:');
    say('    php tools/anonymize-dev-db.php --copy --target=christlike_dev --i-understand');

    return 0;
}

// ------------------------------------------------------------------- copying

function runCopy(string $sourceDb, string $target, string $host, int $port, string $user, string $pass): void
{
    say("COPY — {$sourceDb} -> {$target}");

    // The dump is read-only against the source. --single-transaction avoids
    // locking it; --routines and views come along so the copy is complete.
    $dump = sprintf(
        'mysqldump --host=%s --port=%d --user=%s --single-transaction --routines --events --set-gtid-purged=OFF %s',
        escapeshellarg($host),
        $port,
        escapeshellarg($user),
        escapeshellarg($sourceDb)
    );
    $load = sprintf(
        'mysql --host=%s --port=%d --user=%s %s',
        escapeshellarg($host),
        $port,
        escapeshellarg($user),
        escapeshellarg($target)
    );

    // Credentials go through the environment, never the command line, so they
    // cannot appear in `ps` output or a shell history file.
    $pipe = $dump . ' | ' . $load;
    $descriptors = [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR];
    $process = proc_open(['bash', '-c', $pipe], $descriptors, $pipes, null, ['MYSQL_PWD' => $pass] + getenv());
    if (!is_resource($process)) {
        fail('Could not start mysqldump.');
    }
    fclose($pipes[0]);
    $status = proc_close($process);

    if ($status !== 0) {
        fail("Copy failed (exit {$status}). The target database must already exist and be writable:\n"
            . "          CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
            . "          GRANT ALL PRIVILEGES ON `{$target}`.* TO '{$user}'@'localhost';");
    }

    say('  Copy complete. Source unchanged.');
}

// -------------------------------------------------------------- anonymising

function runAnonymise(PDO $target, string $salt, string $devPassword): int
{
    say('ANONYMISE — rewriting the copy.');
    $changed = 0;

    // Families first: a surname is a property of a family, not a person, so it
    // is derived from family_grp_id where one exists. Members of a household
    // keep a shared surname, which is what makes the directory look real.
    $familyOf = [];
    try {
        foreach ($target->query('select people_id, family_grp_id from family_role_tbl') as $row) {
            if ($row['family_grp_id'] !== null && (int) $row['family_grp_id'] > 0) {
                $familyOf[(int) $row['people_id']] = 'fam-' . (int) $row['family_grp_id'];
            }
        }
    } catch (PDOException) {
        // No family table in this copy; every person becomes their own family.
    }

    $people = $target->query('select people_id, gender, birth_date from christlikeness_people_tbl')->fetchAll();
    $update = $target->prepare(
        'update christlikeness_people_tbl set first_name = ?, middle_name = ?, last_name = ?,
         address_1 = ?, address_2 = ?, city = ?, zip = ?, home_phone = ?, work_phone = ?,
         cell_phone = ?, email = ?, work_email = ?, birth_date = ? where people_id = ?'
    );

    $names = [];
    foreach ($people as $person) {
        $id = (int) $person['people_id'];
        $key = (string) $id;
        $gender = in_array($person['gender'] ?? '', ['M', 'F'], true) ? $person['gender'] : null;

        $first = pseudoFirstName($salt, $key, $gender);
        $last = pseudoSurname($salt, $familyOf[$id] ?? ('person-' . $key));
        $middle = substr(pseudoFirstName($salt, 'mid-' . $key, $gender), 0, 1) . '.';
        $names[$id] = ['first' => $first, 'last' => $last];

        // .invalid is reserved by RFC 2606: these addresses can never be
        // delivered, even if a dev environment is misconfigured to send mail.
        $email = strtolower($first . '.' . $last . $id) . '@example.invalid';
        // 555-01xx is the reserved fictional range; these can never be dialled.
        $phone = sprintf('(555) 01%02d-%04d', seedIndex($salt, 'phone', $key, 100), seedIndex($salt, 'phone2', $key, 10000));

        // Birth dates are shifted within the same year: the birthday feature
        // stays exercisable and age brackets stay realistic, but no real date
        // of birth survives.
        $birth = null;
        if (!empty($person['birth_date']) && $person['birth_date'] !== '0000-00-00') {
            $shift = seedIndex($salt, 'dob', $key, 300) - 150;
            $ts = strtotime((string) $person['birth_date'] . ' ' . $shift . ' days');
            $birth = $ts !== false ? date('Y-m-d', $ts) : null;
        }

        $update->execute([
            $first,
            $middle,
            $last,
            sprintf('%d %s %s', 1 + seedIndex($salt, 'num', $key, 400), STREETS[seedIndex($salt, 'st', $key, count(STREETS))], STREET_TYPES[seedIndex($salt, 'stt', $key, count(STREET_TYPES))]),
            '',
            CITIES[seedIndex($salt, 'city', $key, count(CITIES))],
            sprintf('%04d', seedIndex($salt, 'zip', $key, 10000)),
            $phone,
            $phone,
            $phone,
            $email,
            $email,
            $birth,
            $id,
        ]);
        $changed++;
    }
    say(sprintf('  people                    %5d rewritten', count($people)));

    // Cached name copies elsewhere are regenerated from the same person id, so
    // they agree with the people table rather than drifting from it.
    $couples = 0;
    foreach ($target->query('select couple_id, male_person_id, female_person_id from couples_tbl') as $row) {
        $male = $names[(int) $row['male_person_id']] ?? null;
        $female = $names[(int) $row['female_person_id']] ?? null;
        $stmt = $target->prepare('update couples_tbl set male = ?, female = ?, details = ? where couple_id = ?');
        $stmt->execute([
            $male ? $male['first'] . ' ' . $male['last'] : '',
            $female ? $female['first'] . ' ' . $female['last'] : '',
            '', // free text cannot be safely scanned for PII, so it is cleared
            (int) $row['couple_id'],
        ]);
        $couples++;
    }
    say(sprintf('  couples                   %5d rewritten', $couples));

    $assignments = 0;
    foreach ($target->query('select assignment_id, person_id from schedule_roster_assignment') as $row) {
        $name = $names[(int) $row['person_id']] ?? null;
        $stmt = $target->prepare('update schedule_roster_assignment set display_name = ? where assignment_id = ?');
        $stmt->execute([$name ? $name['first'] . ' ' . $name['last'] : null, (int) $row['assignment_id']]);
        $assignments++;
    }
    say(sprintf('  roster assignments        %5d rewritten', $assignments));

    // Portal accounts follow the person they are linked to, so signing in as a
    // dev user lands on a directory entry with a matching name.
    $users = 0;
    foreach ($target->query('select portal_user_id, churchcrm_person_id from portal_users') as $row) {
        $name = $names[(int) $row['churchcrm_person_id']] ?? null;
        $display = $name ? $name['first'] . ' ' . $name['last'] : 'Dev User ' . (int) $row['portal_user_id'];
        $email = strtolower(str_replace(' ', '.', $display)) . (int) $row['portal_user_id'] . '@example.invalid';
        $stmt = $target->prepare('update portal_users set email = ?, display_name = ?, password_hash = ?, must_change_password = 0 where portal_user_id = ?');
        $stmt->execute([$email, $display, password_hash($devPassword, PASSWORD_DEFAULT), (int) $row['portal_user_id']]);
        $users++;
    }
    say(sprintf('  portal users              %5d rewritten (shared dev password)', $users));

    // Free text and request traces: cleared, not rewritten. There is no
    // reliable way to detect PII inside prose, so none is kept.
    foreach ([['schedule_roster', 'roster_id'], ['schedule_roster_slot', 'slot_id']] as [$table, $pk]) {
        $target->exec("update `{$table}` set notes = null where notes is not null and notes <> ''");
    }
    $target->exec("update church_campus_info set contact_email = 'campus@example.invalid', contact_num = 0");
    $target->exec('update portal_audit_log set ip_address = null, user_agent = null, payload_json = null');
    say('  free text and audit trace       cleared');

    foreach (truncateTables() as $table) {
        $target->exec('truncate table `' . $table . '`');
    }
    say('  sessions and tokens             emptied');

    say("\n  Done. Every account now signs in with: {$devPassword}");

    return $changed;
}

// ------------------------------------------------------------- verification

/**
 * Checks the sanitised copy on its own terms — no value is compared against the
 * source, so verification never re-reads real data.
 */
function runVerify(PDO $target): int
{
    say('VERIFY — scanning the sanitised copy.');
    $failures = 0;
    $check = static function (string $label, string $sql) use ($target, &$failures): void {
        $count = (int) $target->query($sql)->fetchColumn();
        $ok = $count === 0;
        $failures += $ok ? 0 : 1;
        say(sprintf('  [%s] %-52s %d', $ok ? 'ok' : 'FAIL', $label, $count));
    };

    $check('emails outside @example.invalid', "select count(*) from christlikeness_people_tbl where (email <> '' and email not like '%@example.invalid') or (work_email <> '' and work_email not like '%@example.invalid')");
    $check('portal user emails outside @example.invalid', "select count(*) from portal_users where email not like '%@example.invalid'");
    $check('phone numbers outside the 555 range', "select count(*) from christlikeness_people_tbl where (home_phone <> '' and home_phone not like '(555)%') or (cell_phone <> '' and cell_phone not like '(555)%') or (work_phone <> '' and work_phone not like '(555)%')");
    $check('retained IP addresses', 'select count(*) from portal_audit_log where ip_address is not null');
    $check('retained user agents', 'select count(*) from portal_audit_log where user_agent is not null');
    $check('surviving sessions', 'select count(*) from portal_sessions');
    $check('surviving tokens', 'select count(*) from portal_tokens');
    $check('roster free text', "select count(*) from schedule_roster where notes is not null and notes <> ''");
    $check('slot free text', "select count(*) from schedule_roster_slot where notes is not null and notes <> ''");
    $check('couple free text', "select count(*) from couples_tbl where details is not null and details <> ''");
    $pool = implode(',', array_map(static fn (string $n): string => $target->quote($n), SURNAMES));
    $check('surnames outside the pseudonym pool', 'select count(*) from christlikeness_people_tbl where last_name not in (' . $pool . ')');

    say('');
    say($failures === 0
        ? '  PASS — no identifying data detected in the copy.'
        : "  FAIL — {$failures} check(s) found residual identifying data.");

    return $failures === 0 ? 0 : 1;
}

// ---------------------------------------------------------------------- main

// Guard so the pure pseudonym functions can be require()d by tests without
// running the tool. Only a direct CLI invocation executes anything.
$invokedDirectly = PHP_SAPI === 'cli'
    && isset($_SERVER['argv'][0])
    && realpath($_SERVER['argv'][0]) === realpath(__FILE__);

if (!$invokedDirectly) {
    return;
}

assertSafe($mode, $appEnv, $host, $sourceDb, $target);

if ($mode !== 'plan' && !isset($opts['i-understand'])) {
    fail("Write modes require --i-understand.\n"
        . "        Run --plan first to see exactly what would change.");
}

try {
    if ($mode === 'plan') {
        exit(runPlan(openSource($host, $port, $sourceDb, $user, $pass), $sourceDb));
    }

    if ($mode === 'copy') {
        runCopy($sourceDb, $target, $host, $port, $user, $pass);
    }

    $targetPdo = openTarget($host, $port, $target, $sourceDb, $user, $pass);

    if ($mode === 'copy' || $mode === 'anonymise') {
        runAnonymise($targetPdo, $salt, $devPassword);
        say("\n  Point development at the sanitised copy:");
        say("    PORTAL_DB_DATABASE={$target}");
        say("\n  Then verify:");
        say("    php tools/anonymize-dev-db.php --verify --target={$target}");
    }

    exit($mode === 'verify' ? runVerify($targetPdo) : 0);
} catch (PDOException $e) {
    fail('Database error: ' . $e->getMessage());
}
