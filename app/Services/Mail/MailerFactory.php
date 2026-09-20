<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Contracts\Mailer;
use App\Core\Config\EnvLoader;

/**
 * Which transport this deployment sends through, from configuration.
 *
 * `MAIL_TRANSPORT` picks it:
 *
 *  - `smtp` — a relay, named by MAIL_HOST/MAIL_PORT and the rest. The church's
 *    own Google Workspace mailbox is one of these (smtp.gmail.com with an app
 *    password), so moving to it later is these settings and nothing else;
 *  - `file` — write the message under private storage instead of sending it,
 *    for development, where a sign-in link still has to be readable;
 *  - unset or `none` — this installation cannot send mail. Nothing is
 *    half-configured: the features that need mail are simply not offered.
 */
final class MailerFactory
{
    /** Null when this installation has no way to send mail. */
    public static function fromEnvironment(string $appRoot): ?Mailer
    {
        $transport = strtolower(trim((string) EnvLoader::get('MAIL_TRANSPORT', '')));

        return match ($transport) {
            'smtp'  => self::smtp(),
            'file'  => new FileMailer(self::fileDirectory($appRoot)),
            default => null,
        };
    }

    /** Whether anything can be sent at all — what the sign-in screen asks. */
    public static function isConfigured(string $appRoot): bool
    {
        return self::fromEnvironment($appRoot) !== null;
    }

    private static function smtp(): ?Mailer
    {
        $host = trim((string) EnvLoader::get('MAIL_HOST', ''));
        $from = trim((string) EnvLoader::get('MAIL_FROM_ADDRESS', ''));
        /* A relay with nowhere to send from is not a working configuration,
           and a half-configured one must not look like a working one. */
        if ($host === '' || $from === '') {
            return null;
        }

        $encryption = strtolower(trim((string) EnvLoader::get('MAIL_ENCRYPTION', 'tls')));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        return new SmtpMailer(
            host: $host,
            port: (int) (EnvLoader::get('MAIL_PORT', '') ?: ($encryption === 'ssl' ? '465' : '587')),
            fromAddress: $from,
            fromName: trim((string) EnvLoader::get('MAIL_FROM_NAME', '')),
            username: trim((string) EnvLoader::get('MAIL_USERNAME', '')),
            password: (string) EnvLoader::get('MAIL_PASSWORD', ''),
            encryption: $encryption,
        );
    }

    private static function fileDirectory(string $appRoot): string
    {
        $configured = trim((string) EnvLoader::get('MAIL_FILE_PATH', ''));
        if ($configured !== '') {
            return str_starts_with($configured, '/') ? $configured : $appRoot . '/' . $configured;
        }

        $private = trim((string) EnvLoader::get('MAINTENANCE_PRIVATE_PATH', '')) ?: 'storage/private';
        $private = str_starts_with($private, '/') ? $private : $appRoot . '/' . $private;

        return $private . '/mail';
    }
}
