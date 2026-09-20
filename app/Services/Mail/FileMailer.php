<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Contracts\Mailer;
use App\DTO\Mail\MailMessage;
use RuntimeException;

/**
 * Writes each message to a file instead of sending it. For development, where
 * there is no relay and a sign-in link still has to be readable.
 *
 * It writes under the private storage directory, never the web root and never
 * the application log: a sign-in link is a credential for as long as it lives,
 * and a log is the one place it must not be.
 */
final class FileMailer implements Mailer
{
    public function __construct(private readonly string $directory)
    {
    }

    public function send(MailMessage $message): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the mail directory ' . $this->directory . '.');
        }

        $file = rtrim($this->directory, '/') . '/'
            . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.txt';

        $written = @file_put_contents($file, implode("\n", [
            'To: ' . ($message->toName !== null && $message->toName !== ''
                ? $message->toName . ' <' . $message->toAddress . '>'
                : $message->toAddress),
            'Subject: ' . $message->subject,
            'Date: ' . date('r'),
            '',
            $message->text,
        ]) . "\n");
        if ($written === false) {
            throw new RuntimeException('Cannot write the message to ' . $this->directory . '.');
        }
        @chmod($file, 0600);
    }
}
