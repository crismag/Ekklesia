<?php

declare(strict_types=1);

/**
 * Sign-in when an email or phone number is shared.
 *
 * An email or phone on a person record is contact information: a household
 * shares one (children under a guardian's address, older members using a
 * relative's). A login is linked to exactly one person. So:
 *
 *  - a sign-in identity that already has a login opens that login, with no
 *    person matching;
 *  - a first sign-in through a shared contact never picks a person: the user
 *    confirms (one eligible person) or chooses (several), and the login then
 *    belongs to that person for every later sign-in;
 *  - an administrator giving access cannot move a login to another person.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use App\Contracts\AuthRepository;
use App\Contracts\MinistryRepository;
use App\Contracts\PersonContactDirectory;
use App\Core\ActorContext;
use App\Core\Security\PasswordHasher;
use App\Exceptions\LoginChoiceRequired;
use App\Exceptions\PermissionDenied;
use App\Exceptions\ValidationFailed;
use App\Services\Auth\SignInSecrets;
use App\Services\AuthService;
use App\Services\PersonIdentityResolver;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : " ({$detail})");
}

/**
 * A stand-in for an interface: every method throws unless given a behaviour,
 * so the test fails loudly if sign-in reaches for something unexpected.
 *
 * @param array<string,callable> $behaviour
 */
function stubOf(string $interface, array $behaviour): object
{
    static $n = 0;
    $class = 'Stub' . (++$n);
    $methods = [];
    foreach ((new ReflectionClass($interface))->getMethods() as $m) {
        $params = [];
        foreach ($m->getParameters() as $p) {
            $type = $p->getType() !== null ? (string) $p->getType() . ' ' : '';
            $default = $p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '';
            $params[] = $type . '$' . $p->getName() . $default;
        }
        $ret = $m->getReturnType() !== null ? ': ' . $m->getReturnType() : '';
        $call = (string) $m->getReturnType() === 'void'
            ? '($this->b[__FUNCTION__] ?? throw new LogicException("unexpected " . __FUNCTION__))(...func_get_args());'
            : 'return ($this->b[__FUNCTION__] ?? throw new LogicException("unexpected " . __FUNCTION__))(...func_get_args());';
        $methods[] = 'public function ' . $m->getName() . '(' . implode(', ', $params) . ')' . $ret . ' { ' . $call . ' }';
    }
    eval('final class ' . $class . ' implements \\' . $interface . ' { public function __construct(private array $b) {} ' . implode("\n", $methods) . ' }');
    return new $class($behaviour);
}

$hasher = new PasswordHasher();

/** One church: the people records and the logins. */
function church(PasswordHasher $hasher): array
{
    $people = [
        1 => ['first' => 'Pobble',  'last' => 'Sarvik', 'email' => 'family@example.com', 'phone' => '4165550100'],
        2 => ['first' => 'Mevvy',   'last' => 'Sarvik', 'email' => 'family@example.com', 'phone' => '4165550100'],
        3 => ['first' => 'Jovrin', 'last' => 'Sarvik', 'email' => 'family@example.com', 'phone' => null],
        4 => ['first' => 'Pumo',   'last' => 'Sarvik', 'email' => 'family@example.com', 'phone' => null],
        5 => ['first' => 'Avvi',    'last' => 'Ruvex',  'email' => 'avvi@example.com',  'phone' => '6475550199'],
        6 => ['first' => 'Bix',    'last' => 'Ruvex',  'email' => 'bix@example.com',  'phone' => '6475550199'],
    ];
    $accounts = [
        50 => ['id' => 50, 'email' => 'avvi@example.com', 'password_hash' => $hasher->hash('Avvi-own-pass-1'), 'is_active' => true,
               'display_name' => 'Avvi Ruvex', 'must_change_password' => false, 'person_id' => 5],
        51 => ['id' => 51, 'email' => 'bix@example.com', 'password_hash' => $hasher->hash('Bix-own-pass-1'), 'is_active' => true,
               'display_name' => 'Bix Ruvex', 'must_change_password' => false, 'person_id' => 6],
    ];
    return [$people, $accounts];
}

/** @return array{0:AuthService,1:ArrayObject} */
function service(PasswordHasher $hasher, ?SignInSecrets $secrets = null): array
{
    $secrets ??= new SignInSecrets('test-recovery-password', 'Test#{F}{L}#Claim!');
    [$people, $accounts] = church($hasher);
    $state = new ArrayObject(['accounts' => $accounts, 'nextId' => 100, 'roles' => [], 'audit' => []]);

    $directory = new class ($people) implements PersonContactDirectory {
        public function __construct(private array $people) {}
        public function peopleWithContact(string $identifier): array
        {
            $email = PersonIdentityResolver::looksLikeEmail($identifier) ? strtolower(trim($identifier)) : null;
            $digits = PersonIdentityResolver::normalizePhone($identifier);
            $out = [];
            foreach ($this->people as $id => $p) {
                if (($email !== null && $p['email'] === $email) || ($email === null && $p['phone'] !== null && $p['phone'] === $digits)) {
                    $out[] = ['personId' => $id, 'firstName' => $p['first'], 'lastName' => $p['last']];
                }
            }
            return $out;
        }
        public function identityFor(int $personId): ?array
        {
            $p = $this->people[$personId] ?? null;
            return $p === null ? null : [
                'personId' => $personId, 'firstName' => $p['first'], 'lastName' => $p['last'],
                'email' => $p['email'], 'phone' => $p['phone'],
                'isPortalAdmin' => false, 'canManageGroups' => false, 'leaderMinistryIds' => [], 'campusId' => null,
            ];
        }
    };

    $public = static fn (array $a): array => array_diff_key($a, ['person_id' => 1]);
    $repository = stubOf(AuthRepository::class, [
        'findUserByEmail' => static function (string $email) use ($state, $public): ?array {
            foreach ($state['accounts'] as $a) {
                if ($a['email'] === $email) {
                    return $public($a);
                }
            }
            return null;
        },
        'listUsersForPerson' => static fn (int $personId): array => array_values(array_map(
            $public,
            array_filter($state['accounts'], static fn (array $a): bool => $a['person_id'] === $personId),
        )),
        'isMustChangePassword' => static fn (int $id): bool => $state['accounts'][$id]['must_change_password'],
        'provisionUserForPerson' => static function (string $email, string $hash, ?string $name, int $personId) use ($state): int {
            $id = $state['nextId']++;
            $accounts = $state['accounts'];
            $accounts[$id] = ['id' => $id, 'email' => $email, 'password_hash' => $hash, 'is_active' => true,
                'display_name' => $name, 'must_change_password' => true, 'person_id' => $personId];
            $state['accounts'] = $accounts;
            return $id;
        },
        'createUser' => static function (string $email, string $hash, ?string $name) use ($state): int {
            $id = $state['nextId']++;
            $accounts = $state['accounts'];
            $accounts[$id] = ['id' => $id, 'email' => $email, 'password_hash' => $hash, 'is_active' => true,
                'display_name' => $name, 'must_change_password' => false, 'person_id' => null];
            $state['accounts'] = $accounts;
            return $id;
        },
        'linkUserToPerson' => static function (int $accountId, int $personId) use ($state): void {
            $accounts = $state['accounts'];
            $accounts[$accountId]['person_id'] = $personId;
            $state['accounts'] = $accounts;
        },
        'loadUserProfile' => static fn (int $id): ?array => isset($state['accounts'][$id])
            ? ['id' => $id, 'person_id' => $state['accounts'][$id]['person_id'], 'email' => $state['accounts'][$id]['email'],
               'is_active' => true, 'display_name' => $state['accounts'][$id]['display_name'], 'roles' => []]
            : null,
        'clearRolesForUser' => static function (int $id): void {},
        'assignRole' => static function (int $id, string $role, ?int $c, ?int $m) use ($state): void {
            $roles = $state['roles'];
            $roles[] = [$id, $role];
            $state['roles'] = $roles;
        },
        'createSession' => static function (): void {},
        'recordLogin' => static function (): void {},
        'recordAudit' => static function (...$args) use ($state): void {
            $audit = $state['audit'];
            $audit[] = $args;
            $state['audit'] = $audit;
        },
        'updateDisplayName' => static function (): void {},
    ]);

    return [new AuthService($repository, stubOf(MinistryRepository::class, []), $hasher, 3600, $directory, null, $secrets), $state];
}

/** @return LoginChoiceRequired|App\DTO\Auth\AuthSession|Throwable */
function attempt(AuthService $auth, string $identifier, string $password): object
{
    try {
        return $auth->login($identifier, $password);
    } catch (Throwable $e) {
        return $e;
    }
}

echo "First sign-in through a shared email\n";
[$auth, $state] = service($hasher);
$r = attempt($auth, 'family@example.com', 'Test#PS#Claim!');
check('is not signed in straight away: the person must be chosen', $r instanceof LoginChoiceRequired && $r->kind === 'claim', get_class($r));
$names = $r instanceof LoginChoiceRequired ? array_column($r->choices, 'name') : [];
check('offers every eligible person with that email and that default password', $names === ['Pobble Sarvik', 'Pumo Sarvik'], implode(', ', $names));
check('offers nobody whose default password this is not', !in_array('Mevvy Sarvik', $names, true) && !in_array('Jovrin Sarvik', $names, true));
check('creates no login before the choice', count($state['accounts']) === 2);

try {
    $auth->completeLoginChoice('claim', 'family@example.com', [1, 4], 2);
    check('refuses a person who was not offered', false);
} catch (PermissionDenied) {
    check('refuses a person who was not offered', true);
}

$session = $auth->completeLoginChoice('claim', 'family@example.com', [1, 4], 1);
$linked = array_values(array_filter($state['accounts'], static fn (array $a): bool => $a['email'] === 'family@example.com'));
check('the chosen person gets the login', $session->personId === 1 && count($linked) === 1 && $linked[0]['person_id'] === 1);
check('and must change the default password', $session->mustChangePassword === true);
check('the claim is audited', ($state['audit'][0][2] ?? '') === 'auth.login.claimed');

$again = attempt($auth, 'family@example.com', 'Test#PS#Claim!');
check('the next sign-in with that email opens Pobble directly, without asking', $again instanceof App\DTO\Auth\AuthSession && $again->accountId === $linked[0]['id']);
$paul = attempt($auth, 'family@example.com', 'Test#PS#Claim!');
check('and still does not offer Pumo, who shares the email and initials', $paul instanceof App\DTO\Auth\AuthSession);
$mary = attempt($auth, 'family@example.com', 'Test#MS#Claim!');
check("once linked to Pobble, the email is Pobble's login; Mevvy's default password does not open it", $mary instanceof PermissionDenied);

echo "\nOne eligible person is confirmed, not assumed\n";
[$auth, $state] = service($hasher);
$r = attempt($auth, 'family@example.com', 'Test#MS#Claim!');
check('Mevvy alone is offered, to confirm', $r instanceof LoginChoiceRequired && array_column($r->choices, 'id') === [2]);
check('and nothing is created until she confirms', count($state['accounts']) === 2);

echo "\nFirst sign-in through a shared phone\n";
$r = attempt($auth, '(416) 555-0100', 'Test#MS#Claim!');
check('the phone reaches Mevvy (not Pobble, the older record)', $r instanceof LoginChoiceRequired && array_column($r->choices, 'id') === [2]);
$session = $auth->completeLoginChoice('claim', '(416) 555-0100', [2], 2);
check('the login is keyed to the phone', $session->email === 'phone+4165550100@portal.local' && $session->personId === 2);
$again = attempt($auth, '416-555-0100', 'Test#MS#Claim!');
check('the next phone sign-in opens Mevvy directly', $again instanceof App\DTO\Auth\AuthSession && $again->personId === 2);

echo "\nExisting logins reached through a shared phone\n";
[$auth, $state] = service($hasher);
$r = attempt($auth, '647-555-0199', 'Bix-own-pass-1');
check("Bix's own password opens Bix's login, not Avvi's (the older record)", $r instanceof App\DTO\Auth\AuthSession && $r->accountId === 51);
$r = attempt($auth, '647-555-0199', 'Avvi-own-pass-1');
check("Avvi's own password opens Avvi's login", $r instanceof App\DTO\Auth\AuthSession && $r->accountId === 50);
$r = attempt($auth, '647-555-0199', 'Test#AR#Claim!');
check('a default password does not open or claim someone who already has a login', $r instanceof PermissionDenied);
$r = attempt($auth, 'avvi@example.com', 'Bix-own-pass-1');
check("a login's own identity checks only its own password", $r instanceof PermissionDenied);

// Two logins opened by the same password through one phone: the user chooses.
$accounts = $state['accounts'];
$accounts[51]['password_hash'] = $hasher->hash('Avvi-own-pass-1');
$state['accounts'] = $accounts;
$r = attempt($auth, '6475550199', 'Avvi-own-pass-1');
check('when the password opens two logins on a shared phone, the user chooses', $r instanceof LoginChoiceRequired && $r->kind === 'account'
    && array_column($r->choices, 'id') === [50, 51]);
$session = $auth->completeLoginChoice('account', '6475550199', [50, 51], 51);
check('and gets the one they chose', $session->accountId === 51);

echo "\nAn administrator giving access\n";
[$auth, $state] = service($hasher);
$admin = new ActorContext(actorId: 1, personId: null, displayName: 'Admin', permissions: [], ministryScopeIds: [], isPortalWideAdmin: true);
$auth->completeLoginChoice('claim', 'family@example.com', [1], 1);
try {
    $auth->provisionPortalAccess($admin, 'family@example.com', 'member', personId: 2);
    check("cannot move Pobble's login to Mevvy by giving Mevvy access with the shared email", false);
} catch (ValidationFailed $e) {
    check("cannot move Pobble's login to Mevvy by giving Mevvy access with the shared email", str_contains($e->getMessage(), 'Pobble Sarvik'), $e->getMessage());
}
$peterLogin = array_values(array_filter($state['accounts'], static fn (array $a): bool => $a['email'] === 'family@example.com'))[0];
check("Pobble's login still belongs to Pobble", $peterLogin['person_id'] === 1);

echo "\nLinking a login to a person from Users & access\n";
[$auth, $state] = service($hasher);
$admin = new ActorContext(actorId: 1, personId: null, displayName: 'Admin', permissions: [], ministryScopeIds: [], isPortalWideAdmin: true);
$member = new ActorContext(actorId: 2, personId: null, displayName: 'Member', permissions: [], ministryScopeIds: [], isPortalWideAdmin: false);
$loose = $auth->createUser('loose@example.com', 'Loose-login-pass-1', 'Loose');
$outcome = static function (callable $fn): string {
    try { $fn(); return 'ok'; } catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
};
check('a member cannot link logins', str_starts_with($outcome(fn () => $auth->linkLoginToPerson($member, $loose, 3)), PermissionDenied::class));
check('a login already belonging to someone is not moved',
    str_contains($outcome(fn () => $auth->linkLoginToPerson($admin, 50, 3)), 'already belongs to Avvi Ruvex')
    && $state['accounts'][50]['person_id'] === 5);
check('a person who already has a login does not get a second one',
    str_contains($outcome(fn () => $auth->linkLoginToPerson($admin, $loose, 6)), 'already signs in as bix@example.com')
    && $state['accounts'][$loose]['person_id'] === null);
check('a person who does not exist is refused', str_contains($outcome(fn () => $auth->linkLoginToPerson($admin, $loose, 999)), 'no longer exists'));
$before = count($state['audit']);
check('an unlinked login is linked to a person without one', $outcome(fn () => $auth->linkLoginToPerson($admin, $loose, 3)) === 'ok'
    && $state['accounts'][$loose]['person_id'] === 3);
check('and the link is recorded in the activity history', ($state['audit'][$before][2] ?? '') === 'user_account.link');
check('linking it to the same person again changes nothing', $outcome(fn () => $auth->linkLoginToPerson($admin, $loose, 3)) === 'ok' && count($state['audit']) === $before + 1);

echo "\nSign-in secrets come only from the installation\n";
[$auth] = service($hasher, new SignInSecrets(null, null));
check('without a template, a first sign-in is never offered', attempt($auth, 'family@example.com', 'Test#PS#Claim!') instanceof PermissionDenied);
check('without a recovery password, the church admin login is off', attempt($auth, 'church admin', '') instanceof ValidationFailed
    && attempt($auth, 'church admin', 'anything-at-all-12') instanceof PermissionDenied);
try {
    $auth->completeLoginChoice('claim', 'family@example.com', [1], 1);
    check('and a claim cannot be completed', false);
} catch (PermissionDenied) {
    check('and a claim cannot be completed', true);
}

[$auth, $state] = service($hasher);
$r = attempt($auth, 'church admin', 'test-recovery-password');
check('the configured recovery password opens the church admin login', $r instanceof App\DTO\Auth\AuthSession);
check('a wrong one does not', attempt($auth, 'church admin', 'test-recovery-passwore') instanceof PermissionDenied);

check('a recovery password under 12 characters leaves the login off', !(new SignInSecrets('short-pass1', null))->recoveryAdminEnabled());
check('a template without both initials is refused', !(new SignInSecrets(null, 'Fixed#Password#1'))->defaultPasswordsEnabled()
    && !(new SignInSecrets(null, 'Only#{F}#1'))->defaultPasswordsEnabled());
$secrets = new SignInSecrets('test-recovery-password', 'Test#{F}{L}#Claim!');
check('the template is filled with the initials', $secrets->defaultPasswordFor(' jane', 'doe ') === 'Test#JD#Claim!');
check('a missing name gives a question mark', $secrets->defaultPasswordFor('', 'Doe') === 'Test#?D#Claim!');
$dump = print_r($secrets, true) . var_export($secrets, true) . print_r((array) $secrets, true) . json_encode($secrets);
ob_start();
var_dump($secrets);
$dump .= (string) ob_get_clean();
check('a debug dump shows neither secret', !str_contains($dump, 'test-recovery-password') && !str_contains($dump, 'Claim!')
    && str_contains($dump, 'configured'), $dump);
try {
    serialize($secrets);
    check('the secrets cannot be serialized', false);
} catch (LogicException) {
    check('the secrets cannot be serialized', true);
}
try {
    $copy = clone $secrets;
    check('or cloned', false);
} catch (LogicException) {
    check('or cloned', true);
}
try {
    (static function (SignInSecrets $s): never { throw new RuntimeException('boom'); })(new SignInSecrets('another-secret-value', null));
} catch (RuntimeException $e) {
    check('a stack trace does not carry the configured values', !str_contains(print_r($e->getTrace(), true), 'another-secret-value'));
}
putenv('PORTAL_HARDCODED_ADMIN_PASSWORD=from-the-env-file-1');
$_ENV['PORTAL_DEFAULT_PASSWORD_TEMPLATE'] = $_SERVER['PORTAL_DEFAULT_PASSWORD_TEMPLATE'] = 'Env#{F}{L}#File!';
$fromEnv = SignInSecrets::fromEnvironment();
check('the values are read from the environment', $fromEnv->matchesRecoveryAdmin('from-the-env-file-1')
    && $fromEnv->defaultPasswordFor('Jane', 'Doe') === 'Env#JD#File!');
check('and then removed from it', getenv('PORTAL_HARDCODED_ADMIN_PASSWORD') === false
    && !isset($_ENV['PORTAL_DEFAULT_PASSWORD_TEMPLATE']) && !isset($_SERVER['PORTAL_DEFAULT_PASSWORD_TEMPLATE']));
check('later reads in the same request still work', SignInSecrets::fromEnvironment()->matchesRecoveryAdmin('from-the-env-file-1'));

$source = (string) file_get_contents(__DIR__ . '/../../app/Services/AuthService.php')
    . (string) file_get_contents(__DIR__ . '/../../app/Services/PersonIdentityResolver.php');
check('no password or formula is written in the code',
    !preg_match('/DEFAULT_PASSWORD_(PREFIX|SUFFIX)|HARDCODED_ADMIN_DEFAULT_PASSWORD/', $source));

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
