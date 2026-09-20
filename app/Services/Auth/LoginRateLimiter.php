<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\AuthRepository;
use App\Exceptions\TooManyAttempts;
use DateTimeImmutable;

/**
 * How often somebody may try to sign in.
 *
 * Passwords, sign-in links and Google callbacks are all guessed or abused the
 * same way — repeatedly — so they are counted the same way: attempts within a
 * window, against a bucket.
 *
 * Two buckets per attempt, and both matter:
 *
 *  - **the identifier** somebody is trying (an address, or a Google subject),
 *    which slows down guessing at one account from anywhere;
 *  - **the source address**, which slows down one machine working through many
 *    accounts.
 *
 * What is stored is a hash of the bucket, never the address itself: this
 * ledger must not become a second place to learn who has an account here.
 *
 * Counting happens before the attempt and is recorded whatever the outcome, so
 * a refusal cannot be avoided by making requests that fail in some other way.
 */
final readonly class LoginRateLimiter
{
    /**
     * Window and allowance per kind of attempt, per bucket.
     *
     * Chosen so a person who has genuinely forgotten which password they use
     * is never stopped, and a script is: a household sharing one address still
     * signs in, ten wrong passwords in a quarter of an hour does not.
     *
     * @var array<string,array{window:int,identifier:int,source:int}>
     */
    private const LIMITS = [
        'password'   => ['window' => 900,  'identifier' => 10, 'source' => 40],
        'magic_link' => ['window' => 900,  'identifier' => 5,  'source' => 20],
        'google'     => ['window' => 900,  'identifier' => 20, 'source' => 40],
    ];

    public function __construct(private AuthRepository $repository)
    {
    }

    /**
     * Refuse when either bucket is over its allowance.
     *
     * @param 'password'|'magic_link'|'google' $kind
     */
    public function ensureWithinLimit(
        string $kind,
        ?string $identifier,
        ?string $source,
        DateTimeImmutable $now,
    ): void {
        $limit = self::LIMITS[$kind] ?? null;
        if ($limit === null) {
            return;
        }
        $since = $now->modify('-' . $limit['window'] . ' seconds');

        foreach ([['identifier', $identifier], ['source', $source]] as [$which, $value]) {
            if ($value === null || $value === '') {
                continue;
            }
            $used = $this->repository->countAuthAttempts($kind, self::bucket($which, $value), $since);
            if ($used >= $limit[$which]) {
                throw new TooManyAttempts($limit['window']);
            }
        }
    }

    /**
     * Note an attempt against both buckets.
     *
     * @param 'password'|'magic_link'|'google' $kind
     */
    public function record(
        string $kind,
        ?string $identifier,
        ?string $source,
        DateTimeImmutable $now,
    ): void {
        foreach ([['identifier', $identifier], ['source', $source]] as [$which, $value]) {
            if ($value === null || $value === '') {
                continue;
            }
            $this->repository->recordAuthAttempt($kind, self::bucket($which, $value), $now);
        }
    }

    /** Forget what is older than a day: the longest window is a quarter-hour. */
    public function purgeOlderThan(DateTimeImmutable $now): void
    {
        $this->repository->purgeAuthAttempts($now->modify('-1 day'));
    }

    private static function bucket(string $which, string $value): string
    {
        return hash('sha256', $which . '|' . strtolower(trim($value)));
    }
}
