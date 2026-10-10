<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config\EnvLoader;

/**
 * The two sign-in secrets an installation sets in its own .env, and nowhere
 * else:
 *
 *   PORTAL_HARDCODED_ADMIN_PASSWORD   password of the "church admin" recovery
 *                                     login; at least 12 characters
 *   PORTAL_DEFAULT_PASSWORD_TEMPLATE  how a person's first-time password is
 *                                     formed; {F} is replaced by the first
 *                                     initial and {L} by the last initial
 *
 * Neither has a default in the code. An unset (or too weak) value switches
 * that way in off: no recovery login, or no first-time claim. Nothing
 * falls back to a well-known password.
 *
 * The values never leave this object except as the one derived password a
 * claim must hash. They are not properties of the object, so var_dump,
 * print_r, var_export, an (array) cast and json_encode cannot show them;
 * constructor arguments are marked sensitive, so stack traces show them
 * redacted; the object refuses to be serialized or cloned.
 */
final class SignInSecrets
{
    public const ADMIN_PASSWORD_ENV = 'PORTAL_HARDCODED_ADMIN_PASSWORD';
    public const DEFAULT_PASSWORD_TEMPLATE_ENV = 'PORTAL_DEFAULT_PASSWORD_TEMPLATE';
    public const MIN_ADMIN_PASSWORD_LENGTH = 12;

    private const FIRST_INITIAL = '{F}';
    private const LAST_INITIAL = '{L}';

    /** @var array<int,array{admin:?string,template:?string}> held outside the object, by object id */
    private static array $vault = [];

    private static ?self $fromEnvironment = null;

    public function __construct(
        #[\SensitiveParameter] ?string $adminPassword,
        #[\SensitiveParameter] ?string $defaultPasswordTemplate,
    ) {
        $admin = (string) $adminPassword;
        $template = trim((string) $defaultPasswordTemplate);
        self::$vault[spl_object_id($this)] = [
            'admin' => mb_strlen($admin) >= self::MIN_ADMIN_PASSWORD_LENGTH ? $admin : null,
            'template' => str_contains($template, self::FIRST_INITIAL) && str_contains($template, self::LAST_INITIAL)
                ? $template
                : null,
        ];
    }

    public function __destruct()
    {
        unset(self::$vault[spl_object_id($this)]);
    }

    /**
     * Read once per process, then removed from getenv(), $_ENV and $_SERVER,
     * so a later dump of the environment does not show them either.
     */
    public static function fromEnvironment(): self
    {
        if (self::$fromEnvironment === null) {
            self::$fromEnvironment = new self(
                EnvLoader::get(self::ADMIN_PASSWORD_ENV),
                EnvLoader::get(self::DEFAULT_PASSWORD_TEMPLATE_ENV),
            );
            foreach ([self::ADMIN_PASSWORD_ENV, self::DEFAULT_PASSWORD_TEMPLATE_ENV] as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }

        return self::$fromEnvironment;
    }

    /** True when the recovery login has a password of its own. */
    public function recoveryAdminEnabled(): bool
    {
        return $this->adminPassword() !== null;
    }

    public function matchesRecoveryAdmin(#[\SensitiveParameter] string $candidate): bool
    {
        $expected = $this->adminPassword();

        return $expected !== null && hash_equals($expected, $candidate);
    }

    /** True when first-time sign-in with a derived password is offered. */
    public function defaultPasswordsEnabled(): bool
    {
        return $this->template() !== null;
    }

    /**
     * The first-time password for a person, or null when the installation
     * offers none. A missing name gives '?' so the shape stays deterministic;
     * an administrator should fix the record.
     */
    public function defaultPasswordFor(string $firstName, string $lastName): ?string
    {
        $template = $this->template();
        if ($template === null) {
            return null;
        }
        $first = strtoupper(substr(trim($firstName), 0, 1)) ?: '?';
        $last = strtoupper(substr(trim($lastName), 0, 1)) ?: '?';

        return str_replace([self::FIRST_INITIAL, self::LAST_INITIAL], [$first, $last], $template);
    }

    public function matchesDefaultPassword(string $firstName, string $lastName, #[\SensitiveParameter] string $candidate): bool
    {
        $expected = $this->defaultPasswordFor($firstName, $lastName);

        return $expected !== null && hash_equals($expected, $candidate);
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return [
            'recoveryAdmin' => $this->recoveryAdminEnabled() ? 'configured' : 'off',
            'defaultPasswords' => $this->defaultPasswordsEnabled() ? 'configured' : 'off',
        ];
    }

    /** @return array<never> */
    public function __serialize(): array
    {
        throw new \LogicException('Sign-in secrets cannot be serialized.');
    }

    public function __clone()
    {
        throw new \LogicException('Sign-in secrets cannot be cloned.');
    }

    private function adminPassword(): ?string
    {
        return self::$vault[spl_object_id($this)]['admin'] ?? null;
    }

    private function template(): ?string
    {
        return self::$vault[spl_object_id($this)]['template'] ?? null;
    }
}
