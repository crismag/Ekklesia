<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\HttpPostForm;
use App\Services\Geocoding\HttpGet;
use RuntimeException;

/**
 * Google as an identity provider: the authorization URL, the code exchange,
 * and what an ID token has to prove before it is believed.
 *
 * ## Why the checks are here, in one place
 *
 * An ID token is a signed statement from Google about who somebody is. Every
 * check below is load-bearing:
 *
 *  - **the signature**, against Google's published keys, or anybody could mint
 *    identities;
 *  - **the issuer**, so a token signed by some other party is not read as
 *    Google's;
 *  - **the audience**, or a token issued for a different application is
 *    accepted here, which is the classic confused deputy in OpenID Connect;
 *  - **the expiry** (with a little clock tolerance), or an old token works for
 *    ever;
 *  - **a subject**, because that — never the address — is what an account is
 *    matched on afterwards;
 *  - **a verified email**, because linking an account on an address nobody
 *    proved is how somebody claims an account by asserting its address.
 *
 * This class decides nothing about who may sign in: it returns an identity, and
 * GoogleSignInService decides whether this portal has an account for it.
 *
 * Network access goes through HttpGet (the contract the geocoders already use)
 * so the whole flow can be tested against recorded replies, including the ones
 * Google will not produce on demand — a wrong audience, a stale key, an
 * unverified address.
 */
final class GoogleOidcClient
{
    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN     = 'https://oauth2.googleapis.com/token';
    private const CERTS     = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS   = ['https://accounts.google.com', 'accounts.google.com'];

    /** Only what identifies a person. No Gmail, Drive or Calendar access. */
    public const SCOPES = 'openid email profile';

    /** Google's own tolerance for clock drift between their servers and ours. */
    private const CLOCK_SKEW_SECONDS = 120;

    /** @var array{fetchedAt:int,keys:array<string,array{n:string,e:string}>}|null */
    private ?array $keyCache = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly HttpGet $http,
        private readonly HttpPostForm $httpPost,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    /**
     * Where to send somebody to sign in.
     *
     * `$state` is the caller's random value, kept server-side and checked on
     * the way back: a callback with no state check lets a third party complete
     * somebody else's sign-in.
     */
    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        $query = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'state'         => $state,
            /* Nothing here acts on anybody's behalf afterwards, so no refresh
               token is wanted and no consent needs repeating. */
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ];
        if ($loginHint !== null && $loginHint !== '') {
            $query['login_hint'] = $loginHint;
        }

        return self::AUTHORIZE . '?' . http_build_query($query);
    }

    /**
     * Exchange the code Google sent back for the identity it stands for.
     *
     * The exchange is a server-to-server call carrying the client secret, so
     * the browser never holds anything that could be replayed, and the token
     * this verifies came straight from Google over TLS.
     *
     * @return array{subject:string,email:string,emailVerified:bool,name:?string}
     */
    public function identityFromCode(string $code): array
    {
        if ($code === '') {
            throw new RuntimeException('Google sent no authorization code.');
        }

        $reply = $this->httpPost->post(self::TOKEN, [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ], $this->timeoutSeconds);

        $payload = json_decode($reply['body'], true);
        if (!is_array($payload) || !isset($payload['id_token']) || !is_string($payload['id_token'])) {
            /* Google's own reason may name the code; it is not repeated. */
            throw new RuntimeException('Google would not complete the sign-in.');
        }

        return $this->identityFromIdToken($payload['id_token']);
    }

    /**
     * @return array{subject:string,email:string,emailVerified:bool,name:?string}
     */
    public function identityFromIdToken(string $idToken, ?int $now = null): array
    {
        $now ??= time();
        $claims = $this->verifiedClaims($idToken, $now);

        if (!in_array((string) ($claims['iss'] ?? ''), self::ISSUERS, true)) {
            throw new RuntimeException('That identity token was not issued by Google.');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? array_map('strval', $audience) : [(string) $audience];
        if (!in_array($this->clientId, $audiences, true)) {
            throw new RuntimeException('That identity token was issued for a different application.');
        }

        $expiry = (int) ($claims['exp'] ?? 0);
        if ($expiry === 0 || $expiry + self::CLOCK_SKEW_SECONDS <= $now) {
            throw new RuntimeException('That identity token has expired.');
        }
        $issuedAt = (int) ($claims['iat'] ?? 0);
        if ($issuedAt !== 0 && $issuedAt - self::CLOCK_SKEW_SECONDS > $now) {
            throw new RuntimeException('That identity token is not valid yet.');
        }

        $subject = (string) ($claims['sub'] ?? '');
        if ($subject === '') {
            throw new RuntimeException('That identity token names nobody.');
        }

        $verified = ($claims['email_verified'] ?? false) === true
            || ($claims['email_verified'] ?? '') === 'true';

        return [
            'subject'       => $subject,
            'email'         => strtolower(trim((string) ($claims['email'] ?? ''))),
            'emailVerified' => $verified,
            'name'          => isset($claims['name']) ? (string) $claims['name'] : null,
        ];
    }

    /**
     * The token's claims, once its signature is known to be Google's.
     *
     * @return array<string,mixed>
     */
    private function verifiedClaims(string $idToken, int $now): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('That is not an identity token.');
        }
        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;

        $header = self::decodeSegment($encodedHeader);
        $algorithm = (string) ($header['alg'] ?? '');
        /* Only RS256, named by us rather than taken from the token: a token
           that asks to be checked with "none", or with its own symmetric key,
           is a forgery asking to be trusted. */
        if ($algorithm !== 'RS256') {
            throw new RuntimeException('That identity token is not signed the way Google signs.');
        }

        $keyId = (string) ($header['kid'] ?? '');
        $key = $this->publicKey($keyId, $now);
        $signed = $encodedHeader . '.' . $encodedClaims;
        $signature = self::base64UrlDecode($encodedSignature);

        if (openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('That identity token is not signed by Google.');
        }

        return self::decodeSegment($encodedClaims);
    }

    /**
     * Google's signing key for `$keyId`, as a PEM public key.
     *
     * Keys are cached for an hour and fetched again when a token names one we
     * do not have — which is what a key rotation looks like from here.
     */
    private function publicKey(string $keyId, int $now): string
    {
        $keys = $this->signingKeys($now);
        if (!isset($keys[$keyId])) {
            $keys = $this->signingKeys($now, force: true);
        }
        if (!isset($keys[$keyId])) {
            throw new RuntimeException('That identity token names a signing key Google does not publish.');
        }

        return self::pemFromModulus($keys[$keyId]['n'], $keys[$keyId]['e']);
    }

    /** @return array<string,array{n:string,e:string}> */
    private function signingKeys(int $now, bool $force = false): array
    {
        if (!$force && $this->keyCache !== null && $this->keyCache['fetchedAt'] + 3600 > $now) {
            return $this->keyCache['keys'];
        }

        $reply = $this->http->get(self::CERTS, ['Accept' => 'application/json'], $this->timeoutSeconds);
        if ($reply['status'] !== 200) {
            throw new RuntimeException('Google’s signing keys could not be read.');
        }
        $payload = json_decode($reply['body'], true);
        if (!is_array($payload) || !is_array($payload['keys'] ?? null)) {
            throw new RuntimeException('Google’s signing keys could not be read.');
        }

        $keys = [];
        foreach ($payload['keys'] as $key) {
            if (!is_array($key) || ($key['kty'] ?? '') !== 'RSA') {
                continue;
            }
            $id = (string) ($key['kid'] ?? '');
            if ($id !== '' && isset($key['n'], $key['e'])) {
                $keys[$id] = ['n' => (string) $key['n'], 'e' => (string) $key['e']];
            }
        }

        $this->keyCache = ['fetchedAt' => $now, 'keys' => $keys];
        return $keys;
    }

    /**
     * An RSA public key in PEM form, assembled from the modulus and exponent
     * Google publishes.
     *
     * Written out by hand because this application carries no vendor tree;
     * it is the DER encoding of an RSAPublicKey inside a SubjectPublicKeyInfo,
     * which is what openssl_verify() will read.
     */
    private static function pemFromModulus(string $modulus, string $exponent): string
    {
        $der = self::derSequence(
            self::derSequence(
                /* rsaEncryption OID, then its NULL parameters. */
                "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00",
            )
            . self::derBitString(
                self::derSequence(
                    self::derInteger(self::base64UrlDecode($modulus))
                    . self::derInteger(self::base64UrlDecode($exponent)),
                ),
            ),
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derSequence(string $contents): string
    {
        return "\x30" . self::derLength(strlen($contents)) . $contents;
    }

    private static function derBitString(string $contents): string
    {
        /* The leading zero says no bits are unused in the final byte. */
        $contents = "\x00" . $contents;
        return "\x03" . self::derLength(strlen($contents)) . $contents;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        /* A high first bit would read as a negative number. */
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /** @return array<string,mixed> */
    private static function decodeSegment(string $segment): array
    {
        $decoded = json_decode(self::base64UrlDecode($segment), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('That identity token could not be read.');
        }
        return $decoded;
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('That identity token could not be read.');
        }
        return $decoded;
    }

}
