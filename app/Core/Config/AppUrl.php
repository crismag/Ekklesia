<?php

declare(strict_types=1);

namespace App\Core\Config;

/**
 * Where this installation is reachable from outside.
 *
 * Read from configuration (`APP_URL`), never from the request's Host header:
 * a host header is something the client sends, so a sign-in link or an OAuth
 * callback built from one is a link somebody can point at their own server.
 *
 * One setting serves every deployment — a developer's localhost, the demo at
 * ekklesiademo.crishub.com, the church's own host later — so no deployment's
 * address is written into the code.
 */
final class AppUrl
{
    /** The configured base, without a trailing slash, or '' when unset. */
    public static function base(): string
    {
        return rtrim(trim((string) EnvLoader::get('APP_URL', '')), '/');
    }

    public static function isConfigured(): bool
    {
        return self::base() !== '';
    }

    /** An absolute URL for a path within the portal, e.g. '/auth/google/callback'. */
    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }
}
