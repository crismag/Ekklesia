<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Contracts\Mailer;
use App\DTO\Mail\MailMessage;
use RuntimeException;

/**
 * SMTP over PHP streams. No vendor tree, like the rest of this application.
 *
 * SMTP rather than one provider's API because every mailbox the church might
 * use speaks it — a host's own relay today, the church's Google Workspace
 * account later — and moving between them is then a change of four settings
 * rather than a change to authentication.
 *
 * Failure throws. A caller that wants a failure to be invisible to the person
 * signing in (so an address cannot be probed) decides that for itself; this
 * class never pretends to have delivered something.
 */
final class SmtpMailer implements Mailer
{
    /**
     * @param 'none'|'tls'|'ssl' $encryption 'tls' upgrades with STARTTLS;
     *                                       'ssl' connects inside TLS (port 465).
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $fromAddress,
        private readonly string $fromName = '',
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $encryption = 'tls',
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function send(MailMessage $message): void
    {
        $endpoint = ($this->encryption === 'ssl' ? 'ssl://' : '') . $this->host . ':' . $this->port;
        $socket = @stream_socket_client(
            $endpoint,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            /* The host and port are configuration, not a secret; the message
               and its link are never named here. */
            throw new RuntimeException(sprintf('Cannot reach the mail server %s: %s', $endpoint, $errstr));
        }
        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            $this->expect($socket, 220);
            $this->hello($socket);

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('The mail server would not start TLS.');
                }
                /* Re-introduce ourselves: what the server offers changes once
                   the connection is encrypted, and AUTH usually appears only
                   then. */
                $this->hello($socket);
            }

            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($this->username), 334);
                $this->command($socket, base64_encode($this->password), 235);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->fromAddress . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $message->toAddress . '>', 250);
            $this->command($socket, 'DATA', 354);

            $this->write($socket, $this->body($message) . "\r\n.");
            $this->expect($socket, 250);

            $this->command($socket, 'QUIT', 221);
        } finally {
            @fclose($socket);
        }
    }

    /** ESMTP first; a server that does not know EHLO still answers HELO. */
    private function hello(mixed $socket): void
    {
        $name = $this->clientName();
        $this->write($socket, 'EHLO ' . $name);
        [$code] = $this->read($socket);
        if ($code !== 250) {
            $this->command($socket, 'HELO ' . $name, 250);
        }
    }

    /** RFC 5321 wants a domain here; the sender's is the one we can vouch for. */
    private function clientName(): string
    {
        $at = strrchr($this->fromAddress, '@');
        return $at === false ? 'localhost' : substr($at, 1);
    }

    private function body(MailMessage $message): string
    {
        $from = $this->fromName !== ''
            ? sprintf('%s <%s>', self::header($this->fromName), $this->fromAddress)
            : $this->fromAddress;
        $to = $message->toName !== null && $message->toName !== ''
            ? sprintf('%s <%s>', self::header($message->toName), $message->toAddress)
            : $message->toAddress;

        $headers = [
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . self::header($message->subject),
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            /* Transactional mail nobody should reply to in bulk, and which
               must not sit in a mailing-list archive. */
            'Auto-Submitted: auto-generated',
        ];

        if ($message->html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            return implode("\r\n", $headers) . "\r\n\r\n" . self::dotStuff($message->text);
        }

        $boundary = 'ek-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $parts = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            . self::dotStuff($message->text)
            . "\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n"
            . self::dotStuff($message->html)
            . "\r\n--{$boundary}--";

        return implode("\r\n", $headers) . "\r\n\r\n" . $parts;
    }

    /**
     * Anything but plain ASCII is encoded rather than sent raw, and a header
     * can only ever be one line: a newline reaching this would let a caller
     * write headers of their own.
     */
    private static function header(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        return preg_match('/^[\x20-\x7E]*$/', $value) === 1
            ? $value
            : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** A line of its own that is just "." would end the message early. */
    private static function dotStuff(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], "\n", $body);
        return str_replace("\n.", "\n..", $body === '' ? '' : $body);
    }

    private function command(mixed $socket, string $line, int $expected): void
    {
        $this->write($socket, $line);
        $this->expect($socket, $expected);
    }

    private function expect(mixed $socket, int $expected): void
    {
        [$code, $text] = $this->read($socket);
        if ($code !== $expected) {
            throw new RuntimeException(sprintf('The mail server answered %d: %s', $code, $text));
        }
    }

    /**
     * One reply, including a multi-line one (`250-…` continues, `250 …` ends).
     *
     * @return array{0:int,1:string}
     */
    private function read(mixed $socket): array
    {
        $code = 0;
        $text = '';
        while (true) {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new RuntimeException('The mail server closed the connection.');
            }
            $code = (int) substr($line, 0, 3);
            $text .= trim(substr($line, 4)) . ' ';
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return [$code, trim($text)];
    }

    private function write(mixed $socket, string $line): void
    {
        if (@fwrite($socket, $line . "\r\n") === false) {
            throw new RuntimeException('The connection to the mail server failed while sending.');
        }
    }
}
