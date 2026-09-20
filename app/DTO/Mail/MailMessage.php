<?php

declare(strict_types=1);

namespace App\DTO\Mail;

/**
 * One message, as the portal composes it: who it is for, what it says, and
 * nothing about how it travels.
 *
 * Plain text and an optional HTML alternative. The church's mail is short and
 * transactional — a sign-in link, later a reset — so this stays deliberately
 * small: no attachments, no templating engine, no per-transport options.
 */
final readonly class MailMessage
{
    public function __construct(
        public string $toAddress,
        public string $subject,
        public string $text,
        public ?string $html = null,
        public ?string $toName = null,
    ) {
    }
}
