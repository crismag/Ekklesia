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
        1 => ['first' => 'Peter',  'last' => 'Santos', 'email' => 'family@gmail.com', 'phone' => '4165550100'],
        2 => ['first' => 'Mary',   'last' => 'Santos', 'email' => 'family@gmail.com', 'phone' => '4165550100'],
        3 => ['first' => 'Joshua', 'last' => 'Santos', 'email' => 'family@gmail.com', 'phone' => null],
        4 => ['first' => 'Paul',   'last' => 'Santos', 'email' => 'family@gmail.com', 'phone' => null],
        5 => ['first' => 'Ana',    'last' => 'Reyes',  'email' => 'ana@example.com',  'phone' => '6475550199'],
        6 => ['first' => 'Ben',    'last' => 'Reyes',  'email' => 'ben@example.com',  'phone' => '6475550199'],
    ];
    $accounts = [
        50 => ['id' => 50, 'email' => 'ana@example.com', 'password_hash' => $hasher->hash('Ana-own-pass-1'), 'is_active' => true,
               'display_name' => 'Ana Reyes', 'must_change_password' => false, 'person_id' => 5],
        51 => ['id' => 51, 'email' => 'ben@example.com', 'password_hash' => $hasher->hash('Ben-own-pass-1'), 'is_active' => true,
               'display_name' => 'Ben Reyes', 'must_change_password' => false, 'person_id' => 6],
    ];
    return [$people, $accounts];
}

/** @return array{0:AuthService,1:ArrayObject} */
function service(PasswordHasher $hasher): array
{
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
                'defaultPassword' => 'ChristLike#' . $p['first'][0] . $p['last'][0] . '#2026!',
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

    return [new AuthService($repository, stubOf(MinistryRepository::class, []), $hasher, 3600, $directory), $state];
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
$r = attempt($auth, 'family@gmail.com', 'ChristLike#PS#2026!');
check('is not signed in straight away: the person must be chosen', $r instanceof LoginChoiceRequired && $r->kind === 'claim', get_class($r));
$names = $r instanceof LoginChoiceRequired ? array_column($r->choices, 'name') : [];
check('offers every eligible person with that email and that default password', $names === ['Peter Santos', 'Paul Santos'], implode(', ', $names));
check('offers nobody whose default password this is not', !in_array('Mary Santos', $names, true) && !in_array('Joshua Santos', $names, true));
check('creates no login before the choice', count($state['accounts']) === 2);

try {
    $auth->completeLoginChoice('claim', 'family@gmail.com', [1, 4], 2);
    check('refuses a person who was not offered', false);
} catch (PermissionDenied) {
    check('refuses a person who was not offered', true);
}

$session = $auth->completeLoginChoice('claim', 'family@gmail.com', [1, 4], 1);
$linked = array_values(array_filter($state['accounts'], static fn (array $a): bool => $a['email'] === 'family@gmail.com'));
check('the chosen person gets the login', $session->personId === 1 && count($linked) === 1 && $linked[0]['person_id'] === 1);
check('and must change the default password', $session->mustChangePassword === true);
check('the claim is audited', ($state['audit'][0][2] ?? '') === 'auth.login.claimed');

$again = attempt($auth, 'family@gmail.com', 'ChristLike#PS#2026!');
check('the next sign-in with that email opens Peter directly, without asking', $again instanceof App\DTO\Auth\AuthSession && $again->accountId === $linked[0]['id']);
$paul = attempt($auth, 'family@gmail.com', 'ChristLike#PS#2026!');
check('and still does not offer Paul, who shares the email and initials', $paul instanceof App\DTO\Auth\AuthSession);
$mary = attempt($auth, 'family@gmail.com', 'ChristLike#MS#2026!');
check("once linked to Peter, the email is Peter's login; Mary's default password does not open it", $mary instanceof PermissionDenied);

echo "\nOne eligible person is confirmed, not assumed\n";
[$auth, $state] = service($hasher);
$r = attempt($auth, 'family@gmail.com', 'ChristLike#MS#2026!');
check('Mary alone is offered, to confirm', $r instanceof LoginChoiceRequired && array_column($r->choices, 'id') === [2]);
check('and nothing is created until she confirms', count($state['accounts']) === 2);

echo "\nFirst sign-in through a shared phone\n";
$r = attempt($auth, '(416) 555-0100', 'ChristLike#MS#2026!');
check('the phone reaches Mary (not Peter, the older record)', $r instanceof LoginChoiceRequired && array_column($r->choices, 'id') === [2]);
$session = $auth->completeLoginChoice('claim', '(416) 555-0100', [2], 2);
check('the login is keyed to the phone', $session->email === 'phone+4165550100@portal.local' && $session->personId === 2);
$again = attempt($auth, '416-555-0100', 'ChristLike#MS#2026!');
check('the next phone sign-in opens Mary directly', $again instanceof App\DTO\Auth\AuthSession && $again->personId === 2);

echo "\nExisting logins reached through a shared phone\n";
[$auth, $state] = service($hasher);
$r = attempt($auth, '647-555-0199', 'Ben-own-pass-1');
check("Ben's own password opens Ben's login, not Ana's (the older record)", $r instanceof App\DTO\Auth\AuthSession && $r->accountId === 51);
$r = attempt($auth, '647-555-0199', 'Ana-own-pass-1');
check("Ana's own password opens Ana's login", $r instanceof App\DTO\Auth\AuthSession && $r->accountId === 50);
$r = attempt($auth, '647-555-0199', 'ChristLike#AR#2026!');
check('a default password does not open or claim someone who already has a login', $r instanceof PermissionDenied);
$r = attempt($auth, 'ana@example.com', 'Ben-own-pass-1');
check("a login's own identity checks only its own password", $r instanceof PermissionDenied);

// Two logins opened by the same password through one phone: the user chooses.
$accounts = $state['accounts'];
$accounts[51]['password_hash'] = $hasher->hash('Ana-own-pass-1');
$state['accounts'] = $accounts;
$r = attempt($auth, '6475550199', 'Ana-own-pass-1');
check('when the password opens two logins on a shared phone, the user chooses', $r instanceof LoginChoiceRequired && $r->kind === 'account'
    && array_column($r->choices, 'id') === [50, 51]);
$session = $auth->completeLoginChoice('account', '6475550199', [50, 51], 51);
check('and gets the one they chose', $session->accountId === 51);

echo "\nAn administrator giving access\n";
[$auth, $state] = service($hasher);
$admin = new ActorContext(actorId: 1, personId: null, displayName: 'Admin', permissions: [], ministryScopeIds: [], isPortalWideAdmin: true);
$auth->completeLoginChoice('claim', 'family@gmail.com', [1], 1);
try {
    $auth->provisionPortalAccess($admin, 'family@gmail.com', 'member', personId: 2);
    check("cannot move Peter's login to Mary by giving Mary access with the shared email", false);
} catch (ValidationFailed $e) {
    check("cannot move Peter's login to Mary by giving Mary access with the shared email", str_contains($e->getMessage(), 'Peter Santos'), $e->getMessage());
}
$peterLogin = array_values(array_filter($state['accounts'], static fn (array $a): bool => $a['email'] === 'family@gmail.com'))[0];
check("Peter's login still belongs to Peter", $peterLogin['person_id'] === 1);

printf("\nPassed: %d; failed: %d\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
