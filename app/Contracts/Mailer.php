<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Mail\MailMessage;

/**
 * Somewhere to send a message.
 *
 * An interface rather than a call to one provider, because where the church's
 * mail goes is a deployment decision that will change: SMTP today, the
 * church's Google Workspace mailbox later. Authentication depends on this and
 * on nothing about the transport, so that move is configuration rather than a
 * change to how anybody signs in.
 *
 * An implementation either delivers the message or throws. It never reports
 * success it did not have, and it never writes a message's links or tokens to
 * a log.
 */
interface Mailer
{
    public function send(MailMessage $message): void;
}
