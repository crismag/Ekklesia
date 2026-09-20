<?php

declare(strict_types=1);

/**
 * The two sign-in methods beside the password: Google, and a link sent by mail.
 *
 * What these hold, and why each one matters:
 *
 *  - **Neither creates anything.** No person, no account, no role, no
 *    assignment. The fake repository below throws if either method so much as
 *    reaches for a method that would create one.
 *  - **An ambiguous address is refused.** A household shares an address; a
 *    method that guesses which of two accounts was meant is a method that
 *    signs somebody in as their relative.
 *  - **An identity token is believed only after it proves itself** — its
 *    signature, issuer, audience, expiry, and that Google verified the address.
 *  - **A sign-in link works once**, is stored only as a hash, and expires.
 *  - **Attempts are limited**, and the same whether or not an account exists.
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
use App\Contracts\HttpPostForm;
use App\Contracts\Mailer;
use App\Contracts\MinistryRepository;
use App\Core\Security\PasswordHasher;
use App\DTO\Mail\MailMessage;
use App\Exceptions\PermissionDenied;
use App\Exceptions\TooManyAttempts;
use App\Services\Auth\GoogleOidcClient;
use App\Services\Auth\GoogleSignInService;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\MagicLinkService;
use App\Services\AuthService;
use App\Services\Geocoding\HttpGet;

$passed = 0;
$failed = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $label, $ok || $detail === '' ? '' : " ({$detail})");
}

/** @param callable():mixed $run */
function refused(callable $run, string $expected = PermissionDenied::class): bool
{
    try {
        $run();
        return false;
    } catch (Throwable $e) {
        return $e instanceof $expected;
    }
}

/* ------------------------------------------------------------------ fakes */

/**
 * An implementation of an interface where every method throws: the test fails
 * loudly if signing in reaches for something it has no business asking.
 */
function unusedStub(string $interface): object
{
    static $n = 0;
    $class = 'Unused' . (++$n);
    $methods = [];
    foreach ((new ReflectionClass($interface))->getMethods() as $m) {
        $params = [];
        foreach ($m->getParameters() as $p) {
            $type = $p->getType() !== null ? (string) $p->getType() . ' ' : '';
            $default = $p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '';
            $params[] = $type . '$' . $p->getName() . $default;
        }
        $return = $m->getReturnType() !== null ? ': ' . (string) $m->getReturnType() : '';
        $methods[] = 'public function ' . $m->getName() . '(' . implode(', ', $params) . ')' . $return
            . ' { throw new LogicException("signing in must not call " . __FUNCTION__); }';
    }
    eval('final class ' . $class . ' implements \\' . $interface . ' { ' . implode("\n", $methods) . ' }');

    return new $class();
}



/**
 * The whole storage contract, in arrays.
 *
 * Anything that would create a person, an account, a role or a link throws:
 * these methods authenticate, and a test is the place to prove they cannot do
 * anything else.
 */
final class FakeAuthStore implements AuthRepository
{
    /** @var array<int,array{id:int,email:string,is_active:bool,display_name:?string,person_id:?int}> */
    public array $accounts = [];
    /** @var list<array{account_id:int,provider:string,subject:string,linked_email:?string}> */
    public array $credentials = [];
    /** @var array<string,array{account_id:int,expires_at:DateTimeImmutable,used_at:?DateTimeImmutable}> */
    public array $tokens = [];
    /** @var list<array{kind:string,bucket:string,at:DateTimeImmutable}> */
    public array $attempts = [];
    /** @var list<array{token:string,account_id:int}> */
    public array $sessions = [];
    /** @var list<array{action:string,details:?array}> */
    public array $audit = [];

    public function findUserByEmail(string $email): ?array
    {
        foreach ($this->accounts as $a) {
            if ($a['email'] === $email) {
                return $a + ['password_hash' => 'x'];
            }
        }
        return null;
    }

    public function loadUserProfile(int $accountId): ?array
    {
        $a = $this->accounts[$accountId] ?? null;
        return $a === null ? null : $a + ['roles' => []];
    }

    public function recordLogin(int $accountId, DateTimeImmutable $at): void
    {
    }

    public function createSession(
        int $accountId,
        string $sessionToken,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->sessions[] = ['token' => $sessionToken, 'account_id' => $accountId];
    }

    public function findActiveSession(string $sessionToken): ?array
    {
        return null;
    }

    public function touchSession(string $sessionToken, DateTimeImmutable $at): void
    {
    }

    public function revokeSession(string $sessionToken, DateTimeImmutable $at): void
    {
    }

    public function revokeAllSessionsExcept(int $accountId, ?string $exceptToken, DateTimeImmutable $at): int
    {
        return 0;
    }

    public function createUser(string $email, string $passwordHash, ?string $displayName): int
    {
        throw new LogicException('signing in must never create an account');
    }

    public function provisionUserForPerson(string $email, string $passwordHash, ?string $displayName, int $personId): int
    {
        throw new LogicException('signing in must never create an account');
    }

    public function listUsersForPerson(int $personId): array
    {
        return [];
    }

    public function setMustChangePassword(int $accountId, bool $value): void
    {
    }

    public function isMustChangePassword(int $accountId): bool
    {
        return false;
    }

    public function clearRolesForUser(int $accountId): void
    {
        throw new LogicException('signing in must never change roles');
    }

    public function linkUserToPerson(int $accountId, int $personId): void
    {
        throw new LogicException('signing in must never link a person');
    }

    public function assignRole(int $accountId, string $role, ?int $campusId, ?int $ministryId): void
    {
        throw new LogicException('signing in must never grant a role');
    }

    public function listUsersWithAccess(): array
    {
        return [];
    }

    public function updateDisplayName(int $accountId, ?string $displayName): void
    {
    }

    public function updatePasswordHash(int $accountId, string $passwordHash): void
    {
    }

    public function findAccountByCredential(string $provider, string $subject): ?array
    {
        foreach ($this->credentials as $c) {
            if ($c['provider'] === $provider && $c['subject'] === $subject) {
                return $this->accounts[$c['account_id']] ?? null;
            }
        }
        return null;
    }

    public function findActiveAccountsByEmail(string $email): array
    {
        $out = [];
        foreach ($this->accounts as $a) {
            if ($a['email'] === $email && $a['is_active']) {
                $out[] = $a;
            }
        }
        return $out;
    }

    public function linkCredential(
        int $accountId,
        string $provider,
        string $subject,
        ?string $linkedEmail,
        DateTimeImmutable $at,
    ): void {
        foreach ($this->credentials as $c) {
            if (($c['provider'] === $provider && $c['subject'] === $subject)
                || ($c['provider'] === $provider && $c['account_id'] === $accountId)) {
                throw new RuntimeException('duplicate credential');
            }
        }
        $this->credentials[] = [
            'account_id'   => $accountId,
            'provider'     => $provider,
            'subject'      => $subject,
            'linked_email' => $linkedEmail,
        ];
    }

    public function touchCredential(string $provider, string $subject, DateTimeImmutable $at): void
    {
    }

    public function createMagicLoginToken(
        int $accountId,
        string $tokenHash,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->tokens[$tokenHash] = [
            'account_id' => $accountId,
            'expires_at' => $expiresAt,
            'used_at'    => null,
        ];
    }

    public function consumeMagicLoginToken(string $tokenHash, DateTimeImmutable $now): ?int
    {
        $row = $this->tokens[$tokenHash] ?? null;
        /* The adapter does this in one statement; the fake keeps the same
           rule: unknown, spent or expired all answer null. */
        if ($row === null || $row['used_at'] !== null || $row['expires_at'] <= $now) {
            return null;
        }
        $this->tokens[$tokenHash]['used_at'] = $now;
        return $row['account_id'];
    }

    public function recordAuthAttempt(string $kind, string $bucket, DateTimeImmutable $at): void
    {
        $this->attempts[] = ['kind' => $kind, 'bucket' => $bucket, 'at' => $at];
    }

    public function countAuthAttempts(string $kind, string $bucket, DateTimeImmutable $since): int
    {
        $n = 0;
        foreach ($this->attempts as $a) {
            if ($a['kind'] === $kind && $a['bucket'] === $bucket && $a['at'] >= $since) {
                $n++;
            }
        }
        return $n;
    }

    public function purgeAuthAttempts(DateTimeImmutable $before): void
    {
    }

    public function recordAudit(
        ?int $accountId,
        ?int $personId,
        string $action,
        ?string $targetType,
        ?string $targetId,
        ?string $summary,
        ?array $details,
        ?string $ipAddress,
        ?string $userAgent,
        DateTimeImmutable $at,
    ): void {
        $this->audit[] = ['action' => $action, 'details' => $details];
    }
}

final class CollectingMailer implements Mailer
{
    /** @var list<MailMessage> */
    public array $sent = [];
    public bool $broken = false;

    public function send(MailMessage $message): void
    {
        if ($this->broken) {
            throw new RuntimeException('the relay is down');
        }
        $this->sent[] = $message;
    }
}

/** Google's published keys, served from memory. */
final class FakeKeyServer implements HttpGet
{
    public int $calls = 0;

    public function __construct(private readonly string $body)
    {
    }

    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        $this->calls++;
        return ['status' => 200, 'body' => $this->body, 'headers' => []];
    }
}

/** Google's token endpoint, serving whatever the test needs it to. */
final class FakeTokenEndpoint implements HttpPostForm
{
    /** @var list<array<string,string>> */
    public array $calls = [];

    public function __construct(public string $body, public int $status = 200)
    {
    }

    public function post(string $url, array $form, int $timeoutSeconds): array
    {
        $this->calls[] = $form;
        return ['status' => $this->status, 'body' => $this->body];
    }
}

/* ------------------------------------------------------ signing test tokens */

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false) {
    fwrite(STDERR, "openssl is unavailable; cannot test identity tokens.\n");
    exit(1);
}
$details = openssl_pkey_get_details($key);

$b64url = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

$jwks = json_encode(['keys' => [[
    'kty' => 'RSA',
    'kid' => 'test-key',
    'use' => 'sig',
    'alg' => 'RS256',
    'n'   => $b64url($details['rsa']['n']),
    'e'   => $b64url($details['rsa']['e']),
]]], JSON_THROW_ON_ERROR);

const CLIENT_ID = 'client-id.apps.googleusercontent.com';

/** An ID token as Google would send it, unless a test asks for one that is wrong. */
$makeToken = static function (array $claims, array $header = [], ?string $forgeWith = null) use ($key, $b64url): string {
    $head = $b64url(json_encode($header + ['alg' => 'RS256', 'kid' => 'test-key'], JSON_THROW_ON_ERROR));
    $body = $b64url(json_encode($claims + [
        'iss'            => 'https://accounts.google.com',
        'aud'            => CLIENT_ID,
        'exp'            => time() + 600,
        'iat'            => time() - 5,
        'email_verified' => true,
    ], JSON_THROW_ON_ERROR));

    if ($forgeWith !== null) {
        return $head . '.' . $body . '.' . $b64url($forgeWith);
    }
    openssl_sign($head . '.' . $body, $signature, $key, OPENSSL_ALGO_SHA256);
    return $head . '.' . $body . '.' . $b64url($signature);
};

/* ------------------------------------------------------------- the church */

/** Ana has her own address; the Santos household shares one. */
function store(): FakeAuthStore
{
    $store = new FakeAuthStore();
    $store->accounts = [
        50 => ['id' => 50, 'email' => 'ana@example.com', 'is_active' => true, 'display_name' => 'Ana Reyes', 'person_id' => 5],
        51 => ['id' => 51, 'email' => 'family@gmail.com', 'is_active' => true, 'display_name' => 'Peter Santos', 'person_id' => 1],
        52 => ['id' => 52, 'email' => 'family@gmail.com', 'is_active' => true, 'display_name' => 'Mary Santos', 'person_id' => 2],
        53 => ['id' => 53, 'email' => 'left@example.com', 'is_active' => false, 'display_name' => 'Someone Gone', 'person_id' => 7],
    ];
    return $store;
}

function authService(FakeAuthStore $store): AuthService
{
    /* MinistryRepository is consulted when working out what an actor may do,
       which signing in never reaches — so every method throws. */
    return new AuthService(
        $store,
        unusedStub(MinistryRepository::class),
        new PasswordHasher(),
        60 * 60 * 12,
        null,
        new LoginRateLimiter($store),
    );
}

/**
 * @param array<string,mixed> $claims
 */
function googleService(FakeAuthStore $store, string $idToken, string $jwks): GoogleSignInService
{
    $client = new GoogleOidcClient(
        clientId: CLIENT_ID,
        clientSecret: 'a-secret',
        redirectUri: 'https://ekklesiademo.crishub.com/auth/google/callback',
        http: new FakeKeyServer($jwks),
        httpPost: new FakeTokenEndpoint(json_encode(['id_token' => $idToken], JSON_THROW_ON_ERROR)),
    );

    return new GoogleSignInService($store, authService($store), $client, new LoginRateLimiter($store));
}

echo "Signing in with Google\n";

/* The request that is made of Google, before anybody answers it. */
$begin = googleService(store(), $makeToken(['sub' => 'g-1', 'email' => 'ana@example.com']), $jwks)->begin();
parse_str((string) parse_url($begin['url'], PHP_URL_QUERY), $query);
check('asks Google for identity only: openid email profile', ($query['scope'] ?? '') === 'openid email profile');
check('sends the configured callback address, not a hard-coded one',
    ($query['redirect_uri'] ?? '') === 'https://ekklesiademo.crishub.com/auth/google/callback');
check('carries a state worth checking (32 random bytes)', strlen($begin['state']) === 64 && ctype_xdigit($begin['state']));
check('asks for no refresh token: nothing here acts for anybody later', ($query['access_type'] ?? '') === 'online');

$service = googleService(store(), '', $jwks);
check('state must match exactly', $service->stateMatches('abc', 'abc'));
check('a state that differs is refused', !$service->stateMatches('abc', 'abd'));
check('a missing state is refused', !$service->stateMatches('abc', null) && !$service->stateMatches('', ''));

/* One account with that address: the identity is associated, once. */
$store = store();
$session = googleService($store, $makeToken(['sub' => 'g-ana', 'email' => 'ana@example.com']), $jwks)
    ->completeCallback('the-code', '10.0.0.1', 'a browser');
check('an unambiguous verified address signs that account in', $session->accountId === 50);
check('the session is an ordinary one, in the ordinary table', count($store->sessions) === 1 && $store->sessions[0]['account_id'] === 50);
check('the identity is remembered as Google’s subject', count($store->credentials) === 1
    && $store->credentials[0]['subject'] === 'g-ana' && $store->credentials[0]['account_id'] === 50);
check('the association is written to the audit log', count($store->audit) === 1 && $store->audit[0]['action'] === 'auth.google.linked');
check('no token or code is kept in the audit entry',
    ($store->audit[0]['details'] ?? []) === ['email' => 'ana@example.com']);

/* Afterwards it is the subject that matters, not the address. */
$renamed = googleService($store, $makeToken(['sub' => 'g-ana', 'email' => 'ana.reyes@newaddress.example']), $jwks)
    ->completeCallback('another-code');
check('a renamed Google address still opens the same account', $renamed->accountId === 50);
check('and no second association is made', count($store->credentials) === 1);

/* The address that belongs to a household. */
$shared = store();
check('a shared address is refused rather than guessed at', refused(
    fn () => googleService($shared, $makeToken(['sub' => 'g-new', 'email' => 'family@gmail.com']), $jwks)->completeCallback('c'),
));
check('and nothing is associated when it is refused', $shared->credentials === []);
check('and nobody is signed in', $shared->sessions === []);

$unknown = store();
check('an address with no account here is refused', refused(
    fn () => googleService($unknown, $makeToken(['sub' => 'g-x', 'email' => 'stranger@example.com']), $jwks)->completeCallback('c'),
));
check('signing in never creates an account for a stranger', count($unknown->accounts) === 4 && $unknown->credentials === []);

$inactive = store();
check('a deactivated account is refused', refused(
    fn () => googleService($inactive, $makeToken(['sub' => 'g-gone', 'email' => 'left@example.com']), $jwks)->completeCallback('c'),
));

check('an address Google has not verified is refused', refused(
    fn () => googleService(store(), $makeToken(['sub' => 'g-2', 'email' => 'ana@example.com', 'email_verified' => false]), $jwks)
        ->completeCallback('c'),
));

echo "\nWhat an identity token has to prove\n";

check('a forged signature is refused', refused(
    fn () => googleService(store(), $makeToken(['sub' => 'g-ana', 'email' => 'ana@example.com'], forgeWith: 'not-a-signature'), $jwks)
        ->completeCallback('c'),
));
check('a token for another application is refused', refused(
    fn () => googleService(store(), $makeToken(['sub' => 'g-ana', 'email' => 'ana@example.com', 'aud' => 'someone-else.apps.googleusercontent.com']), $jwks)
        ->completeCallback('c'),
));
check('a token from another issuer is refused', refused(
    fn () => googleService(store(), $makeToken(['sub' => 'g-ana', 'email' => 'ana@example.com', 'iss' => 'https://evil.example']), $jwks)
        ->completeCallback('c'),
));
check('an expired token is refused', refused(
    fn () => googleService(store(), $makeToken(['sub' => 'g-ana', 'email' => 'ana@example.com', 'exp' => time() - 3600]), $jwks)
        ->completeCallback('c'),
));
check('a token that names no subject is refused', refused(
    fn () => googleService(store(), $makeToken(['email' => 'ana@example.com']), $jwks)->completeCallback('c'),
));
check('a token asking to be checked with "none" is refused', refused(
    fn () => googleService(store(), $makeToken(['sub' => 'g-ana', 'email' => 'ana@example.com'], ['alg' => 'none'], forgeWith: ''), $jwks)
        ->completeCallback('c'),
));
check('a token signed with a key Google does not publish is refused', refused(
    fn () => googleService(store(), $makeToken(['sub' => 'g-ana', 'email' => 'ana@example.com'], ['kid' => 'some-other-key']), $jwks)
        ->completeCallback('c'),
));

/* A reply with no token at all — a spent or refused code. */
$noToken = store();
$refusedClient = new GoogleOidcClient(
    CLIENT_ID,
    'a-secret',
    'https://ekklesiademo.crishub.com/auth/google/callback',
    new FakeKeyServer($jwks),
    new FakeTokenEndpoint(json_encode(['error' => 'invalid_grant'], JSON_THROW_ON_ERROR), 400),
);
check('a code Google will not exchange is refused', refused(
    fn () => (new GoogleSignInService($noToken, authService($noToken), $refusedClient, new LoginRateLimiter($noToken)))
        ->completeCallback('spent-code'),
));

echo "\nSigning in with a link\n";

function magicService(FakeAuthStore $store, CollectingMailer $mailer): MagicLinkService
{
    return new MagicLinkService(
        $store,
        authService($store),
        $mailer,
        new LoginRateLimiter($store),
        'https://ekklesiademo.crishub.com/login/link/{token}',
        'Christlikeness',
    );
}

$store = store();
$mailer = new CollectingMailer();
magicService($store, $mailer)->requestLink('ana@example.com', '10.0.0.1');
check('a link is sent to an address with one account', count($mailer->sent) === 1);
check('it goes to the address the account holds', $mailer->sent[0]->toAddress === 'ana@example.com');
check('one token is stored', count($store->tokens) === 1);

preg_match('#/login/link/([a-f0-9]+)#', $mailer->sent[0]->text, $m);
$token = $m[1] ?? '';
check('the link carries 32 random bytes', strlen($token) === 64 && ctype_xdigit($token));
check('only a hash of it is stored', !isset($store->tokens[$token]) && isset($store->tokens[hash('sha256', $token)]));
$expiry = $store->tokens[hash('sha256', $token)]['expires_at'];
$life = $expiry->getTimestamp() - time();
check('it stops working after fifteen minutes', $life > 840 && $life <= 900, (string) $life);

$signedIn = magicService($store, $mailer)->completeSignIn($token, '10.0.0.1', 'a browser');
check('following it signs that account in', $signedIn->accountId === 50);
check('through the ordinary session mechanism', count($store->sessions) === 1);
check('a link works once and no more', refused(fn () => magicService($store, $mailer)->completeSignIn($token)));

/* Expiry, and links nobody issued. */
$store2 = store();
$mailer2 = new CollectingMailer();
magicService($store2, $mailer2)->requestLink('ana@example.com');
preg_match('#/login/link/([a-f0-9]+)#', $mailer2->sent[0]->text, $m2);
$hash = hash('sha256', $m2[1]);
$store2->tokens[$hash]['expires_at'] = (new DateTimeImmutable())->modify('-1 second');
check('an expired link is refused', refused(fn () => magicService($store2, $mailer2)->completeSignIn($m2[1])));
check('a link nobody issued is refused', refused(fn () => magicService($store2, $mailer2)->completeSignIn(str_repeat('a', 64))));
check('an empty token is refused', refused(fn () => magicService($store2, $mailer2)->completeSignIn('')));

/* The answers that must not differ. */
$unknownStore = store();
$unknownMailer = new CollectingMailer();
magicService($unknownStore, $unknownMailer)->requestLink('stranger@example.com');
check('an address with no account is sent nothing', $unknownMailer->sent === []);
check('and no token is made for it', $unknownStore->tokens === []);
check('and no account is created', count($unknownStore->accounts) === 4);

$sharedStore = store();
$sharedMailer = new CollectingMailer();
magicService($sharedStore, $sharedMailer)->requestLink('family@gmail.com');
check('a shared address is sent nothing rather than a guess', $sharedMailer->sent === [] && $sharedStore->tokens === []);

$inactiveStore = store();
$inactiveMailer = new CollectingMailer();
magicService($inactiveStore, $inactiveMailer)->requestLink('left@example.com');
check('a deactivated account is sent nothing', $inactiveMailer->sent === []);

/* A relay that is down must not become a way to learn who exists. */
$brokenStore = store();
$brokenMailer = new CollectingMailer();
$brokenMailer->broken = true;
$threw = false;
try {
    magicService($brokenStore, $brokenMailer)->requestLink('ana@example.com');
} catch (Throwable) {
    $threw = true;
}
check('a failed delivery is not reported to the browser', !$threw);

echo "\nHow often anybody may try\n";

$limited = store();
$limiter = new LoginRateLimiter($limited);
$now = new DateTimeImmutable('2026-09-17 10:00:00');
for ($i = 0; $i < 10; $i++) {
    $limiter->ensureWithinLimit('password', 'ana@example.com', '10.0.0.9', $now);
    $limiter->record('password', 'ana@example.com', '10.0.0.9', $now);
}
check('ten password attempts are allowed', true);
check('the eleventh is refused', refused(
    fn () => $limiter->ensureWithinLimit('password', 'ana@example.com', '10.0.0.9', $now),
    TooManyAttempts::class,
));
check('somebody else is unaffected', !refused(
    fn () => $limiter->ensureWithinLimit('password', 'ben@example.com', '10.0.0.8', $now),
    TooManyAttempts::class,
));
check('and the limit lapses with the window', !refused(
    fn () => $limiter->ensureWithinLimit('password', 'ana@example.com', '10.0.0.9', $now->modify('+16 minutes')),
    TooManyAttempts::class,
));
check('what is counted is a hash, not the address anybody typed',
    $limited->attempts !== [] && !str_contains(json_encode($limited->attempts), 'ana@example.com'));

/* Asking for links, over and over. */
$flood = store();
$floodMailer = new CollectingMailer();
$sender = magicService($flood, $floodMailer);
for ($i = 0; $i < 5; $i++) {
    $sender->requestLink('ana@example.com', '10.0.0.7');
}
check('five sign-in links may be asked for', count($floodMailer->sent) === 5);
check('the sixth is refused', refused(
    fn () => $sender->requestLink('ana@example.com', '10.0.0.7'),
    TooManyAttempts::class,
));

/* A password sign-in is counted too, and is refused when it is tried too often. */
$pwStore = store();
$pwStore->accounts[50]['password_hash'] = (new PasswordHasher())->hash('a-real-password');
$auth = authService($pwStore);
for ($i = 0; $i < 10; $i++) {
    try {
        $auth->login('nobody@example.com', 'wrong-password', '10.0.0.6');
    } catch (Throwable) {
        // A wrong password is the point of the loop.
    }
}
check('the eleventh password attempt is refused before the password is looked at', refused(
    fn () => $auth->login('nobody@example.com', 'wrong-password', '10.0.0.6'),
    TooManyAttempts::class,
));

echo "\n" . ($failed === 0 ? '' : 'FAILURES. ') . "Passed: {$passed}; failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
